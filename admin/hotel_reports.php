<?php
/**
 * admin/hotel_reports.php - occupancy, ADR, RevPAR, revenue by property / room type / source, cancellations,
 * GST collected, outstanding balances. Stay nights are attributed to the dates they fall on. CSV for each table.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hotel_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$from = htl_date((string) ($_GET['from'] ?? '')) ?? date('Y-m-01');
$to = htl_date((string) ($_GET['to'] ?? '')) ?? date('Y-m-t');
if ($to < $from) { [$from, $to] = [$to, $from]; }
if (htl_nights($from, $to) > 366) { $to = (new DateTime($from))->modify('+366 days')->format('Y-m-d'); }
$days = htl_nights($from, $to) + 1;
$endExcl = (new DateTime($to))->modify('+1 day')->format('Y-m-d');

$st = $pdo->prepare("SELECT r.*, p.name pname, t.name rname FROM hotel_reservations r JOIN hotel_properties p ON p.id = r.property_id JOIN hotel_room_types t ON t.id = r.room_type_id
    WHERE r.deleted_at IS NULL AND r.status IN ('confirmed','checked_in','checked_out') AND r.check_in < :e AND r.check_out > :f");
$st->execute([':e' => $endExcl, ':f' => $from]);
$stays = $st->fetchAll();

$by = ['prop' => [], 'room' => [], 'source' => [], 'month' => []];
$tot = ['res' => 0, 'rn' => 0, 'rev' => 0.0, 'gst' => 0.0, 'billed' => 0.0];
$add = static function (array &$bucket, string $k, int $rn, float $rev, float $billed): void {
    $bucket[$k] ??= ['k' => $k, 'res' => 0, 'rn' => 0, 'rev' => 0.0, 'billed' => 0.0];
    $bucket[$k]['res']++; $bucket[$k]['rn'] += $rn; $bucket[$k]['rev'] += $rev; $bucket[$k]['billed'] += $billed;
};
foreach ($stays as $r) {
    $a = max($r['check_in'], $from);
    $b = min($r['check_out'], $endExcl);
    $ov = max(0, htl_nights($a, $b));
    if ($ov === 0) { continue; }
    $share = (int) $r['nights'] > 0 ? $ov / (int) $r['nights'] : 0;
    $rn = $ov * (int) $r['rooms'];
    $rev = ((float) $r['room_total'] + (float) $r['extra_total'] + (float) $r['other_charges'] - (float) $r['discount']) * $share;   // before GST
    $billed = (float) $r['total_amount'] * $share;
    $tot['res']++; $tot['rn'] += $rn; $tot['rev'] += $rev; $tot['gst'] += (float) $r['gst_amount'] * $share; $tot['billed'] += $billed;
    $add($by['prop'], $r['pname'], $rn, $rev, $billed);
    $add($by['room'], $r['pname'] . ' - ' . $r['rname'], $rn, $rev, $billed);
    $add($by['source'], (string) $r['source'], $rn, $rev, $billed);
    $add($by['month'], substr($a, 0, 7), $rn, $rev, $billed);
}
ksort($by['month']);
$inv = (int) $pdo->query('SELECT COALESCE(SUM(r.total_rooms),0) FROM hotel_room_types r JOIN hotel_properties p ON p.id = r.property_id WHERE r.deleted_at IS NULL AND p.deleted_at IS NULL AND r.is_active = 1')->fetchColumn();
$avail = $inv * $days;
$occ = $avail > 0 ? $tot['rn'] / $avail * 100 : 0;
$adr = $tot['rn'] > 0 ? $tot['rev'] / $tot['rn'] : 0;
$revpar = $avail > 0 ? $tot['rev'] / $avail : 0;

$P = [':f' => $from, ':t' => $to];
$q = static function (string $sql, array $p = []) use ($pdo): array { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); };
$stat = $q("SELECT SUM(status='cancelled') canc, SUM(status='no_show') ns, COUNT(*) n FROM hotel_reservations WHERE deleted_at IS NULL AND check_in BETWEEN :f AND :t", $P)[0];
$coll = (float) $q("SELECT COALESCE(SUM(CASE WHEN entry_type='refund' THEN -amount ELSE amount END),0) c FROM hotel_payments WHERE DATE(payment_date) BETWEEN :f AND :t", $P)[0]['c'];
$due = $q("SELECT id, reference, guest_name, phone, check_out, balance_amount, status FROM hotel_reservations WHERE deleted_at IS NULL AND status IN ('confirmed','checked_in','checked_out') AND balance_amount > 0.009 ORDER BY check_out LIMIT 30");
$guests = $q("SELECT MAX(guest_name) k, COUNT(*) n, SUM(total_amount) s FROM hotel_reservations WHERE deleted_at IS NULL AND status IN ('confirmed','checked_in','checked_out') AND check_in BETWEEN :f AND :t
    GROUP BY RIGHT(REPLACE(REPLACE(REPLACE(phone,' ',''),'-',''),'+',''),10) ORDER BY s DESC LIMIT 10", $P);

$tables = [
    'property' => ['By property', array_values($by['prop'])], 'room' => ['By room type', array_values($by['room'])],
    'source' => ['By booking source', array_values($by['source'])], 'month' => ['By month', array_values($by['month'])],
];
$exp = (string) ($_GET['export'] ?? '');
if (isset($tables[$exp])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hotel-' . $exp . '-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Name', 'Reservations', 'Room-nights', 'Room revenue (before GST)', 'Billed (incl. GST)']);
    $safe = static fn($v) => (is_string($v) && $v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v;
    foreach ($tables[$exp][1] as $r) { fputcsv($out, array_map($safe, [$r['k'], $r['res'], $r['rn'], round($r['rev'], 2), round($r['billed'], 2)])); }
    fclose($out);
    exit;
}

tl_shell_top('Hotel reports', 'Reports', ['Hotels' => 'hotel_dashboard.php']);
?>
<form method="get" class="panel tx-filter" style="align-items:end">
  <div><label style="font-size:.72rem;color:#6b7a72;display:block">From</label><input type="date" name="from" value="<?= e($from) ?>"></div>
  <div><label style="font-size:.72rem;color:#6b7a72;display:block">To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <button class="btn btn-primary"><i class="fa-solid fa-filter"></i> Apply</button>
  <a class="btn btn-ghost" href="?from=<?= e(date('Y-m-01')) ?>&amp;to=<?= e(date('Y-m-t')) ?>">This month</a>
  <a class="btn btn-ghost" href="?from=<?= e(date('Y-m-d', strtotime('-90 days'))) ?>&amp;to=<?= e(date('Y-m-d')) ?>">Last 90 days</a>
  <a class="btn btn-ghost" href="?from=<?= e(date('Y-01-01')) ?>&amp;to=<?= e(date('Y-12-31')) ?>">This year</a>
</form>
<div class="tx-kpis">
  <div class="tx-kpi"><div class="l">Reservations</div><div class="v"><?= $tot['res'] ?></div></div>
  <div class="tx-kpi"><div class="l">Room-nights sold</div><div class="v"><?= $tot['rn'] ?></div></div>
  <div class="tx-kpi good"><div class="l">Occupancy</div><div class="v"><?= number_format($occ, 1) ?>%</div></div>
  <div class="tx-kpi"><div class="l">ADR (avg room rate)</div><div class="v"><?= e(tl_inr($adr)) ?></div></div>
  <div class="tx-kpi"><div class="l">RevPAR</div><div class="v"><?= e(tl_inr($revpar)) ?></div></div>
  <div class="tx-kpi good"><div class="l">Revenue (before GST)</div><div class="v"><?= e(tl_inr($tot['rev'])) ?></div></div>
  <div class="tx-kpi"><div class="l">GST on stays</div><div class="v"><?= e(tl_inr($tot['gst'])) ?></div></div>
  <div class="tx-kpi good"><div class="l">Collected in period</div><div class="v"><?= e(tl_inr($coll)) ?></div></div>
  <div class="tx-kpi warn"><div class="l">Cancelled / no-show</div><div class="v"><?= (int) $stat['canc'] ?> / <?= (int) $stat['ns'] ?></div><div class="l" style="margin-top:4px">of <?= (int) $stat['n'] ?> booked</div></div>
</div>
<p class="muted" style="font-size:.8rem;margin:-6px 0 16px">Nights are counted on the dates they fall in. Occupancy uses all bookable rooms (<?= $inv ?>) &times; <?= $days ?> days, before any rooms you blocked.</p>

<?php foreach ($tables as $key => [$title, $rows]): ?>
<div class="panel"><div class="panel-head"><h3><i class="fa-solid fa-table"></i> <?= e($title) ?></h3><a class="btn btn-small btn-secondary" href="?<?= e(http_build_query(['from' => $from, 'to' => $to, 'export' => $key])) ?>"><i class="fa-solid fa-file-csv"></i> CSV</a></div>
  <?php if (!$rows): ?><p class="muted" style="margin:0">No stays in this period.</p><?php else: ?><div class="tx-scroll"><table class="tx-table"><thead><tr><th>Name</th><th class="r">Reservations</th><th class="r">Room-nights</th><th class="r">Avg rate</th><th class="r">Room revenue</th><th class="r">Billed (incl. GST)</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= e($key === 'month' ? date('M Y', strtotime($r['k'] . '-01')) : (string) $r['k']) ?></td><td class="r"><?= (int) $r['res'] ?></td><td class="r"><?= (int) $r['rn'] ?></td>
    <td class="r"><?= e(tl_inr($r['rn'] > 0 ? $r['rev'] / $r['rn'] : 0)) ?></td><td class="r"><?= e(tl_inr($r['rev'])) ?></td><td class="r"><?= e(tl_inr($r['billed'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php endforeach; ?>

<div class="tx-grid"><div class="panel"><div class="panel-head"><h3><i class="fa-solid fa-hand-holding-dollar"></i> Outstanding balances</h3></div>
  <?php if (!$due): ?><p class="muted" style="margin:0">Nothing outstanding.</p><?php else: ?><div class="tx-scroll"><table class="tx-table"><thead><tr><th>Guest</th><th>Check-out</th><th class="r">Balance</th><th></th></tr></thead><tbody>
  <?php foreach ($due as $r): ?><tr><td><a href="hotel_view.php?id=<?= (int) $r['id'] ?>"><?= e($r['guest_name']) ?></a><br><small class="muted"><?= e($r['reference']) ?></small></td><td><?= e(date('d M Y', strtotime($r['check_out']))) ?></td><td class="r tx-exp-bad"><?= e(tl_inr($r['balance_amount'])) ?></td>
    <td><a class="btn btn-small btn-secondary" target="_blank" rel="noopener" href="<?= e(htl_wa_link($r['phone'], 'Hello ' . $r['guest_name'] . ', a balance of ' . tl_inr($r['balance_amount']) . ' is pending for booking ' . $r['reference'] . '. Thank you.')) ?>"><i class="fa-brands fa-whatsapp"></i></a></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<div class="panel"><div class="panel-head"><h3><i class="fa-solid fa-star"></i> Top guests</h3></div>
  <?php if (!$guests): ?><p class="muted" style="margin:0">No data.</p><?php endif; ?>
  <?php foreach ($guests as $g): ?><div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px dashed #e3ece7;font-size:.88rem"><span><?= e($g['k']) ?> <small class="muted">&times; <?= (int) $g['n'] ?></small></span><strong><?= e(tl_inr($g['s'])) ?></strong></div><?php endforeach; ?></div></div>
<?php tl_shell_bottom();
