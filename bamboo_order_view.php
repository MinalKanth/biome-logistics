<?php
/**
 * admin/bamboo_order_view.php?id=X - order detail: status, payments, "Book a Truck Online".
 * "Book a Truck Online" creates a transport_bookings row (pickup = your bamboo dispatch point from
 * transport_settings.php, drop = this order's delivery address) so the shipment can be tracked exactly
 * like any transport booking, on the same /track page.
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

$load = function () use ($pdo, $id) {
    $s = $pdo->prepare('SELECT * FROM bamboo_orders WHERE id = :id AND deleted_at IS NULL');
    $s->execute([':id' => $id]);
    return $s->fetch();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $action = (string) ($_POST['action'] ?? '');
    try {
        if ($action === 'update_status') {
            $new = (string) ($_POST['status'] ?? '');
            if (!isset($OS[$new])) { throw new RuntimeException('bad status'); }
            $pdo->beginTransaction();
            $cur = $pdo->prepare('SELECT * FROM bamboo_orders WHERE id = :id FOR UPDATE');
            $cur->execute([':id' => $id]);
            $o = $cur->fetch();
            if (!$o) { throw new RuntimeException('order missing'); }

            $sets = ['status = :s', 'updated_at = NOW()'];
            $p = [':s' => $new, ':id' => $id];
            if ($new === 'confirmed' && !$o['order_number']) {
                $sets[] = 'order_number = :on'; $p[':on'] = tl_next_sequence($pdo, 'bamboo_order', 'BOR');
                $sets[] = 'accepted_at = NOW()';
            }
            if ($new === 'dispatched') {
                if (!$o['invoice_number']) { $sets[] = 'invoice_number = :inv'; $p[':inv'] = tl_next_sequence($pdo, 'bamboo_invoice', 'BINV'); $sets[] = 'invoice_date = CURDATE()'; }
                $sets[] = 'dispatched_at = COALESCE(dispatched_at, NOW())';
            }
            if ($new === 'delivered') { $sets[] = 'delivered_at = COALESCE(delivered_at, NOW())'; }
            $pdo->prepare('UPDATE bamboo_orders SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($p);

            if ($new === 'dispatched' && !$o['stock_deducted']) {
                bb_apply_stock($pdo, $id, true);
                $pdo->prepare('UPDATE bamboo_orders SET stock_deducted = 1 WHERE id = :id')->execute([':id' => $id]);
            }
            if ($new === 'cancelled' && $o['stock_deducted']) {
                bb_apply_stock($pdo, $id, false);
                $pdo->prepare('UPDATE bamboo_orders SET stock_deducted = 0 WHERE id = :id')->execute([':id' => $id]);
            }
            $pdo->commit();
            log_activity((int) $_SESSION['admin_id'], 'bamboo_order_status', "id={$id} status={$new}");
            $_SESSION['flash_success_page'] = 'Status updated to "' . $OS[$new]['label'] . '".';
        } elseif ($action === 'add_payment') {
            $amt = (isset($_POST['amount']) && is_numeric($_POST['amount'])) ? (float) $_POST['amount'] : 0.0;
            $mode = mb_substr(clean_input((string) ($_POST['payment_mode'] ?? 'cash')), 0, 30);
            $ref  = mb_substr(clean_input((string) ($_POST['reference'] ?? '')), 0, 100);
            $date = (string) ($_POST['payment_date'] ?? date('Y-m-d'));
            if ($amt <= 0) { throw new RuntimeException('invalid amount'); }
            $pdo->beginTransaction();
            $rcpt = tl_next_sequence($pdo, 'bamboo_receipt', 'BRC');
            $pdo->prepare('INSERT INTO bamboo_payments (order_id, receipt_number, amount, payment_mode, reference, payment_date, created_by, created_at) VALUES (:o,:r,:a,:m,:ref,:d,:admin,NOW())')
                ->execute([':o' => $id, ':r' => $rcpt, ':a' => $amt, ':m' => $mode, ':ref' => $ref ?: null, ':d' => $date . ' 00:00:00', ':admin' => $_SESSION['admin_id']]);
            bb_recalc_payments($pdo, $id);
            $pdo->commit();
            log_activity((int) $_SESSION['admin_id'], 'bamboo_payment_added', "order_id={$id} amount={$amt}");
            $_SESSION['flash_success_page'] = 'Payment of ' . tl_inr($amt) . ' recorded (' . $rcpt . ').';
        } elseif ($action === 'book_truck') {
            $o = $load();
            if (!$o) { throw new RuntimeException('order missing'); }
            if ($o['transport_booking_id']) { throw new RuntimeException('already booked'); }
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
            $pdo->commit();
            log_activity((int) $_SESSION['admin_id'], 'bamboo_truck_booked', "order_id={$id} booking_id={$bookingId} tracking={$trackingId}");
            $_SESSION['flash_success_page'] = 'Truck booking ' . $trackingId . ' created. Assign a driver/vehicle and set the amount in Transport &gt; All Bookings.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('bamboo_order_view: ' . $e->getMessage());
        $_SESSION['flash_error_page'] = 'Something went wrong. Please try again.';
    }
    header('Location: bamboo_order_view.php?id=' . $id);
    exit;
}

$o = $load();
if (!$o) { $_SESSION['flash_error'] = 'Order not found.'; header('Location: bamboo_orders.php'); exit; }

$items = $pdo->prepare('SELECT * FROM bamboo_order_items WHERE order_id = :id ORDER BY sort_order, id');
$items->execute([':id' => $id]); $items = $items->fetchAll();

$payments = $pdo->prepare('SELECT * FROM bamboo_payments WHERE order_id = :id ORDER BY payment_date DESC, id DESC');
$payments->execute([':id' => $id]); $payments = $payments->fetchAll();

$truck = null;
if ($o['transport_booking_id']) {
    $tb = $pdo->prepare('SELECT tracking_id, status FROM transport_bookings WHERE id = :id');
    $tb->execute([':id' => $o['transport_booking_id']]);
    $truck = $tb->fetch();
}
$balance = max(0, (float) $o['grand_total'] - (float) $o['paid_amount']);
$canBookTruck = !$o['transport_booking_id'] && !in_array($o['status'], ['quotation', 'cancelled'], true);

$actions = '<a href="bamboo_orders.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> All orders</a>';
tl_shell_top('Order ' . ($o['order_number'] ?: $o['quote_number']), 'Order overview', ['Bamboo Trading' => 'bamboo_orders.php'], $actions);
$sm = $OS[$o['status']] ?? $OS['quotation'];
?>
<div class="tx-hero">
  <div>
    <div class="tid"><i class="fa-solid fa-leaf"></i> <?= e($o['order_number'] ?: $o['quote_number']) ?></div>
    <div class="ref">Quote: <?= e($o['quote_number']) ?><?= $o['invoice_number'] ? ' · Invoice: ' . e($o['invoice_number']) : '' ?> · Created <?= e(tl_dt($o['created_at'])) ?></div>
    <div class="route"><span><?= e($o['customer_name']) ?></span><?= $o['company_name'] ? ' <span style="opacity:.8">(' . e($o['company_name']) . ')</span>' : '' ?></div>
    <div class="badges"><?= tl_badge($sm['label'], $sm['class']) ?> <?= tl_payment_badge((string) $o['payment_status']) ?></div>
  </div>
  <div class="tx-hero-actions">
    <?php if ($o['status'] === 'quotation'): ?><a href="bamboo_order_form.php?id=<?= $id ?>" class="btn btn-primary"><i class="fa-solid fa-pen"></i> Edit</a><?php endif; ?>
    <a href="bamboo_document.php?id=<?= $id ?>&mode=quote" target="_blank" class="btn btn-secondary"><i class="fa-solid fa-file-lines"></i> Quotation</a>
    <?php if ($o['invoice_number']): ?><a href="bamboo_document.php?id=<?= $id ?>&mode=invoice" target="_blank" class="btn btn-secondary"><i class="fa-solid fa-file-invoice"></i> Invoice</a><?php endif; ?>
  </div>
</div>

<div class="tx-grid">
  <div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-list"></i> Items</h3></div>
      <div class="tx-scroll"><table class="tx-table"><thead><tr><th>Description</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead><tbody>
        <?php foreach ($items as $it): ?><tr><td><?= e($it['description']) ?></td><td class="r"><?= e(bb_num($it['quantity'])) ?> <?= e(bb_units()[$it['unit']] ?? $it['unit']) ?></td><td class="r"><?= e(number_format((float) $it['rate'], 2)) ?></td><td class="r"><?= e(number_format((float) $it['amount'], 2)) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <div class="amount-strip" style="margin-top:14px">
        <div class="item"><div class="label">Subtotal</div><div class="value"><?= e(tl_inr($o['subtotal'])) ?></div></div>
        <div class="item"><div class="label">GST</div><div class="value"><?= e(tl_inr($o['gst_amount'])) ?></div></div>
        <div class="item ok"><div class="label">Grand total</div><div class="value"><?= e(tl_inr($o['grand_total'])) ?></div></div>
        <div class="item due"><div class="label">Balance</div><div class="value"><?= e(tl_inr($balance)) ?></div></div>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-truck"></i> Delivery</h3></div>
      <div class="info-grid">
        <div class="info-item"><div class="label">Deliver to</div><div class="value" style="font-weight:500"><?= nl2br(e($o['delivery_address'] ?: '—')) ?><br><span class="muted"><?= e(trim(($o['delivery_city'] ?? '') . ', ' . ($o['delivery_state'] ?? '') . ' ' . ($o['delivery_pincode'] ?? ''), ' ,')) ?></span></div></div>
        <div class="info-item"><div class="label">Phone</div><div class="value mono"><?= e($o['phone']) ?></div></div>
      </div>
      <div style="margin-top:16px">
        <?php if ($truck): ?>
          <a class="btn btn-secondary" href="transport_view.php?id=<?= (int) $o['transport_booking_id'] ?>"><i class="fa-solid fa-truck-fast"></i> Truck booking <?= e($truck['tracking_id']) ?> (<?= e(ucfirst(str_replace('_', ' ', $truck['status']))) ?>)</a>
        <?php elseif ($canBookTruck): ?>
          <form method="post" onsubmit="return confirm('Create a truck booking for this order using your dispatch address as pickup?');">
            <?= csrf_field() ?><input type="hidden" name="action" value="book_truck"><input type="hidden" name="id" value="<?= $id ?>">
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-truck-fast"></i> Book a Truck Online</button>
          </form>
        <?php else: ?>
          <p class="muted" style="font-size:.86rem">Confirm the order to enable truck booking.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-bolt"></i> Status</h3></div>
      <form method="post" class="tx-form" style="grid-template-columns:1fr">
        <?= csrf_field() ?><input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="<?= $id ?>">
        <div><label>Order status</label><select name="status" required>
          <?php foreach ($OS as $k => $m): ?><option value="<?= e($k) ?>"<?= $o['status'] === $k ? ' selected' : '' ?>><?= e($m['label']) ?></option><?php endforeach; ?>
        </select></div>
        <button class="btn btn-primary" type="submit" style="justify-content:center"><i class="fa-solid fa-check"></i> Update status</button>
      </form>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-indian-rupee-sign"></i> Record payment</h3></div>
      <form method="post" class="tx-form" style="grid-template-columns:1fr">
        <?= csrf_field() ?><input type="hidden" name="action" value="add_payment"><input type="hidden" name="id" value="<?= $id ?>">
        <div><label>Amount (₹)</label><input name="amount" type="number" step="0.01" min="0.01" max="<?= max(0.01, $balance) ?>" required></div>
        <div><label>Mode</label><select name="payment_mode"><option value="cash">Cash</option><option value="upi">UPI</option><option value="bank_transfer">Bank transfer</option><option value="cheque">Cheque</option></select></div>
        <div><label>Reference (UTR/cheque no.)</label><input name="reference" maxlength="100"></div>
        <div><label>Date</label><input name="payment_date" type="date" value="<?= e(date('Y-m-d')) ?>"></div>
        <button class="btn btn-primary" type="submit" style="justify-content:center"<?= $balance <= 0 ? ' disabled' : '' ?>><i class="fa-solid fa-plus"></i> Add payment</button>
      </form>
      <?php if ($payments): ?><div style="margin-top:14px;font-size:.82rem">
        <?php foreach ($payments as $p): ?><div style="display:flex;justify-content:space-between;padding:6px 0;border-top:1px dashed #e3ece7">
          <span><?= e(tl_dt($p['payment_date'], 'd M')) ?> · <?= e($p['receipt_number']) ?></span><strong><?= e(tl_inr($p['amount'])) ?></strong></div><?php endforeach; ?>
      </div><?php endif; ?>
    </div>
  </div>
</div>
<?php tl_shell_bottom(); ?>