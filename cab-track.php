<?php
/**
 * cab-track.php -> https://biomeenterprises.com/cab-track?ref=CAB-26-00001
 * Customers see their booking status and timeline after entering the reference AND the
 * last 4 digits of the mobile number used for the booking. Lookups are rate-limited per IP
 * (stored in the database) so the 4 digits cannot be brute-forced.
 */
declare(strict_types=1);

require_once __DIR__ . '/admin/config/database.php';
require_once __DIR__ . '/admin/includes/cab_lib.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

$co = tl_settings()['company'];
$ref = strtoupper(trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? '')));
$ref = preg_match('/^[A-Z]{2,6}-\d{2}-\d{3,8}$/', $ref) ? $ref : '';
$tail = preg_replace('/\D/', '', (string) ($_POST['mobile4'] ?? $_GET['m'] ?? ''));
$error = '';
$b = null;
$timeline = [];
$payments = [];
$vehicle = $driver = null;

$pdo = get_db();
$ip = tl_client_ip();

$failed = static function (PDO $pdo, string $ip): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE action = 'cab_track_fail' AND ip_address = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $s->execute([':ip' => $ip]);
    return (int) $s->fetchColumn();
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_SESSION['cab_track_csrf']) || !hash_equals($_SESSION['cab_track_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif ($ref === '' || strlen($tail) !== 4) {
        $error = 'Enter your booking reference and the last 4 digits of your mobile number.';
    } elseif ($failed($pdo, $ip) >= 8) {
        $error = 'Too many unsuccessful attempts. Please try again in 15 minutes or call us.';
    } else {
        $s = $pdo->prepare('SELECT * FROM cab_booking_requests WHERE reference = :r AND deleted_at IS NULL LIMIT 1');
        $s->execute([':r' => $ref]);
        $row = $s->fetch();
        if ($row && hash_equals(substr(cab_clean_phone($row['mobile_number']), -4), $tail)) {
            $b = $row;
        } else {
            $pdo->prepare("INSERT INTO activity_log (admin_id, action, details, ip_address) VALUES (NULL, 'cab_track_fail', :d, :ip)")
                ->execute([':d' => substr($ref, 0, 40), ':ip' => $ip]);
            $error = 'We could not find a booking with those details. Please check and try again.';
        }
    }
}

if ($b) {
    $t = $pdo->prepare('SELECT status, title, description, created_at FROM cab_booking_timeline WHERE booking_id = :i AND customer_visible = 1 ORDER BY id DESC');
    $t->execute([':i' => $b['id']]);
    $timeline = $t->fetchAll();
    if ($b['vehicle_id'] && in_array($b['status'], ['assigned', 'on_trip', 'completed'], true)) {
        $vehicle = $pdo->query('SELECT name, reg_number, color FROM cab_vehicles WHERE id = ' . (int) $b['vehicle_id'])->fetch() ?: null;
        if ($b['driver_id']) {
            $driver = $pdo->query('SELECT full_name, phone FROM cab_drivers WHERE id = ' . (int) $b['driver_id'])->fetch() ?: null;
        }
    }
}
$_SESSION['cab_track_csrf'] = $_SESSION['cab_track_csrf'] ?? bin2hex(random_bytes(32));
$csrf = $_SESSION['cab_track_csrf'];

$steps = ['new' => 'Requested', 'confirmed' => 'Confirmed', 'assigned' => 'Vehicle assigned', 'on_trip' => 'On trip', 'completed' => 'Completed'];
$order = array_keys($steps);
$cur = $b ? ($b['status'] === 'contacted' || $b['status'] === 'quoted' ? 'new' : $b['status']) : '';
$curIdx = array_search($cur, $order, true);
$cancelled = $b && $b['status'] === 'cancelled';
$tot = $b ? cab_totals($b) : null;
$settings = cab_settings($pdo);
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Track Your Cab Booking | <?= tl_e($co['name']) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">
    <link href="lib/animate/animate.min.css" rel="stylesheet">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        body{font-family:Inter,sans-serif;background:#f4f8f6}
        .ct-hero{background:linear-gradient(135deg,#0f4c2d,#198754);color:#fff;padding:56px 0 70px}
        .ct-hero h1{font-weight:800;margin:6px 0 20px}.ct-eyebrow{text-transform:uppercase;letter-spacing:.12em;font-size:.78rem;color:#ffd666;font-weight:700}
        .ct-card{background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(15,76,45,.1);padding:26px;margin-top:-40px;margin-bottom:24px}
        .ct-search{display:flex;gap:10px;flex-wrap:wrap;max-width:720px}.ct-search input{flex:1;min-width:180px;border:0;border-radius:10px;padding:13px 16px}
        .ct-search button{border:0;border-radius:10px;background:#f0a500;color:#14181a;font-weight:700;padding:13px 26px}
        .ct-steps{display:flex;justify-content:space-between;position:relative;margin:26px 0 8px}
        .ct-steps:before{content:'';position:absolute;top:17px;left:6%;right:6%;height:4px;background:#dfe9e3}
        .ct-step{position:relative;text-align:center;flex:1;font-size:.78rem;color:#6b7a72}
        .ct-step .d{width:36px;height:36px;border-radius:50%;background:#dfe9e3;color:#fff;margin:0 auto 8px;display:flex;align-items:center;justify-content:center;position:relative;z-index:1}
        .ct-step.done .d{background:#198754}.ct-step.now .d{background:#f0a500;box-shadow:0 0 0 5px rgba(240,165,0,.25)}.ct-step.done,.ct-step.now{color:#14181a;font-weight:600}
        .ct-tl{border-left:2px solid #dfe9e3;margin-left:6px;padding-left:20px}.ct-tl .e{position:relative;padding-bottom:18px}
        .ct-tl .e:before{content:'';position:absolute;left:-27px;top:4px;width:12px;height:12px;border-radius:50%;background:#198754;border:2px solid #fff;box-shadow:0 0 0 2px #198754}
        .ct-kv{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px}.ct-kv .k{font-size:.72rem;text-transform:uppercase;color:#6b7a72;letter-spacing:.06em}.ct-kv .v{font-weight:600}
        @media(max-width:640px){.ct-step span{display:none}}
    </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>

<section class="ct-hero">
    <div class="container">
        <div class="ct-eyebrow">Cab Booking</div>
        <h1>Track your booking</h1>
        <form class="ct-search" method="post" action="cab-track" role="search">
            <input type="hidden" name="csrf" value="<?= tl_e($csrf) ?>">
            <input type="text" name="ref" placeholder="Reference e.g. CAB-26-00001" value="<?= tl_e($ref) ?>" required maxlength="20" autocomplete="off" aria-label="Booking reference">
            <input type="text" name="mobile4" placeholder="Last 4 digits of mobile" inputmode="numeric" pattern="\d{4}" maxlength="4" required autocomplete="off" aria-label="Last 4 digits of your mobile number" value="<?= tl_e($tail) ?>">
            <button type="submit"><i class="fa fa-search mr-2 me-2"></i>Track</button>
        </form>
    </div>
</section>

<div class="container pb-5">
<?php if ($error): ?><div class="ct-card"><div class="alert alert-danger mb-0"><?= tl_e($error) ?></div></div><?php endif; ?>
<?php if (!$b && !$error): ?>
    <div class="ct-card text-center text-muted"><i class="fa fa-taxi fa-2x mb-3" style="color:#198754"></i><p class="mb-0">Enter your reference number (it was shown after booking and sent by e-mail) and the last 4 digits of your mobile number.</p></div>
<?php endif; ?>

<?php if ($b): ?>
    <div class="ct-card">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
            <div><div class="text-muted small">Reference</div><h3 class="mb-1" style="font-family:monospace"><?= tl_e($b['reference']) ?></h3>
                <span class="badge" style="background:<?= $cancelled ? '#b02a20' : '#198754' ?>;font-size:.85rem"><?= tl_e(cab_statuses()[$b['status']]['label'] ?? $b['status']) ?></span></div>
            <div class="text-end"><div class="text-muted small">Journey</div><strong><?= $b['journey_date'] ? tl_e(date('D, d M Y', strtotime((string) $b['journey_date']))) : '-' ?><?= $b['pickup_time'] ? ' &middot; ' . tl_e(substr((string) $b['pickup_time'], 0, 5)) : '' ?></strong></div>
        </div>

        <?php if ($cancelled): ?>
            <div class="alert alert-danger mt-3 mb-0">This booking was cancelled<?= $b['cancel_reason'] ? ': ' . tl_e($b['cancel_reason']) : '.' ?> Call <?= tl_e($settings['support_phone']) ?> if this is unexpected.</div>
        <?php elseif ($curIdx !== false): ?>
            <div class="ct-steps">
            <?php foreach ($steps as $k => $label): $i = array_search($k, $order, true); $cls = $i < $curIdx ? 'done' : ($i === $curIdx ? 'now' : ''); ?>
                <div class="ct-step <?= $cls ?>"><div class="d"><i class="fa <?= $i < $curIdx || ($i === $curIdx && $k === 'completed') ? 'fa-check' : 'fa-circle' ?>" style="font-size:.8rem"></i></div><span><?= tl_e($label) ?></span></div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="ct-card" style="margin-top:0">
                <h5 class="mb-3">Trip details</h5>
                <div class="ct-kv">
                    <div><div class="k">Service</div><div class="v"><?= tl_e($b['service_type'] ?: '-') ?></div></div>
                    <div><div class="k">Vehicle type</div><div class="v"><?= tl_e($b['vehicle_type'] ?: '-') ?></div></div>
                    <div><div class="k">Pickup</div><div class="v"><?= tl_e($b['pickup_location'] ?: '-') ?></div></div>
                    <div><div class="k">Destination</div><div class="v"><?= tl_e($b['destination'] ?: '-') ?></div></div>
                    <div><div class="k">Duration</div><div class="v"><?= (int) $b['days'] ?> day<?= (int) $b['days'] > 1 ? 's' : '' ?></div></div>
                    <div><div class="k">Passengers</div><div class="v"><?= tl_e($b['passengers'] ?: '-') ?></div></div>
                </div>
                <?php if ($vehicle): ?>
                    <hr><h6>Your vehicle &amp; driver</h6>
                    <div class="ct-kv">
                        <div><div class="k">Vehicle</div><div class="v"><?= tl_e($vehicle['name']) ?><?= $vehicle['color'] ? ' (' . tl_e($vehicle['color']) . ')' : '' ?></div></div>
                        <div><div class="k">Number plate</div><div class="v"><?= tl_e($vehicle['reg_number'] ?: '-') ?></div></div>
                        <?php if ($driver): ?><div><div class="k">Driver</div><div class="v"><?= tl_e($driver['full_name']) ?></div></div>
                        <div><div class="k">Driver phone</div><div class="v"><a href="tel:<?= tl_e(cab_clean_phone($driver['phone'])) ?>"><?= tl_e($driver['phone']) ?></a></div></div><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ((float) $b['total_fare'] > 0): ?>
                    <hr><h6><?= $b['fare_source'] === 'estimate' && in_array($b['status'], ['new', 'contacted', 'quoted'], true) ? 'Estimated fare' : 'Fare' ?></h6>
                    <div class="ct-kv">
                        <div><div class="k">Total (incl. GST)</div><div class="v"><?= tl_e(tl_inr($tot['total'])) ?></div></div>
                        <div><div class="k">Paid</div><div class="v"><?= tl_e(tl_inr($tot['paid'])) ?></div></div>
                        <div><div class="k">Balance</div><div class="v"><?= tl_e(tl_inr($tot['balance'])) ?></div></div>
                    </div>
                    <?php if (in_array($b['status'], ['new', 'contacted', 'quoted'], true)): ?><p class="text-muted small mt-2 mb-0">This is an estimate. The final fare is confirmed by our team.</p><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="ct-card" style="margin-top:0">
                <h5 class="mb-3">Updates</h5>
                <div class="ct-tl">
                <?php foreach ($timeline as $e): ?>
                    <div class="e"><div class="text-muted small"><?= tl_e(tl_dt($e['created_at'], 'd M Y, h:i A')) ?></div><strong><?= tl_e($e['title']) ?></strong><?php if ($e['description']): ?><div class="text-muted small"><?= tl_e($e['description']) ?></div><?php endif; ?></div>
                <?php endforeach; ?>
                </div>
                <hr>
                <a class="btn btn-success rounded-pill btn-sm" href="tel:<?= tl_e(cab_clean_phone($settings['support_phone'])) ?>"><i class="fa fa-phone me-1"></i> Call us</a>
                <a class="btn btn-outline-success rounded-pill btn-sm" target="_blank" rel="noopener" href="https://wa.me/<?= tl_e($settings['whatsapp_number']) ?>?text=<?= tl_e(rawurlencode('Hello, regarding my cab booking ' . $b['reference'])) ?>"><i class="fab fa-whatsapp me-1"></i> WhatsApp</a>
            </div>
        </div>
    </div>
<?php endif; ?>
</div>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
