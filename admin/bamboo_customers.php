<?php
/**
 * admin/bamboo_customers.php - customer directory built from orders & enquiries (grouped by mobile number):
 * lifetime value, outstanding balance, last order, one-click payment reminder.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bamboo_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$q = mb_substr(clean_input((string) ($_GET['q'] ?? '')), 0, 100);
$only = (string) ($_GET['f'] ?? '') === 'due' ? 'due' : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 25;

// last 10 digits of the mobile number identify a customer
$key = "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),'(',''),10)";
$par = [];
$having = '';
$where = 'deleted_at IS NULL';
if ($q !== '') {
    $where .= ' AND ' . bb_like(['customer_name', 'company_name', 'phone', 'email'], $q, $par);
}
if ($only === 'due') {
    $having = 'HAVING outstanding > 0.009';
}
$sql = "SELECT $key AS k, MAX(customer_name) AS name, MAX(company_name) AS company, MAX(phone) AS phone, MAX(email) AS email, MAX(delivery_city) AS city,
               COUNT(*) AS orders,
               COALESCE(SUM(CASE WHEN status NOT IN ('quotation','cancelled') THEN grand_total END),0) AS billed,
               COALESCE(SUM(CASE WHEN status NOT IN ('quotation','cancelled') THEN paid_amount END),0) AS paid,
               COALESCE(SUM(CASE WHEN status NOT IN ('quotation','cancelled') THEN GREATEST(balance_amount,0) END),0) AS outstanding,
               MAX(created_at) AS last_order
        FROM bamboo_orders WHERE $where GROUP BY k $having";

if (($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare($sql . ' ORDER BY billed DESC');
    $st->execute($par);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bamboo-customers-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Name', 'Company', 'Mobile', 'Email', 'City', 'Orders', 'Billed', 'Paid', 'Outstanding', 'Last order']);
    while ($r = $st->fetch()) { fputcsv($out, array_map('bb_csv_safe', [$r['name'], $r['company'], $r['phone'], $r['email'], $r['city'], $r['orders'], $r['billed'], $r['paid'], $r['outstanding'], $r['last_order']])); }
    fclose($out);
    exit;
}

$cnt = $pdo->prepare("SELECT COUNT(*) FROM ($sql) t");
$cnt->execute($par);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$st = $pdo->prepare($sql . ' ORDER BY outstanding DESC, billed DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per));
$st->execute($par);
$rows = $st->fetchAll();
$tot = $pdo->query("SELECT COALESCE(SUM(CASE WHEN status NOT IN ('quotation','cancelled') THEN GREATEST(balance_amount,0) END),0) o, COUNT(DISTINCT $key) c FROM bamboo_orders WHERE deleted_at IS NULL")->fetch();
$qs = static fn(array $x = []) => http_build_query(array_filter(array_merge(['q' => $q, 'f' => $only], $x), static fn($v) => $v !== ''));

tl_shell_top('Customers', 'Customers', ['Bamboo Trading' => 'bamboo_dashboard.php'],
    '<a class="btn btn-secondary" href="?' . e($qs(['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i> Export CSV</a>');
?>
<div class="tx-kpis">
  <div class="tx-kpi"><div class="l">Customers</div><div class="v"><?= (int) $tot['c'] ?></div></div>
  <div class="tx-kpi warn"><div class="l">Total outstanding</div><div class="v"><?= e(tl_inr($tot['o'])) ?></div></div>
</div>
<div class="panel">
  <form method="get" class="tx-filter">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, company, mobile, e-mail" style="flex:1;min-width:220px">
    <label style="display:flex;gap:6px;align-items:center;font-size:.88rem"><input type="checkbox" name="f" value="due" <?= $only === 'due' ? 'checked' : '' ?> style="width:auto"> Only with balance due</label>
    <button class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
  </form>
  <?php if (!$rows): ?><p style="text-align:center;color:#6b7a72;padding:28px">No customers yet. They appear automatically when you create quotations.</p><?php else: ?>
  <div class="tx-scroll"><table class="tx-table"><thead><tr><th>Customer</th><th>Mobile</th><th>City</th><th class="r">Orders</th><th class="r">Billed</th><th class="r">Outstanding</th><th>Last order</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td><strong><?= e($r['name']) ?></strong><?= $r['company'] ? '<br><small class="muted">' . e($r['company']) . '</small>' : '' ?></td>
    <td><a href="tel:<?= e(preg_replace('/\D/', '', (string) $r['phone'])) ?>"><?= e($r['phone']) ?></a></td><td><?= e($r['city'] ?: '-') ?></td>
    <td class="r"><?= (int) $r['orders'] ?></td><td class="r"><?= e(tl_inr($r['billed'])) ?></td>
    <td class="r <?= (float) $r['outstanding'] > 0.009 ? 'tx-exp-bad' : '' ?>"><?= e(tl_inr($r['outstanding'])) ?></td><td><?= e(tl_dt($r['last_order'], 'd M Y')) ?></td>
    <td style="white-space:nowrap"><a class="btn btn-small btn-secondary" href="bamboo_orders.php?q=<?= e(rawurlencode((string) $r['phone'])) ?>">Orders</a>
      <?php if ((float) $r['outstanding'] > 0.009): ?><a class="btn btn-small btn-secondary" target="_blank" rel="noopener" href="<?= e(bb_wa_link($r['phone'], 'Hello ' . $r['name'] . ', a balance of ' . tl_inr($r['outstanding']) . ' is pending with ' . tl_settings()['company']['name'] . '. Kindly arrange the payment. Thank you.')) ?>"><i class="fa-brands fa-whatsapp"></i> Remind</a><?php endif; ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($pages > 1): ?><div style="display:flex;gap:6px;margin-top:14px;align-items:center;flex-wrap:wrap"><span class="muted" style="margin-right:8px"><?= $total ?> customers</span>
    <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?><a class="btn btn-small <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>" href="?<?= e($qs(['page' => $i])) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
  <?php endif; ?>
</div>
<?php tl_shell_bottom();
