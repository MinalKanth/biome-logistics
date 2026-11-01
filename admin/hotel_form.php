<?php
/**
 * admin/hotel_form.php - create a reservation (phone / walk-in) or change dates / room / guests of an existing one.
 * "Check price" shows availability and the exact price before anything is saved.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hotel_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$adminId = (int) $_SESSION['admin_id'];
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$cur = null;
if ($id > 0) {
    $s = $pdo->prepare('SELECT * FROM hotel_reservations WHERE id = :id AND deleted_at IS NULL');
    $s->execute([':id' => $id]);
    $cur = $s->fetch() ?: null;
    if (!$cur) { $_SESSION['flash_error_page'] = 'Reservation not found.'; header('Location: hotel_reservations.php'); exit; }
    if (!in_array($cur['status'], ['pending', 'confirmed'], true)) {
        $_SESSION['flash_error_page'] = 'Only pending or confirmed reservations can be changed.';
        header('Location: hotel_view.php?id=' . $id);
        exit;
    }
}
$locked = $cur && !empty($cur['invoice_number']);

$roomsAll = $pdo->query("SELECT r.id, r.name, r.base_rate, r.max_adults, p.name AS pname FROM hotel_room_types r JOIN hotel_properties p ON p.id = r.property_id
    WHERE r.deleted_at IS NULL AND p.deleted_at IS NULL AND r.is_active = 1 ORDER BY p.name, r.sort_order, r.name")->fetchAll();
$v = [
    'room_type_id' => $cur['room_type_id'] ?? '', 'guest_name' => $cur['guest_name'] ?? '', 'phone' => $cur['phone'] ?? '', 'email' => $cur['email'] ?? '',
    'adults' => $cur['adults'] ?? '2', 'children' => $cur['children'] ?? '0', 'rooms' => $cur['rooms'] ?? '1', 'check_in' => $cur['check_in'] ?? date('Y-m-d'),
    'check_out' => $cur['check_out'] ?? date('Y-m-d', strtotime('+1 day')), 'arrival_time' => isset($cur['arrival_time']) ? (string) $cur['arrival_time'] : '', 'special_requests' => $cur['special_requests'] ?? '',
    'id_type' => $cur['id_type'] ?? '', 'id_number' => $cur['id_number'] ?? '', 'source' => $cur['source'] ?? 'phone', 'status' => 'confirmed',
];
$errors = [];
$preview = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    foreach ($v as $k => $_) { $v[$k] = trim(str_replace("\0", '', (string) ($_POST[$k] ?? ''))); }
    $act = (string) ($_POST['action'] ?? 'save');
    if ($v['guest_name'] === '' || mb_strlen($v['guest_name']) > 150) { $errors[] = 'Guest name is required.'; }
    if (!htl_phone_ok($v['phone'])) { $errors[] = 'Enter a valid mobile number (10+ digits).'; }
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid e-mail address.'; }
    if (!in_array($v['source'], ['phone', 'walk-in', 'agent', 'admin', 'website'], true)) { $v['source'] = 'phone'; }
    if (!in_array($v['status'], ['pending', 'confirmed'], true)) { $v['status'] = 'confirmed'; }
    foreach (['adults' => 1, 'children' => 0, 'rooms' => 1] as $k => $min) {
        if (!ctype_digit($v[$k]) || (int) $v[$k] < $min || (int) $v[$k] > 40) { $errors[] = ucfirst($k) . ' must be a number from ' . $min . '.'; }
    }
    $ci = htl_date($v['check_in']);
    $co = htl_date($v['check_out']);
    if (!$errors && ($e = htl_check_stay($pdo, $ci, $co, false))) { $errors[] = $e; }
    $room = !$errors ? htl_room($pdo, (int) $v['room_type_id']) : null;
    if (!$errors && !$room) { $errors[] = 'Choose a room type.'; }

    if (!$errors && $act === 'preview') {
        $av = htl_availability($pdo, $room, (string) $ci, (string) $co, $id);
        $q = htl_quote($pdo, $room, (string) $ci, (string) $co, (int) $v['rooms'], (int) $v['adults'], (int) $v['children']);
        $preview = ['av' => $av, 'q' => $q, 'room' => $room];
    } elseif (!$errors) {
        try {
            if ($cur) {
                $pdo->beginTransaction();
                $room = htl_room($pdo, (int) $v['room_type_id'], true);
                if (!$room) { throw new RuntimeException('Choose a room type.'); }
                $av = htl_availability($pdo, $room, (string) $ci, (string) $co, $id);
                if ($av['min'] < (int) $v['rooms']) { throw new RuntimeException('Only ' . $av['min'] . ' room(s) free for those dates.'); }
                $q = htl_quote($pdo, $room, (string) $ci, (string) $co, (int) $v['rooms'], (int) $v['adults'], (int) $v['children']);
                if (!$q['ok']) { throw new RuntimeException($q['error']); }
                $pdo->prepare('UPDATE hotel_reservations SET property_id=:p, room_type_id=:r, guest_name=:n, phone=:ph, email=:em, adults=:a, children=:c, rooms=:rm, check_in=:ci, check_out=:co, nights=:ni,
                    arrival_time=:at, special_requests=:sr, id_type=:it, id_number=:in, source=:src' . ($locked ? '' : ', room_total=:rt, extra_total=:et, gst_pct=:gp') . ', updated_at=NOW() WHERE id=:id')
                    ->execute([':p' => $room['property_id'], ':r' => $room['id'], ':n' => $v['guest_name'], ':ph' => $v['phone'], ':em' => $v['email'] ?: null, ':a' => (int) $v['adults'], ':c' => (int) $v['children'], ':rm' => (int) $v['rooms'],
                        ':ci' => $ci, ':co' => $co, ':ni' => $q['nights'], ':at' => htl_valid_time($v['arrival_time']), ':sr' => $v['special_requests'] ?: null, ':it' => $v['id_type'] ?: null, ':in' => $v['id_number'] ?: null, ':src' => $v['source'], ':id' => $id]
                        + ($locked ? [] : [':rt' => $q['room_total'], ':et' => $q['extra_total'], ':gp' => $q['gst_pct']]));
                htl_recalc($pdo, $id);
                $changed = ($cur['check_in'] !== $ci || $cur['check_out'] !== $co || (int) $cur['room_type_id'] !== (int) $room['id'] || (int) $cur['rooms'] !== (int) $v['rooms']);
                htl_timeline($pdo, $id, (string) $cur['status'], $changed ? 'Stay details changed' : 'Booking details edited', $changed ? date('d M', strtotime((string) $ci)) . ' - ' . date('d M Y', strtotime((string) $co)) . ', ' . (int) $v['rooms'] . ' room(s)' : null, $changed, $adminId);
                $pdo->commit();
                log_activity($adminId, 'hotel_reservation_edited', "id={$id}");
                $_SESSION['flash_success_page'] = 'Reservation updated.' . ($locked ? ' (Invoice issued, so the price was not changed.)' : ' Price recalculated.');
                header('Location: hotel_view.php?id=' . $id);
                exit;
            }
            $row = htl_create_reservation($pdo, $v, $v['source'], $adminId, $v['status'], false);
            log_activity($adminId, 'hotel_reservation_created', 'id=' . $row['id']);
            $_SESSION['flash_success_page'] = 'Reservation ' . $row['reference'] . ' created.';
            header('Location: hotel_view.php?id=' . (int) $row['id']);
            exit;
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('hotel_form: ' . $e->getMessage());
            $errors[] = 'Could not save the reservation. Please try again.';
        }
    }
}

tl_shell_top($cur ? 'Change reservation' : 'New reservation', $cur ? 'Change ' . $cur['reference'] : 'New reservation', ['Hotels' => 'hotel_dashboard.php', 'Reservations' => 'hotel_reservations.php']);
?>
<?php if ($errors): ?><div class="tx-flash err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
<?php if ($preview): $q = $preview['q']; $av = $preview['av']; $enough = $av['min'] >= (int) $v['rooms']; ?>
  <div class="panel" style="border-left:4px solid <?= $enough && $q['ok'] ? '#198754' : '#b02a20' ?>">
    <div class="panel-head"><h3><i class="fa-solid fa-calculator"></i> Availability &amp; price</h3></div>
    <p style="margin-top:0"><strong><?= $enough ? '<span style="color:#14663f">Available</span>' : '<span style="color:#b02a20">Not enough rooms</span>' ?></strong> - <?= (int) $av['min'] ?> room(s) free on every night (you asked for <?= (int) $v['rooms'] ?>).</p>
    <?php if ($q['ok']): ?>
    <div class="amount-strip"><div class="item"><div class="label"><?= (int) $q['nights'] ?> night(s) &times; <?= (int) $v['rooms'] ?> room(s)</div><div class="value"><?= e(tl_inr($q['room_total'])) ?></div></div>
      <?php if ($q['extra_total'] > 0): ?><div class="item"><div class="label">Extra guests</div><div class="value"><?= e(tl_inr($q['extra_total'])) ?></div></div><?php endif; ?>
      <div class="item"><div class="label">GST <?= e(rtrim(rtrim(number_format($q['gst_pct'], 2), '0'), '.')) ?>%</div><div class="value"><?= e(tl_inr($q['gst'])) ?></div></div>
      <div class="item ok"><div class="label">Total</div><div class="value"><?= e(tl_inr($q['total'])) ?></div></div></div>
    <?php else: ?><p style="color:#b02a20;margin-bottom:0"><?= e($q['error']) ?></p><?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" class="panel"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
  <div class="panel-head"><h3><i class="fa-solid fa-bed"></i> Stay</h3></div>
  <div class="tx-form" style="margin-bottom:22px">
    <div class="full"><label>Room type *</label><select name="room_type_id" required><option value="">- choose -</option>
      <?php foreach ($roomsAll as $r): ?><option value="<?= (int) $r['id'] ?>" <?= (string) $v['room_type_id'] === (string) $r['id'] ? 'selected' : '' ?>><?= e($r['pname'] . ' - ' . $r['name'] . ' (from ' . tl_inr($r['base_rate']) . ')') ?></option><?php endforeach; ?></select></div>
    <div><label>Check-in *</label><input type="date" name="check_in" required value="<?= e($v['check_in']) ?>" <?= $cur ? '' : 'min="' . e(date('Y-m-d')) . '"' ?>></div>
    <div><label>Check-out *</label><input type="date" name="check_out" required value="<?= e($v['check_out']) ?>"></div>
    <div><label>Rooms</label><input type="number" name="rooms" min="1" max="40" value="<?= e((string) $v['rooms']) ?>"></div>
    <div><label>Adults</label><input type="number" name="adults" min="1" max="40" value="<?= e((string) $v['adults']) ?>"></div>
    <div><label>Children</label><input type="number" name="children" min="0" max="40" value="<?= e((string) $v['children']) ?>"></div>
    <div><label>Expected arrival time</label><input type="time" name="arrival_time" value="<?= e(substr($v['arrival_time'], 0, 5)) ?>"></div>
  </div>
  <div class="panel-head"><h3><i class="fa-solid fa-user"></i> Guest</h3></div>
  <div class="tx-form" style="margin-bottom:22px">
    <div><label>Guest name *</label><input name="guest_name" required maxlength="150" value="<?= e($v['guest_name']) ?>"></div>
    <div><label>Mobile *</label><input name="phone" required maxlength="20" value="<?= e($v['phone']) ?>"></div>
    <div><label>E-mail</label><input type="email" name="email" maxlength="150" value="<?= e($v['email']) ?>"></div>
    <div><label>Booking source</label><select name="source"><?php foreach (['phone' => 'Phone call', 'walk-in' => 'Walk-in', 'agent' => 'Travel agent', 'admin' => 'Other'] as $k => $l): ?><option value="<?= $k ?>" <?= $v['source'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div><label>ID proof type</label><select name="id_type"><?php foreach (['', 'Aadhaar', 'Passport', 'Driving licence', 'Voter ID', 'PAN', 'Other'] as $o): ?><option <?= $v['id_type'] === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></div>
    <div><label>ID number</label><input name="id_number" maxlength="40" value="<?= e($v['id_number']) ?>"></div>
    <?php if (!$cur): ?><div><label>Start as</label><select name="status"><option value="confirmed" <?= $v['status'] === 'confirmed' ? 'selected' : '' ?>>Confirmed</option><option value="pending" <?= $v['status'] === 'pending' ? 'selected' : '' ?>>Pending</option></select></div><?php endif; ?>
    <div class="full"><label>Special requests</label><textarea name="special_requests" rows="2" maxlength="1500"><?= e($v['special_requests']) ?></textarea></div>
  </div>
  <?php if ($locked): ?><p class="muted" style="font-size:.86rem">An invoice has been issued, so the price will stay as invoiced.</p><?php endif; ?>
  <button class="btn btn-secondary" name="action" value="preview" type="submit"><i class="fa-solid fa-calculator"></i> Check availability &amp; price</button>
  <button class="btn btn-primary" name="action" value="save" type="submit"><i class="fa-solid fa-floppy-disk"></i> <?= $cur ? 'Save changes' : 'Create reservation' ?></button>
  <a class="btn btn-ghost" href="<?= $cur ? 'hotel_view.php?id=' . $id : 'hotel_reservations.php' ?>">Cancel</a>
</form>
<?php tl_shell_bottom();
