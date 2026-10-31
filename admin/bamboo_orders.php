<?php
/**
 * admin/bamboo_orders.php - Bamboo orders/quotations list.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bamboo_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$OS = bb_order_statuses();
$fs = (string) ($_GET['s'] ?? '');
$fs = isset($OS[$fs]) ? $fs : '';
$q  = mb_substr(clean_input((string) ($_GET['q'] ?? '')), 0, 100);
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 20;

$where = ['deleted_at IS NULL'];
$par = [];
if ($fs !== '') { $where[] = 'status = :s'; $par[':s'] = $fs; }
if ($q !== '') {
    $where[] = bb_like(['quote_number', 'order_number', 'invoice_number', 'customer_name', 'company_name', 'phone'], $q, $par);
}
$pf = (string) ($_GET['pay'] ?? '');
if ($pf === 'unpaid' || $pf === 'partial' || $pf === 'paid') {
    $where[] = 'payment_status = :pf';
    $par[':pf'] = $pf;
} elseif ($pf === 'overdue') {
    $where[] = "status NOT IN ('quotation','cancelled') AND balance_amount > 0.009 AND due_date IS NOT NULL AND due_date < CURDATE()";
} else {
    $pf = '';
}
$df = bb_valid_date((string) ($_GET['from'] ?? ''));
$dt = bb_valid_date((string) ($_GET['to'] ?? ''));
if ($df) { $where[] = 'DATE(created_at) >= :df'; $par[':df'] = $df; }
if ($dt) { $where[] = 'DATE(created_at) <= :dt'; $par[':dt'] = $dt; }
$wsql = implode(' AND ', $where);

if (($_GET['export'] ?? '') === 'csv') {
    $ex = $pdo->prepare("SELECT quote_number, order_number, invoice_number, status, customer_name, company_name, phone, delivery_city, delivery_state,
        subtotal, discount, transport_charge, gst_pct, gst_amount, grand_total, paid_amount, balance_amount, payment_status, created_at FROM bamboo_orders WHERE $wsql ORDER BY id DESC");
    $ex->execute($par);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bamboo-orders-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $first = true;
    while ($r = $ex->fetch()) {
        if ($first) { fputcsv($out, array_keys($r)); $first = false; }
        fputcsv($out, array_map('bb_csv_safe', $r));
    }
    fclose($out);
    exit;
}

$scalar = function (string $sql, array $p = []) use ($pdo): float {
    try { $s = $pdo->prepare($sql); $s->execute($p); return (float) $s->fetchColumn(); }
    catch (Throwable $e) { return 0.0; }
};
$counts = [];
foreach (array_keys($OS) as $k) { $counts[$k] = (int) $scalar('SELECT COUNT(*) FROM bamboo_orders WHERE deleted_at IS NULL AND status = :s', [':s' => $k]); }
$kBilled = $scalar("SELECT COALESCE(SUM(grand_total),0) FROM bamboo_orders WHERE deleted_at IS NULL AND status NOT IN ('quotation','cancelled')");
$kOut    = $scalar("SELECT COALESCE(SUM(GREATEST(balance_amount,0)),0) FROM bamboo_orders WHERE deleted_at IS NULL AND status NOT IN ('quotation','cancelled')");
$kMonth  = $scalar("SELECT COALESCE(SUM(amount),0) FROM bamboo_payments WHERE payment_date >= DATE_FORMAT(NOW(), '%Y-%m-01')");

$total = (int) $scalar("SELECT COUNT(*) FROM bamboo_orders WHERE $wsql", $par);
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$rows = [];
try {
    $st = $pdo->prepare("SELECT * FROM bamboo_orders WHERE $wsql ORDER BY created_at DESC, id DESC LIMIT :l OFFSET :o");
    foreach ($par as $k => $v) { $st->bindValue($k, $v, PDO::PARAM_STR); }
    $st->bindValue(':l', $per, PDO::PARAM_INT); $st->bindValue(':o', ($page - 1) * $per, PDO::PARAM_INT);
    $st->execute(); $rows = $st->fetchAll();
} catch (Throwable $e) {
    error_log('bamboo orders list: ' . $e->getMessage());
    $_SESSION['flash_error_page'] = 'Run admin/bamboo_install.php once to create the bamboo tables.';
}
$qs = static fn(array $x): string => http_build_query(array_filter(array_merge(['s' => $fs, 'q' => $q, 'pay' => $pf, 'from' => $df, 'to' => $dt], $x), static fn($v) => $v !== '' && $v !== null));

tl_shell_top('Bamboo Orders', 'Bamboo Trading', [], '<a class="btn btn-secondary" href="?' . e($qs(['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i> Export CSV</a><a class="btn btn-primary" href="bamboo_order_form.php"><i class="fa-solid fa-file-circle-plus"></i> New quotation</a>');
?>
<div class="tx-kpis">
  <div class="tx-kpi"><div class="l">Confirmed &amp; billed</div><div class="v"><?= e(tl_inr($kBilled)) ?></div></div>
  <div class="tx-kpi warn"><div class="l">Outstanding</div><div class="v"><?= e(tl_inr($kOut)) ?></div></div>
  <div class="tx-kpi good"><div class="l">Received this month</div><div class="v"><?= e(tl_inr($kMonth)) ?></div></div>
  <div class="tx-kpi"><div class="l">Open quotations</div><div class="v"><?= $counts['quotation'] ?></div></div>
</div>
<div class="panel">
  <div class="tx-tabs">
    <a href="?<?= e($qs(['s' => '', 'page' => 1])) ?>" class="<?= $fs === '' ? 'on' : '' ?>">All</a>
    <?php foreach ($OS as $k => $m): ?><a href="?<?= e($qs(['s' => $k, 'page' => 1])) ?>" class="<?= $fs === $k ? 'on' : '' ?>"><?= e($m['label']) ?> (<?= $counts[$k] ?>)</a><?php endforeach; ?>
  </div>
  <form method="get" class="tx-filter"><input type="hidden" name="s" value="<?= e($fs) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search quote/order/invoice no., customer, phone" style="flex:1;min-width:240px">
    <select name="pay"><option value="">Any payment</option><?php foreach (['unpaid' => 'Unpaid', 'partial' => 'Part paid', 'paid' => 'Paid', 'overdue' => 'Overdue'] as $k => $l): ?><option value="<?= $k ?>"<?= $pf === $k ? ' selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <input type="date" name="from" value="<?= e((string) $df) ?>" title="Created from"><input type="date" name="to" value="<?= e((string) $dt) ?>" title="Created to">
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
  </form>
  <div class="tx-scroll"><table class="tx-table">
    <thead><tr><th>No.</th><th>Customer</th><th>Deliver to</th><th class="r">Total</th><th class="r">Balance</th><th>Status</th><th>Payment</th><th>Date</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="9" style="text-align:center;padding:34px;color:#6b7a72">No orders yet. Create a quotation from Bamboo Enquiries or the button above.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $sm = $OS[$r['status']] ?? $OS['quotation']; ?>
      <tr>
        <td><a href="bamboo_order_view.php?id=<?= (int) $r['id'] ?>"><strong><?= e($r['order_number'] ?: $r['quote_number']) ?></strong></a></td>
        <td><?= e($r['company_name'] ?: $r['customer_name']) ?></td>
        <td><?= e($r['delivery_city'] ?: '—') ?></td>
        <td class="r"><?= e(tl_inr($r['grand_total'])) ?></td>
        <td class="r" style="<?= (float) $r['balance_amount'] > 0.009 ? 'color:#b02a20;font-weight:700' : '' ?>"><?= e(tl_inr(max(0, (float) $r['balance_amount']))) ?></td>
        <td><?= tl_badge($sm['label'], $sm['class']) ?></td>
        <td><?= tl_payment_badge((string) $r['payment_status']) ?></td>
        <td><?= e(tl_dt($r['created_at'], 'd M Y')) ?></td>
        <td><a class="btn btn-small btn-secondary" href="bamboo_order_view.php?id=<?= (int) $r['id'] ?>">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
  <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:.86rem">
    <span class="muted">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> orders</span>
    <span><?php if ($page > 1): ?><a class="btn btn-small btn-ghost" href="?<?= e($qs(['page' => $page - 1])) ?>">← Prev</a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="btn btn-small btn-ghost" href="?<?= e($qs(['page' => $page + 1])) ?>">Next →</a><?php endif; ?></span>
  </div>
  <?php endif; ?>
</div>
<?php tl_shell_bottom(); ?>