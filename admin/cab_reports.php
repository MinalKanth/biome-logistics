<?php
/**
 * admin/cab_reports.php - performance, fleet utilisation, drivers, top customers,
 * outstanding balances. Filter by journey date. CSV export of any table.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/cab_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$from = cab_valid_date((string) ($_GET['from'] ?? '')) ?? date('Y-m-01');
$to   = cab_valid_date((string) ($_GET['to'] ?? '')) ?? date('Y-m-t');
if ($to < $from) { [$from, $to] = [$to, $from]; }
$P = [':f' => $from, ':t' => $to];
$bill = "b.status IN ('confirmed','assigned','on_trip','completed')";
$base = "b.deleted_at IS NULL AND b.journey_date BETWEEN :f AND :t";

$q = static function (string $sql, array $p = []) use ($pdo): array {
    $s = $pdo->prepare($sql);
    $s->execute($p);
    return $s->fetchAll();
};

$tables = [
    'by_service' => ['Revenue by service', ['Service', 'Bookings', 'Billed', 'Received'],
        $q("SELECT COALESCE(b.service_type,'Unspecified') k, COUNT(*) n, COALESCE(SUM(CASE WHEN $bill THEN b.total_fare END),0) billed, COALESCE(SUM(CASE WHEN $bill THEN b.paid_amount END),0) rec FROM cab_booking_requests b WHERE $base GROUP BY k ORDER BY billed DESC", $P)],
    'by_vehicle_type' => ['Revenue by vehicle category', ['Category', 'Bookings', 'Billed', 'Received'],
        $q("SELECT COALESCE(b.vehicle_type,'Unspecified') k, COUNT(*) n, COALESCE(SUM(CASE WHEN $bill THEN b.total_fare END),0) billed, COALESCE(SUM(CASE WHEN $bill THEN b.paid_amount END),0) rec FROM cab_booking_requests b WHERE $base GROUP BY k ORDER BY billed DESC", $P)],
    'fleet' => ['Fleet utilisation', ['Vehicle', 'Trips', 'Days on road', 'Billed'],
        $q("SELECT CONCAT(v.name, IF(v.reg_number IS NULL,'',CONCAT(' (',v.reg_number,')'))) k, COUNT(b.id) n, COALESCE(SUM(b.days),0) d, COALESCE(SUM(b.total_fare),0) billed
            FROM cab_vehicles v LEFT JOIN cab_booking_requests b ON b.vehicle_id = v.id AND $base AND b.status IN ('assigned','on_trip','completed')
            WHERE v.deleted_at IS NULL GROUP BY v.id ORDER BY billed DESC, v.name", $P)],
    'drivers' => ['Driver activity', ['Driver', 'Trips', 'Days on road', 'Billed'],
        $q("SELECT d.full_name k, COUNT(b.id) n, COALESCE(SUM(b.days),0) dd, COALESCE(SUM(b.total_fare),0) billed
            FROM cab_drivers d LEFT JOIN cab_booking_requests b ON b.driver_id = d.id AND $base AND b.status IN ('assigned','on_trip','completed')
            WHERE d.deleted_at IS NULL GROUP BY d.id ORDER BY n DESC, d.full_name", $P)],
    'customers' => ['Top customers', ['Customer', 'Bookings', 'Billed', 'Mobile'],
        $q("SELECT MAX(b.full_name) k, COUNT(*) n, COALESCE(SUM(CASE WHEN $bill THEN b.total_fare END),0) billed, b.mobile_number m FROM cab_booking_requests b WHERE $base GROUP BY b.mobile_number ORDER BY billed DESC, n DESC LIMIT 10", $P)],
];

$sum = $q("SELECT COUNT(*) total,
    SUM(b.status = 'completed') completed, SUM(b.status = 'cancelled') cancelled, SUM(b.status IN ('new','contacted','quoted')) open_leads,
    SUM($bill) won, COALESCE(SUM(CASE WHEN $bill THEN b.total_fare END),0) billed, COALESCE(SUM(CASE WHEN $bill THEN b.paid_amount END),0) rec,
    COALESCE(SUM(CASE WHEN $bill THEN b.balance_amount END),0) due, COALESCE(AVG(CASE WHEN $bill AND b.total_fare > 0 THEN b.total_fare END),0) avgfare
    FROM cab_booking_requests b WHERE $base", $P)[0];
$total = (int) $sum['total'];
$decided = (int) $sum['won'] + (int) $sum['cancelled'];
$conv = $decided > 0 ? round((int) $sum['won'] / $decided * 100) : 0;
$cancelRate = $total > 0 ? round((int) $sum['cancelled'] / $total * 100) : 0;
$outstanding = $q("SELECT b.id, b.reference, b.full_name, b.mobile_number, b.journey_date, b.total_fare, b.balance_amount FROM cab_booking_requests b
    WHERE b.deleted_at IS NULL AND $bill AND b.balance_amount > 0 AND b.total_fare > 0 ORDER BY b.journey_date LIMIT 25");

// CSV export of one table
$exp = (string) ($_GET['export'] ?? '');
if (isset($tables[$exp]) || $exp === 'outstanding') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cab-' . $exp . '-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $safe = static fn($v) => (is_string($v) && $v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v;
    if ($exp === 'outstanding') {
        fputcsv($out, ['Reference', 'Customer', 'Mobile', 'Journey', 'Total', 'Balance']);
        foreach ($outstanding as $r) { fputcsv($out, array_map($safe, [$r['reference'], $r['full_name'], $r['mobile_number'], $r['journey_date'], $r['total_fare'], $r['balance_amount']])); }
    } else {
        fputcsv($out, $tables[$exp][1]);
        foreach ($tables[$exp][2] as $r) { fputcsv($out, array_map($safe, array_values($r))); }
    }
    fclose($out);
    exit;
}

tl_shell_top('Cab reports', 'Reports', ['Cab Services' => 'cab_dashboard.php']);
?>
<form method="get" class="panel tx-filter" style="align-items:end">
  <div><label style="font-size:.72rem;color:#6b7a72;display:block">Journey from</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label style="font-size:.72rem;color:#6b7a72;display:block">to</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
  <a class="btn btn-ghost" href="cab_reports.php?from=<?= e(date('Y-m-01')) ?>&amp;to=<?= e(date('Y-m-t')) ?>">This month</a>
  <a class="btn btn-ghost" href="cab_reports.php?from=<?= e(date('Y-m-d', strtotime('-90 days'))) ?>&amp;to=<?= e(date('Y-m-d')) ?>">Last 90 days</a>
  <a class="btn btn-ghost" href="cab_reports.php?from=<?= e(date('Y-01-01')) ?>&amp;to=<?= e(date('Y-12-31')) ?>">This year</a>
</form>

<div class="tx-kpis">
  <div class="tx-kpi"><div class="l">Bookings</div><div class="v"><?= $total ?></div></div>
  <div class="tx-kpi good"><div class="l">Completed trips</div><div class="v"><?= (int) $sum['completed'] ?></div></div>
  <div class="tx-kpi"><div class="l">Win rate (confirmed vs cancelled)</div><div class="v"><?= $conv ?>%</div></div>
  <div class="tx-kpi warn"><div class="l">Cancellation rate</div><div class="v"><?= $cancelRate ?>%</div></div>
  <div class="tx-kpi"><div class="l">Billed</div><div class="v"><?= e(tl_inr($sum['billed'])) ?></div></div>
  <div class="tx-kpi good"><div class="l">Received</div><div class="v"><?= e(tl_inr($sum['rec'])) ?></div></div>
  <div class="tx-kpi warn"><div class="l">Balance due</div><div class="v"><?= e(tl_inr($sum['due'])) ?></div></div>
  <div class="tx-kpi"><div class="l">Average fare</div><div class="v"><?= e(tl_inr($sum['avgfare'])) ?></div></div>
</div>

<?php foreach ($tables as $key => [$title, $heads, $rows]): ?>
<div class="panel">
  <div class="panel-head"><h3><i class="fa-solid fa-table"></i> <?= e($title) ?></h3><a class="btn btn-small btn-secondary" href="cab_reports.php?<?= e(http_build_query(['from' => $from, 'to' => $to, 'export' => $key])) ?>"><i class="fa-solid fa-file-csv"></i> CSV</a></div>
  <?php if (!$rows): ?><p style="color:#6b7a72;margin:0">No data for this period.</p><?php else: ?>
  <div class="tx-scroll"><table class="tx-table"><thead><tr><?php foreach ($heads as $i => $h): ?><th class="<?= $i > 0 ? 'r' : '' ?>"><?= e($h) ?></th><?php endforeach; ?></tr></thead><tbody>
  <?php foreach ($rows as $r): $v = array_values($r); ?>
    <tr><td><?= e($v[0]) ?></td>
    <?php for ($i = 1; $i < count($heads); $i++): $isMoney = in_array($heads[$i], ['Billed', 'Received'], true); $isPhone = $heads[$i] === 'Mobile'; ?>
      <td class="r"><?= $isMoney ? e(tl_inr($v[$i])) : e((string) $v[$i]) ?></td>
    <?php endfor; ?></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php endforeach; ?>

<div class="panel">
  <div class="panel-head"><h3><i class="fa-solid fa-hand-holding-dollar"></i> Outstanding balances (all dates)</h3><a class="btn btn-small btn-secondary" href="cab_reports.php?<?= e(http_build_query(['from' => $from, 'to' => $to, 'export' => 'outstanding'])) ?>"><i class="fa-solid fa-file-csv"></i> CSV</a></div>
  <?php if (!$outstanding): ?><p style="color:#6b7a72;margin:0">Nothing outstanding.</p><?php else: ?>
  <div class="tx-scroll"><table class="tx-table"><thead><tr><th>Reference</th><th>Customer</th><th>Journey</th><th class="r">Total</th><th class="r">Balance</th><th></th></tr></thead><tbody>
  <?php foreach ($outstanding as $r): ?><tr><td><a href="cab_view.php?id=<?= (int) $r['id'] ?>"><?= e($r['reference']) ?></a></td><td><?= e($r['full_name']) ?><br><small><?= e($r['mobile_number']) ?></small></td>
    <td><?= e(date('d M Y', strtotime((string) $r['journey_date']))) ?></td><td class="r"><?= e(tl_inr($r['total_fare'])) ?></td><td class="r tx-exp-bad"><?= e(tl_inr($r['balance_amount'])) ?></td>
    <td><a class="btn btn-small btn-secondary" target="_blank" rel="noopener" href="<?= e(cab_wa_link($r['mobile_number'], 'Hello ' . $r['full_name'] . ', a balance of ' . tl_inr($r['balance_amount']) . ' is pending for cab booking ' . $r['reference'] . '. Thank you - Biome Enterprises')) ?>"><i class="fa-brands fa-whatsapp"></i> Remind</a></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<?php tl_shell_bottom();
