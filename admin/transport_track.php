<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
// Public page — intentionally no require_admin(). Anyone with a tracking ID can view it.

$pdo = get_db();

$trackingId = strtoupper(trim((string) ($_GET['tracking_id'] ?? $_GET['id'] ?? '')));
$trackingId = preg_replace('/[^A-Z0-9\-]/', '', $trackingId) ?? '';
$trackingId = mb_substr($trackingId, 0, 40);

$booking      = null;
$timeline     = [];
$notFound     = false;

/* Ordered lifecycle used to draw the route strip. Cancelled/returned are
   handled separately since they break out of the normal forward flow. */
$ROUTE_STAGES = [
    'confirmed'        => ['label' => 'Confirmed',    'icon' => 'fa-clipboard-check'],
    'picked_up'        => ['label' => 'Picked Up',    'icon' => 'fa-box'],
    'in_transit'       => ['label' => 'In Transit',   'icon' => 'fa-truck-fast'],
    'out_for_delivery' => ['label' => 'Out For Delivery', 'icon' => 'fa-route'],
    'delivered'        => ['label' => 'Delivered',    'icon' => 'fa-circle-check'],
];
$STATUS_LABELS = [
    'pending'          => 'Pending Confirmation',
    'confirmed'        => 'Confirmed',
    'driver_assigned'  => 'Driver Assigned',
    'picked_up'        => 'Picked Up',
    'in_transit'        => 'In Transit',
    'out_for_delivery' => 'Out For Delivery',
    'delivered'        => 'Delivered',
    'cancelled'        => 'Cancelled',
    'returned'         => 'Returned',
];

if ($trackingId !== '') {
    $stmt = $pdo->prepare(
        "SELECT tb.*, d.full_name AS driver_name, d.mobile AS driver_phone, v.registration_number, v.vehicle_type AS vehicle_type_actual
         FROM transport_bookings tb
         LEFT JOIN transport_drivers d ON d.id = tb.driver_id
         LEFT JOIN transport_vehicles v ON v.id = tb.vehicle_id
         WHERE tb.tracking_id = :tid AND tb.deleted_at IS NULL AND tb.tracking_enabled = 1
         LIMIT 1"
    );
    $stmt->execute([':tid' => $trackingId]);
    $booking = $stmt->fetch();

    if ($booking) {
        $tlStmt = $pdo->prepare(
            'SELECT * FROM transport_booking_timeline
             WHERE booking_id = :bid AND customer_visible = 1
             ORDER BY created_at ASC, id ASC'
        );
        $tlStmt->execute([':bid' => $booking['id']]);
        $timeline = $tlStmt->fetchAll();
    } else {
        $notFound = true;
    }
}

function mask_phone(?string $phone): string
{
    if (!$phone) return '—';
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) < 4) return $phone;
    return str_repeat('•', max(0, strlen($digits) - 4)) . substr($digits, -4);
}

function inr_track(float $amount): string
{
    return '₹' . number_format($amount, 2);
}

$pageTitle = 'Track Your Shipment';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?><?= $booking ? ' — ' . e($booking['tracking_id']) : '' ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  :root {
    --ink:      #12213D;
    --ink-soft: #3E4C6B;
    --paper:    #F5F6F9;
    --card:     #FFFFFF;
    --line:     #E3E7F0;
    --amber:    #F2A93B;
    --amber-soft: #FEF3E0;
    --green:    #1F9D6F;
    --green-soft: #E4F5EE;
    --red:      #C0392B;
    --red-soft: #FBEAE8;
    --mono: 'IBM Plex Mono', ui-monospace, monospace;
    --display: 'Oswald', sans-serif;
    --body: 'Inter', sans-serif;
  }
  * { box-sizing: border-box; }
  html { scroll-behavior: smooth; }
  body {
    margin: 0; background: var(--paper); color: var(--ink);
    font-family: var(--body); line-height: 1.5;
    -webkit-font-smoothing: antialiased;
  }
  @media (prefers-reduced-motion: reduce) {
    * { animation-duration: 0.01ms !important; animation-iteration-count: 1 !important; transition-duration: 0.01ms !important; }
  }

  a { color: inherit; }
  :focus-visible { outline: 2px solid var(--amber); outline-offset: 2px; }

  /* ---------- Top bar ---------- */
  .topbar {
    background: var(--ink); color: #fff;
    padding: 16px 24px;
    display: flex; align-items: center; justify-content: space-between;
  }
  .topbar .brand {
    font-family: var(--display); font-weight: 600; letter-spacing: .04em;
    font-size: 1.15rem; text-transform: uppercase;
    display: flex; align-items: center; gap: 10px;
  }
  .topbar .brand i { color: var(--amber); }
  .topbar .tag { font-size: .75rem; color: #9fabcf; font-family: var(--mono); }

  .wrap { max-width: 880px; margin: 0 auto; padding: 36px 20px 80px; }

  /* ---------- Search hero ---------- */
  .hero {
    text-align: center; padding: 34px 20px 10px;
  }
  .hero h1 {
    font-family: var(--display); font-weight: 600; text-transform: uppercase;
    letter-spacing: .03em; font-size: clamp(1.6rem, 4vw, 2.3rem);
    margin: 0 0 8px;
  }
  .hero p { color: var(--ink-soft); margin: 0 0 26px; font-size: .95rem; }

  .search-form {
    display: flex; gap: 10px; max-width: 480px; margin: 0 auto;
    background: var(--card); border: 1px solid var(--line); border-radius: 10px;
    padding: 6px; box-shadow: 0 1px 2px rgba(18,33,61,.04);
  }
  .search-form input {
    flex: 1; border: none; background: transparent; padding: 10px 12px;
    font-family: var(--mono); font-size: .95rem; letter-spacing: .03em;
    text-transform: uppercase; color: var(--ink);
  }
  .search-form input:focus { outline: none; }
  .search-form input::placeholder { color: #a7b0c6; text-transform: none; letter-spacing: 0; font-family: var(--body); }
  .search-form button {
    background: var(--ink); color: #fff; border: none; border-radius: 7px;
    padding: 0 20px; font-weight: 600; font-size: .88rem; cursor: pointer;
    display: flex; align-items: center; gap: 8px;
    transition: background .15s ease;
  }
  .search-form button:hover { background: #1c2f52; }

  /* ---------- Not found ---------- */
  .not-found {
    max-width: 480px; margin: 30px auto 0; text-align: center;
    background: var(--red-soft); border: 1px solid #f0c8c3; color: var(--red);
    border-radius: 12px; padding: 22px 24px; font-size: .9rem;
  }
  .not-found i { font-size: 1.3rem; margin-bottom: 8px; display: block; }

  /* ---------- Waybill card ---------- */
  .waybill {
    background: var(--card); border: 1px solid var(--line); border-radius: 16px;
    margin-top: 30px; overflow: hidden;
    box-shadow: 0 1px 3px rgba(18,33,61,.05);
    position: relative;
  }
  .waybill::before, .waybill::after {
    content: ''; position: absolute; top: 50%; width: 22px; height: 22px;
    background: var(--paper); border-radius: 50%; transform: translateY(-50%);
  }
  .waybill::before { left: -11px; }
  .waybill::after { right: -11px; }
  .waybill-top {
    padding: 22px 28px; display: flex; justify-content: space-between; align-items: flex-start;
    flex-wrap: wrap; gap: 14px;
  }
  .waybill-id .label { font-size: .7rem; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-soft); }
  .waybill-id .code {
    font-family: var(--mono); font-weight: 600; font-size: 1.35rem; letter-spacing: .04em;
    color: var(--ink); margin-top: 2px;
  }
  .status-pill {
    font-family: var(--body); font-weight: 700; font-size: .78rem;
    padding: 7px 14px; border-radius: 999px; text-transform: uppercase; letter-spacing: .03em;
  }
  .status-pill.pending, .status-pill.confirmed, .status-pill.driver_assigned { background: var(--amber-soft); color: #9c6b13; }
  .status-pill.picked_up, .status-pill.in_transit, .status-pill.out_for_delivery { background: var(--amber-soft); color: #9c6b13; }
  .status-pill.delivered { background: var(--green-soft); color: var(--green); }
  .status-pill.cancelled, .status-pill.returned { background: var(--red-soft); color: var(--red); }

  .waybill-route {
    border-top: 1px dashed var(--line);
    padding: 20px 28px;
    display: grid; grid-template-columns: 1fr auto 1fr; gap: 14px; align-items: center;
  }
  .route-point .label { font-size: .68rem; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-soft); margin-bottom: 4px; }
  .route-point .city { font-family: var(--display); font-size: 1.15rem; font-weight: 600; }
  .route-point.dest { text-align: right; }
  .route-divider { color: var(--amber); font-size: 1.3rem; }

  /* ---------- Route strip (signature element) ---------- */
  .route-strip-wrap { margin: 34px 0 6px; }
  .route-strip-title { font-size: .78rem; text-transform: uppercase; letter-spacing: .08em; color: var(--ink-soft); margin-bottom: 18px; text-align: center; }
  .route-strip {
    position: relative; display: flex; justify-content: space-between; align-items: flex-start;
    padding: 0 6px;
  }
  .route-strip .lane {
    position: absolute; top: 17px; left: 6%; right: 6%; height: 2px;
    background: repeating-linear-gradient(to right, var(--line) 0 8px, transparent 8px 16px);
    z-index: 0;
  }
  .route-strip .lane .fill {
    position: absolute; top: 0; left: 0; height: 2px; background: var(--amber);
  }
  .stage { position: relative; z-index: 1; flex: 1; display: flex; flex-direction: column; align-items: center; text-align: center; }
  .stage .node {
    width: 34px; height: 34px; border-radius: 50%; background: var(--card);
    border: 2px solid var(--line); display: flex; align-items: center; justify-content: center;
    font-size: .78rem; color: #b8c0d6; transition: all .2s ease;
  }
  .stage.done .node { background: var(--green); border-color: var(--green); color: #fff; }
  .stage.current .node {
    background: var(--amber); border-color: var(--amber); color: #fff;
    animation: pulse 1.8s ease-in-out infinite;
  }
  @keyframes pulse {
    0%, 100% { box-shadow: 0 0 0 0 rgba(242,169,59,.45); }
    50% { box-shadow: 0 0 0 8px rgba(242,169,59,0); }
  }
  .stage .truck {
    position: absolute; top: -22px; font-size: .95rem; color: var(--amber);
  }
  .stage .stage-label {
    font-size: .72rem; margin-top: 8px; color: var(--ink-soft); font-weight: 500;
    max-width: 80px;
  }
  .stage.done .stage-label, .stage.current .stage-label { color: var(--ink); font-weight: 600; }

  .cancelled-banner {
    background: var(--red-soft); color: var(--red); border: 1px solid #f0c8c3;
    border-radius: 10px; padding: 14px 18px; margin: 30px 0 6px; font-size: .88rem;
    display: flex; align-items: center; gap: 10px;
  }

  /* ---------- Timeline ---------- */
  .section-title {
    font-family: var(--display); text-transform: uppercase; letter-spacing: .03em;
    font-size: 1rem; font-weight: 600; margin: 44px 0 18px;
    display: flex; align-items: center; gap: 8px;
  }
  .section-title i { color: var(--amber); }

  .timeline { position: relative; padding-left: 26px; }
  .timeline::before {
    content: ''; position: absolute; left: 6px; top: 6px; bottom: 6px; width: 2px;
    background: var(--line);
  }
  .tl-event { position: relative; padding-bottom: 26px; }
  .tl-event:last-child { padding-bottom: 0; }
  .tl-event::before {
    content: ''; position: absolute; left: -26px; top: 3px; width: 12px; height: 12px;
    border-radius: 50%; background: var(--amber); border: 2px solid #fff; box-shadow: 0 0 0 2px var(--amber);
  }
  .tl-event:first-child::before { background: var(--green); box-shadow: 0 0 0 2px var(--green); }
  .tl-time { font-family: var(--mono); font-size: .72rem; color: var(--ink-soft); }
  .tl-title { font-weight: 700; margin: 3px 0 2px; }
  .tl-desc { font-size: .87rem; color: var(--ink-soft); }
  .tl-loc { font-size: .78rem; color: #a7b0c6; margin-top: 3px; }
  .tl-loc i { margin-right: 4px; }

  .empty-timeline {
    text-align: center; color: var(--ink-soft); font-size: .88rem; padding: 20px;
    background: var(--card); border: 1px dashed var(--line); border-radius: 10px;
  }

  /* ---------- Details grid ---------- */
  .details-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 14px; margin-top: 8px;
  }
  .detail-card {
    background: var(--card); border: 1px solid var(--line); border-radius: 12px;
    padding: 16px 18px;
  }
  .detail-card .label { font-size: .7rem; text-transform: uppercase; letter-spacing: .07em; color: var(--ink-soft); margin-bottom: 6px; }
  .detail-card .value { font-weight: 600; font-size: .95rem; }
  .detail-card .value.mono { font-family: var(--mono); font-size: .88rem; }
  .detail-card .sub { font-size: .78rem; color: var(--ink-soft); margin-top: 2px; }

  .payment-strip {
    display: flex; justify-content: space-between; flex-wrap: wrap; gap: 14px;
    background: var(--ink); color: #fff; border-radius: 12px; padding: 18px 22px; margin-top: 8px;
  }
  .payment-strip .item .label { font-size: .68rem; text-transform: uppercase; letter-spacing: .07em; color: #9fabcf; }
  .payment-strip .item .value { font-family: var(--mono); font-weight: 600; font-size: 1.05rem; margin-top: 3px; }
  .payment-strip .item.due .value { color: var(--amber); }

  footer.site-foot {
    text-align: center; font-size: .78rem; color: #a7b0c6; padding: 30px 20px 10px;
  }

  @media (max-width: 640px) {
    .route-strip { flex-wrap: wrap; row-gap: 22px; }
    .route-strip .lane { display: none; }
    .stage { flex: 0 0 33%; }
    .waybill-route { grid-template-columns: 1fr; text-align: left; }
    .route-point.dest { text-align: left; }
    .route-divider { display: none; }
  }
</style>
</head>
<body>

<div class="topbar">
  <div class="brand"><i class="fa-solid fa-truck-fast"></i> Biome Transport</div>
  <div class="tag">Live shipment tracking</div>
</div>

<div class="wrap">

  <div class="hero">
    <h1>Track your shipment</h1>
    <p>Enter the tracking ID from your booking confirmation to see live status.</p>
    <form method="get" action="transport_track.php" class="search-form">
      <input type="text" name="tracking_id" placeholder="e.g. TRK-26-00042"
             value="<?= e($trackingId) ?>" maxlength="40" autocomplete="off" required>
      <button type="submit"><i class="fa-solid fa-magnifying-glass"></i> Track</button>
    </form>
  </div>

  <?php if ($notFound): ?>
    <div class="not-found">
      <i class="fa-solid fa-signal-slash"></i>
      We couldn't find a shipment for tracking ID <strong><?= e($trackingId) ?></strong>.
      Double-check the code from your booking confirmation and try again.
    </div>
  <?php endif; ?>

  <?php if ($booking):
      $status = $booking['status'];
      $isTerminalBad = in_array($status, ['cancelled', 'returned'], true);
      $stageKeys = array_keys($ROUTE_STAGES);
      $currentIndex = array_search($status, $stageKeys, true);
      // 'pending' and 'driver_assigned' map onto the strip as "before confirmed" / "same as confirmed"
      if ($currentIndex === false) {
          $currentIndex = ($status === 'pending') ? -1 : 0;
      }
  ?>

    <!-- ===================== Waybill card ===================== -->
    <div class="waybill">
      <div class="waybill-top">
        <div class="waybill-id">
          <div class="label">Tracking ID</div>
          <div class="code"><?= e($booking['tracking_id']) ?></div>
        </div>
        <span class="status-pill <?= e($status) ?>"><?= e($STATUS_LABELS[$status] ?? ucfirst($status)) ?></span>
      </div>
      <div class="waybill-route">
        <div class="route-point">
          <div class="label">From</div>
          <div class="city"><?= e($booking['pickup_city']) ?></div>
        </div>
        <div class="route-divider"><i class="fa-solid fa-arrow-right-long"></i></div>
        <div class="route-point dest">
          <div class="label">To</div>
          <div class="city"><?= e($booking['drop_city']) ?></div>
        </div>
      </div>
    </div>

    <?php if ($isTerminalBad): ?>
      <div class="cancelled-banner">
        <i class="fa-solid fa-triangle-exclamation"></i>
        This shipment was marked <strong><?= e($STATUS_LABELS[$status] ?? $status) ?></strong>. See the timeline below for details.
      </div>
    <?php else: ?>
      <!-- ===================== Route strip (signature element) ===================== -->
      <div class="route-strip-wrap">
        <div class="route-strip-title">Shipment progress</div>
        <div class="route-strip">
          <div class="lane">
            <?php
              $stageCount = count($ROUTE_STAGES);
              $fillPct = $currentIndex < 0 ? 0 : (($currentIndex) / max(1, $stageCount - 1)) * 100;
            ?>
            <div class="fill" style="width: <?= (float) $fillPct ?>%;"></div>
          </div>
          <?php foreach ($ROUTE_STAGES as $key => $meta): $i = array_search($key, $stageKeys, true); ?>
            <?php
              $cls = 'upcoming';
              if ($i < $currentIndex) $cls = 'done';
              elseif ($i === $currentIndex) $cls = 'current';
            ?>
            <div class="stage <?= $cls ?>">
              <?php if ($cls === 'current'): ?><i class="fa-solid fa-truck truck"></i><?php endif; ?>
              <div class="node">
                <i class="fa-solid <?= $cls === 'done' ? 'fa-check' : e($meta['icon']) ?>"></i>
              </div>
              <div class="stage-label"><?= e($meta['label']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- ===================== Timeline ===================== -->
    <div class="section-title"><i class="fa-solid fa-timeline"></i> Tracking history</div>
    <?php if (!$timeline): ?>
      <div class="empty-timeline">No tracking events yet. Check back once your shipment is picked up.</div>
    <?php else: ?>
      <div class="timeline">
        <?php foreach (array_reverse($timeline) as $ev): ?>
          <div class="tl-event">
            <div class="tl-time"><?= e(date('d M Y, h:i A', strtotime((string) $ev['created_at']))) ?></div>
            <div class="tl-title"><?= e($ev['title']) ?></div>
            <?php if ($ev['description']): ?><div class="tl-desc"><?= e($ev['description']) ?></div><?php endif; ?>
            <?php if ($ev['current_location']): ?><div class="tl-loc"><i class="fa-solid fa-location-dot"></i><?= e($ev['current_location']) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- ===================== Shipment details ===================== -->
    <div class="section-title"><i class="fa-solid fa-box-open"></i> Shipment details</div>
    <div class="details-grid">
      <div class="detail-card">
        <div class="label">Cargo</div>
        <div class="value"><?= e($booking['cargo_type'] ?: '—') ?></div>
        <?php if ($booking['cargo_weight']): ?>
          <div class="sub"><?= e(rtrim(rtrim((string) $booking['cargo_weight'], '0'), '.')) ?> <?= e($booking['cargo_unit']) ?><?= $booking['number_of_packages'] ? ' · ' . (int) $booking['number_of_packages'] . ' pkg' : '' ?></div>
        <?php endif; ?>
      </div>
      <div class="detail-card">
        <div class="label">Vehicle</div>
        <div class="value mono"><?= e($booking['registration_number'] ?: 'Not yet assigned') ?></div>
        <?php if ($booking['vehicle_type_actual']): ?><div class="sub"><?= e($booking['vehicle_type_actual']) ?></div><?php endif; ?>
      </div>
      <div class="detail-card">
        <div class="label">Driver</div>
        <div class="value"><?= e($booking['driver_name'] ?: 'Not yet assigned') ?></div>
        <?php if ($booking['driver_phone']): ?><div class="sub">Contact ending •<?= e(mask_phone($booking['driver_phone'])) ?></div><?php endif; ?>
      </div>
      <div class="detail-card">
        <div class="label">Pickup date</div>
        <div class="value"><?= e($booking['scheduled_pickup'] ? date('d M Y', strtotime((string) $booking['scheduled_pickup'])) : '—') ?></div>
        <?php if ($booking['scheduled_pickup']): ?><div class="sub"><?= e(date('h:i A', strtotime((string) $booking['scheduled_pickup']))) ?></div><?php endif; ?>
      </div>
      <div class="detail-card">
        <div class="label">Expected delivery</div>
        <div class="value"><?= e($booking['expected_delivery'] ? date('d M Y', strtotime((string) $booking['expected_delivery'])) : 'TBD') ?></div>
        <?php if ($booking['expected_delivery']): ?><div class="sub"><?= e(date('h:i A', strtotime((string) $booking['expected_delivery']))) ?></div><?php endif; ?>
      </div>
      <?php if ($booking['customer_notes']): ?>
        <div class="detail-card">
          <div class="label">Notes</div>
          <div class="value" style="font-weight:500;font-size:.87rem;"><?= e($booking['customer_notes']) ?></div>
        </div>
      <?php endif; ?>
    </div>

    <!-- ===================== Payment summary ===================== -->
    <div class="section-title"><i class="fa-solid fa-receipt"></i> Payment summary</div>
    <div class="payment-strip">
      <div class="item">
        <div class="label">Grand total</div>
        <div class="value"><?= e(inr_track((float) $booking['grand_total'])) ?></div>
      </div>
      <div class="item">
        <div class="label">Paid</div>
        <div class="value"><?= e(inr_track((float) $booking['paid_amount'])) ?></div>
      </div>
      <div class="item due">
        <div class="label">Balance due</div>
        <div class="value"><?= e(inr_track(max(0, (float) $booking['balance_amount']))) ?></div>
      </div>
      <div class="item">
        <div class="label">Payment status</div>
        <div class="value" style="text-transform:capitalize;"><?= e($booking['payment_status']) ?></div>
      </div>
    </div>

  <?php endif; ?>

</div>

<footer class="site-foot">
  &copy; <?= date('Y') ?> Biome Transport. Have a question about this shipment? Contact your booking coordinator.
</footer>

</body>
</html>