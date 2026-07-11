<?php
declare(strict_types=1);

// Enable full error reporting (Development only)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

$bookingId = (int) ($_GET['id'] ?? $_POST['booking_id'] ?? 0);
if ($bookingId <= 0) {
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

function status_badge_tl(string $value, array $map): string
{
    $meta = $map[$value] ?? ['label' => ucfirst(str_replace('_', ' ', $value ?: 'N/A')), 'class' => 'muted'];
    return '<span class="badge badge-' . e($meta['class']) . '">' . e($meta['label']) . '</span>';
}
function dt_tl(?string $v, string $fmt = 'd M Y, h:i A'): string
{
    if (!$v || $v === '0000-00-00' || $v === '0000-00-00 00:00:00') return '—';
    $ts = strtotime($v);
    return $ts ? date($fmt, $ts) : '—';
}

$stmt = $pdo->prepare('SELECT * FROM transport_bookings WHERE id = :id');
$stmt->execute([':id' => $bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    $_SESSION['flash_error'] = 'That booking could not be found.';
    header('Location: transport_manage.php');
    exit;
}

$errors = [];

/* =====================================================================
   HANDLE: add a new timeline event
===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_event') {
    csrf_require_valid();

    $status              = (string) ($_POST['status'] ?? '');
    $title               = trim((string) ($_POST['title'] ?? ''));
    $description         = trim((string) ($_POST['description'] ?? ''));
    $isCustomerVisible   = isset($_POST['is_customer_visible']) ? 1 : 0;
    $updateBookingStatus = isset($_POST['update_booking_status']) ? 1 : 0;

    if (!array_key_exists($status, $STATUS_LIST)) $errors[] = 'Select a valid status.';
    if ($title === '') $errors[] = 'Enter a short title for this event.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $pdo->prepare(
                'INSERT INTO transport_booking_timeline
                    (booking_id, tracking_id, status, title, description, is_customer_visible, created_by_admin_id, created_at)
                 VALUES
                    (:bid, :tid, :status, :title, :desc, :visible, :admin, NOW())'
            )->execute([
                ':bid'     => $bookingId,
                ':tid'     => $booking['tracking_id'],
                ':status'  => $status,
                ':title'   => $title,
                ':desc'    => $description ?: null,
                ':visible' => $isCustomerVisible,
                ':admin'   => $_SESSION['admin_id'],
            ]);

            if ($updateBookingStatus) {
                $pdo->prepare(
                    'UPDATE transport_bookings SET status = :status, updated_by = :admin, updated_at = NOW() WHERE id = :id'
                )->execute([
                    ':status' => $status,
                    ':admin'  => $_SESSION['admin_id'],
                    ':id'     => $bookingId,
                ]);
            }

            $pdo->commit();

            log_activity((int) $_SESSION['admin_id'], 'transport_timeline_event_added', "booking_id={$bookingId} status={$status}");
            $_SESSION['flash_success'] = 'Timeline event added.';
            header('Location: timeline.php?id=' . $bookingId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Timeline event insert failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving the event.';
        }
    }
}

/* =====================================================================
   HANDLE: delete a timeline event
===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_event') {
    csrf_require_valid();
    $eventId = (int) ($_POST['event_id'] ?? 0);
    if ($eventId > 0) {
        $pdo->prepare('DELETE FROM transport_booking_timeline WHERE id = :eid AND booking_id = :bid')
            ->execute([':eid' => $eventId, ':bid' => $bookingId]);
        log_activity((int) $_SESSION['admin_id'], 'transport_timeline_event_deleted', "event_id={$eventId}");
        $_SESSION['flash_success'] = 'Timeline event removed.';
    }
    header('Location: timeline.php?id=' . $bookingId);
    exit;
}

/* =====================================================================
   HANDLE: toggle customer visibility
===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_visibility') {
    csrf_require_valid();
    $eventId = (int) ($_POST['event_id'] ?? 0);
    if ($eventId > 0) {
        $pdo->prepare(
            'UPDATE transport_booking_timeline SET is_customer_visible = 1 - is_customer_visible WHERE id = :eid AND booking_id = :bid'
        )->execute([':eid' => $eventId, ':bid' => $bookingId]);
    }
    header('Location: timeline.php?id=' . $bookingId);
    exit;
}

$eventsStmt = $pdo->prepare('SELECT * FROM transport_booking_timeline WHERE booking_id = :id ORDER BY created_at DESC, id DESC');
$eventsStmt->execute([':id' => $bookingId]);
$events = $eventsStmt->fetchAll();

function safe_scalar_transport(PDO $pdo, string $sql, $default = 0)
{
    try { $val = $pdo->query($sql)->fetchColumn(); return $val === false ? $default : $val; }
    catch (Throwable $e) { return $default; }
}
$sidebarUserCount = (int) safe_scalar_transport($pdo, 'SELECT COUNT(*) FROM users');
$sidebarBlogCount = (int) safe_scalar_transport($pdo, 'SELECT COUNT(*) FROM blog_posts');
$sidebarTransportCount = (int) safe_scalar_transport($pdo, 'SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL');

$pageTitle = 'Timeline — ' . $booking['tracking_id'];
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/css/dashboard.css">

<style>
  .tl-grid { display: grid; grid-template-columns: 1fr 1.3fr; gap: 20px; align-items: start; }
  @media (max-width: 980px) { .tl-grid { grid-template-columns: 1fr; } }

  .card-panel { background: var(--surface, #fff); border: 1px solid var(--border, #e9ece9); border-radius: 12px; padding: 22px; }
  .card-panel h2 { font-size: .95rem; margin: 0 0 4px; }
  .card-panel .card-sub { font-size: .8rem; color: var(--text-muted); margin-bottom: 16px; }

  .form-group { margin-bottom: 16px; }
  .form-group label { display: block; font-weight: 600; margin-bottom: 6px; font-size: .85rem; }
  .form-group input, .form-group select, .form-group textarea {
    width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 8px;
    border: 1px solid var(--border, #e2e5e3); font-size: .9rem; font-family: inherit;
  }
  .check-row { display:flex; align-items:center; gap:8px; margin-bottom: 14px; font-size: .85rem; }
  .check-row input { width:auto; }

  .form-errors { background: #fdecea; border: 1px solid #f5c2bd; color: #7a271a; padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: .88rem; }
  .form-errors ul { margin: 0; padding-left: 18px; }

  .timeline { position: relative; padding-left: 26px; }
  .timeline::before { content:''; position:absolute; left:6px; top:4px; bottom:4px; width:2px; background: var(--border, #eee); }
  .tl-event { position: relative; padding-bottom: 22px; }
  .tl-event:last-child { padding-bottom: 0; }
  .tl-event::before { content:''; position:absolute; left:-26px; top:3px; width:12px; height:12px; border-radius:50%; background:#1b7a34; border:2px solid #fff; box-shadow:0 0 0 2px var(--border,#eee); }
  .tl-event.hidden-event::before { background:#aaa; }
  .tl-top { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:2px; }
  .tl-title { font-weight:700; font-size:.9rem; }
  .tl-time { font-size:.74rem; color: var(--text-muted); white-space:nowrap; }
  .tl-desc { font-size:.83rem; color: var(--text-muted); margin-top:2px; }
  .tl-actions { display:flex; gap:8px; margin-top:8px; }
  .tl-actions form { display:inline; }
  .tl-actions button { background:none; border:none; padding:0; font-size:.74rem; color: var(--text-muted); cursor:pointer; text-decoration:underline; }
  .tl-actions button:hover { color:#c0362c; }

  .empty-state { text-align:center; padding: 30px 10px; color: var(--text-muted); }
  .empty-state i { font-size: 1.8rem; margin-bottom: 10px; display:block; }
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
          <div class="breadcrumb">Biome <span class="sep">/</span> <a href="transport_manage.php" style="color:inherit;text-decoration:none;">Transport Bookings</a> <span class="sep">/</span> <span class="current"><?= e($booking['tracking_id']) ?></span></div>
          <h1>Timeline — <?= e($booking['tracking_id']) ?></h1>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <span>Current status: <?= status_badge_tl((string) $booking['status'], $STATUS_LIST) ?></span>
          <a href="payment.php?id=<?= $bookingId ?>" class="btn btn-ghost"><i class="fa-solid fa-indian-rupee-sign"></i> Payments</a>
          <a href="invoice.php?id=<?= $bookingId ?>" class="btn btn-ghost" target="_blank"><i class="fa-solid fa-file-invoice"></i> Invoice</a>
          <a href="transport_view.php?id=<?= $bookingId ?>" class="btn btn-ghost"><i class="fa-solid fa-eye"></i> View Booking</a>
          <a href="transport_manage.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back</a>
        </div>
      </div>

      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div style="background:#e6f4ea;border:1px solid #bfe4c9;color:#1b7a34;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:.9rem;"><?= e($_SESSION['flash_success']) ?></div>
        <?php unset($_SESSION['flash_success']); ?>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="form-errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <div class="tl-grid">
        <!-- ---------- Add event form ---------- -->
        <div class="card-panel">
          <h2>Add timeline event</h2>
          <div class="card-sub">Customer-visible events show up on the public tracking page.</div>

          <form method="post" action="timeline.php?id=<?= $bookingId ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_event">
            <input type="hidden" name="booking_id" value="<?= $bookingId ?>">

            <div class="form-group">
              <label>Status</label>
              <select name="status" required>
                <?php foreach ($STATUS_LIST as $k => $meta): ?>
                  <option value="<?= e($k) ?>" <?= $booking['status'] === $k ? 'selected' : '' ?>><?= e($meta['label']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label>Title</label>
              <input type="text" name="title" maxlength="150" placeholder="e.g. Out for delivery" required>
            </div>

            <div class="form-group">
              <label>Description <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
              <textarea name="description" rows="3" placeholder="Additional details for this event…"></textarea>
            </div>

            <div class="check-row">
              <input type="checkbox" name="is_customer_visible" id="is_customer_visible" value="1" checked>
              <label for="is_customer_visible" style="margin:0;font-weight:400;">Visible to customer</label>
            </div>

            <div class="check-row">
              <input type="checkbox" name="update_booking_status" id="update_booking_status" value="1">
              <label for="update_booking_status" style="margin:0;font-weight:400;">Also update booking status to this value</label>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
              <i class="fa-solid fa-plus"></i> Add event
            </button>
          </form>
        </div>

        <!-- ---------- Timeline list ---------- -->
        <div class="card-panel">
          <h2>Event history</h2>
          <div class="card-sub"><?= count($events) ?> event<?= count($events) === 1 ? '' : 's' ?> recorded.</div>

          <?php if (!$events): ?>
            <div class="empty-state">
              <i class="fa-solid fa-timeline"></i>
              <p>No timeline events yet.</p>
              <span>Events you add will show up here.</span>
            </div>
          <?php else: ?>
            <div class="timeline">
              <?php foreach ($events as $ev): ?>
                <div class="tl-event <?= (int) $ev['is_customer_visible'] === 0 ? 'hidden-event' : '' ?>">
                  <div class="tl-top">
                    <span class="tl-title"><?= e($ev['title']) ?></span>
                    <span class="tl-time"><?= e(dt_tl($ev['created_at'])) ?></span>
                  </div>
                  <div style="margin:2px 0;">
                    <?= status_badge_tl((string) $ev['status'], $STATUS_LIST) ?>
                    <?php if ((int) $ev['is_customer_visible'] === 0): ?>
                      <span class="badge badge-muted" style="font-size:.68rem;">Internal only</span>
                    <?php endif; ?>
                  </div>
                  <?php if ($ev['description']): ?>
                    <div class="tl-desc"><?= e($ev['description']) ?></div>
                  <?php endif; ?>
                  <div class="tl-actions">
                    <form method="post" action="timeline.php?id=<?= $bookingId ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="toggle_visibility">
                      <input type="hidden" name="event_id" value="<?= (int) $ev['id'] ?>">
                      <button type="submit"><?= (int) $ev['is_customer_visible'] === 1 ? 'Hide from customer' : 'Show to customer' ?></button>
                    </form>
                    <form method="post" action="timeline.php?id=<?= $bookingId ?>" onsubmit="return confirm('Remove this timeline event?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete_event">
                      <input type="hidden" name="event_id" value="<?= (int) $ev['id'] ?>">
                      <button type="submit">Delete</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
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
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>