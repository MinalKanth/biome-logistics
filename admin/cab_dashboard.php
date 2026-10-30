<?php
/**
 * admin/cab_dashboard.php - operations overview for the cab service.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/cab_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$one = static function (string $sql, array $p = []) use ($pdo) {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); } catch (Throwable $e) { error_log('cab_dashboard: ' . $e->getMessage()); return 0; }
};
$all = static function (string $sql, array $p = []) use ($pdo): array {
    try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (Throwable $e) { error_log('cab_dashboard: ' . $e->getMessage()); return []; }
};

$installed = (bool) $one("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cab_rates'");
if (!$installed) {
    tl_shell_top('Cab dashboard', 'Cab Services', []);
    echo '<div class="panel"><h3>One-time setup needed</h3><p>Run the installer to upgrade your cab tables (existing requests are kept).</p><a class="btn btn-primary" href="cab_install.php">Run installer</a></div>';
    tl_shell_bottom();
    exit;
}

$live = "b.deleted_at IS NULL";
$kNew      = (int) $one("SELECT COUNT(*) FROM cab_booking_requests b WHERE $live AND b.status = 'new'");
$kToday    = (int) $one("SELECT COUNT(*) FROM cab_booking_requests b WHERE $live AND b.status IN ('confirmed','assigned','on_trip') AND b.journey_date = CURDATE()");
$kRunning  = (int) $one("SELECT COUNT(*) FROM cab_booking_requests b WHERE $live AND b.status = 'on_trip'");
$kMonthRev = (float) $one("SELECT COALESCE(SUM(CASE WHEN entry_type='refund' THEN -amount ELSE amount END),0) FROM cab_payments WHERE payment_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')");
$kBilled   = (float) $one("SELECT COALESCE(SUM(total_fare),0) FROM cab_booking_requests b WHERE $live AND b.status IN ('confirmed','assigned','on_trip','completed') AND b.journey_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')");
$kDue      = (float) $one("SELECT COALESCE(SUM(balance_amount),0) FROM cab_booking_requests b WHERE $live AND b.status IN ('confirmed','assigned','on_trip','completed') AND total_fare > 0");
$kFleet    = (int) $one("SELECT COUNT(*) FROM cab_vehicles WHERE deleted_at IS NULL AND status = 'available'");

$upcoming = $all("SELECT b.*, v.name AS vehicle_name, d.full_name AS driver_name FROM cab_booking_requests b
    LEFT JOIN cab_vehicles v ON v.id = b.vehicle_id LEFT JOIN cab_drivers d ON d.id = b.driver_id
    WHERE $live AND b.status IN ('confirmed','assigned','on_trip') AND COALESCE(b.end_date, b.journey_date) >= CURDATE()
    ORDER BY b.journey_date, b.pickup_time LIMIT 8");
$newest = $all("SELECT id, reference, full_name, service_type, vehicle_type, pickup_location, journey_date, created_at FROM cab_booking_requests b WHERE $live AND b.status = 'new' ORDER BY id DESC LIMIT 6");

// things that need attention
$alerts = [];
$unassigned = (int) $one("SELECT COUNT(*) FROM cab_booking_requests b WHERE $live AND b.status = 'confirmed' AND b.vehicle_id IS NULL AND b.journey_date <= DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
if ($unassigned) { $alerts[] = ['danger', 'fa-triangle-exclamation', "$unassigned confirmed trip(s) in the next 3 days have no vehicle assigned", 'cab_bookings.php?status=confirmed']; }
$followUps = (int) $one("SELECT COUNT(*) FROM cab_booking_requests b WHERE $live AND b.follow_up_date IS NOT NULL AND b.follow_up_date <= CURDATE() AND b.status IN ('new','contacted','quoted')");
if ($followUps) { $alerts[] = ['warning', 'fa-bell', "$followUps follow-up(s) due", 'cab_bookings.php']; }
$stale = (int) $one("SELECT COUNT(*) FROM cab_booking_requests b WHERE $live AND b.status = 'new' AND b.created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
if ($stale) { $alerts[] = ['warning', 'fa-hourglass-half', "$stale new request(s) waiting more than 24 hours", 'cab_bookings.php?status=new']; }
$docs = (int) $one("SELECT COUNT(*) FROM cab_vehicles WHERE deleted_at IS NULL AND (insurance_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) OR permit_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) OR fitness_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) OR puc_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY))");
if ($docs) { $alerts[] = ['danger', 'fa-file-circle-exclamation', "$docs vehicle(s) have documents expired or expiring within 30 days", 'cab_fleet.php']; }
$lic = (int) $one("SELECT COUNT(*) FROM cab_drivers WHERE deleted_at IS NULL AND license_expiry <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
if ($lic) { $alerts[] = ['danger', 'fa-id-card', "$lic driver licence(s) expired or expiring within 30 days", 'cab_fleet.php?tab=drivers']; }
if (!(int) $one('SELECT COUNT(*) FROM cab_rates WHERE is_active = 1')) { $alerts[] = ['warning', 'fa-indian-rupee-sign', 'No live rates: the website shows "fare on request". Review sample rates.', 'cab_rates.php']; }
if (!$kFleet) { $alerts[] = ['warning', 'fa-car-side', 'No available vehicles in the fleet yet', 'cab_fleet.php']; }

// 6-month billing trend (by journey month, confirmed or later)
$trend = [];
for ($i = 5; $i >= 0; $i--) { $m = date('Y-m', strtotime("first day of -$i month")); $trend[$m] = 0.0; }
foreach ($all("SELECT DATE_FORMAT(journey_date,'%Y-%m') m, SUM(total_fare) t FROM cab_booking_requests b WHERE $live AND b.status IN ('confirmed','assigned','on_trip','completed') AND journey_date >= DATE_SUB(DATE_FORMAT(CURDATE(),'%Y-%m-01'), INTERVAL 5 MONTH) GROUP BY m") as $r) {
    if (isset($trend[$r['m']])) { $trend[$r['m']] = (float) $r['t']; }
}
$max = max(1.0, max($trend));
$mix = $all("SELECT COALESCE(service_type,'Unspecified') s, COUNT(*) c FROM cab_booking_requests b WHERE $live GROUP BY s ORDER BY c DESC");
$mixTotal = max(1, array_sum(array_column($mix, 'c')));

tl_shell_top('Cab dashboard', 'Cab Services', [],
    '<a class="btn btn-secondary" href="cab_reports.php"><i class="fa-solid fa-chart-column"></i> Reports</a><a class="btn btn-primary" href="cab_form.php"><i class="fa-solid fa-plus"></i> New booking</a>');
?>
<?php foreach ($alerts as [$cls, $ico, $msg, $href]): ?>
  <a href="<?= e($href) ?>" class="tx-flash <?= $cls === 'danger' ? 'err' : 'ok' ?>" style="display:block;text-decoration:none;<?= $cls === 'warning' ? 'background:#fff7e6;border-color:#f5dfae;color:#8a5a00' : '' ?>"><i class="fa-solid <?= e($ico) ?>"></i> <?= e($msg) ?> <span style="float:right">View &rarr;</span></a>
<?php endforeach; ?>

<div class="tx-kpis">
  <div class="tx-kpi warn"><div class="l">New requests</div><div class="v"><?= $kNew ?></div></div>
  <div class="tx-kpi"><div class="l">Pickups today</div><div class="v"><?= $kToday ?></div></div>
  <div class="tx-kpi"><div class="l">Trips running now</div><div class="v"><?= $kRunning ?></div></div>
  <div class="tx-kpi"><div class="l">Vehicles available</div><div class="v"><?= $kFleet ?></div></div>
  <div class="tx-kpi"><div class="l">Billed this month</div><div class="v"><?= e(tl_inr($kBilled)) ?></div></div>
  <div class="tx-kpi good"><div class="l">Received this month</div><div class="v"><?= e(tl_inr($kMonthRev)) ?></div></div>
  <div class="tx-kpi warn"><div class="l">Outstanding</div><div class="v"><?= e(tl_inr($kDue)) ?></div></div>
</div>

<div class="tx-grid">
  <div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-calendar-days"></i> Upcoming &amp; running trips</h3><a class="btn btn-small btn-secondary" href="cab_bookings.php?status=confirmed">All confirmed</a></div>
      <?php if (!$upcoming): ?><p style="color:#6b7a72;text-align:center;padding:20px">No confirmed trips coming up.</p><?php else: ?>
      <div class="tx-scroll"><table class="tx-table"><thead><tr><th>When</th><th>Customer</th><th>Route</th><th>Vehicle / driver</th><th>Status</th></tr></thead><tbody>
      <?php foreach ($upcoming as $r): ?><tr>
        <td><strong><?= e(date('d M', strtotime($r['journey_date']))) ?></strong><br><small style="color:#6b7a72"><?= $r['pickup_time'] ? e(substr((string) $r['pickup_time'], 0, 5)) : '' ?><?= (int) $r['days'] > 1 ? ' &middot; ' . (int) $r['days'] . 'd' : '' ?></small></td>
        <td><a href="cab_view.php?id=<?= (int) $r['id'] ?>"><strong><?= e($r['full_name']) ?></strong></a><br><small style="color:#6b7a72"><?= e($r['reference']) ?></small></td>
        <td><?= e($r['pickup_location'] ?: '-') ?><?= $r['destination'] ? ' &rarr; ' . e($r['destination']) : '' ?></td>
        <td><?= $r['vehicle_name'] ? e($r['vehicle_name']) . '<br><small style="color:#6b7a72">' . e($r['driver_name'] ?: 'No driver') . '</small>' : '<span class="tx-exp-bad">Unassigned</span>' ?></td>
        <td><?= cab_status_badge((string) $r['status']) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-chart-column"></i> Billing - last 6 months</h3></div>
      <div style="display:flex;align-items:flex-end;gap:14px;height:170px;padding-top:10px">
      <?php foreach ($trend as $m => $t): ?>
        <div style="flex:1;text-align:center;display:flex;flex-direction:column;justify-content:flex-end;height:100%">
          <div style="font-size:.7rem;color:#6b7a72;margin-bottom:4px"><?= $t > 0 ? e(tl_inr($t)) : '' ?></div>
          <div style="background:linear-gradient(180deg,#198754,#0f4c2d);border-radius:6px 6px 0 0;height:<?= max(3, (int) round($t / $max * 110)) ?>px"></div>
          <div style="font-size:.75rem;margin-top:6px;font-weight:600"><?= e(date('M', strtotime($m . '-01'))) ?></div>
        </div>
      <?php endforeach; ?></div>
    </div>
  </div>
  <div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-inbox"></i> Newest requests</h3></div>
      <?php if (!$newest): ?><p style="color:#6b7a72;margin:0">All caught up.</p><?php endif; ?>
      <?php foreach ($newest as $r): ?>
        <a href="cab_view.php?id=<?= (int) $r['id'] ?>" style="display:block;padding:10px 0;border-bottom:1px solid #eef3ef;text-decoration:none;color:inherit">
          <strong><?= e($r['full_name']) ?></strong> <small style="color:#6b7a72;float:right"><?= e(tl_dt($r['created_at'], 'd M, h:i A')) ?></small><br>
          <small style="color:#6b7a72"><?= e($r['service_type'] ?: 'Service TBC') ?> &middot; <?= e($r['vehicle_type'] ?: 'Vehicle TBC') ?> &middot; <?= e($r['pickup_location'] ?: '-') ?></small></a>
      <?php endforeach; ?>
    </div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-chart-pie"></i> Demand by service</h3></div>
      <?php foreach ($mix as $m): $pct = (int) round($m['c'] / $mixTotal * 100); ?>
        <div style="margin-bottom:12px"><div style="display:flex;justify-content:space-between;font-size:.84rem"><span><?= e($m['s']) ?></span><strong><?= (int) $m['c'] ?></strong></div>
          <div style="background:#eef3ef;border-radius:99px;height:7px"><div style="background:#198754;border-radius:99px;height:7px;width:<?= $pct ?>%"></div></div></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php tl_shell_bottom();
