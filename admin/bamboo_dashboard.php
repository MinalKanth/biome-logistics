<?php
/**
 * admin/bamboo_dashboard.php - what needs attention in Bamboo Trading today.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bamboo_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$one = static function (string $sql) use ($pdo) {
    try { return $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { error_log('bamboo_dashboard: ' . $e->getMessage()); return 0; }
};
$all = static function (string $sql) use ($pdo): array {
    try { return $pdo->query($sql)->fetchAll(); } catch (Throwable $e) { error_log('bamboo_dashboard: ' . $e->getMessage()); return []; }
};

if (!$one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'bamboo_stock_ledger'")) {
    tl_shell_top('Bamboo dashboard', 'Bamboo Trading', []);
    echo '<div class="panel"><h3>One-time setup needed</h3><p>Run the installer to add the new Bamboo tables (nothing existing is changed or removed).</p><a class="btn btn-primary" href="bamboo_install.php">Run installer</a></div>';
    tl_shell_bottom();
    exit;
}

$live = 'deleted_at IS NULL';
$active = "status NOT IN ('quotation','cancelled')";
$kNewEnq   = (int) $one("SELECT COUNT(*) FROM bamboo_enquiries WHERE status = 'new'");
$kFollow   = (int) $one("SELECT COUNT(*) FROM bamboo_enquiries WHERE follow_up_date IS NOT NULL AND follow_up_date <= CURDATE() AND status NOT IN ('won','lost')");
$kQuotes   = (int) $one("SELECT COUNT(*) FROM bamboo_orders WHERE $live AND status = 'quotation'");
$kQuoteVal = (float) $one("SELECT COALESCE(SUM(grand_total),0) FROM bamboo_orders WHERE $live AND status = 'quotation'");
$kExpiring = (int) $one("SELECT COUNT(*) FROM bamboo_orders WHERE $live AND status = 'quotation' AND valid_until IS NOT NULL AND valid_until BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
$kExpired  = (int) $one("SELECT COUNT(*) FROM bamboo_orders WHERE $live AND status = 'quotation' AND valid_until IS NOT NULL AND valid_until < CURDATE()");
$kToShip   = (int) $one("SELECT COUNT(*) FROM bamboo_orders WHERE $live AND status IN ('confirmed','processing')");
$kInTransit = (int) $one("SELECT COUNT(*) FROM bamboo_orders WHERE $live AND status = 'dispatched'");
$kRecv     = (float) $one("SELECT COALESCE(SUM(balance_amount),0) FROM bamboo_orders WHERE $live AND $active AND balance_amount > 0");
$kOverdue  = (float) $one("SELECT COALESCE(SUM(balance_amount),0) FROM bamboo_orders WHERE $live AND $active AND balance_amount > 0 AND due_date IS NOT NULL AND due_date < CURDATE()");
$kSales    = (float) $one("SELECT COALESCE(SUM(grand_total),0) FROM bamboo_orders WHERE $live AND $active AND status IN ('dispatched','delivered') AND invoice_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')");
$kColl     = (float) $one("SELECT COALESCE(SUM(CASE WHEN entry_type='refund' THEN -amount ELSE amount END),0) FROM bamboo_payments WHERE payment_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')");
$lowRows   = $all("SELECT name, stock_qty FROM bamboo_products WHERE track_stock = 1 AND is_active = 1 AND stock_qty <= low_stock_alert ORDER BY stock_qty LIMIT 6");

$alerts = [];
if ($kNewEnq)   { $alerts[] = ['warning', 'fa-inbox', "$kNewEnq new enquir" . ($kNewEnq > 1 ? 'ies' : 'y') . ' waiting for a first reply', 'bamboo_enquiries.php?s=new']; }
if ($kFollow)   { $alerts[] = ['warning', 'fa-bell', "$kFollow follow-up(s) due", 'bamboo_enquiries.php?s=due']; }
if ($kOverdue > 0) { $alerts[] = ['danger', 'fa-hand-holding-dollar', tl_inr($kOverdue) . ' of payments is overdue', 'bamboo_orders.php?pay=overdue']; }
if ($kExpiring) { $alerts[] = ['warning', 'fa-hourglass-half', "$kExpiring quotation(s) expire within 3 days", 'bamboo_orders.php?s=quotation']; }
if ($lowRows)   { $alerts[] = ['danger', 'fa-boxes-stacked', count($lowRows) . ' product(s) at or below the low-stock level', 'bamboo_stock.php']; }
if (!(int) $one('SELECT COUNT(*) FROM bamboo_products WHERE price > 0 AND is_active = 1')) { $alerts[] = ['warning', 'fa-tag', 'No product has a selling price yet - set prices in Products & Stock', 'bamboo_products.php']; }

$trend = [];
for ($i = 5; $i >= 0; $i--) { $trend[date('Y-m', strtotime("first day of -$i month"))] = 0.0; }
foreach ($all("SELECT DATE_FORMAT(invoice_date,'%Y-%m') m, SUM(grand_total) t FROM bamboo_orders WHERE $live AND status IN ('dispatched','delivered') AND invoice_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 5 MONTH) GROUP BY m") as $r) {
    if (isset($trend[$r['m']])) { $trend[$r['m']] = (float) $r['t']; }
}
$max = max(1.0, max($trend));

$enqStat = array_fill_keys(array_keys(bb_enquiry_statuses()), 0);
foreach ($all('SELECT status, COUNT(*) c FROM bamboo_enquiries GROUP BY status') as $r) { $enqStat[$r['status']] = (int) $r['c']; }
$ordStat = array_fill_keys(array_keys(bb_order_statuses()), ['c' => 0, 'v' => 0.0]);
foreach ($all("SELECT status, COUNT(*) c, COALESCE(SUM(grand_total),0) v FROM bamboo_orders WHERE $live GROUP BY status") as $r) { $ordStat[$r['status']] = ['c' => (int) $r['c'], 'v' => (float) $r['v']]; }
$todo = $all("SELECT id, quote_number, order_number, customer_name, company_name, delivery_city, grand_total, status, expected_dispatch, balance_amount FROM bamboo_orders WHERE $live AND status IN ('confirmed','processing') ORDER BY expected_dispatch IS NULL, expected_dispatch, id LIMIT 6");
$newEnq = $all("SELECT id, reference, full_name, company_name, products_selected, created_at FROM bamboo_enquiries WHERE status = 'new' ORDER BY id DESC LIMIT 5");
$OS = bb_order_statuses();
$ES = bb_enquiry_statuses();

tl_shell_top('Bamboo dashboard', 'Bamboo Trading', [],
    '<a class="btn btn-secondary" href="bamboo_reports.php"><i class="fa-solid fa-chart-column"></i> Reports</a><a class="btn btn-primary" href="bamboo_order_form.php"><i class="fa-solid fa-file-circle-plus"></i> New quotation</a>');
?>
<?php foreach ($alerts as [$cls, $ico, $msg, $href]): ?>
  <a href="<?= e($href) ?>" class="tx-flash <?= $cls === 'danger' ? 'err' : 'ok' ?>" style="display:block;text-decoration:none;<?= $cls === 'warning' ? 'background:#fff7e6;border-color:#f5dfae;color:#8a5a00' : '' ?>"><i class="fa-solid <?= e($ico) ?>"></i> <?= e($msg) ?> <span style="float:right">View &rarr;</span></a>
<?php endforeach; ?>

<div class="tx-kpis">
  <div class="tx-kpi warn"><div class="l">New enquiries</div><div class="v"><?= $kNewEnq ?></div></div>
  <div class="tx-kpi"><div class="l">Open quotations</div><div class="v"><?= $kQuotes ?></div><div class="l" style="margin-top:4px"><?= e(tl_inr($kQuoteVal)) ?><?= $kExpired ? ' &middot; ' . $kExpired . ' expired' : '' ?></div></div>
  <div class="tx-kpi"><div class="l">To dispatch</div><div class="v"><?= $kToShip ?></div></div>
  <div class="tx-kpi"><div class="l">In transit</div><div class="v"><?= $kInTransit ?></div></div>
  <div class="tx-kpi"><div class="l">Invoiced this month</div><div class="v"><?= e(tl_inr($kSales)) ?></div></div>
  <div class="tx-kpi good"><div class="l">Collected this month</div><div class="v"><?= e(tl_inr($kColl)) ?></div></div>
  <div class="tx-kpi warn"><div class="l">Receivables</div><div class="v"><?= e(tl_inr($kRecv)) ?></div></div>
</div>

<div class="tx-grid">
  <div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-truck-ramp-box"></i> Orders to prepare &amp; dispatch</h3><a class="btn btn-small btn-secondary" href="bamboo_orders.php">All orders</a></div>
      <?php if (!$todo): ?><p class="muted" style="text-align:center;padding:18px;margin:0">Nothing waiting to be dispatched.</p><?php else: ?>
      <div class="tx-scroll"><table class="tx-table"><thead><tr><th>Order</th><th>Customer</th><th>City</th><th>Expected</th><th class="r">Total</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($todo as $r): $late = $r['expected_dispatch'] && $r['expected_dispatch'] < date('Y-m-d'); ?><tr>
        <td><a href="bamboo_order_view.php?id=<?= (int) $r['id'] ?>"><strong><?= e($r['order_number'] ?: $r['quote_number']) ?></strong></a></td>
        <td><?= e($r['company_name'] ?: $r['customer_name']) ?></td><td><?= e($r['delivery_city'] ?: '-') ?></td>
        <td class="<?= $late ? 'tx-exp-bad' : '' ?>"><?= $r['expected_dispatch'] ? e(date('d M', strtotime((string) $r['expected_dispatch']))) . ($late ? ' (late)' : '') : '<span class="muted">not set</span>' ?></td>
        <td class="r"><?= e(tl_inr($r['grand_total'])) ?></td><td><?= tl_badge($OS[$r['status']]['label'], $OS[$r['status']]['class']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-chart-column"></i> Invoiced - last 6 months</h3></div>
      <div style="display:flex;align-items:flex-end;gap:14px;height:170px;padding-top:10px">
      <?php foreach ($trend as $m => $t): ?><div style="flex:1;text-align:center;display:flex;flex-direction:column;justify-content:flex-end;height:100%">
        <div style="font-size:.7rem;color:#6b7a72;margin-bottom:4px"><?= $t > 0 ? e(tl_inr($t)) : '' ?></div>
        <div style="background:linear-gradient(180deg,#198754,#0f4c2d);border-radius:6px 6px 0 0;height:<?= max(3, (int) round($t / $max * 110)) ?>px"></div>
        <div style="font-size:.75rem;margin-top:6px;font-weight:600"><?= e(date('M', strtotime($m . '-01'))) ?></div></div><?php endforeach; ?></div>
    </div>
  </div>
  <div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-inbox"></i> New enquiries</h3><a class="btn btn-small btn-secondary" href="bamboo_enquiries.php">All</a></div>
      <?php if (!$newEnq): ?><p class="muted" style="margin:0">All caught up.</p><?php endif; ?>
      <?php foreach ($newEnq as $r): ?><a href="bamboo_enquiries.php?q=<?= e(rawurlencode((string) $r['reference'])) ?>" style="display:block;padding:10px 0;border-bottom:1px solid #eef3ef;text-decoration:none;color:inherit">
        <strong><?= e($r['full_name']) ?></strong><?= $r['company_name'] ? ' <small class="muted">' . e($r['company_name']) . '</small>' : '' ?><small class="muted" style="float:right"><?= e(tl_dt($r['created_at'], 'd M')) ?></small><br>
        <small class="muted"><?= e($r['products_selected']) ?></small></a><?php endforeach; ?>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-filter"></i> Pipeline</h3></div>
      <p class="muted" style="margin:0 0 6px;font-size:.78rem;text-transform:uppercase;letter-spacing:.06em">Enquiries</p>
      <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px"><?php foreach ($ES as $k => $m): ?><a href="bamboo_enquiries.php?s=<?= e($k) ?>"><?= tl_badge($m['label'] . ' ' . $enqStat[$k], $m['class']) ?></a><?php endforeach; ?></div>
      <p class="muted" style="margin:0 0 6px;font-size:.78rem;text-transform:uppercase;letter-spacing:.06em">Orders</p>
      <?php foreach ($OS as $k => $m): ?><div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dashed #e3ece7;font-size:.86rem"><a href="bamboo_orders.php?s=<?= e($k) ?>"><?= e($m['label']) ?></a><span><strong><?= $ordStat[$k]['c'] ?></strong> <span class="muted">&middot; <?= e(tl_inr($ordStat[$k]['v'])) ?></span></span></div><?php endforeach; ?>
    </div>
    <?php if ($lowRows): ?><div class="panel"><div class="panel-head"><h3><i class="fa-solid fa-triangle-exclamation"></i> Low stock</h3><a class="btn btn-small btn-secondary" href="bamboo_stock.php">Restock</a></div>
      <?php foreach ($lowRows as $r): ?><div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dashed #e3ece7;font-size:.86rem"><span><?= e($r['name']) ?></span><strong class="tx-exp-bad"><?= e(bb_num($r['stock_qty'])) ?></strong></div><?php endforeach; ?></div><?php endif; ?>
  </div>
</div>
<?php tl_shell_bottom();
