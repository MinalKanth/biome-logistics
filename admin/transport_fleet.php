<?php
/**
 * admin/transport_fleet.php - Drivers & Vehicles
 * Add / edit / activate / deactivate drivers and trucks. The Add/Edit booking pages
 * only list ACTIVE drivers and vehicles, so this is where you keep them up to date.
 * Insurance / fitness / permit / licence expiry dates are colour-flagged.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/transport_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$tab = (($_GET['tab'] ?? 'drivers') === 'vehicles') ? 'vehicles' : 'drivers';
$errors = [];

$validDate = static function (string $d): ?string {
    if ($d === '') {
        return null;
    }
    $x = DateTime::createFromFormat('!Y-m-d', $d);
    return ($x && $x->format('Y-m-d') === $d) ? $d : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $action = (string) ($_POST['action'] ?? '');
    $rid = (int) ($_POST['rid'] ?? 0);

    try {
        if ($action === 'save_driver') {
            $name = mb_substr(clean_input((string) ($_POST['full_name'] ?? '')), 0, 150);
            $mobile = mb_substr(clean_input((string) ($_POST['mobile'] ?? '')), 0, 30);
            $lic = mb_substr(clean_input((string) ($_POST['license_number'] ?? '')), 0, 50);
            $exp = $validDate(clean_input((string) ($_POST['license_expiry'] ?? '')));
            $status = ($_POST['employment_status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            if ($name === '') {
                $errors[] = 'Driver name is required.';
            }
            if ($mobile === '' || strlen(preg_replace('/\D/', '', $mobile) ?? '') < 10) {
                $errors[] = 'Enter a valid driver mobile number.';
            }
            if (!$errors) {
                if ($rid > 0) {
                    $pdo->prepare('UPDATE transport_drivers SET full_name=:n, mobile=:m, license_number=:l, license_expiry=:e, employment_status=:s, updated_at=NOW() WHERE id=:id')
                        ->execute([':n' => $name, ':m' => $mobile, ':l' => $lic ?: null, ':e' => $exp, ':s' => $status, ':id' => $rid]);
                } else {
                    $pdo->prepare('INSERT INTO transport_drivers (full_name, mobile, license_number, license_expiry, employment_status, created_at, updated_at) VALUES (:n,:m,:l,:e,:s,NOW(),NOW())')
                        ->execute([':n' => $name, ':m' => $mobile, ':l' => $lic ?: null, ':e' => $exp, ':s' => $status]);
                }
                log_activity((int) $_SESSION['admin_id'], 'transport_driver_saved', $name);
                $_SESSION['flash_success_page'] = 'Driver saved.';
            }
        } elseif ($action === 'save_vehicle') {
            $reg = strtoupper(mb_substr(clean_input((string) ($_POST['registration_number'] ?? '')), 0, 30));
            $type = mb_substr(clean_input((string) ($_POST['vehicle_type'] ?? '')), 0, 100);
            $model = mb_substr(clean_input((string) ($_POST['make_model'] ?? '')), 0, 100);
            $cap = ($_POST['capacity_tons'] ?? '') !== '' && is_numeric($_POST['capacity_tons']) ? (float) $_POST['capacity_tons'] : null;
            $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive', 'maintenance'], true) ? (string) $_POST['status'] : 'active';
            $ins = $validDate(clean_input((string) ($_POST['insurance_expiry'] ?? '')));
            $fit = $validDate(clean_input((string) ($_POST['fitness_expiry'] ?? '')));
            $per = $validDate(clean_input((string) ($_POST['permit_expiry'] ?? '')));
            if ($reg === '') {
                $errors[] = 'Registration number is required.';
            }
            if (!$errors) {
                $dup = $pdo->prepare('SELECT COUNT(*) FROM transport_vehicles WHERE registration_number = :r AND id <> :id');
                $dup->execute([':r' => $reg, ':id' => $rid]);
                if ((int) $dup->fetchColumn() > 0) {
                    $errors[] = 'A vehicle with that registration number already exists.';
                }
            }
            if (!$errors) {
                $p = [':r' => $reg, ':t' => $type ?: null, ':m' => $model ?: null, ':c' => $cap, ':s' => $status, ':i' => $ins, ':f' => $fit, ':p' => $per];
                if ($rid > 0) {
                    $pdo->prepare('UPDATE transport_vehicles SET registration_number=:r, vehicle_type=:t, make_model=:m, capacity_tons=:c, status=:s, insurance_expiry=:i, fitness_expiry=:f, permit_expiry=:p, updated_at=NOW() WHERE id=:id')
                        ->execute($p + [':id' => $rid]);
                } else {
                    $pdo->prepare('INSERT INTO transport_vehicles (registration_number, vehicle_type, make_model, capacity_tons, status, insurance_expiry, fitness_expiry, permit_expiry, created_at, updated_at) VALUES (:r,:t,:m,:c,:s,:i,:f,:p,NOW(),NOW())')
                        ->execute($p);
                }
                log_activity((int) $_SESSION['admin_id'], 'transport_vehicle_saved', $reg);
                $_SESSION['flash_success_page'] = 'Vehicle saved.';
            }
        } elseif ($action === 'toggle_driver' && $rid > 0) {
            $pdo->prepare("UPDATE transport_drivers SET employment_status = IF(employment_status='active','inactive','active') WHERE id = :id")->execute([':id' => $rid]);
        } elseif ($action === 'toggle_vehicle' && $rid > 0) {
            $pdo->prepare("UPDATE transport_vehicles SET status = IF(status='active','inactive','active') WHERE id = :id")->execute([':id' => $rid]);
        }
    } catch (Throwable $e) {
        error_log('fleet: ' . $e->getMessage());
        $errors[] = 'Something went wrong while saving. Please try again.';
    }
    if (!$errors) {
        header('Location: transport_fleet.php?tab=' . ($action === 'save_vehicle' || $action === 'toggle_vehicle' ? 'vehicles' : 'drivers'));
        exit;
    }
    $tab = in_array($action, ['save_vehicle', 'toggle_vehicle'], true) ? 'vehicles' : 'drivers';
}

$editId = (int) ($_GET['edit'] ?? 0);
$edit = null;
if ($editId > 0) {
    $s = $pdo->prepare($tab === 'drivers' ? 'SELECT * FROM transport_drivers WHERE id = :id' : 'SELECT * FROM transport_vehicles WHERE id = :id');
    $s->execute([':id' => $editId]);
    $edit = $s->fetch() ?: null;
}

$drivers  = $pdo->query('SELECT d.*, (SELECT COUNT(*) FROM transport_bookings b WHERE b.driver_id = d.id AND b.deleted_at IS NULL) AS trips FROM transport_drivers d ORDER BY employment_status, full_name')->fetchAll();
$vehicles = $pdo->query('SELECT v.*, (SELECT COUNT(*) FROM transport_bookings b WHERE b.vehicle_id = v.id AND b.deleted_at IS NULL) AS trips FROM transport_vehicles v ORDER BY status, registration_number')->fetchAll();

$expiry = static function (?string $d): string {
    if (!$d) {
        return '<span class="muted">—</span>';
    }
    $days = (int) floor((strtotime($d) - strtotime('today')) / 86400);
    $label = e(date('d M Y', strtotime($d)));
    if ($days < 0) {
        return '<span class="tx-exp-bad">' . $label . ' (expired)</span>';
    }
    if ($days <= 30) {
        return '<span class="tx-exp-soon">' . $label . ' (' . $days . 'd)</span>';
    }
    return $label;
};

tl_shell_top('Drivers & Vehicles', 'Drivers & Vehicles', ['Transport' => 'transport_manage.php']);
?>
<?php if ($errors): ?><div class="tx-flash err"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="tx-tabs">
  <a href="?tab=drivers" class="<?= $tab === 'drivers' ? 'on' : '' ?>"><i class="fa-solid fa-id-card"></i> Drivers (<?= count($drivers) ?>)</a>
  <a href="?tab=vehicles" class="<?= $tab === 'vehicles' ? 'on' : '' ?>"><i class="fa-solid fa-truck"></i> Vehicles (<?= count($vehicles) ?>)</a>
</div>

<?php if ($tab === 'drivers'): ?>
<div class="panel">
  <div class="panel-head"><h3><i class="fa-solid fa-user-plus"></i> <?= $edit ? 'Edit driver' : 'Add driver' ?></h3><?php if ($edit): ?><a class="btn btn-small btn-ghost" href="?tab=drivers">Cancel</a><?php endif; ?></div>
  <form method="post" class="tx-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_driver"><input type="hidden" name="rid" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <div><label>Full name *</label><input name="full_name" maxlength="150" required value="<?= e($edit['full_name'] ?? '') ?>"></div>
    <div><label>Mobile *</label><input name="mobile" type="tel" maxlength="30" required value="<?= e($edit['mobile'] ?? '') ?>"></div>
    <div><label>Licence no.</label><input name="license_number" maxlength="50" value="<?= e($edit['license_number'] ?? '') ?>"></div>
    <div><label>Licence expiry</label><input name="license_expiry" type="date" value="<?= e($edit['license_expiry'] ?? '') ?>"></div>
    <div><label>Status</label><select name="employment_status"><option value="active"<?= ($edit['employment_status'] ?? 'active') === 'active' ? ' selected' : '' ?>>Active</option><option value="inactive"<?= ($edit['employment_status'] ?? '') === 'inactive' ? ' selected' : '' ?>>Inactive</option></select></div>
    <div style="align-self:end"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save driver</button></div>
  </form>
</div>
<div class="panel"><div class="tx-scroll"><table class="tx-table">
  <thead><tr><th>Name</th><th>Mobile</th><th>Licence</th><th>Expiry</th><th class="r">Trips</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$drivers): ?><tr><td colspan="7" style="text-align:center;padding:30px;color:#6b7a72">No drivers yet. Add your first driver above so you can assign them to bookings.</td></tr><?php endif; ?>
  <?php foreach ($drivers as $d): ?>
    <tr>
      <td><strong><?= e($d['full_name']) ?></strong></td><td><?= e($d['mobile']) ?></td><td><?= e($d['license_number'] ?: '—') ?></td>
      <td><?= $expiry($d['license_expiry'] ?? null) ?></td><td class="r"><?= (int) $d['trips'] ?></td>
      <td><?= tl_badge(ucfirst((string) $d['employment_status']), $d['employment_status'] === 'active' ? 'success' : 'muted') ?></td>
      <td style="white-space:nowrap">
        <a class="btn btn-small btn-secondary" href="?tab=drivers&edit=<?= (int) $d['id'] ?>">Edit</a>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_driver"><input type="hidden" name="rid" value="<?= (int) $d['id'] ?>"><button class="btn btn-small btn-ghost" type="submit"><?= $d['employment_status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>

<?php else: ?>
<div class="panel">
  <div class="panel-head"><h3><i class="fa-solid fa-truck-moving"></i> <?= $edit ? 'Edit vehicle' : 'Add vehicle' ?></h3><?php if ($edit): ?><a class="btn btn-small btn-ghost" href="?tab=vehicles">Cancel</a><?php endif; ?></div>
  <form method="post" class="tx-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save_vehicle"><input type="hidden" name="rid" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <div><label>Registration no. *</label><input name="registration_number" maxlength="30" required placeholder="AS01AB1234" value="<?= e($edit['registration_number'] ?? '') ?>"></div>
    <div><label>Type</label><select name="vehicle_type"><option value="">Select</option>
      <?php foreach (array_slice(tl_vehicle_types(), 0, 3) as $vt): ?><option<?= ($edit['vehicle_type'] ?? '') === $vt ? ' selected' : '' ?>><?= e($vt) ?></option><?php endforeach; ?></select></div>
    <div><label>Make / model</label><input name="make_model" maxlength="100" value="<?= e($edit['make_model'] ?? '') ?>"></div>
    <div><label>Capacity (tons)</label><input name="capacity_tons" type="number" step="0.1" min="0" value="<?= e($edit['capacity_tons'] ?? '') ?>"></div>
    <div><label>Insurance expiry</label><input name="insurance_expiry" type="date" value="<?= e($edit['insurance_expiry'] ?? '') ?>"></div>
    <div><label>Fitness expiry</label><input name="fitness_expiry" type="date" value="<?= e($edit['fitness_expiry'] ?? '') ?>"></div>
    <div><label>Permit expiry</label><input name="permit_expiry" type="date" value="<?= e($edit['permit_expiry'] ?? '') ?>"></div>
    <div><label>Status</label><select name="status">
      <?php foreach (['active' => 'Active', 'maintenance' => 'In maintenance', 'inactive' => 'Inactive'] as $k => $l): ?><option value="<?= e($k) ?>"<?= ($edit['status'] ?? 'active') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div style="align-self:end"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save vehicle</button></div>
  </form>
</div>
<div class="panel"><div class="tx-scroll"><table class="tx-table">
  <thead><tr><th>Registration</th><th>Type</th><th>Insurance</th><th>Fitness</th><th>Permit</th><th class="r">Trips</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php if (!$vehicles): ?><tr><td colspan="8" style="text-align:center;padding:30px;color:#6b7a72">No vehicles yet. Add your trucks above so you can assign them to bookings.</td></tr><?php endif; ?>
  <?php foreach ($vehicles as $v): ?>
    <tr>
      <td><strong><?= e($v['registration_number']) ?></strong></td><td><?= e($v['vehicle_type'] ?: '—') ?></td>
      <td><?= $expiry($v['insurance_expiry'] ?? null) ?></td><td><?= $expiry($v['fitness_expiry'] ?? null) ?></td><td><?= $expiry($v['permit_expiry'] ?? null) ?></td>
      <td class="r"><?= (int) $v['trips'] ?></td>
      <td><?= tl_badge(ucfirst((string) $v['status']), $v['status'] === 'active' ? 'success' : ($v['status'] === 'maintenance' ? 'warning' : 'muted')) ?></td>
      <td style="white-space:nowrap">
        <a class="btn btn-small btn-secondary" href="?tab=vehicles&edit=<?= (int) $v['id'] ?>">Edit</a>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_vehicle"><input type="hidden" name="rid" value="<?= (int) $v['id'] ?>"><button class="btn btn-small btn-ghost" type="submit"><?= $v['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>
<?php tl_shell_bottom(); ?>
