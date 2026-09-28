<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

/* =========================================================================
   DATA LAYER
   Every query below is intentionally defensive: if a table/column doesn't
   exist yet in a given install, we fall back to a sane default instead of
   fataling, so the dashboard always renders.
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

/* ---- Users ---- */
$totalUsers    = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM users");
$activeUsers   = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status='active'");
$inactiveUsers = max(0, $totalUsers - $activeUsers);
$totalAdmins   = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM admins");

$newUsers7d = (int) safe_scalar(
    $pdo,
    "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
);
$newUsersPrev7d = (int) safe_scalar(
    $pdo,
    "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
     AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
);
$userTrendPct = $newUsersPrev7d > 0
    ? round((($newUsers7d - $newUsersPrev7d) / max(1, $newUsersPrev7d)) * 100, 1)
    : ($newUsers7d > 0 ? 100.0 : 0.0);

$loginsToday = (int) safe_scalar(
    $pdo,
    "SELECT COUNT(*) FROM activity_log WHERE action='login' AND DATE(created_at)=CURDATE()"
);
$pendingReview = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status='pending'");

$recentLogs = safe_all($pdo, "
    SELECT al.action, al.details, al.ip_address, al.created_at, a.username
    FROM activity_log al
    LEFT JOIN admins a ON a.id = al.admin_id
    ORDER BY al.created_at DESC
    LIMIT 8
");

$recentUsers = safe_all($pdo, "
    SELECT id, username, email, status, created_at
    FROM users
    ORDER BY created_at DESC
    LIMIT 5
");

$adminList = safe_all($pdo, "
    SELECT id, username, role, last_login
    FROM admins
    ORDER BY last_login DESC
    LIMIT 5
");

// Signups per day for the last 14 days — feeds the trend chart
$signupSeries = safe_all($pdo, "
    SELECT DATE(created_at) AS d, COUNT(*) AS c
    FROM users
    WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(created_at)
    ORDER BY d ASC
");
$seriesMap = [];
foreach ($signupSeries as $row) {
    $seriesMap[$row['d']] = (int) $row['c'];
}
$chartLabels = [];
$chartData   = [];
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $chartLabels[] = date('M j', strtotime($day));
    $chartData[]   = $seriesMap[$day] ?? 0;
}

$statusBreakdown = [
    'Active'   => $activeUsers,
    'Inactive' => $inactiveUsers,
    'Pending'  => $pendingReview,
];

/* ---- Transport ---- */
$totalBookings = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL");
$bookingsInTransit = (int) safe_scalar(
    $pdo,
    "SELECT COUNT(*) FROM transport_bookings
     WHERE deleted_at IS NULL AND status IN ('picked_up','in_transit','out_for_delivery')"
);
$revenueThisMonth = (float) safe_scalar(
    $pdo,
    "SELECT COALESCE(SUM(paid_amount),0) FROM transport_bookings
     WHERE deleted_at IS NULL AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())"
);
$pendingPayments = (int) safe_scalar(
    $pdo,
    "SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL AND payment_status IN ('unpaid','partial')"
);

$recentBookings = safe_all($pdo, "
    SELECT id, tracking_id, customer_name, status, payment_status, grand_total, created_at
    FROM transport_bookings
    WHERE deleted_at IS NULL
    ORDER BY created_at DESC
    LIMIT 5
");

// Bookings + revenue per day, last 14 days — feeds the second chart tab
$bookingSeries = safe_all($pdo, "
    SELECT DATE(created_at) AS d, COUNT(*) AS bookings, COALESCE(SUM(grand_total),0) AS revenue
    FROM transport_bookings
    WHERE deleted_at IS NULL AND created_at >= DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    GROUP BY DATE(created_at)
    ORDER BY d ASC
");
$bookingMap = [];
foreach ($bookingSeries as $row) {
    $bookingMap[$row['d']] = ['bookings' => (int) $row['bookings'], 'revenue' => (float) $row['revenue']];
}
$bookingChartLabels  = [];
$bookingChartCounts  = [];
$bookingChartRevenue = [];
for ($i = 13; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-{$i} days"));
    $bookingChartLabels[]  = date('M j', strtotime($day));
    $bookingChartCounts[]  = $bookingMap[$day]['bookings'] ?? 0;
    $bookingChartRevenue[] = $bookingMap[$day]['revenue'] ?? 0;
}

$statusBadgeMap = [
    'pending' => 'muted', 'confirmed' => 'success', 'driver_assigned' => 'success',
    'picked_up' => 'warning', 'in_transit' => 'warning', 'out_for_delivery' => 'warning',
    'delivered' => 'success', 'cancelled' => 'danger', 'returned' => 'danger',
];
$paymentBadgeMap = ['unpaid' => 'danger', 'partial' => 'warning', 'paid' => 'success', 'refunded' => 'muted'];

/* ---- Blog ---- */
$sidebarBlogCount = (int) safe_scalar($pdo, "SELECT COUNT(*) FROM blog_posts");
$recentPosts = safe_all($pdo, "
    SELECT id, title, event_date, status
    FROM blog_posts
    ORDER BY event_date DESC, id DESC
    LIMIT 4
");

/* ---- Notifications ----
   Falls back to an empty list (not fake copy) if the table doesn't exist yet.
   Create it with:
     CREATE TABLE notifications (
       id INT AUTO_INCREMENT PRIMARY KEY,
       title VARCHAR(150), body VARCHAR(255),
       icon VARCHAR(50) DEFAULT 'fa-solid fa-bell',
       created_at DATETIME DEFAULT CURRENT_TIMESTAMP
     ); */
$notifications = safe_all($pdo, "
    SELECT title, body, icon, created_at
    FROM notifications
    ORDER BY created_at DESC
    LIMIT 5
");

/* ---- System resource usage (best-effort, Linux-only for load/disk) ---- */
$cpuLoadPct = 0;
if (function_exists('sys_getloadavg')) {
    $load = sys_getloadavg();
    $cores = (int) (shell_exec('nproc') ?: 1) ?: 1;
    $cpuLoadPct = $load ? min(100, (int) round(($load[0] / max(1, $cores)) * 100)) : 0;
}
$diskTotal = @disk_total_space('/') ?: 0;
$diskFree  = @disk_free_space('/') ?: 0;
$diskUsedPct = $diskTotal > 0 ? (int) round((($diskTotal - $diskFree) / $diskTotal) * 100) : 0;

/* ---- Security alerts: failed logins in the last 24h ---- */
$securityAlerts = (int) safe_scalar(
    $pdo,
    "SELECT COUNT(*) FROM activity_log WHERE action='failed_login' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
);

$pageTitle = "Dashboard";
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/admin-theme-green.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script>

<div class="app-shell">

<?php require __DIR__ . '/includes/sidebar.php'; ?>

  <!-- ===================== MAIN COLUMN ===================== -->
  <div class="main-col">

    <!-- ---------- Topbar ---------- -->
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
        <div class="icon-btn" title="Notifications">
          <i class="fa-regular fa-bell"></i>
          <?php if (count($notifications) > 0): ?><span class="dot"></span><?php endif; ?>
        </div>
        <div class="icon-btn" title="Help &amp; documentation">
          <i class="fa-regular fa-circle-question"></i>
        </div>
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

    <!-- ---------- Content ---------- -->
    <main class="content">

      <div class="page-head">
        <div>
          <div class="breadcrumb">Biome <span class="sep">/</span> <span class="current">Dashboard</span></div>
          <h1>Welcome back, <?= e($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Admin') ?> 👋</h1>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <div class="datetime btn-ghost btn" style="cursor:default;">
            <i class="fa-regular fa-calendar"></i>
            <?= date("l, d F Y · h:i A") ?>
          </div>
          <a href="reports.php" class="btn btn-ghost"><i class="fa-solid fa-download"></i> Export report</a>
          <a href="users.php?action=new" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add user</a>
        </div>
      </div>

      <!-- Signature element: live activity pulse -->
      <svg class="pulse-divider" viewBox="0 0 1200 34" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0,17 L1200,17"></path>
        <path class="live" d="M0,17 L120,17 L132,4 L144,30 L156,17 L300,17 L312,9 L324,25 L336,17 L520,17 L532,2 L544,32 L556,17 L760,17 L772,7 L784,27 L796,17 L1000,17 L1012,4 L1024,30 L1036,17 L1200,17"></path>
      </svg>

      <!-- ---------- Stat cards ---------- -->
      <div class="stat-grid">

        <div class="stat-card">
          <div class="top-row">
            <div class="icon-wrap"><i class="fa-solid fa-users"></i></div>
            <span class="delta <?= $userTrendPct >= 0 ? 'up' : 'down' ?>">
              <i class="fa-solid fa-arrow-<?= $userTrendPct >= 0 ? 'up' : 'down' ?>"></i>
              <?= e((string) abs($userTrendPct)) ?>%
            </span>
          </div>
          <h2><?= e(number_format($totalUsers)) ?></h2>
          <p class="label">Total users</p>
        </div>

        <div class="stat-card">
          <div class="top-row">
            <div class="icon-wrap"><i class="fa-solid fa-user-check"></i></div>
            <span class="delta up"><i class="fa-solid fa-arrow-up"></i> <?= $totalUsers ? round($activeUsers / $totalUsers * 100) : 0 ?>%</span>
          </div>
          <h2><?= e(number_format($activeUsers)) ?></h2>
          <p class="label">Active users</p>
        </div>

        <div class="stat-card">
          <div class="top-row">
            <div class="icon-wrap"><i class="fa-solid fa-truck-fast"></i></div>
            <span class="delta up"><i class="fa-solid fa-circle"></i> live</span>
          </div>
          <h2><?= e(number_format($totalBookings)) ?></h2>
          <p class="label">Transport bookings</p>
        </div>

        <div class="stat-card accent">
          <div class="top-row">
            <div class="icon-wrap"><i class="fa-solid fa-indian-rupee-sign"></i></div>
            <span class="delta up"><i class="fa-solid fa-circle"></i> this month</span>
          </div>
          <h2>₹<?= e(number_format($revenueThisMonth, 2)) ?></h2>
          <p class="label">Revenue this month</p>
        </div>

      </div>

      <!-- ---------- Secondary stat row ---------- -->
      <div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));margin-bottom:26px;">
        <div class="stat-card" style="padding:20px 24px;">
          <div class="top-row" style="margin-bottom:8px;">
            <div class="icon-wrap" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-truck-ramp-box"></i></div>
          </div>
          <h2 style="font-size:26px;"><?= e(number_format($bookingsInTransit)) ?></h2>
          <p class="label">Bookings in transit</p>
        </div>
        <div class="stat-card" style="padding:20px 24px;">
          <div class="top-row" style="margin-bottom:8px;">
            <div class="icon-wrap" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-wallet"></i></div>
          </div>
          <h2 style="font-size:26px;"><?= e(number_format($pendingPayments)) ?></h2>
          <p class="label">Pending payments</p>
        </div>
        <div class="stat-card" style="padding:20px 24px;">
          <div class="top-row" style="margin-bottom:8px;">
            <div class="icon-wrap" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-right-to-bracket"></i></div>
          </div>
          <h2 style="font-size:26px;"><?= e(number_format($loginsToday)) ?></h2>
          <p class="label">Logins today</p>
        </div>
        <div class="stat-card" style="padding:20px 24px;">
          <div class="top-row" style="margin-bottom:8px;">
            <div class="icon-wrap" style="width:36px;height:36px;font-size:14px;color:<?= $securityAlerts > 0 ? '#c0362c' : 'var(--accent)' ?>;"><i class="fa-solid fa-shield-halved"></i></div>
          </div>
          <h2 style="font-size:26px;"><?= e(number_format($securityAlerts)) ?></h2>
          <p class="label">Security alerts (24h)</p>
        </div>
      </div>

      <!-- ---------- Quick actions ---------- -->
      <div class="quick-actions">
        <a href="users.php" class="qa-item">
          <i class="fa-solid fa-users"></i>
          <span>Manage users</span>
          <small>View, edit, suspend</small>
        </a>
        <a href="transport_manage.php" class="qa-item">
          <i class="fa-solid fa-truck-fast"></i>
          <span>Transport</span>
          <small>Bookings &amp; tracking</small>
        </a>
        <a href="admins.php" class="qa-item">
          <i class="fa-solid fa-user-shield"></i>
          <span>Admins</span>
          <small>Roles &amp; access</small>
        </a>
        <a href="logs.php" class="qa-item">
          <i class="fa-solid fa-clock-rotate-left"></i>
          <span>Activity logs</span>
          <small>Audit every action</small>
        </a>
        <a href="reports.php" class="qa-item">
          <i class="fa-solid fa-file-export"></i>
          <span>Reports</span>
          <small>Export &amp; schedule</small>
        </a>
        <a href="security.php" class="qa-item">
          <i class="fa-solid fa-shield-halved"></i>
          <span>Security</span>
          <small>Sessions &amp; 2FA</small>
        </a>
      </div>

      <!-- ---------- Chart + Status breakdown ---------- -->
      <div class="content-grid">

        <div class="panel">
          <div class="panel-head">
            <div>
              <h3 id="chartTitle">Signups, last 14 days</h3>
              <div class="muted" id="chartSubtitle">Daily new user registrations</div>
            </div>
            <div class="tabs">
              <button class="active" type="button" data-chart="signups">Signups</button>
              <button type="button" data-chart="bookings">Bookings</button>
              <button type="button" data-chart="revenue">Revenue</button>
            </div>
          </div>
          <div class="chart-wrap">
            <canvas id="mainChart"></canvas>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h3>User status</h3>
            <span class="muted">All accounts</span>
          </div>
          <div class="chart-wrap" style="height:170px;">
            <canvas id="statusDonut"></canvas>
          </div>
          <div style="margin-top:14px;">
            <div class="progress-row">
              <div class="pr-top"><span>Active</span><strong><?= e((string) $activeUsers) ?></strong></div>
              <div class="progress"><span style="width:<?= $totalUsers ? ($activeUsers / $totalUsers * 100) : 0 ?>%"></span></div>
            </div>
            <div class="progress-row">
              <div class="pr-top"><span>Inactive</span><strong><?= e((string) $inactiveUsers) ?></strong></div>
              <div class="progress danger"><span style="width:<?= $totalUsers ? ($inactiveUsers / $totalUsers * 100) : 0 ?>%"></span></div>
            </div>
            <div class="progress-row">
              <div class="pr-top"><span>Pending</span><strong><?= e((string) $pendingReview) ?></strong></div>
              <div class="progress accent"><span style="width:<?= $totalUsers ? ($pendingReview / $totalUsers * 100) : 0 ?>%"></span></div>
            </div>
          </div>
        </div>

      </div>

      <!-- ---------- Activity table + System overview ---------- -->
      <div class="content-grid">

        <div class="panel">
          <div class="panel-head">
            <h3>Recent activity</h3>
            <a href="logs.php" class="view-all">View all <i class="fa-solid fa-arrow-right" style="font-size:10px;"></i></a>
          </div>
          <table class="data-table">
            <thead>
              <tr>
                <th>Admin</th>
                <th>Action</th>
                <th>Details</th>
                <th>IP address</th>
                <th>Time</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentLogs)): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:30px 0;">No activity recorded yet.</td></tr>
              <?php else: ?>
                <?php foreach ($recentLogs as $log): ?>
                  <tr>
                    <td>
                      <div class="user-cell">
                        <div class="av"><?= e(strtoupper(substr($log['username'] ?? 'S', 0, 1))) ?></div>
                        <div class="meta"><strong><?= e($log['username'] ?? 'System') ?></strong></div>
                      </div>
                    </td>
                    <td><span class="badge badge-success"><?= e($log['action']) ?></span></td>
                    <td style="color:var(--text-secondary);"><?= e($log['details']) ?></td>
                    <td><span class="ip-tag"><?= e($log['ip_address']) ?></span></td>
                    <td><span class="mono-time"><?= e($log['created_at']) ?></span></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h3>System overview</h3>
            <span class="muted">Live</span>
          </div>

          <div class="system-item">
            <div class="left"><i class="fa-solid fa-code"></i> PHP version</div>
            <strong><?= e(phpversion()) ?></strong>
          </div>
          <div class="system-item">
            <div class="left"><i class="fa-solid fa-database"></i> Database</div>
            <strong class="ok">Connected</strong>
          </div>
          <div class="system-item">
            <div class="left"><i class="fa-solid fa-server"></i> Server</div>
            <strong><?= e(php_uname('n')) ?></strong>
          </div>
          <div class="system-item">
            <div class="left"><i class="fa-solid fa-shield-halved"></i> Security</div>
            <strong class="<?= $securityAlerts > 0 ? '' : 'ok' ?>"><?= $securityAlerts > 0 ? $securityAlerts . ' alert(s)' : 'Secure' ?></strong>
          </div>
          <div class="system-item">
            <div class="left"><i class="fa-solid fa-microchip"></i> Memory usage</div>
            <strong><?= e((string) round(memory_get_usage() / 1024 / 1024, 2)) ?> MB</strong>
          </div>

          <div style="margin-top:18px;">
            <h4 style="font-size:13px;color:var(--text-muted);margin-bottom:14px;font-weight:600;letter-spacing:.02em;">RESOURCE USAGE</h4>
            <div class="progress-row">
              <div class="pr-top"><span>CPU load</span><strong><?= (int) $cpuLoadPct ?>%</strong></div>
              <div class="progress"><span style="width:<?= (int) $cpuLoadPct ?>%"></span></div>
            </div>
            <div class="progress-row">
              <div class="pr-top"><span>Disk space</span><strong><?= (int) $diskUsedPct ?>%</strong></div>
              <div class="progress accent"><span style="width:<?= (int) $diskUsedPct ?>%"></span></div>
            </div>
          </div>
        </div>

      </div>

      <!-- ---------- Recent bookings + Notifications ---------- -->
      <div class="content-grid" style="grid-template-columns:1.65fr 1fr;">

        <div class="panel">
          <div class="panel-head">
            <h3>Recent bookings</h3>
            <a href="transport_manage.php" class="view-all">Manage bookings <i class="fa-solid fa-arrow-right" style="font-size:10px;"></i></a>
          </div>
          <table class="data-table">
            <thead>
              <tr>
                <th>Tracking ID</th>
                <th>Customer</th>
                <th>Status</th>
                <th>Payment</th>
                <th>Amount</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentBookings)): ?>
                <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:30px 0;">No bookings yet.</td></tr>
              <?php else: ?>
                <?php foreach ($recentBookings as $b): ?>
                  <tr>
                    <td><a href="transport_view.php?id=<?= (int) $b['id'] ?>" style="color:inherit;text-decoration:none;font-weight:600;"><?= e($b['tracking_id']) ?></a></td>
                    <td style="color:var(--text-secondary);"><?= e($b['customer_name']) ?></td>
                    <td><span class="badge badge-<?= e($statusBadgeMap[$b['status']] ?? 'muted') ?>"><?= e(ucwords(str_replace('_', ' ', (string) $b['status']))) ?></span></td>
                    <td><span class="badge badge-<?= e($paymentBadgeMap[$b['payment_status']] ?? 'muted') ?>"><?= e(ucfirst((string) $b['payment_status'])) ?></span></td>
                    <td>₹<?= e(number_format((float) $b['grand_total'], 2)) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h3>Notifications</h3>
            <span class="muted"><?= count($notifications) ?> new</span>
          </div>

          <?php if (empty($notifications)): ?>
            <p style="color:var(--text-muted);text-align:center;padding:24px 0;">No notifications yet.</p>
          <?php else: ?>
            <?php foreach ($notifications as $n): ?>
              <div class="feed-item">
                <div class="dot-icon"><i class="<?= e($n['icon'] ?: 'fa-solid fa-bell') ?>"></i></div>
                <div class="body">
                  <p><strong><?= e($n['title']) ?></strong> — <?= e($n['body']) ?></p>
                  <time><?= e((string) $n['created_at']) ?></time>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>

      <!-- ---------- Newest users + Recent blog posts ---------- -->
      <div class="content-grid" style="grid-template-columns:1.65fr 1fr;">

        <div class="panel">
          <div class="panel-head">
            <h3>Newest users</h3>
            <a href="users.php" class="view-all">Manage users <i class="fa-solid fa-arrow-right" style="font-size:10px;"></i></a>
          </div>
          <table class="data-table">
            <thead>
              <tr>
                <th>User</th>
                <th>Email</th>
                <th>Status</th>
                <th>Joined</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentUsers)): ?>
                <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:30px 0;">No users yet.</td></tr>
              <?php else: ?>
                <?php foreach ($recentUsers as $u): ?>
                  <?php
                    $status = $u['status'] ?? 'active';
                    $badgeClass = $status === 'active' ? 'badge-success' : ($status === 'pending' ? 'badge-warning' : 'badge-danger');
                  ?>
                  <tr>
                    <td>
                      <div class="user-cell">
                        <div class="av"><?= e(strtoupper(substr($u['username'] ?? 'U', 0, 1))) ?></div>
                        <div class="meta"><strong><?= e($u['username'] ?? 'Unknown') ?></strong><span>#<?= e((string) ($u['id'] ?? '')) ?></span></div>
                      </div>
                    </td>
                    <td style="color:var(--text-secondary);"><?= e($u['email'] ?? '—') ?></td>
                    <td><span class="badge <?= $badgeClass ?>"><?= e(ucfirst($status)) ?></span></td>
                    <td><span class="mono-time"><?= e($u['created_at'] ?? '—') ?></span></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div class="panel">
          <div class="panel-head">
            <h3>Recent blog posts</h3>
            <a href="content.php" class="view-all">Manage content <i class="fa-solid fa-arrow-right" style="font-size:10px;"></i></a>
          </div>
          <?php if (empty($recentPosts)): ?>
            <p style="color:var(--text-muted);text-align:center;padding:24px 0;">No posts yet.</p>
          <?php else: ?>
            <?php foreach ($recentPosts as $p): ?>
              <div class="feed-item">
                <div class="dot-icon"><i class="fa-solid fa-newspaper"></i></div>
                <div class="body">
                  <p><strong><?= e($p['title']) ?></strong></p>
                  <time><?= e((string) $p['event_date']) ?> &middot; <span class="badge badge-<?= $p['status'] === 'published' ? 'success' : 'muted' ?>"><?= e($p['status']) ?></span></time>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      </div>

      <!-- ---------- Admin roster ---------- -->
      <div class="panel" style="margin-bottom:24px;">
        <div class="panel-head">
          <h3>Administrators</h3>
          <a href="admins.php" class="view-all">View all <i class="fa-solid fa-arrow-right" style="font-size:10px;"></i></a>
        </div>
        <?php if (empty($adminList)): ?>
          <p style="color:var(--text-muted);text-align:center;padding:24px 0;">No administrators found.</p>
        <?php else: ?>
          <?php foreach ($adminList as $a): ?>
            <?php
              $lastLoginTs = !empty($a['last_login']) ? strtotime((string) $a['last_login']) : false;
              $isOnline = $lastLoginTs && (time() - $lastLoginTs) <= 900; // 15 minutes
            ?>
            <div class="admin-row">
              <div class="info">
                <div class="av"><?= e(strtoupper(substr($a['username'] ?? 'A', 0, 1))) ?></div>
                <div>
                  <strong><?= e($a['username'] ?? 'Unknown') ?></strong>
                  <span><?= e($a['role'] ?? 'Administrator') ?></span>
                </div>
              </div>
              <div style="display:flex;align-items:center;gap:10px;">
                <span class="mono-time">Last login: <?= e($a['last_login'] ?? '—') ?></span>
                <span class="status-dot <?= $isOnline ? 'online' : 'offline' ?>" title="<?= $isOnline ? 'Active' : 'Offline' ?>"></span>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- ---------- Footer ---------- -->
      <div class="dash-footer">
        <span>&copy; <?= date('Y') ?> Biome Control Panel. All rights reserved.</span>
        <span>
          <a href="settings.php">Settings</a> &nbsp;·&nbsp;
          <a href="security.php">Security</a> &nbsp;·&nbsp;
          <a href="logs.php">Activity logs</a>
        </span>
      </div>

    </main>
  </div>
</div>

<script>
(function () {
  // Mobile sidebar toggle
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('open');
    });
  }

  // Chart.js global defaults tuned for the navy/electric-blue theme
  Chart.defaults.color = '#9aa5bd';
  Chart.defaults.font.family = "Inter, sans-serif";
  Chart.defaults.borderColor = '#1b2438';

  const datasets = {
    signups: {
      title: 'Signups, last 14 days',
      subtitle: 'Daily new user registrations',
      labels: <?= json_encode($chartLabels, JSON_THROW_ON_ERROR) ?>,
      data: <?= json_encode($chartData, JSON_THROW_ON_ERROR) ?>,
      label: 'New users',
      color: '#3da9fc'
    },
    bookings: {
      title: 'Bookings, last 14 days',
      subtitle: 'Daily new transport bookings',
      labels: <?= json_encode($bookingChartLabels, JSON_THROW_ON_ERROR) ?>,
      data: <?= json_encode($bookingChartCounts, JSON_THROW_ON_ERROR) ?>,
      label: 'Bookings',
      color: '#34d399'
    },
    revenue: {
      title: 'Revenue, last 14 days',
      subtitle: 'Daily booking value (₹)',
      labels: <?= json_encode($bookingChartLabels, JSON_THROW_ON_ERROR) ?>,
      data: <?= json_encode($bookingChartRevenue, JSON_THROW_ON_ERROR) ?>,
      label: 'Revenue (₹)',
      color: '#fbbf67'
    }
  };

  const ctx = document.getElementById('mainChart');
  let mainChart = null;

  function renderChart(key) {
    const set = datasets[key];
    if (!ctx) return;

    document.getElementById('chartTitle').textContent = set.title;
    document.getElementById('chartSubtitle').textContent = set.subtitle;

    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 230);
    gradient.addColorStop(0, set.color + '4d');
    gradient.addColorStop(1, set.color + '00');

    if (mainChart) mainChart.destroy();
    mainChart = new Chart(ctx, {
      type: 'line',
      data: {
        labels: set.labels,
        datasets: [{
          label: set.label,
          data: set.data,
          borderColor: set.color,
          backgroundColor: gradient,
          fill: true,
          tension: 0.4,
          pointRadius: 0,
          pointHoverRadius: 5,
          pointHoverBackgroundColor: set.color,
          pointHoverBorderColor: '#0a0e17',
          borderWidth: 2
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 11 } } },
          y: { beginAtZero: true, grid: { color: '#1b2438' }, ticks: { font: { size: 11 }, precision: 0 } }
        }
      }
    });
  }

  document.querySelectorAll('.tabs button[data-chart]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      document.querySelectorAll('.tabs button[data-chart]').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      renderChart(btn.dataset.chart);
    });
  });

  renderChart('signups');

  const donutCtx = document.getElementById('statusDonut');
  if (donutCtx) {
    new Chart(donutCtx, {
      type: 'doughnut',
      data: {
        labels: ['Active', 'Inactive', 'Pending'],
        datasets: [{
          data: [<?= (int) $activeUsers ?>, <?= (int) $inactiveUsers ?>, <?= (int) $pendingReview ?>],
          backgroundColor: ['#34d399', '#f87171', '#fbbf67'],
          borderColor: '#131a2b',
          borderWidth: 3,
          hoverOffset: 6
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '72%',
        plugins: {
          legend: {
            position: 'bottom',
            labels: { boxWidth: 8, boxHeight: 8, usePointStyle: true, pointStyle: 'circle', font: { size: 11.5 } }
          }
        }
      }
    });
  }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>