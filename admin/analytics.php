<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

/* =========================================================================
   DATA LAYER — every query is defensive: missing table/column => safe default,
   so this page always renders regardless of which parts of the schema exist.
   ========================================================================= */
function safe_scalar(PDO $pdo, string $sql, $default = 0)
{
    try {
        $val = $pdo->query($sql)->fetchColumn();
        return $val === false ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}
function safe_all(PDO $pdo, string $sql, $default = [])
{
    try {
        return $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return $default;
    }
}

// Selected range (days) for every trend chart on the page
$range = (int) ($_GET['range'] ?? 30);
if (!in_array($range, [7, 30, 90], true)) {
    $range = 30;
}

/* ---------------------------------------------------------------------
   USERS
--------------------------------------------------------------------- */
$totalUsers      = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM users");
$activeUsers     = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status='active'");
$inactiveUsers   = max(0, $totalUsers - $activeUsers);
$pendingUsers    = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status='pending'");
$newUsersRange   = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)");
$newUsersPrev    = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL " . ($range * 2) . " DAY) AND created_at < DATE_SUB(NOW(), INTERVAL {$range} DAY)");
$userGrowthPct   = $newUsersPrev > 0 ? round((($newUsersRange - $newUsersPrev) / $newUsersPrev) * 100, 1) : ($newUsersRange > 0 ? 100.0 : 0.0);

$signupSeries = safe_all($pdo, "
    SELECT DATE(created_at) AS d, COUNT(*) AS c
    FROM users
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL " . ($range - 1) . " DAY)
    GROUP BY DATE(created_at) ORDER BY d ASC
");
$signupMap = [];
foreach ($signupSeries as $r) { $signupMap[$r['d']] = (int) $r['c']; }
$userLabels = []; $userData = [];
for ($i = $range - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $userLabels[] = date('M j', strtotime($day));
    $userData[]   = $signupMap[$day] ?? 0;
}

/* ---------------------------------------------------------------------
   TRANSPORT — bookings, revenue, status/payment breakdowns
--------------------------------------------------------------------- */
$totalBookings   = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL");
$bookingsRange   = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)");
$bookingsPrev    = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL " . ($range * 2) . " DAY) AND created_at < DATE_SUB(NOW(), INTERVAL {$range} DAY)");
$bookingGrowthPct = $bookingsPrev > 0 ? round((($bookingsRange - $bookingsPrev) / $bookingsPrev) * 100, 1) : ($bookingsRange > 0 ? 100.0 : 0.0);

$totalRevenue    = (float) safe_scalar($pdo, "SELECT COALESCE(SUM(paid_amount),0) FROM transport_bookings WHERE deleted_at IS NULL");
$revenueRange    = (float) safe_scalar($pdo, "SELECT COALESCE(SUM(paid_amount),0) FROM transport_bookings WHERE deleted_at IS NULL AND created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)");
$outstandingAmt  = (float) safe_scalar($pdo, "SELECT COALESCE(SUM(balance_amount),0) FROM transport_bookings WHERE deleted_at IS NULL AND payment_status IN ('unpaid','partial')");
$avgBookingValue = (float) safe_scalar($pdo, "SELECT COALESCE(AVG(net_amount),0) FROM transport_bookings WHERE deleted_at IS NULL");

$bookingSeries = safe_all($pdo, "
    SELECT DATE(created_at) AS d, COUNT(*) AS bookings, COALESCE(SUM(net_amount),0) AS revenue
    FROM transport_bookings
    WHERE deleted_at IS NULL AND created_at >= DATE_SUB(CURDATE(), INTERVAL " . ($range - 1) . " DAY)
    GROUP BY DATE(created_at) ORDER BY d ASC
");
$bookingMap = [];
foreach ($bookingSeries as $r) { $bookingMap[$r['d']] = ['b' => (int) $r['bookings'], 'r' => (float) $r['revenue']]; }
$bookingLabels = []; $bookingCounts = []; $bookingRevenue = [];
for ($i = $range - 1; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $bookingLabels[]  = date('M j', strtotime($day));
    $bookingCounts[]  = $bookingMap[$day]['b'] ?? 0;
    $bookingRevenue[] = $bookingMap[$day]['r'] ?? 0;
}

$statusBreakdown = safe_all($pdo, "
    SELECT status, COUNT(*) AS c FROM transport_bookings
    WHERE deleted_at IS NULL GROUP BY status
");
$paymentBreakdown = safe_all($pdo, "
    SELECT payment_status, COUNT(*) AS c FROM transport_bookings
    WHERE deleted_at IS NULL GROUP BY payment_status
");
$cargoBreakdown = safe_all($pdo, "
    SELECT COALESCE(NULLIF(cargo_type,''),'Unspecified') AS cargo_type, COUNT(*) AS c
    FROM transport_bookings WHERE deleted_at IS NULL
    GROUP BY cargo_type ORDER BY c DESC LIMIT 6
");

$topRoutes = safe_all($pdo, "
    SELECT pickup_city, drop_city, COUNT(*) AS trips, COALESCE(SUM(net_amount),0) AS revenue
    FROM transport_bookings
    WHERE deleted_at IS NULL AND pickup_city IS NOT NULL AND drop_city IS NOT NULL
    GROUP BY pickup_city, drop_city
    ORDER BY trips DESC LIMIT 5
");

$topCustomers = safe_all($pdo, "
    SELECT customer_name, COUNT(*) AS bookings, COALESCE(SUM(net_amount),0) AS revenue
    FROM transport_bookings
    WHERE deleted_at IS NULL
    GROUP BY customer_name
    ORDER BY revenue DESC LIMIT 5
");

$driverPerformance = safe_all($pdo, "
    SELECT d.driver_name,
           COUNT(tb.id) AS bookings,
           SUM(CASE WHEN tb.status = 'delivered' THEN 1 ELSE 0 END) AS delivered
    FROM transport_drivers d
    LEFT JOIN transport_bookings tb ON tb.driver_id = d.id AND tb.deleted_at IS NULL
    GROUP BY d.id, d.driver_name
    ORDER BY bookings DESC LIMIT 6
");

$vehicleUtilization = safe_all($pdo, "
    SELECT v.registration_number, v.vehicle_type,
           COUNT(tb.id) AS trips
    FROM transport_vehicles v
    LEFT JOIN transport_bookings tb ON tb.vehicle_id = v.id AND tb.deleted_at IS NULL
    GROUP BY v.id, v.registration_number, v.vehicle_type
    ORDER BY trips DESC LIMIT 6
");

$avgDeliveryDays = (float) safe_scalar($pdo, "
    SELECT COALESCE(AVG(DATEDIFF(expected_delivery, scheduled_pickup)),0)
    FROM transport_bookings
    WHERE deleted_at IS NULL AND expected_delivery IS NOT NULL AND scheduled_pickup IS NOT NULL
");
$onTimeRate = (float) safe_scalar($pdo, "
    SELECT CASE WHEN COUNT(*)=0 THEN 0 ELSE
      ROUND(SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) / COUNT(*) * 100, 1)
    END
    FROM transport_bookings WHERE deleted_at IS NULL
");

/* ---------------------------------------------------------------------
   BLOG
--------------------------------------------------------------------- */
$totalPosts      = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM blog_posts");
$publishedPosts  = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM blog_posts WHERE status='published'");
$draftPosts      = max(0, $totalPosts - $publishedPosts);
$totalPhotos     = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM blog_photos");

$postsPerMonth = safe_all($pdo, "
    SELECT DATE_FORMAT(event_date, '%b %Y') AS m, COUNT(*) AS c
    FROM blog_posts
    WHERE event_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY YEAR(event_date), MONTH(event_date)
    ORDER BY YEAR(event_date), MONTH(event_date)
");
$blogLabels = array_column($postsPerMonth, 'm');
$blogData   = array_map('intval', array_column($postsPerMonth, 'c'));

/* ---------------------------------------------------------------------
   ADMIN ACTIVITY
--------------------------------------------------------------------- */
$totalAdmins     = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM admins");
$actionsRange    = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM activity_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)");
$failedLogins24h = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM activity_log WHERE action='failed_login' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)");

$adminActivity = safe_all($pdo, "
    SELECT a.username, COUNT(al.id) AS actions, MAX(al.created_at) AS last_action
    FROM admins a
    LEFT JOIN activity_log al ON al.admin_id = a.id AND al.created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)
    GROUP BY a.id, a.username
    ORDER BY actions DESC LIMIT 6
");

$topActions = safe_all($pdo, "
    SELECT action, COUNT(*) AS c
    FROM activity_log
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL {$range} DAY)
    GROUP BY action ORDER BY c DESC LIMIT 6
");

$statusColorMap = [
    'pending' => '#94a3a8', 'confirmed' => '#2f6f8f', 'driver_assigned' => '#2f6f8f',
    'picked_up' => '#b3791b', 'in_transit' => '#b3791b', 'out_for_delivery' => '#b3791b',
    'delivered' => '#1b7a34', 'cancelled' => '#c0362c', 'returned' => '#c0362c',
];
$paymentColorMap = ['unpaid' => '#c0362c', 'partial' => '#b3791b', 'paid' => '#1b7a34', 'refunded' => '#94a3a8'];

$pageTitle = 'Analytics';
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/admin-theme-green.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>

<style>
  .range-switch { display:flex; gap:6px; }
  .range-switch a {
    padding:7px 14px; border-radius:8px; font-size:.8rem; font-weight:600;
    border:1px solid var(--border,#dfeadf); color:var(--text-secondary,#33463a); text-decoration:none;
  }
  .range-switch a.active { background:var(--accent,#1b7a34); border-color:var(--accent,#1b7a34); color:#fff; }

  .analytics-section-title {
    font-size:.78rem; text-transform:uppercase; letter-spacing:.05em;
    color:var(--text-muted,#6b7d6f); margin:30px 0 12px; font-weight:700;
  }
  .analytics-section-title:first-of-type { margin-top:0; }

  .mini-list { display:flex; flex-direction:column; gap:2px; }
  .mini-row {
    display:flex; justify-content:space-between; align-items:center;
    padding:10px 4px; border-bottom:1px solid var(--border,#eee); font-size:.85rem;
  }
  .mini-row:last-child { border-bottom:none; }
  .mini-row .name { font-weight:600; }
  .mini-row .sub { font-size:.74rem; color:var(--text-muted,#6b7d6f); }
  .mini-row .val { text-align:right; }
  .mini-row .val strong { display:block; }

  .legend-dot { display:inline-block; width:8px; height:8px; border-radius:50%; margin-right:6px; }

  .kpi-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:10px; }
  .kpi-box { background:var(--surface,#fff); border:1px solid var(--border,#eee); border-radius:12px; padding:16px 18px; }
  .kpi-box .kpi-label { font-size:.78rem; color:var(--text-muted,#6b7d6f); margin-bottom:6px; }
  .kpi-box .kpi-value { font-size:1.4rem; font-weight:700; }
  .kpi-box .kpi-delta { font-size:.76rem; margin-top:4px; }
  .kpi-delta.up { color:#1b7a34; }
  .kpi-delta.down { color:#c0362c; }
</style>

<div class="app-shell">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

  <div class="main-col">

    <header class="topbar">
      <div style="display:flex;align-items:center;gap:14px;">
        <div class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></div>
        <div class="topbar-search">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" placeholder="Search users, logs, settings…">
          <kbd>⌘K</kbd>
        </div>
      </div>
      <div class="topbar-right">
        <div class="icon-btn" title="Notifications"><i class="fa-regular fa-bell"></i></div>
        <div class="icon-btn" title="Help &amp; documentation"><i class="fa-regular fa-circle-question"></i></div>
        <div class="topbar-divider"></div>
        <div class="profile-chip">
          <div class="avatar"><?= e(strtoupper(substr($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'A', 0, 1))) ?></div>
          <div class="who">
            <strong><?= e($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Administrator') ?></strong>
            <span><?= e($_SESSION['admin_role'] ?? 'Super Admin') ?></span>
          </div>
          <i class="fa-solid fa-chevron-down" style="font-size:10px;color:var(--text-muted);"></i>
        </div>
      </div>
    </header>

    <main class="content">

      <div class="page-head">
        <div>
          <div class="breadcrumb">Biome <span class="sep">/</span> <span class="current">Analytics</span></div>
          <h1>Analytics overview</h1>
        </div>
        <div class="range-switch">
          <a href="?range=7" class="<?= $range === 7 ? 'active' : '' ?>">7D</a>
          <a href="?range=30" class="<?= $range === 30 ? 'active' : '' ?>">30D</a>
          <a href="?range=90" class="<?= $range === 90 ? 'active' : '' ?>">90D</a>
        </div>
      </div>

      <svg class="pulse-divider" viewBox="0 0 1200 34" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0,17 L1200,17"></path>
        <path class="live" d="M0,17 L120,17 L132,4 L144,30 L156,17 L300,17 L312,9 L324,25 L336,17 L520,17 L532,2 L544,32 L556,17 L760,17 L772,7 L784,27 L796,17 L1000,17 L1012,4 L1024,30 L1036,17 L1200,17"></path>
      </svg>

      <!-- ================= HEADLINE KPIs ================= -->
      <div class="stat-grid">
        <div class="stat-card">
          <div class="top-row">
            <div class="icon-wrap"><i class="fa-solid fa-users"></i></div>
            <span class="delta <?= $userGrowthPct >= 0 ? 'up' : 'down' ?>"><i class="fa-solid fa-arrow-<?= $userGrowthPct >= 0 ? 'up' : 'down' ?>"></i> <?= e((string) abs($userGrowthPct)) ?>%</span>
          </div>
          <h2><?= e(number_format($totalUsers)) ?></h2>
          <p class="label">Total users</p>
        </div>
        <div class="stat-card">
          <div class="top-row">
            <div class="icon-wrap"><i class="fa-solid fa-truck-fast"></i></div>
            <span class="delta <?= $bookingGrowthPct >= 0 ? 'up' : 'down' ?>"><i class="fa-solid fa-arrow-<?= $bookingGrowthPct >= 0 ? 'up' : 'down' ?>"></i> <?= e((string) abs($bookingGrowthPct)) ?>%</span>
          </div>
          <h2><?= e(number_format($totalBookings)) ?></h2>
          <p class="label">Total bookings</p>
        </div>
        <div class="stat-card accent">
          <div class="top-row">
            <div class="icon-wrap"><i class="fa-solid fa-indian-rupee-sign"></i></div>
            <span class="delta up"><i class="fa-solid fa-circle"></i> <?= $range ?>d</span>
          </div>
          <h2>₹<?= e(number_format($revenueRange, 0)) ?></h2>
          <p class="label">Revenue (last <?= $range ?>d)</p>
        </div>
        <div class="stat-card">
          <div class="top-row">
            <div class="icon-wrap"><i class="fa-solid fa-wallet"></i></div>
            <span class="delta down"><i class="fa-solid fa-triangle-exclamation"></i> due</span>
          </div>
          <h2>₹<?= e(number_format($outstandingAmt, 0)) ?></h2>
          <p class="label">Outstanding balance</p>
        </div>
      </div>

      <div class="kpi-row" style="margin-top:18px;">
        <div class="kpi-box">
          <div class="kpi-label">Avg. booking value</div>
          <div class="kpi-value">₹<?= e(number_format($avgBookingValue, 0)) ?></div>
        </div>
        <div class="kpi-box">
          <div class="kpi-label">Delivered rate</div>
          <div class="kpi-value"><?= e((string) $onTimeRate) ?>%</div>
        </div>
        <div class="kpi-box">
          <div class="kpi-label">Avg. transit days</div>
          <div class="kpi-value"><?= e(number_format($avgDeliveryDays, 1)) ?></div>
        </div>
        <div class="kpi-box">
          <div class="kpi-label">Admin actions (<?= $range ?>d)</div>
          <div class="kpi-value"><?= e(number_format($actionsRange)) ?></div>
        </div>
        <div class="kpi-box">
          <div class="kpi-label">Failed logins (24h)</div>
          <div class="kpi-value" style="color:<?= $failedLogins24h > 0 ? '#c0362c' : 'inherit' ?>;"><?= e(number_format($failedLogins24h)) ?></div>
        </div>
      </div>

      <!-- ================= USERS ================= -->
      <div class="analytics-section-title">Users</div>
      <div class="content-grid">
        <div class="panel">
          <div class="panel-head">
            <div><h3>Signups over time</h3><div class="muted">Last <?= $range ?> days</div></div>
          </div>
          <div class="chart-wrap"><canvas id="userChart"></canvas></div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Account status</h3><span class="muted">All users</span></div>
          <div class="chart-wrap" style="height:170px;"><canvas id="userStatusDonut"></canvas></div>
          <div style="margin-top:14px;">
            <div class="progress-row"><div class="pr-top"><span>Active</span><strong><?= e((string) $activeUsers) ?></strong></div><div class="progress"><span style="width:<?= $totalUsers ? $activeUsers / $totalUsers * 100 : 0 ?>%"></span></div></div>
            <div class="progress-row"><div class="pr-top"><span>Inactive</span><strong><?= e((string) $inactiveUsers) ?></strong></div><div class="progress danger"><span style="width:<?= $totalUsers ? $inactiveUsers / $totalUsers * 100 : 0 ?>%"></span></div></div>
            <div class="progress-row"><div class="pr-top"><span>Pending</span><strong><?= e((string) $pendingUsers) ?></strong></div><div class="progress accent"><span style="width:<?= $totalUsers ? $pendingUsers / $totalUsers * 100 : 0 ?>%"></span></div></div>
          </div>
        </div>
      </div>

      <!-- ================= TRANSPORT ================= -->
      <div class="analytics-section-title">Transport &amp; Revenue</div>
      <div class="content-grid">
        <div class="panel">
          <div class="panel-head">
            <div>
              <h3 id="bookChartTitle">Bookings, last <?= $range ?> days</h3>
              <div class="muted" id="bookChartSub">Daily new bookings</div>
            </div>
            <div class="tabs">
              <button class="active" type="button" data-series="bookings">Bookings</button>
              <button type="button" data-series="revenue">Revenue</button>
            </div>
          </div>
          <div class="chart-wrap"><canvas id="bookingChart"></canvas></div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Booking status</h3><span class="muted">All time</span></div>
          <div class="chart-wrap" style="height:170px;"><canvas id="statusDonut"></canvas></div>
          <div class="mini-list" style="margin-top:10px;">
            <?php foreach ($statusBreakdown as $s): ?>
              <div class="mini-row">
                <span><span class="legend-dot" style="background:<?= e($statusColorMap[$s['status']] ?? '#94a3a8') ?>"></span><?= e(ucwords(str_replace('_',' ',(string) $s['status']))) ?></span>
                <strong><?= (int) $s['c'] ?></strong>
              </div>
            <?php endforeach; ?>
            <?php if (!$statusBreakdown): ?><p style="color:var(--text-muted);text-align:center;padding:16px 0;">No bookings yet.</p><?php endif; ?>
          </div>
        </div>
      </div>

      <div class="content-grid">
        <div class="panel">
          <div class="panel-head"><h3>Payment status</h3><span class="muted">All bookings</span></div>
          <div class="chart-wrap" style="height:170px;"><canvas id="paymentDonut"></canvas></div>
          <div class="mini-list" style="margin-top:10px;">
            <?php foreach ($paymentBreakdown as $p): ?>
              <div class="mini-row">
                <span><span class="legend-dot" style="background:<?= e($paymentColorMap[$p['payment_status']] ?? '#94a3a8') ?>"></span><?= e(ucfirst((string) $p['payment_status'])) ?></span>
                <strong><?= (int) $p['c'] ?></strong>
              </div>
            <?php endforeach; ?>
            <?php if (!$paymentBreakdown): ?><p style="color:var(--text-muted);text-align:center;padding:16px 0;">No data yet.</p><?php endif; ?>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Cargo type mix</h3><span class="muted">Top 6</span></div>
          <div class="chart-wrap" style="height:220px;"><canvas id="cargoChart"></canvas></div>
        </div>
      </div>

      <div class="content-grid">
        <div class="panel">
          <div class="panel-head"><h3>Top routes</h3><span class="muted">By trip count</span></div>
          <div class="mini-list">
            <?php if (!$topRoutes): ?>
              <p style="color:var(--text-muted);text-align:center;padding:24px 0;">No route data yet.</p>
            <?php else: foreach ($topRoutes as $r): ?>
              <div class="mini-row">
                <span class="name"><?= e($r['pickup_city']) ?> <i class="fa-solid fa-arrow-right" style="font-size:.7rem;color:var(--text-muted);margin:0 4px;"></i> <?= e($r['drop_city']) ?></span>
                <span class="val"><strong><?= (int) $r['trips'] ?> trips</strong><span class="sub">₹<?= e(number_format((float) $r['revenue'], 0)) ?></span></span>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Top customers</h3><span class="muted">By revenue</span></div>
          <div class="mini-list">
            <?php if (!$topCustomers): ?>
              <p style="color:var(--text-muted);text-align:center;padding:24px 0;">No customer data yet.</p>
            <?php else: foreach ($topCustomers as $c): ?>
              <div class="mini-row">
                <span class="name"><?= e($c['customer_name']) ?><span class="sub" style="display:block;"><?= (int) $c['bookings'] ?> booking<?= (int) $c['bookings'] === 1 ? '' : 's' ?></span></span>
                <strong>₹<?= e(number_format((float) $c['revenue'], 0)) ?></strong>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

      <div class="content-grid">
        <div class="panel">
          <div class="panel-head"><h3>Driver performance</h3><span class="muted">By bookings assigned</span></div>
          <table class="data-table">
            <thead><tr><th>Driver</th><th>Bookings</th><th>Delivered</th><th>Rate</th></tr></thead>
            <tbody>
              <?php if (!$driverPerformance): ?>
                <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:24px 0;">No drivers found.</td></tr>
              <?php else: foreach ($driverPerformance as $d): ?>
                <?php $rate = $d['bookings'] > 0 ? round($d['delivered'] / $d['bookings'] * 100) : 0; ?>
                <tr>
                  <td><?= e($d['driver_name'] ?? 'Unknown') ?></td>
                  <td><?= (int) $d['bookings'] ?></td>
                  <td><?= (int) $d['delivered'] ?></td>
                  <td><span class="badge badge-<?= $rate >= 70 ? 'success' : ($rate >= 40 ? 'warning' : 'muted') ?>"><?= $rate ?>%</span></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Vehicle utilization</h3><span class="muted">By trips</span></div>
          <table class="data-table">
            <thead><tr><th>Vehicle</th><th>Type</th><th>Trips</th></tr></thead>
            <tbody>
              <?php if (!$vehicleUtilization): ?>
                <tr><td colspan="3" style="text-align:center;color:var(--text-muted);padding:24px 0;">No vehicles found.</td></tr>
              <?php else: foreach ($vehicleUtilization as $v): ?>
                <tr>
                  <td><?= e($v['registration_number'] ?? '—') ?></td>
                  <td style="color:var(--text-secondary);"><?= e($v['vehicle_type'] ?? '—') ?></td>
                  <td><?= (int) $v['trips'] ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ================= BLOG ================= -->
      <div class="analytics-section-title">Content</div>
      <div class="content-grid">
        <div class="panel">
          <div class="panel-head"><h3>Posts published, last 6 months</h3><span class="muted"><?= (int) $totalPosts ?> total</span></div>
          <div class="chart-wrap"><canvas id="blogChart"></canvas></div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Content snapshot</h3><span class="muted">Live</span></div>
          <div class="system-item"><div class="left"><i class="fa-solid fa-newspaper"></i> Published posts</div><strong><?= (int) $publishedPosts ?></strong></div>
          <div class="system-item"><div class="left"><i class="fa-regular fa-file-lines"></i> Drafts</div><strong><?= (int) $draftPosts ?></strong></div>
          <div class="system-item"><div class="left"><i class="fa-regular fa-image"></i> Total photos</div><strong><?= (int) $totalPhotos ?></strong></div>
          <div class="system-item"><div class="left"><i class="fa-solid fa-chart-line"></i> Avg photos/post</div><strong><?= $totalPosts ? round($totalPhotos / $totalPosts, 1) : 0 ?></strong></div>
        </div>
      </div>

      <!-- ================= ADMIN ACTIVITY ================= -->
      <div class="analytics-section-title">Admin Activity</div>
      <div class="content-grid">
        <div class="panel">
          <div class="panel-head"><h3>Most active admins</h3><span class="muted">Last <?= $range ?> days</span></div>
          <table class="data-table">
            <thead><tr><th>Admin</th><th>Actions</th><th>Last action</th></tr></thead>
            <tbody>
              <?php if (!$adminActivity): ?>
                <tr><td colspan="3" style="text-align:center;color:var(--text-muted);padding:24px 0;">No admins found.</td></tr>
              <?php else: foreach ($adminActivity as $a): ?>
                <tr>
                  <td><?= e($a['username'] ?? 'Unknown') ?></td>
                  <td><?= (int) $a['actions'] ?></td>
                  <td><span class="mono-time"><?= e($a['last_action'] ?? '—') ?></span></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Top actions</h3><span class="muted">Last <?= $range ?> days</span></div>
          <div class="mini-list">
            <?php if (!$topActions): ?>
              <p style="color:var(--text-muted);text-align:center;padding:24px 0;">No activity recorded.</p>
            <?php else: foreach ($topActions as $t): ?>
              <div class="mini-row">
                <span class="name"><?= e(ucwords(str_replace('_',' ',(string) $t['action']))) ?></span>
                <strong><?= (int) $t['c'] ?></strong>
              </div>
            <?php endforeach; endif; ?>
          </div>
        </div>
      </div>

      <div class="dash-footer">
        <span>&copy; <?= date('Y') ?> Biome Control Panel. All rights reserved.</span>
        <span><a href="settings.php">Settings</a> &nbsp;·&nbsp; <a href="security.php">Security</a> &nbsp;·&nbsp; <a href="logs.php">Activity logs</a></span>
      </div>

    </main>
  </div>
</div>

<script>
(function () {
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) toggle.addEventListener('click', () => sidebar.classList.toggle('open'));

  Chart.defaults.color = '#5c7360';
  Chart.defaults.font.family = "Inter, sans-serif";
  Chart.defaults.borderColor = '#e3ece4';

  function lineChart(canvasId, labels, data, label, color) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 230);
    gradient.addColorStop(0, color + '4d');
    gradient.addColorStop(1, color + '00');
    return new Chart(ctx, {
      type: 'line',
      data: { labels, datasets: [{ label, data, borderColor: color, backgroundColor: gradient, fill: true, tension: .4, pointRadius: 0, pointHoverRadius: 5, pointHoverBackgroundColor: color, pointHoverBorderColor: '#fff', borderWidth: 2 }] },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
        scales: { x: { grid: { display: false }, ticks: { font: { size: 11 } } }, y: { beginAtZero: true, grid: { color: '#e3ece4' }, ticks: { font: { size: 11 }, precision: 0 } } } }
    });
  }

  function donutChart(canvasId, labels, data, colors) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return null;
    return new Chart(ctx, {
      type: 'doughnut',
      data: { labels, datasets: [{ data, backgroundColor: colors, borderColor: '#ffffff', borderWidth: 3, hoverOffset: 6 }] },
      options: { responsive: true, maintainAspectRatio: false, cutout: '72%',
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle', font: { size: 11.5 } } } } }
    });
  }

  // ---- Users ----
  lineChart('userChart', <?= json_encode($userLabels) ?>, <?= json_encode($userData) ?>, 'New users', '#1b7a34');
  donutChart('userStatusDonut', ['Active','Inactive','Pending'], [<?= $activeUsers ?>, <?= $inactiveUsers ?>, <?= $pendingUsers ?>], ['#1b7a34','#c0362c','#b3791b']);

  // ---- Bookings / revenue (tab-switchable) ----
  const bookingLabels  = <?= json_encode($bookingLabels) ?>;
  const bookingCounts  = <?= json_encode($bookingCounts) ?>;
  const bookingRevenue = <?= json_encode($bookingRevenue) ?>;
  let bookingChart = lineChart('bookingChart', bookingLabels, bookingCounts, 'Bookings', '#1b7a34');

  document.querySelectorAll('.tabs button[data-series]').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tabs button[data-series]').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const isRevenue = btn.dataset.series === 'revenue';
      document.getElementById('bookChartTitle').textContent = isRevenue ? 'Revenue, last <?= $range ?> days' : 'Bookings, last <?= $range ?> days';
      document.getElementById('bookChartSub').textContent = isRevenue ? 'Daily booking value (₹)' : 'Daily new bookings';
      if (bookingChart) bookingChart.destroy();
      bookingChart = lineChart('bookingChart', bookingLabels, isRevenue ? bookingRevenue : bookingCounts, isRevenue ? 'Revenue (₹)' : 'Bookings', isRevenue ? '#b3791b' : '#1b7a34');
    });
  });

  // ---- Status / payment donuts ----
  donutChart('statusDonut',
    <?= json_encode(array_map(fn($s) => ucwords(str_replace('_',' ',(string) $s['status'])), $statusBreakdown)) ?>,
    <?= json_encode(array_map(fn($s) => (int) $s['c'], $statusBreakdown)) ?>,
    <?= json_encode(array_map(fn($s) => $statusColorMap[$s['status']] ?? '#94a3a8', $statusBreakdown)) ?>
  );
  donutChart('paymentDonut',
    <?= json_encode(array_map(fn($p) => ucfirst((string) $p['payment_status']), $paymentBreakdown)) ?>,
    <?= json_encode(array_map(fn($p) => (int) $p['c'], $paymentBreakdown)) ?>,
    <?= json_encode(array_map(fn($p) => $paymentColorMap[$p['payment_status']] ?? '#94a3a8', $paymentBreakdown)) ?>
  );

  // ---- Cargo mix (bar) ----
  const cargoCtx = document.getElementById('cargoChart');
  if (cargoCtx) {
    new Chart(cargoCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode(array_column($cargoBreakdown, 'cargo_type')) ?>,
        datasets: [{ data: <?= json_encode(array_map('intval', array_column($cargoBreakdown, 'c'))) ?>, backgroundColor: '#1b7a34', borderRadius: 6, maxBarThickness: 34 }]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
        scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: '#e3ece4' }, ticks: { precision: 0 } } } }
    });
  }

  // ---- Blog posts per month ----
  const blogCtx = document.getElementById('blogChart');
  if (blogCtx) {
    new Chart(blogCtx, {
      type: 'bar',
      data: {
        labels: <?= json_encode($blogLabels) ?>,
        datasets: [{ data: <?= json_encode($blogData) ?>, backgroundColor: '#2e9e4f', borderRadius: 6, maxBarThickness: 34 }]
      },
      options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
        scales: { x: { grid: { display: false } }, y: { beginAtZero: true, grid: { color: '#e3ece4' }, ticks: { precision: 0 } } } }
    });
  }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>