<?php
/**
 * track.php  (site root)  ->  https://biomeenterprises.com/track?id=TRK-26-00001
 * ---------------------------------------------------------------
 * Public shipment tracking.
 *  - Anyone with a tracking ID sees: status, route (cities only), progress, ETA, timeline.
 *  - After entering the LAST 4 DIGITS of the booking mobile number they also see:
 *    driver + vehicle, full addresses, charges and can open/print the invoice.
 *    (Attempts are rate-limited in the database so the 4 digits cannot be brute-forced.)
 */
declare(strict_types=1);

require_once __DIR__ . '/admin/config/database.php';
require_once __DIR__ . '/admin/includes/transport_lib.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

if (empty($_SESSION['tk_csrf'])) {
    $_SESSION['tk_csrf'] = bin2hex(random_bytes(32));
}

$co       = tl_settings()['company'];
$STATUS   = tl_statuses();
$FLOW     = tl_status_flow();
$tid      = tl_clean_tracking_id((string) ($_POST['id'] ?? $_GET['id'] ?? ''));
$booking  = null;
$timeline = [];
$notFound = false;
$verifyErr = '';
$dbError  = false;

try {
    $pdo = get_db();

    if ($tid !== '') {
        $stmt = $pdo->prepare(
            'SELECT tb.*, d.full_name AS driver_name, d.mobile AS driver_phone, v.registration_number
             FROM transport_bookings tb
             LEFT JOIN transport_drivers d ON d.id = tb.driver_id
             LEFT JOIN transport_vehicles v ON v.id = tb.vehicle_id
             WHERE tb.tracking_id = :tid AND tb.deleted_at IS NULL AND tb.tracking_enabled = 1 LIMIT 1'
        );
        $stmt->execute([':tid' => $tid]);
        $booking = $stmt->fetch() ?: null;
        $notFound = ($booking === null);

        // ---- verification (last 4 digits of phone) ----
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify') {
            if (!hash_equals((string) $_SESSION['tk_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
                $verifyErr = 'Your session expired. Please try again.';
            } else {
                $tail = preg_replace('/\D/', '', (string) ($_POST['tail'] ?? '')) ?? '';
                $ip   = tl_client_ip();

                $c1 = $pdo->prepare('SELECT COUNT(*) FROM transport_verify_attempts WHERE tracking_id = :t AND created_at >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)');
                $c1->execute([':t' => $tid]);
                $c2 = $pdo->prepare('SELECT COUNT(*) FROM transport_verify_attempts WHERE ip = :ip AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)');
                $c2->execute([':ip' => $ip]);

                if ((int) $c1->fetchColumn() >= 5 || (int) $c2->fetchColumn() >= 15) {
                    $verifyErr = 'Too many attempts. Please wait 30 minutes or call us for help.';
                } elseif (strlen($tail) !== 4) {
                    $verifyErr = 'Enter exactly 4 digits.';
                } elseif ($booking && tl_phone_tail((string) $booking['phone']) !== '' && hash_equals(tl_phone_tail((string) $booking['phone']), $tail)) {
                    $_SESSION['track_ok'][$tid] = time() + 1800;
                    session_regenerate_id(true);
                    header('Location: track?id=' . rawurlencode($tid));
                    exit;
                } else {
                    $pdo->prepare('INSERT INTO transport_verify_attempts (tracking_id, ip, created_at) VALUES (:t, :ip, NOW())')
                        ->execute([':t' => $tid, ':ip' => $ip]);
                    $verifyErr = 'Those digits do not match this booking.';
                }
            }
        }

        if ($booking) {
            $tl = $pdo->prepare('SELECT * FROM transport_booking_timeline WHERE booking_id = :b AND customer_visible = 1 ORDER BY created_at DESC, id DESC');
            $tl->execute([':b' => $booking['id']]);
            $timeline = $tl->fetchAll();
        }
    }
} catch (Throwable $e) {
    error_log('track.php: ' . $e->getMessage());
    $dbError = true;
}

$verified = $booking && !empty($_SESSION['track_ok'][$tid]) && (int) $_SESSION['track_ok'][$tid] > time();

$status   = $booking ? (string) $booking['status'] : '';
$meta     = $STATUS[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'class' => 'muted', 'icon' => 'fa-info-circle', 'msg' => ''];
$isBroken = in_array($status, ['cancelled', 'returned'], true);
$flowIdx  = array_search($status, $FLOW, true);
$percent  = ($flowIdx === false) ? 0 : (int) round($flowIdx / (count($FLOW) - 1) * 100);
$isFinal  = $isBroken || $status === 'delivered';

$currentLocation = '';
foreach ($timeline as $row) {
    if (!empty($row['current_location'])) {
        $currentLocation = (string) $row['current_location'];
        break;
    }
}
$lastUpdate = $timeline ? $timeline[0]['created_at'] : ($booking['updated_at'] ?? null);
$totals = $booking ? tl_compute_totals($booking) : null;
$shareUrl = $booking ? tl_track_url($tid) : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= $booking ? tl_e($tid) . ' - ' : '' ?>Track Your Shipment | <?= tl_e($co['name']) ?></title>
    <meta name="description" content="Track your Biome Enterprises shipment live with your tracking ID.">
    <meta name="robots" content="noindex, nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Roboto:wght@500;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">
    <link href="lib/animate/animate.min.css" rel="stylesheet">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        :root{--g:#198754;--gd:#0f4c2d;--gold:#ffc107;--ink:#14181a;--soft:#f4f8f6;--line:#e3ece7;--r:1rem}
        .tk-hero{background:linear-gradient(120deg,rgba(15,76,45,.96),rgba(20,24,26,.94)),url('img/fleet.avif') center/cover;padding:64px 0 96px;color:#fff}
        .tk-eyebrow{color:var(--gold);font-weight:700;letter-spacing:.14em;text-transform:uppercase;font-size:.78rem;margin-bottom:10px}
        .tk-hero h1{font-weight:800;font-size:clamp(1.8rem,4vw,2.8rem);margin-bottom:22px}
        .tk-search{display:flex;gap:10px;max-width:640px;background:#fff;border-radius:16px;padding:8px;box-shadow:0 20px 50px rgba(0,0,0,.25)}
        .tk-search input{flex:1;border:0;outline:0;padding:12px 16px;font-size:1.05rem;font-weight:600;letter-spacing:.04em;text-transform:uppercase;border-radius:12px;min-width:0}
        .tk-btn{border:0;border-radius:12px;padding:12px 26px;font-weight:700;background:var(--g);color:#fff;cursor:pointer;transition:.2s;text-decoration:none;display:inline-block}
        .tk-btn:hover{background:var(--gd);color:#fff}
        .tk-btn.ghost{background:#fff;color:var(--gd);border:1px solid var(--line)}
        .tk-btn.gold{background:var(--gold);color:#3b2f00}
        .tk-wrap{margin-top:-58px;position:relative;z-index:2;padding-bottom:70px}
        .tk-card{background:#fff;border:1px solid var(--line);border-radius:var(--r);box-shadow:0 16px 40px rgba(15,76,45,.1);padding:26px;margin-bottom:22px}
        .tk-card h3{font-size:1.02rem;font-weight:700;color:var(--gd);margin-bottom:16px;display:flex;align-items:center;gap:8px}
        .tk-card h3 i{color:var(--g)}
        .tk-status{display:flex;flex-wrap:wrap;gap:18px;align-items:center;justify-content:space-between}
        .tk-id{font-family:ui-monospace,Menlo,Consolas,monospace;font-weight:800;font-size:1.35rem;color:var(--gd);letter-spacing:.03em}
        .tk-badge{display:inline-flex;align-items:center;gap:8px;padding:8px 16px;border-radius:50px;font-weight:700;font-size:.92rem}
        .tk-badge.info{background:#e7f0ff;color:#2f6fed}.tk-badge.warning{background:#fff4e0;color:#b56a00}
        .tk-badge.success{background:#e5f6ec;color:#14663f}.tk-badge.danger{background:#fdecea;color:#b02a20}.tk-badge.muted{background:#eef1ef;color:#5b6b63}
        .tk-route{display:flex;align-items:center;gap:14px;font-size:1.15rem;font-weight:700;color:var(--ink);margin:16px 0 4px;flex-wrap:wrap}
        .tk-route i{color:var(--gold)}
        .tk-msg{color:#5b6b63;margin:0}
        .tk-progress{position:relative;margin:34px 6px 6px}
        .tk-track{position:absolute;left:0;right:0;top:19px;height:6px;background:#e6efe9;border-radius:6px}
        .tk-fill{position:absolute;left:0;top:19px;height:6px;background:linear-gradient(90deg,var(--g),#39c088);border-radius:6px;transition:width 1s ease;width:0}
        .tk-nodes{position:relative;display:flex;justify-content:space-between}
        .tk-node{width:14.28%;text-align:center;font-size:.72rem;color:#7a8a82;font-weight:600}
        .tk-node .dot{width:44px;height:44px;border-radius:50%;background:#fff;border:3px solid #dbe7e0;display:grid;place-items:center;margin:0 auto 8px;color:#a7b7ae;font-size:.95rem;transition:.4s}
        .tk-node.done .dot{background:var(--g);border-color:var(--g);color:#fff}
        .tk-node.now .dot{background:#fff;border-color:var(--g);color:var(--g);box-shadow:0 0 0 6px rgba(25,135,84,.16);animation:tkPulse 1.8s infinite}
        .tk-node.done,.tk-node.now{color:var(--gd)}
        @keyframes tkPulse{50%{box-shadow:0 0 0 12px rgba(25,135,84,0)}}
        .tk-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:16px}
        .tk-item .l{font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;color:#7a8a82;margin-bottom:3px}
        .tk-item .v{font-weight:600;color:var(--ink);word-break:break-word}
        .tk-item .v.mono{font-family:ui-monospace,Menlo,monospace}
        .tk-tl{position:relative;padding-left:34px;margin:0;list-style:none}
        .tk-tl:before{content:'';position:absolute;left:11px;top:6px;bottom:6px;width:2px;background:#dbe7e0}
        .tk-tl li{position:relative;padding-bottom:22px}
        .tk-tl li:last-child{padding-bottom:0}
        .tk-tl li .pin{position:absolute;left:-34px;top:0;width:24px;height:24px;border-radius:50%;background:#fff;border:2px solid #cfe0d6;display:grid;place-items:center;font-size:.62rem;color:#8aa197}
        .tk-tl li:first-child .pin{background:var(--g);border-color:var(--g);color:#fff;box-shadow:0 0 0 5px rgba(25,135,84,.15)}
        .tk-tl .t{font-weight:700;color:var(--ink)}
        .tk-tl .d{color:#5b6b63;font-size:.9rem;margin:2px 0}
        .tk-tl .m{font-size:.78rem;color:#7a8a82}
        .tk-lock{background:linear-gradient(135deg,#fffbea,#fff);border:1px dashed #e6c650}
        .tk-lock form{display:flex;gap:10px;flex-wrap:wrap;margin-top:12px}
        .tk-lock input{border:1px solid #d9c98a;border-radius:10px;padding:11px 14px;width:150px;font-weight:700;letter-spacing:.3em;text-align:center}
        .tk-err{color:#b02a20;font-size:.88rem;margin-top:8px}
        .tk-empty{text-align:center;padding:34px 10px}
        .tk-empty i{font-size:2.4rem;color:#c3d2c9;margin-bottom:10px}
        .tk-alert{border-radius:14px;padding:16px 18px;margin-bottom:22px;font-weight:600}
        .tk-alert.danger{background:#fdecea;color:#8a2a22;border:1px solid #f5c6c2}
        .tk-pay{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}
        .tk-pay div{background:var(--soft);border-radius:12px;padding:12px 14px}
        .tk-pay small{display:block;color:#7a8a82;text-transform:uppercase;font-size:.66rem;letter-spacing:.06em}
        .tk-pay strong{font-size:1.05rem}
        @media(max-width:768px){.tk-node span{display:none}.tk-node .dot{width:34px;height:34px;font-size:.78rem}.tk-track,.tk-fill{top:14px}.tk-search{flex-direction:column}}
    </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>

<section class="tk-hero">
    <div class="container">
        <div class="tk-eyebrow">Shipment Tracking</div>
        <h1>Where is my cargo?</h1>
        <form class="tk-search" action="track" method="get" role="search">
            <input type="text" name="id" placeholder="Enter tracking ID e.g. TRK-26-00001" maxlength="40" value="<?= tl_e($tid) ?>" autocomplete="off" aria-label="Tracking ID" required>
            <button type="submit" class="tk-btn"><i class="fa fa-search me-2"></i>Track</button>
        </form>
    </div>
</section>

<div class="container tk-wrap">

<?php if ($dbError): ?>
    <div class="tk-card tk-empty"><i class="fa fa-plug"></i><h4>Tracking is temporarily unavailable</h4>
        <p class="text-muted mb-0">Please try again in a few minutes or call <?= tl_e($co['phone']) ?>.</p></div>

<?php elseif ($tid === ''): ?>
    <div class="tk-card tk-empty"><i class="fa fa-map-marked-alt"></i><h4>Enter your tracking ID above</h4>
        <p class="text-muted">You received it on screen and by e-mail when you booked. It looks like <strong>TRK-26-00001</strong>.</p>
        <a href="transport-booking" class="tk-btn"><i class="fa fa-truck me-2"></i>Book a new shipment</a></div>

<?php elseif ($notFound): ?>
    <div class="tk-card tk-empty"><i class="fa fa-search"></i><h4>We couldn't find “<?= tl_e($tid) ?>”</h4>
        <p class="text-muted">Check the ID for typing mistakes, or call our dispatch desk on <strong><?= tl_e($co['phone']) ?></strong>.</p></div>

<?php else: ?>

    <?php if ($isBroken): ?>
        <div class="tk-alert danger"><i class="fa fa-exclamation-triangle me-2"></i><?= tl_e($meta['msg']) ?> Please contact us on <?= tl_e($co['phone']) ?> for details.</div>
    <?php endif; ?>

    <div class="tk-card">
        <div class="tk-status">
            <div>
                <div class="tk-id"><?= tl_e($booking['tracking_id']) ?></div>
                <div class="tk-route"><span><?= tl_e($booking['pickup_city']) ?></span><i class="fa fa-long-arrow-alt-right"></i><span><?= tl_e($booking['drop_city']) ?></span></div>
                <p class="tk-msg"><?= tl_e($meta['msg']) ?></p>
            </div>
            <div class="text-lg-end">
                <span class="tk-badge <?= tl_e($meta['class']) ?>"><i class="fa <?= tl_e($meta['icon']) ?>"></i><?= tl_e($meta['label']) ?></span>
                <div class="small text-muted mt-2">Last update: <?= tl_e(tl_dt($lastUpdate)) ?></div>
            </div>
        </div>

        <?php if (!$isBroken): ?>
        <div class="tk-progress" aria-label="Shipment progress">
            <div class="tk-track"></div>
            <div class="tk-fill" id="tkFill" data-w="<?= (int) $percent ?>"></div>
            <div class="tk-nodes">
                <?php foreach ($FLOW as $i => $st): $cls = ($flowIdx !== false && $i < $flowIdx) ? 'done' : (($flowIdx === $i) ? 'now' : ''); ?>
                    <div class="tk-node <?= $cls ?>"><div class="dot"><i class="fa <?= tl_e($STATUS[$st]['icon']) ?>"></i></div><span><?= tl_e($STATUS[$st]['label']) ?></span></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="tk-card">
                <h3><i class="fa fa-stream"></i> Journey timeline</h3>
                <?php if (!$timeline): ?>
                    <p class="text-muted mb-0">No updates yet. Check back soon.</p>
                <?php else: ?>
                    <ul class="tk-tl">
                        <?php foreach ($timeline as $ev): $es = $STATUS[$ev['status'] ?? ''] ?? null; ?>
                            <li>
                                <span class="pin"><i class="fa <?= tl_e($es['icon'] ?? 'fa-circle') ?>"></i></span>
                                <div class="t"><?= tl_e($ev['title'] ?: ($es['label'] ?? 'Update')) ?></div>
                                <?php if (!empty($ev['description'])): ?><div class="d"><?= tl_e($ev['description']) ?></div><?php endif; ?>
                                <div class="m"><i class="far fa-clock me-1"></i><?= tl_e(tl_dt($ev['created_at'])) ?><?php if (!empty($ev['current_location'])): ?> &nbsp;·&nbsp; <i class="fa fa-map-marker-alt me-1"></i><?= tl_e($ev['current_location']) ?><?php endif; ?></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="tk-card">
                <h3><i class="fa fa-info-circle"></i> Shipment details</h3>
                <div class="tk-grid">
                    <?php if ($currentLocation !== ''): ?><div class="tk-item"><div class="l">Current location</div><div class="v"><?= tl_e($currentLocation) ?></div></div><?php endif; ?>
                    <div class="tk-item"><div class="l">Expected delivery</div><div class="v"><?= tl_e($booking['expected_delivery'] ? tl_dt($booking['expected_delivery'], 'd M Y') : 'To be confirmed') ?></div></div>
                    <div class="tk-item"><div class="l">Pickup date</div><div class="v"><?= tl_e(tl_dt($booking['scheduled_pickup'], 'd M Y')) ?></div></div>
                    <div class="tk-item"><div class="l">Cargo</div><div class="v"><?= tl_e($booking['cargo_type'] ?: '—') ?></div></div>
                    <div class="tk-item"><div class="l">Vehicle type</div><div class="v"><?= tl_e($booking['vehicle_type'] ?: '—') ?></div></div>
                    <?php if ($booking['lr_number']): ?><div class="tk-item"><div class="l">LR number</div><div class="v mono"><?= tl_e($booking['lr_number']) ?></div></div><?php endif; ?>
                    <?php if ($status === 'delivered' && $booking['delivered_at']): ?>
                        <div class="tk-item"><div class="l">Delivered on</div><div class="v"><?= tl_e(tl_dt($booking['delivered_at'])) ?></div></div>
                        <?php if ($booking['received_by']): ?><div class="tk-item"><div class="l">Received by</div><div class="v"><?= tl_e($booking['received_by']) ?></div></div><?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($verified): ?>
                <div class="tk-card">
                    <h3><i class="fa fa-user-check"></i> Your booking</h3>
                    <div class="tk-grid">
                        <div class="tk-item"><div class="l">Driver</div><div class="v"><?= tl_e($booking['driver_name'] ?: 'Being assigned') ?></div></div>
                        <div class="tk-item"><div class="l">Driver phone</div><div class="v mono"><?= $booking['driver_phone'] ? '<a href="tel:' . tl_e($booking['driver_phone']) . '" class="text-success">' . tl_e($booking['driver_phone']) . '</a>' : '—' ?></div></div>
                        <div class="tk-item"><div class="l">Vehicle no.</div><div class="v mono"><?= tl_e($booking['registration_number'] ?: '—') ?></div></div>
                        <div class="tk-item" style="grid-column:1/-1"><div class="l">Pickup address</div><div class="v"><?= nl2br(tl_e($booking['pickup_address'])) ?></div></div>
                        <div class="tk-item" style="grid-column:1/-1"><div class="l">Delivery address</div><div class="v"><?= nl2br(tl_e($booking['drop_address'])) ?></div></div>
                    </div>
                </div>

                <div class="tk-card">
                    <h3><i class="fa fa-file-invoice"></i> Billing</h3>
                    <?php if ($totals['grand'] > 0): ?>
                        <div class="tk-pay">
                            <div><small>Total</small><strong><?= tl_e(tl_inr($totals['grand'])) ?></strong></div>
                            <div><small>Paid</small><strong class="text-success"><?= tl_e(tl_inr($totals['paid'])) ?></strong></div>
                            <div><small>Balance</small><strong class="<?= $totals['balance'] > 0.009 ? 'text-danger' : '' ?>"><?= tl_e(tl_inr(max(0, $totals['balance']))) ?></strong></div>
                        </div>
                        <div class="mt-3 d-flex flex-wrap gap-2">
                            <a class="tk-btn" href="transport-invoice?id=<?= rawurlencode($tid) ?>" target="_blank" rel="noopener"><i class="fa fa-file-invoice me-2"></i>View / print invoice</a>
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">Your quote and invoice will appear here once our team has confirmed the charges.</p>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="tk-card tk-lock">
                    <h3><i class="fa fa-lock"></i> See driver, address &amp; invoice</h3>
                    <p class="mb-0 small text-muted">For your privacy, enter the <strong>last 4 digits</strong> of the mobile number you gave when booking.</p>
                    <form method="post" action="track">
                        <input type="hidden" name="action" value="verify">
                        <input type="hidden" name="id" value="<?= tl_e($tid) ?>">
                        <input type="hidden" name="csrf_token" value="<?= tl_e($_SESSION['tk_csrf']) ?>">
                        <input type="text" name="tail" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" placeholder="••••" autocomplete="off" required aria-label="Last 4 digits of mobile number">
                        <button class="tk-btn gold" type="submit">Unlock</button>
                    </form>
                    <?php if ($verifyErr): ?><div class="tk-err"><i class="fa fa-exclamation-circle me-1"></i><?= tl_e($verifyErr) ?></div><?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="tk-card">
                <h3><i class="fa fa-share-alt"></i> Share &amp; help</h3>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="tk-btn ghost" id="copyLink" data-url="<?= tl_e($shareUrl) ?>"><i class="fa fa-link me-2"></i>Copy link</button>
                    <a class="tk-btn ghost" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode('Track my shipment ' . $tid . ': ' . $shareUrl) ?>"><i class="fab fa-whatsapp me-2"></i>WhatsApp</a>
                    <a class="tk-btn ghost" href="tel:<?= tl_e(preg_replace('/[^0-9+]/', '', $co['phone'])) ?>"><i class="fa fa-phone-alt me-2"></i>Call dispatch</a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var fill = document.getElementById('tkFill');
    if (fill) { setTimeout(function () { fill.style.width = fill.dataset.w + '%'; }, 150); }

    var copy = document.getElementById('copyLink');
    if (copy) {
        copy.addEventListener('click', function () {
            if (navigator.clipboard) { navigator.clipboard.writeText(copy.dataset.url); }
            copy.innerHTML = '<i class="fa fa-check me-2"></i>Copied';
        });
    }
    <?php if ($booking && !$isFinal && $_SERVER['REQUEST_METHOD'] === 'GET'): ?>
    setTimeout(function () { window.location.reload(); }, 90000); // live refresh while the shipment is moving
    <?php endif; ?>
});
</script>
</body>
</html>
