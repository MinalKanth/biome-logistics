<?php
/**
 * admin/bamboo_order_view.php?id=X - order detail: validated workflow, stock-safe dispatch, payments & refunds,
 * send quotation to customer, timeline, "Book a Truck Online".
 * "Book a Truck Online" creates a transport_bookings row (pickup = your bamboo dispatch point,
 * drop = this order's delivery address) so the shipment is tracked like any transport booking.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bamboo_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { header('Location: bamboo_orders.php'); exit; }
$OS = bb_order_statuses();
$FLOW = bb_order_flow();
$adminId = (int) $_SESSION['admin_id'];

$load = function () use ($pdo, $id) {
    $s = $pdo->prepare('SELECT * FROM bamboo_orders WHERE id = :id AND deleted_at IS NULL');
    $s->execute([':id' => $id]);
    return $s->fetch();
};
$ok  = static function (string $m): void { $_SESSION['flash_success_page'] = $m; };
$err = static function (string $m): void { $_SESSION['flash_error_page'] = $m; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'update_status') {
            $new = (string) ($_POST['status'] ?? '');
            $note = mb_substr(clean_input((string) ($_POST['note'] ?? '')), 0, 255);
            $pdo->beginTransaction();
            $cur = $pdo->prepare('SELECT * FROM bamboo_orders WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
            $cur->execute([':id' => $id]);
            $o = $cur->fetch();
            if (!$o) { throw new RuntimeException('Order not found.'); }
            if (!isset($OS[$new]) || !in_array($new, $FLOW[$o['status']] ?? [], true)) {
                throw new RuntimeException('You cannot move an order from "' . ($OS[$o['status']]['label'] ?? $o['status']) . '" to "' . ($OS[$new]['label'] ?? $new) . '".');
            }
            $sets = ['status = :s', 'updated_at = NOW()'];
            $p = [':s' => $new, ':id' => $id];
            $title = 'Status: ' . $OS[$new]['label'];
            $visible = true;
            $cnt = $pdo->prepare('SELECT COUNT(*) FROM bamboo_order_items WHERE order_id = :o');
            $cnt->execute([':o' => $id]);

            if ($new === 'confirmed') {
                if ((int) $cnt->fetchColumn() === 0 || (float) $o['grand_total'] <= 0) { throw new RuntimeException('The order needs items and a total before it can be confirmed.'); }
                if (!$o['order_number']) {
                    $sets[] = 'order_number = :on'; $p[':on'] = tl_next_sequence($pdo, 'bamboo_order', 'BOR');
                    $sets[] = 'accepted_at = NOW()';
                }
                $title = 'Order confirmed';
                if ($o['enquiry_id']) {
                    $pdo->prepare("UPDATE bamboo_enquiries SET status = 'won', updated_at = NOW() WHERE id = :e")->execute([':e' => $o['enquiry_id']]);
                }
            }
            if ($new === 'dispatched') {
                if (trim((string) $o['delivery_address']) === '' && trim((string) $o['delivery_city']) === '') {
                    throw new RuntimeException('Add a delivery address before dispatching (edit is only possible on quotations - use the notes field or re-open).');
                }
                $short = bb_stock_shortages($pdo, $id);
                if ($short) {
                    $m = array_map(static fn($r) => $r['name'] . ': need ' . bb_num($r['need']) . ', in stock ' . bb_num($r['stock_qty']), $short);
                    throw new RuntimeException('Not enough stock to dispatch - ' . implode('; ', $m) . '. Add stock in Bamboo > Stock first.');
                }
                if (!$o['invoice_number']) {
                    $sets[] = 'invoice_number = :inv'; $p[':inv'] = tl_next_sequence($pdo, 'bamboo_invoice', 'BINV'); $sets[] = 'invoice_date = CURDATE()';
                }
                if (!$o['due_date']) {
                    $sets[] = 'due_date = DATE_ADD(CURDATE(), INTERVAL ' . max(0, (int) bb_settings()['payment_due_days']) . ' DAY)';
                }
                $sets[] = 'dispatched_at = COALESCE(dispatched_at, NOW())';
                $title = 'Dispatched';
            }
            if ($new === 'delivered') { $sets[] = 'delivered_at = COALESCE(delivered_at, NOW())'; $title = 'Delivered'; }
            if ($new === 'processing') { $title = 'Order is being prepared'; }
            if ($new === 'cancelled') {
                if ($note === '') { throw new RuntimeException('Please give a cancellation reason.'); }
                $sets[] = 'cancel_reason = :cr'; $p[':cr'] = $note;
                $title = 'Order cancelled';
            }
            if ($new === 'quotation' && $o['status'] === 'cancelled') {
                if ((float) $o['paid_amount'] > 0.009) { throw new RuntimeException('Refund the ' . tl_inr($o['paid_amount']) . ' received before re-opening this order.'); }
                $sets[] = 'cancel_reason = NULL';
                $title = 'Re-opened as quotation';
                $visible = false;
            }
            $pdo->prepare('UPDATE bamboo_orders SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($p);

            if ($new === 'dispatched' && !$o['stock_deducted']) {
                bb_apply_stock($pdo, $id, true, $adminId, 'Dispatch ' . ($o['order_number'] ?: $o['quote_number']));
                $pdo->prepare('UPDATE bamboo_orders SET stock_deducted = 1 WHERE id = :id')->execute([':id' => $id]);
            }
            if ($new === 'cancelled' && $o['stock_deducted']) {
                bb_apply_stock($pdo, $id, false, $adminId, 'Cancelled ' . ($o['order_number'] ?: $o['quote_number']));
                $pdo->prepare('UPDATE bamboo_orders SET stock_deducted = 0 WHERE id = :id')->execute([':id' => $id]);
            }
            bb_event($pdo, $id, $new, $title, $new === 'cancelled' ? $note : ($note !== '' ? $note : null), $visible, $adminId);
            $pdo->commit();
            log_activity($adminId, 'bamboo_order_status', "id={$id} {$o['status']}->{$new}");

            if (in_array($new, ['confirmed', 'dispatched', 'delivered'], true) && !empty($o['email'])) {
                $txt = ['confirmed' => 'Your order is confirmed.', 'dispatched' => 'Your order has been dispatched.', 'delivered' => 'Your order has been delivered. Thank you for your business.'][$new];
                $o2 = $load();
                tl_send_mail((string) $o['email'], (string) $o['customer_name'], $title . ' - ' . ($o2['order_number'] ?: $o['quote_number']),
                    tl_mail_shell($title, '<p>Hi ' . tl_e((string) $o['customer_name']) . ',</p><p>' . $txt . '</p>', bb_public_url((string) $o['public_token']), 'View order'));
            }
            $ok('Status updated to "' . $OS[$new]['label'] . '".' . ($new === 'cancelled' && (float) $o['paid_amount'] > 0.009 ? ' Note: ' . tl_inr($o['paid_amount']) . ' was received - record a refund if you return it.' : ''));

        } elseif ($action === 'add_payment') {
            $o = $load();
            if (!$o) { throw new RuntimeException('Order not found.'); }
            $type = ($_POST['entry_type'] ?? 'payment') === 'refund' ? 'refund' : 'payment';
            $amt = (isset($_POST['amount']) && is_numeric($_POST['amount'])) ? round((float) $_POST['amount'], 2) : 0.0;
            $mode = (string) ($_POST['payment_mode'] ?? 'cash');
            $mode = isset(bb_payment_modes()[$mode]) ? $mode : 'other';
            $ref  = mb_substr(clean_input((string) ($_POST['reference'] ?? '')), 0, 100);
            $date = bb_valid_date((string) ($_POST['payment_date'] ?? ''));
            $rem  = mb_substr(clean_input((string) ($_POST['remarks'] ?? '')), 0, 255);
            if ($amt <= 0) { throw new RuntimeException('Enter a valid amount.'); }
            if (!$date || $date > date('Y-m-d')) { throw new RuntimeException('Payment date must be a valid date that is not in the future.'); }
            $paid = (float) $o['paid_amount'];
            $balance = round((float) $o['grand_total'] - $paid, 2);
            if ($type === 'payment') {
                if ($o['status'] === 'quotation') { throw new RuntimeException('Confirm the order before recording payments.'); }
                if ($o['status'] === 'cancelled') { throw new RuntimeException('This order is cancelled. Only refunds can be recorded.'); }
                if ($amt > $balance + 0.005) { throw new RuntimeException('Amount is more than the balance due (' . tl_inr($balance) . ').'); }
            } elseif ($amt > $paid + 0.005) {
                throw new RuntimeException('Refund is more than the amount received (' . tl_inr($paid) . ').');
            }
            $pdo->beginTransaction();
            $rcpt = tl_next_sequence($pdo, 'bamboo_receipt', 'BRC');
            $pdo->prepare('INSERT INTO bamboo_payments (order_id, receipt_number, entry_type, amount, payment_mode, reference, payment_date, remarks, created_by, created_at)
                           VALUES (:o,:r,:t,:a,:m,:ref,:d,:rem,:admin,NOW())')
                ->execute([':o' => $id, ':r' => $rcpt, ':t' => $type, ':a' => $amt, ':m' => $mode, ':ref' => $ref ?: null, ':d' => $date . ' ' . date('H:i:s'), ':rem' => $rem ?: null, ':admin' => $adminId]);
            bb_recalc_payments($pdo, $id);
            bb_event($pdo, $id, $type, ($type === 'refund' ? 'Refund of ' : 'Payment of ') . tl_inr($amt) . ' recorded', bb_payment_modes()[$mode], $type === 'payment', $adminId);
            $pdo->commit();
            log_activity($adminId, 'bamboo_' . $type, "order_id={$id} amount={$amt}");
            $ok(($type === 'refund' ? 'Refund' : 'Payment') . ' of ' . tl_inr($amt) . ' recorded (' . $rcpt . ').');

        } elseif ($action === 'send_quote') {
            $o = $load();
            if (!$o || $o['status'] !== 'quotation') { throw new RuntimeException('Only quotations can be sent.'); }
            $sent = bb_mail_customer_quote($o);
            $pdo->prepare('UPDATE bamboo_orders SET quote_sent_at = NOW() WHERE id = :id')->execute([':id' => $id]);
            bb_event($pdo, $id, 'quote_sent', $sent ? 'Quotation e-mailed to customer' : 'Quotation marked as sent', null, false, $adminId);
            $ok($sent ? 'Quotation e-mailed to ' . $o['email'] . '.' : 'Marked as sent. (No e-mail was sent - the customer has no e-mail or SMTP is not set up. Use the WhatsApp / copy-link buttons.)');
            if ($o['enquiry_id']) {
                $pdo->prepare("UPDATE bamboo_enquiries SET status = IF(status IN ('new','contacted'),'quoted',status), updated_at = NOW() WHERE id = :e")->execute([':e' => $o['enquiry_id']]);
            }

        } elseif ($action === 'notes') {
            $o = $load();
            if (!$o) { throw new RuntimeException('Order not found.'); }
            $pdo->prepare('UPDATE bamboo_orders SET internal_notes = :n, expected_dispatch = :ed, due_date = :dd, updated_at = NOW() WHERE id = :id')
                ->execute([':n' => mb_substr(trim((string) ($_POST['internal_notes'] ?? '')), 0, 3000) ?: null,
                           ':ed' => bb_valid_date((string) ($_POST['expected_dispatch'] ?? '')), ':dd' => bb_valid_date((string) ($_POST['due_date'] ?? '')), ':id' => $id]);
            $ok('Saved.');

        } elseif ($action === 'delete_quote') {
            $o = $load();
            if (!$o || $o['status'] !== 'quotation' || (float) $o['paid_amount'] > 0) { throw new RuntimeException('Only unpaid quotations can be deleted. Cancel the order instead.'); }
            $pdo->prepare('UPDATE bamboo_orders SET deleted_at = NOW() WHERE id = :id')->execute([':id' => $id]);
            if ($o['enquiry_id']) {
                $pdo->prepare('UPDATE bamboo_enquiries SET order_id = NULL, updated_at = NOW() WHERE id = :e AND order_id = :o')->execute([':e' => $o['enquiry_id'], ':o' => $id]);
            }
            log_activity($adminId, 'bamboo_quote_deleted', "id={$id}");
            $ok('Quotation deleted.');
            header('Location: bamboo_orders.php');
            exit;

        } elseif ($action === 'book_truck') {
            $o = $load();
            if (!$o) { throw new RuntimeException('Order not found.'); }
            if ($o['transport_booking_id']) { throw new RuntimeException('A truck is already booked for this order.'); }
            $bb = bb_settings();
            $pdo->beginTransaction();
            $trackingId = tl_next_sequence($pdo, 'tracking_id', 'TRK');
            $itemsDesc = implode(', ', array_map(fn($r) => $r['description'], $pdo->query('SELECT description FROM bamboo_order_items WHERE order_id = ' . (int) $id)->fetchAll()));
            $pdo->prepare(
                "INSERT INTO transport_bookings (
                    tracking_id, customer_name, company_name, email, phone, service_type, vehicle_type, cargo_type, cargo_description,
                    pickup_address, pickup_city, pickup_state, pickup_contact_person, pickup_contact_number,
                    drop_address, drop_city, drop_state, drop_pincode, drop_contact_person, drop_contact_number,
                    status, priority, customer_notes, tracking_enabled,
                    total_amount, gst_amount, toll_amount, fuel_charge, labour_charge, extra_charge, discount,
                    grand_total, advance_paid, paid_amount, balance_amount, payment_status,
                    source, created_by, created_at, updated_at
                ) VALUES (
                    :tid, :cn, :co, :em, :ph, 'Bamboo Trading Delivery', 'Not sure - please advise', 'Bamboo / Timber', :desc,
                    :pa, :pc, :ps, :pcp, :pcn,
                    :da, :dc, :ds, :dp, :dcp, :dcn,
                    'pending', 'normal', :notes, 1,
                    0,0,0,0,0,0,0, 0,0,0,0,'unpaid',
                    'bamboo', :admin, NOW(), NOW()
                )"
            )->execute([
                ':tid' => $trackingId, ':cn' => $o['customer_name'], ':co' => $o['company_name'] ?: null, ':em' => $o['email'] ?: null, ':ph' => $o['phone'],
                ':desc' => $itemsDesc ?: null,
                ':pa' => $bb['dispatch_address'], ':pc' => $bb['dispatch_city'], ':ps' => $bb['dispatch_state'], ':pcp' => 'Biome Enterprises Dispatch', ':pcn' => tl_settings()['company']['phone'],
                ':da' => $o['delivery_address'], ':dc' => $o['delivery_city'], ':ds' => $o['delivery_state'], ':dp' => $o['delivery_pincode'],
                ':dcp' => $o['customer_name'], ':dcn' => $o['phone'],
                ':notes' => 'Linked bamboo order ' . ($o['order_number'] ?: $o['quote_number']),
                ':admin' => $_SESSION['admin_id'],
            ]);
            $bookingId = (int) $pdo->lastInsertId();
            tl_add_timeline($pdo, $bookingId, $trackingId, 'pending', 'Booking created from bamboo order', 'Dispatch of bamboo order ' . ($o['order_number'] ?: $o['quote_number']), $bb['dispatch_city'], true, (int) $_SESSION['admin_id']);
            $pdo->prepare('UPDATE bamboo_orders SET transport_booking_id = :bid, updated_at = NOW() WHERE id = :id')->execute([':bid' => $bookingId, ':id' => $id]);
            bb_event($pdo, $id, 'truck', 'Truck booked: ' . $trackingId, null, true, $adminId);
            $pdo->commit();
            log_activity((int) $_SESSION['admin_id'], 'bamboo_truck_booked', "order_id={$id} booking_id={$bookingId} tracking={$trackingId}");
            $_SESSION['flash_success_page'] = 'Truck booking ' . $trackingId . ' created. Assign a driver/vehicle and set the amount in Transport &gt; All Bookings.';
        }
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err($e->getMessage());   // our own, user-safe messages
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('bamboo_order_view: ' . $e->getMessage());
        $err('Something went wrong. Nothing was changed.');
    }
    header('Location: bamboo_order_view.php?id=' . $id);
    exit;
}

$o = $load();
if (!$o) { $_SESSION['flash_error_page'] = 'Order not found.'; header('Location: bamboo_orders.php'); exit; }

$items = $pdo->prepare('SELECT i.*, p.track_stock, p.stock_qty FROM bamboo_order_items i LEFT JOIN bamboo_products p ON p.id = i.product_id WHERE i.order_id = :id ORDER BY i.sort_order, i.id');
$items->execute([':id' => $id]); $items = $items->fetchAll();
$payments = $pdo->prepare('SELECT * FROM bamboo_payments WHERE order_id = :id ORDER BY payment_date DESC, id DESC');
$payments->execute([':id' => $id]); $payments = $payments->fetchAll();
$events = $pdo->prepare('SELECT e.*, a.full_name AS admin_name FROM bamboo_order_events e LEFT JOIN admins a ON a.id = e.created_by WHERE e.order_id = :id ORDER BY e.id DESC');
$events->execute([':id' => $id]); $events = $events->fetchAll();

$truck = null;
if ($o['transport_booking_id']) {
    $tb = $pdo->prepare('SELECT tracking_id, status FROM transport_bookings WHERE id = :id');
    $tb->execute([':id' => $o['transport_booking_id']]);
    $truck = $tb->fetch();
}
$balance = max(0, (float) $o['grand_total'] - (float) $o['paid_amount']);
$canBookTruck = !$o['transport_booking_id'] && !in_array($o['status'], ['quotation', 'cancelled'], true);
$tax = bb_tax_split($o);
$next = $FLOW[$o['status']] ?? [];
$overdue = $balance > 0.009 && $o['due_date'] && $o['due_date'] < date('Y-m-d') && !in_array($o['status'], ['quotation', 'cancelled'], true);
$short = ($o['status'] !== 'cancelled' && !$o['stock_deducted']) ? bb_stock_shortages($pdo, $id) : [];
$publicUrl = bb_public_url((string) $o['public_token']);
$no = $o['order_number'] ?: $o['quote_number'];
$waText = 'Hello ' . $o['customer_name'] . ', your quotation ' . $o['quote_number'] . ' from ' . tl_settings()['company']['name'] . ' (' . tl_inr($o['grand_total']) . '): ' . $publicUrl;

$actions = '<a href="bamboo_orders.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> All orders</a>';
tl_shell_top('Order ' . $no, 'Order overview', ['Bamboo Trading' => 'bamboo_dashboard.php'], $actions);
$sm = $OS[$o['status']] ?? $OS['quotation'];
?>
<div class="tx-hero">
  <div>
    <div class="tid"><i class="fa-solid fa-leaf"></i> <?= e($no) ?></div>
    <div class="ref">Quote: <?= e($o['quote_number']) ?><?= $o['invoice_number'] ? ' · Invoice: ' . e($o['invoice_number']) : '' ?> · Created <?= e(tl_dt($o['created_at'])) ?></div>
    <div class="route"><span><?= e($o['customer_name']) ?></span><?= $o['company_name'] ? ' <span style="opacity:.8">(' . e($o['company_name']) . ')</span>' : '' ?></div>
    <div class="badges"><?= tl_badge($sm['label'], $sm['class']) ?> <?= tl_payment_badge((string) $o['payment_status']) ?><?= $overdue ? ' ' . tl_badge('Payment overdue', 'danger') : '' ?>
      <?php if ($o['customer_response'] === 'accepted'): ?><?= tl_badge('Accepted by customer', 'success') ?><?php elseif ($o['customer_response'] === 'changes'): ?><?= tl_badge('Customer asked for changes', 'warning') ?><?php endif; ?></div>
  </div>
  <div class="tx-hero-actions">
    <?php if ($o['status'] === 'quotation'): ?><a href="bamboo_order_form.php?id=<?= $id ?>" class="btn btn-primary"><i class="fa-solid fa-pen"></i> Edit</a><?php endif; ?>
    <a href="bamboo_document.php?id=<?= $id ?>&amp;mode=quote" target="_blank" class="btn btn-secondary"><i class="fa-solid fa-file-lines"></i> Quotation</a>
    <?php if ($o['invoice_number']): ?><a href="bamboo_document.php?id=<?= $id ?>&amp;mode=invoice" target="_blank" class="btn btn-secondary"><i class="fa-solid fa-file-invoice"></i> Invoice</a><?php endif; ?>
  </div>
</div>

<?php if ($o['customer_response'] === 'changes' && $o['customer_message']): ?>
  <div class="tx-flash err" style="background:#fff7e6;border-color:#f5dfae;color:#8a5a00"><strong>Customer's message:</strong> <?= nl2br(e($o['customer_message'])) ?></div>
<?php endif; ?>
<?php if ($o['status'] === 'cancelled' && $o['cancel_reason']): ?><div class="tx-flash err">Cancelled: <?= e($o['cancel_reason']) ?></div><?php endif; ?>
<?php if ($short): ?><div class="tx-flash err" style="background:#fff7e6;border-color:#f5dfae;color:#8a5a00"><i class="fa-solid fa-triangle-exclamation"></i> Low stock for dispatch: <?= e(implode('; ', array_map(static fn($r) => $r['name'] . ' (need ' . bb_num($r['need']) . ', have ' . bb_num($r['stock_qty']) . ')', $short))) ?>. <a href="bamboo_stock.php">Add stock</a></div><?php endif; ?>

<div class="tx-grid">
  <div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-list"></i> Items</h3></div>
      <div class="tx-scroll"><table class="tx-table"><thead><tr><th>Description</th><th>HSN</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead><tbody>
        <?php foreach ($items as $it): ?><tr><td><?= e($it['description']) ?></td><td><?= e($it['hsn'] ?: bb_settings()['hsn']) ?></td><td class="r"><?= e(bb_num($it['quantity'])) ?> <?= e(bb_units()[$it['unit']] ?? $it['unit']) ?></td><td class="r"><?= e(number_format((float) $it['rate'], 2)) ?></td><td class="r"><?= e(number_format((float) $it['amount'], 2)) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="amount-strip" style="margin-top:14px">
        <div class="item"><div class="label">Subtotal</div><div class="value"><?= e(tl_inr($o['subtotal'])) ?></div></div>
        <?php if ((float) $o['discount'] > 0): ?><div class="item"><div class="label">Discount</div><div class="value">-<?= e(tl_inr($o['discount'])) ?></div></div><?php endif; ?>
        <?php if ((float) $o['transport_charge'] > 0): ?><div class="item"><div class="label">Transport</div><div class="value"><?= e(tl_inr($o['transport_charge'])) ?></div></div><?php endif; ?>
        <?php if ($tax['type'] === 'intra'): ?>
          <div class="item"><div class="label">CGST <?= e(bb_num($tax['half'])) ?>%</div><div class="value"><?= e(tl_inr($tax['cgst'])) ?></div></div>
          <div class="item"><div class="label">SGST <?= e(bb_num($tax['half'])) ?>%</div><div class="value"><?= e(tl_inr($tax['sgst'])) ?></div></div>
        <?php else: ?><div class="item"><div class="label">IGST <?= e(bb_num($tax['pct'])) ?>%</div><div class="value"><?= e(tl_inr($tax['igst'])) ?></div></div><?php endif; ?>
        <div class="item ok"><div class="label">Grand total</div><div class="value"><?= e(tl_inr($o['grand_total'])) ?></div></div>
        <div class="item ok"><div class="label">Received</div><div class="value"><?= e(tl_inr($o['paid_amount'])) ?></div></div>
        <div class="item due"><div class="label">Balance</div><div class="value"><?= e(tl_inr($balance)) ?></div></div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-truck"></i> Delivery</h3></div>
      <div class="info-grid">
        <div class="info-item"><div class="label">Deliver to</div><div class="value" style="font-weight:500"><?= nl2br(e($o['delivery_address'] ?: '-')) ?><br><span class="muted"><?= e(trim(($o['delivery_city'] ?? '') . ', ' . ($o['delivery_state'] ?? '') . ' ' . ($o['delivery_pincode'] ?? ''), ' ,')) ?></span></div></div>
        <div class="info-item"><div class="label">Phone</div><div class="value mono"><a href="tel:<?= e(preg_replace('/\D/', '', (string) $o['phone'])) ?>"><?= e($o['phone']) ?></a></div></div>
        <?php if ($o['dispatched_at']): ?><div class="info-item"><div class="label">Dispatched</div><div class="value"><?= e(tl_dt($o['dispatched_at'])) ?></div></div><?php endif; ?>
        <?php if ($o['delivered_at']): ?><div class="info-item"><div class="label">Delivered</div><div class="value"><?= e(tl_dt($o['delivered_at'])) ?></div></div><?php endif; ?>
      </div>
      <div style="margin-top:16px">
        <?php if ($truck): ?>
          <a class="btn btn-secondary" href="transport_view.php?id=<?= (int) $o['transport_booking_id'] ?>"><i class="fa-solid fa-truck-fast"></i> Truck booking <?= e($truck['tracking_id']) ?> (<?= e(ucfirst(str_replace('_', ' ', $truck['status']))) ?>)</a>
        <?php elseif ($canBookTruck): ?>
          <form method="post" onsubmit="return confirm('Create a truck booking for this order using your dispatch address as pickup?');">
            <?= csrf_field() ?><input type="hidden" name="action" value="book_truck"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-truck-fast"></i> Book a Truck Online</button>
          </form>
        <?php else: ?><p class="muted" style="font-size:.86rem">Confirm the order to enable truck booking.</p><?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-note-sticky"></i> Internal notes &amp; dates</h3></div>
      <form method="post" class="tx-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="notes">
        <div class="full"><label>Notes (never shown to the customer)</label><textarea name="internal_notes" rows="3" maxlength="3000"><?= e($o['internal_notes']) ?></textarea></div>
        <div><label>Expected dispatch</label><input type="date" name="expected_dispatch" value="<?= e($o['expected_dispatch']) ?>"></div>
        <div><label>Payment due date</label><input type="date" name="due_date" value="<?= e($o['due_date']) ?>"></div>
        <div style="align-self:end"><button class="btn btn-primary">Save</button></div>
      </form>
    </div>
  </div>

  <div>
    <?php if ($o['status'] === 'quotation'): ?>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-paper-plane"></i> Send quotation</h3></div>
      <?= $o['quote_sent_at'] ? '<p class="muted" style="font-size:.82rem;margin-top:0">Last sent ' . e(tl_dt($o['quote_sent_at'])) . ($o['quote_viewed_at'] ? ' · viewed ' . e(tl_dt($o['quote_viewed_at'])) : ' · not viewed yet') . '</p>' : '' ?>
      <div style="display:flex;flex-direction:column;gap:8px">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="send_quote"><button class="btn btn-primary" style="width:100%;justify-content:center"><i class="fa-solid fa-envelope"></i> E-mail to customer</button></form>
        <a class="btn btn-secondary" style="justify-content:center" target="_blank" rel="noopener" href="<?= e(bb_wa_link($o['phone'], $waText)) ?>"><i class="fa-brands fa-whatsapp"></i> Send on WhatsApp</a>
        <input type="text" readonly value="<?= e($publicUrl) ?>" onclick="this.select();document.execCommand('copy')" style="width:100%;border:1px solid #d4e0d9;border-radius:8px;padding:8px;font-size:.78rem" title="Click to copy the customer link">
      </div>
      <?php if ($o['valid_until']): ?><p class="muted" style="font-size:.8rem;margin-bottom:0">Valid until <?= e(date('d M Y', strtotime((string) $o['valid_until']))) ?><?= $o['valid_until'] < date('Y-m-d') ? ' <strong style="color:#b02a20">(expired)</strong>' : '' ?></p><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-diagram-next"></i> Workflow</h3></div>
      <?php if (!$next): ?><p class="muted" style="margin:0">This order is <strong><?= e($sm['label']) ?></strong>. No further steps.</p><?php endif; ?>
      <?php foreach ($next as $to): $isCancel = $to === 'cancelled'; ?>
        <form method="post" style="margin-bottom:10px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="update_status"><input type="hidden" name="status" value="<?= e($to) ?>">
          <?php if ($isCancel): ?><input name="note" placeholder="Cancellation reason (required)" required maxlength="255" style="width:100%;margin-bottom:6px;border:1px solid #d4e0d9;border-radius:8px;padding:8px 10px"><?php endif; ?>
          <?php if ($to === 'delivered' || $to === 'dispatched' || $to === 'processing'): ?><input name="note" placeholder="Note for the timeline (optional)" maxlength="255" style="width:100%;margin-bottom:6px;border:1px solid #d4e0d9;border-radius:8px;padding:8px 10px"><?php endif; ?>
          <button class="btn <?= $isCancel ? 'btn-danger' : 'btn-primary' ?>" style="width:100%;justify-content:center" <?= $isCancel ? 'onclick="return confirm(\'Cancel this order? Stock dispatched will be restored.\')"' : '' ?>>
            <i class="fa-solid <?= e($OS[$to]['icon']) ?>"></i> <?= $to === 'quotation' ? 'Re-open as quotation' : ($isCancel ? 'Cancel order' : 'Mark ' . e(strtolower($OS[$to]['label']))) ?></button>
        </form>
      <?php endforeach; ?>
      <?php if ($o['status'] === 'quotation'): ?>
        <form method="post" onsubmit="return confirm('Delete this quotation?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete_quote"><button class="btn btn-small btn-ghost" style="color:#b02a20"><i class="fa-solid fa-trash"></i> Delete quotation</button></form>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-indian-rupee-sign"></i> Payments</h3></div>
      <form method="post" class="tx-form" style="grid-template-columns:1fr"><?= csrf_field() ?><input type="hidden" name="action" value="add_payment"><input type="hidden" name="id" value="<?= $id ?>">
        <div><label>Type</label><select name="entry_type"><option value="payment">Payment received</option><option value="refund">Refund given</option></select></div>
        <div><label>Amount (&#8377;)</label><input name="amount" type="number" step="0.01" min="0.01" required value="<?= $balance > 0 ? e((string) $balance) : '' ?>"></div>
        <div><label>Mode</label><select name="payment_mode"><?php foreach (bb_payment_modes() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div><label>Reference (UTR / cheque no.)</label><input name="reference" maxlength="100"></div>
        <div><label>Date</label><input name="payment_date" type="date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div>
        <button class="btn btn-primary" type="submit" style="justify-content:center"<?= $o['status'] === 'quotation' ? ' disabled title="Confirm the order first"' : '' ?>><i class="fa-solid fa-plus"></i> Add entry</button>
      </form>
      <?php if ($payments): ?><div style="margin-top:14px;font-size:.82rem">
        <?php foreach ($payments as $p): $ref = $p['entry_type'] === 'refund'; ?><div style="display:flex;justify-content:space-between;padding:6px 0;border-top:1px dashed #e3ece7">
          <span><?= e(tl_dt($p['payment_date'], 'd M')) ?> · <?= e($p['receipt_number']) ?> · <?= e(bb_payment_modes()[$p['payment_mode']] ?? $p['payment_mode']) ?></span><strong style="color:<?= $ref ? '#b02a20' : '#14663f' ?>"><?= $ref ? '-' : '' ?><?= e(tl_inr($p['amount'])) ?></strong></div><?php endforeach; ?>
      </div><?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-clock-rotate-left"></i> Timeline</h3></div>
      <div class="mini-timeline">
        <?php foreach ($events as $ev): ?><div class="mini-tl-event"><div class="mini-tl-time"><?= e(tl_dt($ev['created_at'], 'd M, h:i A')) ?><?= $ev['admin_name'] ? ' &middot; ' . e($ev['admin_name']) : '' ?><?= $ev['customer_visible'] ? '' : ' &middot; <i class="fa-solid fa-eye-slash" title="Internal"></i>' ?></div>
          <div class="mini-tl-title"><?= e($ev['title']) ?></div><?php if ($ev['description']): ?><div class="mini-tl-desc"><?= e($ev['description']) ?></div><?php endif; ?></div><?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php tl_shell_bottom();
