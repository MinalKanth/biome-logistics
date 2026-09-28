<?php
/**
 * transport-booking.php  (site root)  ->  https://biomeenterprises.com/transport-booking
 * ---------------------------------------------------------------
 * Public, customer-facing transport booking form.
 * Saves the request into transport_bookings as status "pending" (source "website"),
 * creates a tracking ID, writes the first timeline entry and e-mails the customer
 * and your team. The admin then prices/confirms it from the admin panel.
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

if (empty($_SESSION['tb_csrf'])) {
    $_SESSION['tb_csrf'] = bin2hex(random_bytes(32));
}
if (empty($_SESSION['tb_form_ts']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['tb_form_ts'] = time();
}

$co        = tl_settings()['company'];
$errors    = [];
$success   = null;
$vehicleTypes = tl_vehicle_types();
$cargoTypes   = tl_cargo_types();
$states       = tl_states();

if (!empty($_SESSION['tb_success'])) {
    $success = $_SESSION['tb_success'];
    unset($_SESSION['tb_success']);
}

$fields = [
    'customer_name', 'company_name', 'phone', 'email',
    'pickup_address', 'pickup_city', 'pickup_state', 'pickup_pincode',
    'drop_address', 'drop_city', 'drop_state', 'drop_pincode', 'drop_contact_person', 'drop_contact_number',
    'cargo_type', 'cargo_description', 'cargo_weight', 'cargo_unit', 'number_of_packages', 'vehicle_type',
    'pickup_date', 'pickup_time', 'customer_notes',
];
$old = array_fill_keys($fields, '');
$old['cargo_unit'] = 'ton';
$old['pickup_state'] = 'Assam';
$old['fragile'] = $old['hazardous'] = $old['temperature_controlled'] = 0;
$old['consent'] = 0;

/* which wizard step does each field live on (used to reopen the right step after an error) */
$stepOf = [
    'pickup_address' => 1, 'pickup_city' => 1, 'pickup_state' => 1, 'pickup_pincode' => 1,
    'drop_address' => 1, 'drop_city' => 1, 'drop_state' => 1, 'drop_pincode' => 1, 'pickup_date' => 1, 'pickup_time' => 1,
    'cargo_type' => 2, 'cargo_weight' => 2, 'number_of_packages' => 2, 'vehicle_type' => 2, 'cargo_description' => 2,
    'customer_name' => 3, 'phone' => 3, 'email' => 3, 'consent' => 3, 'drop_contact_number' => 1,
];

/* =============================================================
   HANDLE SUBMIT
============================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['tb_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
        $errors['__form'] = 'Your session expired. Please refresh the page and submit again.';
    } else {
        foreach ($fields as $f) {
            $old[$f] = trim(str_replace("\0", '', (string) ($_POST[$f] ?? '')));
        }
        foreach (['fragile', 'hazardous', 'temperature_controlled', 'consent'] as $flag) {
            $old[$flag] = isset($_POST[$flag]) ? 1 : 0;
        }

        // Bot checks: hidden honeypot + "filled in faster than a human could"
        $tooFast = (time() - (int) ($_SESSION['tb_form_ts'] ?? 0)) < 5;
        if (($_POST['website'] ?? '') !== '' || $tooFast) {
            $errors['__form'] = 'We could not submit your request. Please wait a few seconds and try again.';
        }

        // ---- validation ----
        $len = static fn(string $v): int => mb_strlen($v);
        if ($old['customer_name'] === '' || $len($old['customer_name']) < 2 || $len($old['customer_name']) > 150) {
            $errors['customer_name'] = 'Please enter your full name.';
        }
        $phoneDigits = preg_replace('/\D/', '', $old['phone']) ?? '';
        if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 13) {
            $errors['phone'] = 'Enter a valid mobile number (10 digits).';
        }
        if ($old['email'] !== '' && (!filter_var($old['email'], FILTER_VALIDATE_EMAIL) || $len($old['email']) > 150)) {
            $errors['email'] = 'Enter a valid e-mail address or leave it blank.';
        }
        foreach (['pickup_address' => 'pickup address', 'drop_address' => 'delivery address'] as $k => $label) {
            if ($len($old[$k]) < 5 || $len($old[$k]) > 500) {
                $errors[$k] = "Please enter the full {$label}.";
            }
        }
        foreach (['pickup_city' => 'pickup city', 'drop_city' => 'delivery city'] as $k => $label) {
            if ($old[$k] === '' || $len($old[$k]) > 100) {
                $errors[$k] = "Please enter the {$label}.";
            }
        }
        foreach (['pickup_pincode', 'drop_pincode'] as $k) {
            if ($old[$k] !== '' && !preg_match('/^[0-9]{6}$/', $old[$k])) {
                $errors[$k] = 'PIN code must be 6 digits.';
            }
        }
        if ($old['drop_contact_number'] !== '' && strlen(preg_replace('/\D/', '', $old['drop_contact_number']) ?? '') < 10) {
            $errors['drop_contact_number'] = 'Enter a valid receiver mobile number.';
        }
        if (!in_array($old['cargo_type'], $cargoTypes, true)) {
            $errors['cargo_type'] = 'Please choose a cargo type.';
        }
        if (!in_array($old['vehicle_type'], $vehicleTypes, true)) {
            $errors['vehicle_type'] = 'Please choose a vehicle type.';
        }
        if (!in_array($old['cargo_unit'], ['kg', 'ton'], true)) {
            $old['cargo_unit'] = 'ton';
        }
        if ($old['cargo_weight'] !== '' && (!is_numeric($old['cargo_weight']) || (float) $old['cargo_weight'] <= 0 || (float) $old['cargo_weight'] > 1000000)) {
            $errors['cargo_weight'] = 'Enter a valid weight.';
        }
        if ($old['number_of_packages'] !== '' && (!ctype_digit($old['number_of_packages']) || (int) $old['number_of_packages'] > 1000000)) {
            $errors['number_of_packages'] = 'Enter a whole number of packages.';
        }
        $pickupDt = DateTime::createFromFormat('!Y-m-d', $old['pickup_date']);
        $today    = new DateTime('today');
        if (!$pickupDt || $pickupDt->format('Y-m-d') !== $old['pickup_date']) {
            $errors['pickup_date'] = 'Choose a pickup date.';
        } elseif ($pickupDt < $today) {
            $errors['pickup_date'] = 'Pickup date cannot be in the past.';
        } elseif ($pickupDt > (clone $today)->modify('+1 year')) {
            $errors['pickup_date'] = 'Pickup date is too far ahead.';
        }
        if ($old['pickup_time'] !== '' && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $old['pickup_time'])) {
            $errors['pickup_time'] = 'Invalid time.';
        }
        if ($old['cargo_description'] !== '' && $len($old['cargo_description']) > 1000) {
            $errors['cargo_description'] = 'Please keep the description under 1000 characters.';
        }
        if ($len($old['customer_notes']) > 1000) {
            $errors['customer_notes'] = 'Please keep the notes under 1000 characters.';
        }
        if (!$old['consent']) {
            $errors['consent'] = 'Please tick the box so we can contact you about this booking.';
        }
        if ($old['pickup_state'] !== '' && !in_array($old['pickup_state'], $states, true)) {
            $old['pickup_state'] = '';
        }
        if ($old['drop_state'] !== '' && !in_array($old['drop_state'], $states, true)) {
            $old['drop_state'] = '';
        }

        // ---- save ----
        if (!$errors) {
            $pdo = get_db();
            try {
                $ip = tl_client_ip();
                $rl = $pdo->prepare("SELECT COUNT(*) FROM transport_bookings WHERE source = 'website' AND source_ip = :ip AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
                $rl->execute([':ip' => $ip]);
                if ((int) $rl->fetchColumn() >= 5) {
                    $errors['__form'] = 'Too many requests from your network. Please call us on ' . $co['phone'] . ' to book.';
                }
            } catch (Throwable $e) {
                error_log('transport-booking rate check: ' . $e->getMessage());
                $errors['__form'] = 'The booking system is being set up. Please call us on ' . $co['phone'] . '.';
            }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                $trackingId = tl_next_sequence($pdo, 'tracking_id', 'TRK');
                $enquiryRef = tl_next_sequence($pdo, 'enquiry_reference', 'ENQ');
                $when = $old['pickup_date'] . ' ' . ($old['pickup_time'] !== '' ? $old['pickup_time'] . ':00' : '09:00:00');

                $pdo->prepare(
                    "INSERT INTO transport_bookings (
                        tracking_id, enquiry_reference, customer_name, company_name, email, phone, service_type,
                        vehicle_type, cargo_type, cargo_description, cargo_weight, cargo_unit, number_of_packages,
                        fragile, hazardous, temperature_controlled,
                        pickup_address, pickup_city, pickup_state, pickup_pincode, pickup_contact_person, pickup_contact_number,
                        drop_address, drop_city, drop_state, drop_pincode, drop_contact_person, drop_contact_number,
                        status, priority, scheduled_pickup, customer_notes, tracking_enabled,
                        total_amount, gst_amount, toll_amount, fuel_charge, labour_charge, extra_charge, discount,
                        grand_total, advance_paid, paid_amount, balance_amount, payment_status,
                        source, source_ip, created_at, updated_at
                    ) VALUES (
                        :tid, :enq, :name, :company, :email, :phone, 'Transportation & Logistics',
                        :vehicle, :cargo, :cdesc, :weight, :unit, :pkgs,
                        :fragile, :hazard, :temp,
                        :paddr, :pcity, :pstate, :ppin, :pperson, :pnum,
                        :daddr, :dcity, :dstate, :dpin, :dperson, :dnum,
                        'pending', 'normal', :sched, :notes, 1,
                        0, 0, 0, 0, 0, 0, 0,
                        0, 0, 0, 0, 'unpaid',
                        'website', :ip, NOW(), NOW()
                    )"
                )->execute([
                    ':tid' => $trackingId, ':enq' => $enquiryRef, ':name' => $old['customer_name'],
                    ':company' => $old['company_name'] ?: null, ':email' => $old['email'] ?: null, ':phone' => $old['phone'],
                    ':vehicle' => $old['vehicle_type'], ':cargo' => $old['cargo_type'], ':cdesc' => $old['cargo_description'] ?: null,
                    ':weight' => $old['cargo_weight'] !== '' ? (float) $old['cargo_weight'] : null, ':unit' => $old['cargo_unit'],
                    ':pkgs' => $old['number_of_packages'] !== '' ? (int) $old['number_of_packages'] : null,
                    ':fragile' => (int) $old['fragile'], ':hazard' => (int) $old['hazardous'], ':temp' => (int) $old['temperature_controlled'],
                    ':paddr' => $old['pickup_address'], ':pcity' => $old['pickup_city'], ':pstate' => $old['pickup_state'] ?: null,
                    ':ppin' => $old['pickup_pincode'] ?: null, ':pperson' => $old['customer_name'], ':pnum' => $old['phone'],
                    ':daddr' => $old['drop_address'], ':dcity' => $old['drop_city'], ':dstate' => $old['drop_state'] ?: null,
                    ':dpin' => $old['drop_pincode'] ?: null, ':dperson' => $old['drop_contact_person'] ?: null,
                    ':dnum' => $old['drop_contact_number'] ?: null,
                    ':sched' => $when, ':notes' => $old['customer_notes'] ?: null, ':ip' => tl_client_ip(),
                ]);
                $bookingId = (int) $pdo->lastInsertId();

                tl_add_timeline($pdo, $bookingId, $trackingId, 'pending', 'Booking request received',
                    'We have received your request and our dispatch team will contact you shortly to confirm the quote.',
                    $old['pickup_city'], true, null);

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Public transport booking failed: ' . $e->getMessage());
                $errors['__form'] = 'Something went wrong while saving your booking. Please try again or call ' . $co['phone'] . '.';
            }
        }

        // ---- notifications (never block the customer if mail fails) ----
        if (!$errors && isset($trackingId, $bookingId)) {
            $route = tl_e($old['pickup_city']) . ' &rarr; ' . tl_e($old['drop_city']);
            $trackUrl = tl_track_url($trackingId);

            if ($old['email'] !== '') {
                $inner = '<p>Hi ' . tl_e($old['customer_name']) . ',</p>'
                    . '<p>Thank you for booking with ' . tl_e($co['name']) . '. Your request has been received and our dispatch team will call you shortly to confirm the quote.</p>'
                    . '<table role="presentation" cellpadding="6" style="background:#f4f8f6;border-radius:8px;width:100%;margin:14px 0">'
                    . '<tr><td style="color:#6b7a72">Tracking ID</td><td><strong style="font-size:17px">' . tl_e($trackingId) . '</strong></td></tr>'
                    . '<tr><td style="color:#6b7a72">Route</td><td>' . $route . '</td></tr>'
                    . '<tr><td style="color:#6b7a72">Cargo</td><td>' . tl_e($old['cargo_type']) . '</td></tr>'
                    . '<tr><td style="color:#6b7a72">Pickup date</td><td>' . tl_e(tl_dt($when, 'd M Y')) . '</td></tr></table>'
                    . '<p style="color:#6b7a72;font-size:13px">Keep your tracking ID safe. You will need the last 4 digits of your mobile number to view invoices.</p>';
                tl_send_mail($old['email'], $old['customer_name'], 'Booking received - ' . $trackingId,
                    tl_mail_shell('Your booking request is received', $inner, $trackUrl, 'Track your shipment'));
            }

            $notify = (string) tl_settings()['mail']['notify_to'];
            if ($notify !== '') {
                $adminUrl = tl_site_url() . '/admin/transport_view.php?id=' . $bookingId;
                $inner = '<p><strong>New online transport booking</strong></p>'
                    . '<p>' . tl_e($old['customer_name']) . ' &middot; ' . tl_e($old['phone']) . ($old['email'] ? ' &middot; ' . tl_e($old['email']) : '') . '</p>'
                    . '<p>' . $route . ' &middot; ' . tl_e($old['cargo_type']) . ' &middot; ' . tl_e($old['vehicle_type']) . '<br>Pickup: ' . tl_e(tl_dt($when, 'd M Y, h:i A')) . '</p>'
                    . '<p>Tracking ID: <strong>' . tl_e($trackingId) . '</strong></p>';
                tl_send_mail($notify, 'Dispatch', 'New booking ' . $trackingId . ' - ' . $old['pickup_city'] . ' to ' . $old['drop_city'],
                    tl_mail_shell('New booking to price & confirm', $inner, $adminUrl, 'Open in admin'), $old['email'] ?: null);
            }

            $_SESSION['tb_success'] = ['tid' => $trackingId, 'name' => $old['customer_name'], 'route' => $old['pickup_city'] . ' → ' . $old['drop_city'], 'emailed' => $old['email'] !== ''];
            $_SESSION['tb_csrf'] = bin2hex(random_bytes(32));
            header('Location: transport-booking#done');
            exit;
        }
    }
}

$startStep = 1;
if ($errors) {
    $startStep = 3;
    foreach (array_keys($errors) as $k) {
        if (isset($stepOf[$k])) {
            $startStep = min($startStep, $stepOf[$k]);
        }
    }
}
$minDate = date('Y-m-d');
$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="tb-err">' . tl_e($errors[$k]) . '</div>' : '';
$bad = static fn(string $k): string => isset($errors[$k]) ? ' is-invalid' : '';
$selected = static fn($a, $b): string => ((string) $a === (string) $b) ? ' selected' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Book Transport &amp; Freight Online | <?= tl_e($co['name']) ?></title>
    <meta name="description" content="Book a 32 ft container or open-body truck online with Biome Enterprises. Get a tracking ID instantly and follow your cargo across North-East India.">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="canonical" href="<?= tl_e(tl_site_url()) ?>/transport-booking">
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
        :root{--tb-green:#198754;--tb-green-dark:#0f4c2d;--tb-gold:#ffc107;--tb-ink:#14181a;--tb-soft:#f4f8f6;--tb-line:#e3ece7;--tb-radius:1rem}
        .tb-hero{background:linear-gradient(120deg,rgba(15,76,45,.96),rgba(20,24,26,.94)),url('img/fleet.avif') center/cover;padding:70px 0 90px;color:#fff;position:relative}
        .tb-hero h1{font-weight:800;font-size:clamp(1.9rem,4.2vw,3rem);margin-bottom:12px}
        .tb-hero p{color:#d5e2db;max-width:640px;font-size:1.05rem;margin:0}
        .tb-eyebrow{color:var(--tb-gold);font-weight:700;letter-spacing:.14em;text-transform:uppercase;font-size:.78rem;margin-bottom:10px}
        .tb-wrap{margin-top:-56px;position:relative;z-index:2;padding-bottom:70px}
        .tb-card{background:#fff;border:1px solid var(--tb-line);border-radius:var(--tb-radius);box-shadow:0 20px 50px rgba(15,76,45,.12);padding:28px}
        .tb-steps{display:flex;gap:10px;margin-bottom:26px}
        .tb-pill{flex:1;display:flex;align-items:center;gap:10px;padding:10px 14px;border-radius:12px;background:var(--tb-soft);color:#6b7a72;font-weight:600;font-size:.88rem;border:1px solid transparent;transition:.25s}
        .tb-pill span{width:26px;height:26px;border-radius:50%;background:#dbe7e0;color:#42564b;display:grid;place-items:center;font-size:.8rem;flex:none}
        .tb-pill.active{background:#fff;border-color:var(--tb-green);color:var(--tb-green-dark);box-shadow:0 6px 18px rgba(25,135,84,.15)}
        .tb-pill.active span,.tb-pill.done span{background:var(--tb-green);color:#fff}
        .tb-pill.done{color:var(--tb-green-dark)}
        .tb-step{display:none;border:0;padding:0;margin:0;min-width:0}
        .tb-step.active{display:block;animation:tbFade .35s ease}
        @keyframes tbFade{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
        .tb-step h3{font-size:1.2rem;font-weight:700;color:var(--tb-green-dark);margin-bottom:4px}
        .tb-step .sub{color:#6b7a72;font-size:.9rem;margin-bottom:18px}
        .tb-block{background:var(--tb-soft);border-radius:14px;padding:18px;margin-bottom:16px;border:1px solid var(--tb-line)}
        .tb-block h4{font-size:.8rem;text-transform:uppercase;letter-spacing:.08em;color:#42564b;margin-bottom:14px;font-weight:700}
        .tb-block h4 i{color:var(--tb-green);margin-right:6px}
        .tb-card .form-control,.tb-card .form-select{border-radius:10px;border-color:#d4e0d9}
        .tb-card .form-control:focus,.tb-card .form-select:focus{border-color:var(--tb-green);box-shadow:0 0 0 .2rem rgba(25,135,84,.15)}
        .tb-card .is-invalid{border-color:#dc3545}
        .tb-err{color:#c0362c;font-size:.8rem;margin-top:4px}
        .tb-vehicles{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:10px}
        .tb-vehicles input{position:absolute;opacity:0;pointer-events:none}
        .tb-vehicles label{display:block;border:2px solid #d4e0d9;border-radius:12px;padding:14px;cursor:pointer;background:#fff;transition:.2s;height:100%}
        .tb-vehicles label strong{display:block;color:var(--tb-ink);font-size:.92rem}
        .tb-vehicles label small{color:#6b7a72}
        .tb-vehicles label i{color:var(--tb-green);font-size:1.2rem;margin-bottom:6px;display:block}
        .tb-vehicles input:checked+label{border-color:var(--tb-green);background:#eaf6ef;box-shadow:0 6px 18px rgba(25,135,84,.15)}
        .tb-vehicles input:focus-visible+label{outline:3px solid rgba(255,193,7,.6)}
        .tb-actions{display:flex;justify-content:space-between;gap:12px;margin-top:22px;flex-wrap:wrap}
        .tb-btn{border:0;border-radius:12px;padding:13px 26px;font-weight:700;cursor:pointer;transition:.2s}
        .tb-btn-primary{background:var(--tb-green);color:#fff}.tb-btn-primary:hover{background:var(--tb-green-dark)}
        .tb-btn-ghost{background:#fff;color:var(--tb-green-dark);border:1px solid var(--tb-line)}
        .tb-btn[disabled]{opacity:.7;cursor:wait}
        .tb-side{position:sticky;top:96px}
        .tb-side .tb-card{padding:22px;margin-bottom:18px}
        .tb-side h5{font-weight:700;color:var(--tb-green-dark);margin-bottom:14px}
        .tb-next{list-style:none;padding:0;margin:0}
        .tb-next li{display:flex;gap:12px;margin-bottom:14px;font-size:.9rem;color:#42564b}
        .tb-next li b{width:28px;height:28px;border-radius:50%;background:var(--tb-gold);color:#3b2f00;display:grid;place-items:center;flex:none;font-size:.82rem}
        .tb-alert{background:#fdecea;border:1px solid #f5c6c2;color:#8a2a22;border-radius:12px;padding:14px 16px;margin-bottom:18px;font-size:.92rem}
        .tb-alert ul{margin:6px 0 0 18px;padding:0}
        .tb-hp{position:absolute;left:-9999px;top:-9999px;height:0;overflow:hidden}
        .tb-done{text-align:center;padding:26px 8px}
        .tb-done .ring{width:82px;height:82px;border-radius:50%;background:#eaf6ef;color:var(--tb-green);display:grid;place-items:center;margin:0 auto 16px;font-size:2rem}
        .tb-tid{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:clamp(1.5rem,4vw,2.1rem);font-weight:800;letter-spacing:.04em;color:var(--tb-green-dark);background:var(--tb-soft);border:2px dashed var(--tb-green);border-radius:14px;display:inline-block;padding:12px 26px;margin:14px 0}
        .tb-trackmini{display:flex;gap:8px}.tb-trackmini input{flex:1}
        @media(max-width:991px){.tb-side{position:static}.tb-steps{flex-direction:column}}
    </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>

<section class="tb-hero">
    <div class="container">
        <div class="tb-eyebrow">Transport &amp; Logistics</div>
        <h1>Book your truck in 3 easy steps</h1>
        <p>32 ft single-axle, multi-axle and open-body trucks across North-East India. Submit your load details and get a tracking ID instantly.</p>
    </div>
</section>

<div class="container tb-wrap">
    <div class="row g-4">
        <div class="col-lg-8">
            <div class="tb-card" id="bookingCard">

            <?php if ($success): ?>
                <div class="tb-done" id="done">
                    <div class="ring"><i class="fa fa-check"></i></div>
                    <h2 class="fw-bold" style="color:var(--tb-green-dark)">Booking request received</h2>
                    <p class="text-muted mb-0">Thank you, <?= tl_e($success['name']) ?>. Route: <strong><?= tl_e($success['route']) ?></strong></p>
                    <div class="tb-tid" id="tidText"><?= tl_e($success['tid']) ?></div>
                    <p class="text-muted mb-4">
                        Save this tracking ID.
                        <?= !empty($success['emailed']) ? 'We have also e-mailed it to you.' : '' ?>
                        Our dispatch team will call you shortly to confirm the quote.
                    </p>
                    <div class="d-flex justify-content-center flex-wrap gap-2">
                        <a class="tb-btn tb-btn-primary text-decoration-none" href="track?id=<?= rawurlencode($success['tid']) ?>"><i class="fa fa-map-marker-alt me-2"></i>Track this shipment</a>
                        <button type="button" class="tb-btn tb-btn-ghost" id="copyTid"><i class="fa fa-copy me-2"></i>Copy ID</button>
                        <a class="tb-btn tb-btn-ghost text-decoration-none" href="transport-booking">Book another</a>
                    </div>
                </div>

            <?php else: ?>
                <div class="tb-steps" id="tbSteps">
                    <div class="tb-pill active" data-pill="1"><span>1</span> Route &amp; Schedule</div>
                    <div class="tb-pill" data-pill="2"><span>2</span> Cargo &amp; Vehicle</div>
                    <div class="tb-pill" data-pill="3"><span>3</span> Your Details</div>
                </div>

                <?php if ($errors): ?>
                    <div class="tb-alert" role="alert">
                        <strong><i class="fa fa-exclamation-circle me-1"></i> Please fix the following:</strong>
                        <ul><?php foreach ($errors as $m): ?><li><?= tl_e($m) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>

                <form method="post" action="transport-booking" id="bookingForm" novalidate data-start="<?= (int) $startStep ?>">
                    <input type="hidden" name="csrf_token" value="<?= tl_e($_SESSION['tb_csrf']) ?>">
                    <div class="tb-hp" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

                    <!-- STEP 1 -->
                    <fieldset class="tb-step active" data-step="1">
                        <h3>Where should we pick up and deliver?</h3>
                        <div class="sub">Full addresses help us quote accurately.</div>

                        <div class="tb-block">
                            <h4><i class="fa fa-arrow-circle-up"></i> Pickup</h4>
                            <div class="row g-3">
                                <div class="col-12"><label class="form-label small fw-semibold" for="pickup_address">Pickup address *</label>
                                    <textarea class="form-control<?= $bad('pickup_address') ?>" id="pickup_address" name="pickup_address" rows="2" maxlength="500" required><?= tl_e($old['pickup_address']) ?></textarea><?= $err('pickup_address') ?></div>
                                <div class="col-md-4"><label class="form-label small fw-semibold" for="pickup_city">City *</label>
                                    <input class="form-control<?= $bad('pickup_city') ?>" id="pickup_city" name="pickup_city" maxlength="100" required value="<?= tl_e($old['pickup_city']) ?>"><?= $err('pickup_city') ?></div>
                                <div class="col-md-5"><label class="form-label small fw-semibold" for="pickup_state">State</label>
                                    <select class="form-select" id="pickup_state" name="pickup_state"><option value="">Select</option>
                                    <?php foreach ($states as $s): ?><option<?= $selected($s, $old['pickup_state']) ?>><?= tl_e($s) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-3"><label class="form-label small fw-semibold" for="pickup_pincode">PIN code</label>
                                    <input class="form-control<?= $bad('pickup_pincode') ?>" id="pickup_pincode" name="pickup_pincode" inputmode="numeric" maxlength="6" value="<?= tl_e($old['pickup_pincode']) ?>"><?= $err('pickup_pincode') ?></div>
                            </div>
                        </div>

                        <div class="tb-block">
                            <h4><i class="fa fa-arrow-circle-down"></i> Delivery</h4>
                            <div class="row g-3">
                                <div class="col-12"><label class="form-label small fw-semibold" for="drop_address">Delivery address *</label>
                                    <textarea class="form-control<?= $bad('drop_address') ?>" id="drop_address" name="drop_address" rows="2" maxlength="500" required><?= tl_e($old['drop_address']) ?></textarea><?= $err('drop_address') ?></div>
                                <div class="col-md-4"><label class="form-label small fw-semibold" for="drop_city">City *</label>
                                    <input class="form-control<?= $bad('drop_city') ?>" id="drop_city" name="drop_city" maxlength="100" required value="<?= tl_e($old['drop_city']) ?>"><?= $err('drop_city') ?></div>
                                <div class="col-md-5"><label class="form-label small fw-semibold" for="drop_state">State</label>
                                    <select class="form-select" id="drop_state" name="drop_state"><option value="">Select</option>
                                    <?php foreach ($states as $s): ?><option<?= $selected($s, $old['drop_state']) ?>><?= tl_e($s) ?></option><?php endforeach; ?></select></div>
                                <div class="col-md-3"><label class="form-label small fw-semibold" for="drop_pincode">PIN code</label>
                                    <input class="form-control<?= $bad('drop_pincode') ?>" id="drop_pincode" name="drop_pincode" inputmode="numeric" maxlength="6" value="<?= tl_e($old['drop_pincode']) ?>"><?= $err('drop_pincode') ?></div>
                                <div class="col-md-6"><label class="form-label small fw-semibold" for="drop_contact_person">Receiver name</label>
                                    <input class="form-control" id="drop_contact_person" name="drop_contact_person" maxlength="150" value="<?= tl_e($old['drop_contact_person']) ?>"></div>
                                <div class="col-md-6"><label class="form-label small fw-semibold" for="drop_contact_number">Receiver mobile</label>
                                    <input class="form-control<?= $bad('drop_contact_number') ?>" id="drop_contact_number" name="drop_contact_number" type="tel" maxlength="20" value="<?= tl_e($old['drop_contact_number']) ?>"><?= $err('drop_contact_number') ?></div>
                            </div>
                        </div>

                        <div class="tb-block">
                            <h4><i class="fa fa-calendar-alt"></i> Pickup schedule</h4>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label small fw-semibold" for="pickup_date">Pickup date *</label>
                                    <input class="form-control<?= $bad('pickup_date') ?>" id="pickup_date" name="pickup_date" type="date" min="<?= tl_e($minDate) ?>" required value="<?= tl_e($old['pickup_date']) ?>"><?= $err('pickup_date') ?></div>
                                <div class="col-md-6"><label class="form-label small fw-semibold" for="pickup_time">Preferred time</label>
                                    <input class="form-control<?= $bad('pickup_time') ?>" id="pickup_time" name="pickup_time" type="time" value="<?= tl_e($old['pickup_time']) ?>"><?= $err('pickup_time') ?></div>
                            </div>
                        </div>
                        <div class="tb-actions"><span></span><button type="button" class="tb-btn tb-btn-primary" data-next>Next: Cargo &amp; Vehicle <i class="fa fa-arrow-right ms-1"></i></button></div>
                    </fieldset>

                    <!-- STEP 2 -->
                    <fieldset class="tb-step" data-step="2">
                        <h3>What are we moving?</h3>
                        <div class="sub">Tell us about the load and pick the truck you prefer.</div>

                        <div class="tb-block">
                            <h4><i class="fa fa-box"></i> Cargo</h4>
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label small fw-semibold" for="cargo_type">Cargo type *</label>
                                    <select class="form-select<?= $bad('cargo_type') ?>" id="cargo_type" name="cargo_type" required><option value="">Select cargo type</option>
                                    <?php foreach ($cargoTypes as $c): ?><option<?= $selected($c, $old['cargo_type']) ?>><?= tl_e($c) ?></option><?php endforeach; ?></select><?= $err('cargo_type') ?></div>
                                <div class="col-md-3 col-6"><label class="form-label small fw-semibold" for="cargo_weight">Approx. weight</label>
                                    <input class="form-control<?= $bad('cargo_weight') ?>" id="cargo_weight" name="cargo_weight" type="number" step="0.01" min="0" value="<?= tl_e($old['cargo_weight']) ?>"><?= $err('cargo_weight') ?></div>
                                <div class="col-md-3 col-6"><label class="form-label small fw-semibold" for="cargo_unit">Unit</label>
                                    <select class="form-select" id="cargo_unit" name="cargo_unit"><option value="ton"<?= $selected('ton', $old['cargo_unit']) ?>>Ton</option><option value="kg"<?= $selected('kg', $old['cargo_unit']) ?>>Kg</option></select></div>
                                <div class="col-md-4"><label class="form-label small fw-semibold" for="number_of_packages">No. of packages</label>
                                    <input class="form-control<?= $bad('number_of_packages') ?>" id="number_of_packages" name="number_of_packages" type="number" min="0" value="<?= tl_e($old['number_of_packages']) ?>"><?= $err('number_of_packages') ?></div>
                                <div class="col-md-8"><label class="form-label small fw-semibold d-block">Handling</label>
                                    <div class="d-flex flex-wrap gap-3 pt-1">
                                        <label class="form-check"><input class="form-check-input" type="checkbox" name="fragile" value="1"<?= $old['fragile'] ? ' checked' : '' ?>> <span class="form-check-label">Fragile</span></label>
                                        <label class="form-check"><input class="form-check-input" type="checkbox" name="hazardous" value="1"<?= $old['hazardous'] ? ' checked' : '' ?>> <span class="form-check-label">Hazardous</span></label>
                                        <label class="form-check"><input class="form-check-input" type="checkbox" name="temperature_controlled" value="1"<?= $old['temperature_controlled'] ? ' checked' : '' ?>> <span class="form-check-label">Temperature controlled</span></label>
                                    </div></div>
                                <div class="col-12"><label class="form-label small fw-semibold" for="cargo_description">Cargo description</label>
                                    <textarea class="form-control<?= $bad('cargo_description') ?>" id="cargo_description" name="cargo_description" rows="2" maxlength="1000" placeholder="e.g. 400 bundles of bamboo poles, 12 ft"><?= tl_e($old['cargo_description']) ?></textarea><?= $err('cargo_description') ?></div>
                            </div>
                        </div>

                        <div class="tb-block">
                            <h4><i class="fa fa-truck"></i> Preferred vehicle *</h4>
                            <?php $vehMeta = [
                                '32 ft Single Axle Container' => ['15-18 Tons', 'General cargo'],
                                '32 ft Multi Axle Container'  => ['20-25 Tons', 'Heavy / bulk loads'],
                                '32 ft Open Body'             => ['18-22 Tons', 'Oversized goods'],
                                'Not sure - please advise'    => ['We will recommend', 'Best fit for your load'],
                            ]; ?>
                            <div class="tb-vehicles">
                                <?php foreach ($vehicleTypes as $i => $v): ?>
                                    <div style="position:relative">
                                        <input type="radio" name="vehicle_type" id="veh<?= $i ?>" value="<?= tl_e($v) ?>"<?= $old['vehicle_type'] === $v ? ' checked' : '' ?> required>
                                        <label for="veh<?= $i ?>"><i class="fa <?= $i === 3 ? 'fa-question-circle' : 'fa-truck-moving' ?>"></i><strong><?= tl_e($v) ?></strong><small><?= tl_e(($vehMeta[$v][0] ?? '') . ' · ' . ($vehMeta[$v][1] ?? '')) ?></small></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?= $err('vehicle_type') ?>
                        </div>
                        <div class="tb-actions">
                            <button type="button" class="tb-btn tb-btn-ghost" data-prev><i class="fa fa-arrow-left me-1"></i> Back</button>
                            <button type="button" class="tb-btn tb-btn-primary" data-next>Next: Your Details <i class="fa fa-arrow-right ms-1"></i></button>
                        </div>
                    </fieldset>

                    <!-- STEP 3 -->
                    <fieldset class="tb-step" data-step="3">
                        <h3>How can we reach you?</h3>
                        <div class="sub">We will call to confirm the quote. Your details are only used for this booking.</div>
                        <div class="tb-block">
                            <div class="row g-3">
                                <div class="col-md-6"><label class="form-label small fw-semibold" for="customer_name">Full name *</label>
                                    <input class="form-control<?= $bad('customer_name') ?>" id="customer_name" name="customer_name" maxlength="150" autocomplete="name" required value="<?= tl_e($old['customer_name']) ?>"><?= $err('customer_name') ?></div>
                                <div class="col-md-6"><label class="form-label small fw-semibold" for="company_name">Company (optional)</label>
                                    <input class="form-control" id="company_name" name="company_name" maxlength="150" autocomplete="organization" value="<?= tl_e($old['company_name']) ?>"></div>
                                <div class="col-md-6"><label class="form-label small fw-semibold" for="phone">Mobile number *</label>
                                    <input class="form-control<?= $bad('phone') ?>" id="phone" name="phone" type="tel" maxlength="20" autocomplete="tel" required value="<?= tl_e($old['phone']) ?>"><?= $err('phone') ?></div>
                                <div class="col-md-6"><label class="form-label small fw-semibold" for="email">E-mail (for confirmation)</label>
                                    <input class="form-control<?= $bad('email') ?>" id="email" name="email" type="email" maxlength="150" autocomplete="email" value="<?= tl_e($old['email']) ?>"><?= $err('email') ?></div>
                                <div class="col-12"><label class="form-label small fw-semibold" for="customer_notes">Anything else we should know?</label>
                                    <textarea class="form-control<?= $bad('customer_notes') ?>" id="customer_notes" name="customer_notes" rows="2" maxlength="1000"><?= tl_e($old['customer_notes']) ?></textarea><?= $err('customer_notes') ?></div>
                                <div class="col-12">
                                    <label class="form-check"><input class="form-check-input<?= $bad('consent') ?>" type="checkbox" name="consent" value="1" required<?= $old['consent'] ? ' checked' : '' ?>>
                                    <span class="form-check-label small">I agree that <?= tl_e($co['name']) ?> may contact me by phone, WhatsApp or e-mail about this booking.</span></label><?= $err('consent') ?></div>
                            </div>
                        </div>
                        <div class="tb-actions">
                            <button type="button" class="tb-btn tb-btn-ghost" data-prev><i class="fa fa-arrow-left me-1"></i> Back</button>
                            <button type="submit" class="tb-btn tb-btn-primary" id="submitBtn"><i class="fa fa-paper-plane me-2"></i>Submit booking request</button>
                        </div>
                    </fieldset>
                </form>
            <?php endif; ?>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="tb-side">
                <div class="tb-card">
                    <h5><i class="fa fa-search-location me-2 text-success"></i>Track a shipment</h5>
                    <form action="track" method="get" class="tb-trackmini">
                        <input class="form-control" name="id" placeholder="TRK-26-00001" maxlength="40" aria-label="Tracking ID" required>
                        <button class="tb-btn tb-btn-primary" type="submit" style="padding:10px 18px">Go</button>
                    </form>
                </div>
                <div class="tb-card">
                    <h5>What happens next?</h5>
                    <ul class="tb-next">
                        <li><b>1</b><span><strong>You submit</strong> the request and instantly get a tracking ID.</span></li>
                        <li><b>2</b><span><strong>Dispatch calls you</strong> to confirm the quote, truck and pickup time.</span></li>
                        <li><b>3</b><span><strong>Follow live updates</strong> on the tracking page until delivery.</span></li>
                        <li><b>4</b><span><strong>Get your GST invoice</strong> online once the trip is billed.</span></li>
                    </ul>
                </div>
                <div class="tb-card">
                    <h5>Prefer to talk?</h5>
                    <p class="mb-2 small text-muted">Our dispatch desk is happy to book by phone.</p>
                    <a class="fw-bold text-success text-decoration-none" href="tel:<?= tl_e(preg_replace('/[^0-9+]/', '', $co['phone'])) ?>"><i class="fa fa-phone-alt me-2"></i><?= tl_e($co['phone']) ?></a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var copyBtn = document.getElementById('copyTid');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var t = document.getElementById('tidText').textContent.trim();
            if (navigator.clipboard) { navigator.clipboard.writeText(t); }
            copyBtn.innerHTML = '<i class="fa fa-check me-2"></i>Copied';
        });
        var done = document.getElementById('done');
        if (done) { done.scrollIntoView({behavior: 'smooth', block: 'center'}); }
        return;
    }

    var form = document.getElementById('bookingForm');
    if (!form) { return; }
    var steps = form.querySelectorAll('.tb-step');
    var pills = document.querySelectorAll('[data-pill]');
    var current = 1;

    function show(n) {
        current = n;
        steps.forEach(function (s) { s.classList.toggle('active', Number(s.dataset.step) === n); });
        pills.forEach(function (p) {
            var k = Number(p.dataset.pill);
            p.classList.toggle('active', k === n);
            p.classList.toggle('done', k < n);
        });
        var top = document.getElementById('bookingCard');
        if (top) { window.scrollTo({top: top.getBoundingClientRect().top + window.pageYOffset - 100, behavior: 'smooth'}); }
    }

    function stepValid(n) {
        var box = form.querySelector('.tb-step[data-step="' + n + '"]');
        var fields = box.querySelectorAll('input, select, textarea');
        for (var i = 0; i < fields.length; i++) {
            if (!fields[i].checkValidity()) { fields[i].reportValidity(); return false; }
        }
        return true;
    }

    form.querySelectorAll('[data-next]').forEach(function (b) {
        b.addEventListener('click', function () { if (stepValid(current)) { show(current + 1); } });
    });
    form.querySelectorAll('[data-prev]').forEach(function (b) {
        b.addEventListener('click', function () { show(current - 1); });
    });
    pills.forEach(function (p) {
        p.style.cursor = 'pointer';
        p.addEventListener('click', function () {
            var k = Number(p.dataset.pill);
            if (k < current) { show(k); }
        });
    });

    form.addEventListener('submit', function (e) {
        for (var n = 1; n <= 3; n++) {
            if (!stepValid(n)) { e.preventDefault(); show(n); return; }
        }
        var btn = document.getElementById('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting...';
    });

    ['pickup_pincode', 'drop_pincode'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) { el.addEventListener('input', function () { el.value = el.value.replace(/\D/g, '').slice(0, 6); }); }
    });

    var start = Number(form.dataset.start || 1);
    if (start > 1) { show(start); }
});
</script>
</body>
</html>
