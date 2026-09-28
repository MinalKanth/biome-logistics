<?php
declare(strict_types=1);

// =========================================================================
// 1. ELITE SEO ENGINE & HEADERS
// =========================================================================
require_once __DIR__ . '/EliteSeoEngine.php'; 
$seo = new EliteEnterpriseSeoEngine();
$seo->sendEnterpriseHeaders();

require_once __DIR__ . '/admin/config/database.php';

// =========================================================================
// 2. CONTACT FORM & SECURE SESSION LOGIC
// =========================================================================
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
    <?php
    // =========================================================================
    // 🚀 DYNAMIC META GENERATION FOR CONTACT PAGE
    // =========================================================================
    echo $seo->generateMeta([
        'title'       => 'Contact Us | Biome Enterprises - Let\'s Talk Business',
        'description' => 'Get in touch with Biome Enterprises for logistics, bamboo trading, compliance services, and corporate solutions. We are here to help your business grow.',
        'keywords'    => 'Contact Biome Enterprises, customer support Assam, business inquiry logistics, bamboo supplier contact, corporate compliance help',
        'url_path'    => '/contact.php', 
        'type'        => 'website'
    ]);
    
    // =========================================================================
    // 🧠 AI SEMANTIC SCHEMAS (JSON-LD)
    // =========================================================================
    echo $seo->schema($seo->coreGraph());
    echo $seo->schema($seo->breadcrumbSchema([
        "Home" => "/",
        "Contact Us" => "/contact.php"
    ]));
    ?>

    <meta content="width=device-width, initial-scale=1.0" name="viewport">

    <!-- Favicon -->
    <link rel="icon" href="/favicon.ico" type="image/x-icon">

    <!-- Google Web Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Roboto:wght@500;700;800&display=swap" rel="stylesheet">

    <!-- Icon Font Stylesheet -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Libraries Stylesheet -->
    <link href="lib/animate/animate.min.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">

    <style>
        :root {
            --be-primary: #ffc107;
            --be-primary-dark: #bf9107;
            --be-success: #198754;
            --be-dark: #271e01;
            --be-radius: 1rem;
            --be-shadow-soft: 0 10px 30px rgba(57, 51, 10, 0.08);
            --be-shadow-strong: 0 20px 50px rgba(194, 157, 8, 0.18);
            --be-transition: all .35s cubic-bezier(.25,.8,.25,1);
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f7f9fc;
            overflow-x: hidden;
        }
        h1, h2, h3, h4, h5, h6 { font-family: 'Roboto', sans-serif; }

        

        /* ---- Reveal-on-scroll ---- */
        .reveal {
            opacity: 0;
            transform: translateY(40px);
            transition: opacity .8s ease, transform .8s ease;
        }
        .reveal.is-visible { opacity: 1; transform: translateY(0); }
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

        /* ---- Animated Background Blobs ---- */
        .be-blob {
            filter: blur(70px);
            opacity: 0.4;
            animation: be-float 10s ease-in-out infinite;
        }
        @keyframes be-float {
            0%, 100% { transform: translateY(0) translateX(0) scale(1); }
            50% { transform: translateY(-30px) translateX(20px) scale(1.05); }
        }

        /* ---- Glass / premium cards ---- */
        .card {
            border-radius: var(--be-radius) !important;
            transition: var(--be-transition);
        }
        .card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 45px rgba(191, 145, 7, 0.22) !important;
        }

        /* Quick-info cards (call / email / location) */
        .info-card {
            position: relative;
            overflow: hidden;
            border-radius: var(--be-radius);
            box-shadow: var(--be-shadow-soft);
            transition: var(--be-transition);
            background: #fff;
        }
        .info-card::before {
            content: "";
            position: absolute; inset: 0;
            background: radial-gradient(circle at top right, rgba(255,193,7,.12), transparent 60%);
        }
        .info-card i { transition: var(--be-transition); }
        .info-card:hover { transform: translateY(-6px); box-shadow: var(--be-shadow-strong); }
        .info-card:hover i { transform: scale(1.12) rotate(-4deg); filter: drop-shadow(0 4px 14px rgba(25,135,84,.45)); }

        /* Buttons with premium hover */
        .btn {
            border-radius: 50px;
            position: relative;
            overflow: hidden;
            transition: var(--be-transition);
        }
        .btn-primary {
            background: linear-gradient(135deg, var(--be-primary), var(--be-primary-dark));
            border: none;
            color: #271e01;
        }
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(255, 193, 7, 0.35);
            color: #271e01;
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

        /* ---- Section headings ---- */
        h6.text-secondary.text-uppercase {
            letter-spacing: 3px;
            font-weight: 700;
            font-size: .8rem;
            display: inline-block;
            position: relative;
            padding-bottom: .5rem;
        }
        h6.text-secondary.text-uppercase::after {
            content: "";
            position: absolute; left: 0; bottom: 0;
            width: 50px; height: 3px;
            background: linear-gradient(90deg, var(--be-primary), var(--be-success));
            border-radius: 2px;
        }

        /* ---- Form Styling ---- */
        .bg-light.p-4 {
            border-radius: var(--be-radius);
            box-shadow: var(--be-shadow-soft);
        }
        form .form-control, form .form-select {
            border-radius: .65rem;
            border: 1px solid #e3e8f0;
            transition: var(--be-transition);
        }
        form .form-control:focus, form .form-select:focus {
            border-color: var(--be-primary);
            box-shadow: 0 0 0 .25rem rgba(255, 193, 7, 0.18);
        }
        .form-floating > .form-control:focus ~ label,
        .form-floating > .form-control:not(:placeholder-shown) ~ label,
        .form-floating > .form-select ~ label {
            color: var(--be-primary-dark);
        }

        /* Map frame premium border */
        .contact-form ~ .pe-lg-0 .position-relative {
            border-radius: var(--be-radius);
            overflow: hidden;
            box-shadow: var(--be-shadow-strong);
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
            position: fixed !important;
            right: 24px !important;
            bottom: 20px !important;
            left: auto !important;
            z-index: 1501;
            transition: var(--be-transition);
        }
        .back-to-top:hover {
            background: var(--be-success) !important;
            box-shadow: 0 14px 32px rgba(25,135,84,.4);
        }

        /* ---- Navbar glass on scroll ---- */
        .navbar {
            background: transparent !important;
            box-shadow: none !important;
            transition: background .4s ease, box-shadow .4s ease, padding .4s ease;
        }
        .navbar .navbar-brand h2,
        .navbar .navbar-brand,
        .navbar .nav-link,
        .navbar .dropdown-toggle { color:#fff !important; }
        .navbar .navbar-toggler { border-color: rgba(255,255,255,.35); }
        .navbar .navbar-toggler i, .navbar .navbar-toggler-icon { color:#fff !important; }

        .navbar.be-scrolled {
            background: rgba(255,255,255,.98) !important;
            backdrop-filter: blur(14px) saturate(160%);
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            padding-top: .4rem !important;
            padding-bottom: .4rem !important;
            box-shadow: 0 6px 20px rgba(0,0,0,.08) !important;
        }
        .navbar.be-scrolled .navbar-brand h2,
        .navbar.be-scrolled .navbar-brand,
        .navbar.be-scrolled .nav-link,
        .navbar.be-scrolled .dropdown-toggle { color: var(--be-dark) !important; }

        /* Selection color branding */
        ::selection { background: var(--be-primary); color: #271e01; }

        /* ---- Mobile polish ---- */
        @media (max-width: 768px) {
            .py-5 { padding-top: 2.5rem !important; padding-bottom: 2.5rem !important; }
            .row.g-5 { --bs-gutter-y: 2rem; }
            .card { border-radius: .85rem !important; }
        }
        @media (max-width: 576px) {
            .whatsapp-float { width: 50px; height: 50px; font-size: 1.3rem; bottom: 76px; right: 16px; }
            .display-3 { font-size: 2rem !important; }
        }
    </style>
</head>

<body>
  

    <!-- Navbar -->
    <?php include __DIR__ . '/navbar.php'; ?>
    <!-- Navbar End -->

<!-- =========================
         PAGE HEADER START
    ========================= -->
    <div class="container-fluid page-header py-5 position-relative overflow-hidden">

        <!-- Animated Background Blobs -->
        <div class="be-blob position-absolute rounded-circle d-none d-md-block" style="width:280px;height:280px;top:8%;left:-6%;background:#ffc107; z-index: 0; pointer-events: none;"></div>
        <div class="be-blob position-absolute rounded-circle d-none d-md-block" style="width:220px;height:220px;bottom:5%;right:-4%;background:#198754;animation-delay:2s; z-index: 0; pointer-events: none;"></div>

        <!-- Aligned container (Center on mobile, Left on desktop) -->
        <div class="container py-5 position-relative z-1 text-center text-lg-start">

            <h6 class="text-uppercase text-warning fw-bold mb-3 reveal">
                Get In Touch
            </h6>

            <h1 class="text-white fw-bold mb-4 reveal" style="line-height: 1.2;">
                Contact <span class="text-success">Biome Enterprises</span>
            </h1>

            <p class="text-light fs-5 mb-4 reveal mx-auto mx-lg-0 opacity-75" style="max-width: 800px;">
                Transportation • Bamboo Trading • Legal Services • Cab Rentals • Business Registration
            </p>

            <nav class="reveal d-flex justify-content-center justify-content-lg-start">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a class="text-white text-decoration-none opacity-75" href="index.php" style="transition: all 0.3s ease;">Home</a>
                    </li>
                    <li class="breadcrumb-item text-white fw-bold active" aria-current="page">
                        Contact Us
                    </li>
                </ol>
            </nav>

        </div>
    </div>
    <!-- PAGE HEADER END -->


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


    <!-- Contact Start -->
    <div class="container-fluid overflow-hidden py-5 px-lg-0">
        <div class="container contact-page py-5 px-lg-0">
            <div class="row g-5 mx-lg-0 align-items-stretch">

                <div class="col-md-6 contact-form reveal">
                    <h6 class="text-secondary text-uppercase">Let's Build Your Business Together</h6>
                    <h1 class="mb-4">Need Transportation, Bamboo or Business Services?</h1>
                    <p class="mb-4">
                        Whether you're looking for logistics, premium bamboo products, GST registration, MSME registration,
                        company incorporation, accounting services or cab rentals, our team is ready to assist you with
                        reliable and professional solutions.
                    </p>

                    <div class="bg-light p-4">
                        <?php if ($contactFormSuccess): ?>
                            <div class="alert alert-success">Thank you! Your message has been received. We'll get back to you shortly.</div>
                        <?php endif; ?>

                        <?php if ($contactFormErrors): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    <?php foreach ($contactFormErrors as $err): ?>
                                        <li><?= cf_e($err) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?= cf_e($contactCsrfToken) ?>">
                            <input type="hidden" name="contact_form_submit" value="1">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="text" class="form-control" id="name" name="name" placeholder="Your Name" maxlength="150" required value="<?= cf_e($contactFormValues['name']) ?>">
                                        <label for="name">Your Name</label>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-floating">
                                        <input type="email" class="form-control" id="email" name="email" placeholder="Your Email" maxlength="150" required value="<?= cf_e($contactFormValues['email']) ?>">
                                        <label for="email">Your Email</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-floating">
                                        <select class="form-select" id="subject" name="subject">
                                            <option value="" <?= $contactFormValues['subject'] === '' ? 'selected' : '' ?>>Select a service</option>
                                            <?php
                                            $services = ['Transportation & Logistics', 'Bamboo Trading', 'GST Registration', 'Company Registration', 'MSME Registration', 'Accounting', 'Cab Rental', 'Other'];
                                            foreach ($services as $service):
                                            ?>
                                                <option value="<?= cf_e($service) ?>" <?= $contactFormValues['subject'] === $service ? 'selected' : '' ?>><?= cf_e($service) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <label for="subject">Service Required</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <div class="form-floating">
                                        <textarea class="form-control" placeholder="Describe your requirements..." id="message" name="message" maxlength="2000" style="height: 100px"><?= cf_e($contactFormValues['message']) ?></textarea>
                                        <label for="message">Message</label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-primary btn-lg w-100 py-3" type="submit">Request Consultation</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="col-md-6 pe-lg-0 reveal">
                    <div class="position-relative h-100" style="min-height:420px;">
                        <iframe class="position-absolute w-100 h-100" style="object-fit: cover; border:0;" src="https://www.google.com/maps?q=Assam,India&output=embed" allowfullscreen="" aria-hidden="false" tabindex="0"></iframe>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Contact End -->


   

    <!-- Footer -->
    <?php include __DIR__ . '/footer.php'; ?>
    <!-- Footer End -->

 