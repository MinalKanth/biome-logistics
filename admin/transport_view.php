<?php
/**
 * admin/transport_view.php?id=BOOKING_ID
 * REWRITTEN: the old version joined a non-existent table (transport_customers) and read ~30 columns
 * that do not exist (booking_reference, delivery_city ...), so it crashed. This one reads the real columns
 * and adds a "Quick update" box: change status + location in one click, and the customer sees it on /track.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/transport_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: transport_manage.php');
    exit;
}

$STATUS = tl_statuses();

/* ---------------- Quick status update ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'quick_update') {
    csrf_require_valid();
    $newStatus = (string) ($_POST['status'] ?? '');
    $location  = mb_substr(trim((string) ($_POST['location'] ?? '')), 0, 200);
    $note      = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
    $notify    = isset($_POST['notify']);
    $receiver  = mb_substr(trim((string) ($_POST['received_by'] ?? '')), 0, 150);

    if (!isset($STATUS[$newStatus])) {
        $_SESSION['flash_error_page'] = 'Choose a valid status.';
    } else {
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT * FROM transport_bookings WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
            $q->execute([':id' => $id]);
            $cur = $q->fetch();
            if (!$cur) {
                throw new RuntimeException('booking missing');
            }
            $pdo->prepare(
                "UPDATE transport_bookings SET status = :s,
                    delivered_at = IF(:s2 = 'delivered', COALESCE(delivered_at, NOW()), delivered_at),
                    received_by = IF(:s3 = 'delivered' AND :r <> '', :r2, received_by),
                    updated_by = :a, updated_at = NOW() WHERE id = :id"
            )->execute([':s' => $newStatus, ':s2' => $newStatus, ':s3' => $newStatus, ':r' => $receiver, ':r2' => $receiver, ':a' => $_SESSION['admin_id'], ':id' => $id]);

            tl_add_timeline($pdo, $id, (string) $cur['tracking_id'], $newStatus, $STATUS[$newStatus]['label'],
                $note !== '' ? $note : $STATUS[$newStatus]['msg'], $location, true, (int) $_SESSION['admin_id']);
            $pdo->commit();

            log_activity((int) $_SESSION['admin_id'], 'transport_status_updated', "booking_id={$id} status={$newStatus}");
            $_SESSION['flash_success_page'] = 'Status updated to "' . $STATUS[$newStatus]['label'] . '". The customer can see it on the tracking page.';

            if ($notify && !empty($cur['email']) && $newStatus !== (string) $cur['status']) {
                $inner = '<p>Hi ' . tl_e($cur['customer_name']) . ',</p><p>Update on your shipment <strong>' . tl_e($cur['tracking_id']) . '</strong> ('
                    . tl_e($cur['pickup_city']) . ' &rarr; ' . tl_e($cur['drop_city']) . '):</p>'
                    . '<p style="font-size:18px;font-weight:700;color:#0f4c2d">' . tl_e($STATUS[$newStatus]['label']) . '</p>'
                    . ($location !== '' ? '<p>Location: ' . tl_e($location) . '</p>' : '')
                    . ($note !== '' ? '<p>' . tl_e($note) . '</p>' : '');
                $sent = tl_send_mail((string) $cur['email'], (string) $cur['customer_name'], 'Shipment ' . $cur['tracking_id'] . ' - ' . $STATUS[$newStatus]['label'],
                    tl_mail_shell('Shipment update', $inner, tl_track_url((string) $cur['tracking_id']), 'Track shipment'));
                if ($sent) {
                    $_SESSION['flash_success_page'] .= ' E-mail sent.';
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Quick update failed: ' . $e->getMessage());
            $_SESSION['flash_error_page'] = 'Could not update the status. Please try again.';
        }
    }
    header('Location: transport_view.php?id=' . $id);
    exit;
}

/* ---------------- Load ---------------- */
$stmt = $pdo->prepare(
    'SELECT tb.*, d.full_name AS driver_name, d.mobile AS driver_phone, d.license_number,
            v.registration_number, v.vehicle_type AS vehicle_type_actual
     FROM transport_bookings tb
     LEFT JOIN transport_drivers d ON d.id = tb.driver_id
     LEFT JOIN transport_vehicles v ON v.id = tb.vehicle_id
     WHERE tb.id = :id AND tb.deleted_at IS NULL'
);
$stmt->execute([':id' => $id]);
$b = $stmt->fetch();
if (!$b) {
    $_SESSION['flash_error'] = 'Booking not found or has been deleted.';
    header('Location: transport_manage.php');
    exit;
}

$tl = $pdo->prepare('SELECT * FROM transport_booking_timeline WHERE booking_id = :id ORDER BY created_at DESC, id DESC LIMIT 8');
$tl->execute([':id' => $id]);
$timeline = $tl->fetchAll();

$ph = $pdo->prepare('SELECT * FROM transport_payment_history WHERE booking_id = :id ORDER BY payment_date DESC, id DESC');
$ph->execute([':id' => $id]);
$payments = $ph->fetchAll();

$t = tl_compute_totals($b);
$weight = ($b['cargo_weight'] !== null && $b['cargo_weight'] !== '')
    ? rtrim(rtrim(number_format((float) $b['cargo_weight'], 2, '.', ''), '0'), '.') . ' ' . ($b['cargo_unit'] ?: 'kg') : '—';
$isNew = ($b['status'] === 'pending' && ($b['source'] ?? '') === 'website');

$actions = '<a href="transport_manage.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> All bookings</a>';
tl_shell_top('Booking ' . $b['tracking_id'], 'Booking overview', ['Transport' => 'transport_manage.php'], $actions);
?>

<?php if ($isNew): ?>
  <div class="tx-flash err" style="background:#fff8e1;border-color:#ffe08a;color:#7a5b00">
    <i class="fa-solid fa-bell"></i> <strong>New online request.</strong> Call the customer, then click <em>Edit</em> to enter the freight amount, assign a driver and vehicle, and set the status to Confirmed.
  </div>
<?php endif; ?>

<div class="tx-hero">
  <div>
    <div class="tid"><i class="fa-solid fa-barcode"></i> <?= e($b['tracking_id']) ?></div>
    <div class="ref">Enquiry: <?= e($b['enquiry_reference'] ?: '—') ?> &nbsp;·&nbsp; Created <?= e(tl_dt($b['created_at'])) ?> &nbsp;·&nbsp; Source: <?= e(ucfirst((string) ($b['source'] ?: 'admin'))) ?></div>
    <div class="route"><span><?= e($b['pickup_city']) ?></span><i class="fa-solid fa-arrow-right-long"></i><span><?= e($b['drop_city']) ?></span></div>
    <div class="badges"><?= tl_status_badge((string) $b['status']) ?> <?= tl_payment_badge((string) $b['payment_status']) ?> <?= tl_badge(ucfirst((string) $b['priority']), 'info') ?></div>
  </div>
  <div class="tx-hero-actions">
    <a href="transport_edit.php?id=<?= $id ?>" class="btn btn-primary"><i class="fa-solid fa-pen"></i> Edit</a>
    <a href="timeline.php?id=<?= $id ?>" class="btn btn-secondary"><i class="fa-solid fa-timeline"></i> Timeline</a>
    <a href="payment.php?id=<?= $id ?>" class="btn btn-secondary"><i class="fa-solid fa-wallet"></i> Payments</a>
    <a href="invoice.php?id=<?= $id ?>" class="btn btn-secondary"><i class="fa-solid fa-file-invoice"></i> Invoice</a>
    <a href="../track?id=<?= rawurlencode((string) $b['tracking_id']) ?>" target="_blank" rel="noopener" class="btn btn-ghost" style="background:#fff"><i class="fa-solid fa-arrow-up-right-from-square"></i> Customer view</a>
  </div>
</div>

<div class="tx-grid">
  <div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-user"></i> Customer</h3></div>
      <div class="info-grid">
        <div class="info-item"><div class="label">Name</div><div class="value"><?= e($b['customer_name']) ?></div></div>
        <div class="info-item"><div class="label">Company</div><div class="value <?= $b['company_name'] ? '' : 'muted' ?>"><?= e($b['company_name'] ?: 'Not provided') ?></div></div>
        <div class="info-item"><div class="label">Phone</div><div class="value mono"><a href="tel:<?= e($b['phone']) ?>"><?= e($b['phone']) ?></a></div></div>
        <div class="info-item"><div class="label">Alternate</div><div class="value mono <?= $b['alternate_phone'] ? '' : 'muted' ?>"><?= e($b['alternate_phone'] ?: '—') ?></div></div>
        <div class="info-item"><div class="label">E-mail</div><div class="value <?= $b['email'] ? '' : 'muted' ?>"><?= e($b['email'] ?: '—') ?></div></div>
        <div class="info-item"><div class="label">Service</div><div class="value"><?= e($b['service_type'] ?: '—') ?></div></div>
      </div>
      <?php if (!empty($b['customer_notes'])): ?>
        <div class="info-item" style="margin-top:16px"><div class="label">Customer notes</div><div class="value" style="font-weight:500"><?= nl2br(e($b['customer_notes'])) ?></div></div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-route"></i> Pickup &amp; delivery</h3></div>
      <div class="info-grid">
        <div class="info-item"><div class="label">Pickup address</div><div class="value" style="font-weight:500"><?= nl2br(e($b['pickup_address'])) ?><br><span class="muted"><?= e(trim(($b['pickup_city'] ?? '') . ', ' . ($b['pickup_state'] ?? '') . ' ' . ($b['pickup_pincode'] ?? ''), ' ,')) ?></span></div></div>
        <div class="info-item"><div class="label">Pickup contact</div><div class="value"><?= e($b['pickup_contact_person'] ?: $b['customer_name']) ?></div><div class="value mono muted"><?= e($b['pickup_contact_number'] ?: $b['phone']) ?></div></div>
        <div class="info-item"><div class="label">Delivery address</div><div class="value" style="font-weight:500"><?= nl2br(e($b['drop_address'])) ?><br><span class="muted"><?= e(trim(($b['drop_city'] ?? '') . ', ' . ($b['drop_state'] ?? '') . ' ' . ($b['drop_pincode'] ?? ''), ' ,')) ?></span></div></div>
        <div class="info-item"><div class="label">Receiver</div><div class="value <?= $b['drop_contact_person'] ? '' : 'muted' ?>"><?= e($b['drop_contact_person'] ?: '—') ?></div><div class="value mono muted"><?= e($b['drop_contact_number'] ?: '—') ?></div></div>
        <div class="info-item"><div class="label">Scheduled pickup</div><div class="value"><?= e(tl_dt($b['scheduled_pickup'])) ?></div></div>
        <div class="info-item"><div class="label">Expected delivery</div><div class="value"><?= e(tl_dt($b['expected_delivery'])) ?></div></div>
        <div class="info-item"><div class="label">Distance</div><div class="value"><?= $b['distance_km'] ? e((string) $b['distance_km']) . ' km' : '—' ?></div></div>
        <?php if ($b['delivered_at']): ?><div class="info-item"><div class="label">Delivered at</div><div class="value"><?= e(tl_dt($b['delivered_at'])) ?><?= $b['received_by'] ? ' · ' . e($b['received_by']) : '' ?></div></div><?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-box"></i> Cargo &amp; vehicle</h3></div>
      <div class="info-grid">
        <div class="info-item"><div class="label">Cargo type</div><div class="value"><?= e($b['cargo_type'] ?: '—') ?></div></div>
        <div class="info-item"><div class="label">Weight</div><div class="value"><?= e($weight) ?></div></div>
        <div class="info-item"><div class="label">Packages</div><div class="value"><?= $b['number_of_packages'] ? (int) $b['number_of_packages'] : '—' ?></div></div>
        <div class="info-item"><div class="label">Declared value</div><div class="value"><?= $b['cargo_value'] ? e(tl_inr($b['cargo_value'])) : '—' ?></div></div>
        <div class="info-item"><div class="label">Handling</div><div class="value">
          <?= $b['fragile'] ? tl_badge('Fragile', 'warning') : '' ?> <?= $b['hazardous'] ? tl_badge('Hazardous', 'danger') : '' ?> <?= $b['temperature_controlled'] ? tl_badge('Temp-controlled', 'info') : '' ?>
          <?= (!$b['fragile'] && !$b['hazardous'] && !$b['temperature_controlled']) ? '<span class="muted">Standard</span>' : '' ?></div></div>
        <div class="info-item"><div class="label">Requested vehicle</div><div class="value"><?= e($b['vehicle_type'] ?: '—') ?></div></div>
        <div class="info-item"><div class="label">Assigned vehicle</div><div class="value mono <?= $b['registration_number'] ? '' : 'muted' ?>"><?= e($b['registration_number'] ?: 'Not assigned') ?></div></div>
        <div class="info-item"><div class="label">Driver</div><div class="value <?= $b['driver_name'] ? '' : 'muted' ?>"><?= e($b['driver_name'] ?: 'Not assigned') ?></div><div class="value mono muted"><?= e($b['driver_phone'] ?: '') ?></div></div>
        <div class="info-item"><div class="label">LR number</div><div class="value mono <?= $b['lr_number'] ? '' : 'muted' ?>"><?= e($b['lr_number'] ?: '—') ?></div></div>
      </div>
      <?php if (!empty($b['cargo_description'])): ?>
        <div class="info-item" style="margin-top:16px"><div class="label">Description</div><div class="value" style="font-weight:500"><?= nl2br(e($b['cargo_description'])) ?></div></div>
      <?php endif; ?>
      <?php if (!empty($b['internal_notes'])): ?>
        <div class="info-item" style="margin-top:16px"><div class="label">Internal notes (not shown to customer)</div><div class="value" style="font-weight:500"><?= nl2br(e($b['internal_notes'])) ?></div></div>
      <?php endif; ?>
    </div>
  </div>

  <div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-bolt"></i> Quick update</h3></div>
      <form method="post" class="tx-form" style="grid-template-columns:1fr">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="quick_update">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div><label for="qu_status">New status</label>
          <select name="status" id="qu_status" required>
            <?php foreach ($STATUS as $k => $m): ?><option value="<?= e($k) ?>"<?= $k === $b['status'] ? ' selected' : '' ?>><?= e($m['label']) ?></option><?php endforeach; ?>
          </select></div>
        <div><label for="qu_loc">Current location</label><input name="location" id="qu_loc" maxlength="200" placeholder="e.g. Nagaon bypass"></div>
        <div><label for="qu_note">Note for customer (optional)</label><textarea name="note" id="qu_note" rows="2" maxlength="500"></textarea></div>
        <div><label for="qu_recv">Received by (when delivered)</label><input name="received_by" id="qu_recv" maxlength="150"></div>
        <label style="text-transform:none;letter-spacing:0;font-size:.85rem;display:flex;gap:8px;align-items:center">
          <input type="checkbox" name="notify" value="1" style="width:auto" <?= $b['email'] ? 'checked' : 'disabled' ?>> E-mail the customer<?= $b['email'] ? '' : ' (no e-mail on file)' ?></label>
        <button class="btn btn-primary" type="submit" style="justify-content:center"><i class="fa-solid fa-paper-plane"></i> Update &amp; notify</button>
      </form>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-indian-rupee-sign"></i> Billing</h3>
        <a class="btn btn-small btn-primary" href="payment.php?id=<?= $id ?>">Record payment</a></div>
      <?php if ($t['grand'] <= 0): ?>
        <p class="muted" style="font-size:.88rem">No amount entered yet. <a href="transport_edit.php?id=<?= $id ?>">Edit booking</a> to set the freight amount.</p>
      <?php endif; ?>
      <div class="amount-strip">
        <div class="item"><div class="label">Freight</div><div class="value"><?= e(tl_inr($t['total'])) ?></div></div>
        <div class="item"><div class="label">GST</div><div class="value"><?= e(tl_inr($t['gst'])) ?></div></div>
        <div class="item"><div class="label">Grand total</div><div class="value"><?= e(tl_inr($t['grand'])) ?></div></div>
        <div class="item ok"><div class="label">Paid</div><div class="value"><?= e(tl_inr($t['paid'])) ?></div></div>
        <div class="item due"><div class="label">Balance</div><div class="value"><?= e(tl_inr(max(0, $t['balance']))) ?></div></div>
      </div>
      <div style="margin-top:12px;font-size:.82rem" class="muted">
        Invoice: <?= $b['invoice_number'] ? '<strong>' . e($b['invoice_number']) . '</strong>' : 'not generated yet' ?>
        &nbsp;·&nbsp; <a href="invoice.php?id=<?= $id ?>"><?= $b['invoice_number'] ? 'Open' : 'Generate' ?></a>
      </div>
      <?php if ($payments): ?>
        <div style="margin-top:14px;font-size:.82rem">
          <?php foreach (array_slice($payments, 0, 4) as $p): ?>
            <div style="display:flex;justify-content:space-between;padding:6px 0;border-top:1px dashed #e3ece7">
              <span><?= e(tl_dt($p['payment_date'], 'd M')) ?> · <?= e(ucfirst((string) $p['payment_type'])) ?></span>
              <strong><?= ($p['payment_type'] ?? '') === 'refund' ? '-' : '' ?><?= e(tl_inr($p['amount'])) ?></strong>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-timeline"></i> Recent activity</h3><a class="btn btn-small btn-ghost" href="timeline.php?id=<?= $id ?>">Manage</a></div>
      <?php if (!$timeline): ?><p class="muted" style="font-size:.86rem">No events yet.</p>
      <?php else: ?>
        <div class="mini-timeline">
          <?php foreach ($timeline as $ev): ?>
            <div class="mini-tl-event">
              <div class="mini-tl-time"><?= e(tl_dt($ev['created_at'])) ?><?= (int) $ev['customer_visible'] === 0 ? ' · internal' : '' ?></div>
              <div class="mini-tl-title"><?= e($ev['title']) ?></div>
              <?php if ($ev['current_location']): ?><div class="mini-tl-desc"><i class="fa-solid fa-location-dot"></i> <?= e($ev['current_location']) ?></div><?php endif; ?>
              <?php if ($ev['description']): ?><div class="mini-tl-desc"><?= e($ev['description']) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php tl_shell_bottom(); ?>
