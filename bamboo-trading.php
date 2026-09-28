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
// 2. FORM & SECURE SESSION LOGIC
// =========================================================================
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['bamboo_csrf_token'])) {
    $_SESSION['bamboo_csrf_token'] = bin2hex(random_bytes(32));
}
$bambooCsrfToken = $_SESSION['bamboo_csrf_token'];

$bambooFormErrors = [];
$bambooFormSuccess = false;
if (!empty($_SESSION['bamboo_flash_success'])) {
    $bambooFormSuccess = true;
    unset($_SESSION['bamboo_flash_success']);
}

$bambooFormValues = [
    'full_name'          => '',
    'mobile_number'      => '',
    'email'              => '',
    'company_name'       => '',
    'state'              => '',
    'city'               => '',
    'products'           => [],
    'quantity_required'  => '',
    'delivery_location'  => '',
    'message'            => '',
];

$bambooAllowedProducts = [
    'Raw Long Bamboo', 'Raw Bamboo Pieces', 'Bamboo Poles', 'Bamboo Sticks',
    'Bamboo Fence', 'Bamboo Mats', 'Bamboo Furniture', 'Bamboo Handicrafts',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bamboo_form_submit'])) {

    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['bamboo_csrf_token'], $postedToken)) {
        $bambooFormErrors[] = 'Your session expired. Please refresh the page and try again.';
    } else {

        $fullName  = trim((string) ($_POST['full_name'] ?? ''));
        $mobile    = trim((string) ($_POST['mobile_number'] ?? ''));
        $email     = trim((string) ($_POST['email'] ?? ''));
        $company   = trim((string) ($_POST['company_name'] ?? ''));
        $state     = trim((string) ($_POST['state'] ?? ''));
        $city      = trim((string) ($_POST['city'] ?? ''));
        $products  = is_array($_POST['products'] ?? null) ? $_POST['products'] : [];
        $quantity  = trim((string) ($_POST['quantity_required'] ?? ''));
        $delivery  = trim((string) ($_POST['delivery_location'] ?? ''));
        $message   = trim((string) ($_POST['message'] ?? ''));

        // Only keep products that are actually in our allowed list.
        $products = array_values(array_intersect($products, $bambooAllowedProducts));

        $bambooFormValues = [
            'full_name'         => $fullName,
            'mobile_number'     => $mobile,
            'email'             => $email,
            'company_name'      => $company,
            'state'             => $state,
            'city'              => $city,
            'products'          => $products,
            'quantity_required' => $quantity,
            'delivery_location' => $delivery,
            'message'           => $message,
        ];

        if ($fullName === '' || mb_strlen($fullName) > 150) {
            $bambooFormErrors[] = 'Full name is required (max 150 characters).';
        }
        if ($mobile === '' || !preg_match('/^[0-9+\-\s()]{7,20}$/', $mobile)) {
            $bambooFormErrors[] = 'Please enter a valid mobile number.';
        }
        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
            $bambooFormErrors[] = 'Please enter a valid email address.';
        }
        if (mb_strlen($company) > 150) {
            $bambooFormErrors[] = 'Company name is too long.';
        }
        if (mb_strlen($state) > 100 || mb_strlen($city) > 100) {
            $bambooFormErrors[] = 'State/City is too long.';
        }
        if (empty($products)) {
            $bambooFormErrors[] = 'Please select at least one product.';
        }
        if (mb_strlen($quantity) > 100) {
            $bambooFormErrors[] = 'Quantity required is too long.';
        }
        if (mb_strlen($delivery) > 200) {
            $bambooFormErrors[] = 'Delivery location is too long.';
        }
        if (mb_strlen($message) > 2000) {
            $bambooFormErrors[] = 'Additional requirements are too long (max 2000 characters).';
        }

        $now = time();
        $bucket = $_SESSION['bamboo_rate_limit'] ?? ['count' => 0, 'start' => $now];
        if ($now - $bucket['start'] > 600) {
            $bucket = ['count' => 0, 'start' => $now];
        }
        $bucket['count']++;
        $_SESSION['bamboo_rate_limit'] = $bucket;
        if ($bucket['count'] > 3) {
            $bambooFormErrors[] = 'Too many submissions. Please wait a few minutes and try again.';
        }

        if (!$bambooFormErrors) {
            try {
                $pdo = get_db();
                $stmt = $pdo->prepare(
                    'INSERT INTO bamboo_enquiries
                        (full_name, mobile_number, email, company_name, state, city, products_selected, quantity_required, delivery_location, additional_requirements, ip_address)
                     VALUES
                        (:full_name, :mobile_number, :email, :company_name, :state, :city, :products, :quantity, :delivery, :message, :ip)'
                );
                $stmt->execute([
                    ':full_name'   => $fullName,
                    ':mobile_number' => $mobile,
                    ':email'       => $email !== '' ? $email : null,
                    ':company_name' => $company !== '' ? $company : null,
                    ':state'       => $state !== '' ? $state : null,
                    ':city'        => $city !== '' ? $city : null,
                    ':products'    => implode(', ', $products),
                    ':quantity'    => $quantity !== '' ? $quantity : null,
                    ':delivery'    => $delivery !== '' ? $delivery : null,
                    ':message'     => $message !== '' ? $message : null,
                    ':ip'          => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                $_SESSION['bamboo_flash_success'] = true;
                $_SESSION['bamboo_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . $_SERVER['PHP_SELF'] . '#enquiry');
                exit;

            } catch (PDOException $e) {
                error_log('Bamboo enquiry insert failed: ' . $e->getMessage());
                $bambooFormErrors[] = 'Something went wrong on our end. Please try again later.';
            }
        }
    }
}

function bf_e(string $value): string
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
    // 🚀 DYNAMIC META GENERATION FOR BAMBOO PAGE
    // =========================================================================
    echo $seo->generateMeta([
        'title'       => 'Bamboo Trading & Suppliers | Biome Enterprises Assam',
        'description' => 'Premium raw bamboo, long bamboo poles, bamboo pieces, and sustainable bamboo products supplied across India by Biome Enterprises.',
        'keywords'    => 'Bamboo suppliers Assam, Raw bamboo trading, Buy long bamboo poles, Bamboo products India, Biome Enterprises bamboo',
        'url_path'    => '/bamboo.php', 
        'type'        => 'website'
    ]);
    
    // =========================================================================
    // 🧠 AI SEMANTIC SCHEMAS (JSON-LD)
    // =========================================================================
    echo $seo->schema($seo->coreGraph());
    echo $seo->schema($seo->breadcrumbSchema([
        "Home" => "/",
        "Bamboo Trading" => "/bamboo.php"
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
        /* ---- MASTER PREMIUM THEME VARIABLES ---- */
        :root {
            --be-primary: #ffc107;
            --be-primary-dark: #bf9107;
            --be-success: #198754;
            --be-dark: #271e01;
            --be-radius: 1rem;
            --be-shadow-soft: 0 10px 30px rgba(57, 51, 10, 0.08);
            --be-shadow-strong: 0 20px 50px rgba(194, 157, 8, 0.18);
            --be-transition: all .35s cubic-bezier(.25,.8,.25,1);
            
            /* Bamboo Specific Vars (Preserved) */
            --bamboo-green: #198754;
            --bamboo-green-dark: #14532d;
            --bamboo-gold: #ffc107;
            --bamboo-dark: #1c1f1d;
            --bamboo-transition: all .35s cubic-bezier(.25, .8, .25, 1);
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f7f9fc;
            overflow-x: hidden;
            max-width: 100%;
        }
        h1, h2, h3, h4, h5, h6 { font-family: 'Roboto', sans-serif; }
        img { max-width: 100%; height: auto; }

        /* ---- Scroll progress bar ---- */
        #scrollProgress {
            position: fixed; top: 0; left: 0; height: 4px; width: 0%;
            background: linear-gradient(90deg, var(--be-primary), var(--be-success));
            z-index: 2000; transition: width .1s ease-out;
        }

        /* ---- Cursor glow (desktop only) ---- */
        #cursorGlow {
            position: fixed; width: 320px; height: 320px; border-radius: 50%;
            background: radial-gradient(circle, rgba(255,193,7,.14), transparent 70%);
            pointer-events: none; z-index: 1; transform: translate(-50%, -50%);
            transition: opacity .3s ease; opacity: 0;
        }
        @media (hover: hover) and (pointer: fine) { #cursorGlow { opacity: 1; } }
        
        /* ---- Reveal-on-scroll Animations ---- */
        .reveal { opacity: 0; transform: translateY(40px); transition: opacity .8s ease, transform .8s ease; }
        .reveal.is-visible { opacity: 1; transform: translateY(0); }
        .reveal-stagger > * { opacity: 0; transform: translateY(30px); transition: opacity .7s ease, transform .7s ease; }
        .reveal-stagger.is-visible > * { opacity: 1; transform: translateY(0); }
        .reveal-stagger.is-visible > *:nth-child(1) { transition-delay: .05s; }
        .reveal-stagger.is-visible > *:nth-child(2) { transition-delay: .15s; }
        .reveal-stagger.is-visible > *:nth-child(3) { transition-delay: .25s; }
        .reveal-stagger.is-visible > *:nth-child(4) { transition-delay: .35s; }

        /* ---- PREMIUM PAGE HEADER (Strictly Locked Theme) ---- */
        .page-header {
            position: relative;
            background: linear-gradient(135deg, #1c1602 0%, #3a2e05 55%, #16210f 100%);
            overflow: hidden;
            padding: 100px 0 80px 0;
        }
        .page-header::before {
            content: ""; position: absolute; inset: 0;
            background: radial-gradient(circle at 15% 20%, rgba(255,193,7,.25), transparent 55%),
                        radial-gradient(circle at 85% 80%, rgba(25,135,84,.3), transparent 55%);
            pointer-events: none;
        }
        .page-header h6.text-warning {
            letter-spacing: 3px; display: inline-block; padding: .35rem 1rem;
            border: 1px solid rgba(255,255,255,.35); border-radius: 50px;
            backdrop-filter: blur(6px); background: rgba(255,255,255,.08);
            position: relative; z-index: 1; margin-bottom: 1rem;
        }
        .page-header .text-success { color: #198754 !important; }
        
        /* Breadcrumbs */
        .breadcrumb { background: rgba(0,0,0,0.25); display: inline-flex; padding: 10px 25px; border-radius: 50px; backdrop-filter: blur(8px); margin: 0; }
        .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,.6); }

        /* ---- Animated Background Blobs ---- */
        .be-blob { filter: blur(70px); opacity: 0.4; animation: be-float 10s ease-in-out infinite; }
        @keyframes be-float {
            0%, 100% { transform: translateY(0) translateX(0) scale(1); }
            50% { transform: translateY(-30px) translateX(20px) scale(1.05); }
        }

        /* Fluid typography */
        .display-3 { font-size: clamp(1.7rem, 5vw + 1rem, 4rem); }
        .display-5 { font-size: clamp(1.4rem, 3vw + 1rem, 2.5rem); }
        h1 { font-size: clamp(1.5rem, 3vw + 1rem, 2.5rem); }
        h2 { font-size: clamp(1.3rem, 2.5vw + .8rem, 2.1rem); }
        h3 { font-size: clamp(1.15rem, 2vw + .7rem, 1.75rem); }
        h4 { font-size: clamp(1.05rem, 1.5vw + .6rem, 1.5rem); }
        .fs-5 { font-size: clamp(.95rem, 1vw + .7rem, 1.25rem) !important; }

        /* ---------- Buttons ---------- */
        a, .btn { transition: var(--bamboo-transition); }
        .btn-success {
            position: relative; overflow: hidden; z-index: 1;
            background: linear-gradient(135deg, var(--be-success), #115d3a); border: none;
            border-radius: 50px; padding: 14px 34px;
        }
        .btn-success::after {
            content: ""; position: absolute; inset: 0;
            background: linear-gradient(120deg, rgba(255,255,255,.25), transparent 60%);
            transform: translateX(-120%); transition: transform .6s ease; z-index: -1;
        }
        .btn-success:hover::after { transform: translateX(0); }
        .btn-success:hover, .btn-outline-light:hover {
            transform: translateY(-3px); box-shadow: 0 12px 24px rgba(25, 135, 84, .25);
        }

        /* ---------- Product / Feature Cards ---------- */
        .service-item, .product-feature, .bg-white.rounded-4 {
            transition: var(--bamboo-transition);
            border: 1px solid rgba(25, 135, 84, .08);
            border-radius: var(--be-radius);
            box-shadow: var(--be-shadow-soft);
        }
        .service-item:hover, .product-feature:hover {
            transform: translateY(-10px);
            box-shadow: var(--be-shadow-strong) !important;
        }
        .service-item img { transition: transform .5s ease; }
        .service-item:hover img { transform: scale(1.06); }
        .badge { letter-spacing: .03em; }

        /* Industry / application icon tiles */
        .container-xxl .text-center.p-4.rounded-4 { transition: var(--bamboo-transition); cursor: default; }
        .container-xxl .text-center.p-4.rounded-4:hover {
            transform: translateY(-8px) scale(1.03); background: var(--bamboo-green) !important;
            box-shadow: var(--be-shadow-strong);
        }
        .container-xxl .text-center.p-4.rounded-4:hover i, .container-xxl .text-center.p-4.rounded-4:hover h6 { color: #fff !important; }

        /* ---------- Enquiry Form ---------- */
        #enquiry .bg-white.rounded-4 { border: none; box-shadow: var(--be-shadow-soft); }
        #enquiry .form-control, #enquiry .form-select {
            border-radius: .75rem; border: 1px solid #dee2e6; transition: var(--bamboo-transition);
        }
        #enquiry .form-control:focus, #enquiry .form-select:focus { border-color: var(--bamboo-green); box-shadow: 0 0 0 .25rem rgba(25, 135, 84, .15); }
        #enquiry .form-label { color: var(--bamboo-dark); font-size: .92rem; }
        #enquiry select[multiple] option { padding: .35rem .5rem; }
        #enquiry select[multiple] option:checked { background: var(--bamboo-green); color: #fff; }
        
        .char-counter { font-size: .78rem; color: #6c757d; display: block; text-align: right; margin-top: .25rem; }
        
        /* Button Loading state */
        #enquiry button[type="submit"].is-loading { pointer-events: none; opacity: .8; }
        #enquiry button[type="submit"] .spinner-border { width: 1rem; height: 1rem; margin-right: .5rem; display: none; }
        #enquiry button[type="submit"].is-loading .spinner-border { display: inline-block; }

        /* Form Invalid state */
        .form-control.is-invalid-bamboo, .form-select.is-invalid-bamboo { border-color: #dc3545 !important; box-shadow: 0 0 0 .2rem rgba(220, 53, 69, .15) !important; }

        /* ---------- Sticky Mobile CTA Bar ---------- */
        .bamboo-mobile-cta {
            display: none; position: fixed; left: 0; right: 0; bottom: 0; z-index: 1040;
            background: rgba(255,255,255,.92); backdrop-filter: blur(12px); box-shadow: 0 -8px 24px rgba(0,0,0,.12); padding: .6rem 1rem;
        }
        .bamboo-mobile-cta .btn { border-radius: 50px; font-weight: 600; }

        /* ---- WhatsApp floating pulse ---- */
        .whatsapp-float {
            position: fixed; bottom: 90px; right: 24px; z-index: 1500;
            width: 60px; height: 60px; border-radius: 50%; background: var(--be-success);
            display: flex; align-items: center; justify-content: center; color: #fff; font-size: 1.6rem;
            box-shadow: 0 8px 24px rgba(25,135,84,.4); animation: be-pulse 2.4s infinite;
        }
        @keyframes be-pulse {
            0% { box-shadow: 0 0 0 0 rgba(25,135,84,.45); }
            70% { box-shadow: 0 0 0 16px rgba(25,135,84,0); }
            100% { box-shadow: 0 0 0 0 rgba(25,135,84,0); }
        }

        /* ---- Back to top ---- */
        .back-to-top {
            border-radius: 50% !important; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;
            box-shadow: var(--be-shadow-strong); background: linear-gradient(135deg, var(--be-primary), var(--be-primary-dark)) !important; border: none;
            position: fixed !important; right: 24px !important; bottom: 20px !important; left: auto !important; z-index: 1501;
            transition: var(--be-transition);
        }
        .back-to-top:hover { background: var(--be-success) !important; box-shadow: 0 14px 32px rgba(25,135,84,.4); }

        /* Navbar Fixes */
        .navbar { transition: background .4s ease, box-shadow .4s ease, padding .4s ease; background: transparent !important; box-shadow: none !important; }
        .navbar .navbar-brand h2, .navbar .navbar-brand, .navbar .nav-link, .navbar .dropdown-toggle { color:#fff !important; }
        .navbar .navbar-toggler { border-color: rgba(255,255,255,.35); }
        .navbar .navbar-toggler i, .navbar .navbar-toggler-icon { color:#fff !important; }
        .navbar.be-scrolled { background: rgba(255,255,255,.98) !important; backdrop-filter: blur(14px); padding-top: .4rem !important; padding-bottom: .4rem !important; box-shadow: 0 6px 20px rgba(0,0,0,.08) !important; }
        .navbar.be-scrolled .navbar-brand h2, .navbar.be-scrolled .navbar-brand, .navbar.be-scrolled .nav-link, .navbar.be-scrolled .dropdown-toggle { color: var(--be-dark) !important; }

        ::selection { background: var(--be-primary); color: var(--be-dark); }

        /* ===================== FULL MOBILE RESPONSIVENESS ===================== */
        @media (max-width: 991.98px) {
            .bamboo-mobile-cta { display: flex; gap: .6rem; }
            body { padding-bottom: 70px; }
            .row.align-items-center.g-5 > [class*="col-lg-6"] { margin-bottom: 2rem; }
            .row.align-items-center.g-5 > [class*="col-lg-6"]:last-child { margin-bottom: 0; }
        }
        @media (max-width: 767.98px) {
            .back-to-top { bottom: 78px !important; right: 12px !important; }
            #enquiry .bg-white.rounded-4 { padding: 1.5rem !important; }
        }
        @media (max-width: 575.98px) {
            .whatsapp-float { width: 50px; height: 50px; font-size: 1.3rem; bottom: 76px; right: 16px; }
            .bamboo-mobile-cta { padding: .55rem .75rem; }
            .bamboo-mobile-cta .btn { font-size: .85rem; padding: .55rem .75rem; }
        }
    </style>
</head>

<body>

    <!-- Navbar -->
    <!-- <div id="navbar"></div> -->
    <?php include __DIR__ . '/navbar.php'; ?>

    <!-- Navbar End -->

    <!-- =========================
     PAGE HEADER START
========================= -->
    <div class="container-fluid page-header bamboo-header py-5 position-relative overflow-hidden" style="margin-bottom:6rem;">
        <div class="container py-5 position-relative">
            <div class="row align-items-center">

                <div class="col-lg-8">

                    <h6 class="text-uppercase text-warning fw-bold mb-3 animated slideInDown">
                        Premium Bamboo Trading
                    </h6>

                    <h1 class="display-3 text-white fw-bold mb-4 animated slideInDown">
                        Sustainable <span class="text-success">Bamboo Products</span><br> For Industries, Construction & Handicrafts
                    </h1>

                    <p class="text-light fs-5 mb-4 animated fadeInUp">
                        Biome Enterprises supplies premium quality bamboo products sourced from North-East India. We deliver bulk quantities across India for construction, furniture manufacturing, handicrafts, paper industries, agriculture and export businesses.
                    </p>

                    <div class="d-flex flex-wrap gap-3 animated fadeInUp">

                        <a href="#enquiry" class="btn btn-success btn-lg px-5 py-3 rounded-pill shadow">
                            <i class="fa fa-paper-plane me-2"></i> Request Bulk Quote
                        </a>

                        <a href="tel:+919678431656" class="btn btn-outline-light btn-lg px-5 py-3 rounded-pill">
                            <i class="fa fa-phone me-2"></i> Call Now
                        </a>

                    </div>

                </div>

            </div>
        </div>
    </div>
    <!-- PAGE HEADER END -->


    <!-- =========================
     ABOUT START
========================= -->

    <div class="container-fluid overflow-hidden py-5 px-lg-0">

        <div class="container py-5">

            <div class="row align-items-center g-5">

                <!-- IMAGE -->

                <div class="col-lg-6 wow fadeInLeft" data-wow-delay=".2s">

                    <div class="position-relative">

                        <img src="img/bamboo-main.png" class="img-fluid rounded-4 shadow-lg" alt="Biome Bamboo">

                        <div class="position-absolute bottom-0 start-0 translate-middle-y bg-success rounded-4 p-4 shadow-lg">

                            <h2 class="text-white fw-bold mb-0">
                                100+
                            </h2>

                            <small class="text-light">
                            Bulk Orders Delivered
                        </small>

                        </div>

                    </div>

                </div>


                <!-- CONTENT -->

                <div class="col-lg-6 wow fadeInRight" data-wow-delay=".3s">

                    <h6 class="text-success text-uppercase fw-bold mb-3">
                        About Bamboo Trading
                    </h6>

                    <h1 class="display-5 fw-bold mb-4">

                        Premium Bamboo Supplier From
                        <span class="text-success">
                        North-East India
                    </span>

                    </h1>

                    <p class="mb-4">

                        Biome Enterprises specializes in supplying premium quality bamboo products for construction companies, furniture manufacturers, handicraft industries, paper mills, interior designers, exporters and wholesale buyers.

                    </p>

                    <p class="mb-5">

                        We source high-quality bamboo directly from North-East India and provide reliable logistics, competitive pricing, customized sizing and timely delivery throughout India.

                    </p>


                    <div class="row g-4">

                        <div class="col-sm-6">

                            <div class="border rounded-4 p-4 h-100 shadow-sm product-feature">

                                <i class="fa fa-leaf fa-3x text-success mb-3"></i>

                                <h5>
                                    Sustainable Harvesting
                                </h5>

                                <p class="mb-0">

                                    Eco-friendly bamboo sourced responsibly from trusted growers.

                                </p>

                            </div>

                        </div>

                        <div class="col-sm-6">

                            <div class="border rounded-4 p-4 h-100 shadow-sm product-feature">

                                <i class="fa fa-truck fa-3x text-success mb-3"></i>

                                <h5>
                                    Pan India Delivery
                                </h5>

                                <p class="mb-0">

                                    Safe transportation to industries and businesses across India.

                                </p>

                            </div>

                        </div>

                        <div class="col-sm-6">

                            <div class="border rounded-4 p-4 h-100 shadow-sm product-feature">

                                <i class="fa fa-ruler-combined fa-3x text-success mb-3"></i>

                                <h5>
                                    Custom Sizes
                                </h5>

                                <p class="mb-0">

                                    Lengths and diameters customized according to your project.

                                </p>

                            </div>

                        </div>

                        <div class="col-sm-6">

                            <div class="border rounded-4 p-4 h-100 shadow-sm product-feature">

                                <i class="fa fa-award fa-3x text-success mb-3"></i>

                                <h5>
                                    Premium Quality
                                </h5>

                                <p class="mb-0">

                                    Carefully selected bamboo with strict quality inspection.

                                </p>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- ABOUT END -->


    <!-- =========================
     BAMBOO PRODUCTS START
========================= -->

    <div class="container-xxl py-5">

        <div class="container">

            <div class="text-center mb-5 wow fadeInUp">

                <h6 class="text-success text-uppercase">
                    Our Products
                </h6>

                <h1 class="display-5 fw-bold">

                    Explore Our Premium Bamboo Collection

                </h1>

                <p class="mx-auto" style="max-width:850px;">

                    We supply premium bamboo products for construction, furniture manufacturing, handicrafts, paper industries and industrial applications.

                </p>

            </div>

            <div class="row g-4">

                <!-- Product -->

                <div class="col-lg-4 col-md-6 wow fadeInUp">

                    <div class="service-item rounded-4 overflow-hidden shadow-lg border-0 h-100">

                        <img src="img/bamboo1.png" class="img-fluid w-100" alt="Long Bamboo">

                        <div class="p-4">

                            <h4>
                                Long Bamboo Poles
                            </h4>

                            <p>

                                Premium long bamboo for construction, scaffolding and industrial use.

                            </p>

                            <ul class="list-unstyled">

                                <li><i class="fa fa-check text-success me-2"></i>10–30 Feet</li>
                                <li><i class="fa fa-check text-success me-2"></i>Bulk Supply</li>
                                <li><i class="fa fa-check text-success me-2"></i>Custom Sizes</li>

                            </ul>

                            <a href="#enquiry" class="btn btn-success rounded-pill px-4">

                            Send Enquiry

                        </a>

                        </div>

                    </div>

                </div>


                <!-- Product -->

                <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay=".2s">

                    <div class="service-item rounded-4 overflow-hidden shadow-lg border-0 h-100">

                        <img src="img/bamboo2.png" class="img-fluid w-100" alt="Raw Bamboo">

                        <div class="p-4">

                            <h4>
                                Raw Bamboo Pieces
                            </h4>

                            <p>

                                Fresh raw bamboo supplied for industrial, commercial and handicraft purposes.

                            </p>

                            <ul class="list-unstyled">

                                <li><i class="fa fa-check text-success me-2"></i>Natural Finish</li>
                                <li><i class="fa fa-check text-success me-2"></i>Bulk Orders</li>
                                <li><i class="fa fa-check text-success me-2"></i>Quality Checked</li>

                            </ul>

                            <a href="#enquiry" class="btn btn-success rounded-pill px-4">

                            Send Enquiry

                        </a>

                        </div>

                    </div>

                </div>


                <!-- Product -->

                <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay=".4s">

                    <div class="service-item rounded-4 overflow-hidden shadow-lg border-0 h-100">

                        <img src="img/bamboo3.png" class="img-fluid w-100" alt="Craft Bamboo">

                        <div class="p-4">

                            <h4>

                                Bamboo For Handicrafts

                            </h4>

                            <p>

                                Premium bamboo for furniture, handicrafts, décor and artisan products.

                            </p>

                            <ul class="list-unstyled">

                                <li><i class="fa fa-check text-success me-2"></i>Furniture Grade</li>
                                <li><i class="fa fa-check text-success me-2"></i>Craft Quality</li>
                                <li><i class="fa fa-check text-success me-2"></i>Export Ready</li>

                            </ul>

                            <a href="#enquiry" class="btn btn-success rounded-pill px-4">

                            Send Enquiry

                        </a>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- BAMBOO PRODUCTS END -->

    <!-- =========================================================
     PRODUCT CATALOG START
========================================================= -->
    <div class="container-xxl py-5 bg-light">
        <div class="container">

            <div class="text-center wow fadeInUp" data-wow-delay="0.1s">
                <h6 class="text-success text-uppercase">Our Product Range</h6>
                <h1 class="mb-4">Bulk Bamboo Products We Supply</h1>
                <p class="mx-auto" style="max-width:850px;">
                    We supply premium quality bamboo products in bulk quantities for construction companies, furniture manufacturers, handicraft industries, paper mills, exporters and wholesale buyers across India.
                </p>
            </div>

            <div class="row g-4">

                <!-- Product -->
                <div class="col-lg-3 col-md-6 wow fadeInUp">
                    <div class="service-item p-4 rounded-4 shadow-sm h-100">

                        <img src="img/products/bamboo-01.png" class="img-fluid rounded-3 mb-4" alt="Raw Long Bamboo">

                        <h5>Raw Long Bamboo</h5>

                        <p>
                            High quality naturally grown bamboo available in different diameters and lengths.
                        </p>

                        <span class="badge bg-success mb-3">
                        Construction Grade
                    </span>

                        <a href="#enquiry" class="btn btn-success rounded-pill w-100">
                        I'm Interested
                    </a>

                    </div>
                </div>

                <!-- Product -->
                <div class="col-lg-3 col-md-6 wow fadeInUp" data-wow-delay=".2s">
                    <div class="service-item p-4 rounded-4 shadow-sm h-100">

                        <img src="img/products/bamboo-02.png" class="img-fluid rounded-3 mb-4" alt="Bamboo Pieces">

                        <h5>Bamboo Pieces</h5>

                        <p>
                            Premium cut bamboo pieces suitable for handicrafts and industrial processing.
                        </p>

                        <span class="badge bg-primary mb-3">
                        Bulk Supply
                    </span>

                        <a href="#enquiry" class="btn btn-success rounded-pill w-100">
                        I'm Interested
                    </a>

                    </div>
                </div>

                <!-- Product -->
                <div class="col-lg-3 col-md-6 wow fadeInUp" data-wow-delay=".4s">
                    <div class="service-item p-4 rounded-4 shadow-sm h-100">

                        <img src="img/products/bamboo-03.png" class="img-fluid rounded-3 mb-4" alt="Bamboo Sticks">

                        <h5>Bamboo Sticks</h5>

                        <p>
                            Strong and durable bamboo sticks used in agriculture, fencing and decoration.
                        </p>

                        <span class="badge bg-warning text-dark mb-3">
                        Premium Quality
                    </span>

                        <a href="#enquiry" class="btn btn-success rounded-pill w-100">
                        I'm Interested
                    </a>

                    </div>
                </div>

                <!-- Product -->
                <div class="col-lg-3 col-md-6 wow fadeInUp" data-wow-delay=".6s">
                    <div class="service-item p-4 rounded-4 shadow-sm h-100">

                        <img src="img/products/bamboo-04.png" class="img-fluid rounded-3 mb-4" alt="Bamboo Poles">

                        <h5>Bamboo Poles</h5>

                        <p>
                            Industrial grade bamboo poles available for large commercial projects.
                        </p>

                        <span class="badge bg-dark mb-3">
                        Export Quality
                    </span>

                        <a href="#enquiry" class="btn btn-success rounded-pill w-100">
                        I'm Interested
                    </a>

                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- PRODUCT CATALOG END -->


    <!-- =========================================================
     WHY BUY FROM US
========================================================= -->

    <div class="container-fluid py-5 overflow-hidden">

        <div class="container">

            <div class="row align-items-center g-5">

                <div class="col-lg-6 wow fadeInLeft">

                    <img src="img/biome-bamboo-factory.png" class="img-fluid rounded-4 shadow-lg" alt="Bamboo Factory">

                </div>

                <div class="col-lg-6 wow fadeInRight">

                    <h6 class="text-success text-uppercase">
                        Why Choose Us
                    </h6>

                    <h1 class="mb-5">
                        Reliable Bamboo Supplier Across India
                    </h1>

                    <div class="d-flex mb-4">

                        <i class="fa fa-check-circle fa-3x text-success me-4"></i>

                        <div>

                            <h5>Premium Quality Bamboo</h5>

                            <p class="mb-0">
                                Carefully selected bamboo from trusted forests across North-East India.
                            </p>

                        </div>

                    </div>

                    <div class="d-flex mb-4">

                        <i class="fa fa-truck fa-3x text-success me-4"></i>

                        <div>

                            <h5>Fast Pan India Delivery</h5>

                            <p class="mb-0">
                                Secure transportation with timely delivery to any state in India.
                            </p>

                        </div>

                    </div>

                    <div class="d-flex mb-4">

                        <i class="fa fa-industry fa-3x text-success me-4"></i>

                        <div>

                            <h5>Bulk Industrial Orders</h5>

                            <p class="mb-0">
                                Wholesale quantities available for manufacturers and exporters.
                            </p>

                        </div>

                    </div>

                    <div class="d-flex">

                        <i class="fa fa-headset fa-3x text-success me-4"></i>

                        <div>

                            <h5>Dedicated Support</h5>

                            <p class="mb-0">
                                Our sales team assists you in selecting the right bamboo products.
                            </p>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- WHY BUY FROM US END -->


    <!-- =========================================================
     BAMBOO APPLICATIONS
========================================================= -->

    <div class="container-xxl py-5 bg-light">

        <div class="container">

            <div class="text-center mb-5 wow fadeInUp">

                <h6 class="text-success text-uppercase">
                    Applications
                </h6>

                <h1>
                    Industries We Serve
                </h1>

            </div>

            <div class="row g-4">

                <div class="col-lg-2 col-md-4 col-6">
                    <div class="text-center p-4 rounded-4 shadow-sm bg-white h-100">
                        <i class="fa fa-home fa-3x text-success mb-3"></i>
                        <h6>Construction</h6>
                    </div>
                </div>

                <div class="col-lg-2 col-md-4 col-6">
                    <div class="text-center p-4 rounded-4 shadow-sm bg-white h-100">
                        <i class="fa fa-couch fa-3x text-success mb-3"></i>
                        <h6>Furniture</h6>
                    </div>
                </div>

                <div class="col-lg-2 col-md-4 col-6">
                    <div class="text-center p-4 rounded-4 shadow-sm bg-white h-100">
                        <i class="fa fa-paint-brush fa-3x text-success mb-3"></i>
                        <h6>Handicrafts</h6>
                    </div>
                </div>

                <div class="col-lg-2 col-md-4 col-6">
                    <div class="text-center p-4 rounded-4 shadow-sm bg-white h-100">
                        <i class="fa fa-box fa-3x text-success mb-3"></i>
                        <h6>Packaging</h6>
                    </div>
                </div>

                <div class="col-lg-2 col-md-4 col-6">
                    <div class="text-center p-4 rounded-4 shadow-sm bg-white h-100">
                        <i class="fa fa-tree fa-3x text-success mb-3"></i>
                        <h6>Landscaping</h6>
                    </div>
                </div>

                <div class="col-lg-2 col-md-4 col-6">
                    <div class="text-center p-4 rounded-4 shadow-sm bg-white h-100">
                        <i class="fa fa-globe fa-3x text-success mb-3"></i>
                        <h6>Export</h6>
                    </div>
                </div>

            </div>

        </div>

    </div>

    <!-- BAMBOO APPLICATIONS END -->

    <!-- ========================================================= -->
    <!-- ENQUIRY FORM START -->
    <!-- ========================================================= -->

    <div id="enquiry" class="container-fluid py-5 bg-success bg-gradient position-relative overflow-hidden">
        <div class="container py-4">

            <div class="row align-items-center g-5">

                <!-- Left Content -->
                <div class="col-lg-5 wow fadeInLeft">

                    <h6 class="text-uppercase text-warning fw-bold">
                        Get a Free Quote
                    </h6>

                    <h1 class="display-5 text-white mb-4">
                        Interested in Our Bamboo Products?
                    </h1>

                    <p class="text-light mb-4">
                        Fill out the enquiry form and our sales team will contact you with pricing, availability, transportation details and the best quotation for your selected bamboo products.
                    </p>

                    <div class="mb-4 d-flex">
                        <i class="fa fa-check-circle fa-2x text-warning me-3"></i>
                        <div>
                            <h5 class="text-white mb-1">
                                Premium Quality Bamboo
                            </h5>
                            <p class="text-light mb-0">
                                Directly sourced from North-East India.
                            </p>
                        </div>
                    </div>

                    <div class="mb-4 d-flex">
                        <i class="fa fa-truck fa-2x text-warning me-3"></i>
                        <div>
                            <h5 class="text-white mb-1">
                                Pan India Delivery
                            </h5>
                            <p class="text-light mb-0">
                                Safe transportation for bulk orders.
                            </p>
                        </div>
                    </div>

                    <div class="mb-4 d-flex">
                        <i class="fa fa-phone fa-2x text-warning me-3"></i>
                        <div>
                            <h5 class="text-white mb-1">
                                Quick Response
                            </h5>
                            <p class="text-light mb-0">
                                We usually respond within 24 hours.
                            </p>
                        </div>
                    </div>

                </div>

                <!-- Form -->
                <div class="col-lg-7 wow fadeInRight">

                    <div class="bg-white rounded-4 shadow-lg p-5">

                        <h3 class="mb-4">
                            Product Enquiry Form
                        </h3>

                        <?php if ($bambooFormSuccess): ?>
                            <div class="alert alert-success">Thank you! Your enquiry has been received. Our sales team will contact you shortly.</div>
                        <?php endif; ?>

                        <?php if ($bambooFormErrors): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    <?php foreach ($bambooFormErrors as $err): ?>
                                        <li><?= bf_e($err) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <form method="post" action="" id="bambooEnquiryForm" novalidate>
                            <input type="hidden" name="csrf_token" value="<?= bf_e($bambooCsrfToken) ?>">
                            <input type="hidden" name="bamboo_form_submit" value="1">

                            <div class="row g-4">

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Full Name</label>
                                    <input type="text" name="full_name" class="form-control py-3" placeholder="Enter your full name" maxlength="150" required value="<?= bf_e($bambooFormValues['full_name']) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Mobile Number</label>
                                    <input type="tel" name="mobile_number" class="form-control py-3" placeholder="+91 XXXXX XXXXX" maxlength="20" required value="<?= bf_e($bambooFormValues['mobile_number']) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Email Address</label>
                                    <input type="email" name="email" class="form-control py-3" placeholder="Enter email" maxlength="150" value="<?= bf_e($bambooFormValues['email']) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Company Name</label>
                                    <input type="text" name="company_name" class="form-control py-3" placeholder="Company / Business" maxlength="150" value="<?= bf_e($bambooFormValues['company_name']) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">State</label>
                                    <input type="text" name="state" class="form-control py-3" placeholder="State" maxlength="100" value="<?= bf_e($bambooFormValues['state']) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">City</label>
                                    <input type="text" name="city" class="form-control py-3" placeholder="City" maxlength="100" value="<?= bf_e($bambooFormValues['city']) ?>">
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold">Select Products</label>
                                    <select class="form-select py-3" name="products[]" multiple size="8" required>
                                        <?php foreach ($bambooAllowedProducts as $product): ?>
                                            <option value="<?= bf_e($product) ?>" <?= in_array($product, $bambooFormValues['products'], true) ? 'selected' : '' ?>>
                                                <?= bf_e($product) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Hold Ctrl (Windows) or Command (Mac) to select multiple products. Tap on mobile to select one or more.</small>
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Quantity Required</label>
                                    <input type="text" name="quantity_required" class="form-control py-3" placeholder="Example: 500 Pieces" maxlength="100" value="<?= bf_e($bambooFormValues['quantity_required']) ?>">
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label fw-bold">Delivery Location</label>
                                    <input type="text" name="delivery_location" class="form-control py-3" placeholder="Delivery Address" maxlength="200" value="<?= bf_e($bambooFormValues['delivery_location']) ?>">
                                </div>

                                <div class="col-12">
                                    <label class="form-label fw-bold">Additional Requirements</label>
                                    <textarea name="message" id="bambooMessage" class="form-control" rows="5" maxlength="2000" placeholder="Write your requirement..."><?= bf_e($bambooFormValues['message']) ?></textarea>
                                    <span class="char-counter"><span id="bambooMessageCount">0</span>/2000</span>
                                </div>

                                <div class="col-12">
                                    <button class="btn btn-success btn-lg rounded-pill px-5 py-3 w-100" type="submit" id="bambooSubmitBtn">
                                        <span class="spinner-border" role="status" aria-hidden="true"></span>
                                        <i class="fa fa-paper-plane me-2"></i>
                                        Submit Product Enquiry
                                    </button>
                                </div>

                            </div>
                        </form>

                    </div>

                </div>

            </div>

        </div>
    </div>

    <!-- ========================================================= -->
    <!-- ENQUIRY FORM END -->
    <!-- ========================================================= -->


    <!-- ========================================================= -->
    <!-- CTA START -->
    <!-- ========================================================= -->

    <div class="container-fluid py-5">

        <div class="container">

            <div class="bg-dark rounded-4 shadow-lg p-5 text-center wow zoomIn">

                <h2 class="text-white mb-3">
                    Looking for Bulk Bamboo Supply?
                </h2>

                <p class="text-light mb-4">

                    We supply premium bamboo products to wholesalers, exporters, manufacturers, construction companies, furniture makers and government organizations across India.

                </p>

                <a href="#enquiry" class="btn btn-success btn-lg rounded-pill px-5 me-3">

                Request Quotation

            </a>

                <a href="tel:+919678431656" class="btn btn-outline-light btn-lg rounded-pill px-5">

                    <i class="fa fa-phone me-2"></i> Call Now

                </a>

            </div>

        </div>

    </div>

    <!-- ========================================================= -->
    <!-- CTA END -->
    <!-- ========================================================= -->

    <!-- Footer  -->
    <!-- <div id="footer"></div> -->
    <?php include __DIR__ . '/footer.php'; ?>
    <!-- Footer end -->
 

    <script>
        // Navbar scroll effect

        window.addEventListener("scroll", function() {

            const nav = document.querySelector(".navbar");

            if (window.scrollY > 50) {

                nav.classList.add("scrolled");

            } else {

                nav.classList.remove("scrolled");

            }

        });

        // Active Menu

        const current = window.location.pathname.split("/").pop();

        document.querySelectorAll(".navbar-nav a").forEach(link => {

            const href = link.getAttribute("href");

            if (href === current) {

                link.classList.add("active");

            }

        });





        $('.testimonial-carousel').owlCarousel({

            loop: true,

            margin: 30,

            center: true,

            autoplay: true,

            autoplayTimeout: 3500,

            autoplayHoverPause: true,

            smartSpeed: 1200,

            dots: true,

            nav: false,

            responsive: {

                0: {
                    items: 1
                },

                768: {
                    items: 2
                },

                1200: {
                    items: 3
                }

            }

        });


        $(".testimonial-item").hover(function() {

            $(this).css({
                transform: "translateY(-15px) rotateX(5deg)"
            });

        }, function() {

            $(this).css({
                transform: "translateY(0)"
            });

        });



        // fetch("navbar.php")
        //     .then(response => response.text())
        //     .then(data => {
        //         document.getElementById("navbar").innerHTML = data;

        //         // Reinitialize Bootstrap dropdowns
        //         document.querySelectorAll('.dropdown-toggle').forEach(function(el) {
        //             new bootstrap.Dropdown(el);
        //         });
        //     });

        // // Footer

        // fetch("footer.php")

        // .then(response => response.text())

        // .then(data => {

        //     document.getElementById("footer").innerHTML = data;

        // });
    </script>


    <script>
        /* ===================================================
                                                                                           BIOME BAMBOO PAGE INTERACTIONS
                                                                                        =================================================== */

        document.addEventListener("DOMContentLoaded", function() {

            /* Spotlight hover (desktop/mouse only — skip on touch devices) */

            const isTouchDevice = window.matchMedia("(hover: none), (pointer: coarse)").matches;

            if (!isTouchDevice) {
                document.querySelectorAll(".service-item,.product-feature,.bg-white.rounded-4").forEach(card => {

                    card.addEventListener("mousemove", function(e) {

                        const rect = this.getBoundingClientRect();

                        const x = e.clientX - rect.left;

                        const y = e.clientY - rect.top;

                        this.style.background =
                            `radial-gradient(circle at ${x}px ${y}px,
rgba(25,135,84,.08),
#ffffff 65%)`;

                    });

                    card.addEventListener("mouseleave", function() {

                        this.style.background = "#fff";

                    });

                });
            }

            /* Smooth scroll */

            document.querySelectorAll('a[href^="#"]').forEach(anchor => {

                anchor.addEventListener("click", function(e) {

                    const target = document.querySelector(this.getAttribute("href"));

                    if (target) {

                        e.preventDefault();

                        target.scrollIntoView({

                            behavior: "smooth"

                        });

                    }

                });

            });

            /* Button Loading */

            const form = document.querySelector("#enquiry form");



            /* Counter Animation */

            document.querySelectorAll(".position-absolute h2").forEach(counter => {

                let target = parseInt(counter.innerText);

                let value = 0;

                let speed = 25;

                let run = setInterval(() => {

                    value++;

                    counter.innerText = value + "+";

                    if (value >= target) {

                        clearInterval(run);

                    }

                }, speed);

            });

            /* Reveal Effect */

            const observer = new IntersectionObserver(entries => {

                entries.forEach(entry => {

                    if (entry.isIntersecting) {

                        entry.target.style.opacity = "1";

                        entry.target.style.transform = "translateY(0)";

                    }

                });

            }, {
                threshold: .2
            });

            document.querySelectorAll(".service-item,.product-feature,.bg-dark.rounded-4").forEach(el => {

                el.style.opacity = "0";

                el.style.transform = "translateY(40px)";

                el.style.transition = ".7s";

                observer.observe(el);

            });

            /* Header Parallax */

            const hero = document.querySelector(".bamboo-header");

            window.addEventListener("scroll", () => {

                if (hero) {

                    hero.style.backgroundPositionY = (window.scrollY * 0.35) + "px";

                }

            });

        });
    </script>

    <!-- ===================================================
         NEW: Enquiry form interactivity (live counter,
         basic client-side validation feedback, submit
         loading state). Purely additive — server-side
         validation/logic in PHP above is unchanged.
    ==================================================== -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {

            const bambooForm = document.getElementById("bambooEnquiryForm");
            if (!bambooForm) return;

            /* Live character counter for the message textarea */
            const msgField = document.getElementById("bambooMessage");
            const msgCount = document.getElementById("bambooMessageCount");

            function updateMsgCount() {
                if (msgField && msgCount) {
                    msgCount.textContent = msgField.value.length;
                }
            }
            if (msgField) {
                updateMsgCount();
                msgField.addEventListener("input", updateMsgCount);
            }

            /* Simple inline validation highlighting */
            function markField(field, valid) {
                field.classList.toggle("is-invalid-bamboo", !valid);
            }

            const requiredText = bambooForm.querySelectorAll('input[required]');
            requiredText.forEach(field => {
                field.addEventListener("blur", function() {
                    markField(field, field.value.trim().length > 0);
                });
                field.addEventListener("input", function() {
                    if (field.value.trim().length > 0) {
                        markField(field, true);
                    }
                });
            });

            const mobileField = bambooForm.querySelector('input[name="mobile_number"]');
            if (mobileField) {
                mobileField.addEventListener("blur", function() {
                    const pattern = /^[0-9+\-\s()]{7,20}$/;
                    markField(mobileField, pattern.test(mobileField.value.trim()));
                });
            }

            const emailField = bambooForm.querySelector('input[name="email"]');
            if (emailField) {
                emailField.addEventListener("blur", function() {
                    if (emailField.value.trim() === "") {
                        markField(emailField, true);
                        return;
                    }
                    const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    markField(emailField, pattern.test(emailField.value.trim()));
                });
            }

            const productsSelect = bambooForm.querySelector('select[name="products[]"]');
            if (productsSelect) {
                function resizeProductSelect() {
                    productsSelect.setAttribute('size', window.innerWidth < 576 ? '6' : '8');
                }
                resizeProductSelect();
                window.addEventListener('resize', resizeProductSelect);

                productsSelect.addEventListener("change", function() {
                    markField(productsSelect, productsSelect.selectedOptions.length > 0);
                });
            }

            /* Submit button loading state (server still drives the real submit/redirect) */
            const submitBtn = document.getElementById("bambooSubmitBtn");
            bambooForm.addEventListener("submit", function(e) {
                let valid = true;

                requiredText.forEach(field => {
                    const ok = field.value.trim().length > 0;
                    markField(field, ok);
                    if (!ok) valid = false;
                });

                if (productsSelect && productsSelect.selectedOptions.length === 0) {
                    markField(productsSelect, false);
                    valid = false;
                }

                if (mobileField) {
                    const pattern = /^[0-9+\-\s()]{7,20}$/;
                    const ok = pattern.test(mobileField.value.trim());
                    markField(mobileField, ok);
                    if (!ok) valid = false;
                }

                if (!valid) {
                    e.preventDefault();
                    const firstInvalid = bambooForm.querySelector(".is-invalid-bamboo");
                    if (firstInvalid) {
                        firstInvalid.scrollIntoView({
                            behavior: "smooth",
                            block: "center"
                        });
                        firstInvalid.focus({
                            preventScroll: true
                        });
                    }
                    return;
                }

                if (submitBtn) {
                    submitBtn.classList.add("is-loading");
                }
            });
        });
    </script>

</body>

</html>