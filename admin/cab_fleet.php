<?php
/**
 * admin/cab_fleet.php - vehicles and drivers: add / edit / status / soft delete,
 * with insurance, permit, fitness, PUC and licence expiry warnings.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/cab_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$adminId = (int) $_SESSION['admin_id'];
$tab = ($_GET['tab'] ?? 'vehicles') === 'drivers' ? 'drivers' : 'vehicles';
$editId = (int) ($_GET['edit'] ?? 0);
$errors = [];

$vehStatuses = ['available' => 'Available', 'maintenance' => 'In maintenance', 'inactive' => 'Inactive'];
$drvStatuses = ['active' => 'Active', 'on_leave' => 'On leave', 'inactive' => 'Inactive'];
$d = static fn($x) => cab_valid_date((string) $x);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $act = (string) ($_POST['action'] ?? '');
    $pid = (int) ($_POST['id'] ?? 0);
    $tab = ($_POST['tab'] ?? '') === 'drivers' ? 'drivers' : 'vehicles';
    try {
        if ($act === 'save_vehicle') {
            $f = array_map(static fn($x) => trim(str_replace("\0", '', (string) $x)), $_POST);
            $name = mb_substr($f['name'] ?? '', 0, 120);
            $cat = $f['category'] ?? '';
            $reg = strtoupper(preg_replace('/\s+/', ' ', $f['reg_number'] ?? ''));
            $seats = (int) ($f['seats'] ?? 0);
            $status = $f['status'] ?? 'available';
            if ($name === '' || !in_array($cat, cab_vehicle_types(), true)) { $errors[] = 'Vehicle name and a valid category are required.'; }
            if ($seats < 1 || $seats > 60) { $errors[] = 'Seats must be 1-60.'; }
            if (!isset($vehStatuses[$status])) { $errors[] = 'Invalid status.'; }
            if ($reg !== '') {
                $dup = $pdo->prepare('SELECT COUNT(*) FROM cab_vehicles WHERE reg_number = :r AND id <> :i AND deleted_at IS NULL');
                $dup->execute([':r' => $reg, ':i' => $pid]);
                if ((int) $dup->fetchColumn() > 0) { $errors[] = 'Another vehicle already has that registration number.'; }
            }
            if (!$errors) {
                $par = [':n' => $name, ':c' => $cat, ':r' => $reg ?: null, ':s' => $seats, ':f' => mb_substr($f['fuel_type'] ?? '', 0, 20) ?: null,
                    ':t' => mb_substr($f['transmission'] ?? '', 0, 20) ?: null, ':ac' => !empty($_POST['ac']) ? 1 : 0, ':col' => mb_substr($f['color'] ?? '', 0, 30) ?: null,
                    ':y' => ((int) ($f['year_of_make'] ?? 0)) ?: null, ':ins' => $d($f['insurance_expiry'] ?? ''), ':per' => $d($f['permit_expiry'] ?? ''),
                    ':fit' => $d($f['fitness_expiry'] ?? ''), ':puc' => $d($f['puc_expiry'] ?? ''), ':odo' => max(0, (int) ($f['odometer'] ?? 0)),
                    ':st' => $status, ':nt' => mb_substr($f['notes'] ?? '', 0, 1000) ?: null];
                if ($pid > 0) {
                    $pdo->prepare('UPDATE cab_vehicles SET name=:n, category=:c, reg_number=:r, seats=:s, fuel_type=:f, transmission=:t, ac=:ac, color=:col, year_of_make=:y,
                        insurance_expiry=:ins, permit_expiry=:per, fitness_expiry=:fit, puc_expiry=:puc, odometer=:odo, status=:st, notes=:nt, updated_at=NOW() WHERE id=:id AND deleted_at IS NULL')
                        ->execute($par + [':id' => $pid]);
                } else {
                    $pdo->prepare('INSERT INTO cab_vehicles (name, category, reg_number, seats, fuel_type, transmission, ac, color, year_of_make, insurance_expiry, permit_expiry, fitness_expiry, puc_expiry, odometer, status, notes, created_at, updated_at)
                        VALUES (:n,:c,:r,:s,:f,:t,:ac,:col,:y,:ins,:per,:fit,:puc,:odo,:st,:nt,NOW(),NOW())')->execute($par);
                }
                log_activity($adminId, 'cab_vehicle_saved', $name);
                $_SESSION['flash_success_page'] = 'Vehicle saved.';
                header('Location: cab_fleet.php?tab=vehicles');
                exit;
            }
            $tab = 'vehicles';
            $editId = $pid;
        } elseif ($act === 'save_driver') {
            $f = array_map(static fn($x) => trim(str_replace("\0", '', (string) $x)), $_POST);
            $name = mb_substr($f['full_name'] ?? '', 0, 150);
            $phone = $f['phone'] ?? '';
            $status = $f['status'] ?? 'active';
            if ($name === '') { $errors[] = 'Driver name is required.'; }
            if (!preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) { $errors[] = 'Enter a valid phone number.'; }
            if (!isset($drvStatuses[$status])) { $errors[] = 'Invalid status.'; }
            if (!$errors) {
                $par = [':n' => $name, ':p' => $phone, ':ap' => mb_substr($f['alt_phone'] ?? '', 0, 20) ?: null, ':ln' => mb_substr($f['license_number'] ?? '', 0, 40) ?: null,
                    ':le' => $d($f['license_expiry'] ?? ''), ':bn' => mb_substr($f['badge_number'] ?? '', 0, 40) ?: null, ':ad' => mb_substr($f['address'] ?? '', 0, 255) ?: null,
                    ':jo' => $d($f['joined_on'] ?? ''), ':w' => max(0, round((float) ($f['daily_wage'] ?? 0), 2)), ':st' => $status, ':nt' => mb_substr($f['notes'] ?? '', 0, 1000) ?: null];
                if ($pid > 0) {
                    $pdo->prepare('UPDATE cab_drivers SET full_name=:n, phone=:p, alt_phone=:ap, license_number=:ln, license_expiry=:le, badge_number=:bn, address=:ad, joined_on=:jo, daily_wage=:w, status=:st, notes=:nt, updated_at=NOW() WHERE id=:id AND deleted_at IS NULL')
                        ->execute($par + [':id' => $pid]);
                } else {
                    $pdo->prepare('INSERT INTO cab_drivers (full_name, phone, alt_phone, license_number, license_expiry, badge_number, address, joined_on, daily_wage, status, notes, created_at, updated_at)
                        VALUES (:n,:p,:ap,:ln,:le,:bn,:ad,:jo,:w,:st,:nt,NOW(),NOW())')->execute($par);
                }
                log_activity($adminId, 'cab_driver_saved', $name);
                $_SESSION['flash_success_page'] = 'Driver saved.';
                header('Location: cab_fleet.php?tab=drivers');
                exit;
            }
            $tab = 'drivers';
            $editId = $pid;
        } elseif (($act === 'delete_vehicle' || $act === 'delete_driver') && $pid > 0) {
            $isV = $act === 'delete_vehicle';
            $col = $isV ? 'vehicle_id' : 'driver_id';
            $act2 = "'" . implode("','", cab_active_statuses()) . "'";
            $use = $pdo->prepare("SELECT COUNT(*) FROM cab_booking_requests WHERE $col = :i AND deleted_at IS NULL AND status IN ($act2) AND COALESCE(end_date, journey_date) >= CURDATE()");
            $use->execute([':i' => $pid]);
            if ((int) $use->fetchColumn() > 0) {
                $_SESSION['flash_error_page'] = 'Cannot remove: it is assigned to a current or upcoming booking.';
            } else {
                $pdo->prepare('UPDATE ' . ($isV ? 'cab_vehicles' : 'cab_drivers') . ' SET deleted_at = NOW() WHERE id = :i')->execute([':i' => $pid]);
                log_activity($adminId, 'cab_fleet_removed', "{$act} id={$pid}");
                $_SESSION['flash_success_page'] = 'Removed.';
            }
            header('Location: cab_fleet.php?tab=' . ($isV ? 'vehicles' : 'drivers'));
            exit;
        }
    } catch (Throwable $e) {
        error_log('cab_fleet: ' . $e->getMessage());
        $errors[] = 'Could not save. Please try again.';
    }
}

$expiry = static function (?string $date): string {
    if (!$date) { return '<span style="color:#9aa8a0">-</span>'; }
    $days = (int) floor((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
    $txt = e(date('d M Y', strtotime($date)));
    if ($days < 0) { return '<span class="tx-exp-bad" title="Expired">' . $txt . ' (expired)</span>'; }
    if ($days <= 30) { return '<span class="tx-exp-soon">' . $txt . ' (' . $days . 'd)</span>'; }
    return $txt;
};

$vehicles = $pdo->query('SELECT * FROM cab_vehicles WHERE deleted_at IS NULL ORDER BY category, name')->fetchAll();
$drivers = $pdo->query('SELECT * FROM cab_drivers WHERE deleted_at IS NULL ORDER BY full_name')->fetchAll();
$edit = null;
if ($editId > 0) {
    foreach ($tab === 'vehicles' ? $vehicles : $drivers as $r) {
        if ((int) $r['id'] === $editId) { $edit = $r; }
    }
}
$adding = isset($_GET['add']) || $edit || ($errors && $_SERVER['REQUEST_METHOD'] === 'POST');
$pv = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($edit ?? []); // repopulate after validation error
$val = static fn(string $k, $def = '') => e((string) ($pv[$k] ?? $def));

tl_shell_top('Fleet & drivers', 'Fleet & drivers', ['Cab Services' => 'cab_dashboard.php'],
    '<a class="btn btn-primary" href="cab_fleet.php?tab=' . $tab . '&amp;add=1"><i class="fa-solid fa-plus"></i> Add ' . ($tab === 'vehicles' ? 'vehicle' : 'driver') . '</a>');
?>
<?php if ($errors): ?><div class="tx-flash err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
<div class="tx-tabs">
  <a href="cab_fleet.php?tab=vehicles" class="<?= $tab === 'vehicles' ? 'on' : '' ?>">Vehicles (<?= count($vehicles) ?>)</a>
  <a href="cab_fleet.php?tab=drivers" class="<?= $tab === 'drivers' ? 'on' : '' ?>">Drivers (<?= count($drivers) ?>)</a>
</div>

<?php if ($adding && $tab === 'vehicles'): ?>
<form method="post" class="panel"><?= csrf_field() ?><input type="hidden" name="action" value="save_vehicle"><input type="hidden" name="tab" value="vehicles"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? ($_POST['id'] ?? 0)) ?>">
  <div class="panel-head"><h3><i class="fa-solid fa-car"></i> <?= $edit ? 'Edit vehicle' : 'Add vehicle' ?></h3></div>
  <div class="tx-form">
    <div><label>Name / model *</label><input name="name" required maxlength="120" value="<?= $val('name') ?>" placeholder="Toyota Innova Crysta"></div>
    <div><label>Category *</label><select name="category"><?php foreach (cab_vehicle_types() as $c): ?><option <?= ($pv['category'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?></select></div>
    <div><label>Registration no.</label><input name="reg_number" maxlength="30" value="<?= $val('reg_number') ?>" placeholder="AS 17 AB 1234"></div>
    <div><label>Seats *</label><input type="number" min="1" max="60" name="seats" value="<?= $val('seats', '4') ?>"></div>
    <div><label>Fuel</label><select name="fuel_type"><?php foreach (['', 'Petrol', 'Diesel', 'CNG', 'Electric', 'Hybrid'] as $o): ?><option <?= ($pv['fuel_type'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></div>
    <div><label>Transmission</label><select name="transmission"><?php foreach (['', 'Manual', 'Automatic'] as $o): ?><option <?= ($pv['transmission'] ?? '') === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></div>
    <div><label>Colour</label><input name="color" maxlength="30" value="<?= $val('color') ?>"></div>
    <div><label>Year</label><input type="number" min="1990" max="2100" name="year_of_make" value="<?= $val('year_of_make') ?>"></div>
    <div><label>Odometer (km)</label><input type="number" min="0" name="odometer" value="<?= $val('odometer', '0') ?>"></div>
    <div><label>Insurance expiry</label><input type="date" name="insurance_expiry" value="<?= $val('insurance_expiry') ?>"></div>
    <div><label>Permit expiry</label><input type="date" name="permit_expiry" value="<?= $val('permit_expiry') ?>"></div>
    <div><label>Fitness expiry</label><input type="date" name="fitness_expiry" value="<?= $val('fitness_expiry') ?>"></div>
    <div><label>PUC expiry</label><input type="date" name="puc_expiry" value="<?= $val('puc_expiry') ?>"></div>
    <div><label>Status</label><select name="status"><?php foreach ($vehStatuses as $k => $l): ?><option value="<?= $k ?>" <?= ($pv['status'] ?? 'available') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div style="align-self:end"><label style="display:flex;gap:8px;align-items:center;text-transform:none;font-size:.9rem"><input type="checkbox" name="ac" value="1" style="width:auto" <?= !$pv || !empty($pv['ac']) ? 'checked' : '' ?>> Air-conditioned</label></div>
    <div class="full"><label>Notes</label><textarea name="notes" rows="2" maxlength="1000"><?= $val('notes') ?></textarea></div>
  </div>
  <div style="margin-top:16px"><button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save vehicle</button> <a class="btn btn-ghost" href="cab_fleet.php?tab=vehicles">Cancel</a></div>
</form>
<?php elseif ($adding && $tab === 'drivers'): ?>
<form method="post" class="panel"><?= csrf_field() ?><input type="hidden" name="action" value="save_driver"><input type="hidden" name="tab" value="drivers"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? ($_POST['id'] ?? 0)) ?>">
  <div class="panel-head"><h3><i class="fa-solid fa-id-card"></i> <?= $edit ? 'Edit driver' : 'Add driver' ?></h3></div>
  <div class="tx-form">
    <div><label>Full name *</label><input name="full_name" required maxlength="150" value="<?= $val('full_name') ?>"></div>
    <div><label>Phone *</label><input name="phone" required maxlength="20" value="<?= $val('phone') ?>"></div>
    <div><label>Alt. phone</label><input name="alt_phone" maxlength="20" value="<?= $val('alt_phone') ?>"></div>
    <div><label>Licence no.</label><input name="license_number" maxlength="40" value="<?= $val('license_number') ?>"></div>
    <div><label>Licence expiry</label><input type="date" name="license_expiry" value="<?= $val('license_expiry') ?>"></div>
    <div><label>Badge no.</label><input name="badge_number" maxlength="40" value="<?= $val('badge_number') ?>"></div>
    <div><label>Joined on</label><input type="date" name="joined_on" value="<?= $val('joined_on') ?>"></div>
    <div><label>Daily wage (&#8377;)</label><input type="number" min="0" step="0.01" name="daily_wage" value="<?= $val('daily_wage', '0') ?>"></div>
    <div><label>Status</label><select name="status"><?php foreach ($drvStatuses as $k => $l): ?><option value="<?= $k ?>" <?= ($pv['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
    <div class="full"><label>Address</label><input name="address" maxlength="255" value="<?= $val('address') ?>"></div>
    <div class="full"><label>Notes</label><textarea name="notes" rows="2" maxlength="1000"><?= $val('notes') ?></textarea></div>
  </div>
  <div style="margin-top:16px"><button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save driver</button> <a class="btn btn-ghost" href="cab_fleet.php?tab=drivers">Cancel</a></div>
</form>
<?php endif; ?>

<div class="panel">
<?php if ($tab === 'vehicles'): ?>
  <?php if (!$vehicles): ?><p style="text-align:center;color:#6b7a72;padding:30px">No vehicles yet. Add your first vehicle to start assigning bookings.</p><?php else: ?>
  <div class="tx-scroll"><table class="tx-table"><thead><tr><th>Vehicle</th><th>Reg. no.</th><th>Seats</th><th>Insurance</th><th>Permit</th><th>Fitness</th><th>PUC</th><th>Odometer</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($vehicles as $r): ?><tr>
    <td><strong><?= e($r['name']) ?></strong><br><small style="color:#6b7a72"><?= e($r['category']) ?><?= $r['fuel_type'] ? ' &middot; ' . e($r['fuel_type']) : '' ?></small></td>
    <td><?= e($r['reg_number'] ?: '-') ?></td><td><?= (int) $r['seats'] ?></td>
    <td><?= $expiry($r['insurance_expiry']) ?></td><td><?= $expiry($r['permit_expiry']) ?></td><td><?= $expiry($r['fitness_expiry']) ?></td><td><?= $expiry($r['puc_expiry']) ?></td>
    <td><?= number_format((int) $r['odometer']) ?> km</td>
    <td><span class="badge badge-<?= $r['status'] === 'available' ? 'success' : ($r['status'] === 'maintenance' ? 'warning' : 'muted') ?>"><?= e($vehStatuses[$r['status']] ?? $r['status']) ?></span></td>
    <td style="white-space:nowrap"><a class="btn btn-small btn-secondary" href="cab_fleet.php?tab=vehicles&amp;edit=<?= (int) $r['id'] ?>"><i class="fa-solid fa-pen"></i></a>
      <form method="post" style="display:inline" onsubmit="return confirm('Remove this vehicle?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_vehicle"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-small btn-danger"><i class="fa-solid fa-trash"></i></button></form></td>
  </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
<?php else: ?>
  <?php if (!$drivers): ?><p style="text-align:center;color:#6b7a72;padding:30px">No drivers yet.</p><?php else: ?>
  <div class="tx-scroll"><table class="tx-table"><thead><tr><th>Driver</th><th>Phone</th><th>Licence</th><th>Licence expiry</th><th class="r">Daily wage</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($drivers as $r): ?><tr>
    <td><strong><?= e($r['full_name']) ?></strong></td><td><a href="tel:<?= e(cab_clean_phone($r['phone'])) ?>"><?= e($r['phone']) ?></a></td>
    <td><?= e($r['license_number'] ?: '-') ?></td><td><?= $expiry($r['license_expiry']) ?></td><td class="r"><?= e(tl_inr($r['daily_wage'])) ?></td>
    <td><span class="badge badge-<?= $r['status'] === 'active' ? 'success' : ($r['status'] === 'on_leave' ? 'warning' : 'muted') ?>"><?= e($drvStatuses[$r['status']] ?? $r['status']) ?></span></td>
    <td style="white-space:nowrap"><a class="btn btn-small btn-secondary" href="cab_fleet.php?tab=drivers&amp;edit=<?= (int) $r['id'] ?>"><i class="fa-solid fa-pen"></i></a>
      <form method="post" style="display:inline" onsubmit="return confirm('Remove this driver?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_driver"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-small btn-danger"><i class="fa-solid fa-trash"></i></button></form></td>
  </tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php tl_shell_bottom();
