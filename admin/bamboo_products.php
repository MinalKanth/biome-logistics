<?php
/**
 * admin/bamboo_products.php - Products, prices and stock.
 * Prices here pre-fill quotations. Turn on "track stock" for a product and its
 * stock is reduced automatically when an order is marked Dispatched (and restored if cancelled).
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bamboo_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$errors = [];
$units = bb_units();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $action = (string) ($_POST['action'] ?? '');
    $rid = (int) ($_POST['rid'] ?? 0);
    try {
        if ($action === 'save') {
            $name = mb_substr(clean_input((string) ($_POST['name'] ?? '')), 0, 150);
            $cat  = mb_substr(clean_input((string) ($_POST['category'] ?? '')), 0, 80);
            $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 1000);
            $hsn  = preg_replace('/[^0-9]/', '', (string) ($_POST['hsn'] ?? '')) ?? '';
            $unit = array_key_exists((string) ($_POST['unit'] ?? ''), $units) ? (string) $_POST['unit'] : 'piece';
            $num  = static fn(string $k): float => (isset($_POST[$k]) && is_numeric($_POST[$k]) && (float) $_POST[$k] >= 0) ? (float) $_POST[$k] : 0.0;
            $track = isset($_POST['track_stock']) ? 1 : 0;
            $active = isset($_POST['is_active']) ? 1 : 0;
            if ($name === '') {
                $errors[] = 'Product name is required.';
            }
            if (!$errors) {
                $p = [':n' => $name, ':c' => $cat ?: null, ':d' => $desc ?: null, ':h' => substr($hsn, 0, 12) ?: null, ':u' => $unit,
                      ':pr' => $num('price'), ':mo' => $num('min_order'), ':st' => $num('stock_qty'), ':la' => $num('low_stock_alert'), ':t' => $track, ':a' => $active];
                if ($rid > 0) {
                    $pdo->prepare('UPDATE bamboo_products SET name=:n, category=:c, description=:d, hsn=:h, unit=:u, price=:pr, min_order=:mo, stock_qty=:st, low_stock_alert=:la, track_stock=:t, is_active=:a, updated_at=NOW() WHERE id=:id')
                        ->execute($p + [':id' => $rid]);
                } else {
                    $pdo->prepare('INSERT INTO bamboo_products (name, category, description, hsn, unit, price, min_order, stock_qty, low_stock_alert, track_stock, is_active, sort_order, created_at, updated_at) VALUES (:n,:c,:d,:h,:u,:pr,:mo,:st,:la,:t,:a,99,NOW(),NOW())')
                        ->execute($p);
                }
                log_activity((int) $_SESSION['admin_id'], 'bamboo_product_saved', $name);
                $_SESSION['flash_success_page'] = 'Product saved.';
            }
        } elseif ($action === 'adjust' && $rid > 0) {
            $delta = (isset($_POST['delta']) && is_numeric($_POST['delta'])) ? (float) $_POST['delta'] : 0.0;
            if ($delta != 0.0) {
                $pdo->prepare('UPDATE bamboo_products SET stock_qty = GREATEST(0, stock_qty + :d), track_stock = 1, updated_at = NOW() WHERE id = :id')->execute([':d' => $delta, ':id' => $rid]);
                log_activity((int) $_SESSION['admin_id'], 'bamboo_stock_adjusted', "product={$rid} delta={$delta}");
                $_SESSION['flash_success_page'] = 'Stock updated.';
            }
        }
    } catch (Throwable $e) {
        error_log('bamboo products: ' . $e->getMessage());
        $errors[] = 'Something went wrong while saving.';
    }
    if (!$errors) {
        header('Location: bamboo_products.php');
        exit;
    }
}

$edit = null;
if (($eid = (int) ($_GET['edit'] ?? 0)) > 0) {
    $s = $pdo->prepare('SELECT * FROM bamboo_products WHERE id = :id');
    $s->execute([':id' => $eid]);
    $edit = $s->fetch() ?: null;
}
$products = [];
try {
    $products = $pdo->query('SELECT * FROM bamboo_products ORDER BY is_active DESC, sort_order, name')->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'Run admin/bamboo_install.php once to create the product tables.';
}
$low = 0;
foreach ($products as $p) {
    if ($p['track_stock'] && (float) $p['stock_qty'] <= (float) $p['low_stock_alert']) {
        $low++;
    }
}

tl_shell_top('Products & Stock', 'Products & Stock', ['Bamboo Trading' => 'bamboo_orders.php']);
?>
<?php if ($errors): ?><div class="tx-flash err"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($low): ?><div class="tx-flash err" style="background:#fff8e1;border-color:#ffe08a;color:#7a5b00"><i class="fa-solid fa-triangle-exclamation"></i> <?= $low ?> product(s) at or below the low-stock level.</div><?php endif; ?>

<div class="panel">
  <div class="panel-head"><h3><i class="fa-solid fa-plus"></i> <?= $edit ? 'Edit product' : 'Add product' ?></h3><?php if ($edit): ?><a class="btn btn-small btn-ghost" href="bamboo_products.php">Cancel</a><?php endif; ?></div>
  <form method="post" class="tx-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="rid" value="<?= (int) ($edit['id'] ?? 0) ?>">
    <div><label>Name *</label><input name="name" maxlength="150" required value="<?= e($edit['name'] ?? '') ?>"></div>
    <div><label>Category</label><input name="category" maxlength="80" placeholder="Raw / Finished" value="<?= e($edit['category'] ?? '') ?>"></div>
    <div><label>Unit</label><select name="unit"><?php foreach ($units as $k => $l): ?><option value="<?= e($k) ?>"<?= ($edit['unit'] ?? 'piece') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div><label>Selling price / unit (₹)</label><input name="price" type="number" step="0.01" min="0" value="<?= e($edit['price'] ?? '0') ?>"></div>
    <div><label>HSN</label><input name="hsn" maxlength="12" value="<?= e($edit['hsn'] ?? bb_settings()['hsn']) ?>"></div>
    <div><label>Minimum order</label><input name="min_order" type="number" step="0.01" min="0" value="<?= e($edit['min_order'] ?? '0') ?>"></div>
    <div><label>Stock in hand</label><input name="stock_qty" type="number" step="0.01" min="0" value="<?= e($edit['stock_qty'] ?? '0') ?>"></div>
    <div><label>Low-stock alert at</label><input name="low_stock_alert" type="number" step="0.01" min="0" value="<?= e($edit['low_stock_alert'] ?? '0') ?>"></div>
    <div class="full"><label>Description</label><textarea name="description" rows="2" maxlength="1000"><?= e($edit['description'] ?? '') ?></textarea></div>
    <div class="full" style="display:flex;gap:22px;flex-wrap:wrap;align-items:center">
      <label style="text-transform:none;letter-spacing:0;display:flex;gap:8px;align-items:center;margin:0"><input type="checkbox" name="track_stock" value="1" style="width:auto"<?= !empty($edit['track_stock']) ? ' checked' : '' ?>> Track stock for this product</label>
      <label style="text-transform:none;letter-spacing:0;display:flex;gap:8px;align-items:center;margin:0"><input type="checkbox" name="is_active" value="1" style="width:auto"<?= (!$edit || !empty($edit['is_active'])) ? ' checked' : '' ?>> Active (available on quotations)</label>
      <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save product</button>
    </div>
  </form>
</div>

<div class="panel"><div class="tx-scroll"><table class="tx-table">
  <thead><tr><th>Product</th><th>Category</th><th>Unit</th><th class="r">Price (₹)</th><th class="r">Stock</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($products as $p): $isLow = $p['track_stock'] && (float) $p['stock_qty'] <= (float) $p['low_stock_alert']; ?>
    <tr>
      <td><strong><?= e($p['name']) ?></strong></td><td><?= e($p['category'] ?: '—') ?></td><td><?= e($units[$p['unit']] ?? $p['unit']) ?></td>
      <td class="r"><?= (float) $p['price'] > 0 ? e(number_format((float) $p['price'], 2)) : '<span class="muted">set price</span>' ?></td>
      <td class="r <?= $isLow ? 'tx-exp-bad' : '' ?>"><?= $p['track_stock'] ? e(bb_num($p['stock_qty'])) . ($isLow ? ' ⚠' : '') : '<span class="muted">not tracked</span>' ?></td>
      <td><?= tl_badge($p['is_active'] ? 'Active' : 'Hidden', $p['is_active'] ? 'success' : 'muted') ?></td>
      <td style="white-space:nowrap">
        <form method="post" style="display:inline-flex;gap:4px;align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="adjust"><input type="hidden" name="rid" value="<?= (int) $p['id'] ?>">
          <input name="delta" type="number" step="0.01" placeholder="+/- qty" style="width:90px;border:1px solid #d4e0d9;border-radius:8px;padding:6px 8px" aria-label="Stock change"><button class="btn btn-small btn-ghost" type="submit">Adjust</button></form>
        <a class="btn btn-small btn-secondary" href="?edit=<?= (int) $p['id'] ?>">Edit</a>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$products): ?><tr><td colspan="7" style="text-align:center;padding:30px;color:#6b7a72">No products yet.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php tl_shell_bottom(); ?>