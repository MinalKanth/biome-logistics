<?php
/**
 * admin/cab_view.php - one booking: workflow, vehicle/driver assignment (with clash
 * detection), fare, payments, notes, customer-visible timeline.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/cab_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$adminId = (int) $_SESSION['admin_id'];

$load = static function () use ($pdo, $id): ?array {
    $s = $pdo->prepare('SELECT * FROM cab_booking_requests WHERE id = :id AND deleted_at IS NULL');
    $s->execute([':id' => $id]);
    return $s->fetch() ?: null;
};
$b = $id > 0 ? $load() : null;
if (!$b) {
    $_SESSION['flash_error_page'] = 'Booking not found.';
    header('Location: cab_bookings.php');
    exit;
}

$ST   = cab_statuses();
$FLOW = cab_status_flow();
$flash = static function (string $type, string $msg): void {
    $_SESSION[$type === 'ok' ? 'flash_success_page' : 'flash_error_page'] = $msg;
};
$money = static fn($v): float => max(0.0, round((float) str_replace(',', '', (string) $v), 2));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {

            case 'status': {
                $to = (string) ($_POST['to'] ?? '');
                if (!isset($ST[$to]) || !in_array($to, $FLOW[$b['status']] ?? [], true)) {
                    $flash('err', 'That status change is not allowed from "' . ($ST[$b['status']]['label'] ?? $b['status']) . '".');
                    break;
                }
                $note = mb_substr(clean_input((string) ($_POST['note'] ?? '')), 0, 255);
                $set = ['status = :s', 'updated_at = NOW()'];
                $par = [':s' => $to, ':id' => $id];
                $title = 'Status changed to ' . $ST[$to]['label'];
                $visible = true;
                $mail = null;

                if ($to === 'confirmed' && (float) $b['total_fare'] <= 0) {
                    $flash('err', 'Set the fare before confirming the booking.');
                    break 1;
                }
                if ($to === 'confirmed') {
                    $mail = ['Booking confirmed', 'Your cab booking is confirmed for <b>' . e($b['journey_date'] ?? 'the requested date') . '</b>. Total fare: <b>' . e(tl_inr($b['total_fare'])) . '</b>. We will share vehicle and driver details before pickup.'];
                    $title = 'Booking confirmed';
                }
                if ($to === 'cancelled') {
                    if ($note === '') {
                        $flash('err', 'Please give a cancellation reason.');
                        break 1;
                    }
                    $set[] = 'cancel_reason = :cr';
                    $par[':cr'] = $note;
                    $title = 'Booking cancelled';
                    $mail = ['Booking cancelled', 'Your cab booking has been cancelled. Reason: ' . e($note)];
                }
                if ($to === 'on_trip') {
                    if (!$b['vehicle_id']) {
                        $flash('err', 'Assign a vehicle before starting the trip.');
                        break 1;
                    }
                    $odo = ($_POST['odometer'] ?? '') !== '' ? (int) $_POST['odometer'] : null;
                    $set[] = 'started_at = NOW()';
                    $set[] = 'odometer_start = :os';
                    $par[':os'] = $odo;
                    $title = 'Trip started';
                }
                if ($to === 'completed') {
                    $odoEnd = ($_POST['odometer'] ?? '') !== '' ? (int) $_POST['odometer'] : null;
                    if ($odoEnd !== null && $b['odometer_start'] !== null && $odoEnd < (int) $b['odometer_start']) {
                        $flash('err', 'Closing odometer cannot be lower than the starting reading.');
                        break 1;
                    }
                    $set[] = 'completed_at = NOW()';
                    $set[] = 'odometer_end = :oe';
                    $par[':oe'] = $odoEnd;
                    $title = 'Trip completed';
                    $mail = ['Trip completed', 'Thank you for travelling with Biome Enterprises. We hope you had a comfortable journey.'];
                    if ($odoEnd !== null && $b['vehicle_id']) {
                        $pdo->prepare('UPDATE cab_vehicles SET odometer = GREATEST(odometer, :o), updated_at = NOW() WHERE id = :v')->execute([':o' => $odoEnd, ':v' => $b['vehicle_id']]);
                    }
                }
                if ($to === 'new' && $b['status'] === 'cancelled') {
                    $set[] = 'cancel_reason = NULL';
                    $title = 'Booking re-opened';
                }
                $pdo->beginTransaction();
                $pdo->prepare('UPDATE cab_booking_requests SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($par);
                cab_timeline($pdo, $id, $to, $title, $note !== '' ? $note : null, $visible, $adminId);
                $pdo->commit();
                log_activity($adminId, 'cab_status', "id={$id} {$b['status']}->{$to}");
                if ($mail) {
                    cab_mail_customer_status($b, $mail[0], $mail[1]);
                }
                $flash('ok', 'Status updated to ' . $ST[$to]['label'] . '.');
                break;
            }

            case 'assign': {
                $vid = (int) ($_POST['vehicle_id'] ?? 0) ?: null;
                $did = (int) ($_POST['driver_id'] ?? 0) ?: null;
                if (!in_array($b['status'], ['confirmed', 'assigned'], true)) {
                    $flash('err', 'Confirm the booking before assigning a vehicle.');
                    break;
                }
                if (!$b['journey_date']) {
                    $flash('err', 'This booking has no journey date, so availability cannot be checked. Edit the booking first.');
                    break;
                }
                if ($vid) {
                    $v = $pdo->prepare("SELECT * FROM cab_vehicles WHERE id = :i AND deleted_at IS NULL");
                    $v->execute([':i' => $vid]);
                    $veh = $v->fetch();
                    if (!$veh || $veh['status'] !== 'available') {
                        $flash('err', 'That vehicle is not available (maintenance or inactive).');
                        break;
                    }
                }
                if ($did) {
                    $d = $pdo->prepare("SELECT status FROM cab_drivers WHERE id = :i AND deleted_at IS NULL");
                    $d->execute([':i' => $did]);
                    if ($d->fetchColumn() !== 'active') {
                        $flash('err', 'That driver is not active.');
                        break;
                    }
                }
                $end = $b['end_date'] ?: $b['journey_date'];
                $clash = cab_conflicts($pdo, $vid, $did, (string) $b['journey_date'], (string) $end, $id);
                if ($clash) {
                    $c = $clash[0];
                    $what = ($vid && (int) $c['vehicle_id'] === $vid) ? 'vehicle' : 'driver';
                    $flash('err', "Clash: that {$what} is already booked for {$c['reference']} ({$c['full_name']}, " . date('d M', strtotime((string) $c['journey_date'])) . ').');
                    break;
                }
                $pdo->beginTransaction();
                $newStatus = $vid ? 'assigned' : $b['status'];
                $pdo->prepare('UPDATE cab_booking_requests SET vehicle_id = :v, driver_id = :d, status = :s, updated_at = NOW() WHERE id = :id')
                    ->execute([':v' => $vid, ':d' => $did, ':s' => $newStatus, ':id' => $id]);
                $desc = $vid ? ($veh['name'] . ($veh['reg_number'] ? ' (' . $veh['reg_number'] . ')' : '')) : 'Vehicle cleared';
                cab_timeline($pdo, $id, $newStatus, $vid ? 'Vehicle assigned' : 'Vehicle unassigned', $desc, true, $adminId);
                $pdo->commit();
                log_activity($adminId, 'cab_assign', "id={$id} vehicle={$vid} driver={$did}");
                $flash('ok', 'Assignment saved.');
                break;
            }

            case 'fare': {
                if (in_array($b['status'], ['completed', 'cancelled'], true) && $b['invoice_number']) {
                    $flash('err', 'An invoice has already been issued; fare is locked.');
                    break;
                }
                $b2 = $b;
                $b2['base_fare'] = $money($_POST['base_fare'] ?? 0);
                $b2['extra_charges'] = $money($_POST['extra_charges'] ?? 0);
                $b2['discount'] = $money($_POST['discount'] ?? 0);
                $gp = (float) ($_POST['gst_pct'] ?? 0);
                $b2['gst_pct'] = ($gp >= 0 && $gp <= 28) ? $gp : 0;
                $dep = $money($_POST['security_deposit'] ?? 0);
                if ($b2['discount'] > $b2['base_fare'] + $b2['extra_charges']) {
                    $flash('err', 'Discount cannot exceed the fare.');
                    break;
                }
                $pdo->prepare('UPDATE cab_booking_requests SET base_fare=:b, extra_charges=:e, discount=:d, gst_pct=:g, security_deposit=:s, fare_source=\'manual\' WHERE id=:id')
                    ->execute([':b' => $b2['base_fare'], ':e' => $b2['extra_charges'], ':d' => $b2['discount'], ':g' => $b2['gst_pct'], ':s' => $dep, ':id' => $id]);
                cab_recalc($pdo, $id);
                cab_timeline($pdo, $id, (string) $b['status'], 'Fare updated', null, false, $adminId);
                log_activity($adminId, 'cab_fare', "id={$id}");
                $flash('ok', 'Fare saved.');
                break;
            }

            case 'reprice': {
                $km = $b['distance_km'] !== null ? (float) $b['distance_km'] : null;
                $est = cab_estimate($pdo, (string) $b['vehicle_type'], (string) $b['service_type'], (int) $b['days'], $km);
                if (!$est['ok']) {
                    $flash('err', 'No active rate found for this vehicle and service. Set the fare manually or activate the rate.');
                    break;
                }
                $pdo->prepare('UPDATE cab_booking_requests SET base_fare=:b, gst_pct=:g, security_deposit=:s, fare_source=\'estimate\' WHERE id=:id')
                    ->execute([':b' => $est['base'], ':g' => $est['gst_pct'], ':s' => $est['deposit'], ':id' => $id]);
                cab_recalc($pdo, $id);
                cab_timeline($pdo, $id, (string) $b['status'], 'Fare recalculated from rate card', null, false, $adminId);
                $flash('ok', 'Fare recalculated from the rate card.');
                break;
            }

            case 'payment': {
                $amt = $money($_POST['amount'] ?? 0);
                $type = ($_POST['entry_type'] ?? 'payment') === 'refund' ? 'refund' : 'payment';
                $mode = (string) ($_POST['payment_mode'] ?? '');
                $mode = in_array($mode, cab_payment_modes(), true) ? $mode : 'Cash';
                $ref  = mb_substr(clean_input((string) ($_POST['reference'] ?? '')), 0, 100);
                $rem  = mb_substr(clean_input((string) ($_POST['remarks'] ?? '')), 0, 255);
                if ($amt <= 0) {
                    $flash('err', 'Enter a valid amount.');
                    break;
                }
                $t = cab_totals($b);
                if ($type === 'payment' && $amt > $t['balance'] + 0.005) {
                    $flash('err', 'Amount is more than the balance due (' . tl_inr($t['balance']) . ').');
                    break;
                }
                if ($type === 'refund' && $amt > $t['paid'] + 0.005) {
                    $flash('err', 'Refund is more than the amount received.');
                    break;
                }
                $pdo->beginTransaction();
                $rc = tl_next_sequence($pdo, 'cab_receipt', 'CRC');
                $pdo->prepare('INSERT INTO cab_payments (booking_id, receipt_number, entry_type, amount, payment_mode, reference, payment_date, remarks, created_by, created_at)
                               VALUES (:b, :rc, :t, :a, :m, :r, NOW(), :rem, :by, NOW())')
                    ->execute([':b' => $id, ':rc' => $rc, ':t' => $type, ':a' => $amt, ':m' => $mode, ':r' => $ref ?: null, ':rem' => $rem ?: null, ':by' => $adminId]);
                cab_recalc($pdo, $id);
                cab_timeline($pdo, $id, (string) $b['status'], ($type === 'refund' ? 'Refund of ' : 'Payment of ') . tl_inr($amt) . ' recorded', $mode, $type === 'payment', $adminId);
                $pdo->commit();
                log_activity($adminId, 'cab_' . $type, "id={$id} amt={$amt}");
                $flash('ok', ($type === 'refund' ? 'Refund' : 'Payment') . ' recorded (' . $rc . ').');
                break;
            }

            case 'notes': {
                $notes = mb_substr(trim((string) ($_POST['internal_notes'] ?? '')), 0, 3000);
                $fu = cab_valid_date((string) ($_POST['follow_up_date'] ?? ''));
                $pdo->prepare('UPDATE cab_booking_requests SET internal_notes = :n, follow_up_date = :f, updated_at = NOW() WHERE id = :id')
                    ->execute([':n' => $notes !== '' ? $notes : null, ':f' => $fu, ':id' => $id]);
                $flash('ok', 'Notes saved.');
                break;
            }

            case 'invoice': {
                if (!in_array($b['status'], ['confirmed', 'assigned', 'on_trip', 'completed'], true) || (float) $b['total_fare'] <= 0) {
                    $flash('err', 'An invoice needs a confirmed booking with a fare.');
                    break;
                }
                if ($b['invoice_number']) {
                    $flash('err', 'Invoice already issued.');
                    break;
                }
                $pdo->beginTransaction();
                $no = cab_invoice_no_safe($pdo);
                $pdo->prepare('UPDATE cab_booking_requests SET invoice_number = :n, invoice_date = CURDATE() WHERE id = :id')->execute([':n' => $no, ':id' => $id]);
                cab_timeline($pdo, $id, (string) $b['status'], 'Invoice ' . $no . ' issued', null, false, $adminId);
                $pdo->commit();
                $flash('ok', 'Invoice ' . $no . ' issued.');
                break;
            }

            default:
                $flash('err', 'Unknown action.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('cab_view [' . $action . ']: ' . $e->getMessage());
        $flash('err', 'Something went wrong. Nothing was changed.');
    }
    header('Location: cab_view.php?id=' . $id);
    exit;
}

/** wrapper so the call above reads clearly */
function cab_invoice_no_safe(PDO $pdo): string
{
    return cab_new_invoice_no($pdo);
}

/* ---------------- data for display ---------------- */
$vehicles = $pdo->query("SELECT id, name, category, reg_number, seats FROM cab_vehicles WHERE status = 'available' AND deleted_at IS NULL ORDER BY category, name")->fetchAll();
$drivers  = $pdo->query("SELECT id, full_name FROM cab_drivers WHERE status = 'active' AND deleted_at IS NULL ORDER BY full_name")->fetchAll();
$free = [];
if ($b['journey_date']) {
    foreach ($vehicles as $v) {
        $free[(int) $v['id']] = !cab_conflicts($pdo, (int) $v['id'], null, (string) $b['journey_date'], (string) ($b['end_date'] ?: $b['journey_date']), $id);
    }
}
$veh = $b['vehicle_id'] ? $pdo->query('SELECT * FROM cab_vehicles WHERE id = ' . (int) $b['vehicle_id'])->fetch() : null;
$drv = $b['driver_id'] ? $pdo->query('SELECT * FROM cab_drivers WHERE id = ' . (int) $b['driver_id'])->fetch() : null;
$tl = $pdo->prepare('SELECT t.*, a.full_name AS admin_name FROM cab_booking_timeline t LEFT JOIN admins a ON a.id = t.created_by WHERE booking_id = :id ORDER BY t.id DESC');
$tl->execute([':id' => $id]);
$timeline = $tl->fetchAll();
$pays = $pdo->prepare('SELECT * FROM cab_payments WHERE booking_id = :id ORDER BY id DESC');
$pays->execute([':id' => $id]);
$payments = $pays->fetchAll();
$tot = cab_totals($b);
$next = $FLOW[$b['status']] ?? [];
$locked = !empty($b['invoice_number']) && in_array($b['status'], ['completed', 'cancelled'], true);

tl_shell_top('Booking ' . $b['reference'], $b['reference'], ['Cab Services' => 'cab_dashboard.php', 'Bookings' => 'cab_bookings.php'],
    '<a class="btn btn-secondary" href="cab_form.php?id=' . $id . '"><i class="fa-solid fa-pen"></i> Edit</a>'
    . ($b['invoice_number'] ? '<a class="btn btn-secondary" target="_blank" href="cab_invoice.php?id=' . $id . '"><i class="fa-solid fa-file-invoice"></i> Invoice</a>' : '')
    . '<a class="btn btn-secondary" target="_blank" href="cab_invoice.php?id=' . $id . '&amp;doc=tripsheet"><i class="fa-solid fa-print"></i> Trip sheet</a>');
?>
<div class="tx-hero">
  <div>
    <div class="tid"><?= e($b['reference']) ?></div>
    <div class="ref">Received <?= e(tl_dt($b['created_at'])) ?> &middot; via <?= e($b['source'] ?: 'website') ?></div>
    <div class="route"><i class="fa-solid fa-location-dot"></i> <?= e($b['pickup_location'] ?: 'Pickup TBC') ?> <?php if ($b['destination']): ?><i class="fa-solid fa-arrow-right"></i> <?= e($b['destination']) ?><?php endif; ?></div>
    <div class="badges"><?= cab_status_badge((string) $b['status']) ?> <?= cab_pay_badge((string) $b['payment_status']) ?>
      <span class="badge badge-muted"><?= e($b['service_type'] ?: 'Service TBC') ?></span><span class="badge badge-muted"><?= e($b['vehicle_type'] ?: 'Vehicle TBC') ?></span></div>
  </div>
  <div class="tx-hero-actions">
    <a class="btn btn-secondary" href="tel:<?= e(cab_clean_phone($b['mobile_number'])) ?>"><i class="fa-solid fa-phone"></i> Call</a>
    <a class="btn btn-secondary" target="_blank" rel="noopener" href="<?= e(cab_wa_link($b['mobile_number'], 'Hello ' . $b['full_name'] . ', regarding your cab booking ' . $b['reference'] . '.')) ?>"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
  </div>
</div>

<div class="tx-grid">
<div>
  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-circle-info"></i> Trip details</h3></div>
    <div class="info-grid">
      <div class="info-item"><div class="label">Customer</div><div class="value"><?= e($b['full_name']) ?></div></div>
      <div class="info-item"><div class="label">Mobile</div><div class="value"><?= e($b['mobile_number']) ?></div></div>
      <div class="info-item"><div class="label">Email</div><div class="value<?= $b['email'] ? '' : ' muted' ?>"><?= e($b['email'] ?: 'Not given') ?></div></div>
      <div class="info-item"><div class="label">Journey</div><div class="value"><?= $b['journey_date'] ? e(date('D, d M Y', strtotime($b['journey_date']))) : '-' ?><?= $b['pickup_time'] ? ' at ' . e(substr((string) $b['pickup_time'], 0, 5)) : '' ?></div></div>
      <div class="info-item"><div class="label">Duration</div><div class="value"><?= (int) $b['days'] ?> day<?= (int) $b['days'] > 1 ? 's' : '' ?><?= $b['end_date'] && $b['end_date'] !== $b['journey_date'] ? ' (to ' . e(date('d M', strtotime($b['end_date']))) . ')' : '' ?></div></div>
      <div class="info-item"><div class="label">Passengers</div><div class="value"><?= e($b['passengers'] ?: '-') ?></div></div>
      <div class="info-item"><div class="label">Est. distance</div><div class="value"><?= $b['distance_km'] !== null ? e((string) $b['distance_km']) . ' km' : '-' ?></div></div>
      <?php if ($b['odometer_start'] !== null): ?><div class="info-item"><div class="label">Odometer</div><div class="value"><?= e((string) $b['odometer_start']) ?> &rarr; <?= $b['odometer_end'] !== null ? e((string) $b['odometer_end']) . ' (' . ((int) $b['odometer_end'] - (int) $b['odometer_start']) . ' km)' : '...' ?></div></div><?php endif; ?>
    </div>
    <?php if ($b['message']): ?><div style="margin-top:16px"><div class="info-item"><div class="label">Customer's message</div><div class="value" style="font-weight:400;white-space:pre-line"><?= e($b['message']) ?></div></div></div><?php endif; ?>
    <?php if ($b['cancel_reason']): ?><div class="tx-flash err" style="margin:16px 0 0">Cancelled: <?= e($b['cancel_reason']) ?></div><?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-indian-rupee-sign"></i> Fare &amp; payments</h3>
      <div style="display:flex;gap:8px">
      <?php if (!$locked): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="reprice"><button class="btn btn-small btn-secondary" title="Recalculate from the rate card"><i class="fa-solid fa-calculator"></i> Use rate card</button></form><?php endif; ?>
      <?php if (!$b['invoice_number'] && in_array($b['status'], ['confirmed', 'assigned', 'on_trip', 'completed'], true) && (float) $b['total_fare'] > 0): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="invoice"><button class="btn btn-small btn-primary" onclick="return confirm('Issue an invoice number? The fare will be locked once the trip is completed.')"><i class="fa-solid fa-file-invoice"></i> Issue invoice</button></form><?php endif; ?>
      </div>
    </div>
    <div class="amount-strip" style="margin-bottom:18px">
      <div class="item"><div class="label">Taxable</div><div class="value"><?= e(tl_inr($tot['taxable'])) ?></div></div>
      <div class="item"><div class="label">GST <?= e(rtrim(rtrim((string) $b['gst_pct'], '0'), '.')) ?>%</div><div class="value"><?= e(tl_inr($tot['gst'])) ?></div></div>
      <div class="item"><div class="label">Total</div><div class="value"><?= e(tl_inr($tot['total'])) ?></div></div>
      <div class="item ok"><div class="label">Received</div><div class="value"><?= e(tl_inr($tot['paid'])) ?></div></div>
      <div class="item <?= $tot['balance'] > 0 ? 'due' : 'ok' ?>"><div class="label">Balance</div><div class="value"><?= e(tl_inr($tot['balance'])) ?></div></div>
    </div>
    <?php if ($b['fare_source'] === 'estimate'): ?><p style="font-size:.8rem;color:#6b7a72;margin:-6px 0 14px"><i class="fa-solid fa-circle-info"></i> Fare came from the rate card estimate. You can still adjust it below.</p><?php endif; ?>
    <form method="post" class="tx-form" <?= $locked ? 'style="opacity:.6;pointer-events:none"' : '' ?>>
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="fare">
      <div><label>Base fare (&#8377;)</label><input type="number" step="0.01" min="0" name="base_fare" value="<?= e((string) $b['base_fare']) ?>"></div>
      <div><label>Extras: tolls, parking (&#8377;)</label><input type="number" step="0.01" min="0" name="extra_charges" value="<?= e((string) $b['extra_charges']) ?>"></div>
      <div><label>Discount (&#8377;)</label><input type="number" step="0.01" min="0" name="discount" value="<?= e((string) $b['discount']) ?>"></div>
      <div><label>GST %</label><input type="number" step="0.01" min="0" max="28" name="gst_pct" value="<?= e((string) $b['gst_pct']) ?>"></div>
      <div><label>Security deposit (&#8377;)</label><input type="number" step="0.01" min="0" name="security_deposit" value="<?= e((string) $b['security_deposit']) ?>"></div>
      <div style="align-self:end"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save fare</button></div>
    </form>

    <h4 style="margin:24px 0 10px;font-size:.9rem">Record a payment</h4>
    <form method="post" class="tx-form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="payment">
      <div><label>Type</label><select name="entry_type"><option value="payment">Payment received</option><option value="refund">Refund given</option></select></div>
      <div><label>Amount (&#8377;)</label><input type="number" step="0.01" min="0.01" name="amount" required value="<?= $tot['balance'] > 0 ? e((string) $tot['balance']) : '' ?>"></div>
      <div><label>Mode</label><select name="payment_mode"><?php foreach (cab_payment_modes() as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
      <div><label>Txn / UPI ref</label><input name="reference" maxlength="100"></div>
      <div class="full"><label>Remarks</label><input name="remarks" maxlength="255"></div>
      <div><button class="btn btn-primary" type="submit"><i class="fa-solid fa-plus"></i> Add entry</button></div>
    </form>
    <?php if ($payments): ?>
    <div class="tx-scroll" style="margin-top:16px"><table class="tx-table"><thead><tr><th>Receipt</th><th>Date</th><th>Mode</th><th>Ref</th><th class="r">Amount</th></tr></thead><tbody>
      <?php foreach ($payments as $p): ?><tr><td><?= e($p['receipt_number']) ?></td><td><?= e(tl_dt($p['payment_date'], 'd M Y, h:i A')) ?></td><td><?= e($p['payment_mode']) ?></td><td><?= e($p['reference'] ?: '-') ?></td>
        <td class="r" style="color:<?= $p['entry_type'] === 'refund' ? '#b02a20' : '#14663f' ?>;font-weight:700"><?= $p['entry_type'] === 'refund' ? '-' : '' ?><?= e(tl_inr($p['amount'])) ?></td></tr><?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-note-sticky"></i> Internal notes &amp; follow-up</h3></div>
    <form method="post" class="tx-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="notes">
      <div class="full"><label>Notes (never shown to the customer)</label><textarea name="internal_notes" rows="3" maxlength="3000"><?= e($b['internal_notes']) ?></textarea></div>
      <div><label>Follow-up date</label><input type="date" name="follow_up_date" value="<?= e($b['follow_up_date']) ?>"></div>
      <div style="align-self:end"><button class="btn btn-primary">Save notes</button></div>
    </form>
  </div>
</div>

<div>
  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-diagram-next"></i> Workflow</h3></div>
    <?php if (!$next): ?>
      <p style="color:#6b7a72;margin:0">This booking is <strong><?= e($ST[$b['status']]['label'] ?? $b['status']) ?></strong>. No further steps.</p>
    <?php else: foreach ($next as $to): $needNote = $to === 'cancelled'; $needOdo = in_array($to, ['on_trip', 'completed'], true); ?>
      <form method="post" style="margin-bottom:10px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="to" value="<?= e($to) ?>">
        <?php if ($needNote): ?><input name="note" placeholder="Reason (required)" required maxlength="255" style="width:100%;margin-bottom:6px;border:1px solid #d4e0d9;border-radius:8px;padding:8px 10px"><?php endif; ?>
        <?php if ($needOdo): ?><input type="number" min="0" name="odometer" placeholder="<?= $to === 'on_trip' ? 'Starting odometer (km)' : 'Closing odometer (km)' ?>" style="width:100%;margin-bottom:6px;border:1px solid #d4e0d9;border-radius:8px;padding:8px 10px"><?php endif; ?>
        <button class="btn <?= $to === 'cancelled' ? 'btn-danger' : 'btn-primary' ?>" style="width:100%" <?= $to === 'cancelled' ? 'onclick="return confirm(\'Cancel this booking?\')"' : '' ?>><i class="fa-solid <?= e($ST[$to]['icon']) ?>"></i> <?= $to === 'new' ? 'Re-open' : 'Mark ' . e($ST[$to]['label']) ?></button>
      </form>
    <?php endforeach; endif; ?>
  </div>

  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-car-side"></i> Vehicle &amp; driver</h3></div>
    <?php if ($veh): ?><p style="margin:0 0 4px"><strong><?= e($veh['name']) ?></strong> <small style="color:#6b7a72"><?= e($veh['reg_number']) ?></small></p><?php endif; ?>
    <?php if ($drv): ?><p style="margin:0 0 12px;font-size:.9rem"><i class="fa-solid fa-id-card"></i> <?= e($drv['full_name']) ?> &middot; <a href="tel:<?= e(cab_clean_phone($drv['phone'])) ?>"><?= e($drv['phone']) ?></a></p><?php endif; ?>
    <?php if (in_array($b['status'], ['confirmed', 'assigned'], true)): ?>
    <form method="post" class="tx-form" style="grid-template-columns:1fr"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="assign">
      <div><label>Vehicle</label><select name="vehicle_id"><option value="">- none -</option>
        <?php foreach ($vehicles as $v): $ok = $free[(int) $v['id']] ?? true; ?>
          <option value="<?= (int) $v['id'] ?>" <?= (int) $b['vehicle_id'] === (int) $v['id'] ? 'selected' : '' ?>><?= e($v['name'] . ($v['reg_number'] ? ' - ' . $v['reg_number'] : '') . ' (' . $v['category'] . ')') ?><?= $ok ? '' : ' - BUSY on these dates' ?></option>
        <?php endforeach; ?></select></div>
      <div><label>Driver</label><select name="driver_id"><option value="">- none -</option>
        <?php foreach ($drivers as $d): ?><option value="<?= (int) $d['id'] ?>" <?= (int) $b['driver_id'] === (int) $d['id'] ? 'selected' : '' ?>><?= e($d['full_name']) ?></option><?php endforeach; ?></select></div>
      <button class="btn btn-primary">Save assignment</button>
      <?php if (!$vehicles): ?><small style="color:#b56a00">No vehicles yet. <a href="cab_fleet.php">Add your fleet</a>.</small><?php endif; ?>
    </form>
    <?php elseif (!$veh): ?><p style="color:#6b7a72;margin:0;font-size:.88rem">Confirm the booking to assign a vehicle and driver.</p><?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-clock-rotate-left"></i> Timeline</h3></div>
    <div class="mini-timeline">
      <?php foreach ($timeline as $t): ?>
        <div class="mini-tl-event"><div class="mini-tl-time"><?= e(tl_dt($t['created_at'], 'd M, h:i A')) ?><?= $t['admin_name'] ? ' &middot; ' . e($t['admin_name']) : '' ?><?= $t['customer_visible'] ? '' : ' &middot; <i class="fa-solid fa-eye-slash" title="Internal"></i>' ?></div>
          <div class="mini-tl-title"><?= e($t['title']) ?></div><?php if ($t['description']): ?><div class="mini-tl-desc"><?= e($t['description']) ?></div><?php endif; ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
</div>
<?php tl_shell_bottom();
