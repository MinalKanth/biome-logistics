<?php
/**
 * admin/hotel_rooms.php?property=ID - room types: capacity, base / weekend rate, extra-person charges, number of rooms.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hotel_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$adminId = (int) $_SESSION['admin_id'];
$AM = htl_amenities();
$errors = [];
$propId = (int) ($_GET['property'] ?? $_POST['property'] ?? 0);
$props = $pdo->query('SELECT id, name FROM hotel_properties WHERE deleted_at IS NULL ORDER BY sort_order, name')->fetchAll();
if (!$propId && $props) { $propId = (int) $props[0]['id']; }
$prop = null;
foreach ($props as $p) { if ((int) $p['id'] === $propId) { $prop = $p; } }
$editId = (int) ($_GET['edit'] ?? 0);

if ($prop && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $act = (string) ($_POST['action'] ?? '');
    $rid = (int) ($_POST['id'] ?? 0);
    try {
        if ($act === 'save') {
            $f = $_POST;
            $name = mb_substr(trim((string) ($f['name'] ?? '')), 0, 120);
            $maxA = (int) ($f['max_adults'] ?? 0); $maxC = (int) ($f['max_children'] ?? 0); $baseA = (int) ($f['base_adults'] ?? 0);
            $num = static fn($k) => (isset($f[$k]) && is_numeric($f[$k])) ? round((float) $f[$k], 2) : -1.0;
            $rate = $num('base_rate'); $wk = $num('weekend_rate'); $ex = $num('extra_adult_rate'); $ch = $num('child_rate');
            $total = (int) ($f['total_rooms'] ?? 0);
            if ($name === '') { $errors[] = 'Room type name is required.'; }
            if ($maxA < 1 || $maxA > 20) { $errors[] = 'Max adults must be 1-20.'; }
            if ($maxC < 0 || $maxC > 10) { $errors[] = 'Max children must be 0-10.'; }
            if ($baseA < 1 || $baseA > $maxA) { $errors[] = 'Adults included in the rate must be between 1 and the max adults.'; }
            if ($rate <= 0 || $rate > 1000000) { $errors[] = 'Base rate per night must be greater than zero.'; }
            if ($wk < 0 || $ex < 0 || $ch < 0) { $errors[] = 'Weekend, extra-adult and child rates cannot be negative (use 0 for none).'; }
            if ($total < 1 || $total > 500) { $errors[] = 'Number of rooms must be 1-500.'; }
            $am = array_values(array_intersect(array_keys($AM), (array) ($_POST['amenities'] ?? [])));
            if (!$errors) {
                if ($rid > 0) {   // never let inventory drop below what is already booked
                    $cur = $pdo->prepare("SELECT COALESCE(MAX(rooms),0) FROM hotel_reservations WHERE room_type_id = :r AND deleted_at IS NULL AND status IN ('pending','confirmed','checked_in') AND check_out >= CURDATE()");
                    $cur->execute([':r' => $rid]);
                    if ($total < (int) $cur->fetchColumn()) { $errors[] = 'There is already a booking for more rooms than that. Cancel it or keep the room count.'; }
                }
            }
            if (!$errors) {
                $img = htl_save_image($_FILES['image'] ?? null);
                $par = [':n' => $name, ':d' => mb_substr((string) ($f['description'] ?? ''), 0, 2000) ?: null, ':b' => mb_substr((string) ($f['bed_type'] ?? ''), 0, 40) ?: null,
                    ':sz' => ((int) ($f['size_sqft'] ?? 0)) ?: null, ':ma' => $maxA, ':mc' => $maxC, ':ba' => $baseA, ':r' => $rate, ':w' => max(0, $wk), ':e' => max(0, $ex), ':c' => max(0, $ch),
                    ':t' => $total, ':am' => implode(',', $am) ?: null, ':a' => !empty($f['is_active']) ? 1 : 0, ':so' => (int) ($f['sort_order'] ?? 0)];
                if ($rid > 0) {
                    $old = $pdo->prepare('SELECT image FROM hotel_room_types WHERE id = :i AND property_id = :p AND deleted_at IS NULL');
                    $old->execute([':i' => $rid, ':p' => $propId]);
                    $oldImg = $old->fetchColumn();
                    $pdo->prepare('UPDATE hotel_room_types SET name=:n, description=:d, bed_type=:b, size_sqft=:sz, max_adults=:ma, max_children=:mc, base_adults=:ba, base_rate=:r, weekend_rate=:w,
                        extra_adult_rate=:e, child_rate=:c, total_rooms=:t, amenities=:am, is_active=:a, sort_order=:so' . ($img ? ', image=:img' : '') . ', updated_at=NOW() WHERE id=:id AND property_id=:p AND deleted_at IS NULL')
                        ->execute($par + [':id' => $rid, ':p' => $propId] + ($img ? [':img' => $img] : []));
                    if ($img && $oldImg) { htl_delete_image((string) $oldImg); }
                } else {
                    $pdo->prepare('INSERT INTO hotel_room_types (property_id, name, description, bed_type, size_sqft, max_adults, max_children, base_adults, base_rate, weekend_rate, extra_adult_rate, child_rate, total_rooms, amenities, is_active, sort_order, image, created_at, updated_at)
                        VALUES (:p,:n,:d,:b,:sz,:ma,:mc,:ba,:r,:w,:e,:c,:t,:am,:a,:so,:img,NOW(),NOW())')->execute($par + [':p' => $propId, ':img' => $img]);
                }
                log_activity($adminId, 'hotel_room_saved', $name);
                $_SESSION['flash_success_page'] = 'Room type saved.';
                header('Location: hotel_rooms.php?property=' . $propId);
                exit;
            }
            $editId = $rid;
        } elseif ($act === 'delete' && $rid > 0) {
            $u = $pdo->prepare("SELECT COUNT(*) FROM hotel_reservations WHERE room_type_id = :r AND deleted_at IS NULL AND status IN ('pending','confirmed','checked_in') AND check_out >= CURDATE()");
            $u->execute([':r' => $rid]);
            if ((int) $u->fetchColumn() > 0) { throw new RuntimeException('This room type has current or upcoming reservations.'); }
            $pdo->prepare('UPDATE hotel_room_types SET deleted_at = NOW(), is_active = 0 WHERE id = :r AND property_id = :p')->execute([':r' => $rid, ':p' => $propId]);
            $_SESSION['flash_success_page'] = 'Room type removed.';
            header('Location: hotel_rooms.php?property=' . $propId);
            exit;
        }
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
        $editId = (int) ($_POST['id'] ?? 0);
    } catch (Throwable $e) {
        error_log('hotel_rooms: ' . $e->getMessage());
        $errors[] = 'Could not save. Please try again.';
    }
}

$rooms = [];
if ($prop) {
    $st = $pdo->prepare('SELECT * FROM hotel_room_types WHERE property_id = :p AND deleted_at IS NULL ORDER BY sort_order, base_rate');
    $st->execute([':p' => $propId]);
    $rooms = $st->fetchAll();
}
$edit = null;
foreach ($rooms as $r) { if ((int) $r['id'] === $editId) { $edit = $r; } }
$adding = $prop && (isset($_GET['add']) || $edit || $errors);
$pv = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($edit ?? []);
$v = static fn(string $k, $d = '') => e((string) ($pv[$k] ?? $d));
$selAm = $_SERVER['REQUEST_METHOD'] === 'POST' ? (array) ($_POST['amenities'] ?? []) : array_filter(explode(',', (string) ($edit['amenities'] ?? '')));

tl_shell_top('Room types', 'Room types', ['Hotels' => 'hotel_dashboard.php', 'Properties' => 'hotel_properties.php'],
    $prop ? '<a class="btn btn-primary" href="hotel_rooms.php?property=' . $propId . '&amp;add=1"><i class="fa-solid fa-plus"></i> Add room type</a>' : '');
?>
<?php if ($errors): ?><div class="tx-flash err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
<?php if (!$props): ?><div class="panel"><p style="margin:0">Add a property first. <a href="hotel_properties.php?add=1">Add property &rarr;</a></p></div><?php else: ?>
<form method="get" class="tx-filter" style="margin-bottom:14px"><select name="property" onchange="this.form.submit()"><?php foreach ($props as $p): ?><option value="<?= (int) $p['id'] ?>" <?= (int) $p['id'] === $propId ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select></form>

<?php if ($adding): ?>
<form method="post" enctype="multipart/form-data" class="panel"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="property" value="<?= $propId ?>"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? ($_POST['id'] ?? 0)) ?>">
  <div class="panel-head"><h3><i class="fa-solid fa-bed"></i> <?= $edit ? 'Edit room type' : 'Add room type' ?> - <?= e($prop['name']) ?></h3></div>
  <div class="tx-form">
    <div><label>Name *</label><input name="name" required maxlength="120" value="<?= $v('name') ?>" placeholder="Deluxe Double"></div>
    <div><label>Bed type</label><input name="bed_type" maxlength="40" value="<?= $v('bed_type') ?>" placeholder="1 King bed"></div>
    <div><label>Size (sq ft)</label><input type="number" name="size_sqft" min="0" value="<?= $v('size_sqft') ?>"></div>
    <div><label>Number of rooms of this type *</label><input type="number" name="total_rooms" min="1" max="500" required value="<?= $v('total_rooms', '1') ?>"></div>
    <div><label>Max adults / room *</label><input type="number" name="max_adults" min="1" max="20" value="<?= $v('max_adults', '2') ?>"></div>
    <div><label>Max children / room</label><input type="number" name="max_children" min="0" max="10" value="<?= $v('max_children', '1') ?>"></div>
    <div><label>Adults included in rate *</label><input type="number" name="base_adults" min="1" max="20" value="<?= $v('base_adults', '2') ?>"></div>
    <div><label>Base rate / night (&#8377;) *</label><input type="number" name="base_rate" step="0.01" min="0" value="<?= $v('base_rate') ?>"></div>
    <div><label>Fri / Sat rate (&#8377;, 0 = same)</label><input type="number" name="weekend_rate" step="0.01" min="0" value="<?= $v('weekend_rate', '0') ?>"></div>
    <div><label>Extra adult / night (&#8377;)</label><input type="number" name="extra_adult_rate" step="0.01" min="0" value="<?= $v('extra_adult_rate', '0') ?>"></div>
    <div><label>Child / night (&#8377;, 0 = free)</label><input type="number" name="child_rate" step="0.01" min="0" value="<?= $v('child_rate', '0') ?>"></div>
    <div><label>Display order</label><input type="number" name="sort_order" value="<?= $v('sort_order', '0') ?>"></div>
    <div class="full"><label>Description</label><textarea name="description" rows="3" maxlength="2000"><?= $v('description') ?></textarea></div>
    <div class="full"><label>Photo (JPG / PNG / WebP, max 4 MB)</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp">
      <?php if (!empty($edit['image'])): ?><div style="margin-top:8px"><img src="../<?= e(htl_image_url($edit['image'])) ?>" alt="" style="height:60px;border-radius:8px"></div><?php endif; ?></div>
    <div class="full"><label>Room amenities</label><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:6px 14px">
      <?php foreach ($AM as $k => [$l, $ic]): ?><label style="display:flex;gap:7px;align-items:center;text-transform:none;letter-spacing:0;font-size:.88rem;margin:0"><input type="checkbox" name="amenities[]" value="<?= e($k) ?>" style="width:auto" <?= in_array($k, $selAm, true) ? 'checked' : '' ?>><i class="fa-solid <?= e($ic) ?>" style="width:16px;color:#198754"></i> <?= e($l) ?></label><?php endforeach; ?></div></div>
    <div class="full"><label style="display:flex;gap:8px;align-items:center;text-transform:none;letter-spacing:0"><input type="checkbox" name="is_active" value="1" style="width:auto" <?= (!$pv || !empty($pv['is_active'])) ? 'checked' : '' ?>> Available for booking</label></div>
  </div>
  <div style="margin-top:16px"><button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save room type</button> <a class="btn btn-ghost" href="hotel_rooms.php?property=<?= $propId ?>">Cancel</a></div>
</form>
<?php endif; ?>

<div class="panel">
<?php if (!$rooms): ?><p style="text-align:center;color:#6b7a72;padding:30px">No room types yet for <?= e($prop['name']) ?>.</p><?php else: ?>
  <div class="tx-scroll"><table class="tx-table"><thead><tr><th>Room type</th><th>Sleeps</th><th class="r">Rooms</th><th class="r">Base / night</th><th class="r">Fri-Sat</th><th class="r">Extra adult</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rooms as $r): ?><tr>
    <td style="display:flex;gap:10px;align-items:center"><img src="../<?= e(htl_image_url($r['image'])) ?>" alt="" style="width:54px;height:40px;object-fit:cover;border-radius:6px"><div><strong><?= e($r['name']) ?></strong><br><small class="muted"><?= e($r['bed_type'] ?: '') ?></small></div></td>
    <td><?= (int) $r['max_adults'] ?>A + <?= (int) $r['max_children'] ?>C</td><td class="r"><?= (int) $r['total_rooms'] ?></td><td class="r"><?= e(tl_inr($r['base_rate'])) ?></td>
    <td class="r"><?= (float) $r['weekend_rate'] > 0 ? e(tl_inr($r['weekend_rate'])) : '<span class="muted">same</span>' ?></td><td class="r"><?= (float) $r['extra_adult_rate'] > 0 ? e(tl_inr($r['extra_adult_rate'])) : '<span class="muted">-</span>' ?></td>
    <td><?= $r['is_active'] ? tl_badge('Bookable', 'success') : tl_badge('Off', 'muted') ?></td>
    <td style="white-space:nowrap"><a class="btn btn-small btn-secondary" href="hotel_rates.php?room=<?= (int) $r['id'] ?>"><i class="fa-solid fa-calendar-days"></i> Rates &amp; blocks</a>
      <a class="btn btn-small btn-secondary" href="hotel_rooms.php?property=<?= $propId ?>&amp;edit=<?= (int) $r['id'] ?>"><i class="fa-solid fa-pen"></i></a>
      <form method="post" style="display:inline" onsubmit="return confirm('Remove this room type?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="property" value="<?= $propId ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-small btn-danger"><i class="fa-solid fa-trash"></i></button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
</div>
<?php endif; tl_shell_bottom();
