<?php
/**
 * admin/hotel_dashboard.php - today at the property: arrivals, in-house, departures, occupancy, money, alerts.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hotel_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$one = static function (string $sql) use ($pdo) { try { return $pdo->query($sql)->fetchColumn(); } catch (Throwable $e) { error_log('hotel_dashboard: ' . $e->getMessage()); return 0; } };
$all = static function (string $sql) use ($pdo): array { try { return $pdo->query($sql)->fetchAll(); } catch (Throwable $e) { error_log('hotel_dashboard: ' . $e->getMessage()); return []; } };

if (!$one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'hotel_reservations'")) {
    tl_shell_top('Hotel dashboard', 'Hotels & Homestays', []);
    echo '<div class="panel"><h3>One-time setup needed</h3><p>Run the installer to create the hotel tables (nothing existing is changed).</p><a class="btn btn-primary" href="hotel_install.php">Run installer</a></div>';
    tl_shell_bottom();
    exit;
}

$live = 'r.deleted_at IS NULL';
$kArr = (int) $one("SELECT COUNT(*) FROM hotel_reservations r WHERE $live AND r.check_in = CURDATE() AND r.status IN ('pending','confirmed')");
$kIn = (int) $one("SELECT COUNT(*) FROM hotel_reservations r WHERE $live AND r.status = 'checked_in'");
$kDep = (int) $one("SELECT COUNT(*) FROM hotel_reservations r WHERE $live AND r.check_out = CURDATE() AND r.status = 'checked_in'");
$kPend = (int) $one("SELECT COUNT(*) FROM hotel_reservations r WHERE $live AND r.status = 'pending'");
$kMonthBilled = (float) $one("SELECT COALESCE(SUM(total_amount),0) FROM hotel_reservations r WHERE $live AND r.status IN ('confirmed','checked_in','checked_out') AND r.check_in >= DATE_FORMAT(CURDATE(),'%Y-%m-01') AND r.check_in <= LAST_DAY(CURDATE())");
$kColl = (float) $one("SELECT COALESCE(SUM(CASE WHEN entry_type='refund' THEN -amount ELSE amount END),0) FROM hotel_payments WHERE payment_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')");
$kDue = (float) $one("SELECT COALESCE(SUM(balance_amount),0) FROM hotel_reservations r WHERE $live AND r.status IN ('confirmed','checked_in','checked_out') AND balance_amount > 0");

// occupancy tonight and over the next 7 nights
$roomTypes = $all('SELECT r.* FROM hotel_room_types r JOIN hotel_properties p ON p.id = r.property_id WHERE r.deleted_at IS NULL AND p.deleted_at IS NULL AND r.is_active = 1');
$inv = 0; $occ = array_fill(0, 7, 0);
foreach ($roomTypes as $rt) {
    $av = htl_availability($pdo, $rt, date('Y-m-d'), date('Y-m-d', strtotime('+7 days')))['nights'];
    $inv += (int) $rt['total_rooms'];
    $i = 0;
    foreach ($av as $free) { $occ[$i++] += (int) $rt['total_rooms'] - $free; }
}
$occPct = static fn(int $n) => $inv > 0 ? (int) round($n / $inv * 100) : 0;

$alerts = [];
if (!(int) $one('SELECT COUNT(*) FROM hotel_properties WHERE deleted_at IS NULL')) { $alerts[] = ['warning', 'fa-hotel', 'No property yet - add your first hotel or homestay', 'hotel_properties.php?add=1']; }
elseif (!(int) $one('SELECT COUNT(*) FROM hotel_properties WHERE deleted_at IS NULL AND is_active = 1')) { $alerts[] = ['warning', 'fa-eye-slash', 'No property is live on the website yet', 'hotel_properties.php']; }
if ($kPend) { $alerts[] = ['warning', 'fa-hourglass-half', "$kPend booking request(s) waiting to be confirmed", 'hotel_reservations.php?status=pending']; }
$tom = (int) $one("SELECT COUNT(*) FROM hotel_reservations r WHERE $live AND r.check_in = DATE_ADD(CURDATE(), INTERVAL 1 DAY) AND r.status IN ('pending','confirmed')");
if ($tom) { $alerts[] = ['warning', 'fa-suitcase-rolling', "$tom guest(s) arrive tomorrow", 'hotel_reservations.php?from=' . date('Y-m-d', strtotime('+1 day')) . '&to=' . date('Y-m-d', strtotime('+1 day'))]; }
$late = (int) $one("SELECT COUNT(*) FROM hotel_reservations r WHERE $live AND r.status = 'confirmed' AND r.check_in < CURDATE()");
if ($late) { $alerts[] = ['danger', 'fa-user-clock', "$late confirmed booking(s) past their check-in date - check in or mark no-show", 'hotel_reservations.php?status=confirmed']; }
$overstay = (int) $one("SELECT COUNT(*) FROM hotel_reservations r WHERE $live AND r.status = 'checked_in' AND r.check_out < CURDATE()");
if ($overstay) { $alerts[] = ['danger', 'fa-clock', "$overstay guest(s) still checked in after their check-out date", 'hotel_reservations.php?view=inhouse']; }
if ($kDep) { $alerts[] = ['warning', 'fa-right-from-bracket', "$kDep guest(s) due to check out today", 'hotel_reservations.php?view=departing']; }

$arrivals = $all("SELECT r.*, p.name pname, t.name rname FROM hotel_reservations r JOIN hotel_properties p ON p.id = r.property_id JOIN hotel_room_types t ON t.id = r.room_type_id
    WHERE $live AND r.check_in = CURDATE() AND r.status IN ('pending','confirmed') ORDER BY r.arrival_time IS NULL, r.arrival_time LIMIT 8");
$inhouse = $all("SELECT r.*, p.name pname, t.name rname FROM hotel_reservations r JOIN hotel_properties p ON p.id = r.property_id JOIN hotel_room_types t ON t.id = r.room_type_id
    WHERE $live AND r.status = 'checked_in' ORDER BY r.check_out LIMIT 8");
$pending = $all("SELECT r.*, p.name pname, t.name rname FROM hotel_reservations r JOIN hotel_properties p ON p.id = r.property_id JOIN hotel_room_types t ON t.id = r.room_type_id
    WHERE $live AND r.status = 'pending' ORDER BY r.id DESC LIMIT 6");

tl_shell_top('Hotel dashboard', 'Hotels & Homestays', [],
    '<a class="btn btn-secondary" href="hotel_calendar.php"><i class="fa-solid fa-calendar-days"></i> Calendar</a><a class="btn btn-primary" href="hotel_form.php"><i class="fa-solid fa-plus"></i> New reservation</a>');
?>
<?php foreach ($alerts as [$cls, $ico, $msg, $href]): ?>
  <a href="<?= e($href) ?>" class="tx-flash <?= $cls === 'danger' ? 'err' : 'ok' ?>" style="display:block;text-decoration:none;<?= $cls === 'warning' ? 'background:#fff7e6;border-color:#f5dfae;color:#8a5a00' : '' ?>"><i class="fa-solid <?= e($ico) ?>"></i> <?= e($msg) ?> <span style="float:right">View &rarr;</span></a>
<?php endforeach; ?>

<div class="tx-kpis">
  <div class="tx-kpi"><div class="l">Arriving today</div><div class="v"><?= $kArr ?></div></div>
  <div class="tx-kpi"><div class="l">In-house</div><div class="v"><?= $kIn ?></div></div>
  <div class="tx-kpi"><div class="l">Departing today</div><div class="v"><?= $kDep ?></div></div>
  <div class="tx-kpi good"><div class="l">Occupancy tonight</div><div class="v"><?= $occPct($occ[0]) ?>%</div><div class="l" style="margin-top:4px"><?= (int) $occ[0] ?> of <?= $inv ?> rooms</div></div>
  <div class="tx-kpi"><div class="l">Booked this month</div><div class="v"><?= e(tl_inr($kMonthBilled)) ?></div></div>
  <div class="tx-kpi good"><div class="l">Collected this month</div><div class="v"><?= e(tl_inr($kColl)) ?></div></div>
  <div class="tx-kpi warn"><div class="l">Balance due</div><div class="v"><?= e(tl_inr($kDue)) ?></div></div>
</div>

<div class="tx-grid"><div>
  <div class="panel"><div class="panel-head"><h3><i class="fa-solid fa-suitcase-rolling"></i> Arriving today</h3><a class="btn btn-small btn-secondary" href="hotel_reservations.php?view=arriving">All</a></div>
    <?php if (!$arrivals): ?><p class="muted" style="margin:0;text-align:center;padding:14px">No arrivals today.</p><?php else: ?><div class="tx-scroll"><table class="tx-table"><thead><tr><th>Guest</th><th>Room</th><th>Arrival</th><th class="r">Balance</th><th>Status</th></tr></thead><tbody>
    <?php foreach ($arrivals as $r): ?><tr><td><a href="hotel_view.php?id=<?= (int) $r['id'] ?>"><strong><?= e($r['guest_name']) ?></strong></a><br><small class="muted"><?= e($r['reference']) ?></small></td><td><?= e($r['pname']) ?><br><small class="muted"><?= e($r['rname']) ?> &times; <?= (int) $r['rooms'] ?></small></td>
      <td><?= $r['arrival_time'] ? e(substr((string) $r['arrival_time'], 0, 5)) : '<span class="muted">-</span>' ?></td><td class="r"><?= e(tl_inr($r['balance_amount'])) ?></td><td><?= htl_badge((string) $r['status']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
  <div class="panel"><div class="panel-head"><h3><i class="fa-solid fa-key"></i> In-house guests</h3><a class="btn btn-small btn-secondary" href="hotel_reservations.php?view=inhouse">All</a></div>
    <?php if (!$inhouse): ?><p class="muted" style="margin:0;text-align:center;padding:14px">Nobody checked in.</p><?php else: ?><div class="tx-scroll"><table class="tx-table"><thead><tr><th>Guest</th><th>Room</th><th>Departs</th><th class="r">Balance</th></tr></thead><tbody>
    <?php foreach ($inhouse as $r): $dep = $r['check_out'] <= date('Y-m-d'); ?><tr><td><a href="hotel_view.php?id=<?= (int) $r['id'] ?>"><strong><?= e($r['guest_name']) ?></strong></a></td><td><?= e($r['room_numbers'] ?: $r['rname']) ?></td>
      <td class="<?= $dep ? 'tx-exp-bad' : '' ?>"><?= e(date('d M', strtotime($r['check_out']))) ?><?= $dep ? ' (today)' : '' ?></td><td class="r"><?= e(tl_inr($r['balance_amount'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></div>
  <div class="panel"><div class="panel-head"><h3><i class="fa-solid fa-chart-column"></i> Occupancy - next 7 nights</h3></div>
    <div style="display:flex;align-items:flex-end;gap:12px;height:140px"><?php foreach ($occ as $i => $n): $d = date('Y-m-d', strtotime("+$i day")); $pc = $occPct((int) $n); ?>
      <div style="flex:1;text-align:center;display:flex;flex-direction:column;justify-content:flex-end;height:100%"><div style="font-size:.7rem;color:#6b7a72;margin-bottom:3px"><?= $pc ?>%</div>
        <div style="background:linear-gradient(180deg,#198754,#0f4c2d);border-radius:6px 6px 0 0;height:<?= max(3, (int) round($pc * 0.9)) ?>px"></div><div style="font-size:.72rem;margin-top:5px;font-weight:600"><?= e(date('D', strtotime($d))) ?></div></div><?php endforeach; ?></div></div>
</div><div>
  <div class="panel"><div class="panel-head"><h3><i class="fa-solid fa-hourglass-half"></i> Awaiting confirmation</h3><a class="btn btn-small btn-secondary" href="hotel_reservations.php?status=pending">All</a></div>
    <?php if (!$pending): ?><p class="muted" style="margin:0">All caught up.</p><?php endif; ?>
    <?php foreach ($pending as $r): ?><a href="hotel_view.php?id=<?= (int) $r['id'] ?>" style="display:block;padding:9px 0;border-bottom:1px solid #eef3ef;text-decoration:none;color:inherit"><strong><?= e($r['guest_name']) ?></strong> <small class="muted" style="float:right"><?= e(tl_dt($r['created_at'], 'd M, h:i A')) ?></small><br>
      <small class="muted"><?= e($r['pname']) ?> &middot; <?= e(date('d M', strtotime($r['check_in']))) ?>-<?= e(date('d M', strtotime($r['check_out']))) ?> &middot; <?= e(tl_inr($r['total_amount'])) ?></small></a><?php endforeach; ?></div>
  <div class="panel"><div class="panel-head"><h3><i class="fa-solid fa-bolt"></i> Quick links</h3></div><div style="display:flex;flex-direction:column;gap:8px">
    <a class="btn btn-secondary" href="hotel_properties.php">Properties</a><a class="btn btn-secondary" href="hotel_calendar.php">Availability calendar</a><a class="btn btn-secondary" href="hotel_reports.php">Reports</a><a class="btn btn-secondary" href="hotel_settings.php">Settings</a><a class="btn btn-secondary" target="_blank" href="../hotel">View public page</a></div></div>
</div></div>
<?php tl_shell_bottom();
