<?php
/**
 * Shared admin sidebar.
 * Requires $pdo (from bootstrap.php) to already be available in the including page.
 * Include with: require __DIR__ . '/includes/sidebar.php';
 *
 * CHANGED: fa-grid-2 (Font Awesome PRO only) -> fa-table-cells-large (free);
 * Timeline/Payments/Invoices no longer point at pages that need ?id= and bounce back;
 * added "New Requests", "Live Tracking", "Fleet" and "Public Tracking Page".
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
$sidebarPendingCount = sidebar_safe_count($pdo, "SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL AND status = 'pending'");

$sidebarLiveCount = isset($sidebarLiveCount) ? $sidebarLiveCount : sidebar_safe_count($pdo, "SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL AND status = 'in_transit'");
$sidebarCabNew   = sidebar_safe_count($pdo, "SELECT COUNT(*) FROM cab_booking_requests WHERE deleted_at IS NULL AND status = 'new'");
$sidebarCabToday = sidebar_safe_count($pdo, "SELECT COUNT(*) FROM cab_booking_requests WHERE deleted_at IS NULL AND status IN ('confirmed','assigned','on_trip') AND journey_date <= CURDATE() AND COALESCE(end_date, journey_date) >= CURDATE()");
$sidebarHtlPending = sidebar_safe_count($pdo, "SELECT COUNT(*) FROM hotel_reservations WHERE deleted_at IS NULL AND status = 'pending'");
$sidebarHtlArriving = sidebar_safe_count($pdo, "SELECT COUNT(*) FROM hotel_reservations WHERE deleted_at IS NULL AND check_in = CURDATE() AND status IN ('pending','confirmed')");
$sidebarBambooNewCount   = sidebar_safe_count($pdo, "SELECT COUNT(*) FROM bamboo_enquiries WHERE status = 'new'");
$sidebarBambooDueCount   = sidebar_safe_count($pdo, "SELECT COUNT(*) FROM bamboo_enquiries WHERE follow_up_date IS NOT NULL AND follow_up_date <= CURDATE() AND status NOT IN ('won','lost')");
$sidebarBambooOpenOrders = sidebar_safe_count($pdo, "SELECT COUNT(*) FROM bamboo_orders WHERE deleted_at IS NULL AND status NOT IN ('delivered','cancelled')");

$navActive = static fn(string ...$pages): string => in_array($currentPage, $pages, true) ? ' active' : '';
$curStatus = (string) ($_GET['status'] ?? '');
?>
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <div class="mark">B</div>
      <div class="name">Biome<br><small>Control Panel</small></div>
    </div>

    <div class="sidebar-section-label">Overview</div>
    <nav class="sidebar-nav">
      <ul>
        <li><a href="dashboard.php" class="nav-item<?= $navActive('dashboard.php') ?>"><i class="fa-solid fa-table-cells-large"></i> Dashboard</a></li>
        <li><a href="analytics.php" class="nav-item<?= $navActive('analytics.php') ?>"><i class="fa-solid fa-chart-line"></i> Analytics</a></li>
      </ul>

      <div class="sidebar-section-label">Manage</div>
      <ul>
        <li><a href="users.php" class="nav-item<?= $navActive('users.php') ?>"><i class="fa-solid fa-users"></i> Users <span class="pill"><?= e((string) $totalUsers) ?></span></a></li>
        <li><a href="blog_manage.php" class="nav-item<?= $navActive('blog_manage.php') ?>"><i class="fa-solid fa-newspaper"></i> Blog Posts <span class="pill"><?= e((string) $sidebarBlogCount) ?></span></a></li>
      </ul>

      <!-- Bamboo Trading -->
      <div class="sidebar-section-label">Bamboo Trading</div>
      <ul>
        <li><a href="bamboo_dashboard.php" class="nav-item<?= $navActive('bamboo_dashboard.php') ?>"><i class="fa-solid fa-gauge-high"></i> Bamboo Dashboard</a></li>
        <li>
          <a href="bamboo_enquiries.php" class="nav-item<?= $navActive('bamboo_enquiries.php') ?>">
            <i class="fa-solid fa-seedling"></i> Enquiries
            <?php if ($sidebarBambooNewCount > 0): ?><span class="pill" style="background:#f59e0b;color:#fff;"><?= e((string) $sidebarBambooNewCount) ?></span><?php endif; ?>
          </a>
        </li>
        <?php if ($sidebarBambooDueCount > 0): ?>
        <li><a href="bamboo_enquiries.php?s=due" class="nav-item<?= ($currentPage === 'bamboo_enquiries.php' && ($_GET['s'] ?? '') === 'due') ? ' active' : '' ?>"><i class="fa-solid fa-bell"></i> Follow-ups due <span class="pill" style="background:#dc3545;color:#fff;"><?= e((string) $sidebarBambooDueCount) ?></span></a></li>
        <?php endif; ?>
        <li>
          <a href="bamboo_orders.php" class="nav-item<?= $navActive('bamboo_orders.php', 'bamboo_order_form.php', 'bamboo_order_view.php') ?>">
            <i class="fa-solid fa-file-invoice-dollar"></i> Orders &amp; Quotations
            <span class="pill"><?= e((string) $sidebarBambooOpenOrders) ?></span>
          </a>
        </li>
        <li><a href="bamboo_products.php" class="nav-item<?= $navActive('bamboo_products.php') ?>"><i class="fa-solid fa-boxes-stacked"></i> Products &amp; Stock</a></li>
        <li><a href="bamboo_stock.php" class="nav-item<?= $navActive('bamboo_stock.php') ?>"><i class="fa-solid fa-boxes-packing"></i> Stock Ledger</a></li>
        <li><a href="bamboo_customers.php" class="nav-item<?= $navActive('bamboo_customers.php') ?>"><i class="fa-solid fa-address-book"></i> Customers</a></li>
        <li><a href="bamboo_reports.php" class="nav-item<?= $navActive('bamboo_reports.php') ?>"><i class="fa-solid fa-chart-column"></i> Bamboo Reports</a></li>
        <li><a href="bamboo_settings.php" class="nav-item<?= $navActive('bamboo_settings.php') ?>"><i class="fa-solid fa-sliders"></i> Bamboo Settings</a></li>
        <li><a href="../bamboo-trading" target="_blank" rel="noopener" class="nav-item"><i class="fa-solid fa-arrow-up-right-from-square"></i> Public Bamboo Page</a></li>
      </ul>

      <!-- Hotels & Homestays -->
      <div class="sidebar-section-label">Hotels &amp; Homestays</div>
      <ul>
        <li><a href="hotel_dashboard.php" class="nav-item<?= $navActive('hotel_dashboard.php') ?>"><i class="fa-solid fa-gauge-high"></i> Hotel Dashboard</a></li>
        <li>
          <a href="hotel_reservations.php" class="nav-item<?= in_array($currentPage, ['hotel_reservations.php', 'hotel_view.php', 'hotel_form.php'], true) ? ' active' : '' ?>">
            <i class="fa-solid fa-bell-concierge"></i> Reservations
            <?php if ($sidebarHtlPending > 0): ?><span class="pill" style="background:#f59e0b;color:#fff;"><?= e((string) $sidebarHtlPending) ?> new</span><?php elseif ($sidebarHtlArriving > 0): ?><span class="pill"><?= e((string) $sidebarHtlArriving) ?> today</span><?php endif; ?>
          </a>
        </li>
        <li><a href="hotel_calendar.php" class="nav-item<?= $navActive('hotel_calendar.php') ?>"><i class="fa-solid fa-calendar-days"></i> Availability Calendar</a></li>
        <li><a href="hotel_properties.php" class="nav-item<?= in_array($currentPage, ['hotel_properties.php', 'hotel_rooms.php', 'hotel_rates.php'], true) ? ' active' : '' ?>"><i class="fa-solid fa-hotel"></i> Properties &amp; Rooms</a></li>
        <li><a href="hotel_reports.php" class="nav-item<?= $navActive('hotel_reports.php') ?>"><i class="fa-solid fa-chart-column"></i> Hotel Reports</a></li>
        <li><a href="hotel_settings.php" class="nav-item<?= $navActive('hotel_settings.php') ?>"><i class="fa-solid fa-sliders"></i> Hotel Settings</a></li>
        <li><a href="../hotel" target="_blank" rel="noopener" class="nav-item"><i class="fa-solid fa-arrow-up-right-from-square"></i> Public Hotel Page</a></li>
      </ul>

      <!-- Cab Services -->
      <div class="sidebar-section-label">Cab Services</div>
      <ul>
        <li><a href="cab_dashboard.php" class="nav-item<?= $navActive('cab_dashboard.php') ?>"><i class="fa-solid fa-gauge-high"></i> Cab Dashboard</a></li>
        <li>
          <a href="cab_bookings.php" class="nav-item<?= ($currentPage === 'cab_bookings.php' && $curStatus === '') || in_array($currentPage, ['cab_view.php', 'cab_form.php'], true) ? ' active' : '' ?>">
            <i class="fa-solid fa-taxi"></i> Bookings
            <?php if ($sidebarCabNew > 0): ?><span class="pill" style="background:#f59e0b;color:#fff;"><?= e((string) $sidebarCabNew) ?> new</span><?php endif; ?>
          </a>
        </li>
        <li><a href="cab_bookings.php?status=on_trip" class="nav-item<?= ($currentPage === 'cab_bookings.php' && $curStatus === 'on_trip') ? ' active' : '' ?>"><i class="fa-solid fa-route"></i> Trips running today <?php if ($sidebarCabToday > 0): ?><span class="pill"><?= e((string) $sidebarCabToday) ?></span><?php endif; ?></a></li>
        <li><a href="cab_form.php" class="nav-item<?= $navActive('cab_form.php') ?>"><i class="fa-solid fa-plus"></i> Add Booking</a></li>
        <li><a href="cab_fleet.php" class="nav-item<?= $navActive('cab_fleet.php') ?>"><i class="fa-solid fa-car-side"></i> Fleet &amp; Drivers</a></li>
        <li><a href="cab_rates.php" class="nav-item<?= $navActive('cab_rates.php') ?>"><i class="fa-solid fa-indian-rupee-sign"></i> Rates &amp; Settings</a></li>
        <li><a href="cab_reports.php" class="nav-item<?= $navActive('cab_reports.php') ?>"><i class="fa-solid fa-chart-column"></i> Reports</a></li>
        <li><a href="../cab" target="_blank" rel="noopener" class="nav-item"><i class="fa-solid fa-arrow-up-right-from-square"></i> Public Cab Page</a></li>
      </ul>

      <!-- Transport -->
      <div class="sidebar-section-label">Transport &amp; Logistics</div>
      <ul>
        <li>
          <a href="transport_manage.php" class="nav-item<?= ($currentPage === 'transport_manage.php' && $curStatus === '') || in_array($currentPage, ['transport_view.php', 'transport_edit.php', 'timeline.php'], true) ? ' active' : '' ?>">
            <i class="fa-solid fa-truck-fast"></i> All Bookings
            <span class="pill"><?= e((string) $sidebarTransportCount) ?></span>
          </a>
        </li>
        <li>
          <a href="transport_manage.php?status=pending" class="nav-item<?= ($currentPage === 'transport_manage.php' && $curStatus === 'pending') ? ' active' : '' ?>">
            <i class="fa-solid fa-inbox"></i> New Requests
            <?php if ($sidebarPendingCount > 0): ?><span class="pill" style="background:#f59e0b;color:#fff;"><?= e((string) $sidebarPendingCount) ?></span><?php endif; ?>
          </a>
        </li>
        <li>
          <a href="transport_add.php" class="nav-item<?= $navActive('transport_add.php') ?>">
            <i class="fa-solid fa-plus"></i> Add Booking
          </a>
        </li>
        <li>
          <a href="transport_manage.php?status=in_transit" class="nav-item<?= ($currentPage === 'transport_manage.php' && $curStatus === 'in_transit') ? ' active' : '' ?>">
            <i class="fa-solid fa-location-dot"></i> Live Tracking
            <?php if ($sidebarLiveCount > 0): ?><span class="pill"><?= e((string) $sidebarLiveCount) ?></span><?php endif; ?>
          </a>
        </li>
        <li>
          <a href="transport_invoices.php" class="nav-item<?= $navActive('transport_invoices.php', 'invoice.php', 'payment.php') ?>">
            <i class="fa-solid fa-file-invoice-dollar"></i> Invoices &amp; Payments
          </a>
        </li>
        <li>
          <a href="transport_fleet.php" class="nav-item<?= $navActive('transport_fleet.php') ?>">
            <i class="fa-solid fa-id-card"></i> Drivers &amp; Vehicles
          </a>
        </li>
        <li>
          <a href="../track" target="_blank" rel="noopener" class="nav-item">
            <i class="fa-solid fa-arrow-up-right-from-square"></i> Public Tracking Page
          </a>
        </li>
      </ul>
    </nav>

    <div class="sidebar-foot">
      <div class="sidebar-upgrade">
        <div class="label">System status</div>
        <p>All services operational.</p>
      </div>
    </div>
</aside>
