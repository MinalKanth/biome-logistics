<?php
/**
 * Shared admin sidebar.
 * Requires $pdo (from bootstrap.php) to already be available in the including page.
 * Include with: require __DIR__ . '/includes/sidebar.php';
 */

$currentPage = basename($_SERVER['PHP_SELF']);

if (!function_exists('sidebar_safe_count')) {
    function sidebar_safe_count(PDO $pdo, string $sql): int
    {
        try {
            $val = $pdo->query($sql)->fetchColumn();
            return $val === false ? 0 : (int) $val;
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!isset($totalUsers)) {
    $totalUsers = sidebar_safe_count($pdo, 'SELECT COUNT(*) FROM users');
}
if (!isset($sidebarBlogCount)) {
    $sidebarBlogCount = sidebar_safe_count($pdo, 'SELECT COUNT(*) FROM blog_posts');
}
if (!isset($sidebarTransportCount)) {
    $sidebarTransportCount = sidebar_safe_count($pdo, 'SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL');
}

$navActive = static fn(string ...$pages): string => in_array($currentPage, $pages, true) ? ' active' : '';
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <div class="mark">A</div>
      <div class="name">Biome<br><small>Control Panel</small></div>
    </div>

    <div class="sidebar-section-label">Overview</div>
    <nav class="sidebar-nav">
      <ul>
        <li><a href="dashboard.php" class="nav-item<?= $navActive('dashboard.php') ?>"><i class="fa-solid fa-grid-2"></i> Dashboard</a></li>
        <li><a href="analytics.php" class="nav-item<?= $navActive('analytics.php') ?>"><i class="fa-solid fa-chart-line"></i> Analytics</a></li>
        <li><a href="reports.php" class="nav-item<?= $navActive('reports.php') ?>"><i class="fa-solid fa-file-lines"></i> Reports</a></li>
      </ul>

      <div class="sidebar-section-label">Manage</div>
      <ul>
        <li><a href="users.php" class="nav-item<?= $navActive('users.php') ?>"><i class="fa-solid fa-users"></i> Users <span class="pill"><?= e((string) $totalUsers) ?></span></a></li>
        <li><a href="bamboo_enquiries.php" class="nav-item<?= $navActive('bamboo_enquiries.php') ?>"><i class="fa-solid fa-user-shield"></i> Bamboo Enquiries</a></li>
        <li><a href="blog_manage.php" class="nav-item<?= $navActive('blog_manage.php') ?>"><i class="fa-solid fa-newspaper"></i> Blog Posts <span class="pill"><?= e((string) $sidebarBlogCount) ?></span></a></li>
      </ul>

      <!-- Transport -->
      <div class="sidebar-section-label">Transport Management</div>
      <ul>
        <li>
          <a href="transport_manage.php" class="nav-item<?= $navActive('transport_manage.php') ?>">
            <i class="fa-solid fa-truck-fast"></i>
            Bookings
            <span class="pill"><?= e((string) $sidebarTransportCount) ?></span>
          </a>
        </li>
        <li>
          <a href="transport_add.php" class="nav-item<?= $navActive('transport_add.php') ?>">
            <i class="fa-solid fa-plus"></i>
            Add Booking
          </a>
        </li>
        <li>
          <a href="timeline.php" class="nav-item<?= $navActive('timeline.php') ?>">
            <i class="fa-solid fa-timeline"></i>
            Timeline
          </a>
        </li>
        <li>
          <a href="payment.php" class="nav-item<?= $navActive('payment.php') ?>">
            <i class="fa-solid fa-wallet"></i>
            Payments
          </a>
        </li>
        <li>
          <a href="invoice.php" class="nav-item<?= $navActive('invoice.php') ?>">
            <i class="fa-solid fa-file-invoice-dollar"></i>
            Invoices
          </a>
        </li>
        <li>
          <a href="transport_track.php" class="nav-item<?= $navActive('transport_track.php') ?>">
            <i class="fa-solid fa-location-dot"></i>
            Live Tracking
          </a>
        </li>
      </ul>

      <div class="sidebar-section-label">System</div>
      <ul>
        <li><a href="logs.php" class="nav-item<?= $navActive('logs.php') ?>"><i class="fa-solid fa-clock-rotate-left"></i> Activity Logs</a></li>
        <li><a href="notifications.php" class="nav-item<?= $navActive('notifications.php') ?>"><i class="fa-solid fa-bell"></i> Notifications</a></li>
        <li><a href="security.php" class="nav-item<?= $navActive('security.php') ?>"><i class="fa-solid fa-shield-halved"></i> Security</a></li>
        <li><a href="settings.php" class="nav-item<?= $navActive('settings.php') ?>"><i class="fa-solid fa-gear"></i> Settings</a></li>
      </ul>
    </nav>

    <div class="sidebar-foot">
      <div class="sidebar-upgrade">
        <div class="label">System status</div>
        <p>All services operational. Last checked just now.</p>
        <a href="security.php" class="btn btn-outline-accent" style="width:100%;justify-content:center;">
          <i class="fa-solid fa-circle-check"></i> View status page
        </a>
      </div>
    </div>
</aside>