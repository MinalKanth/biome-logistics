<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['cab_csrf_token'])) {
    $_SESSION['cab_csrf_token'] = bin2hex(random_bytes(32));
}
$cabCsrfToken = $_SESSION['cab_csrf_token'];

$cabFormErrors = [];
$cabFormSuccess = false;
if (!empty($_SESSION['cab_flash_success'])) {
    $cabFormSuccess = true;
    unset($_SESSION['cab_flash_success']);
}

$cabFormValues = [
    'name'        => '',
    'mobile'      => '',
    'email'       => '',
    'vehicle'     => '',
    'service'     => '',
    'pickup'      => '',
    'destination' => '',
    'date'        => '',
    'passengers'  => '',
    'message'     => '',
];

$cabAllowedVehicles = ['Hatchback', 'Sedan', 'SUV', 'Innova Crysta', 'Tempo Traveller', 'Luxury Car'];
$cabAllowedServices = ['Self Drive', 'Car With Driver', 'Rental Car (Daily)', 'Rental Car (Weekly)', 'Rental Car (Monthly)'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cab_form_submit'])) {

    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['cab_csrf_token'], $postedToken)) {
        $cabFormErrors[] = 'Your session expired. Please refresh the page and try again.';
    } else {

        $name        = trim((string) ($_POST['name'] ?? ''));
        $mobile      = trim((string) ($_POST['mobile'] ?? ''));
        $email       = trim((string) ($_POST['email'] ?? ''));
        $vehicle     = trim((string) ($_POST['vehicle'] ?? ''));
        $service     = trim((string) ($_POST['service'] ?? ''));
        $pickup      = trim((string) ($_POST['pickup'] ?? ''));
        $destination = trim((string) ($_POST['destination'] ?? ''));
        $date        = trim((string) ($_POST['date'] ?? ''));
        $passengers  = trim((string) ($_POST['passengers'] ?? ''));
        $message     = trim((string) ($_POST['message'] ?? ''));

        $cabFormValues = compact('name', 'mobile', 'email', 'vehicle', 'service', 'pickup', 'destination', 'date', 'passengers', 'message');

        if ($name === '' || mb_strlen($name) > 150) {
            $cabFormErrors[] = 'Full name is required (max 150 characters).';
        }
        if ($mobile === '' || !preg_match('/^[0-9+\-\s()]{7,20}$/', $mobile)) {
            $cabFormErrors[] = 'Please enter a valid mobile number.';
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
            $cabFormErrors[] = 'Please enter a valid email address.';
        }
        if ($vehicle !== '' && !in_array($vehicle, $cabAllowedVehicles, true)) {
            $cabFormErrors[] = 'Please select a valid vehicle type.';
        }
        if ($service !== '' && !in_array($service, $cabAllowedServices, true)) {
            $cabFormErrors[] = 'Please select a valid service type.';
        }
        if (mb_strlen($pickup) > 200 || mb_strlen($destination) > 200) {
            $cabFormErrors[] = 'Pickup/destination location is too long.';
        }

        $dateValue = null;
        if ($date !== '') {
            $d = DateTime::createFromFormat('Y-m-d', $date);
            if (!$d || $d->format('Y-m-d') !== $date) {
                $cabFormErrors[] = 'Please enter a valid journey date.';
            } else {
                $dateValue = $date;
            }
        }

        $passengersValue = null;
        if ($passengers !== '') {
            if (!ctype_digit($passengers) || (int) $passengers < 1 || (int) $passengers > 100) {
                $cabFormErrors[] = 'Please enter a valid number of passengers.';
            } else {
                $passengersValue = (int) $passengers;
            }
        }

        if (mb_strlen($message) > 2000) {
            $cabFormErrors[] = 'Additional requirements are too long (max 2000 characters).';
        }

        $now = time();
        $bucket = $_SESSION['cab_rate_limit'] ?? ['count' => 0, 'start' => $now];
        if ($now - $bucket['start'] > 600) {
            $bucket = ['count' => 0, 'start' => $now];
        }
        $bucket['count']++;
        $_SESSION['cab_rate_limit'] = $bucket;
        if ($bucket['count'] > 3) {
            $cabFormErrors[] = 'Too many submissions. Please wait a few minutes and try again.';
        }

        if (!$cabFormErrors) {
            try {
                $pdo = get_db();
                $stmt = $pdo->prepare(
                    'INSERT INTO cab_booking_requests
                        (full_name, mobile_number, email, vehicle_type, service_type, pickup_location, destination, journey_date, passengers, message, ip_address)
                     VALUES
                        (:name, :mobile, :email, :vehicle, :service, :pickup, :destination, :date, :passengers, :message, :ip)'
                );
                $stmt->execute([
                    ':name'        => $name,
                    ':mobile'      => $mobile,
                    ':email'       => $email !== '' ? $email : null,
                    ':vehicle'     => $vehicle !== '' ? $vehicle : null,
                    ':service'     => $service !== '' ? $service : null,
                    ':pickup'      => $pickup !== '' ? $pickup : null,
                    ':destination' => $destination !== '' ? $destination : null,
                    ':date'        => $dateValue,
                    ':passengers'  => $passengersValue,
                    ':message'     => $message !== '' ? $message : null,
                    ':ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                $_SESSION['cab_flash_success'] = true;
                $_SESSION['cab_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . $_SERVER['PHP_SELF'] . '#booking');
                exit;

            } catch (PDOException $e) {
                error_log('Cab booking insert failed: ' . $e->getMessage());
                $cabFormErrors[] = 'Something went wrong on our end. Please try again later.';
            }
        }
    }
}

function cb_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Biome Enterprises | Cab Service</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicon -->
    <link rel="icon" href="/favicon.ico" type="image/x-icon">

    <!-- Google Web Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Roboto:wght@500;700&display=swap" rel="stylesheet">

    <!-- Icon Font Stylesheet -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Libraries Stylesheet -->
    <!-- <link href="lib/animate/animate.min.css" rel="stylesheet">
    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet"> -->

    <!-- Customized Bootstrap Stylesheet -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">

    <!-- Template Stylesheet -->
    <link href="css/style.css" rel="stylesheet">

    <style>
        :root {
            --be-primary: #ffc107;
            --be-primary-dark: #bf9107;
            --be-success: #198754;
            --be-dark: #271e01;
            --be-radius: 1rem;
            --be-shadow-soft: 0 10px 30px rgba(57, 51, 10, 0.08);
            --be-shadow-strong: 0 20px 50px #c29d082e;
            --be-transition: all .35s cubic-bezier(.25,.8,.25,1);
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f7f9fc;
            overflow-x: hidden;
        }
        h1, h2, h3, h4, h5, h6 { font-family: 'Roboto', sans-serif; }

        /* ---- Scroll progress bar ---- */
        #scrollProgress {
            position: fixed;
            top: 0; left: 0;
            height: 4px;
            width: 0%;
            background: linear-gradient(90deg, var(--be-primary), var(--be-success));
            z-index: 2000;
            transition: width .1s ease-out;
        }

        /* ---- Reveal-on-scroll (added on top of WOW/animate.css used by page JS) ---- */
        .reveal {
            opacity: 0;
            transform: translateY(40px);
            transition: opacity .7s ease, transform .7s ease;
        }
        .reveal.active { opacity: 1; transform: translateY(0); }

        /* ---- Navbar glass on scroll ---- */
        .navbar {
            transition: background .4s ease, box-shadow .4s ease, padding .4s ease;
        }
        .navbar.be-scrolled, .navbar.scrolled {
            background: rgba(255,255,255,.78) !important;
            backdrop-filter: blur(14px) saturate(160%);
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            padding-top: .4rem !important;
            padding-bottom: .4rem !important;
            box-shadow: 0 6px 20px rgba(0,0,0,.08) !important;
        }

        /* ---- Hero / Page header ---- */
        .cab-header {
            position: relative;
            background: linear-gradient(135deg, #1c1602 0%, #3a2e05 55%, #16210f 100%);
            background-size: cover;
            background-position: center;
            overflow: hidden;
        }
        .cab-header::before {
            content: "";
            position: absolute; inset: 0;
            background: radial-gradient(circle at 15% 20%, rgba(255,193,7,.25), transparent 55%),
                        radial-gradient(circle at 85% 80%, rgba(25,135,84,.3), transparent 55%);
            pointer-events: none;
        }
        .cab-header h6.text-warning {
            color: var(--be-primary) !important;
            letter-spacing: 3px;
            display: inline-block;
            padding: .35rem 1rem;
            border: 1px solid rgba(255,255,255,.35);
            border-radius: 50px;
            backdrop-filter: blur(6px);
            background: rgba(255,255,255,.08);
        }
        .cab-header .text-success { color: var(--be-primary) !important; }

        /* ---- Fluid type ---- */
        h1, .display-3 { font-size: clamp(1.8rem, 4.5vw + .5rem, 3.2rem) !important; }
        .display-5 { font-size: clamp(1.5rem, 3vw + .5rem, 2.4rem) !important; }
        p { font-size: clamp(.92rem, .4vw + .8rem, 1.05rem); }

        /* ---- Buttons ---- */
        .btn {
            position: relative;
            overflow: hidden;
            border-radius: 50px;
        }
        .btn-success {
            background: linear-gradient(135deg, var(--be-success), #115d3a);
            border: none;
            transition: var(--be-transition);
        }
        .btn-success:hover {
            transform: translateY(-4px) !important;
            box-shadow: 0 12px 24px rgba(25, 135, 84, 0.35);
        }
        .btn-outline-light:hover {
            background: var(--be-primary);
            border-color: var(--be-primary);
            color: var(--be-dark) !important;
        }
        .btn-success.glow {
            box-shadow: 0 0 18px rgba(25,135,84,.55);
        }
        /* Ripple from page JS */
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,.45);
            transform: scale(0);
            animation: be-ripple .65s ease-out;
            pointer-events: none;
        }
        @keyframes be-ripple {
            to { transform: scale(2.4); opacity: 0; }
        }

        /* ---- Cards / service items ---- */
        .service-item, .service-feature, .bg-white {
            transition: var(--be-transition);
        }
        .service-item:hover, .service-feature:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 45px rgba(191, 145, 7, 0.22) !important;
        }
        .service-item i.text-success, .service-feature i.text-success {
            transition: var(--be-transition);
        }
        .service-item:hover i.text-success, .service-feature:hover i.text-success {
            transform: scale(1.1) rotate(-4deg);
            filter: drop-shadow(0 4px 14px rgba(25,135,84,.45));
        }
        .rounded-4 { border-radius: var(--be-radius) !important; }

        .bg-success, .bg-success.bg-gradient {
            background: linear-gradient(135deg, var(--be-success), #0d4429) !important;
        }

        .bg-dark {
            background: linear-gradient(135deg, #1c1602, #16210f) !important;
        }

        /* Step numbers */
        .rounded-circle.bg-success {
            background: linear-gradient(135deg, var(--be-success), #115d3a) !important;
            transition: var(--be-transition);
        }
        .rounded-circle.bg-success:hover { transform: scale(1.08); }

        /* ---- Booking form ---- */
        #booking .form-control, #booking .form-select {
            border-radius: .65rem;
            border: 1px solid #e3e8f0;
            transition: var(--be-transition);
        }
        #booking .form-control:focus, #booking .form-select:focus {
            border-color: var(--be-primary);
            box-shadow: 0 0 0 .25rem rgba(255, 193, 7, 0.18);
            transform: translateY(-2px);
        }

        /* ---- WhatsApp floating pulse ---- */
        .whatsapp-float {
            position: fixed;
            bottom: 90px; right: 24px;
            z-index: 1500;
            width: 60px; height: 60px;
            border-radius: 50%;
            background: var(--be-success);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.6rem;
            box-shadow: 0 8px 24px rgba(25,135,84,.4);
            animation: be-pulse 2.4s infinite;
        }
        @keyframes be-pulse {
            0% { box-shadow: 0 0 0 0 rgba(25,135,84,.45); }
            70% { box-shadow: 0 0 0 16px rgba(25,135,84,0); }
            100% { box-shadow: 0 0 0 0 rgba(25,135,84,0); }
        }

        /* ---- Sticky mobile call bar ---- */
        #mobileCallBar {
            position: fixed;
            left: 0; right: 0; bottom: 0;
            z-index: 1600;
            display: none;
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(12px);
            box-shadow: 0 -8px 24px rgba(0,0,0,.12);
            padding: .6rem 1rem;
        }
        @media (max-width: 576px) {
            #mobileCallBar { display: flex; gap: .6rem; }
            body { padding-bottom: 64px; }
        }

        /* ---- Back to top ---- */
        .back-to-top {
            border-radius: 50% !important;
            width: 50px; height: 50px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: var(--be-shadow-strong);
            background: linear-gradient(135deg, var(--be-primary), var(--be-primary-dark)) !important;
            border: none;
        }
        .back-to-top {

    position: fixed !important;

    right: 24px !important;

    bottom: 20px !important;

    left: auto !important;

    z-index: 1501;

}
        .back-to-top:hover {
            background: var(--be-success) !important;
            box-shadow: 0 14px 32px rgba(25,135,84,.4);
        }

        ::selection { background: var(--be-primary); color: var(--be-dark); }

        /* ===================== FULL MOBILE RESPONSIVENESS ===================== */

        @media (max-width: 991px) {
            .position-absolute.bottom-0.start-0.translate-middle-y {
                position: static !important;
                transform: none !important;
                margin-top: -2.5rem;
                display: inline-block;
            }
        }

        @media (max-width: 768px) {
            .py-5 { padding-top: 2.5rem !important; padding-bottom: 2.5rem !important; }
            .row.g-4, .row.g-5 { row-gap: 1.5rem; }
            .service-item.p-5, .service-feature.p-4, .bg-white.p-5 { padding: 1.75rem !important; }
            .cab-header .d-flex.flex-wrap.gap-3 { flex-direction: column; }
            .cab-header .d-flex.flex-wrap.gap-3 .btn { width: 100%; text-align: center; }
            .fa-4x { font-size: 2.3rem !important; }
        }

        @media (max-width: 576px) {
            .container { padding-left: 1rem; padding-right: 1rem; }
            .fa-3x { font-size: 1.9rem !important; }
            .fa-2x { font-size: 1.4rem !important; }
            #booking .bg-white.rounded-4.p-5 { padding: 1.5rem !important; }
            .bg-dark.rounded-4.p-5 { padding: 1.75rem !important; }
            .bg-dark .btn { display: block; width: 100%; margin: 0 0 .75rem 0 !important; }
            .whatsapp-float { width: 50px; height: 50px; font-size: 1.3rem; bottom: 76px; right: 16px; }
            .rounded-circle.bg-success { width: 70px !important; height: 70px !important; }
        }

        @media (max-width: 400px) {
            .display-3, h1 { font-size: 1.6rem !important; }
        }
    </style>
</head>

<body>

    <div id="scrollProgress"></div>

    <!-- Navbar -->
    <?php include __DIR__ . '/navbar.php'; ?>
    <!-- Navbar End -->

    <!-- =========================================
                PAGE HEADER START
========================================= -->

    <div class="container-fluid cab-header position-relative overflow-hidden py-5">

        <div class="container py-5">

            <div class="row align-items-center">

                <div class="col-lg-8 reveal">

                    <h6 class="text-uppercase text-warning fw-bold mb-3">
                        Self Drive • Driver-Assisted Travel • Rental Cars
                    </h6>

                    <h1 class="display-3 text-white fw-bold mb-4">
                        Self Drive,

                        <span class="text-success">    Driver-Assisted Travel</span> & Rental Cars
                    </h1>

                    <p class="text-light fs-5 mb-4">

                        Choose from self-drive cars, driver services vehicles and flexible daily, weekly or monthly rentals across Assam and North-East India.

                    </p>

                    <div class="d-flex flex-wrap gap-3">

                        <a href="#booking" class="btn btn-success btn-lg rounded-pill px-5 py-3 shadow">
                            <i class="fa fa-car me-2"></i> Book Now
                        </a>

                        <a href="tel:+919678431656" class="btn btn-outline-light btn-lg rounded-pill px-5 py-3">
                            <i class="fa fa-phone me-2"></i> Call Now
                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- =========================================
                PAGE HEADER END
========================================= -->




    <!-- =========================================
                ABOUT START
========================================= -->

    <div class="container-fluid overflow-hidden py-5 px-lg-0">

        <div class="container py-5">

            <div class="row align-items-center g-5">

                <!-- Image -->

                <div class="col-lg-6 reveal" data-wow-delay=".2s">

                    <div class="position-relative">

                        <img src="img/cab-main.png" class="img-fluid rounded-4 shadow-lg w-100" alt="Cab Service">

                        <div class="position-absolute bottom-0 start-0 translate-middle-y bg-success rounded-4 shadow-lg p-4">

                            <h2 class="text-white fw-bold mb-0">
                                24×7
                            </h2>

                            <small class="text-light">
                            Cab Booking Support
                        </small>

                        </div>

                    </div>

                </div>

                <!-- Content -->

                <div class="col-lg-6 reveal mt-5 mt-lg-0" data-wow-delay=".3s">

                    <h6 class="text-success text-uppercase fw-bold mb-3">
                        About Our Car Rental Services
                    </h6>

                    <h1 class="display-5 fw-bold mb-4">

                        Self Drive, Cars With Driver & Rental Cars


                    </h1>

                    <p class="mb-4">

                        Biome Enterprises provides self-drive cars, driver services vehicles and flexible rental cars for individuals, tourists, families and businesses across North-East India.

                    </p>

                    <p class="mb-5">

                        Choose from daily, weekly and monthly rental plans with clean, insured and professionally maintained vehicles suitable for every travel requirement.

                    </p>

                    <div class="row g-4">

                        <!-- Self Drive -->

                        <div class="col-lg-6">

                            <div class="service-feature border rounded-4 shadow-lg p-4 h-100 position-relative overflow-hidden">

                                <span class="badge bg-success rounded-pill mb-3">
                                    Most Popular
                                </span>

                                <div class="d-flex align-items-center mb-4">

                                    <div class="icon-box me-3">
                                        <i class="fa fa-key fa-2x text-success"></i>
                                    </div>

                                    <div>

                                        <h4 class="fw-bold mb-1">
                                            Self Drive Cars
                                        </h4>

                                        <small class="text-muted">
                                            Complete Freedom
                                        </small>

                                    </div>

                                </div>

                                <p class="mb-4">

                                    Enjoy the freedom of driving yourself with clean, insured and well-maintained vehicles available for flexible rental durations.

                                </p>

                                <ul class="list-unstyled mb-4">

                                    <li><i class="fa fa-check-circle text-success me-2"></i>Fully Insured</li>

                                    <li><i class="fa fa-check-circle text-success me-2"></i>Flexible Rental Plans</li>

                                    <li><i class="fa fa-check-circle text-success me-2"></i>Easy Booking</li>

                                </ul>

                                <a href="#booking" class="fw-bold text-success text-decoration-none">

                                    Book Self Drive
                                    <i class="fa fa-arrow-right ms-2"></i>

                                </a>

                            </div>

                        </div>

                        <!-- Cars With Driver -->

                        <div class="col-lg-6">

                            <div class="service-feature border rounded-4 shadow-lg p-4 h-100 position-relative overflow-hidden">

                                <span class="badge bg-primary rounded-pill mb-3">
                                    Business & Family
                                </span>

                                <div class="d-flex align-items-center mb-4">

                                    <div class="icon-box me-3">
                                        <i class="fa fa-user-tie fa-2x text-success"></i>
                                    </div>

                                    <div>

                                        <h4 class="fw-bold mb-1">
                                            Cars With Driver
                                        </h4>

                                        <small class="text-muted">
                                            Professional Travel
                                        </small>

                                    </div>

                                </div>

                                <p class="mb-4">

                                    Professional drivers for airport transfers, corporate travel, sightseeing, weddings and outstation trips.

                                </p>

                                <ul class="list-unstyled mb-4">

                                    <li><i class="fa fa-check-circle text-success me-2"></i>Experienced Drivers</li>

                                    <li><i class="fa fa-check-circle text-success me-2"></i>Safe Journey</li>

                                    <li><i class="fa fa-check-circle text-success me-2"></i>24×7 Support</li>

                                </ul>

                                <a href="#booking" class="fw-bold text-success text-decoration-none">

                                    Hire A Driver
                                    <i class="fa fa-arrow-right ms-2"></i>

                                </a>

                            </div>

                        </div>

                        <!-- Flexible Rental -->

                        <div class="col-lg-6">

                            <div class="service-feature border rounded-4 shadow-lg p-4 h-100 position-relative overflow-hidden">

                                <span class="badge bg-warning text-dark rounded-pill mb-3">
                                    Long Term
                                </span>

                                <div class="d-flex align-items-center mb-4">

                                    <div class="icon-box me-3">
                                        <i class="fa fa-calendar-alt fa-2x text-success"></i>
                                    </div>

                                    <div>

                                        <h4 class="fw-bold mb-1">
                                            Flexible Rentals
                                        </h4>

                                        <small class="text-muted">
                                            Daily • Weekly • Monthly
                                        </small>

                                    </div>

                                </div>

                                <p class="mb-4">

                                    Affordable rental plans designed for tourists, businesses, families and long-term transportation requirements.

                                </p>

                                <ul class="list-unstyled mb-4">

                                    <li><i class="fa fa-check-circle text-success me-2"></i>Daily Rentals</li>

                                    <li><i class="fa fa-check-circle text-success me-2"></i>Weekly Plans</li>

                                    <li><i class="fa fa-check-circle text-success me-2"></i>Monthly Packages</li>

                                </ul>

                                <a href="#booking" class="fw-bold text-success text-decoration-none">

                                    View Plans
                                    <i class="fa fa-arrow-right ms-2"></i>

                                </a>

                            </div>

                        </div>

                        <!-- Local & Outstation -->

                        <div class="col-lg-6">

                            <div class="service-feature border rounded-4 shadow-lg p-4 h-100 position-relative overflow-hidden">

                                <span class="badge bg-danger rounded-pill mb-3">
                                    North-East India
                                </span>

                                <div class="d-flex align-items-center mb-4">

                                    <div class="icon-box me-3">
                                        <i class="fa fa-route fa-2x text-success"></i>
                                    </div>

                                    <div>

                                        <h4 class="fw-bold mb-1">
                                            Local & Outstation
                                        </h4>

                                        <small class="text-muted">
                                            Travel Anywhere
                                        </small>

                                    </div>

                                </div>

                                <p class="mb-4">

                                    Convenient transportation for city rides, intercity journeys, tourism, business travel and airport transfers.

                                </p>

                                <ul class="list-unstyled mb-4">

                                    <li><i class="fa fa-check-circle text-success me-2"></i>City Rides</li>

                                    <li><i class="fa fa-check-circle text-success me-2"></i>Outstation Trips</li>

                                    <li><i class="fa fa-check-circle text-success me-2"></i>Airport Pickup & Drop</li>

                                </ul>

                                <a href="#booking" class="fw-bold text-success text-decoration-none">

                                    Book Your Trip
                                    <i class="fa fa-arrow-right ms-2"></i>

                                </a>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- =========================================
                ABOUT END
========================================= -->





    <!-- =========================================
            WHY CHOOSE US START
========================================= -->

    <div class="container-xxl py-5">

        <div class="container">

            <div class="text-center mb-5 reveal">

                <h1 class="display-5 fw-bold">

                    One Destination For Every
                    <span class="text-success">
        Car Rental Need
    </span>

                </h1>

                <p class="mx-auto" style="max-width:850px;">

                    Whether you want to drive yourself, hire a driver services vehicle or rent a car for several days or months, Biome Enterprises delivers safe, reliable and affordable transportation solutions.

                </p>

            </div>

            <div class="row g-4">

                <!-- Card -->

                <div class="col-lg-4 col-md-6">

                    <div class="service-item rounded-4 shadow-lg h-100 p-5 text-center">

                        <i class="fa fa-car-side fa-4x text-success mb-4"></i>

                        <h4>

                            Modern Fleet

                        </h4>

                        <p>

                            Clean, sanitized and well-maintained vehicles for every travel requirement.

                        </p>

                    </div>

                </div>

                <!-- Card -->

                <div class="col-lg-4 col-md-6">

                    <div class="service-item rounded-4 shadow-lg h-100 p-5 text-center">

                        <i class="fa fa-route fa-4x text-success mb-4"></i>

                        <h4>

                            Long Distance Trips

                        </h4>

                        <p>

                            Comfortable intercity and outstation travel across North-East India.

                        </p>

                    </div>

                </div>

                <!-- Card -->

                <div class="col-lg-4 col-md-6">

                    <div class="service-item rounded-4 shadow-lg h-100 p-5 text-center">

                        <i class="fa fa-plane-arrival fa-4x text-success mb-4"></i>

                        <h4>

                            Airport Transfers

                        </h4>

                        <p>

                            Timely airport pickup and drop services with professional driver-assisted travel.

                        </p>

                    </div>

                </div>

                <!-- Card -->

                <div class="col-lg-4 col-md-6">

                    <div class="service-item rounded-4 shadow-lg h-100 p-5 text-center">

                        <i class="fa fa-briefcase fa-4x text-success mb-4"></i>

                        <h4>

                            Corporate Travel

                        </h4>

                        <p>

                            Dedicated transportation services for businesses and executives.

                        </p>

                    </div>

                </div>

                <!-- Card -->

                <div class="col-lg-4 col-md-6">

                    <div class="service-item rounded-4 shadow-lg h-100 p-5 text-center">

                        <i class="fa fa-user-friends fa-4x text-success mb-4"></i>

                        <h4>

                            Family Trips

                        </h4>

                        <p>

                            Spacious vehicles for vacations, weddings and family outings.

                        </p>

                    </div>

                </div>

                <!-- Card -->

                <div class="col-lg-4 col-md-6">

                    <div class="service-item rounded-4 shadow-lg h-100 p-5 text-center">

                        <i class="fa fa-headset fa-4x text-success mb-4"></i>

                        <h4>

                            24×7 Customer Support

                        </h4>

                        <p>

                            Quick booking assistance and dedicated customer support whenever you need us.

                        </p>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- =========================================
            WHY CHOOSE US END
========================================= -->

    <!-- =========================================
                OUR FLEET START
========================================= -->

    <div class="container-xxl py-5 bg-light">

        <div class="container">

            <div class="text-center mb-5 reveal">

                <h6 class="text-success text-uppercase">
                    Our Fleet
                </h6>

                <h1 class="display-5 fw-bold">
                    Choose The Perfect Vehicle
                </h1>

                <p class="mx-auto" style="max-width:850px;">

                    Our fleet is available for self-drive rentals, driver services services and flexible daily, weekly or monthly rental plans. Choose the perfect vehicle according to your travel and business requirements.

                </p>

            </div>

            <div class="row g-4">

                <!-- Hatchback -->
                <div class="col-lg-4 col-md-6">

                    <div class="service-item rounded-4 shadow-lg overflow-hidden h-100">

                        <img src="img/cars/hatchback.png" class="img-fluid w-100" alt="Hatchback">

                        <div class="p-4">

                            <h4>Hatchback</h4>

                            <p>
                                Perfect for city rides, airport transfers and daily commuting.
                            </p>

                            <ul class="list-unstyled">

                                <li><i class="fa fa-check text-success me-2"></i>4 Passengers</li>
                                <li><i class="fa fa-check text-success me-2"></i>AC Vehicle</li>
                                <li><i class="fa fa-check text-success me-2"></i>Fuel Efficient</li>

                            </ul>

                            <a href="#booking" class="btn btn-success rounded-pill px-4">
                            Request Vehicle
                        </a>

                        </div>

                    </div>

                </div>

                <!-- Sedan -->

                <div class="col-lg-4 col-md-6">

                    <div class="service-item rounded-4 shadow-lg overflow-hidden h-100">

                        <img src="img/cars/sedan.png" class="img-fluid w-100" alt="Sedan">

                        <div class="p-4">

                            <h4>Sedan</h4>

                            <p>
                                Comfortable executive travel for business meetings and family trips.
                            </p>

                            <ul class="list-unstyled">

                                <li><i class="fa fa-check text-success me-2"></i>4 Passengers</li>
                                <li><i class="fa fa-check text-success me-2"></i>Premium Comfort</li>
                                <li><i class="fa fa-check text-success me-2"></i>Long Distance</li>

                            </ul>

                            <a href="#booking" class="btn btn-success rounded-pill px-4">
                            Book Now
                        </a>

                        </div>

                    </div>

                </div>

                <!-- SUV -->

                <div class="col-lg-4 col-md-6">

                    <div class="service-item rounded-4 shadow-lg overflow-hidden h-100">

                        <img src="img/cars/suv.png" class="img-fluid w-100" alt="SUV">

                        <div class="p-4">

                            <h4>SUV</h4>

                            <p>
                                Ideal for group travel, hilly roads and long-distance journeys.
                            </p>

                            <ul class="list-unstyled">

                                <li><i class="fa fa-check text-success me-2"></i>6-7 Passengers</li>
                                <li><i class="fa fa-check text-success me-2"></i>Extra Luggage</li>
                                <li><i class="fa fa-check text-success me-2"></i>Comfort Ride</li>

                            </ul>

                            <a href="#booking" class="btn btn-success rounded-pill px-4">
                            Book Now
                        </a>

                        </div>

                    </div>

                </div>

                <!-- Innova -->

                <div class="col-lg-4 col-md-6">

                    <div class="service-item rounded-4 shadow-lg overflow-hidden h-100">

                        <img src="img/cars/innova-crysta.png" class="img-fluid w-100" alt="Innova">

                        <div class="p-4">

                            <h4>Innova Crysta</h4>

                            <p>
                                Premium MPV for business tours, airport transfers and family vacations.
                            </p>

                            <ul class="list-unstyled">

                                <li><i class="fa fa-check text-success me-2"></i>7 Passengers</li>
                                <li><i class="fa fa-check text-success me-2"></i>Luxury Interior</li>
                                <li><i class="fa fa-check text-success me-2"></i>Executive Travel</li>

                            </ul>

                            <a href="#booking" class="btn btn-success rounded-pill px-4">
                            Book Now
                        </a>

                        </div>

                    </div>

                </div>

                <!-- Traveller -->

                <div class="col-lg-4 col-md-6">

                    <div class="service-item rounded-4 shadow-lg overflow-hidden h-100">

                        <img src="img/cars/traveller.png" class="img-fluid w-100" alt="Tempo Traveller">

                        <div class="p-4">

                            <h4>Tempo Traveller</h4>

                            <p>
                                Best choice for tourism groups, office outings and family tours.
                            </p>

                            <ul class="list-unstyled">

                                <li><i class="fa fa-check text-success me-2"></i>12-17 Seats</li>
                                <li><i class="fa fa-check text-success me-2"></i>Tour Package</li>
                                <li><i class="fa fa-check text-success me-2"></i>Outstation</li>

                            </ul>

                            <a href="#booking" class="btn btn-success rounded-pill px-4">
                            Book Now
                        </a>

                        </div>

                    </div>

                </div>

                <!-- Luxury -->

                <div class="col-lg-4 col-md-6">

                    <div class="service-item rounded-4 shadow-lg overflow-hidden h-100">

                        <img src="img/cars/luxury.png" class="img-fluid w-100" alt="Luxury Car">

                        <div class="p-4">

                            <h4>Luxury Cars</h4>

                            <p>
                                Available for both self-drive and driver services executive travel.
                            </p>

                            <ul class="list-unstyled">

                                <li><i class="fa fa-check text-success me-2"></i>Luxury Interior</li>
                                <li><i class="fa fa-check text-success me-2"></i>Professional Driver-Assisted Travel</li>
                                <li><i class="fa fa-check text-success me-2"></i>Business Events</li>

                            </ul>

                            <a href="#booking" class="btn btn-success rounded-pill px-4">
                            Book Now
                        </a>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- =========================================
                OUR FLEET END
========================================= -->




    <!-- =========================================
                SERVICES START
========================================= -->

    <div class="container-fluid py-5">

        <div class="container">

            <div class="text-center mb-5 reveal">

                <h6 class="text-success text-uppercase">
                    Our Rental Solutions
                </h6>

                <h1 class="display-5 fw-bold">
                    Self Drive • Cars With Driver • Rental Cars
                </h1>

            </div>

            <div class="row g-4">

                <div class="col-lg-3 col-md-6">

                    <div class="service-item p-4 rounded-4 shadow text-center h-100">

                        <i class="fa fa-plane fa-4x text-success mb-4"></i>

                        <h5>Airport Transfers</h5>

                        <p>
                            Timely pickup and drop services to all nearby airports.
                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="service-item p-4 rounded-4 shadow text-center h-100">

                        <i class="fa fa-city fa-4x text-success mb-4"></i>

                        <h5>Local City Rides</h5>

                        <p>
                            Comfortable daily city travel with professional drivers.
                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="service-item p-4 rounded-4 shadow text-center h-100">

                        <i class="fa fa-route fa-4x text-success mb-4"></i>

                        <h5>Outstation Trips</h5>

                        <p>
                            Safe long-distance travel across North-East India.
                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="service-item p-4 rounded-4 shadow text-center h-100">

                        <i class="fa fa-briefcase fa-4x text-success mb-4"></i>

                        <h5>Corporate Travel</h5>

                        <p>
                            Reliable transport solutions for businesses and executives.
                        </p>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- =========================================
                SERVICES END
========================================= -->





    <!-- =========================================
            BOOKING PROCESS START
========================================= -->

    <div class="container-xxl py-5 bg-light">

        <div class="container">

            <div class="text-center reveal">

                <h6 class="text-success text-uppercase">
                    Booking Process
                </h6>

                <h1 class="display-5 fw-bold">
                    Book Your Ride In 4 Easy Steps
                </h1>

            </div>

            <div class="row g-4 mt-4">

                <div class="col-lg-3 col-md-6">

                    <div class="text-center p-4">

                        <div class="rounded-circle bg-success text-white d-inline-flex align-items-center justify-content-center mb-4" style="width:90px;height:90px;">

                            <h2>1</h2>

                        </div>

                        <h5>Choose Vehicle</h5>

                        <p>
                            Select the vehicle that best suits your travel needs.
                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="text-center p-4">

                        <div class="rounded-circle bg-success text-white d-inline-flex align-items-center justify-content-center mb-4" style="width:90px;height:90px;">

                            <h2>2</h2>

                        </div>

                        <h5>Choose Service</h5>

                        <p>
                            Select Self Drive, Driver or Rental plan.
                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="text-center p-4">

                        <div class="rounded-circle bg-success text-white d-inline-flex align-items-center justify-content-center mb-4" style="width:90px;height:90px;">

                            <h2>3</h2>

                        </div>

                        <h5>Confirm Booking</h5>

                        <p>
                            Receive your quotation and confirm your booking instantly.
                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="text-center p-4">

                        <div class="rounded-circle bg-success text-white d-inline-flex align-items-center justify-content-center mb-4" style="width:90px;height:90px;">

                            <h2>4</h2>

                        </div>

                        <h5>Enjoy Your Ride</h5>

                        <p>
                            Sit back and travel comfortably with our professional drivers.
                        </p>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- =========================================
            BOOKING PROCESS END
========================================= -->

    <!-- =========================================
            BOOKING FORM START
========================================= -->

    <div id="booking" class="container-fluid py-5 bg-success bg-gradient position-relative overflow-hidden">

        <div class="container py-5">

            <div class="row g-5 align-items-center">

                <!-- Left Side -->

                <div class="col-lg-5 reveal">

                    <h6 class="text-uppercase text-warning fw-bold">
                        Book Your Ride
                    </h6>

                    <h1 class="display-5 text-white mb-4">
                        Request Your Vehicle
                    </h1>

                    <p class="text-light mb-4">
                        Book a self-drive car, driver services vehicle or rental car for daily, weekly or monthly use.
                    </p>

                    <div class="mb-4 d-flex">

                        <i class="fa fa-check-circle fa-2x text-warning me-3"></i>

                        <div>

                            <h5 class="text-white mb-1">
                                Self Drive, Driver & Rental
                            </h5>

                            <p class="text-light mb-0">

                                Available with self-drive, driver-assisted travel service or long-term rental options.

                            </p>

                        </div>

                    </div>

                    <div class="mb-4 d-flex">

                        <i class="fa fa-car fa-2x text-warning me-3"></i>

                        <div>

                            <h5 class="text-white mb-1">
                                Multiple Vehicle Options
                            </h5>

                            <p class="text-light mb-0">
                                Hatchbacks, Sedans, SUVs, Innova, Tempo Traveller & Luxury Cars.
                            </p>

                        </div>

                    </div>

                    <div class="mb-4 d-flex">

                        <i class="fa fa-headset fa-2x text-warning me-3"></i>

                        <div>

                            <h5 class="text-white mb-1">
                                24×7 Customer Support
                            </h5>

                            <p class="text-light mb-0">
                                Dedicated assistance before and during your trip.
                            </p>

                        </div>

                    </div>

                </div>

                <!-- Booking Form -->

                <div class="col-lg-7 reveal">

                    <div class="bg-white rounded-4 shadow-lg p-5">

                        <h3 class="mb-4">
                            Cab Booking Form
                        </h3>

                        <?php if ($cabFormSuccess): ?>
                                <div class="alert alert-success">Thank you! Your booking request has been received. Our team will contact you shortly.</div>
                            <?php endif; ?>

                            <?php if ($cabFormErrors): ?>
                                <div class="alert alert-danger">
                                    <ul class="mb-0">
                                        <?php foreach ($cabFormErrors as $err): ?>
                                            <li><?= cb_e($err) ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>

                            <form method="post" action="">
                                <input type="hidden" name="csrf_token" value="<?= cb_e($cabCsrfToken) ?>">
                                <input type="hidden" name="cab_form_submit" value="1">

                                <div class="row g-4">
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Full Name</label>
                                        <input type="text" name="name" class="form-control py-3" placeholder="Enter your name" maxlength="150" required value="<?= cb_e($cabFormValues['name']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Mobile Number</label>
                                        <input type="tel" name="mobile" class="form-control py-3" placeholder="+91 XXXXX XXXXX" maxlength="20" required value="<?= cb_e($cabFormValues['mobile']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Email Address</label>
                                        <input type="email" name="email" class="form-control py-3" placeholder="Email" maxlength="150" value="<?= cb_e($cabFormValues['email']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Vehicle Type</label>
                                        <select class="form-select py-3" name="vehicle">
                                            <option value="" <?= $cabFormValues['vehicle'] === '' ? 'selected' : '' ?>>Select Vehicle</option>
                                            <?php foreach ($cabAllowedVehicles as $v): ?>
                                                <option value="<?= cb_e($v) ?>" <?= $cabFormValues['vehicle'] === $v ? 'selected' : '' ?>><?= cb_e($v) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Service Type</label>
                                        <select class="form-select py-3" name="service">
                                            <option value="" <?= $cabFormValues['service'] === '' ? 'selected' : '' ?>>Select Service</option>
                                            <?php foreach ($cabAllowedServices as $s): ?>
                                                <option value="<?= cb_e($s) ?>" <?= $cabFormValues['service'] === $s ? 'selected' : '' ?>><?= cb_e($s) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Pickup Location</label>
                                        <input type="text" name="pickup" class="form-control py-3" placeholder="Pickup Address" maxlength="200" value="<?= cb_e($cabFormValues['pickup']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Destination</label>
                                        <input type="text" name="destination" class="form-control py-3" placeholder="Drop Location" maxlength="200" value="<?= cb_e($cabFormValues['destination']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Journey Date</label>
                                        <input type="date" name="date" class="form-control py-3" value="<?= cb_e($cabFormValues['date']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label fw-bold">Number of Passengers</label>
                                        <input type="number" name="passengers" class="form-control py-3" placeholder="Passengers" min="1" max="100" value="<?= cb_e($cabFormValues['passengers']) ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label fw-bold">Additional Requirements</label>
                                        <textarea name="message" class="form-control" rows="5" maxlength="2000" placeholder="Write your travel requirements..."><?= cb_e($cabFormValues['message']) ?></textarea>
                                    </div>
                                    <div class="col-12">
                                        <button class="btn btn-success btn-lg rounded-pill w-100 py-3" type="submit">
                                            <i class="fa fa-paper-plane me-2"></i>
                                            Request Vehicle
                                        </button>
                                    </div>
                                </div>
                            </form>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- =========================================
            BOOKING FORM END
========================================= -->





    <!-- =========================================
            FINAL CTA START
========================================= -->

    <div class="container-fluid py-5">

        <div class="container">

            <div class="bg-dark rounded-4 shadow-lg p-5 text-center reveal">

                <h2 class="text-white mb-3">

                    Need A Self Drive, Driver-Assisted Travel or Rental Car?

                </h2>

                <p class="text-light mb-4">

                    From self-drive cars and driver services vehicles to flexible daily, weekly and monthly rentals, Biome Enterprises provides dependable mobility solutions for individuals, families, tourists and corporate clients across North-East India.

                </p>

                <a href="#booking" class="btn btn-success btn-lg rounded-pill px-5 me-3">

Request Your Vehicle

</a>

                <a href="tel:+919678431656" class="btn btn-outline-light btn-lg rounded-pill px-5">

                    <i class="fa fa-phone me-2"></i> Call Now

                </a>

            </div>

        </div>

    </div>

    <!-- =========================================
            FINAL CTA END
========================================= -->


    <!-- Footer -->
    <?php include __DIR__ . '/footer.php'; ?>
    <!-- Footer End -->


    <!-- Floating WhatsApp Button -->
    <a href="https://wa.me/919678431656" target="_blank" class="whatsapp-float" aria-label="Chat on WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Sticky Mobile Call Bar -->
    <div id="mobileCallBar">
        <a href="tel:+919678431656" class="btn btn-success flex-fill"><i class="fa fa-phone me-2"></i>Call Now</a>
        <a href="https://wa.me/919678431656" target="_blank" class="btn btn-light flex-fill"><i class="fab fa-whatsapp me-2"></i>WhatsApp</a>
    </div>

    <!-- Back to Top -->
    <a href="#" class="btn btn-lg btn-lg-square rounded-0 back-to-top"><i class="bi bi-arrow-up"></i></a>


    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="lib/wow/wow.min.js"></script>
    <script src="lib/easing/easing.min.js"></script>
    <script src="lib/waypoints/waypoints.min.js"></script>
    <!-- <script src="lib/owlcarousel/owl.carousel.min.js"></script> -->

    <!-- Template Javascript -->
    <script src="js/main.js"></script>

    <!-- ===================== Premium Interactivity (scroll progress, reveal, navbar) ===================== -->
    <script>
    (function () {
        // Scroll progress bar
        const progressBar = document.getElementById('scrollProgress');
        function updateProgress() {
            const h = document.documentElement;
            const scrolled = (h.scrollTop) / (h.scrollHeight - h.clientHeight) * 100;
            if (progressBar) progressBar.style.width = scrolled + '%';
        }
        window.addEventListener('scroll', updateProgress, { passive: true });
        updateProgress();

        // Navbar glass effect on scroll
        const nav = document.querySelector('nav.navbar, .navbar');
        function updateNav() {
            if (!nav) return;
            if (window.scrollY > 40) {
                nav.classList.add('be-scrolled');
            } else {
                nav.classList.remove('be-scrolled');
            }
        }
        window.addEventListener('scroll', updateNav, { passive: true });
        updateNav();
    })();
    </script>

    <script>
        /*==================================================
                                                                                    CAB PAGE INTERACTIONS
                                                                                ==================================================*/

        document.addEventListener("DOMContentLoaded", function() {

            const isTouch = window.matchMedia('(hover: none), (pointer: coarse)').matches;

            /* Reveal Animation */

            const reveal = document.querySelectorAll(".reveal,.service-item,.shadow-sm,.shadow-lg,.bg-white");

            const observer = new IntersectionObserver(entries => {

                entries.forEach(entry => {

                    if (entry.isIntersecting) {

                        entry.target.classList.add("active");
                        entry.target.classList.add("animate__animated");
                        entry.target.classList.add("animate__fadeInUp");

                    }

                });

            }, {
                threshold: .15
            });

            reveal.forEach(el => {

                el.classList.add("reveal");
                observer.observe(el);

            });

            /* Magnetic Buttons (desktop only — disabled on touch to avoid mobile jank) */

            if (!isTouch) {
                document.querySelectorAll(".btn").forEach(btn => {

                    btn.addEventListener("mousemove", e => {

                        const rect = btn.getBoundingClientRect();

                        const x = e.clientX - rect.left - rect.width / 2;
                        const y = e.clientY - rect.top - rect.height / 2;

                        btn.style.transform =
                            `translate(${x*.15}px,${y*.15}px)`;

                    });

                    btn.addEventListener("mouseleave", () => {

                        btn.style.transform = "";

                    });

                });
            }

            /* Ripple */

            document.querySelectorAll(".btn").forEach(btn => {

                btn.addEventListener("click", function(e) {

                    const circle = document.createElement("span");

                    circle.classList.add("ripple");

                    const d = Math.max(btn.clientWidth, btn.clientHeight);

                    circle.style.width = d + "px";
                    circle.style.height = d + "px";

                    const rect = btn.getBoundingClientRect();
                    const offsetX = (e.clientX || rect.left + rect.width / 2) - rect.left;
                    const offsetY = (e.clientY || rect.top + rect.height / 2) - rect.top;

                    circle.style.left = offsetX - d / 2 + "px";
                    circle.style.top = offsetY - d / 2 + "px";

                    btn.appendChild(circle);

                    setTimeout(() => circle.remove(), 700);

                });

            });

            /* Header Parallax (disabled on touch — fixed backgrounds are not used here, kept simple) */

            const header = document.querySelector(".cab-header");

            if (!isTouch) {
                window.addEventListener("scroll", () => {

                    if (header) {

                        header.style.backgroundPositionY =
                            window.scrollY * .25 + "px";

                    }

                }, { passive: true });
            }

            /* Auto Glow */

            setInterval(() => {

                document.querySelectorAll(".btn-success").forEach(btn => {

                    btn.classList.toggle("glow");

                });

            }, 2500);

        });

        window.addEventListener("scroll", function() {

            const nav = document.querySelector(".navbar");

            if (nav) {

                if (window.scrollY > 50) {

                    nav.classList.add("scrolled");

                } else {

                    nav.classList.remove("scrolled");

                }

            }

        });
    </script>
</body>

</html>