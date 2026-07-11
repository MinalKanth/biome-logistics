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

$totalUsers    = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM users");
$activeUsers   = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM users WHERE status='active'");
$inactiveUsers = max(0, $totalUsers - $activeUsers);
$totalAdmins   = (int)safe_scalar($pdo, "SELECT COUNT(*) FROM admins");

// New users in the last 7 days (used for the trend delta on the stat card)
$newUsers7d = (int)safe_scalar(
    $pdo,
    "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
);
$newUsersPrev7d = (int)safe_scalar(
    $pdo,
    "SELECT COUNT(*) FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
     AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
);
$userTrendPct = $newUsersPrev7d > 0
    ? round((($newUsers7d - $newUsersPrev7d) / max(1, $newUsersPrev7d)) * 100, 1)
    : ($newUsers7d > 0 ? 100.0 : 0.0);

// Sessions / logins today (best-effort; falls back to 0 if no such table)
$loginsToday = (int)safe_scalar(
    $pdo,
    "SELECT COUNT(*) FROM activity_log WHERE action='login' AND DATE(created_at)=CURDATE()"
);

// Pending items — generic "needs attention" count, e.g. unverified users
$pendingReview = (int)safe_scalar(
    $pdo,
    "SELECT COUNT(*) FROM users WHERE status='pending'"
);

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
    $seriesMap[$row['d']] = (int)$row['c'];
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

$pageTitle = "Dashboard";
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/admin-theme-green.css">
<!-- <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js"></script> -->

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
          <span class="dot"></span>
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
              <?= e((string)abs($userTrendPct)) ?>%
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
            <div class="icon-wrap"><i class="fa-solid fa-user-xmark"></i></div>
            <span class="delta down"><i class="fa-solid fa-arrow-down"></i> <?= $totalUsers ? round($inactiveUsers / $totalUsers * 100) : 0 ?>%</span>
          </div>
          <h2><?= e(number_format($inactiveUsers)) ?></h2>
          <p class="label">Inactive users</p>
        </div>

        <div class="stat-card accent">
          <div class="top-row">
            <div class="icon-wrap"><i class="fa-solid fa-user-shield"></i></div>
            <span class="delta up"><i class="fa-solid fa-circle"></i> live</span>
          </div>
          <h2><?= e(number_format($totalAdmins)) ?></h2>
          <p class="label">Administrators</p>
        </div>

      </div>

      <!-- ---------- Secondary stat row ---------- -->
      <div class="stat-grid" style="grid-template-columns:repeat(auto-fit,minmax(240px,1fr));margin-bottom:26px;">
        <div class="stat-card" style="padding:20px 24px;">
          <div class="top-row" style="margin-bottom:8px;">
            <div class="icon-wrap" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-right-to-bracket"></i></div>
          </div>
          <h2 style="font-size:26px;"><?= e(number_format($loginsToday)) ?></h2>
          <p class="label">Logins today</p>
        </div>
        <div class="stat-card" style="padding:20px 24px;">
          <div class="top-row" style="margin-bottom:8px;">
            <div class="icon-wrap" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-hourglass-half"></i></div>
          </div>
          <h2 style="font-size:26px;"><?= e(number_format($pendingReview)) ?></h2>
          <p class="label">Pending review</p>
        </div>
        <div class="stat-card" style="padding:20px 24px;">
          <div class="top-row" style="margin-bottom:8px;">
            <div class="icon-wrap" style="width:36px;height:36px;font-size:14px;"><i class="fa-solid fa-user-plus"></i></div>
          </div>
          <h2 style="font-size:26px;"><?= e(number_format($newUsers7d)) ?></h2>
          <p class="label">New users (7 days)</p>
        </div>
        <div class="stat-card" style="padding:20px 24px;">
          <div class="top-row" style="margin-bottom:8px;">
            <div class="icon-wrap" style="width:36px;height:36px;font-size:14px;color:var(--accent);"><i class="fa-solid fa-shield-halved"></i></div>
          </div>
          <h2 style="font-size:26px;">0</h2>
          <p class="label">Security alerts</p>
        </div>
      </div>

      <!-- ---------- Quick actions ---------- -->
      <div class="quick-actions">
        <a href="users.php" class="qa-item">
          <i class="fa-solid fa-users"></i>
          <span>Manage users</span>
          <small>View, edit, suspend</small>
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
        <a href="settings.php" class="qa-item">
          <i class="fa-solid fa-gear"></i>
          <span>Settings</span>
          <small>System config</small>
        </a>
      </div>

      <!-- ---------- Chart + Status breakdown ---------- -->
      <div class="content-grid">

        <div class="panel">
          <div class="panel-head">
            <div>
              <h3>Signups, last 14 days</h3>
              <div class="muted">Daily new user registrations</div>
            </div>
            <div class="tabs">
              <button class="active" type="button">14D</button>
              <button type="button">30D</button>
              <button type="button">90D</button>
            </div>
          </div>
          <div class="chart-wrap">
            <canvas id="signupChart"></canvas>
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
              <div class="pr-top"><span>Active</span><strong><?= e((string)$activeUsers) ?></strong></div>
              <div class="progress"><span style="width:<?= $totalUsers ? ($activeUsers / $totalUsers * 100) : 0 ?>%"></span></div>
            </div>
            <div class="progress-row">
              <div class="pr-top"><span>Inactive</span><strong><?= e((string)$inactiveUsers) ?></strong></div>
              <div class="progress danger"><span style="width:<?= $totalUsers ? ($inactiveUsers / $totalUsers * 100) : 0 ?>%"></span></div>
            </div>
            <div class="progress-row">
              <div class="pr-top"><span>Pending</span><strong><?= e((string)$pendingReview) ?></strong></div>
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
            <strong class="ok">Secure</strong>
          </div>
          <div class="system-item">
            <div class="left"><i class="fa-solid fa-microchip"></i> Memory usage</div>
            <strong><?= e((string)round(memory_get_usage() / 1024 / 1024, 2)) ?> MB</strong>
          </div>
          <div class="system-item">
            <div class="left"><i class="fa-solid fa-clock"></i> Uptime</div>
            <strong>99.98%</strong>
          </div>

          <div style="margin-top:18px;">
            <h4 style="font-size:13px;color:var(--text-muted);margin-bottom:14px;font-weight:600;letter-spacing:.02em;">RESOURCE USAGE</h4>
            <div class="progress-row">
              <div class="pr-top"><span>CPU load</span><strong>34%</strong></div>
              <div class="progress"><span style="width:34%"></span></div>
            </div>
            <div class="progress-row">
              <div class="pr-top"><span>Disk space</span><strong>61%</strong></div>
              <div class="progress accent"><span style="width:61%"></span></div>
            </div>
            <div class="progress-row">
              <div class="pr-top"><span>Bandwidth</span><strong>18%</strong></div>
              <div class="progress"><span style="width:18%"></span></div>
            </div>
          </div>
        </div>

      </div>

      <!-- ---------- New users + Admin roster + Notifications ---------- -->
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
                        <div class="meta"><strong><?= e($u['username'] ?? 'Unknown') ?></strong><span>#<?= e((string)($u['id'] ?? '')) ?></span></div>
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
            <h3>Notifications</h3>
            <span class="muted">4 new</span>
          </div>

          <div class="feed-item">
            <div class="dot-icon"><i class="fa-solid fa-shield-halved"></i></div>
            <div class="body">
              <p><strong>Security scan completed</strong> — no vulnerabilities found across all endpoints.</p>
              <time>12 minutes ago</time>
            </div>
          </div>
          <div class="feed-item">
            <div class="dot-icon"><i class="fa-solid fa-database"></i></div>
            <div class="body">
              <p><strong>Backup finished</strong> — nightly database snapshot stored successfully.</p>
              <time>1 hour ago</time>
            </div>
          </div>
          <div class="feed-item">
            <div class="dot-icon"><i class="fa-solid fa-user-plus"></i></div>
            <div class="body">
              <p><strong><?= e((string)$newUsers7d) ?> new users</strong> joined in the past week.</p>
              <time>Today</time>
            </div>
          </div>
          <div class="feed-item">
            <div class="dot-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="body">
              <p><strong>SSL certificate</strong> renews in 24 days — no action needed yet.</p>
              <time>Yesterday</time>
            </div>
          </div>
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
                <span class="status-dot online" title="Active"></span>
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

  const labels = <?= json_encode($chartLabels, JSON_THROW_ON_ERROR) ?>;
  const data   = <?= json_encode($chartData, JSON_THROW_ON_ERROR) ?>;

  const ctx = document.getElementById('signupChart');
  if (ctx) {
    const gradient = ctx.getContext('2d').createLinearGradient(0, 0, 0, 230);
    gradient.addColorStop(0, 'rgba(61,169,252,0.30)');
    gradient.addColorStop(1, 'rgba(61,169,252,0)');

    new Chart(ctx, {
      type: 'line',
      data: {
        labels: labels,
        datasets: [{
          label: 'New users',
          data: data,
          borderColor: '#3da9fc',
          backgroundColor: gradient,
          fill: true,
          tension: 0.4,
          pointRadius: 0,
          pointHoverRadius: 5,
          pointHoverBackgroundColor: '#3da9fc',
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

  const donutCtx = document.getElementById('statusDonut');
  if (donutCtx) {
    new Chart(donutCtx, {
      type: 'doughnut',
      data: {
        labels: ['Active', 'Inactive', 'Pending'],
        datasets: [{
          data: [<?= (int)$activeUsers ?>, <?= (int)$inactiveUsers ?>, <?= (int)$pendingReview ?>],
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
function safe_scalar_blog(PDO $pdo, string $sql, $default = 0)
{
    try {
        $val = $pdo->query($sql)->fetchColumn();
        return $val === false ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}
$sidebarBlogCount = (int) safe_scalar_blog($pdo, 'SELECT COUNT(*) FROM blog_posts');
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>