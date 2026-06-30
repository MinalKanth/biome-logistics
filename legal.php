<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['legal_csrf_token'])) {
    $_SESSION['legal_csrf_token'] = bin2hex(random_bytes(32));
}
$legalCsrfToken = $_SESSION['legal_csrf_token'];

$legalFormErrors = [];
$legalFormSuccess = false;
if (!empty($_SESSION['legal_flash_success'])) {
    $legalFormSuccess = true;
    unset($_SESSION['legal_flash_success']);
}

$legalFormValues = [
    'name'    => '',
    'email'   => '',
    'phone'   => '',
    'service' => '',
    'message' => '',
];

$legalAllowedServices = [
    'Company Registration', 'GST Registration', 'Accounting', 'Legal Documentation',
    'MSME Registration', 'FSSAI Registration', 'Import Export Code',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['legal_form_submit'])) {

    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['legal_csrf_token'], $postedToken)) {
        $legalFormErrors[] = 'Your session expired. Please refresh the page and try again.';
    } else {

        $name    = trim((string) ($_POST['name'] ?? ''));
        $email   = trim((string) ($_POST['email'] ?? ''));
        $phone   = trim((string) ($_POST['phone'] ?? ''));
        $service = trim((string) ($_POST['service'] ?? ''));
        $message = trim((string) ($_POST['message'] ?? ''));

        $legalFormValues = compact('name', 'email', 'phone', 'service', 'message');

        if ($name === '' || mb_strlen($name) > 150) {
            $legalFormErrors[] = 'Name is required (max 150 characters).';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            $legalFormErrors[] = 'Please enter a valid email address.';
        }
        if ($phone === '' || !preg_match('/^[0-9+\-\s()]{7,20}$/', $phone)) {
            $legalFormErrors[] = 'Please enter a valid phone number.';
        }
        if ($service !== '' && !in_array($service, $legalAllowedServices, true)) {
            $legalFormErrors[] = 'Please select a valid service from the list.';
        }
        if (mb_strlen($message) > 2000) {
            $legalFormErrors[] = 'Message is too long (max 2000 characters).';
        }

        $now = time();
        $bucket = $_SESSION['legal_rate_limit'] ?? ['count' => 0, 'start' => $now];
        if ($now - $bucket['start'] > 600) {
            $bucket = ['count' => 0, 'start' => $now];
        }
        $bucket['count']++;
        $_SESSION['legal_rate_limit'] = $bucket;
        if ($bucket['count'] > 3) {
            $legalFormErrors[] = 'Too many submissions. Please wait a few minutes and try again.';
        }

        if (!$legalFormErrors) {
            try {
                $pdo = get_db();
                $stmt = $pdo->prepare(
                    'INSERT INTO legal_consultation_requests (full_name, email, phone, service_required, message, ip_address)
                     VALUES (:name, :email, :phone, :service, :message, :ip)'
                );
                $stmt->execute([
                    ':name'    => $name,
                    ':email'   => $email,
                    ':phone'   => $phone,
                    ':service' => $service !== '' ? $service : null,
                    ':message' => $message !== '' ? $message : null,
                    ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                $_SESSION['legal_flash_success'] = true;
                $_SESSION['legal_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . $_SERVER['PHP_SELF'] . '#consultation');
                exit;

            } catch (PDOException $e) {
                error_log('Legal consultation insert failed: ' . $e->getMessage());
                $legalFormErrors[] = 'Something went wrong on our end. Please try again later.';
            }
        }
    }
}

function lf_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Biome Enterprises | Legal & Accounting Services</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">

    <meta name="keywords" content="Legal Services, Accounting Services, GST Registration, MSME Registration, FSSAI Registration, Company Registration, Import Export Code, Bookkeeping, Assam">

    <meta name="description" content="Biome Enterprises provides complete legal, accounting, taxation, registration and compliance services for startups, MSMEs and businesses across North-East India.">

    <!-- Favicon -->
    <link href="img/favicon.ico" rel="icon">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Roboto:wght@500;700&display=swap" rel="stylesheet">

    <!-- Icons -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Libraries -->
    <link href="lib/animate/animate.min.css" rel="stylesheet">

    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">

    <!-- Bootstrap -->
    <link href="css/bootstrap.min.css" rel="stylesheet">

    <!-- Main CSS -->
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
            transition: opacity .7s ease, transform .7s ease;
        }
        .reveal.is-visible { opacity: 1; transform: translateY(0); }

        /* ---- Navbar glass on scroll ---- */
        .navbar {
            transition: background .4s ease, box-shadow .4s ease, padding .4s ease;
        }
        .navbar.be-scrolled {
            background: rgba(255,255,255,.78) !important;
            backdrop-filter: blur(14px) saturate(160%);
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            padding-top: .4rem !important;
            padding-bottom: .4rem !important;
            box-shadow: 0 6px 20px rgba(0,0,0,.08) !important;
        }

        /* ---- Page header ---- */
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
        .page-header h6.text-success { color: var(--be-primary) !important; }
        .page-header .text-success { color: var(--be-primary) !important; }
        .breadcrumb { background: transparent; margin: 0; }
        .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,.6); }

        /* ---- Fluid type ---- */
        h1, .display-3 { font-size: clamp(1.8rem, 4.5vw + .5rem, 3.2rem) !important; }
        h2, .display-5 { font-size: clamp(1.5rem, 3vw + .5rem, 2.3rem) !important; }
        p { font-size: clamp(.92rem, .4vw + .8rem, 1.05rem); }

        /* ---- Cards ---- */
        .rounded-4 { border-radius: var(--be-radius) !important; }
        .bg-white.rounded-4, .bg-light.rounded-4, .border.rounded-4 {
            transition: var(--be-transition);
        }
        .bg-white.rounded-4:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 45px rgba(191, 145, 7, 0.22) !important;
        }
        .bg-white.rounded-4 i.text-success {
            transition: var(--be-transition);
        }
        .bg-white.rounded-4:hover i.text-success {
            transform: scale(1.1) rotate(-4deg);
            filter: drop-shadow(0 4px 14px rgba(25,135,84,.45));
        }

        /* ---- Buttons ---- */
        .btn {
            position: relative;
            overflow: hidden;
            border-radius: 50px;
            transition: var(--be-transition);
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
            background: linear-gradient(135deg, var(--be-success), #115d3a);
            border: none;
        }
        .btn-success:hover {
            box-shadow: 0 12px 24px rgba(25, 135, 84, 0.35);
        }
        .btn-light:hover {
            box-shadow: 0 12px 24px rgba(0,0,0,.18);
        }
        .ripple {
            position: absolute;
            border-radius: 50%;
            background: rgba(255,255,255,.4);
            transform: scale(0);
            animation: be-ripple .6s linear;
            pointer-events: none;
        }
        @keyframes be-ripple {
            to { transform: scale(4); opacity: 0; }
        }

        /* ---- List group ---- */
        .list-group-item {
            border-color: #eee;
            transition: var(--be-transition);
        }
        .list-group-item:hover {
            background: rgba(255,193,7,.08);
            transform: translateX(4px);
        }

        /* ---- Accordion ---- */
        .accordion-button:not(.collapsed) {
            background: rgba(25,135,84,.08);
            color: var(--be-success);
            box-shadow: none;
        }
        .accordion-button:focus { box-shadow: none; border-color: var(--be-primary); }

        /* ---- Form ---- */
        #consultation .form-control, #consultation .form-select {
            border-radius: .65rem;
            border: 1px solid #e3e8f0;
            transition: var(--be-transition);
        }
        #consultation .form-control:focus, #consultation .form-select:focus {
            border-color: var(--be-primary);
            box-shadow: 0 0 0 .25rem rgba(255, 193, 7, 0.18);
        }

        /* ---- CTA ---- */
        .bg-success {
            background: linear-gradient(135deg, var(--be-success), #0d4429) !important;
            position: relative;
            overflow: hidden;
        }
        .bg-success::before {
            content: "";
            position: absolute; inset: 0;
            background: radial-gradient(circle at 20% 20%, rgba(255,193,7,.18), transparent 55%);
            pointer-events: none;
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
        .back-to-top:hover {
            background: var(--be-success) !important;
            box-shadow: 0 14px 32px rgba(25,135,84,.4);
        }

        ::selection { background: var(--be-primary); color: var(--be-dark); }

        /* ===================== FULL MOBILE RESPONSIVENESS ===================== */

        @media (max-width: 768px) {
            .py-5 { padding-top: 2.5rem !important; padding-bottom: 2.5rem !important; }
            .row.g-4, .row.g-3, .row.g-5 { row-gap: 1.25rem; }
            .rounded-4 { border-radius: .85rem !important; }
        }

        @media (max-width: 576px) {
            .p-5 { padding: 1.75rem !important; }
            .fa-4x { font-size: 2.3rem !important; }
            .fa-3x { font-size: 1.9rem !important; }
            .fa-2x { font-size: 1.4rem !important; }
            .container { padding-left: 1rem; padding-right: 1rem; }
            .whatsapp-float { width: 50px; height: 50px; font-size: 1.3rem; bottom: 76px; right: 16px; }
            .btn.px-5 { padding: .85rem 1.75rem !important; }
            #consultation .col-lg-6 .btn-lg { width: 100%; }
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



    <!-- ==============================
            PAGE HEADER
    ===============================-->

    <div class="container-fluid page-header py-5">

        <div class="container py-5">

            <div class="row align-items-center">

                <div class="col-lg-8 reveal">

                    <h6 class="text-uppercase text-success fw-bold mb-3">

                        Business Compliance Experts

                    </h6>

                    <h1 class="display-3 text-white fw-bold mb-4">

                        Legal &
                        <span class="text-success">
                            Accounting Services
                        </span>

                    </h1>

                    <p class="text-light fs-5 mb-4">

                        Professional Legal Documentation, Company Registration, GST Compliance, Accounting, Taxation and Financial Management Solutions for Businesses across North-East India.

                    </p>

                    <a href="#consultation" class="btn btn-success btn-lg px-5 py-3 rounded-pill">

                        Get Free Consultation

                    </a>

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

                        Legal & Accounting Services

                    </li>

                </ol>

            </nav>

        </div>

    </div>

    <!-- PAGE HEADER END -->




    <!-- ==============================
            SERVICE HIGHLIGHTS
    ===============================-->

    <div class="container py-5">

        <div class="text-center mb-5 reveal">

            <h6 class="text-success text-uppercase fw-bold">

                What We Offer

            </h6>

            <h2 class="display-5 fw-bold">

                Complete Legal & Accounting Solutions

            </h2>

            <p class="text-muted">

                Helping startups, MSMEs, proprietorships, partnerships and companies remain compliant, financially organised and legally secure.

            </p>

        </div>

        <div class="row g-4 reveal">

            <!-- Card 1 -->

            <div class="col-lg-4 col-md-6">

                <div class="bg-white rounded-4 shadow h-100 p-4 text-center">

                    <div class="mb-4">

                        <i class="fas fa-file-signature fa-3x text-success"></i>

                    </div>

                    <h4 class="fw-bold">

                        Business Registration

                    </h4>

                    <p class="text-muted">

                        Complete registration support for Proprietorship, Partnership, LLP and Private Limited Companies.

                    </p>

                </div>

            </div>

            <!-- Card 2 -->

            <div class="col-lg-4 col-md-6">

                <div class="bg-white rounded-4 shadow h-100 p-4 text-center">

                    <div class="mb-4">

                        <i class="fas fa-balance-scale fa-3x text-success"></i>

                    </div>

                    <h4 class="fw-bold">

                        Legal Documentation

                    </h4>

                    <p class="text-muted">

                        Professional drafting, reviewing and negotiating contracts, agreements and business documentation.

                    </p>

                </div>

            </div>

            <!-- Card 3 -->

            <div class="col-lg-4 col-md-6">

                <div class="bg-white rounded-4 shadow h-100 p-4 text-center">

                    <div class="mb-4">

                        <i class="fas fa-calculator fa-3x text-success"></i>

                    </div>

                    <h4 class="fw-bold">

                        Accounting Services

                    </h4>

                    <p class="text-muted">

                        Complete bookkeeping, financial statements, accounting, tax planning and compliance.

                    </p>

                </div>

            </div>

            <!-- Card 4 -->

            <div class="col-lg-4 col-md-6">

                <div class="bg-white rounded-4 shadow h-100 p-4 text-center">

                    <div class="mb-4">

                        <i class="fas fa-file-invoice-dollar fa-3x text-success"></i>

                    </div>

                    <h4 class="fw-bold">

                        GST Services

                    </h4>

                    <p class="text-muted">

                        GST Registration, Return Filing, Amendments, Advisory and Compliance.

                    </p>

                </div>

            </div>

            <!-- Card 5 -->

            <div class="col-lg-4 col-md-6">

                <div class="bg-white rounded-4 shadow h-100 p-4 text-center">

                    <div class="mb-4">

                        <i class="fas fa-industry fa-3x text-success"></i>

                    </div>

                    <h4 class="fw-bold">

                        MSME & FSSAI

                    </h4>

                    <p class="text-muted">

                        UDYAM Registration, FSSAI License, Business Certifications and Renewals.

                    </p>

                </div>

            </div>

            <!-- Card 6 -->

            <div class="col-lg-4 col-md-6">

                <div class="bg-white rounded-4 shadow h-100 p-4 text-center">

                    <div class="mb-4">

                        <i class="fas fa-globe fa-3x text-success"></i>

                    </div>

                    <h4 class="fw-bold">

                        Import Export Code

                    </h4>

                    <p class="text-muted">

                        IEC Registration, Documentation and Compliance for International Trade.

                    </p>

                </div>

            </div>

        </div>

    </div>

    <!-- SERVICE SECTION END -->



    <!-- ==============================
            ABOUT SECTION
    ===============================-->

    <div class="container py-5">

        <div class="row align-items-center g-5">

            <div class="col-lg-6 reveal">

                <h6 class="text-success text-uppercase fw-bold">

                    About Our Services

                </h6>

                <h2 class="display-5 fw-bold mb-4">

                    Reliable Legal & Financial Support For Every Business

                </h2>

                <p class="mb-4">

                    Biome Enterprises delivers comprehensive legal, accounting and compliance services designed to simplify business operations. Whether you are launching a startup or managing an established enterprise, we provide expert guidance in legal documentation,
                    registrations, accounting, taxation and regulatory compliance.

                </p>

                <div class="row g-3">

                    <div class="col-md-6">

                        <div class="d-flex">

                            <i class="fas fa-check-circle text-success mt-1 me-3"></i>

                            <span>

                                Company Registration

                            </span>

                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="d-flex">

                            <i class="fas fa-check-circle text-success mt-1 me-3"></i>

                            <span>

                                GST Registration

                            </span>

                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="d-flex">

                            <i class="fas fa-check-circle text-success mt-1 me-3"></i>

                            <span>

                                Contract Drafting

                            </span>

                        </div>

                    </div>

                    <div class="col-md-6">

                        <div class="d-flex">

                            <i class="fas fa-check-circle text-success mt-1 me-3"></i>

                            <span>

                                Financial Reporting

                            </span>

                        </div>

                    </div>

                </div>

            </div>

            <div class="col-lg-6 reveal">

                <div class="position-relative">

                    <div class="bg-white shadow rounded-4 p-5">

                        <div class="text-center mb-4">

                            <i class="fas fa-balance-scale fa-4x text-success mb-3"></i>

                            <h3 class="fw-bold">
                                Business Compliance
                            </h3>

                            <p class="text-muted">

                                Complete legal, accounting and taxation services under one roof.

                            </p>

                        </div>

                        <div class="row g-4">

                            <div class="col-6">

                                <div class="border rounded-4 p-3 text-center">

                                    <h2 class="text-success fw-bold counter-number" data-target="100">0</h2>

                                    <p class="mb-0">

                                        Businesses Assisted

                                    </p>

                                </div>

                            </div>

                            <div class="col-6">

                                <div class="border rounded-4 p-3 text-center">

                                    <h2 class="text-success fw-bold counter-number" data-target="20">0</h2>

                                    <p class="mb-0">

                                        Professional Services

                                    </p>

                                </div>

                            </div>

                            <div class="col-6">

                                <div class="border rounded-4 p-3 text-center">

                                    <h2 class="text-success fw-bold">

                                        24x7

                                    </h2>

                                    <p class="mb-0">

                                        Client Support

                                    </p>

                                </div>

                            </div>

                            <div class="col-6">

                                <div class="border rounded-4 p-3 text-center">

                                    <h2 class="text-success fw-bold counter-number" data-target="100" data-suffix="%">0</h2>

                                    <p class="mb-0">

                                        Compliance Assistance

                                    </p>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>



    <!-- ==============================
            SERVICES LIST
    ===============================-->

    <div class="container-fluid bg-light py-5">

        <div class="container">

            <div class="text-center mb-5 reveal">

                <h6 class="text-success text-uppercase fw-bold">

                    Our Expertise

                </h6>

                <h2 class="display-5 fw-bold">

                    Legal & Accounting Services

                </h2>

                <p class="text-muted">

                    End-to-end business compliance and financial management solutions.

                </p>

            </div>

            <div class="row">

                <div class="col-lg-6 reveal">

                    <ul class="list-group shadow-sm">

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> Drafting, Reviewing & Negotiating Contracts

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> Legal Documentation

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> Company Registration

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> Partnership Registration

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> Private Limited Registration

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> LLP Registration

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> GST Registration

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> GST Return Filing

                        </li>

                    </ul>

                </div>

                <div class="col-lg-6 mt-4 mt-lg-0 reveal">

                    <ul class="list-group shadow-sm">

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> MSME (UDYAM) Registration

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> FSSAI Registration

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> Import Export Code (IEC)

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> Accounting & Bookkeeping

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> Financial Transactions

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> Financial Reporting

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> Financial Planning

                        </li>

                        <li class="list-group-item py-3">

                            <i class="fas fa-check text-success me-2"></i> Day-to-Day Accounts Management

                        </li>

                    </ul>

                </div>

            </div>

        </div>

    </div>



    <!-- ==============================
            CONSULTATION SECTION
    ===============================-->

    <div class="container py-5" id="consultation">

        <div class="row g-5">

            <div class="col-lg-6 reveal">

                <h6 class="text-success text-uppercase">

                    Free Consultation

                </h6>

                <h2 class="display-5 fw-bold mb-4">

                    Let's Build Your Business Together

                </h2>

                <p class="mb-4">

                    Whether you're starting a new company, registering GST, applying for MSME, maintaining books of accounts or drafting business agreements, our experts are ready to help.

                </p>

                <?php if ($legalFormSuccess): ?>
                        <div class="alert alert-success">Thank you! Your consultation request has been received. Our team will contact you shortly.</div>
                    <?php endif; ?>

                    <?php if ($legalFormErrors): ?>
                        <div class="alert alert-danger">
                            <ul class="mb-0">
                                <?php foreach ($legalFormErrors as $err): ?>
                                    <li><?= lf_e($err) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?= lf_e($legalCsrfToken) ?>">
                        <input type="hidden" name="legal_form_submit" value="1">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <input type="text" name="name" class="form-control py-3" placeholder="Your Name" maxlength="150" required value="<?= lf_e($legalFormValues['name']) ?>">
                            </div>
                            <div class="col-md-6">
                                <input type="email" name="email" class="form-control py-3" placeholder="Email Address" maxlength="150" required value="<?= lf_e($legalFormValues['email']) ?>">
                            </div>
                            <div class="col-12">
                                <input type="tel" name="phone" class="form-control py-3" placeholder="Phone Number" maxlength="20" required value="<?= lf_e($legalFormValues['phone']) ?>">
                            </div>
                            <div class="col-12">
                                <select class="form-select py-3" name="service">
                                    <option value="" <?= $legalFormValues['service'] === '' ? 'selected' : '' ?>>Select Service</option>
                                    <?php foreach ($legalAllowedServices as $service): ?>
                                        <option value="<?= lf_e($service) ?>" <?= $legalFormValues['service'] === $service ? 'selected' : '' ?>><?= lf_e($service) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <textarea name="message" class="form-control" rows="6" maxlength="2000" placeholder="Describe your requirement"><?= lf_e($legalFormValues['message']) ?></textarea>
                            </div>
                            <div class="col-12">
                                <button class="btn btn-success btn-lg w-100" type="submit">Request Consultation</button>
                            </div>
                        </div>
                    </form>

            </div>

            <div class="col-lg-6 reveal">

                <div class="bg-light rounded-4 shadow p-5 h-100">

                    <h3 class="fw-bold mb-4">
                        Why Choose Biome Enterprises?
                    </h3>

                    <div class="d-flex mb-4">

                        <div class="me-3">
                            <i class="fas fa-check-circle fa-2x text-success"></i>
                        </div>

                        <div>

                            <h5>Experienced Professionals</h5>

                            <p class="mb-0">
                                Expert guidance for business registration, legal documentation and accounting services.
                            </p>

                        </div>

                    </div>

                    <div class="d-flex mb-4">

                        <div class="me-3">
                            <i class="fas fa-check-circle fa-2x text-success"></i>
                        </div>

                        <div>

                            <h5>Complete Compliance</h5>

                            <p class="mb-0">
                                Stay legally compliant with timely registrations, filings and documentation.
                            </p>

                        </div>

                    </div>

                    <div class="d-flex mb-4">

                        <div class="me-3">
                            <i class="fas fa-check-circle fa-2x text-success"></i>
                        </div>

                        <div>

                            <h5>Affordable Pricing</h5>

                            <p class="mb-0">
                                Transparent pricing with customized solutions for startups, MSMEs and enterprises.
                            </p>

                        </div>

                    </div>

                    <div class="d-flex">

                        <div class="me-3">
                            <i class="fas fa-check-circle fa-2x text-success"></i>
                        </div>

                        <div>

                            <h5>Fast Turnaround</h5>

                            <p class="mb-0">
                                Quick processing and dedicated customer support.
                            </p>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>



    <!-- FAQ -->

    <div class="container py-5">

        <div class="text-center mb-5 reveal">

            <h6 class="text-success text-uppercase">
                Frequently Asked Questions
            </h6>

            <h2 class="display-5 fw-bold">
                Common Questions
            </h2>

        </div>

        <div class="accordion reveal" id="faqAccordion">

            <div class="accordion-item">

                <h2 class="accordion-header">

                    <button class="accordion-button" data-bs-toggle="collapse" data-bs-target="#faq1">

                        Which registrations do you provide?

                    </button>

                </h2>

                <div id="faq1" class="accordion-collapse collapse show" data-bs-parent="#faqAccordion">

                    <div class="accordion-body">

                        Company Registration, GST Registration, MSME Registration, FSSAI, IEC, Partnership, LLP and Proprietorship Registration.

                    </div>

                </div>

            </div>

            <div class="accordion-item">

                <h2 class="accordion-header">

                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#faq2">

                        Do you provide accounting services?

                    </button>

                </h2>

                <div id="faq2" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">

                    <div class="accordion-body">

                        Yes. We provide bookkeeping, financial reporting, accounting, taxation and financial planning.

                    </div>

                </div>

            </div>

            <div class="accordion-item">

                <h2 class="accordion-header">

                    <button class="accordion-button collapsed" data-bs-toggle="collapse" data-bs-target="#faq3">

                        Can startups avail your services?

                    </button>

                </h2>

                <div id="faq3" class="accordion-collapse collapse" data-bs-parent="#faqAccordion">

                    <div class="accordion-body">

                        Absolutely. We specialize in helping startups establish legally compliant businesses.

                    </div>

                </div>

            </div>

        </div>

    </div>



    <!-- CTA -->

    <div class="container-fluid bg-success text-white py-5">

        <div class="container text-center reveal">

            <h2 class="display-5 text-white mb-4">

                Ready To Grow Your Business?

            </h2>

            <p class="lead mb-4">

                Let our experts manage your legal, accounting and compliance needs while you focus on growing your business.

            </p>

            <a href="contact.php" class="btn btn-light btn-lg rounded-pill px-5">

                Contact Us Today

            </a>

        </div>

    </div>



    <!-- Footer -->

    <?php include __DIR__ . '/footer.php'; ?>


    <!-- Floating WhatsApp Button -->
    <a href="https://wa.me/919678431656" target="_blank" class="whatsapp-float" aria-label="Chat on WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Sticky Mobile Call Bar -->
    <div id="mobileCallBar">
        <a href="tel:+919678431656" class="btn btn-success flex-fill"><i class="fa fa-phone me-2"></i>Call Now</a>
        <a href="https://wa.me/919678431656" target="_blank" class="btn btn-light flex-fill"><i class="fab fa-whatsapp me-2"></i>WhatsApp</a>
    </div>

    <!-- Back To Top -->

    <a href="#" class="btn btn-lg btn-lg-square rounded-0 back-to-top">

        <i class="bi bi-arrow-up"></i>

    </a>


    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="lib/wow/wow.min.js"></script>
    <script src="lib/easing/easing.min.js"></script>
    <script src="lib/waypoints/waypoints.min.js"></script>
    <script src="lib/owlcarousel/owl.carousel.min.js"></script>
    <script src="js/main.js"></script>

    <!-- ===================== Counter Animation (vanilla JS, 0 -> target) ===================== -->
    <script>
    (function () {
        const counters = document.querySelectorAll('.counter-number');
        const duration = 1600;

        function animateCounter(el) {
            const target = parseInt(el.getAttribute('data-target'), 10) || 0;
            const suffix = el.getAttribute('data-suffix') || '+';
            const start = performance.now();

            function step(now) {
                const progress = Math.min((now - start) / duration, 1);
                const eased = 1 - (1 - progress) * (1 - progress);
                el.textContent = Math.floor(eased * target);
                if (progress < 1) {
                    requestAnimationFrame(step);
                } else {
                    el.textContent = target + suffix;
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

    <!-- ===================== Premium Interactivity ===================== -->
    <script>
    (function () {
        const isTouch = window.matchMedia('(hover: none), (pointer: coarse)').matches;

        // Scroll progress bar
        const progressBar = document.getElementById('scrollProgress');
        function updateProgress() {
            const h = document.documentElement;
            const scrolled = (h.scrollTop) / (h.scrollHeight - h.clientHeight) * 100;
            if (progressBar) progressBar.style.width = scrolled + '%';
        }
        window.addEventListener('scroll', updateProgress, { passive: true });
        updateProgress();

        // Reveal-on-scroll
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

        // Navbar glass on scroll
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

        // Card spotlight + tilt (desktop only)
        if (!isTouch) {
            document.querySelectorAll('.bg-white.rounded-4, .bg-light.rounded-4').forEach(function (card) {
                card.addEventListener('mousemove', function (e) {
                    const rect = card.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    card.style.background = 'radial-gradient(circle at ' + x + 'px ' + y + 'px, rgba(255,193,7,.10), #fff 65%)';
                });
                card.addEventListener('mouseleave', function () {
                    card.style.background = '';
                });
            });

            // Magnetic buttons
            document.querySelectorAll('.btn').forEach(function (btn) {
                btn.addEventListener('mousemove', function (e) {
                    const rect = btn.getBoundingClientRect();
                    const x = e.clientX - rect.left - rect.width / 2;
                    const y = e.clientY - rect.top - rect.height / 2;
                    btn.style.transform = 'translate(' + x * .12 + 'px,' + y * .12 + 'px)';
                });
                btn.addEventListener('mouseleave', function () {
                    btn.style.transform = '';
                });
            });
        }

        // Ripple on click (works on touch too)
        document.querySelectorAll('.btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                const circle = document.createElement('span');
                circle.classList.add('ripple');
                const d = Math.max(btn.clientWidth, btn.clientHeight);
                circle.style.width = d + 'px';
                circle.style.height = d + 'px';
                const rect = btn.getBoundingClientRect();
                const offsetX = (e.clientX || rect.left + rect.width / 2) - rect.left;
                const offsetY = (e.clientY || rect.top + rect.height / 2) - rect.top;
                circle.style.left = offsetX - d / 2 + 'px';
                circle.style.top = offsetY - d / 2 + 'px';
                btn.appendChild(circle);
                setTimeout(function () { circle.remove(); }, 650);
            });
        });

        // Smooth anchor scroll
        document.querySelectorAll('a[href^="#"]').forEach(function (anchor) {
            anchor.addEventListener('click', function (e) {
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            });
        });

        // Floating-label lift on focus
        document.querySelectorAll('.form-control, .form-select').forEach(function (input) {
            input.addEventListener('focus', function () { input.style.transform = 'translateY(-2px)'; });
            input.addEventListener('blur', function () { input.style.transform = ''; });
        });
    })();
    </script>
</body>

</html>