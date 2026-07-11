<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

$bookingId = (int) ($_GET['id'] ?? 0);
if ($bookingId <= 0) {
    $_SESSION['flash_error'] = 'Invalid booking.';
    header('Location: transport_manage.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT tb.*,
            d.driver_name, d.phone AS driver_phone, d.license_number,
            v.registration_number, v.vehicle_type AS vehicle_type_actual, v.brand, v.model,
            c.customer_code, c.gstin AS customer_gstin
     FROM transport_bookings tb
     LEFT JOIN transport_drivers d ON d.id = tb.driver_id
     LEFT JOIN transport_vehicles v ON v.id = tb.vehicle_id
     LEFT JOIN transport_customers c ON c.id = tb.customer_id
     WHERE tb.id = :id AND tb.deleted_at IS NULL"
);
$stmt->execute([':id' => $bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    $_SESSION['flash_error'] = 'Booking not found or has been deleted.';
    header('Location: transport_manage.php');
    exit;
}

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
    'unpaid'   => ['label' => 'Unpaid',        'class' => 'danger'],
    'partial'  => ['label' => 'Partially Paid','class' => 'warning'],
    'paid'     => ['label' => 'Paid',          'class' => 'success'],
    'refunded' => ['label' => 'Refunded',      'class' => 'muted'],
];
$PRIORITY_LIST = [
    'low'    => ['label' => 'Low',    'class' => 'muted'],
    'normal' => ['label' => 'Normal', 'class' => 'info'],
    'high'   => ['label' => 'High',   'class' => 'warning'],
    'urgent' => ['label' => 'Urgent', 'class' => 'danger'],
];

function status_badge_view(string $value, array $map): string
{
    $meta = $map[$value] ?? ['label' => ucfirst(str_replace('_', ' ', $value ?: 'N/A')), 'class' => 'muted'];
    return '<span class="badge badge-' . e($meta['class']) . '">' . e($meta['label']) . '</span>';
}
function inr_view(float $amount): string { return '₹' . number_format($amount, 2); }
function dt_view(?string $v, string $fmt = 'd M Y, h:i A'): string
{
    if (!$v || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($v);
    return $ts ? date($fmt, $ts) : '—';
}

/* ---- Recent timeline preview (last 6 entries, newest first) ---- */
$tlStmt = $pdo->prepare(
    'SELECT * FROM transport_booking_timeline WHERE booking_id = :id ORDER BY created_at DESC, id DESC LIMIT 6'
);
$tlStmt->execute([':id' => $bookingId]);
$timelinePreview = $tlStmt->fetchAll();

$tlCountStmt = $pdo->prepare('SELECT COUNT(*) FROM transport_booking_timeline WHERE booking_id = :id');
$tlCountStmt->execute([':id' => $bookingId]);
$timelineTotal = (int) $tlCountStmt->fetchColumn();

/* ---- Payment history (if the table has rows for this booking) ---- */
$payments = [];
try {
    $pStmt = $pdo->prepare('SELECT * FROM transport_payment_history WHERE booking_id = :id ORDER BY payment_date DESC, id DESC');
    $pStmt->execute([':id' => $bookingId]);
    $payments = $pStmt->fetchAll();
} catch (Throwable $e) {
    $payments = [];
}

/* ---- Documents (if any) ---- */
$documents = [];
try {
    $docStmt = $pdo->prepare('SELECT * FROM transport_documents WHERE booking_id = :id ORDER BY created_at DESC');
    $docStmt->execute([':id' => $bookingId]);
    $documents = $docStmt->fetchAll();
} catch (Throwable $e) {
    $documents = [];
}

function safe_scalar_transport(PDO $pdo, string $sql, $default = 0)
{
    try {
        $val = $pdo->query($sql)->fetchColumn();
        return $val === false ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}
$sidebarUserCount = (int) safe_scalar_transport($pdo, 'SELECT COUNT(*) FROM users');

$pageTitle = 'Booking Details';
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/css/dashboard.css">

<style>
  .panel {
    background: var(--surface, #fff);
    border: 1px solid var(--border, #eee);
    border-radius: 12px;
    padding: 20px 22px;
    margin-bottom: 18px;
  }
  .panel-head {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    margin-bottom: 16px; padding-bottom: 12px;
    border-bottom: 1px solid var(--border, #eee);
  }
  .panel-head h3 { margin: 0; font-size: 1rem; display:flex; align-items:center; gap:8px; }
  .panel-head h3 i { color: #4b5fd6; font-size: .85rem; }
  .panel-head .sub { font-size: .78rem; color: var(--text-muted); }

  .info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px 18px;
  }
  .info-item .label { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-muted); margin-bottom: 4px; }
  .info-item .value { font-size: .92rem; font-weight: 600; color: #1c2540; }
  .info-item .value.mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
  .info-item .value.muted { font-weight: 400; color: var(--text-muted); }

  .badge-info    { background:#e7f0ff; color:#2f6fed; }
  .badge-warning { background:#fff4e0; color:#c8790a; }
  .badge-danger  { background:#fdecea; color:#c0362c; }

  .hero-head {
    display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap;
    background: linear-gradient(135deg, #12213d, #1c2f52);
    color: #fff; border-radius: 14px; padding: 24px 26px; margin-bottom: 20px;
  }
  .hero-head .tid { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1.3rem; font-weight: 700; letter-spacing: .03em; }
  .hero-head .ref { font-size: .78rem; color: #a7b0c6; margin-top: 4px; }
  .hero-head .route { font-size: .95rem; margin-top: 12px; display: flex; align-items: center; gap: 10px; }
  .hero-head .route .city { font-weight: 700; }
  .hero-head .route i { color: #f2a93b; }
  .hero-head .badges { display: flex; gap: 8px; flex-wrap: wrap; }
  .hero-actions { display: flex; gap: 8px; flex-wrap: wrap; }

  .amount-strip {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
    gap: 12px; margin-top: 6px;
  }
  .amount-strip .item {
    background: #f7f9fd; border: 1px solid #e4e8f2; border-radius: 10px; padding: 12px 14px;
  }
  .amount-strip .item .label { font-size: .7rem; text-transform: uppercase; color: var(--text-muted); }
  .amount-strip .item .value { font-weight: 700; font-size: 1.02rem; margin-top: 3px; }
  .amount-strip .item.due .value { color: #c0362c; }

  .mini-timeline { position: relative; padding-left: 22px; }
  .mini-timeline::before { content:''; position:absolute; left:5px; top:4px; bottom:4px; width:2px; background:var(--border,#eee); }
  .mini-tl-event { position: relative; padding-bottom: 18px; }
  .mini-tl-event:last-child { padding-bottom: 0; }
  .mini-tl-event::before {
    content:''; position:absolute; left:-22px; top:3px; width:10px; height:10px; border-radius:50%;
    background:#4b5fd6; border:2px solid #fff; box-shadow:0 0 0 2px #4b5fd6;
  }
  .mini-tl-event:first-child::before { background:#1b7a34; box-shadow:0 0 0 2px #1b7a34; }
  .mini-tl-time { font-size: .72rem; color: var(--text-muted); font-family: ui-monospace, monospace; }
  .mini-tl-title { font-weight: 700; font-size: .87rem; margin: 2px 0; }
  .mini-tl-desc { font-size: .8rem; color: var(--text-muted); }

  .empty-mini { font-size: .85rem; color: var(--text-muted); padding: 8px 0; }
</style>

<div class="app-shell">

  <!-- ===================== SIDEBAR ===================== -->
  <?php require __DIR__ . '/includes/sidebar.php'; ?>

  <!-- ===================== MAIN COLUMN ===================== -->
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
        <div class="icon-btn" title="Notifications"><i class="fa-regular fa-bell"></i><span class="dot"></span></div>
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
          <div class="breadcrumb">Biome <span class="sep">/</span> <a href="transport_manage.php" class="current" style="text-decoration:none;">Transport Bookings</a> <span class="sep">/</span> <span class="current">Booking Details</span></div>
          <h1>Booking overview</h1>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <a href="transport_manage.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to list</a>
        </div>
      </div>

      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="flash-success" style="background:#e6f4ea;border:1px solid #bfe4c9;color:#1b7a34;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:.9rem;">
          <?= e($_SESSION['flash_success']) ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
      <?php endif; ?>

      <!-- ===================== Hero header ===================== -->
      <div class="hero-head">
        <div>
          <div class="tid"><i class="fa-solid fa-barcode"></i> <?= e($booking['tracking_id']) ?></div>
          <div class="ref">Ref: <?= e($booking['booking_reference'] ?: '—') ?> &nbsp;·&nbsp; Created <?= e(dt_view($booking['created_at'])) ?></div>
          <div class="route">
            <span class="city"><?= e($booking['pickup_city']) ?></span>
            <i class="fa-solid fa-arrow-right-long"></i>
            <span class="city"><?= e($booking['delivery_city']) ?></span>
          </div>
          <div class="badges" style="margin-top:12px;">
            <?= status_badge_view($booking['status'], $STATUS_LIST) ?>
            <?= status_badge_view($booking['payment_status'], $PAYMENT_STATUS_LIST) ?>
            <?= status_badge_view($booking['priority'], $PRIORITY_LIST) ?>
          </div>
        </div>
        <div class="hero-actions">
          <a href="transport_edit.php?id=<?= $bookingId ?>" class="btn btn-primary"><i class="fa-solid fa-pen"></i> Edit</a>
          <a href="transport_timeline.php?id=<?= $bookingId ?>" class="btn btn-secondary"><i class="fa-solid fa-timeline"></i> Manage timeline</a>
          <a href="transport_payment.php?id=<?= $bookingId ?>" class="btn btn-secondary"><i class="fa-solid fa-sack-dollar"></i> Record payment</a>
          <a href="transport_invoice.php?id=<?= $bookingId ?>" class="btn btn-secondary"><i class="fa-solid fa-file-invoice"></i> Invoice</a>
          <a href="transport_track.php?tracking_id=<?= urlencode($booking['tracking_id']) ?>" class="btn btn-ghost" target="_blank"><i class="fa-solid fa-arrow-up-right-from-square"></i> Public view</a>
          <form method="post" action="transport_delete.php" style="display:inline;"
                onsubmit="return confirm('Delete this booking? It will be moved to trash.');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $bookingId ?>">
            <input type="hidden" name="redirect" value="transport_manage.php">
            <button type="submit" class="btn btn-danger"><i class="fa-solid fa-trash"></i> Delete</button>
          </form>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:2fr 1fr;gap:18px;align-items:start;">
        <div>

          <!-- ============ Customer ============ -->
          <div class="panel">
            <div class="panel-head"><h3><i class="fa-solid fa-user"></i> Customer information</h3></div>
            <div class="info-grid">
              <div class="info-item"><div class="label">Customer name</div><div class="value"><?= e($booking['customer_name']) ?></div></div>
              <div class="info-item"><div class="label">Company</div><div class="value <?= $booking['company_name'] ? '' : 'muted' ?>"><?= e($booking['company_name'] ?: 'Not provided') ?></div></div>
              <div class="info-item"><div class="label">Phone</div><div class="value mono"><?= e($booking['phone']) ?></div></div>
              <div class="info-item"><div class="label">Alternate phone</div><div class="value mono <?= $booking['alternate_phone'] ? '' : 'muted' ?>"><?= e($booking['alternate_phone'] ?: '—') ?></div></div>
              <div class="info-item"><div class="label">Email</div><div class="value <?= $booking['email'] ? '' : 'muted' ?>"><?= e($booking['email'] ?: '—') ?></div></div>
              <div class="info-item"><div class="label">Customer code</div><div class="value mono <?= $booking['customer_code'] ? '' : 'muted' ?>"><?= e($booking['customer_code'] ?: 'Walk-in / one-off') ?></div></div>
            </div>
          </div>

          <!-- ============ Route ============ -->
          <div class="panel">
            <div class="panel-head"><h3><i class="fa-solid fa-route"></i> Pickup &amp; delivery</h3></div>
            <div class="info-grid">
              <div class="info-item">
                <div class="label">Pickup address</div>
                <div class="value" style="font-weight:500;"><?= nl2br(e($booking['pickup_address'])) ?></div>
              </div>
              <div class="info-item">
                <div class="label">Pickup contact</div>
                <div class="value"><?= e($booking['pickup_contact_name'] ?: $booking['customer_name']) ?></div>
                <div class="value mono muted" style="font-size:.8rem;"><?= e($booking['pickup_contact_phone'] ?: $booking['phone']) ?></div>
              </div>
              <div class="info-item">
                <div class="label">Delivery address</div>
                <div class="value" style="font-weight:500;"><?= nl2br(e($booking['delivery_address'])) ?></div>
              </div>
              <div class="info-item">
                <div class="label">Delivery contact</div>
                <div class="value"><?= e($booking['delivery_contact_name'] ?: '—') ?></div>
                <div class="value mono muted" style="font-size:.8rem;"><?= e($booking['delivery_contact_phone'] ?: '—') ?></div>
              </div>
            </div>
          </div>

          <!-- ============ Cargo ============ -->
          <div class="panel">
            <div class="panel-head"><h3><i class="fa-solid fa-box"></i> Cargo details</h3></div>
            <div class="info-grid">
              <div class="info-item"><div class="label">Cargo type</div><div class="value"><?= e($booking['cargo_type'] ?: '—') ?></div></div>
              <div class="info-item"><div class="label">Weight</div><div class="value"><?= $booking['cargo_weight'] ? e(rtrim(rtrim((string) $booking['cargo_weight'], '0'), '.') . ' ' . $booking['cargo_weight_unit']) : '—' ?></div></div>
              <div class="info-item"><div class="label">Volume</div><div class="value"><?= $booking['cargo_volume'] ? e((string) $booking['cargo_volume']) . ' cbm' : '—' ?></div></div>
              <div class="info-item"><div class="label">Package count</div><div class="value"><?= $booking['package_count'] ? (int) $booking['package_count'] : '—' ?></div></div>
              <div class="info-item"><div class="label">Declared value</div><div class="value"><?= $booking['cargo_value'] ? inr_view((float) $booking['cargo_value']) : '—' ?></div></div>
              <div class="info-item">
                <div class="label">Handling flags</div>
                <div class="value" style="display:flex;gap:6px;flex-wrap:wrap;">
                  <?php if ($booking['fragile']): ?><span class="badge badge-warning">Fragile</span><?php endif; ?>
                  <?php if ($booking['hazardous']): ?><span class="badge badge-danger">Hazardous</span><?php endif; ?>
                  <?php if ($booking['temperature_controlled']): ?><span class="badge badge-info">Temp-controlled</span><?php endif; ?>
                  <?php if (!$booking['fragile'] && !$booking['hazardous'] && !$booking['temperature_controlled']): ?><span class="value muted" style="font-weight:400;">None</span><?php endif; ?>
                </div>
              </div>
              <?php if ($booking['cargo_description']): ?>
                <div class="info-item" style="grid-column: 1 / -1;"><div class="label">Description</div><div class="value" style="font-weight:500;"><?= nl2br(e($booking['cargo_description'])) ?></div></div>
              <?php endif; ?>
            </div>
          </div>

          <!-- ============ Vehicle / Driver / Schedule ============ -->
          <div class="panel">
            <div class="panel-head"><h3><i class="fa-solid fa-truck"></i> Vehicle, driver &amp; schedule</h3></div>
            <div class="info-grid">
              <div class="info-item"><div class="label">Vehicle requested</div><div class="value"><?= e($booking['vehicle_type_requested'] ?: '—') ?></div></div>
              <div class="info-item"><div class="label">Assigned vehicle</div><div class="value mono <?= $booking['registration_number'] ? '' : 'muted' ?>"><?= e($booking['registration_number'] ?: 'Unassigned') ?></div></div>
              <div class="info-item"><div class="label">Assigned driver</div><div class="value <?= $booking['driver_name'] ? '' : 'muted' ?>"><?= e($booking['driver_name'] ?: 'Unassigned') ?></div></div>
              <div class="info-item"><div class="label">Driver phone</div><div class="value mono <?= $booking['driver_phone'] ? '' : 'muted' ?>"><?= e($booking['driver_phone'] ?: '—') ?></div></div>
              <div class="info-item"><div class="label">Pickup scheduled</div><div class="value"><?= e(dt_view($booking['pickup_date'], 'd M Y')) ?><?= $booking['pickup_time'] ? ' · ' . e(dt_view($booking['pickup_time'], 'h:i A')) : '' ?></div></div>
              <div class="info-item"><div class="label">Expected delivery</div><div class="value"><?= $booking['expected_delivery_date'] ? e(dt_view($booking['expected_delivery_date'], 'd M Y')) : 'TBD' ?><?= $booking['expected_delivery_time'] ? ' · ' . e(dt_view($booking['expected_delivery_time'], 'h:i A')) : '' ?></div></div>
              <div class="info-item"><div class="label">Actual pickup</div><div class="value <?= $booking['actual_pickup_time'] ? '' : 'muted' ?>"><?= e(dt_view($booking['actual_pickup_time'])) ?></div></div>
              <div class="info-item"><div class="label">Actual delivery</div><div class="value <?= $booking['actual_delivery_time'] ? '' : 'muted' ?>"><?= e(dt_view($booking['actual_delivery_time'])) ?></div></div>
              <div class="info-item"><div class="label">Est. distance / duration</div><div class="value"><?= $booking['estimated_distance'] ? e((string) $booking['estimated_distance']) . ' km' : '—' ?><?= $booking['estimated_duration'] ? ' · ' . (int) $booking['estimated_duration'] . ' min' : '' ?></div></div>
            </div>
          </div>

          <!-- ============ Notes ============ -->
          <?php if ($booking['special_instruction'] || $booking['customer_notes'] || $booking['internal_notes']): ?>
          <div class="panel">
            <div class="panel-head"><h3><i class="fa-solid fa-note-sticky"></i> Notes &amp; instructions</h3></div>
            <div class="info-grid">
              <?php if ($booking['special_instruction']): ?>
                <div class="info-item" style="grid-column:1/-1;"><div class="label">Special instruction <span class="badge badge-info" style="margin-left:4px;">Customer-visible</span></div><div class="value" style="font-weight:500;"><?= nl2br(e($booking['special_instruction'])) ?></div></div>
              <?php endif; ?>
              <?php if ($booking['customer_notes']): ?>
                <div class="info-item" style="grid-column:1/-1;"><div class="label">Customer notes <span class="badge badge-info" style="margin-left:4px;">Customer-visible</span></div><div class="value" style="font-weight:500;"><?= nl2br(e($booking['customer_notes'])) ?></div></div>
              <?php endif; ?>
              <?php if ($booking['internal_notes']): ?>
                <div class="info-item" style="grid-column:1/-1;"><div class="label">Internal notes <span class="badge badge-muted" style="margin-left:4px;">Admin only</span></div><div class="value" style="font-weight:500;"><?= nl2br(e($booking['internal_notes'])) ?></div></div>
              <?php endif; ?>
            </div>
          </div>
          <?php endif; ?>

          <!-- ============ Documents ============ -->
          <?php if ($documents): ?>
          <div class="panel">
            <div class="panel-head"><h3><i class="fa-solid fa-paperclip"></i> Documents</h3><span class="sub"><?= count($documents) ?> file(s)</span></div>
            <div class="table-scroll">
              <table class="data-table">
                <thead><tr><th>Type</th><th>Title</th><th>Uploaded</th><th></th></tr></thead>
                <tbody>
                  <?php foreach ($documents as $doc): ?>
                    <tr>
                      <td><?= e($doc['document_type'] ?: '—') ?></td>
                      <td><?= e($doc['document_title'] ?: $doc['file_name']) ?></td>
                      <td><span class="mono-time"><?= e(dt_view($doc['created_at'])) ?></span></td>
                      <td><a href="../<?= e($doc['file_path']) ?>" target="_blank" class="btn btn-small btn-ghost"><i class="fa-solid fa-download"></i></a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
          <?php endif; ?>

        </div>

        <div>
          <!-- ============ Pricing summary ============ -->
          <div class="panel">
            <div class="panel-head"><h3><i class="fa-solid fa-sack-dollar"></i> Pricing</h3></div>
            <div class="amount-strip">
              <div class="item"><div class="label">Total</div><div class="value"><?= inr_view((float) $booking['total_amount']) ?></div></div>
              <div class="item"><div class="label">GST (<?= e((string) $booking['gst_percentage']) ?>%)</div><div class="value"><?= inr_view((float) $booking['gst_amount']) ?></div></div>
              <div class="item"><div class="label">Discount</div><div class="value">-<?= inr_view((float) $booking['discount_amount']) ?></div></div>
              <div class="item"><div class="label">Other charges</div><div class="value">+<?= inr_view((float) $booking['other_charges']) ?></div></div>
              <div class="item"><div class="label">Net amount</div><div class="value" style="color:#1b7a34;"><?= inr_view((float) $booking['net_amount']) ?></div></div>
              <div class="item"><div class="label">Advance</div><div class="value"><?= inr_view((float) $booking['advance_amount']) ?></div></div>
              <div class="item"><div class="label">Paid</div><div class="value"><?= inr_view((float) $booking['paid_amount']) ?></div></div>
              <div class="item due"><div class="label">Balance due</div><div class="value"><?= inr_view((float) $booking['balance_amount']) ?></div></div>
            </div>
            <div style="margin-top:14px;">
              <a href="transport_payment.php?id=<?= $bookingId ?>" class="btn btn-primary" style="width:100%;justify-content:center;"><i class="fa-solid fa-plus"></i> Record a payment</a>
            </div>
          </div>

          <!-- ============ Payment history ============ -->
          <?php if ($payments): ?>
          <div class="panel">
            <div class="panel-head"><h3><i class="fa-solid fa-receipt"></i> Payment history</h3></div>
            <?php foreach ($payments as $p): ?>
              <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border,#eee);font-size:.85rem;">
                <div>
                  <strong><?= inr_view((float) $p['amount']) ?></strong><br>
                  <span style="color:var(--text-muted);font-size:.76rem;"><?= e(ucfirst((string) ($p['payment_method'] ?: 'N/A'))) ?> · <?= e(dt_view($p['payment_date'], 'd M Y')) ?></span>
                </div>
                <span class="badge badge-<?= $p['payment_status'] === 'success' || $p['payment_status'] === 'completed' ? 'success' : 'muted' ?>"><?= e(ucfirst((string) ($p['payment_status'] ?: '—'))) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <!-- ============ Timeline preview ============ -->
          <div class="panel">
            <div class="panel-head">
              <h3><i class="fa-solid fa-timeline"></i> Recent activity</h3>
              <a href="transport_timeline.php?id=<?= $bookingId ?>" class="btn btn-small btn-ghost">Manage <i class="fa-solid fa-arrow-right"></i></a>
            </div>
            <?php if (!$timelinePreview): ?>
              <div class="empty-mini">No timeline events yet.</div>
            <?php else: ?>
              <div class="mini-timeline">
                <?php foreach ($timelinePreview as $ev): ?>
                  <div class="mini-tl-event">
                    <div class="mini-tl-time"><?= e(dt_view($ev['created_at'])) ?></div>
                    <div class="mini-tl-title"><?= e($ev['title']) ?></div>
                    <?php if ($ev['description']): ?><div class="mini-tl-desc"><?= e($ev['description']) ?></div><?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
              <?php if ($timelineTotal > count($timelinePreview)): ?>
                <div style="text-align:center;margin-top:10px;">
                  <a href="transport_timeline.php?id=<?= $bookingId ?>" class="btn btn-small btn-ghost">View all <?= $timelineTotal ?> events</a>
                </div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </main>
  </div>
</div>

<script>
(function () {
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () { sidebar.classList.toggle('open'); });
  }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>