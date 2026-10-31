<?php
/**
 * admin/bamboo_reports.php - sales, products, customers, GST summary, receivables ageing,
 * enquiry conversion, stock valuation. Invoice-date based. CSV export for each table.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bamboo_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$from = bb_valid_date((string) ($_GET['from'] ?? '')) ?? date('Y-m-01');
$to   = bb_valid_date((string) ($_GET['to'] ?? '')) ?? date('Y-m-t');
if ($to < $from) { [$from, $to] = [$to, $from]; }
$P = [':f' => $from, ':t' => $to];
$inv = "o.deleted_at IS NULL AND o.status IN ('dispatched','delivered') AND o.invoice_date BETWEEN :f AND :t";
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); };

$sum = $q("SELECT COUNT(*) n, COALESCE(SUM(o.grand_total),0) grand, COALESCE(SUM(o.grand_total - o.gst_amount),0) taxable, COALESCE(SUM(o.gst_amount),0) gst,
                  COALESCE(AVG(o.grand_total),0) avgv, COALESCE(SUM(o.balance_amount),0) due FROM bamboo_orders o WHERE $inv", $P)[0];
$coll = (float) $q("SELECT COALESCE(SUM(CASE WHEN entry_type='refund' THEN -amount ELSE amount END),0) c FROM bamboo_payments WHERE DATE(payment_date) BETWEEN :f AND :t", $P)[0]['c'];

$tables = [
    'monthly' => ['Sales by month', ['Month', 'Invoices', 'Taxable value', 'GST', 'Total'],
        $q("SELECT DATE_FORMAT(o.invoice_date,'%Y-%m') k, COUNT(*) n, SUM(o.grand_total - o.gst_amount) t, SUM(o.gst_amount) g, SUM(o.grand_total) s FROM bamboo_orders o WHERE $inv GROUP BY k ORDER BY k", $P), [2, 3, 4]],
    'products' => ['Sales by product', ['Product', 'Quantity', 'Revenue (before GST)', 'Avg rate', 'Est. margin'],
        $q("SELECT i.description k, SUM(i.quantity) qn, SUM(i.amount) rev, SUM(i.amount) / NULLIF(SUM(i.quantity),0) avgr,
                   CASE WHEN MAX(p.cost_price) > 0 THEN SUM(i.amount) - SUM(i.quantity * p.cost_price) END margin
            FROM bamboo_order_items i JOIN bamboo_orders o ON o.id = i.order_id LEFT JOIN bamboo_products p ON p.id = i.product_id
            WHERE $inv GROUP BY i.description ORDER BY rev DESC", $P), [2, 3, 4]],
    'customers' => ['Top customers', ['Customer', 'Invoices', 'Total billed', 'Balance due'],
        $q("SELECT COALESCE(NULLIF(MAX(o.company_name),''), MAX(o.customer_name)) k, COUNT(*) n, SUM(o.grand_total) s, SUM(GREATEST(o.balance_amount,0)) d FROM bamboo_orders o WHERE $inv
            GROUP BY RIGHT(REPLACE(REPLACE(REPLACE(o.phone,' ',''),'-',''),'+',''),10) ORDER BY s DESC LIMIT 15", $P), [2, 3]],
];

// GST summary: by rate, split into CGST/SGST (own state) and IGST
$gstRows = [];
foreach ($q("SELECT o.gst_pct, o.gst_amount, o.grand_total, o.delivery_state FROM bamboo_orders o WHERE $inv", $P) as $o) {
    $k = bb_num($o['gst_pct']) . '%';
    $gstRows[$k] ??= ['k' => $k, 'taxable' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'igst' => 0.0, 'total' => 0.0];
    $sp = bb_tax_split($o);
    $gstRows[$k]['taxable'] += (float) $o['grand_total'] - (float) $o['gst_amount'];
    $gstRows[$k]['cgst'] += $sp['cgst']; $gstRows[$k]['sgst'] += $sp['sgst']; $gstRows[$k]['igst'] += $sp['igst'];
    $gstRows[$k]['total'] += (float) $o['gst_amount'];
}
ksort($gstRows);
$tables['gst'] = ['GST summary (output tax)', ['GST rate', 'Taxable value', 'CGST', 'SGST', 'IGST', 'Total GST'], array_values($gstRows), [1, 2, 3, 4, 5]];

// receivables ageing (as of today, all dates)
$ageing = ['Not yet due' => 0.0, '1-30 days' => 0.0, '31-60 days' => 0.0, '61-90 days' => 0.0, '90+ days' => 0.0];
$recv = $q("SELECT o.id, o.order_number, o.quote_number, o.customer_name, o.company_name, o.phone, o.invoice_number, o.invoice_date, o.due_date, o.balance_amount,
                   DATEDIFF(CURDATE(), COALESCE(o.due_date, o.invoice_date, DATE(o.created_at))) late
            FROM bamboo_orders o WHERE o.deleted_at IS NULL AND o.status NOT IN ('quotation','cancelled') AND o.balance_amount > 0.009 ORDER BY late DESC");
foreach ($recv as $r) {
    $d = (int) $r['late'];
    $b = $d <= 0 ? 'Not yet due' : ($d <= 30 ? '1-30 days' : ($d <= 60 ? '31-60 days' : ($d <= 90 ? '61-90 days' : '90+ days')));
    $ageing[$b] += (float) $r['balance_amount'];
}

$enq = array_fill_keys(array_keys(bb_enquiry_statuses()), 0);
foreach ($q('SELECT status, COUNT(*) c FROM bamboo_enquiries WHERE DATE(created_at) BETWEEN :f AND :t GROUP BY status', $P) as $r) { $enq[$r['status']] = (int) $r['c']; }
$enqTotal = array_sum($enq);
$decided = $enq['won'] + $enq['lost'];
$sent = (int) $q("SELECT COUNT(*) c FROM bamboo_orders o WHERE o.deleted_at IS NULL AND o.quote_sent_at IS NOT NULL AND DATE(o.quote_sent_at) BETWEEN :f AND :t", $P)[0]['c'];
$acc = (int) $q("SELECT COUNT(*) c FROM bamboo_orders o WHERE o.deleted_at IS NULL AND o.customer_response = 'accepted' AND DATE(o.responded_at) BETWEEN :f AND :t", $P)[0]['c'];
$stockVal = (float) $pdo->query('SELECT COALESCE(SUM(stock_qty * cost_price),0) FROM bamboo_products WHERE track_stock = 1')->fetchColumn();

$exp = (string) ($_GET['export'] ?? '');
if (isset($tables[$exp]) || $exp === 'receivables') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bamboo-' . $exp . '-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    if ($exp === 'receivables') {
        fputcsv($out, ['Order', 'Invoice', 'Customer', 'Mobile', 'Invoice date', 'Due date', 'Days late', 'Balance']);
        foreach ($recv as $r) { fputcsv($out, array_map('bb_csv_safe', [$r['order_number'] ?: $r['quote_number'], $r['invoice_number'], $r['company_name'] ?: $r['customer_name'], $r['phone'], $r['invoice_date'], $r['due_date'], max(0, (int) $r['late']), $r['balance_amount']])); }
    } else {
        fputcsv($out, $tables[$exp][1]);
        foreach ($tables[$exp][2] as $r) { fputcsv($out, array_map('bb_csv_safe', array_values($r))); }
    }
    fclose($out);
    exit;
}

tl_shell_top('Bamboo reports', 'Reports', ['Bamboo Trading' => 'bamboo_dashboard.php']);
?>
<form method="get" class="panel tx-filter" style="align-items:end">
  <div><label style="font-size:.72rem;color:#6b7a72;display:block">Invoice date from</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label style="font-size:.72rem;color:#6b7a72;display:block">to</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
  <a class="btn btn-ghost" href="?from=<?= e(date('Y-m-01')) ?>&amp;to=<?= e(date('Y-m-t')) ?>">This month</a>
  <a class="btn btn-ghost" href="?from=<?= e(date('Y-m-d', strtotime('-90 days'))) ?>&amp;to=<?= e(date('Y-m-d')) ?>">Last 90 days</a>
  <a class="btn btn-ghost" href="?from=<?= e(date('Y-01-01')) ?>&amp;to=<?= e(date('Y-12-31')) ?>">This year</a>
</form>

<div class="tx-kpis">
  <div class="tx-kpi"><div class="l">Invoices</div><div class="v"><?= (int) $sum['n'] ?></div></div>
  <div class="tx-kpi"><div class="l">Taxable value</div><div class="v"><?= e(tl_inr($sum['taxable'])) ?></div></div>
  <div class="tx-kpi"><div class="l">GST charged</div><div class="v"><?= e(tl_inr($sum['gst'])) ?></div></div>
  <div class="tx-kpi good"><div class="l">Invoiced total</div><div class="v"><?= e(tl_inr($sum['grand'])) ?></div></div>
  <div class="tx-kpi good"><div class="l">Collected in period</div><div class="v"><?= e(tl_inr($coll)) ?></div></div>
  <div class="tx-kpi"><div class="l">Average invoice</div><div class="v"><?= e(tl_inr($sum['avgv'])) ?></div></div>
  <div class="tx-kpi"><div class="l">Stock value (cost)</div><div class="v"><?= e(tl_inr($stockVal)) ?></div></div>
</div>

<?php foreach ($tables as $key => [$title, $heads, $rows, $moneyCols]): ?>
<div class="panel">
  <div class="panel-head"><h3><i class="fa-solid fa-table"></i> <?= e($title) ?></h3><a class="btn btn-small btn-secondary" href="?<?= e(http_build_query(['from' => $from, 'to' => $to, 'export' => $key])) ?>"><i class="fa-solid fa-file-csv"></i> CSV</a></div>
  <?php if (!$rows): ?><p class="muted" style="margin:0">No invoices in this period.</p><?php else: ?>
  <div class="tx-scroll"><table class="tx-table"><thead><tr><?php foreach ($heads as $i => $h): ?><th class="<?= $i > 0 ? 'r' : '' ?>"><?= e($h) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($rows as $r): $v = array_values($r); ?><tr><td><?= e((string) $v[0]) ?></td>
    <?php for ($i = 1; $i < count($heads); $i++): ?><td class="r"><?= in_array($i, $moneyCols, true) ? ($v[$i] === null ? '<span class="muted">-</span>' : e(tl_inr($v[$i]))) : e(bb_num($v[$i])) ?></td><?php endfor; ?></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
  <?php if ($key === 'gst'): ?><p class="muted" style="font-size:.8rem;margin-bottom:0">Split follows delivery state vs your state (<?= e((string) tl_settings()['company']['state']) ?>). Share this with your CA - it is a working summary, not a filed return.</p><?php endif; ?>
  <?php if ($key === 'products'): ?><p class="muted" style="font-size:.8rem;margin-bottom:0">Estimated margin uses each product's current cost price (set it under Products &amp; Stock).</p><?php endif; ?>
</div>
<?php endforeach; ?>

<div class="panel">
  <div class="panel-head"><h3><i class="fa-solid fa-hand-holding-dollar"></i> Receivables ageing (today)</h3><a class="btn btn-small btn-secondary" href="?<?= e(http_build_query(['from' => $from, 'to' => $to, 'export' => 'receivables'])) ?>"><i class="fa-solid fa-file-csv"></i> CSV</a></div>
  <div class="amount-strip" style="margin-bottom:14px"><?php foreach ($ageing as $l => $v): ?><div class="item <?= $l === 'Not yet due' ? '' : ($v > 0 ? 'due' : '') ?>"><div class="label"><?= e($l) ?></div><div class="value"><?= e(tl_inr($v)) ?></div></div><?php endforeach; ?></div>
  <?php if ($recv): ?><div class="tx-scroll"><table class="tx-table"><thead><tr><th>Order</th><th>Customer</th><th>Due</th><th class="r">Days late</th><th class="r">Balance</th><th></th></tr></thead><tbody>
  <?php foreach (array_slice($recv, 0, 25) as $r): ?><tr><td><a href="bamboo_order_view.php?id=<?= (int) $r['id'] ?>"><?= e($r['order_number'] ?: $r['quote_number']) ?></a></td><td><?= e($r['company_name'] ?: $r['customer_name']) ?></td>
    <td><?= $r['due_date'] ? e(date('d M Y', strtotime((string) $r['due_date']))) : '-' ?></td><td class="r <?= (int) $r['late'] > 0 ? 'tx-exp-bad' : '' ?>"><?= max(0, (int) $r['late']) ?></td><td class="r"><?= e(tl_inr($r['balance_amount'])) ?></td>
    <td><a class="btn btn-small btn-secondary" target="_blank" rel="noopener" href="<?= e(bb_wa_link($r['phone'], 'Hello ' . $r['customer_name'] . ', a balance of ' . tl_inr($r['balance_amount']) . ' is pending for ' . ($r['invoice_number'] ?: ($r['order_number'] ?: $r['quote_number'])) . '. Thank you - ' . tl_settings()['company']['name'])) ?>"><i class="fa-brands fa-whatsapp"></i> Remind</a></td></tr><?php endforeach; ?>
  </tbody></table></div><?php else: ?><p class="muted" style="margin:0">Nothing outstanding.</p><?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head"><h3><i class="fa-solid fa-filter"></i> Enquiry conversion (enquiries received in period)</h3></div>
  <div class="amount-strip"><div class="item"><div class="label">Enquiries</div><div class="value"><?= $enqTotal ?></div></div>
    <div class="item ok"><div class="label">Won</div><div class="value"><?= $enq['won'] ?></div></div><div class="item due"><div class="label">Lost</div><div class="value"><?= $enq['lost'] ?></div></div>
    <div class="item"><div class="label">Win rate</div><div class="value"><?= $decided > 0 ? round($enq['won'] / $decided * 100) : 0 ?>%</div></div>
    <div class="item"><div class="label">Quotes sent</div><div class="value"><?= $sent ?></div></div><div class="item ok"><div class="label">Accepted online</div><div class="value"><?= $acc ?></div></div></div>
</div>
<?php tl_shell_bottom();
