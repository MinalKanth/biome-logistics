<?php
/**
 * admin/cab_form.php - create a booking by hand (phone / walk-in) or edit an existing one.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/cab_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$adminId = (int) $_SESSION['admin_id'];
$cur = null;
if ($id > 0) {
    $s = $pdo->prepare('SELECT * FROM cab_booking_requests WHERE id = :id AND deleted_at IS NULL');
    $s->execute([':id' => $id]);
    $cur = $s->fetch() ?: null;
    if (!$cur) {
        $_SESSION['flash_error_page'] = 'Booking not found.';
        header('Location: cab_bookings.php');
        exit;
    }
}
$locked = $cur && !empty($cur['invoice_number']);

$v = [
    'full_name' => $cur['full_name'] ?? '', 'mobile_number' => $cur['mobile_number'] ?? '', 'email' => $cur['email'] ?? '',
    'vehicle_type' => $cur['vehicle_type'] ?? '', 'service_type' => $cur['service_type'] ?? '',
    'pickup_location' => $cur['pickup_location'] ?? '', 'destination' => $cur['destination'] ?? '',
    'journey_date' => $cur['journey_date'] ?? '', 'pickup_time' => isset($cur['pickup_time']) ? substr((string) $cur['pickup_time'], 0, 5) : '',
    'return_date' => $cur['return_date'] ?? '', 'passengers' => $cur['passengers'] ?? '', 'distance_km' => $cur['distance_km'] ?? '',
    'message' => $cur['message'] ?? '', 'source' => $cur['source'] ?? 'phone',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    foreach ($v as $k => $_) {
        $v[$k] = trim(str_replace("\0", '', (string) ($_POST[$k] ?? '')));
    }
    $services = cab_service_types();
    if ($v['full_name'] === '' || mb_strlen($v['full_name']) > 150) { $errors[] = 'Customer name is required (max 150 characters).'; }
    if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $v['mobile_number'])) { $errors[] = 'Enter a valid mobile number.'; }
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid e-mail address.'; }
    if ($v['vehicle_type'] !== '' && !in_array($v['vehicle_type'], cab_vehicle_types(), true)) { $errors[] = 'Invalid vehicle type.'; }
    if ($v['service_type'] !== '' && !isset($services[$v['service_type']])) { $errors[] = 'Invalid service type.'; }
    if (!in_array($v['source'], ['phone', 'walk-in', 'admin', 'website'], true)) { $v['source'] = 'phone'; }
    $date = cab_valid_date($v['journey_date']);
    if ($v['journey_date'] !== '' && !$date) { $errors[] = 'Invalid journey date.'; }
    $ret = cab_valid_date($v['return_date']);
    if ($v['return_date'] !== '' && !$ret) { $errors[] = 'Invalid return date.'; }
    if ($date && $ret && $ret < $date) { $errors[] = 'Return date cannot be before the journey date.'; }
    $time = cab_valid_time($v['pickup_time']);
    if ($v['pickup_time'] !== '' && !$time) { $errors[] = 'Invalid pickup time.'; }
    $pax = null;
    if ($v['passengers'] !== '') {
        if (!ctype_digit($v['passengers']) || (int) $v['passengers'] < 1 || (int) $v['passengers'] > 100) { $errors[] = 'Passengers must be 1-100.'; } else { $pax = (int) $v['passengers']; }
    }
    $km = null;
    if ($v['distance_km'] !== '') {
        if (!is_numeric($v['distance_km']) || (float) $v['distance_km'] < 0 || (float) $v['distance_km'] > 20000) { $errors[] = 'Distance must be 0-20000 km.'; } else { $km = round((float) $v['distance_km'], 1); }
    }
    if (mb_strlen($v['pickup_location']) > 200 || mb_strlen($v['destination']) > 200) { $errors[] = 'Location is too long (max 200).'; }
    if (mb_strlen($v['message']) > 2000) { $errors[] = 'Message is too long (max 2000).'; }

    $days = $v['service_type'] !== '' ? cab_days($v['service_type'], $date, $ret) : 1;
    $end  = $date ? cab_end_date($date, $days) : null;

    // keep an assigned vehicle/driver from silently double-booking after a date change
    if (!$errors && $cur && $date && ($cur['vehicle_id'] || $cur['driver_id']) && in_array($cur['status'], cab_active_statuses(), true)) {
        $clash = cab_conflicts($pdo, $cur['vehicle_id'] ? (int) $cur['vehicle_id'] : null, $cur['driver_id'] ? (int) $cur['driver_id'] : null, $date, (string) $end, $id);
        if ($clash) {
            $errors[] = 'The assigned vehicle/driver is already booked for those dates (' . $clash[0]['reference'] . '). Un-assign first or choose other dates.';
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $par = [
                ':n' => $v['full_name'], ':m' => $v['mobile_number'], ':e' => $v['email'] ?: null,
                ':vt' => $v['vehicle_type'] ?: null, ':st' => $v['service_type'] ?: null,
                ':pl' => $v['pickup_location'] ?: null, ':ds' => $v['destination'] ?: null,
                ':jd' => $date, ':pt' => $time, ':rd' => $ret, ':ed' => $end, ':dy' => $days,
                ':px' => $pax, ':km' => $km, ':msg' => $v['message'] ?: null, ':src' => $v['source'],
            ];
            if ($cur) {
                $pdo->prepare('UPDATE cab_booking_requests SET full_name=:n, mobile_number=:m, email=:e, vehicle_type=:vt, service_type=:st, pickup_location=:pl, destination=:ds,
                    journey_date=:jd, pickup_time=:pt, return_date=:rd, end_date=:ed, days=:dy, passengers=:px, distance_km=:km, message=:msg, source=:src, updated_at=NOW() WHERE id=:id')
                    ->execute($par + [':id' => $id]);
                $newId = $id;
                cab_timeline($pdo, $id, (string) $cur['status'], 'Booking details edited', null, false, $adminId);
            } else {
                $ref = cab_new_reference($pdo);
                $pdo->prepare('INSERT INTO cab_booking_requests (reference, full_name, mobile_number, email, vehicle_type, service_type, pickup_location, destination,
                    journey_date, pickup_time, return_date, end_date, days, passengers, distance_km, message, source, status, created_by, created_at, updated_at, ip_address)
                    VALUES (:ref, :n, :m, :e, :vt, :st, :pl, :ds, :jd, :pt, :rd, :ed, :dy, :px, :km, :msg, :src, \'new\', :by, NOW(), NOW(), :ip)')
                    ->execute($par + [':ref' => $ref, ':by' => $adminId, ':ip' => app_client_ip()]);
                $newId = (int) $pdo->lastInsertId();
                cab_timeline($pdo, $newId, 'new', 'Booking created by staff', 'Source: ' . $v['source'], true, $adminId);
            }
            if (!empty($_POST['auto_price']) && !$locked && $v['vehicle_type'] !== '' && $v['service_type'] !== '') {
                $est = cab_estimate($pdo, $v['vehicle_type'], $v['service_type'], $days, $km);
                if ($est['ok']) {
                    $pdo->prepare('UPDATE cab_booking_requests SET base_fare=:b, gst_pct=:g, security_deposit=:s, fare_source=\'estimate\' WHERE id=:id')
                        ->execute([':b' => $est['base'], ':g' => $est['gst_pct'], ':s' => $est['deposit'], ':id' => $newId]);
                    cab_recalc($pdo, $newId);
                } else {
                    $_SESSION['flash_error_page'] = 'Saved, but no active rate was found - set the fare manually.';
                }
            }
            $pdo->commit();
            log_activity($adminId, $cur ? 'cab_edited' : 'cab_created', "id={$newId}");
            if (empty($_SESSION['flash_error_page'])) {
                $_SESSION['flash_success_page'] = $cur ? 'Booking updated.' : 'Booking created.';
            }
            header('Location: cab_view.php?id=' . $newId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('cab_form: ' . $e->getMessage());
            $errors[] = 'Could not save the booking. Please try again.';
        }
    }
}

tl_shell_top($cur ? 'Edit booking' : 'New booking', $cur ? 'Edit ' . $cur['reference'] : 'New cab booking', ['Cab Services' => 'cab_dashboard.php', 'Bookings' => 'cab_bookings.php']);
?>
<?php if ($errors): ?><div class="tx-flash err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
<form method="post" class="panel">
  <?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <div class="panel-head"><h3><i class="fa-solid fa-user"></i> Customer</h3></div>
  <div class="tx-form" style="margin-bottom:22px">
    <div><label>Full name *</label><input name="full_name" required maxlength="150" value="<?= e($v['full_name']) ?>"></div>
    <div><label>Mobile *</label><input name="mobile_number" required maxlength="20" value="<?= e($v['mobile_number']) ?>"></div>
    <div><label>E-mail</label><input type="email" name="email" maxlength="150" value="<?= e($v['email']) ?>"></div>
    <div><label>Booking source</label><select name="source"><?php foreach (['phone' => 'Phone call', 'walk-in' => 'Walk-in', 'admin' => 'Other', 'website' => 'Website'] as $k => $l): ?><option value="<?= $k ?>" <?= $v['source'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="panel-head"><h3><i class="fa-solid fa-route"></i> Trip</h3></div>
  <div class="tx-form" style="margin-bottom:22px">
    <div><label>Service</label><select name="service_type"><option value="">- select -</option><?php foreach (array_keys(cab_service_types()) as $s): ?><option <?= $v['service_type'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    <div><label>Vehicle category</label><select name="vehicle_type"><option value="">- select -</option><?php foreach (cab_vehicle_types() as $s): ?><option <?= $v['vehicle_type'] === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select></div>
    <div><label>Pickup</label><input name="pickup_location" maxlength="200" value="<?= e($v['pickup_location']) ?>"></div>
    <div><label>Destination</label><input name="destination" maxlength="200" value="<?= e($v['destination']) ?>"></div>
    <div><label>Journey date</label><input type="date" name="journey_date" value="<?= e($v['journey_date']) ?>"></div>
    <div><label>Pickup time</label><input type="time" name="pickup_time" value="<?= e($v['pickup_time']) ?>"></div>
    <div><label>Return date (multi-day)</label><input type="date" name="return_date" value="<?= e($v['return_date']) ?>"></div>
    <div><label>Passengers</label><input type="number" min="1" max="100" name="passengers" value="<?= e((string) $v['passengers']) ?>"></div>
    <div><label>Approx. distance (km)</label><input type="number" min="0" step="0.1" name="distance_km" value="<?= e((string) $v['distance_km']) ?>"></div>
    <div class="full"><label>Customer's requirements</label><textarea name="message" rows="3" maxlength="2000"><?= e($v['message']) ?></textarea></div>
  </div>
  <?php if (!$locked): ?>
  <label style="display:flex;gap:8px;align-items:center;margin-bottom:18px;font-size:.9rem"><input type="checkbox" name="auto_price" value="1" <?= !$cur ? 'checked' : '' ?>> Calculate the fare from the rate card when saving <small style="color:#6b7a72">(only works for active rates)</small></label>
  <?php else: ?><p style="color:#6b7a72;font-size:.88rem">An invoice has been issued, so the fare is locked.</p><?php endif; ?>
  <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= $cur ? 'Save changes' : 'Create booking' ?></button>
  <a class="btn btn-ghost" href="<?= $cur ? 'cab_view.php?id=' . $id : 'cab_bookings.php' ?>">Cancel</a>
</form>
<?php tl_shell_bottom();
