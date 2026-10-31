<?php
/**
 * admin/bamboo_stock.php - stock ledger, purchases / damage / corrections, valuation, low-stock list.
 * Every stock change in the module (sales, reversals, edits) is a ledger line, so stock always adds up.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bamboo_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$adminId = (int) $_SESSION['admin_id'];
$REASONS = [
    'purchase'   => ['Purchase / stock in', 1],
    'return'     => ['Customer return', 1],
    'correction' => ['Stock count correction', 0],
    'damage'     => ['Damaged / wasted', -1],
    'sample'     => ['Sample / own use', -1],
];
$labels = ['opening' => 'Opening balance', 'sale' => 'Sale (dispatch)', 'sale_reversal' => 'Sale reversed'] + array_map(static fn($r) => $r[0], $REASONS);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    try {
        $pid = (int) ($_POST['product_id'] ?? 0);
        $reason = (string) ($_POST['reason'] ?? '');
        $qty = (isset($_POST['qty']) && is_numeric($_POST['qty'])) ? round((float) $_POST['qty'], 2) : 0.0;
        $dir = (string) ($_POST['direction'] ?? 'in');
        $party = mb_substr(clean_input((string) ($_POST['party'] ?? '')), 0, 150);
        $cost = (isset($_POST['unit_cost']) && is_numeric($_POST['unit_cost']) && (float) $_POST['unit_cost'] > 0) ? round((float) $_POST['unit_cost'], 2) : null;
        $note = mb_substr(clean_input((string) ($_POST['note'] ?? '')), 0, 255);
        if (!isset($REASONS[$reason])) { throw new RuntimeException('Choose a reason.'); }
        if ($qty <= 0 || $qty > 100000000) { throw new RuntimeException('Enter a quantity greater than zero.'); }
        $fixed = $REASONS[$reason][1];
        $sign = $fixed !== 0 ? $fixed : ($dir === 'out' ? -1 : 1);
        $pdo->beginTransaction();
        $pr = $pdo->prepare('SELECT stock_qty, cost_price FROM bamboo_products WHERE id = :id FOR UPDATE');
        $pr->execute([':id' => $pid]);
        $row = $pr->fetch();
        if (!$row) { throw new RuntimeException('Choose a product.'); }
        bb_stock_move($pdo, $pid, $sign * $qty, $reason, 'manual', null, $note ?: null, $adminId, $party ?: null, $cost);
        $upd = 'track_stock = 1';
        $par = [':id' => $pid];
        if ($reason === 'purchase' && $cost !== null) {
            // weighted-average cost
            $oldQty = max(0.0, (float) $row['stock_qty']);
            $avg = ($oldQty + $qty) > 0 ? round((($oldQty * (float) $row['cost_price']) + ($qty * $cost)) / ($oldQty + $qty), 2) : $cost;
            $upd .= ', cost_price = :cp';
            $par[':cp'] = $avg;
        }
        $pdo->prepare("UPDATE bamboo_products SET $upd WHERE id = :id")->execute($par);
        $pdo->commit();
        log_activity($adminId, 'bamboo_stock_entry', "product={$pid} {$reason} " . ($sign * $qty));
        $_SESSION['flash_success_page'] = 'Stock entry saved.';
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $_SESSION['flash_error_page'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('bamboo_stock: ' . $e->getMessage());
        $_SESSION['flash_error_page'] = 'Could not save the stock entry.';
    }
    header('Location: bamboo_stock.php');
    exit;
}

$fp = (int) ($_GET['product'] ?? 0);
$fr = (string) ($_GET['reason'] ?? '');
$fr = isset($labels[$fr]) ? $fr : '';
$from = bb_valid_date((string) ($_GET['from'] ?? ''));
$to = bb_valid_date((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 30;
$where = ['1=1'];
$par = [];
if ($fp > 0) { $where[] = 'l.product_id = :p'; $par[':p'] = $fp; }
if ($fr !== '') { $where[] = 'l.reason = :r'; $par[':r'] = $fr; }
if ($from) { $where[] = 'DATE(l.created_at) >= :f'; $par[':f'] = $from; }
if ($to) { $where[] = 'DATE(l.created_at) <= :t'; $par[':t'] = $to; }
$wsql = implode(' AND ', $where);

if (($_GET['export'] ?? '') === 'csv') {
    $ex = $pdo->prepare("SELECT l.created_at, p.name product, l.reason, l.qty_change, l.balance_after, l.party, l.unit_cost, l.note FROM bamboo_stock_ledger l JOIN bamboo_products p ON p.id = l.product_id WHERE $wsql ORDER BY l.id DESC");
    $ex->execute($par);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bamboo-stock-ledger-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Product', 'Reason', 'Change', 'Balance', 'Supplier / party', 'Unit cost', 'Note']);
    while ($r = $ex->fetch(PDO::FETCH_NUM)) { fputcsv($out, array_map('bb_csv_safe', $r)); }
    fclose($out);
    exit;
}

$c = $pdo->prepare("SELECT COUNT(*) FROM bamboo_stock_ledger l WHERE $wsql");
$c->execute($par);
$total = (int) $c->fetchColumn();
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$st = $pdo->prepare("SELECT l.*, p.name, p.unit, a.full_name admin_name FROM bamboo_stock_ledger l JOIN bamboo_products p ON p.id = l.product_id LEFT JOIN admins a ON a.id = l.created_by WHERE $wsql ORDER BY l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($par);
$rows = $st->fetchAll();
$products = $pdo->query('SELECT * FROM bamboo_products ORDER BY is_active DESC, name')->fetchAll();
$val = 0.0; $low = [];
foreach ($products as $p) {
    if ($p['track_stock']) {
        $val += (float) $p['stock_qty'] * (float) $p['cost_price'];
        if ((float) $p['stock_qty'] <= (float) $p['low_stock_alert']) { $low[] = $p; }
    }
}
$qs = static fn(array $x = []) => http_build_query(array_filter(array_merge(['product' => $fp ?: '', 'reason' => $fr, 'from' => $from, 'to' => $to], $x), static fn($v) => $v !== '' && $v !== null));

tl_shell_top('Stock ledger', 'Stock ledger', ['Bamboo Trading' => 'bamboo_dashboard.php'],
    '<a class="btn btn-secondary" href="?' . e($qs(['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i> Export CSV</a><a class="btn btn-secondary" href="bamboo_products.php"><i class="fa-solid fa-boxes-stacked"></i> Products</a>');
?>
<div class="tx-kpis">
  <div class="tx-kpi"><div class="l">Tracked products</div><div class="v"><?= count(array_filter($products, static fn($p) => $p['track_stock'])) ?></div></div>
  <div class="tx-kpi <?= $low ? 'warn' : '' ?>"><div class="l">Low / out of stock</div><div class="v"><?= count($low) ?></div></div>
  <div class="tx-kpi good"><div class="l">Stock value (at cost)</div><div class="v"><?= e(tl_inr($val)) ?></div></div>
</div>
<?php if ($low): ?><div class="tx-flash err" style="background:#fff7e6;border-color:#f5dfae;color:#8a5a00"><i class="fa-solid fa-triangle-exclamation"></i> Low stock: <?= e(implode(', ', array_map(static fn($p) => $p['name'] . ' (' . bb_num($p['stock_qty']) . ')', $low))) ?></div><?php endif; ?>

<form method="post" class="panel"><?= csrf_field() ?>
  <div class="panel-head"><h3><i class="fa-solid fa-plus-minus"></i> Stock entry</h3></div>
  <div class="tx-form">
    <div><label>Product</label><select name="product_id" required><option value="">- choose -</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"><?= e($p['name']) ?> (<?= e(bb_num($p['stock_qty'])) ?> in stock)</option><?php endforeach; ?></select></div>
    <div><label>Reason</label><select name="reason" id="reason" required><?php foreach ($REASONS as $k => [$l]): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
    <div id="dirWrap" style="display:none"><label>Direction</label><select name="direction"><option value="in">Add to stock</option><option value="out">Remove from stock</option></select></div>
    <div><label>Quantity</label><input type="number" name="qty" step="0.01" min="0.01" required></div>
    <div><label>Supplier / party</label><input name="party" maxlength="150"></div>
    <div><label>Unit cost (&#8377;) <small style="text-transform:none">purchases only</small></label><input type="number" name="unit_cost" step="0.01" min="0"></div>
    <div class="full"><label>Note</label><input name="note" maxlength="255"></div>
    <div><button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save entry</button></div>
  </div>
</form>
<script>document.getElementById('reason').addEventListener('change',function(){document.getElementById('dirWrap').style.display=this.value==='correction'?'':'none';});</script>

<div class="panel">
  <div class="panel-head"><h3><i class="fa-solid fa-book"></i> Ledger</h3></div>
  <form method="get" class="tx-filter">
    <select name="product"><option value="">All products</option><?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>"<?= $fp === (int) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
    <select name="reason"><option value="">Any reason</option><?php foreach ($labels as $k => $l): ?><option value="<?= e($k) ?>"<?= $fr === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
    <input type="date" name="from" value="<?= e((string) $from) ?>"><input type="date" name="to" value="<?= e((string) $to) ?>">
    <button class="btn btn-primary"><i class="fa-solid fa-filter"></i> Filter</button>
  </form>
  <?php if (!$rows): ?><p style="text-align:center;color:#6b7a72;padding:24px">No stock movements yet.</p><?php else: ?>
  <div class="tx-scroll"><table class="tx-table"><thead><tr><th>Date</th><th>Product</th><th>Reason</th><th class="r">Change</th><th class="r">Balance</th><th>Party / note</th><th>By</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $neg = (float) $r['qty_change'] < 0; ?><tr>
    <td><?= e(tl_dt($r['created_at'], 'd M Y, h:i A')) ?></td><td><strong><?= e($r['name']) ?></strong></td><td><?= e($labels[$r['reason']] ?? $r['reason']) ?><?= $r['ref_type'] === 'order' && $r['ref_id'] ? ' <a href="bamboo_order_view.php?id=' . (int) $r['ref_id'] . '">order</a>' : '' ?></td>
    <td class="r" style="font-weight:700;color:<?= $neg ? '#b02a20' : '#14663f' ?>"><?= $neg ? '' : '+' ?><?= e(bb_num($r['qty_change'])) ?></td><td class="r"><?= e(bb_num($r['balance_after'])) ?></td>
    <td><?= e(trim(($r['party'] ?? '') . ' ' . ($r['note'] ?? ''))) ?: '-' ?><?= $r['unit_cost'] !== null ? '<br><small class="muted">@ ' . e(tl_inr($r['unit_cost'])) . '</small>' : '' ?></td><td><?= e($r['admin_name'] ?: '-') ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($pages > 1): ?><div style="display:flex;gap:6px;margin-top:14px;align-items:center;flex-wrap:wrap"><span class="muted" style="margin-right:8px"><?= $total ?> entries</span>
    <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?><a class="btn btn-small <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>" href="?<?= e($qs(['page' => $i])) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
  <?php endif; ?>
</div>
<?php tl_shell_bottom();
