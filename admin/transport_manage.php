<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

/* =====================================================================
   LOOKUP DATA (hard-coded here; swap for transport_*_master tables
   any time — the view code below only cares about the arrays)
===================================================================== */
$STATUS_LIST = [
    'pending'          => ['label' => 'Pending',          'class' => 'muted'],
    'confirmed'        => ['label' => 'Confirmed',        'class' => 'info'],
    'driver_assigned'  => ['label' => 'Driver Assigned',  'class' => 'info'],
    'picked_up'        => ['label' => 'Picked Up',        'class' => 'warning'],
    'in_transit'       => ['label' => 'In Transit',       'class' => 'warning'],
    'out_for_delivery' => ['label' => 'Out For Delivery', 'class' => 'warning'],
    'delivered'        => ['label' => 'Delivered',        'class' => 'success'],
    'cancelled'        => ['label' => 'Cancelled',        'class' => 'danger'],
    'returned'         => ['label' => 'Returned',         'class' => 'danger'],
];

$PAYMENT_STATUS_LIST = [
    'unpaid'       => ['label' => 'Unpaid',        'class' => 'danger'],
    'partial'      => ['label' => 'Partially Paid','class' => 'warning'],
    'paid'         => ['label' => 'Paid',          'class' => 'success'],
    'refunded'     => ['label' => 'Refunded',      'class' => 'muted'],
];

$PRIORITY_LIST = [
    'low'    => ['label' => 'Low',    'class' => 'muted'],
    'normal' => ['label' => 'Normal', 'class' => 'info'],
    'high'   => ['label' => 'High',   'class' => 'warning'],
    'urgent' => ['label' => 'Urgent', 'class' => 'danger'],
];

function status_badge(string $value, array $map): string
{
    $meta = $map[$value] ?? ['label' => ucfirst(str_replace('_', ' ', $value ?: 'N/A')), 'class' => 'muted'];
    return '<span class="badge badge-' . e($meta['class']) . '">' . e($meta['label']) . '</span>';
}

/* =====================================================================
   BULK ACTIONS + SINGLE DELETE (POST only, CSRF-checked)
===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_require_valid();

    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        try {
            $stmt = $pdo->prepare('UPDATE transport_bookings SET deleted_at = NOW(), updated_by = :uid, updated_at = NOW() WHERE id = :id');
            $stmt->execute([':uid' => $_SESSION['admin_id'], ':id' => $id]);

            log_activity((int) $_SESSION['admin_id'], 'transport_booking_deleted', "booking_id={$id}");
            $_SESSION['flash_success'] = 'Booking moved to trash.';
        } catch (Throwable $e) {
            error_log('Transport booking deletion failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Something went wrong while deleting the booking.';
        }
    }
    header('Location: transport_manage.php?' . http_build_query($_GET));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_action') {
    csrf_require_valid();

    $ids = array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])));
    $bulkTo = (string) ($_POST['bulk_to'] ?? '');

    if ($ids && $bulkTo !== '') {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        try {
            if ($bulkTo === '__delete__') {
                $sql = "UPDATE transport_bookings SET deleted_at = NOW(), updated_by = ?, updated_at = NOW() WHERE id IN ($placeholders)";
                $params = array_merge([$_SESSION['admin_id']], $ids);
                $pdo->prepare($sql)->execute($params);
                $_SESSION['flash_success'] = count($ids) . ' booking(s) moved to trash.';
                log_activity((int) $_SESSION['admin_id'], 'transport_booking_bulk_deleted', 'ids=' . implode(',', $ids));
            } elseif (array_key_exists($bulkTo, $STATUS_LIST)) {
                $sql = "UPDATE transport_bookings SET status = ?, updated_by = ?, updated_at = NOW() WHERE id IN ($placeholders)";
                $params = array_merge([$bulkTo, $_SESSION['admin_id']], $ids);
                $pdo->prepare($sql)->execute($params);

                // Drop a timeline entry for each booking so the customer-visible
                // tracking history reflects the bulk update.
                $tlStmt = $pdo->prepare(
                    'INSERT INTO transport_booking_timeline
                        (booking_id, tracking_id, status, title, description, is_customer_visible, created_by_admin_id, created_at)
                     SELECT id, tracking_id, ?, ?, ?, 1, ?, NOW() FROM transport_bookings WHERE id = ?'
                );
                $title = $STATUS_LIST[$bulkTo]['label'];
                foreach ($ids as $bid) {
                    $tlStmt->execute([$bulkTo, $title, 'Status updated to ' . $title, $_SESSION['admin_id'], $bid]);
                }

                $_SESSION['flash_success'] = count($ids) . ' booking(s) updated to "' . $title . '".';
                log_activity((int) $_SESSION['admin_id'], 'transport_booking_bulk_status', 'ids=' . implode(',', $ids) . ' status=' . $bulkTo);
            }
        } catch (Throwable $e) {
            error_log('Transport bulk action failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Something went wrong while applying the bulk action.';
        }
    } else {
        $_SESSION['flash_error'] = 'Select at least one booking and an action.';
    }
    header('Location: transport_manage.php?' . http_build_query($_GET));
    exit;
}

/* =====================================================================
   FILTERS
===================================================================== */
$search        = mb_substr(clean_input((string) ($_GET['q'] ?? '')), 0, 150);
$fStatus       = (string) ($_GET['status'] ?? '');
$fPayment      = (string) ($_GET['payment_status'] ?? '');
$fPriority     = (string) ($_GET['priority'] ?? '');
$fDateFrom     = (string) ($_GET['date_from'] ?? '');
$fDateTo       = (string) ($_GET['date_to'] ?? '');

$fStatus   = array_key_exists($fStatus, $STATUS_LIST) ? $fStatus : '';
$fPayment  = array_key_exists($fPayment, $PAYMENT_STATUS_LIST) ? $fPayment : '';
$fPriority = array_key_exists($fPriority, $PRIORITY_LIST) ? $fPriority : '';

$where  = ['tb.deleted_at IS NULL'];
$params = [];

if ($search !== '') {
    $where[] = '(tb.tracking_id LIKE :s OR tb.booking_reference LIKE :s OR tb.customer_name LIKE :s OR tb.phone LIKE :s OR tb.company_name LIKE :s)';
    $params[':s'] = '%' . $search . '%';
}
if ($fStatus !== '') {
    $where[] = 'tb.status = :status';
    $params[':status'] = $fStatus;
}
if ($fPayment !== '') {
    $where[] = 'tb.payment_status = :payment_status';
    $params[':payment_status'] = $fPayment;
}
if ($fPriority !== '') {
    $where[] = 'tb.priority = :priority';
    $params[':priority'] = $fPriority;
}
if ($fDateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDateFrom)) {
    $where[] = 'tb.pickup_date >= :date_from';
    $params[':date_from'] = $fDateFrom;
}
if ($fDateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fDateTo)) {
    $where[] = 'tb.pickup_date <= :date_to';
    $params[':date_to'] = $fDateTo;
}
$whereSql = implode(' AND ', $where);

/* =====================================================================
   PAGINATION
===================================================================== */
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = defined('USERS_PER_PAGE') ? USERS_PER_PAGE : 15;
$offset  = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM transport_bookings tb WHERE $whereSql");
$countStmt->execute($params);
$totalRows  = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

$listSql = "
    SELECT
        tb.id, tb.tracking_id, tb.booking_reference, tb.customer_name, tb.company_name,
        tb.phone, tb.pickup_city, tb.pickup_state, tb.delivery_city, tb.delivery_state,
        tb.cargo_type, tb.cargo_weight, tb.cargo_weight_unit, tb.vehicle_type_requested,
        tb.status, tb.priority, tb.pickup_date, tb.expected_delivery_date,
        tb.net_amount, tb.paid_amount, tb.balance_amount, tb.payment_status,
        tb.created_at,
        d.id AS driver_id, d.driver_name, d.phone AS driver_phone, d.photo AS driver_photo,
        v.id AS vehicle_id, v.registration_number, v.vehicle_type AS vehicle_type_actual
    FROM transport_bookings tb
    LEFT JOIN transport_drivers d ON d.id = tb.driver_id
    LEFT JOIN transport_vehicles v ON v.id = tb.vehicle_id
    WHERE $whereSql
    ORDER BY tb.created_at DESC, tb.id DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($listSql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$bookings = $stmt->fetchAll();

/* =====================================================================
   DASHBOARD CARDS (live counts — safe against a missing/empty table)
===================================================================== */
function safe_scalar_transport(PDO $pdo, string $sql, array $params = [], $default = 0)
{
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $val = $stmt->fetchColumn();
        return $val === false ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}

$cardTodayBookings = (int) safe_scalar_transport($pdo,
    "SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL AND DATE(created_at) = CURDATE()");

$cardActiveBookings = (int) safe_scalar_transport($pdo,
    "SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL AND status IN ('confirmed','driver_assigned','picked_up','in_transit','out_for_delivery')");

$cardCompletedBookings = (int) safe_scalar_transport($pdo,
    "SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL AND status = 'delivered'");

$cardCancelledBookings = (int) safe_scalar_transport($pdo,
    "SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL AND status IN ('cancelled','returned')");

$cardPendingAmount = (float) safe_scalar_transport($pdo,
    "SELECT COALESCE(SUM(balance_amount),0) FROM transport_bookings WHERE deleted_at IS NULL AND payment_status IN ('unpaid','partial')");

$cardMonthlyRevenue = (float) safe_scalar_transport($pdo,
    "SELECT COALESCE(SUM(net_amount),0) FROM transport_bookings WHERE deleted_at IS NULL AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");

$cardDriverCount = (int) safe_scalar_transport($pdo,
    "SELECT COUNT(*) FROM transport_drivers WHERE status = 'active'");

$cardVehicleCount = (int) safe_scalar_transport($pdo,
    "SELECT COUNT(*) FROM transport_vehicles WHERE status = 'active'");

$sidebarUserCount = (int) safe_scalar_transport($pdo, 'SELECT COUNT(*) FROM users');

function inr(float $amount): string
{
    return '₹' . number_format($amount, 2);
}

$pageTitle = 'Manage Transport Bookings';
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/css/dashboard.css">

<style>
  /* ---- Additions specific to the Transport module (kept local so
         dashboard.css never has to know about this page) ---- */
  .stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 22px;
  }
  .stat-card {
    background: var(--surface, #fff);
    border: 1px solid var(--border, #eee);
    border-radius: 12px;
    padding: 16px 18px;
    display: flex;
    flex-direction: column;
    gap: 6px;
  }
  .stat-card .stat-icon {
    width: 34px; height: 34px;
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px;
    margin-bottom: 4px;
  }
  .stat-card.c-blue   .stat-icon { background:#e7f0ff; color:#2f6fed; }
  .stat-card.c-amber  .stat-icon { background:#fff4e0; color:#c8790a; }
  .stat-card.c-green  .stat-icon { background:#e6f4ea; color:#1b7a34; }
  .stat-card.c-red    .stat-icon { background:#fdecea; color:#c0362c; }
  .stat-card.c-purple .stat-icon { background:#f1e9ff; color:#7c3aed; }
  .stat-card.c-teal   .stat-icon { background:#e2f7f4; color:#0f8f7f; }
  .stat-value { font-size: 1.4rem; font-weight: 700; line-height: 1.1; }
  .stat-label { font-size: .78rem; color: var(--text-muted); font-weight: 500; }

  .badge-info    { background:#e7f0ff; color:#2f6fed; }
  .badge-warning { background:#fff4e0; color:#c8790a; }
  .badge-danger  { background:#fdecea; color:#c0362c; }

  .filter-form {
    display: flex; flex-wrap: wrap; gap: 10px; align-items: center;
    margin-bottom: 4px;
  }
  .filter-form select, .filter-form input[type="date"] {
    border: 1px solid var(--border, #ddd);
    border-radius: 8px;
    padding: 7px 10px;
    font-size: .85rem;
    background: #fff;
  }

  .tracking-id-badge {
    display: inline-flex; align-items: center; gap: 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: .78rem;
    background: #f4f6fb;
    border: 1px solid #e4e8f2;
    border-radius: 6px;
    padding: 3px 8px;
    color: #33415c;
  }

  .route-cell { font-size: .82rem; line-height: 1.4; }
  .route-cell .city { font-weight: 600; }
  .route-cell .arrow { color: var(--text-muted); margin: 0 4px; }

  .driver-badge {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: .8rem;
  }
  .driver-badge .av {
    width: 22px; height: 22px; border-radius: 50%;
    background: #eef1f8; color: #4b5875;
    display: flex; align-items: center; justify-content: center;
    font-size: .68rem; font-weight: 700; flex-shrink: 0;
  }
  .driver-badge.unassigned { color: var(--text-muted); font-style: italic; }

  .amount-cell { font-size: .82rem; line-height: 1.5; }
  .amount-cell .net { font-weight: 700; }
  .amount-cell .balance { color: #c0362c; font-size: .74rem; }
  .amount-cell .balance.zero { color: #1b7a34; }

  .bulk-bar {
    display: none;
    align-items: center; gap: 10px;
    background: #eef2ff; border: 1px solid #d7e0ff;
    border-radius: 10px; padding: 10px 14px;
    margin-bottom: 14px; font-size: .85rem;
  }
  .bulk-bar.show { display: flex; flex-wrap: wrap; }
  .bulk-bar select {
    border: 1px solid #cfd8f5; border-radius: 6px; padding: 6px 8px; font-size: .82rem;
  }
  .checkbox-col { width: 34px; text-align: center; }
  .priority-flag { font-size: .7rem; margin-left: 6px; }
</style>

<div class="app-shell">

  <!-- ===================== SIDEBAR ===================== -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <div class="mark">A</div>
      <div class="name">Biome<br><small>Control Panel</small></div>
    </div>

    <div class="sidebar-section-label">Overview</div>
    <nav class="sidebar-nav">
      <ul>
        <li><a href="dashboard.php" class="nav-item"><i class="fa-solid fa-grid-2"></i> Dashboard</a></li>
        <li><a href="analytics.php" class="nav-item"><i class="fa-solid fa-chart-line"></i> Analytics</a></li>
        <li><a href="reports.php" class="nav-item"><i class="fa-solid fa-file-lines"></i> Reports</a></li>
      </ul>

      <div class="sidebar-section-label">Manage</div>
      <ul>
        <li><a href="users.php" class="nav-item"><i class="fa-solid fa-users"></i> Users <span class="pill"><?= e((string) $sidebarUserCount) ?></span></a></li>
        <li><a href="admins.php" class="nav-item"><i class="fa-solid fa-user-shield"></i> Admins</a></li>
        <li><a href="bamboo-enquiries.php" class="nav-item"><i class="fa-solid fa-user-shield"></i> Bamboo Trading</a></li>
        <li><a href="roles.php" class="nav-item"><i class="fa-solid fa-key"></i> Roles &amp; Permissions</a></li>
        <li><a href="content.php" class="nav-item"><i class="fa-solid fa-layer-group"></i> Content</a></li>
        <li><a href="billing.php" class="nav-item"><i class="fa-solid fa-credit-card"></i> Billing</a></li>
        <li><a href="blog_manage.php" class="nav-item"><i class="fa-solid fa-newspaper"></i> Blog Posts</a></li>
        <li><a href="transport_manage.php" class="nav-item active"><i class="fa-solid fa-truck-fast"></i> Transport Bookings <span class="pill"><?= e((string) $totalRows) ?></span></a></li>
      </ul>

      <div class="sidebar-section-label">System</div>
      <ul>
        <li><a href="logs.php" class="nav-item"><i class="fa-solid fa-clock-rotate-left"></i> Activity Logs</a></li>
        <li><a href="notifications.php" class="nav-item"><i class="fa-solid fa-bell"></i> Notifications</a></li>
        <li><a href="security.php" class="nav-item"><i class="fa-solid fa-shield-halved"></i> Security</a></li>
        <li><a href="settings.php" class="nav-item"><i class="fa-solid fa-gear"></i> Settings</a></li>
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
          <div class="breadcrumb">Biome <span class="sep">/</span> <span class="current">Transport Bookings</span></div>
          <h1>Manage transport bookings</h1>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <div class="datetime btn-ghost btn" style="cursor:default;">
            <i class="fa-solid fa-truck-fast"></i>
            <?= e(number_format($totalRows)) ?> total
          </div>
          <a href="transport_add.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New booking</a>
        </div>
      </div>

      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="flash-success" style="background:#e6f4ea;border:1px solid #bfe4c9;color:#1b7a34;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:.9rem;">
          <?= e($_SESSION['flash_success']) ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
      <?php endif; ?>
      <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="flash-error" style="background:#fdecea;border:1px solid #f5c2bd;color:#7a271a;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:.9rem;">
          <?= e($_SESSION['flash_error']) ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
      <?php endif; ?>

      <!-- ---------- Dashboard cards ---------- -->
      <div class="stats-grid">
        <div class="stat-card c-blue">
          <div class="stat-icon"><i class="fa-solid fa-calendar-day"></i></div>
          <div class="stat-value"><?= e(number_format($cardTodayBookings)) ?></div>
          <div class="stat-label">Today's Bookings</div>
        </div>
        <div class="stat-card c-amber">
          <div class="stat-icon"><i class="fa-solid fa-truck-moving"></i></div>
          <div class="stat-value"><?= e(number_format($cardActiveBookings)) ?></div>
          <div class="stat-label">Active Bookings</div>
        </div>
        <div class="stat-card c-green">
          <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
          <div class="stat-value"><?= e(number_format($cardCompletedBookings)) ?></div>
          <div class="stat-label">Completed</div>
        </div>
        <div class="stat-card c-red">
          <div class="stat-icon"><i class="fa-solid fa-circle-xmark"></i></div>
          <div class="stat-value"><?= e(number_format($cardCancelledBookings)) ?></div>
          <div class="stat-label">Cancelled / Returned</div>
        </div>
        <div class="stat-card c-purple">
          <div class="stat-icon"><i class="fa-solid fa-hourglass-half"></i></div>
          <div class="stat-value"><?= e(inr($cardPendingAmount)) ?></div>
          <div class="stat-label">Pending Amount</div>
        </div>
        <div class="stat-card c-teal">
          <div class="stat-icon"><i class="fa-solid fa-sack-dollar"></i></div>
          <div class="stat-value"><?= e(inr($cardMonthlyRevenue)) ?></div>
          <div class="stat-label">Revenue (this month)</div>
        </div>
        <div class="stat-card c-blue">
          <div class="stat-icon"><i class="fa-solid fa-id-card"></i></div>
          <div class="stat-value"><?= e(number_format($cardDriverCount)) ?></div>
          <div class="stat-label">Active Drivers</div>
        </div>
        <div class="stat-card c-amber">
          <div class="stat-icon"><i class="fa-solid fa-truck"></i></div>
          <div class="stat-value"><?= e(number_format($cardVehicleCount)) ?></div>
          <div class="stat-label">Active Vehicles</div>
        </div>
      </div>

      <!-- Signature element: live activity pulse -->
      <svg class="pulse-divider" viewBox="0 0 1200 34" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0,17 L1200,17"></path>
        <path class="live" d="M0,17 L120,17 L132,4 L144,30 L156,17 L300,17 L312,9 L324,25 L336,17 L520,17 L532,2 L544,32 L556,17 L760,17 L772,7 L784,27 L796,17 L1000,17 L1012,4 L1024,30 L1036,17 L1200,17"></path>
      </svg>

      <!-- ---------- Toolbar: search + filters ---------- -->
      <div class="toolbar" style="flex-direction:column;align-items:stretch;gap:12px;">
        <form method="get" action="transport_manage.php" class="filter-form">
          <div class="search-field" style="flex:1;min-width:220px;">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="q" placeholder="Search tracking ID, customer, phone, company…"
                   value="<?= e($search) ?>" maxlength="150">
          </div>

          <select name="status">
            <option value="">All statuses</option>
            <?php foreach ($STATUS_LIST as $key => $meta): ?>
              <option value="<?= e($key) ?>" <?= $fStatus === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
            <?php endforeach; ?>
          </select>

          <select name="payment_status">
            <option value="">All payments</option>
            <?php foreach ($PAYMENT_STATUS_LIST as $key => $meta): ?>
              <option value="<?= e($key) ?>" <?= $fPayment === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
            <?php endforeach; ?>
          </select>

          <select name="priority">
            <option value="">All priorities</option>
            <?php foreach ($PRIORITY_LIST as $key => $meta): ?>
              <option value="<?= e($key) ?>" <?= $fPriority === $key ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
            <?php endforeach; ?>
          </select>

          <input type="date" name="date_from" value="<?= e($fDateFrom) ?>" title="Pickup date from">
          <input type="date" name="date_to" value="<?= e($fDateTo) ?>" title="Pickup date to">

          <button type="submit" class="btn btn-ghost"><i class="fa-solid fa-filter"></i> Apply</button>
          <?php if ($search !== '' || $fStatus !== '' || $fPayment !== '' || $fPriority !== '' || $fDateFrom !== '' || $fDateTo !== ''): ?>
            <a href="transport_manage.php" class="btn btn-secondary">Clear all</a>
          <?php endif; ?>
        </form>
      </div>

      <!-- ---------- Bulk action bar ---------- -->
      <form method="post" action="transport_manage.php?<?= e(http_build_query($_GET)) ?>" id="bulkForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="bulk_action">
        <div class="bulk-bar" id="bulkBar">
          <span><strong id="bulkCount">0</strong> selected</span>
          <select name="bulk_to" id="bulkTo" required>
            <option value="">Choose action…</option>
            <optgroup label="Change status">
              <?php foreach ($STATUS_LIST as $key => $meta): ?>
                <option value="<?= e($key) ?>">Set status: <?= e($meta['label']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <option value="__delete__">Delete selected</option>
          </select>
          <button type="submit" class="btn btn-small btn-primary" id="bulkApply"
                  onclick="return confirm('Apply this action to the selected bookings?');">
            <i class="fa-solid fa-bolt"></i> Apply
          </button>
          <button type="button" class="btn btn-small btn-secondary" id="bulkClear">Clear selection</button>
        </div>

        <!-- ---------- Bookings table ---------- -->
        <div class="table-panel">
          <div class="table-caption">
            <span>
              <?php if ($search !== ''): ?>
                Showing results for <strong>&ldquo;<?= e($search) ?>&rdquo;</strong>
              <?php else: ?>
                Showing <strong><?= e((string) count($bookings)) ?></strong> of <strong><?= e(number_format($totalRows)) ?></strong> bookings
              <?php endif; ?>
            </span>
            <span>Page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?></span>
          </div>

          <div class="table-scroll">
            <table class="data-table">
              <thead>
                <tr>
                  <th class="checkbox-col"><input type="checkbox" id="selectAll"></th>
                  <th>Tracking ID</th>
                  <th>Customer</th>
                  <th>Route</th>
                  <th>Cargo / Vehicle</th>
                  <th>Driver</th>
                  <th>Status</th>
                  <th>Payment</th>
                  <th>Amount</th>
                  <th>Pickup date</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!$bookings): ?>
                  <tr>
                    <td colspan="11">
                      <div class="empty-state">
                        <i class="fa-solid fa-truck-fast"></i>
                        <p>No bookings found<?= ($search !== '' || $fStatus !== '') ? ' for these filters' : '' ?>.</p>
                        <span><?= ($search !== '' || $fStatus !== '') ? 'Try adjusting your search or filters.' : 'New bookings will appear here once created.' ?></span>
                      </div>
                    </td>
                  </tr>
                <?php endif; ?>
                <?php foreach ($bookings as $b): ?>
                  <?php
                    $balance = (float) $b['balance_amount'];
                    $priorityMeta = $PRIORITY_LIST[$b['priority']] ?? null;
                  ?>
                  <tr>
                    <td class="checkbox-col">
                      <input type="checkbox" class="row-check" name="ids[]" value="<?= (int) $b['id'] ?>" form="bulkForm">
                    </td>
                    <td>
                      <span class="tracking-id-badge"><i class="fa-solid fa-barcode"></i> <?= e($b['tracking_id']) ?></span>
                      <?php if ($b['booking_reference']): ?>
                        <div style="font-size:.72rem;color:var(--text-muted);margin-top:3px;">Ref: <?= e($b['booking_reference']) ?></div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <div class="user-cell">
                        <div class="av"><?= e(strtoupper(substr($b['customer_name'] ?: '?', 0, 1))) ?></div>
                        <div class="meta">
                          <strong><?= e($b['customer_name']) ?></strong>
                          <span><?= e($b['company_name'] ?: $b['phone']) ?></span>
                        </div>
                      </div>
                      <?php if ($priorityMeta && $b['priority'] !== 'normal'): ?>
                        <span class="badge badge-<?= e($priorityMeta['class']) ?> priority-flag"><?= e($priorityMeta['label']) ?></span>
                      <?php endif; ?>
                    </td>
                    <td class="route-cell">
                      <span class="city"><?= e($b['pickup_city']) ?></span>
                      <span class="arrow">&rarr;</span>
                      <span class="city"><?= e($b['delivery_city']) ?></span>
                    </td>
                    <td class="route-cell">
                      <?= e($b['cargo_type'] ?: '—') ?><br>
                      <span style="color:var(--text-muted);">
                        <?= $b['cargo_weight'] ? e(rtrim(rtrim((string) $b['cargo_weight'], '0'), '.') . ' ' . $b['cargo_weight_unit']) : '' ?>
                        <?= $b['registration_number'] ? ' · ' . e($b['registration_number']) : '' ?>
                      </span>
                    </td>
                    <td>
                      <?php if ($b['driver_id']): ?>
                        <span class="driver-badge">
                          <span class="av"><?= e(strtoupper(substr($b['driver_name'] ?: '?', 0, 1))) ?></span>
                          <?= e($b['driver_name']) ?>
                        </span>
                      <?php else: ?>
                        <span class="driver-badge unassigned"><i class="fa-regular fa-circle"></i> Unassigned</span>
                      <?php endif; ?>
                    </td>
                    <td><?= status_badge($b['status'], $STATUS_LIST) ?></td>
                    <td><?= status_badge($b['payment_status'], $PAYMENT_STATUS_LIST) ?></td>
                    <td class="amount-cell">
                      <div class="net"><?= e(inr((float) $b['net_amount'])) ?></div>
                      <div class="balance <?= $balance <= 0 ? 'zero' : '' ?>">
                        <?= $balance > 0 ? 'Due ' . e(inr($balance)) : 'Fully paid' ?>
                      </div>
                    </td>
                    <td><span class="mono-time"><?= e((string) $b['pickup_date']) ?></span></td>
                    <td class="actions">
                      <a href="transport_track.php?id=<?= (int) $b['id'] ?>" class="btn btn-small btn-ghost" title="Timeline / tracking">
                        <i class="fa-solid fa-timeline"></i>
                      </a>
                      <a href="transport_edit.php?id=<?= (int) $b['id'] ?>" class="btn btn-small btn-secondary">
                        <i class="fa-solid fa-pen"></i> Edit
                      </a>
                      <form method="post" action="transport_manage.php?<?= e(http_build_query($_GET)) ?>" class="inline-form"
                            onsubmit="return confirm('Delete this booking? It will be moved to trash and can be restored from the database if needed.');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                        <button type="submit" class="btn btn-small btn-danger">
                          <i class="fa-solid fa-trash"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      </form>

      <!-- ---------- Pagination ---------- -->
      <?php if ($totalPages > 1): ?>
        <?php
          $qsBase = $_GET;
          unset($qsBase['page']);
          $qsPrefix = $qsBase ? '&' . http_build_query($qsBase) : '';
        ?>
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="transport_manage.php?page=<?= $page - 1 ?><?= e($qsPrefix) ?>" aria-label="Previous page">
              <i class="fa-solid fa-chevron-left" style="font-size:11px;"></i>
            </a>
          <?php endif; ?>

          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="transport_manage.php?page=<?= $p ?><?= e($qsPrefix) ?>"
               class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="transport_manage.php?page=<?= $page + 1 ?><?= e($qsPrefix) ?>" aria-label="Next page">
              <i class="fa-solid fa-chevron-right" style="font-size:11px;"></i>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

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
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('open');
    });
  }

  const selectAll  = document.getElementById('selectAll');
  const rowChecks  = () => Array.from(document.querySelectorAll('.row-check'));
  const bulkBar    = document.getElementById('bulkBar');
  const bulkCount  = document.getElementById('bulkCount');
  const bulkClear  = document.getElementById('bulkClear');

  function refreshBulkBar() {
    const checked = rowChecks().filter(cb => cb.checked);
    bulkCount.textContent = checked.length;
    bulkBar.classList.toggle('show', checked.length > 0);
    if (selectAll) {
      selectAll.checked = checked.length > 0 && checked.length === rowChecks().length;
    }
  }

  if (selectAll) {
    selectAll.addEventListener('change', function () {
      rowChecks().forEach(cb => { cb.checked = selectAll.checked; });
      refreshBulkBar();
    });
  }

  document.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('row-check')) {
      refreshBulkBar();
    }
  });

  if (bulkClear) {
    bulkClear.addEventListener('click', function () {
      rowChecks().forEach(cb => { cb.checked = false; });
      if (selectAll) selectAll.checked = false;
      refreshBulkBar();
    });
  }

  refreshBulkBar();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>