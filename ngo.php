<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['contact_csrf_token'])) {
    $_SESSION['contact_csrf_token'] = bin2hex(random_bytes(32));
}
$contactCsrfToken = $_SESSION['contact_csrf_token'];

$contactFormErrors = [];
$contactFormSuccess = false;
if (!empty($_SESSION['contact_flash_success'])) {
    $contactFormSuccess = true;
    unset($_SESSION['contact_flash_success']);
}

$contactFormValues = [
    'name'    => '',
    'email'   => '',
    'subject' => '',
    'message' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_form_submit'])) {

    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['contact_csrf_token'], $postedToken)) {
        $contactFormErrors[] = 'Your session expired. Please refresh the page and try again.';
    } else {

        $name    = trim((string) ($_POST['name'] ?? ''));
        $email   = trim((string) ($_POST['email'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        $contactFormValues = compact('name', 'email', 'subject', 'message');

        if ($name === '' || mb_strlen($name) > 150) {
            $contactFormErrors[] = 'Name is required (max 150 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $contactFormErrors[] = 'Please enter a valid email address.';
        }

        $allowedServices = [
            'Transportation & Logistics', 'Bamboo Trading', 'GST Registration',
            'Company Registration', 'MSME Registration', 'Accounting', 'Cab Rental', 'Other',
        ];
        if ($subject !== '' && !in_array($subject, $allowedServices, true)) {
            $contactFormErrors[] = 'Please select a valid service from the list.';
        }
        if (mb_strlen($message) > 2000) {
            $contactFormErrors[] = 'Message is too long (max 2000 characters).';
        }

        $now = time();
        $bucket = $_SESSION['contact_rate_limit'] ?? ['count' => 0, 'start' => $now];
        if ($now - $bucket['start'] > 600) {
            $bucket = ['count' => 0, 'start' => $now];
        }
        $bucket['count']++;
        $_SESSION['contact_rate_limit'] = $bucket;
        if ($bucket['count'] > 3) {
            $contactFormErrors[] = 'Too many submissions. Please wait a few minutes and try again.';
        }

        if (!$contactFormErrors) {
            try {
                $pdo = get_db();
                $stmt = $pdo->prepare(
                    'INSERT INTO contact_messages (full_name, email, service_required, message, ip_address)
                     VALUES (:name, :email, :subject, :message, :ip)'
                );
                $stmt->execute([
                    ':name'    => $name,
                    ':email'   => $email,
                    ':subject' => $subject !== '' ? $subject : null,
                    ':message' => $message !== '' ? $message : null,
                    ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                $_SESSION['contact_flash_success'] = true;
                $_SESSION['contact_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;

            } catch (PDOException $e) {
                error_log('Contact form insert failed: ' . $e->getMessage());
                $contactFormErrors[] = 'Something went wrong on our end. Please try again later.';
            }
        }
    }
}

function cf_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Biome Enterprises | NGO & Sustainability</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta name="keywords" content="NGO, Sustainability, Environment, Bamboo Plantation, Green India, CSR, Community Development, Biome Enterprises, Assam">

    <meta name="description" content="Biome Enterprises is committed to sustainability, environmental conservation, bamboo plantation, community development, skill development, waste management, and creating a greener future through impactful NGO initiatives.">


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
    <link href="lib/animate/animate.min.css" rel="stylesheet">
    <!-- <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet"> -->
    <link href="css/navbar-active-state.css" rel="stylesheet">
    <!-- Customized Bootstrap Stylesheet -->
    <link href="css/bootstrap.min.css" rel="stylesheet">

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

        /* ---- Reveal-on-scroll ---- */
        .reveal {
            opacity: 0;
            transform: translateY(40px);
            transition: opacity .8s ease, transform .8s ease;
        }
        .reveal.is-visible {
            opacity: 1;
            transform: translateY(0);
        }
        .reveal-stagger > * {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity .7s ease, transform .7s ease;
        }
        .reveal-stagger.is-visible > * { opacity: 1; transform: translateY(0); }
        .reveal-stagger.is-visible > *:nth-child(1) { transition-delay: .05s; }
        .reveal-stagger.is-visible > *:nth-child(2) { transition-delay: .15s; }
        .reveal-stagger.is-visible > *:nth-child(3) { transition-delay: .25s; }

        /* ---- Page Header (replaces template's page-header bg) ---- */
        .page-header {
            position: relative;
            background: linear-gradient(135deg, #1c1602 0%, #3a2e05 55%, #16210f 100%);
            overflow: hidden;
        }
        .page-header::before {
            content: "";
            position: absolute; inset: 0;
            background: radial-gradient(circle at 15% 20%, rgba(255,193,7,.25), transparent 55%),
                        radial-gradient(circle at 85% 80%, rgba(25,135,84,.3), transparent 55%);
            pointer-events: none;
        }
        .page-header h6.text-warning {
            letter-spacing: 3px;
            display: inline-block;
            padding: .35rem 1rem;
            border: 1px solid rgba(255,255,255,.35);
            border-radius: 50px;
            backdrop-filter: blur(6px);
            background: rgba(255,255,255,.08);
        }
        .page-header .text-success { color: #ffc107 !important; }
        .breadcrumb { background: transparent; margin: 0; }
        .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,.6); }

        /* ---- Glass cards ---- */
        .glass-card {
            background: rgba(255, 255, 255, .15);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, .25);
            border-radius: var(--be-radius);
            box-shadow: var(--be-shadow-strong);
            transition: var(--be-transition);
        }
        .glass-card.bg-white {
            background: #fff !important;
            color: var(--be-dark);
            box-shadow: var(--be-shadow-soft);
        }
        .glass-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 45px rgba(191, 145, 7, 0.22);
        }

        .glass-card-dark {
            background: linear-gradient(135deg, rgba(38, 30, 4, .94), rgba(13, 46, 30, .94));
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, .15);
            border-radius: var(--be-radius);
            box-shadow: var(--be-shadow-strong);
            transition: var(--be-transition);
        }
        .glass-card-dark:hover { transform: translateY(-6px); }
        .glass-card-dark hr { border-color: rgba(255,255,255,.2); }

        /* ---- Section title ---- */
        .section-title {
            font-weight: 700;
            position: relative;
            padding-bottom: 15px;
        }

        .section-title::after {
            content: "";
            position: absolute;
            width: 70px;
            height: 4px;
            background: linear-gradient(90deg, var(--be-primary), var(--be-success));
            left: 50%;
            bottom: 0;
            transform: translateX(-50%);
            border-radius: 50px;
        }

        /* ---- Buttons ---- */
        .btn {
            position: relative;
            overflow: hidden;
        }
        .btn::after {
            content: "";
            position: absolute; top: 50%; left: 50%;
            width: 0; height: 0;
            background: rgba(255,255,255,.25);
            border-radius: 50%;
            transform: translate(-50%,-50%);
            transition: width .5s ease, height .5s ease;
        }
        .btn:active::after { width: 300px; height: 300px; }

        .btn-success {
            border-radius: 50px;
            padding: 14px 34px;
            transition: var(--be-transition);
            background: linear-gradient(135deg, var(--be-success), #115d3a);
            border: none;
        }

        .btn-success:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(25, 135, 84, 0.35);
        }

        .btn-outline-light:hover {
            background: var(--be-primary);
            border-color: var(--be-primary);
            color: var(--be-dark) !important;
            transform: translateY(-4px);
        }

        .btn-light:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(0,0,0,.2);
        }

        /* ---- Floating animation ---- */
        .floating {
            animation: floating 3.5s ease-in-out infinite;
        }

        @keyframes floating {
            0% { transform: translateY(0); }
            50% { transform: translateY(-12px); }
            100% { transform: translateY(0); }
        }

        /* ---- Generic icon + card accents (gold/green, no blue) ---- */
        .text-success, i.text-success { color: var(--be-success) !important; }
        i.fa-3x.text-success, i.fa-5x.text-success {
            transition: var(--be-transition);
        }
        .bg-white.rounded-4:hover i.text-success {
            transform: scale(1.1) rotate(-4deg);
            filter: drop-shadow(0 4px 14px rgba(25,135,84,.45));
        }

        .bg-white.rounded-4 {
            transition: var(--be-transition);
        }
        .bg-white.rounded-4:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 45px rgba(191, 145, 7, 0.22) !important;
        }

        /* ---- Gallery ---- */
        .gallery-card {
            border-radius: var(--be-radius);
            overflow: hidden;
            box-shadow: var(--be-shadow-soft);
            transition: var(--be-transition);
            height: 100%;
        }
        .gallery-card img {
            width: 100%;
            height: 100%;
            min-height: 220px;
            object-fit: cover;
            transition: transform .6s ease;
            display: block;
        }
        .gallery-card:hover { box-shadow: var(--be-shadow-strong); transform: translateY(-6px); }
        .gallery-card:hover img { transform: scale(1.08); }

        /* ---- CTA section ---- */
        section.bg-success {
            background: linear-gradient(135deg, var(--be-success), #0d4429) !important;
            position: relative;
            overflow: hidden;
        }
        section.bg-success::before {
            content: "";
            position: absolute; inset: 0;
            background: radial-gradient(circle at 20% 20%, rgba(255,193,7,.18), transparent 55%);
            pointer-events: none;
        }
        section.bg-success h6.text-warning { color: var(--be-primary) !important; }

        /* ---- Counters ---- */
        .counter { display: inline-block; }

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

        /* Fluid type */
        .display-3 { font-size: clamp(1.8rem, 4.5vw + .5rem, 3.4rem) !important; }
        .display-5 { font-size: clamp(1.5rem, 3vw + .5rem, 2.4rem) !important; }
        p { font-size: clamp(.92rem, .4vw + .8rem, 1.05rem); }

        @media (max-width: 991px) {
            .page-header .col-lg-5 { margin-top: 2.5rem; }
        }

        @media (max-width: 768px) {
            .py-5 { padding-top: 2.5rem !important; padding-bottom: 2.5rem !important; }
            .row.g-4 { row-gap: 1.25rem; }
            .glass-card, .glass-card-dark, .bg-white.rounded-4 { border-radius: .85rem !important; }
            .page-header .d-flex.flex-wrap.gap-3 { flex-direction: column; }
            .page-header .d-flex.flex-wrap.gap-3 .btn { width: 100%; text-align: center; }
        }

        @media (max-width: 576px) {
            .glass-card.p-5, .glass-card-dark.p-5 { padding: 1.75rem !important; }
            .glass-card.p-4, .bg-white.rounded-4.p-4 { padding: 1.25rem !important; }
            .section-title { font-size: 1.5rem !important; }
            .fa-5x { font-size: 3rem !important; }
            .fa-3x { font-size: 1.9rem !important; }
            .gallery-card img { min-height: 160px; }
            .whatsapp-float { width: 50px; height: 50px; font-size: 1.3rem; bottom: 76px; right: 16px; }
            .container { padding-left: 1rem; padding-right: 1rem; }
        }

        @media (max-width: 400px) {
            .display-3, h1 { font-size: 1.6rem !important; }
            .btn-lg { font-size: .85rem; padding: .65rem 1.3rem; }
        }
    </style>
</head>

<body>

    <div id="scrollProgress"></div>

    <!-- Navbar -->
    <?php include __DIR__ . '/navbar.php'; ?>
    <!-- Navbar End -->


    <!-- Page Header Start -->
    <div class="container-fluid page-header position-relative overflow-hidden py-5">

        <div class="container py-5 position-relative">

            <div class="row align-items-center">

                <div class="col-lg-7 reveal">

                    <h6 class="text-uppercase text-warning fw-bold mb-3">

                        Biome Foundation

                    </h6>

                    <h1 class="display-3 text-white fw-bold mb-4">

                        NGO &
                        <span class="text-success">
                            Sustainability
                        </span>

                    </h1>

                    <p class="text-light fs-5 mb-4">

                        Building a greener tomorrow through environmental conservation, bamboo plantation, community empowerment, education and sustainable development initiatives across North-East India.

                    </p>

                    <div class="d-flex flex-wrap gap-3">

                        <a href="#mission" class="btn btn-success">

                            Explore Our Mission

                        </a>

                        <a href="contact.php" class="btn btn-outline-light rounded-pill px-4 py-3">

                            Become a Volunteer

                        </a>

                    </div>

                </div>


                <div class="col-lg-5 text-center mt-5 mt-lg-0 reveal">

                    <div class="glass-card p-5 floating">

                        <i class="fas fa-seedling fa-5x text-success mb-4"></i>

                        <h3 class="text-white fw-bold">

                            Together We Can Create A Greener Future

                        </h3>

                        <p class="text-light mb-0">

                            Every tree planted, every community empowered, every life transformed contributes to a sustainable tomorrow.

                        </p>

                    </div>

                </div>

            </div>

            <nav class="mt-5">

                <ol class="breadcrumb">

                    <li class="breadcrumb-item">

                        <a class="text-white" href="index.php">

                            Home

                        </a>

                    </li>

                    <li class="breadcrumb-item text-white active">

                        NGO & Sustainability

                    </li>

                </ol>

            </nav>

        </div>

    </div>
    <!-- Page Header End -->


    <!-- Quick Info Cards Start -->
    <div class="container">
        <div class="row g-4 mb-5 reveal reveal-stagger" style="margin-top:-3rem;">

            <div class="col-md-4">
                <div class="card info-card border-0 shadow text-center p-4 h-100">
                    <i class="fa fa-phone fa-3x text-primary mb-3"></i>
                    <h5>Call</h5>
                    <p class="mb-0">
                        <a href="tel:+919678431656" class="text-dark text-decoration-none fw-semibold">
                            +91 96784 31656
                        </a>
                    </p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card info-card border-0 shadow text-center p-4 h-100">
                    <i class="fa fa-envelope fa-3x text-primary mb-3"></i>
                    <h5>Email</h5>
                    <p class="mb-0">
                        <a href="mailto:info@biomeenterprises.com" class="text-dark text-decoration-none">
                            info@biomeenterprises.com
                        </a>
                    </p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card info-card border-0 shadow text-center p-4 h-100">
                    <i class="fa fa-map-marker-alt fa-3x text-primary mb-3"></i>
                    <h5>Location</h5>
                    <p class="mb-0">Assam, India</p>
                </div>
            </div>

        </div>
    </div>
    <!-- Quick Info Cards End -->


    <!-- ===========================
        QUICK IMPACT SECTION
    ============================ -->

    <div class="container py-5">

        <div class="row g-4 reveal reveal-stagger">

            <div class="col-6 col-md-3">

                <div class="glass-card bg-white text-center p-4 h-100">

                    <i class="fas fa-tree fa-3x text-success mb-3"></i>

                    <h2 class="fw-bold text-success">

                        10K+

                    </h2>

                    <h6>

                        Trees To Be Planted

                    </h6>

                </div>

            </div>


            <div class="col-6 col-md-3">

                <div class="glass-card bg-white text-center p-4 h-100">

                    <i class="fas fa-users fa-3x text-success mb-3"></i>

                    <h2 class="fw-bold text-success">

                        500+

                    </h2>

                    <h6>

                        Families To Support

                    </h6>

                </div>

            </div>


            <div class="col-6 col-md-3">

                <div class="glass-card bg-white text-center p-4 h-100">

                    <i class="fas fa-hands-helping fa-3x text-success mb-3"></i>

                    <h2 class="fw-bold text-success">

                        100+

                    </h2>

                    <h6>

                        Volunteers

                    </h6>

                </div>

            </div>


            <div class="col-6 col-md-3">

                <div class="glass-card bg-white text-center p-4 h-100">

                    <i class="fas fa-globe-asia fa-3x text-success mb-3"></i>

                    <h2 class="fw-bold text-success">

                        20+

                    </h2>

                    <h6>

                        Sustainability Programs

                    </h6>

                </div>

            </div>

        </div>

    </div>

     <!-- ===========================
        CORE VALUES SECTION
=========================== -->

    <section class="py-5 bg-light">

        <div class="container">

            <div class="text-center mb-5 reveal">

                <h6 class="text-success text-uppercase fw-bold">

                    Our Core Values

                </h6>

                <h2 class="section-title">

                    Principles That Guide Every Initiative

                </h2>

                <p class="text-muted mt-4">

                    We believe meaningful change begins with responsible actions, transparent governance and active community participation.

                </p>

            </div>

            <div class="row g-4 reveal reveal-stagger">

                <div class="col-lg-4 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-seedling fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">

                            Environmental Care

                        </h4>

                        <p class="text-muted mb-0">

                            Promoting afforestation, bamboo cultivation, biodiversity conservation and sustainable environmental practices.

                        </p>

                    </div>

                </div>

                <div class="col-lg-4 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-users fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">

                            Community First

                        </h4>

                        <p class="text-muted mb-0">

                            Supporting education, healthcare, employment generation and rural development programs.

                        </p>

                    </div>

                </div>

                <div class="col-lg-4 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-handshake fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">

                            Collaboration

                        </h4>

                        <p class="text-muted mb-0">

                            Working together with government, NGOs, educational institutions and corporate partners.

                        </p>

                    </div>

                </div>

                <div class="col-lg-4 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-lightbulb fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">

                            Innovation

                        </h4>

                        <p class="text-muted mb-0">

                            Developing sustainable and practical solutions for long-term social and environmental impact.

                        </p>

                    </div>

                </div>

                <div class="col-lg-4 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-recycle fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">

                            Sustainability

                        </h4>

                        <p class="text-muted mb-0">

                            Encouraging responsible resource management and circular economy practices.

                        </p>

                    </div>

                </div>

                <div class="col-lg-4 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-globe-asia fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">

                            Global Responsibility

                        </h4>

                        <p class="text-muted mb-0">

                            Aligning our initiatives with the United Nations Sustainable Development Goals (SDGs).

                        </p>

                    </div>

                </div>

            </div>

        </div>

    </section>



    <!-- ===========================
        SUSTAINABILITY PILLARS
=========================== -->

    <section class="py-5">

        <div class="container">

            <div class="text-center mb-5 reveal">

                <h6 class="text-success text-uppercase fw-bold">

                    Sustainability Pillars

                </h6>

                <h2 class="section-title">

                    Areas We Focus On

                </h2>

            </div>

            <div class="row g-4 reveal reveal-stagger">

                <div class="col-lg-3 col-md-6">

                    <div class="glass-card-dark p-4 text-center h-100">

                        <i class="fas fa-tree fa-3x text-success mb-4"></i>

                        <h5 class="text-white">

                            Tree Plantation

                        </h5>

                        <p class="text-light mb-0">

                            Restoring green cover through plantation drives and awareness campaigns.

                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="glass-card-dark p-4 text-center h-100">

                        <i class="fas fa-leaf fa-3x text-success mb-4"></i>

                        <h5 class="text-white">

                            Bamboo Mission

                        </h5>

                        <p class="text-light mb-0">

                            Promoting bamboo cultivation as a sustainable livelihood and eco-friendly resource.

                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="glass-card-dark p-4 text-center h-100">

                        <i class="fas fa-book-reader fa-3x text-success mb-4"></i>

                        <h5 class="text-white">

                            Education

                        </h5>

                        <p class="text-light mb-0">

                            Skill development, awareness programs and educational support.

                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="glass-card-dark p-4 text-center h-100">

                        <i class="fas fa-hands-helping fa-3x text-success mb-4"></i>

                        <h5 class="text-white">

                            Social Welfare

                        </h5>

                        <p class="text-light mb-0">

                            Empowering rural communities through healthcare, livelihood and social initiatives.

                        </p>

                    </div>

                </div>

            </div>

        </div>

    </section>

    <!-- ===========================
        SDGs SECTION
=========================== -->

    <section class="py-5 bg-light">

        <div class="container">

            <div class="text-center mb-5 reveal">

                <h6 class="text-success text-uppercase fw-bold">

                    United Nations SDGs

                </h6>

                <h2 class="section-title">

                    Sustainable Development Goals We Support

                </h2>

                <p class="text-muted mt-4">

                    Our initiatives align with the United Nations Sustainable Development Goals to create a cleaner environment, stronger communities and a more sustainable future.

                </p>

            </div>

            <div class="row g-4 reveal reveal-stagger">

                <div class="col-lg-3 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-heart fa-3x text-danger mb-3"></i>

                        <h5>Good Health</h5>

                        <p class="mb-0">

                            Supporting community healthcare, sanitation and awareness initiatives.

                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-graduation-cap fa-3x text-primary mb-3"></i>

                        <h5>Quality Education</h5>

                        <p class="mb-0">

                            Skill development, literacy and educational empowerment programs.

                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-leaf fa-3x text-success mb-3"></i>

                        <h5>Climate Action</h5>

                        <p class="mb-0">

                            Plantation drives, biodiversity conservation and environmental awareness.

                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-hands-helping fa-3x text-warning mb-3"></i>

                        <h5>Partnerships</h5>

                        <p class="mb-0">

                            Working with organizations, institutions and communities for sustainable impact.

                        </p>

                    </div>

                </div>

            </div>

        </div>

    </section>



    <!-- ===========================
        OUR PROGRAMS
=========================== -->

    <section class="py-5">

        <div class="container">

            <div class="text-center mb-5 reveal">

                <h6 class="text-success text-uppercase fw-bold">

                    Our Programs

                </h6>

                <h2 class="section-title">

                    Creating Long-Term Social Impact

                </h2>

            </div>

            <div class="row g-4 reveal reveal-stagger">

                <div class="col-lg-6">

                    <div class="bg-white rounded-4 shadow p-4 h-100">

                        <div class="d-flex mb-4">

                            <div class="me-4">

                                <i class="fas fa-tree fa-3x text-success"></i>

                            </div>

                            <div>

                                <h4 class="fw-bold">

                                    Tree Plantation Drives

                                </h4>

                                <p class="mb-0">

                                    Organizing plantation campaigns in schools, villages, institutions and public spaces to increase green cover and promote environmental awareness.

                                </p>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-lg-6">

                    <div class="bg-white rounded-4 shadow p-4 h-100">

                        <div class="d-flex mb-4">

                            <div class="me-4">

                                <i class="fas fa-seedling fa-3x text-success"></i>

                            </div>

                            <div>

                                <h4 class="fw-bold">

                                    Bamboo Development

                                </h4>

                                <p class="mb-0">

                                    Promoting bamboo cultivation, processing and entrepreneurship as an eco-friendly and sustainable livelihood opportunity.

                                </p>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-lg-6">

                    <div class="bg-white rounded-4 shadow p-4 h-100">

                        <div class="d-flex mb-4">

                            <div class="me-4">

                                <i class="fas fa-book-reader fa-3x text-success"></i>

                            </div>

                            <div>

                                <h4 class="fw-bold">

                                    Education & Awareness

                                </h4>

                                <p class="mb-0">

                                    Conducting workshops, awareness campaigns and educational programs focused on sustainability and environmental protection.

                                </p>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-lg-6">

                    <div class="bg-white rounded-4 shadow p-4 h-100">

                        <div class="d-flex mb-4">

                            <div class="me-4">

                                <i class="fas fa-users fa-3x text-success"></i>

                            </div>

                            <div>

                                <h4 class="fw-bold">

                                    Community Empowerment

                                </h4>

                                <p class="mb-0">

                                    Supporting rural communities, women entrepreneurs, artisans and youth through skill development and livelihood initiatives.

                                </p>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>

    <!-- ===========================
        ENVIRONMENTAL INITIATIVES
=========================== -->

    <section class="py-5 bg-light">

        <div class="container">

            <div class="text-center mb-5 reveal">
                <h6 class="text-success text-uppercase fw-bold">
                    Environmental Initiatives
                </h6>

                <h2 class="section-title">
                    Protecting Nature Through Action
                </h2>

                <p class="text-muted mt-4">
                    Our environmental initiatives focus on restoring ecosystems, promoting sustainable living and encouraging active community participation for a greener future.
                </p>

            </div>

            <div class="row g-4 reveal reveal-stagger">

                <div class="col-lg-4">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-tree fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">
                            Tree Plantation
                        </h4>

                        <p class="mb-0">
                            Organizing plantation drives in schools, villages and public spaces to improve biodiversity and reduce carbon emissions.
                        </p>

                    </div>

                </div>

                <div class="col-lg-4">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-recycle fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">
                            Waste Management
                        </h4>

                        <p class="mb-0">
                            Promoting waste segregation, recycling and responsible disposal through awareness programs.
                        </p>

                    </div>

                </div>

                <div class="col-lg-4">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-water fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">
                            Water Conservation
                        </h4>

                        <p class="mb-0">
                            Encouraging rainwater harvesting, water conservation and responsible use of natural resources.
                        </p>

                    </div>

                </div>

            </div>

        </div>

    </section>



    <!-- ===========================
        COMMUNITY DEVELOPMENT
=========================== -->

    <section class="py-5">

        <div class="container">

            <div class="row align-items-center">

                <div class="col-lg-6 reveal">

                    <h6 class="text-success text-uppercase fw-bold">

                        Community Development

                    </h6>

                    <h2 class="display-5 fw-bold mb-4">

                        Empowering Communities Through Sustainable Growth

                    </h2>

                    <p class="mb-4">

                        We work closely with local communities to improve education, healthcare, employment opportunities and sustainable livelihoods through impactful social programs.

                    </p>

                    <div class="row g-3">

                        <div class="col-6">

                            <div class="d-flex">

                                <i class="fas fa-check-circle text-success me-3 mt-1"></i>

                                <span>

                                Skill Development

                            </span>

                            </div>

                        </div>

                        <div class="col-6">

                            <div class="d-flex">

                                <i class="fas fa-check-circle text-success me-3 mt-1"></i>

                                <span>

                                Women Empowerment

                            </span>

                            </div>

                        </div>

                        <div class="col-6">

                            <div class="d-flex">

                                <i class="fas fa-check-circle text-success me-3 mt-1"></i>

                                <span>

                                Rural Development

                            </span>

                            </div>

                        </div>

                        <div class="col-6">

                            <div class="d-flex">

                                <i class="fas fa-check-circle text-success me-3 mt-1"></i>

                                <span>

                                Youth Engagement

                            </span>

                            </div>

                        </div>

                        <div class="col-6">

                            <div class="d-flex">

                                <i class="fas fa-check-circle text-success me-3 mt-1"></i>

                                <span>

                                Health Awareness

                            </span>

                            </div>

                        </div>

                        <div class="col-6">

                            <div class="d-flex">

                                <i class="fas fa-check-circle text-success me-3 mt-1"></i>

                                <span>

                                Environmental Education

                            </span>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-lg-6 mt-5 mt-lg-0 reveal">

                    <div class="glass-card-dark p-5">

                        <h3 class="text-white fw-bold mb-4">

                            Our Focus Areas

                        </h3>

                        <div class="mb-4">

                            <h5 class="text-white">

                                🌱 Sustainable Agriculture

                            </h5>

                            <p class="text-light mb-0">

                                Promoting eco-friendly farming and sustainable cultivation practices.

                            </p>

                        </div>

                        <hr>

                        <div class="mb-4">

                            <h5 class="text-white">

                                🎋 Bamboo Economy

                            </h5>

                            <p class="text-light mb-0">

                                Supporting bamboo cultivation, processing and value-added products.

                            </p>

                        </div>

                        <hr>

                        <div class="mb-4">

                            <h5 class="text-white">

                                🤝 Social Responsibility

                            </h5>

                            <p class="text-light mb-0">

                                Building inclusive communities through partnerships and volunteer initiatives.

                            </p>

                        </div>

                        <hr>

                        <div>

                            <h5 class="text-white">

                                🌍 Climate Action

                            </h5>

                            <p class="text-light mb-0">

                                Reducing environmental impact through awareness, conservation and green practices.

                            </p>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>



    <!-- ===========================
        IMPACT STATISTICS
=========================== -->

    <section class="py-5 bg-light">

        <div class="container">

            <div class="text-center mb-5 reveal">

                <h6 class="text-success text-uppercase fw-bold">

                    Our Impact

                </h6>

                <h2 class="section-title">

                    Together We Are Making A Difference

                </h2>

                <p class="text-muted mt-4">

                    Every initiative contributes towards building a greener, healthier and more sustainable future for our communities.

                </p>

            </div>

            <div class="row g-4 reveal reveal-stagger">

                <div class="col-6 col-lg-3 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-tree fa-3x text-success mb-3"></i>

                        <h2 class="fw-bold text-success counter-number" data-target="10000">0</h2>

                        <h6>Trees Planned</h6>

                    </div>

                </div>

                <div class="col-6 col-lg-3 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-users fa-3x text-success mb-3"></i>

                        <h2 class="fw-bold text-success counter-number" data-target="500">0</h2>

                        <h6>Families Supported</h6>

                    </div>

                </div>

                <div class="col-6 col-lg-3 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-hands-helping fa-3x text-success mb-3"></i>

                        <h2 class="fw-bold text-success counter-number" data-target="100">0</h2>

                        <h6>Volunteers</h6>

                    </div>

                </div>

                <div class="col-6 col-lg-3 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-leaf fa-3x text-success mb-3"></i>

                        <h2 class="fw-bold text-success counter-number" data-target="20">0</h2>

                        <h6>Green Initiatives</h6>

                    </div>

                </div>

            </div>

        </div>

    </section>



    <!-- ===========================
        GALLERY
=========================== -->

    <section class="py-5">
        <div class="container">

            <div class="text-center mb-5 reveal">
                <h6 class="text-success text-uppercase fw-bold">
                    Gallery
                </h6>

                <h2 class="section-title">
                    Our Vision In Action
                </h2>

                <p class="text-muted mt-3">
                    A glimpse of our environmental conservation, community development and sustainability initiatives.
                </p>
            </div>

            <div class="row g-4 reveal reveal-stagger">

                <div class="col-12 col-lg-8">
                    <div class="gallery-card">
                        <img src="img/ngo1.png" alt="NGO Activity" loading="lazy">
                    </div>
                </div>

                <div class="col-6 col-lg-4">
                    <div class="gallery-card">
                        <img src="img/ngo2.png" alt="Tree Plantation" loading="lazy">
                    </div>
                </div>

                <div class="col-6 col-lg-4">
                    <div class="gallery-card">
                        <img src="img/ngo3.png" alt="Community Support" loading="lazy">
                    </div>
                </div>

                <div class="col-6 col-lg-4">
                    <div class="gallery-card">
                        <img src="img/ngo4.png" alt="Bamboo Initiative" loading="lazy">
                    </div>
                </div>

                <div class="col-6 col-lg-4">
                    <div class="gallery-card">
                        <img src="img/ngo5.png" alt="Environmental Program" loading="lazy">
                    </div>
                </div>

            </div>

        </div>
    </section>



    <!-- ===========================
        CALL TO ACTION
=========================== -->

    <section class="py-5 bg-success text-white">

        <div class="container text-center reveal">

            <h6 class="text-uppercase text-warning fw-bold">

                Join Our Mission

            </h6>

            <h2 class="display-5 text-white fw-bold mb-4">

                Together We Can Build A Sustainable Future

            </h2>

            <p class="lead mb-5">

                Whether you are an individual, organization or corporate partner, your support can help us create meaningful environmental and social impact.

            </p>

            <div class="d-flex justify-content-center flex-wrap gap-3">

                <a href="contact.php" class="btn btn-light btn-lg rounded-pill px-5">

                Become A Volunteer

            </a>

                <a href="contact.php" class="btn btn-outline-light btn-lg rounded-pill px-5">

                Partner With Us

            </a>

            </div>

        </div>

    </section>


    <!-- Floating WhatsApp Button -->
    <a href="https://wa.me/919678431656" target="_blank" class="whatsapp-float" aria-label="Chat on WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Sticky Mobile Call Bar -->
    <div id="mobileCallBar">
        <a href="tel:+919678431656" class="btn btn-primary flex-fill"><i class="fa fa-phone me-2"></i>Call Now</a>
        <a href="https://wa.me/919678431656" target="_blank" class="btn btn-success flex-fill"><i class="fab fa-whatsapp me-2"></i>WhatsApp</a>
    </div>

    <!-- Footer -->
    <?php include __DIR__ . '/footer.php'; ?>
    <!-- Footer End -->


    <!-- Back to Top -->
    <a href="#" class="btn btn-lg btn-primary btn-lg-square rounded-0 back-to-top"><i class="bi bi-arrow-up"></i></a>


    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="lib/wow/wow.min.js"></script>
    <script src="lib/easing/easing.min.js"></script>
    <script src="lib/waypoints/waypoints.min.js"></script>
    <!-- <script src="lib/counterup/counterup.min.js"></script> -->
    <script src="lib/owlcarousel/owl.carousel.min.js"></script>

    <!-- Template Javascript -->
    <script src="js/main.js"></script>

   <script>
    (function () {
        const counters = document.querySelectorAll('.counter-number');
        const duration = 1800;

        function animateCounter(el) {
            const target = parseInt(el.getAttribute('data-target'), 10) || 0;
            const start = performance.now();

            function step(now) {
                const progress = Math.min((now - start) / duration, 1);
                const eased = 1 - (1 - progress) * (1 - progress);
                el.textContent = Math.floor(eased * target);
                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    el.textContent = target;
                }
            }
            requestAnimationFrame(step);
        }

        if ('IntersectionObserver' in window) {
            const observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting && !entry.target.dataset.animated) {
                        entry.target.dataset.animated = 'true';
                        animateCounter(entry.target);
                        observer.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.4 });

            counters.forEach(function (el) { observer.observe(el); });
        } else {
            counters.forEach(animateCounter);
        }
    })();
    </script>

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

        // Reveal-on-scroll sections
        const reveals = document.querySelectorAll('.reveal');
        if ('IntersectionObserver' in window) {
            const revealObserver = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        revealObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.12 });
            reveals.forEach(function (el) { revealObserver.observe(el); });
        } else {
            reveals.forEach(function (el) { el.classList.add('is-visible'); });
        }

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

        // Card mouse-follow glow (gold accent, no template styling)
        document.querySelectorAll('.bg-white.rounded-4, .glass-card.bg-white').forEach(function (card) {
            card.addEventListener('mousemove', function (e) {
                const rect = card.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                card.style.background = 'radial-gradient(circle at ' + x + 'px ' + y + 'px, rgba(255,193,7,.10), #fff 65%)';
            });
            card.addEventListener('mouseleave', function () {
                card.style.background = '#fff';
            });
        });
    })();
    </script>


</body>

</html>