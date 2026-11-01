<?php
/**
 * admin/hotel_properties.php - hotels / homestays: add, edit, photo, amenities, show/hide on website, delete.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hotel_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$adminId = (int) $_SESSION['admin_id'];
$TYPES = htl_property_types();
$AM = htl_amenities();
$errors = [];
$editId = (int) ($_GET['edit'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $act = (string) ($_POST['action'] ?? '');
    $pid = (int) ($_POST['id'] ?? 0);
    try {
        if ($act === 'save') {
            $f = array_map(static fn($x) => is_string($x) ? trim(str_replace("\0", '', $x)) : $x, $_POST);
            $name = mb_substr((string) ($f['name'] ?? ''), 0, 150);
            $type = (string) ($f['property_type'] ?? 'hotel');
            $cin = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($f['check_in_time'] ?? '')) ? $f['check_in_time'] : '12:00';
            $cout = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($f['check_out_time'] ?? '')) ? $f['check_out_time'] : '11:00';
            $email = (string) ($f['email'] ?? '');
            $stars = (int) ($f['star_rating'] ?? 0);
            $am = array_values(array_intersect(array_keys($AM), (array) ($_POST['amenities'] ?? [])));
            if ($name === '') { $errors[] = 'Property name is required.'; }
            if (!isset($TYPES[$type])) { $errors[] = 'Choose a property type.'; }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Enter a valid e-mail address.'; }
            if ($stars < 0 || $stars > 5) { $errors[] = 'Star rating must be 0-5.'; }
            if (($f['phone'] ?? '') !== '' && !preg_match('/^[0-9+\-\s()]{7,25}$/', (string) ($f['phone'] ?? ''))) { $errors[] = 'Enter a valid phone number.'; }
            $img = null;
            if (!$errors) {
                $img = htl_save_image($_FILES['image'] ?? null);   // throws a user-safe RuntimeException
                $slugBase = htl_slug($name);
                $slug = $slugBase;
                for ($i = 2; ; $i++) {   // unique slug
                    $c = $pdo->prepare('SELECT COUNT(*) FROM hotel_properties WHERE slug = :s AND id <> :i');
                    $c->execute([':s' => $slug, ':i' => $pid]);
                    if ((int) $c->fetchColumn() === 0) { break; }
                    $slug = $slugBase . '-' . $i;
                }
                $par = [':n' => $name, ':t' => $type, ':c' => mb_substr((string) ($f['city'] ?? ''), 0, 100) ?: null, ':s' => mb_substr((string) ($f['state'] ?? ''), 0, 100) ?: null,
                    ':a' => mb_substr((string) ($f['address'] ?? ''), 0, 255) ?: null, ':pc' => mb_substr((string) ($f['pincode'] ?? ''), 0, 10) ?: null,
                    ':sd' => mb_substr((string) ($f['short_desc'] ?? ''), 0, 255) ?: null, ':d' => mb_substr((string) ($f['description'] ?? ''), 0, 5000) ?: null,
                    ':ph' => mb_substr((string) ($f['phone'] ?? ''), 0, 30) ?: null, ':em' => $email ?: null, ':st' => $stars ?: null, ':ci' => $cin, ':co' => $cout,
                    ':am' => implode(',', $am) ?: null, ':po' => mb_substr((string) ($f['policies'] ?? ''), 0, 3000) ?: null, ':so' => (int) ($f['sort_order'] ?? 0)];
                if ($pid > 0) {
                    $oldImg = $pdo->prepare('SELECT image FROM hotel_properties WHERE id = :i AND deleted_at IS NULL');
                    $oldImg->execute([':i' => $pid]);
                    $old = $oldImg->fetchColumn();
                    $pdo->prepare('UPDATE hotel_properties SET name=:n, slug=:sl, property_type=:t, city=:c, state=:s, address=:a, pincode=:pc, short_desc=:sd, description=:d, phone=:ph, email=:em,
                        star_rating=:st, check_in_time=:ci, check_out_time=:co, amenities=:am, policies=:po, sort_order=:so' . ($img ? ', image=:img' : '') . ', updated_at=NOW() WHERE id=:id AND deleted_at IS NULL')
                        ->execute($par + [':sl' => $slug, ':id' => $pid] + ($img ? [':img' => $img] : []));
                    if ($img && $old) { htl_delete_image((string) $old); }
                } else {
                    $pdo->prepare('INSERT INTO hotel_properties (name, slug, property_type, city, state, address, pincode, short_desc, description, phone, email, star_rating, check_in_time, check_out_time, amenities, policies, sort_order, image, is_active, created_at, updated_at)
                        VALUES (:n,:sl,:t,:c,:s,:a,:pc,:sd,:d,:ph,:em,:st,:ci,:co,:am,:po,:so,:img,0,NOW(),NOW())')->execute($par + [':sl' => $slug, ':img' => $img]);
                    $pid = (int) $pdo->lastInsertId();
                }
                log_activity($adminId, 'hotel_property_saved', $name);
                $_SESSION['flash_success_page'] = 'Property saved. Next: add its room types.';
                header('Location: hotel_rooms.php?property=' . $pid);
                exit;
            }
            $editId = $pid;
        } elseif ($act === 'toggle' && $pid > 0) {
            $row = $pdo->prepare('SELECT is_active FROM hotel_properties WHERE id = :i AND deleted_at IS NULL');
            $row->execute([':i' => $pid]);
            $cur = $row->fetchColumn();
            if ($cur === false) { throw new RuntimeException('Property not found.'); }
            if (!(int) $cur) {
                $ok = $pdo->prepare('SELECT COUNT(*) FROM hotel_room_types WHERE property_id = :i AND is_active = 1 AND deleted_at IS NULL AND base_rate > 0 AND total_rooms > 0');
                $ok->execute([':i' => $pid]);
                if ((int) $ok->fetchColumn() === 0) { throw new RuntimeException('Add at least one active room type with a rate before showing this property on the website.'); }
            }
            $pdo->prepare('UPDATE hotel_properties SET is_active = :a, updated_at = NOW() WHERE id = :i')->execute([':a' => $cur ? 0 : 1, ':i' => $pid]);
            $_SESSION['flash_success_page'] = $cur ? 'Property hidden from the website.' : 'Property is now live on the website.';
            header('Location: hotel_properties.php');
            exit;
        } elseif ($act === 'delete' && $pid > 0) {
            $u = $pdo->prepare("SELECT COUNT(*) FROM hotel_reservations WHERE property_id = :i AND deleted_at IS NULL AND status IN ('pending','confirmed','checked_in') AND check_out >= CURDATE()");
            $u->execute([':i' => $pid]);
            if ((int) $u->fetchColumn() > 0) { throw new RuntimeException('This property has current or upcoming reservations. Cancel or complete them first.'); }
            $pdo->prepare('UPDATE hotel_properties SET deleted_at = NOW(), is_active = 0 WHERE id = :i')->execute([':i' => $pid]);
            log_activity($adminId, 'hotel_property_deleted', "id={$pid}");
            $_SESSION['flash_success_page'] = 'Property removed.';
            header('Location: hotel_properties.php');
            exit;
        }
    } catch (RuntimeException $e) {
        $errors[] = $e->getMessage();
        $editId = $pid;
    } catch (Throwable $e) {
        error_log('hotel_properties: ' . $e->getMessage());
        $errors[] = 'Could not save. Has hotel_install.php been run?';
    }
}

$props = $pdo->query("SELECT p.*, (SELECT COUNT(*) FROM hotel_room_types r WHERE r.property_id = p.id AND r.deleted_at IS NULL) rooms_types,
        (SELECT COALESCE(SUM(total_rooms),0) FROM hotel_room_types r WHERE r.property_id = p.id AND r.deleted_at IS NULL AND r.is_active = 1) rooms_total,
        (SELECT MIN(base_rate) FROM hotel_room_types r WHERE r.property_id = p.id AND r.deleted_at IS NULL AND r.is_active = 1 AND base_rate > 0) from_rate
        FROM hotel_properties p WHERE p.deleted_at IS NULL ORDER BY p.sort_order, p.name")->fetchAll();
$edit = null;
foreach ($props as $p) { if ((int) $p['id'] === $editId) { $edit = $p; } }
$adding = isset($_GET['add']) || $edit || $errors;
$pv = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($edit ?? []);
$v = static fn(string $k, $d = '') => e((string) ($pv[$k] ?? $d));
$selAm = $_SERVER['REQUEST_METHOD'] === 'POST' ? (array) ($_POST['amenities'] ?? []) : array_filter(explode(',', (string) ($edit['amenities'] ?? '')));

tl_shell_top('Properties', 'Properties', ['Hotels' => 'hotel_dashboard.php'], '<a class="btn btn-primary" href="hotel_properties.php?add=1"><i class="fa-solid fa-plus"></i> Add property</a>');
?>
<?php if ($errors): ?><div class="tx-flash err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>

<?php if ($adding): ?>
<form method="post" enctype="multipart/form-data" class="panel"><?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? ($_POST['id'] ?? 0)) ?>">
  <div class="panel-head"><h3><i class="fa-solid fa-hotel"></i> <?= $edit ? 'Edit property' : 'Add property' ?></h3></div>
  <div class="tx-form">
    <div><label>Name *</label><input name="name" required maxlength="150" value="<?= $v('name') ?>"></div>
    <div><label>Type</label><select name="property_type"><?php foreach ($TYPES as $k => $l): ?><option value="<?= e($k) ?>" <?= ($pv['property_type'] ?? 'hotel') === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label>City / town</label><input name="city" maxlength="100" value="<?= $v('city') ?>"></div>
    <div><label>State</label><input name="state" maxlength="100" value="<?= $v('state', 'Assam') ?>"></div>
    <div class="full"><label>Address</label><input name="address" maxlength="255" value="<?= $v('address') ?>"></div>
    <div><label>PIN code</label><input name="pincode" maxlength="10" value="<?= $v('pincode') ?>"></div>
    <div><label>Property phone</label><input name="phone" maxlength="30" value="<?= $v('phone') ?>"></div>
    <div><label>Property e-mail</label><input type="email" name="email" maxlength="150" value="<?= $v('email') ?>"></div>
    <div><label>Star rating (0 = none)</label><input type="number" name="star_rating" min="0" max="5" value="<?= $v('star_rating', '0') ?>"></div>
    <div><label>Check-in time</label><input type="time" name="check_in_time" value="<?= $v('check_in_time', '12:00') ?>"></div>
    <div><label>Check-out time</label><input type="time" name="check_out_time" value="<?= $v('check_out_time', '11:00') ?>"></div>
    <div><label>Display order</label><input type="number" name="sort_order" value="<?= $v('sort_order', '0') ?>"></div>
    <div class="full"><label>Short description (shown on cards)</label><input name="short_desc" maxlength="255" value="<?= $v('short_desc') ?>"></div>
    <div class="full"><label>Full description</label><textarea name="description" rows="5" maxlength="5000"><?= $v('description') ?></textarea></div>
    <div class="full"><label>Photo (JPG / PNG / WebP, max 4 MB)</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp">
      <?php if (!empty($edit['image'])): ?><div style="margin-top:8px"><img src="../<?= e(htl_image_url($edit['image'])) ?>" alt="" style="height:70px;border-radius:8px"> <small class="muted">current photo - choose a file only to replace it</small></div><?php endif; ?></div>
    <div class="full"><label>Amenities</label><div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:6px 14px">
      <?php foreach ($AM as $k => [$l, $ic]): ?><label style="display:flex;gap:7px;align-items:center;text-transform:none;letter-spacing:0;font-size:.88rem;margin:0"><input type="checkbox" name="amenities[]" value="<?= e($k) ?>" style="width:auto" <?= in_array($k, $selAm, true) ? 'checked' : '' ?>><i class="fa-solid <?= e($ic) ?>" style="width:16px;color:#198754"></i> <?= e($l) ?></label><?php endforeach; ?></div></div>
    <div class="full"><label>House rules / policies (shown to guests)</label><textarea name="policies" rows="3" maxlength="3000"><?= $v('policies') ?></textarea></div>
  </div>
  <div style="margin-top:16px"><button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save property</button> <a class="btn btn-ghost" href="hotel_properties.php">Cancel</a></div>
</form>
<?php endif; ?>

<div class="panel">
<?php if (!$props): ?><p style="text-align:center;color:#6b7a72;padding:34px">No properties yet. Add your first hotel or homestay. The website keeps showing &ldquo;coming soon&rdquo; until one is live.</p><?php else: ?>
  <div class="tx-scroll"><table class="tx-table"><thead><tr><th>Property</th><th>Location</th><th class="r">Room types</th><th class="r">Rooms</th><th class="r">From / night</th><th>Website</th><th></th></tr></thead><tbody>
  <?php foreach ($props as $p): ?><tr>
    <td style="display:flex;gap:10px;align-items:center"><img src="../<?= e(htl_image_url($p['image'])) ?>" alt="" style="width:54px;height:40px;object-fit:cover;border-radius:6px"><div><strong><?= e($p['name']) ?></strong><br><small class="muted"><?= e($TYPES[$p['property_type']] ?? $p['property_type']) ?></small></div></td>
    <td><?= e(trim(($p['city'] ?? '') . ', ' . ($p['state'] ?? ''), ' ,')) ?: '-' ?></td><td class="r"><?= (int) $p['rooms_types'] ?></td><td class="r"><?= (int) $p['rooms_total'] ?></td>
    <td class="r"><?= $p['from_rate'] ? e(tl_inr($p['from_rate'])) : '<span class="muted">-</span>' ?></td>
    <td><?= $p['is_active'] ? tl_badge('Live', 'success') : tl_badge('Hidden', 'muted') ?></td>
    <td style="white-space:nowrap"><a class="btn btn-small btn-secondary" href="hotel_rooms.php?property=<?= (int) $p['id'] ?>"><i class="fa-solid fa-bed"></i> Rooms</a>
      <a class="btn btn-small btn-secondary" href="hotel_properties.php?edit=<?= (int) $p['id'] ?>"><i class="fa-solid fa-pen"></i></a>
      <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn btn-small btn-secondary" title="Show / hide on website"><i class="fa-solid <?= $p['is_active'] ? 'fa-eye-slash' : 'fa-eye' ?>"></i></button></form>
      <form method="post" style="display:inline" onsubmit="return confirm('Remove this property?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button class="btn btn-small btn-danger"><i class="fa-solid fa-trash"></i></button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
<?php endif; ?>
</div>
<?php tl_shell_bottom();
