<?php


declare(strict_types=1);


require_once __DIR__ . '/admin/config/database.php';


if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['quote_csrf_token'])) {
    $_SESSION['quote_csrf_token'] = bin2hex(random_bytes(32));
}
$quoteCsrfToken = $_SESSION['quote_csrf_token'];

$quoteFormErrors = [];
$quoteFormSuccess = false;
if (!empty($_SESSION['quote_flash_success'])) {
    $quoteFormSuccess = true;
    unset($_SESSION['quote_flash_success']);
}

// Keep submitted values so the form can be re-filled if validation fails.
$quoteFormValues = [
    'full_name'         => '',
    'mobile_number'     => '',
    'email'             => '',
    'city_state'        => '',
    'service_required'  => '',
    'company_name'       => '',
    'message'            => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quote_form_submit'])) {

    // ---- CSRF check ----
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['quote_csrf_token'], $postedToken)) {
        $quoteFormErrors[] = 'Your session expired. Please refresh the page and try again.';
    } else {

        // ---- Collect + sanitize input ----
        $fullName  = trim((string) ($_POST['full_name'] ?? ''));
        $mobile    = trim((string) ($_POST['mobile_number'] ?? ''));
        $email     = trim((string) ($_POST['email'] ?? ''));
        $cityState = trim((string) ($_POST['city_state'] ?? ''));
        $service   = trim((string) ($_POST['service_required'] ?? ''));
        $company   = trim((string) ($_POST['company_name'] ?? ''));
        $message   = trim((string) ($_POST['message'] ?? ''));

        $quoteFormValues = compact(
            'fullName', 'mobile', 'email', 'cityState', 'service', 'company', 'message'
        );
        // also keep snake_case keys for the HTML below
        $quoteFormValues = [
            'full_name'        => $fullName,
            'mobile_number'    => $mobile,
            'email'            => $email,
            'city_state'       => $cityState,
            'service_required' => $service,
            'company_name'     => $company,
            'message'          => $message,
        ];

        // ---- Validation ----
        if ($fullName === '' || mb_strlen($fullName) > 150) {
            $quoteFormErrors[] = 'Full name is required (max 150 characters).';
        }

        // Accepts digits, spaces, +, -, ( ) — 7 to 20 chars total
        if ($mobile === '' || !preg_match('/^[0-9+\-\s()]{7,20}$/', $mobile)) {
            $quoteFormErrors[] = 'Please enter a valid mobile number.';
        }

        if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150)) {
            $quoteFormErrors[] = 'Please enter a valid email address.';
        }

        if (mb_strlen($cityState) > 150) {
            $quoteFormErrors[] = 'City/State is too long.';
        }

        $allowedServices = [
            'Transportation & Logistics', 'Bamboo Trading', 'Legal & Compliance',
            'GST Registration', 'FSSAI Registration', 'MSME Registration',
            'Company Registration', 'Accounting & Taxation', 'Cab Rental',
        ];
        if ($service !== '' && !in_array($service, $allowedServices, true)) {
            $quoteFormErrors[] = 'Please select a valid service from the list.';
        }

        if (mb_strlen($company) > 150) {
            $quoteFormErrors[] = 'Company/Business name is too long.';
        }

        if (mb_strlen($message) > 2000) {
            $quoteFormErrors[] = 'Message is too long (max 2000 characters).';
        }

        // ---- Basic spam throttle: max 3 submissions per 10 minutes per session ----
        $now = time();
        $bucket = $_SESSION['quote_rate_limit'] ?? ['count' => 0, 'start' => $now];
        if ($now - $bucket['start'] > 600) {
            $bucket = ['count' => 0, 'start' => $now];
        }
        $bucket['count']++;
        $_SESSION['quote_rate_limit'] = $bucket;
        if ($bucket['count'] > 3) {
            $quoteFormErrors[] = 'Too many submissions. Please wait a few minutes and try again.';
        }

        // ---- Insert into DB if everything is valid ----
        if (!$quoteFormErrors) {
            try {
                $pdo = get_db();
                $stmt = $pdo->prepare(
                    'INSERT INTO quote_requests
                        (full_name, mobile_number, email, city_state, service_required, company_name, message, ip_address)
                     VALUES
                        (:full_name, :mobile_number, :email, :city_state, :service_required, :company_name, :message, :ip)'
                );
                $stmt->execute([
                    ':full_name'        => $fullName,
                    ':mobile_number'    => $mobile,
                    ':email'            => $email !== '' ? $email : null,
                    ':city_state'       => $cityState !== '' ? $cityState : null,
                    ':service_required' => $service !== '' ? $service : null,
                    ':company_name'     => $company !== '' ? $company : null,
                    ':message'          => $message !== '' ? $message : null,
                    ':ip'               => $_SERVER['REMOTE_ADDR'] ?? null,
                ]);

                // the page never resubmits the form.
                $_SESSION['quote_flash_success'] = true;
                $_SESSION['quote_csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;

            } catch (PDOException $e) {
                error_log('Quote form insert failed: ' . $e->getMessage());
                $quoteFormErrors[] = 'Something went wrong on our end. Please try again later.';
            }
        }
    }
}

/** Small escaping helper for use in the HTML below. */
function qf_e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>

<?php if ($quoteFormSuccess): ?>
    <div class="alert alert-success">
        Thank you! Your request has been received. Our team will contact you shortly.
    </div>
<?php endif; ?>

<?php if ($quoteFormErrors): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($quoteFormErrors as $err): ?>
                <li><?= qf_e($err) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Biome Enterprises | Reliable Transportation, Bamboo Trading, Legal & Compliance Services</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta name="keywords" content="Biome Enterprises, transportation, logistics, bamboo trading, legal services, compliance, accounting, hospitality, cab booking, North-East India, Assam">
    <meta name="description" content="Biome Enterprises provides reliable transportation, bamboo trading, legal & compliance services, accounting, hospitality, and cab booking solutions across North-East India.">
    <!-- Canonical URL -->
    <link rel="canonical" href="https://biomeenterprises.com/">

    <!-- Open Graph (social share / rich preview) -->
    <meta property="og:type" content="website">
    <meta property="og:title" content="Biome Enterprises | Logistics, Bamboo Trading & Compliance Services">
    <meta property="og:description" content="Reliable transportation, bamboo trading, legal & compliance services, accounting, hospitality, and cab booking across North-East India.">
    <meta property="og:image" content="https://biomeenterprises.com/img/logo-og.png">
    <meta property="og:url" content="https://biomeenterprises.com/">
    <meta property="og:site_name" content="Biome Enterprises">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="Biome Enterprises">
    <meta name="twitter:description" content="Reliable transportation, bamboo trading, legal & compliance services across North-East India.">
    <meta name="twitter:image" content="https://biomeenterprises.com/img/logo-og.png">

    <!-- Organization structured data — main signal Google uses for the search-result logo -->
    <script type="application/ld+json">
    {
    "@context": "https://schema.org",
    "@type": "Organization",
    "name": "Biome Enterprises",
    "url": "https://biomeenterprises.com",
    "logo": "https://biomeenterprises.com/img/logo.png",
    "description": "Transportation, bamboo trading, legal & compliance, accounting, hospitality, and cab booking solutions across North-East India.",
    "telephone": "+91-96784-31656",
    "address": {
        "@type": "PostalAddress",
        "addressRegion": "Assam",
        "addressCountry": "IN"
    },
    "sameAs": []
    }
    </script>
    <!-- Favicon -->
    <link rel="icon" href="img/favicon.ico" type="image/x-icon">
    
    <!-- Favicons -->
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="img/apple-touch-icon.png">

    <!-- Google Web Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Roboto:wght@500;700&display=swap" rel="stylesheet">

    <!-- Icon Font Stylesheet -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Libraries Stylesheet -->
    <link href="lib/animate/animate.min.css" rel="stylesheet">

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

        html {
            scroll-behavior: smooth;
            overflow-x: hidden;
            width: 100%;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f7f9fc;
            overflow-x: hidden;
            width: 100%;
            position: relative;
        }

        /* Sticky navbar: pin it to its own compositor layer so scroll-driven
           class/style changes (background, blur, padding) never trigger a
           layout shift of the bar itself — this is what fixes the
           left-right "jump" while scrolling on phones. */
        .navbar.sticky-top {
            transform: translateZ(0);
            -webkit-transform: translateZ(0);
            backface-visibility: hidden;
            will-change: background, box-shadow;
            left: 0;
            right: 0;
            width: 100%;
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

        /* ---- Hero Carousel: premium look ---- */
        #heroCarousel { position: relative; overflow: hidden; }
        #heroCarousel .carousel-item img {
            width: 100%;
            height: 100vh;
            min-height: 560px;
            max-height: 780px;
            object-fit: cover;
            filter: brightness(.7) saturate(1.1);
            transform: scale(1.05);
            transition: transform 8s ease;
        }
        #heroCarousel .carousel-item.active img { transform: scale(1); }
        #heroCarousel::after {
            content: "";
            position: absolute; inset: 0;
            background: linear-gradient(180deg, rgba(32, 30, 11, 0.55) 0%, rgba(162, 151, 56, 0.25) 45%, rgba(11,18,32,.85) 100%);
            pointer-events: none;
        }
        #heroCarousel .carousel-caption {
            top: 0; bottom: 0; z-index: 5;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: flex-start;
            text-align: left;
            background: none;
        }
        #heroCarousel .carousel-caption h5 {
            letter-spacing: 3px;
            font-weight: 600;
            display: inline-block;
            padding: .35rem 1rem;
            border: 1px solid rgba(255,255,255,.4);
            border-radius: 50px;
            backdrop-filter: blur(6px);
            background: rgba(255,255,255,.08);
        }
        #heroCarousel .carousel-caption h1 {
            font-weight: 800;
            text-shadow: 0 4px 30px rgba(0,0,0,.4);
        }
        #heroCarousel .btn-lg {
            border-radius: 50px;
            padding: .85rem 2.2rem;
            font-weight: 600;
            letter-spacing: .5px;
            box-shadow: var(--be-shadow-soft);
            transition: var(--be-transition);
        }
        #heroCarousel .btn-lg:hover {
            transform: translateY(-4px);
            box-shadow: var(--be-shadow-strong);
        }
        #heroCarousel .carousel-indicators button {
            width: 40px; height: 4px; border-radius: 2px;
        }
        @media (max-width: 768px) {
            #heroCarousel .carousel-item img { height: 80vh; min-height: 460px; }
            #heroCarousel .carousel-caption h1 { font-size: 2rem; }
        }

        /* ---- Glass / premium cards ---- */
        .card, .counter-box {
            border-radius: var(--be-radius) !important;
            transition: var(--be-transition);
        }
        .card:hover {
            transform: translateY(-8px);
            box-shadow: var(--be-shadow-strong) !important;
        }
        .card { overflow: hidden; }
        .card .card-img-top { border-radius: .65rem; overflow: hidden; }
        .card img { transition: transform .6s ease; }
        .card:hover img { transform: scale(1.08); }

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
        }
        .btn-primary:hover, .btn-outline-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(255, 193, 7, 0.35);
        }
        .btn-success:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 24px rgba(25, 135, 84, 0.35);
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

        /* ---- Counter boxes ---- */
        .counter-box {
            position: relative;
            overflow: hidden;
            box-shadow: var(--be-shadow-soft);
        }
        .counter-box::before {
            content: "";
            position: absolute; inset: 0;
            background: radial-gradient(circle at top right, rgba(255,255,255,.18), transparent 60%);
        }
        .counter-box .counter-number { font-size: 2.5rem; line-height: 1; font-weight: 800; }
        .counter-box:hover { transform: translateY(-8px) scale(1.02); }

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

        /* ---- Quote / form card ---- */
        form .form-control, form .form-select {
            border-radius: .65rem;
            border: 1px solid #e3e8f0;
            transition: var(--be-transition);
        }
        form .form-control:focus, form .form-select:focus {
            border-color: var(--be-primary);
            box-shadow: 0 0 0 .25rem rgba(255, 193, 7, 0.18);
            transform: translateY(-2px);
        }

        /* ---- Team cards ---- */
        .card img.card-img-top { aspect-ratio: 1/1; object-fit: cover; }

        /* ---- Testimonial carousel premium ---- */
        #testimonialCarousel .card {
            background: #fff;
            box-shadow: var(--be-shadow-soft);
        }
        #testimonialCarousel .carousel-control-prev,
        #testimonialCarousel .carousel-control-next {
            position: static;
            display: inline-flex;
            width: auto;
            opacity: 1;
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

        /* ---- Back to top ---- */
        .back-to-top {
            border-radius: 50% !important;
            width: 50px; height: 50px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: var(--be-shadow-strong);
        }
        .back-to-top {

                position: fixed !important;

                right: 24px !important;

                bottom: 20px !important;

                left: auto !important;

                z-index: 1501;

            }

        /* ---- Navbar premium ---- */
        .navbar.be-scrolled {
            box-shadow: 0 6px 20px rgba(0,0,0,.08) !important;
        }

        /* ---- Mobile polish ---- */
        @media (max-width: 576px) {
            h1.display-4 { font-size: 1.8rem; }
            .container { padding-left: 1.1rem; padding-right: 1.1rem; }
            .counter-box .counter-number { font-size: 1.8rem; }
        }

        /* ===================== TOP-TIER PREMIUM LAYER ===================== */

        /* Fluid typography across all breakpoints */
        h1 { font-size: clamp(1.7rem, 4vw + .5rem, 3.2rem); }
        h2 { font-size: clamp(1.4rem, 2.6vw + .5rem, 2.2rem); }
        .display-4 { font-size: clamp(1.8rem, 5vw + .5rem, 3.6rem) !important; }
        p { font-size: clamp(.92rem, .4vw + .8rem, 1.05rem); }

        /* Gradient accent text for emphasis */
        .text-primary {
            background: linear-gradient(135deg, var(--be-primary), var(--be-success));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        /* Keep solid color where gradient text would hurt legibility (small UI bits) */
        .btn .text-primary, .badge.text-primary { -webkit-text-fill-color: initial; background: none; }

        /* Glassmorphism navbar once scrolled */
        .navbar {
            transition: background .4s ease, box-shadow .4s ease, padding .4s ease;
        }
        .navbar.be-scrolled {
            background: rgba(255,255,255,.78) !important;
            backdrop-filter: blur(14px) saturate(160%);
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            padding-top: .4rem !important;
            padding-bottom: .4rem !important;
        }

        /* Staggered reveal — children fade up in sequence */
        .reveal-stagger > * {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity .7s ease, transform .7s ease;
        }
        .reveal-stagger.is-visible > * { opacity: 1; transform: translateY(0); }
        .reveal-stagger.is-visible > *:nth-child(1) { transition-delay: .05s; }
        .reveal-stagger.is-visible > *:nth-child(2) { transition-delay: .15s; }
        .reveal-stagger.is-visible > *:nth-child(3) { transition-delay: .25s; }
        .reveal-stagger.is-visible > *:nth-child(4) { transition-delay: .35s; }
        .reveal-stagger.is-visible > *:nth-child(5) { transition-delay: .45s; }
        .reveal-stagger.is-visible > *:nth-child(6) { transition-delay: .55s; }

        /* Tilt-ready cards (JS adds the transform) */
        .tilt-card { transform-style: preserve-3d; will-change: transform; }

        /* Shimmer skeleton sweep on counter boxes (one-time on load) */
        .counter-box { position: relative; }
        .counter-box::after {
            content: "";
            position: absolute; top: 0; left: -150%;
            width: 60%; height: 100%;
            background: linear-gradient(120deg, transparent, rgba(255,255,255,.35), transparent);
            transform: skewX(-20deg);
            animation: be-shimmer 3.2s ease-in-out infinite;
        }
        @keyframes be-shimmer {
            0% { left: -150%; }
            50% { left: 150%; }
            100% { left: 150%; }
        }

        /* Animated underline links (footer/social/nav anchors inside content cards) */
        .card a:not(.btn) {
            position: relative;
            text-decoration: none;
        }
        .card a:not(.btn)::after {
            content: "";
            position: absolute; left: 0; bottom: -2px;
            width: 0; height: 2px;
            background: var(--be-primary);
            transition: width .3s ease;
        }
        .card a:not(.btn):hover::after { width: 100%; }

        /* Floating soft blobs behind hero for depth (decorative, css-only) */
        .be-blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: .35;
            z-index: 1;
            pointer-events: none;
            animation: be-float 9s ease-in-out infinite;
        }
        @keyframes be-float {
            0%, 100% { transform: translateY(0) translateX(0); }
            50% { transform: translateY(-25px) translateX(15px); }
        }

        /* Custom cursor glow (desktop only) */
        #cursorGlow {
            position: fixed;
            width: 320px; height: 320px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(255,193,7,.14), transparent 70%);
            pointer-events: none;
            z-index: 1;
            transform: translate(-50%, -50%);
            transition: opacity .3s ease;
            opacity: 0;
        }
        @media (hover: hover) and (pointer: fine) {
            #cursorGlow { opacity: 1; }
        }

        /* Smoother section spacing rhythm on mobile */
        @media (max-width: 768px) {
            .py-5 { padding-top: 2.5rem !important; padding-bottom: 2.5rem !important; }
            .row.g-5 { --bs-gutter-y: 2rem; }
            .card { border-radius: .85rem !important; }
        }
        @media (max-width: 480px) {
            .counter-box { padding: 1.25rem !important; }
            .counter-box i { font-size: 1.8rem !important; }
            #heroCarousel .btn-lg { padding: .65rem 1.4rem; font-size: .85rem; }
        }

        /* Sticky mobile call bar */
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

        /* Selection color branding */
        ::selection { background: var(--be-primary); color: #271e01; }

        /* ===================== HOVER ACCENT FIXES (gold / orange / green, no blue) ===================== */

        .card:hover {
            box-shadow: 0 20px 45px rgba(191, 145, 7, 0.22) !important;
        }
        .tilt-card:hover {
            box-shadow: 0 22px 48px rgba(191, 145, 7, 0.26) !important;
        }
        .counter-box:hover {
            box-shadow: 0 18px 40px rgba(191, 145, 7, 0.3) !important;
        }
        .card a:not(.btn)::after {
            background: linear-gradient(90deg, var(--be-primary), var(--be-success));
        }
        .btn-outline-primary {
            color: var(--be-primary-dark);
            border-color: var(--be-primary);
        }
        .btn-outline-primary:hover {
            background: linear-gradient(135deg, var(--be-primary), var(--be-primary-dark));
            border-color: var(--be-primary);
            color: #271e01;
        }
        .btn-sm.btn-outline-primary:hover {
            background: var(--be-success);
            border-color: var(--be-success);
            color: #fff;
            transform: translateY(-3px) scale(1.05);
        }
        /* icon hover glow inside feature/about blocks */
        .fa-3x.text-primary, .fas.text-primary {
            transition: var(--be-transition);
        }
        .d-flex:hover > .fa-3x.text-primary,
        .d-flex:hover > .fas.text-primary {
            filter: drop-shadow(0 4px 14px rgba(255,193,7,.55));
            transform: scale(1.08) rotate(-3deg);
        }
        /* back to top hover */
        .back-to-top:hover {
            background: var(--be-success) !important;
            box-shadow: 0 14px 32px rgba(25,135,84,.4);
        }

        /* ===================== DEEPER MOBILE RESPONSIVENESS ===================== */

        @media (max-width: 991px) {
            #heroCarousel .carousel-caption { align-items: center; text-align: center; }
            #heroCarousel .carousel-caption .container .row { justify-content: center; }
            #heroCarousel .carousel-caption h5 { margin-left: auto; margin-right: auto; }
        }

        @media (max-width: 767px) {
            .display-4 { line-height: 1.25 !important; }
            #heroCarousel .btn-lg { display: block; width: 100%; margin: 0 0 .75rem 0 !important; }
            #heroCarousel .col-12.col-lg-8 a.btn-lg:last-child { margin-bottom: 0 !important; }
            .counter-box .counter-number { font-size: 1.6rem; }
            .counter-box i.fa-3x { font-size: 1.6rem !important; }
            .card-body { padding: .75rem !important; }
            h4.card-title { font-size: 1.05rem; }
            .whatsapp-float { width: 50px; height: 50px; font-size: 1.3rem; bottom: 76px; right: 16px; }
        }

        @media (max-width: 575px) {
            .row.g-5, .row.g-4 { row-gap: 1.25rem; }
            .col-6 .counter-box { padding: 1rem !important; }
            .navbar-brand img { max-height: 38px; }
            #testimonialCarousel .card { padding: 1.5rem !important; }
            #testimonialCarousel .fs-5 { font-size: .95rem !important; }
            .container { padding-left: 1rem; padding-right: 1rem; }
        }

        @media (max-width: 380px) {
            h1 { font-size: 1.5rem; }
            #heroCarousel .carousel-caption h1 { font-size: 1.5rem !important; }
            .btn-lg { padding: .65rem 1rem; font-size: .8rem; }
        }
    </style>
</head>


<body>

    <div id="scrollProgress"></div>
    <div id="cursorGlow"></div>

    <!-- Navbar -->
     <?php include __DIR__ . '/navbar.php'; ?>
    <!-- Navbar End -->

    <!-- ===================== Bootstrap Carousel Start ===================== -->
    <div id="heroCarousel" class="carousel slide mb-5" data-bs-ride="carousel" data-bs-interval="5000">

        <div class="be-blob" style="width:260px;height:260px;top:10%;left:-5%;background:#ffc107;"></div>
        <div class="be-blob" style="width:200px;height:200px;bottom:8%;right:-3%;background:#198754;animation-delay:2s;"></div>

        <div class="carousel-indicators">
            <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="0" class="active" aria-current="true" aria-label="Slide 1"></button>
            <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="1" aria-label="Slide 2"></button>
        </div>

        <div class="carousel-inner">

            <!-- Slide 1 -->
            <div class="carousel-item active">
                <img src="img/carousel-1.png" class="d-block w-100" alt="Transport and Logistics">
                <div class="carousel-caption">
                    <div class="container">
                        <div class="row">
                            <div class="col-12 col-lg-8">
                                <h5 class="text-white text-uppercase mb-3">Transport & Logistics Solution</h5>
                                <h1 class="display-4 text-white mb-4">
                                    NORTHEAST INDIA'S LEADING B2B
                                    <span class="text-primary">LOGISTICS</span> &
                                    <span class="text-primary">BAMBOO FIRM</span>
                                </h1>
                                <p class="fs-5 fw-medium text-white mb-4">
                                    Biome Enterprises delivers sophisticated supply chain solutions, industrial bamboo procurement, and corporate fleet oversight across all eight states.
                                </p>
                                <a href="" class="btn btn-success btn-lg me-3">BOOK A TRUCK</a>
                                <a href="" class="btn btn-light btn-lg">TRACK CONSOLE</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Slide 2 -->
            <div class="carousel-item">
                <img src="img/carousel-2.png" class="d-block w-100" alt="Bamboo Trading">
                <div class="carousel-caption">
                    <div class="container">
                        <div class="row">
                            <div class="col-12 col-lg-8">
                                <h5 class="text-white text-uppercase mb-3">Transport & Logistics Solution</h5>
                                <h1 class="display-4 text-white mb-4">
                                    NORTHEAST INDIA'S LEADING B2B
                                    <span class="text-primary">LOGISTICS</span> &
                                    <span class="text-primary">BAMBOO FIRM</span>
                                </h1>
                                <p class="fs-5 fw-medium text-white mb-4">
                                    Biome Enterprises delivers sophisticated supply chain solutions, industrial bamboo procurement, and corporate fleet oversight across all eight states.
                                </p>
                                <a href="" class="btn btn-primary btn-lg me-3">BOOK A TRUCK</a>
                                <a href="" class="btn btn-light btn-lg">TRACK CONSOLE</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Previous</span>
        </button>
        <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
            <span class="visually-hidden">Next</span>
        </button>
    </div>
    <!-- ===================== Bootstrap Carousel End ===================== -->


    <!-- About Start -->

    <div class="container py-5 reveal">
        <div class="row g-5 align-items-center">
            <div class="col-lg-6">
                <img class="img-fluid rounded-4 w-100" style="object-fit:cover; max-height:450px;" src="img/about-us.png" alt="About Biome Enterprises">
            </div>
            <div class="col-lg-6">
                <h6 class="text-secondary text-uppercase mb-3">Why Choose Us</h6>
                <h1 class="mb-4">Complete Logistics & Business Solutions Across India</h1>
                <p class="mb-4">Biome Enterprises is your trusted partner for transportation, bamboo trading, legal compliance, accounting, hospitality, and travel services. With our strategic base in North-East India and a growing Pan India network, we deliver reliable,
                    timely, and cost-effective solutions for businesses and individuals.</p>
                <div class="row g-4 mb-4">
                    <div class="col-sm-6">
                        <i class="fa fa-globe fa-3x text-primary mb-3"></i>
                        <h5>Pan India Coverage</h5>
                        <p class="m-0">Connecting Assam with major commercial hubs including Delhi, Punjab, Haryana, Uttar Pradesh, Uttarakhand, Gujarat, Maharashtra, Madhya Pradesh, West Bengal, and other key destinations.</p>
                    </div>
                    <div class="col-sm-6">
                        <i class="fa fa-shipping-fast fa-3x text-primary mb-3"></i>
                        <h5>Reliable & On-Time Service</h5>
                        <p class="m-0">We prioritize timely deliveries, transparent communication, and professional service, ensuring dependable logistics, compliance, and travel solutions every time.</p>
                    </div>
                </div>
                <a href="" class="btn btn-primary btn-lg">Explore More</a>
            </div>
        </div>
    </div>

    <!-- About End -->


    <!-- ===================== Fact / Counter Start ===================== -->

    <div class="container py-5 reveal">
        <div class="row g-5">
            <div class="col-lg-6">
                <h6 class="text-secondary text-uppercase mb-3">Some Facts</h6>
                <h1 class="mb-4">Your Trusted Partner for Logistics & Business Solutions Across India</h1>
                <p class="mb-4">Biome Enterprises provides reliable transportation, bamboo trading, legal & compliance services, accounting, hospitality, and cab booking solutions. Based in Assam, we proudly connect North-East India with major business hubs across
                    the country through dependable, customer-focused services.</p>
                <div class="d-flex align-items-center">
                    <i class="fa fa-headphones fa-2x flex-shrink-0 bg-primary p-3 text-white rounded"></i>
                    <div class="ps-4">
                        <h6 class="mb-1">Call for Any Query</h6>
                        <h3 class="text-primary m-0">+91 96784 31656</h3>
                    </div>
                </div>
            </div>
            <div class="col-lg-6">
                <div class="row g-4 reveal reveal-stagger">

                    <!-- Card 1 -->
                    <div class="col-6">
                        <div class="counter-box bg-primary shadow text-center p-4 h-100 tilt-card">
                            <i class="fa fa-users fa-3x text-white mb-3"></i>
                            <h2 class="text-white fw-bold mb-1">
                                <span class="counter-number" data-target="100">0</span>+
                            </h2>
                            <p class="text-white mb-0">Satisfied Clients</p>
                        </div>
                    </div>

                    <!-- Card 2 -->
                    <div class="col-6">
                        <div class="counter-box bg-success shadow text-center p-4 h-100 tilt-card">
                            <i class="fa fa-truck fa-3x text-white mb-3"></i>
                            <h2 class="text-white fw-bold mb-1">
                                <span class="counter-number" data-target="150">0</span>+
                            </h2>
                            <p class="text-white mb-0">Fleet & Deliveries</p>
                        </div>
                    </div>

                    <!-- Card 3 -->
                    <div class="col-6">
                        <div class="counter-box bg-dark shadow text-center p-4 h-100 tilt-card">
                            <i class="fa fa-map-marker fa-3x text-warning mb-3"></i>
                            <h2 class="text-white fw-bold mb-1">
                                <span class="counter-number" data-target="28">0</span>
                            </h2>
                            <p class="text-white mb-0">States Connected</p>
                        </div>
                    </div>

                    <!-- Card 4 -->
                    <div class="col-6">
                        <div class="counter-box bg-secondary shadow text-center p-4 h-100 tilt-card">
                            <i class="fa fa-check-circle fa-3x text-white mb-3"></i>
                            <h2 class="text-white fw-bold mb-1">
                                <span class="counter-number" data-target="99">0</span>%
                            </h2>
                            <p class="text-white mb-0">On-Time Delivery</p>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- ===================== Fact / Counter End ===================== -->


    <!-- Service Start -->

    <div class="container py-5 reveal">
        <div class="text-center mb-5">
            <h6 class="text-secondary text-uppercase">Our Services</h6>
            <h1 class="mb-4">Explore Our Services</h1>
            <p class="mb-0">Biome Enterprises delivers integrated supply chain optimization and ethical trade operations across the North-East Indian economic corridor.</p>
        </div>
        <div class="row g-4 reveal reveal-stagger">

            <!-- Logistics -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm p-3 tilt-card">
                    <img class="card-img-top rounded" src="img/service01.png" alt="Transportation Services" loading="lazy">
                    <div class="card-body px-0">
                        <h4 class="card-title">Transportation & Logistics</h4>
                        <p class="card-text">Reliable Pan India transportation with 32-ft open-body and multi-axle container trucks, connecting Assam to major industrial hubs.</p>
                        <a class="btn btn-outline-primary" href="transportation.php">Read More <i class="fa fa-arrow-right ms-1"></i></a>
                    </div>
                </div>
            </div>

            <!-- Bamboo -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm p-3 tilt-card">
                    <img class="card-img-top rounded" src="img/service02.png" alt="Bamboo Trading" loading="lazy">
                    <div class="card-body px-0">
                        <h4 class="card-title">Bamboo Trading</h4>
                        <p class="card-text">Premium raw bamboo, long bamboo poles, bamboo pieces, handicraft materials, and sustainable bamboo products supplied across India.</p>
                        <a class="btn btn-outline-primary" href="bamboo.php">Read More <i class="fa fa-arrow-right ms-1"></i></a>
                    </div>
                </div>
            </div>

            <!-- Legal -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm p-3 tilt-card">
                    <img class="card-img-top rounded" src="img/service03.png" alt="Legal & Compliance" loading="lazy">
                    <div class="card-body px-0">
                        <h4 class="card-title">Legal & Compliance</h4>
                        <p class="card-text">GST, FSSAI, MSME, Company Registration, IEC, Accounting, Taxation, Documentation, and complete business compliance services.</p>
                        <a class="btn btn-outline-primary" href="legal.php">Read More <i class="fa fa-arrow-right ms-1"></i></a>
                    </div>
                </div>
            </div>

            <!-- Cab -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm p-3 tilt-card">
                    <img class="card-img-top rounded" src="img/service04.png" alt="Cab Rental" loading="lazy">
                    <div class="card-body px-0">
                        <h4 class="card-title">Cab Rental Services</h4>
                        <p class="card-text">Self-drive cars, chauffeur-driven vehicles, airport transfers, local travel, and corporate rental services across North-East India.</p>
                        <a class="btn btn-outline-primary" href="cab.php">Read More <i class="fa fa-arrow-right ms-1"></i></a>
                    </div>
                </div>
            </div>

            <!-- Hotel -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm p-3 tilt-card">
                    <img class="card-img-top rounded" src="img/service05.png" alt="Hotels & Homestays" loading="lazy">
                    <div class="card-body px-0">
                        <h4 class="card-title">Hotels & Homestays <span class="badge bg-warning text-dark">Upcoming</span></h4>
                        <p class="card-text">Book trusted hotels, hill-station stays, premium homestays, and business accommodations across all eight North-East states.</p>
                        <a class="btn btn-outline-primary" href="hotel.php">Read More <i class="fa fa-arrow-right ms-1"></i></a>
                    </div>
                </div>
            </div>

            <!-- Restaurant -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 border-0 shadow-sm p-3 tilt-card">
                    <img class="card-img-top rounded" src="img/service06.png" alt="Restaurant" loading="lazy">
                    <div class="card-body px-0">
                        <h4 class="card-title">Restaurant & Ethnic Cuisine <span class="badge bg-warning text-dark">Upcoming</span></h4>
                        <p class="card-text">Experience authentic North-East cuisine, bamboo shoot delicacies, smoked meats, traditional dishes, and local culinary specialties.</p>
                        <a class="btn btn-outline-primary" href="restaurant.php">Read More <i class="fa fa-arrow-right ms-1"></i></a>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Service End -->


    <!-- Feature Start -->

    <div class="container py-5 reveal">
        <div class="row g-5 align-items-center">
            <div class="col-lg-5">
                <h6 class="text-secondary text-uppercase mb-3">Why Choose Biome Enterprises</h6>
                <h1 class="mb-4">Complete Business Solutions Under One Roof</h1>

                <div class="d-flex mb-4">
                    <i class="fas fa-truck-moving text-primary fa-3x flex-shrink-0"></i>
                    <div class="ms-4">
                        <h5>Pan India Logistics Network</h5>
                        <p class="mb-0">Reliable freight transportation using 32-ft open-body and multi-axle container trucks connecting Assam with major industrial hubs across India.</p>
                    </div>
                </div>

                <div class="d-flex mb-4">
                    <i class="fas fa-seedling text-primary fa-3x flex-shrink-0"></i>
                    <div class="ms-4">
                        <h5>Bamboo Trading Solutions</h5>
                        <p class="mb-0">Supplying premium raw bamboo, long bamboo poles, bamboo pieces, and sustainable bamboo products for industries, construction, and handicrafts.</p>
                    </div>
                </div>

                <div class="d-flex mb-4">
                    <i class="fas fa-balance-scale text-primary fa-3x flex-shrink-0"></i>
                    <div class="ms-4">
                        <h5>Legal & Compliance Services</h5>
                        <p class="mb-0">GST, FSSAI, MSME (Udyam), IEC, Company Registration, Accounting, Taxation, Documentation, and Financial Compliance.</p>
                    </div>
                </div>

                <div class="d-flex mb-4">
                    <i class="fas fa-hotel text-primary fa-3x flex-shrink-0"></i>
                    <div class="ms-4">
                        <h5>Hospitality & Travel Services</h5>
                        <p class="mb-0">Book trusted hotels, homestays, restaurants, self-drive cars, chauffeur-driven vehicles, and rental cabs across North-East India.</p>
                    </div>
                </div>

                <div class="d-flex">
                    <i class="fas fa-headset text-primary fa-3x flex-shrink-0"></i>
                    <div class="ms-4">
                        <h5>Dedicated Customer Support</h5>
                        <p class="mb-0">Fast quotations, transparent communication, and expert assistance to ensure smooth logistics, compliance, and travel services.</p>
                    </div>
                </div>

            </div>

            <div class="col-lg-7">
                <img class="img-fluid rounded-4 w-100" src="img/feature.png" alt="Biome Enterprises Features">
            </div>
        </div>
    </div>

    <!-- Feature End -->


    <!-- Quote Start -->

    <div class="container py-5 reveal">
        <div class="row g-5 align-items-center">

            <div class="col-lg-7">
                <div class="card shadow border-0 p-4 p-md-5">
                    <form method="post" action="">
                        <input type="hidden" name="csrf_token" value="<?= qf_e($quoteCsrfToken) ?>">
                        <input type="hidden" name="quote_form_submit" value="1">

                        <div class="row g-3">

                            <!-- Name -->
                            <div class="col-md-6">
                                <input type="text" name="full_name" class="form-control form-control-lg" placeholder="Full Name *"
                                    maxlength="150" required
                                    value="<?= qf_e($quoteFormValues['full_name']) ?>">
                            </div>

                            <!-- Mobile -->
                            <div class="col-md-6">
                                <input type="tel" name="mobile_number" class="form-control form-control-lg" placeholder="Mobile Number *"
                                    maxlength="20" required
                                    value="<?= qf_e($quoteFormValues['mobile_number']) ?>">
                            </div>

                            <!-- Email -->
                            <div class="col-md-6">
                                <input type="email" name="email" class="form-control form-control-lg" placeholder="Email Address"
                                    maxlength="150"
                                    value="<?= qf_e($quoteFormValues['email']) ?>">
                            </div>

                            <!-- Location -->
                            <div class="col-md-6">
                                <input type="text" name="city_state" class="form-control form-control-lg" placeholder="City / State"
                                    maxlength="150"
                                    value="<?= qf_e($quoteFormValues['city_state']) ?>">
                            </div>

                            <!-- Service -->
                            <div class="col-md-6">
                                <select name="service_required" class="form-select form-select-lg">
                                    <option value="" <?= $quoteFormValues['service_required'] === '' ? 'selected' : '' ?>>Select Required Service</option>
                                    <?php
                                    $services = [
                                        'Transportation & Logistics', 'Bamboo Trading', 'Legal & Compliance',
                                        'GST Registration', 'FSSAI Registration', 'MSME Registration',
                                        'Company Registration', 'Accounting & Taxation', 'Cab Rental',
                                    ];
                                    foreach ($services as $service):
                                    ?>
                                        <option value="<?= qf_e($service) ?>" <?= $quoteFormValues['service_required'] === $service ? 'selected' : '' ?>>
                                            <?= qf_e($service) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <!-- Business -->
                            <div class="col-md-6">
                                <input type="text" name="company_name" class="form-control form-control-lg" placeholder="Company / Business Name"
                                    maxlength="150"
                                    value="<?= qf_e($quoteFormValues['company_name']) ?>">
                            </div>

                            <!-- Message -->
                            <div class="col-12">
                                <textarea name="message" class="form-control" rows="5" maxlength="2000"
                                        placeholder="Describe Your Requirement"><?= qf_e($quoteFormValues['message']) ?></textarea>
                            </div>

                            <!-- Button -->
                            <div class="col-12">
                                <button class="btn btn-primary btn-lg w-100" type="submit">
                                    Request Free Quote
                                </button>
                            </div>

                        </div>
                    </form>
                </div>
            </div>
            <div class="col-lg-5">
                <h6 class="text-secondary text-uppercase mb-3">Get A Quote</h6>
                <h1 class="mb-4">Request a Free Consultation & Quotation</h1>
                <p class="mb-4">Looking for reliable transportation, premium bamboo trading, legal & compliance assistance, hotel bookings, restaurant reservations, or cab rental services? Share your requirements with us and our team will provide a customized solution
                    and competitive quotation tailored to your business or personal needs.</p>

                <div class="card border-0 shadow-sm p-4">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">

                        <div class="d-flex align-items-center">
                            <i class="fa fa-headphones fa-2x text-primary"></i>
                            <div class="ms-3">
                                <span class="small text-uppercase text-secondary fw-bold">24/7 Customer Support</span>
                                <h5 class="mb-1 fw-bold">Need Immediate Assistance?</h5>
                                <h3 class="text-primary fw-bold mb-0">
                                    <a href="tel:+919678431656" class="text-decoration-none">+91 96784 31656</a>
                                </h3>
                            </div>
                        </div>

                        <a href="https://wa.me/919678431656" target="_blank" class="btn btn-success btn-lg rounded-circle">
                            <i class="fab fa-whatsapp"></i>
                        </a>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Quote End -->


    <!-- Team Start -->

    <div class="container py-5 reveal">
        <div class="text-center mb-5">
            <h6 class="text-secondary text-uppercase">Our Team</h6>
            <h1 class="mb-0">Experienced Professionals Behind Every Successful Project</h1>
        </div>
        <div class="row g-4 reveal reveal-stagger">
            <div class="col-lg-3 col-md-6">
                <div class="card border-0 shadow-sm text-center p-3 h-100">
                    <img class="card-img-top rounded" src="img/team-1.jpeg" alt="" loading="lazy">
                    <div class="card-body">
                        <h5 class="mb-0">Bittu Ali Hazarika</h5>
                        <p class="text-muted">Managing Director</p>
                        <div>
                            <a href="" class="btn btn-sm btn-outline-primary rounded-circle me-1"><i class="fab fa-facebook-f"></i></a>
                            <a href="" class="btn btn-sm btn-outline-primary rounded-circle me-1"><i class="fab fa-twitter"></i></a>
                            <a href="" class="btn btn-sm btn-outline-primary rounded-circle"><i class="fab fa-instagram"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            <!-- <div class="col-lg-3 col-md-6">
                <div class="card border-0 shadow-sm text-center p-3 h-100">
                    <img class="card-img-top rounded" src="img/team-2.jpg" alt="" loading="lazy">
                    <div class="card-body">
                        <h5 class="mb-0">Full Name</h5>
                        <p class="text-muted">Operations Head</p>
                        <div>
                            <a href="" class="btn btn-sm btn-outline-primary rounded-circle me-1"><i class="fab fa-facebook-f"></i></a>
                            <a href="" class="btn btn-sm btn-outline-primary rounded-circle me-1"><i class="fab fa-twitter"></i></a>
                            <a href="" class="btn btn-sm btn-outline-primary rounded-circle"><i class="fab fa-instagram"></i></a>
                        </div>
                    </div>
                </div>
            </div> -->
            <div class="col-lg-3 col-md-6">
                <div class="card border-0 shadow-sm text-center p-3 h-100">
                    <img class="card-img-top rounded" src="img/team-4.png" alt="" loading="lazy">
                    <div class="card-body">
                        <h5 class="mb-0">Pinku Sawra</h5>
                        <p class="text-muted">Data Entry Operator</p>
                        <div>
                            <a href="" class="btn btn-sm btn-outline-primary rounded-circle me-1"><i class="fab fa-facebook-f"></i></a>
                            <a href="" class="btn btn-sm btn-outline-primary rounded-circle me-1"><i class="fab fa-twitter"></i></a>
                            <a href="" class="btn btn-sm btn-outline-primary rounded-circle"><i class="fab fa-instagram"></i></a>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6">
                <div class="card border-0 shadow-sm text-center p-3 h-100">
                    <img class="card-img-top rounded" src="img/team-3.png" alt="" loading="lazy">
                    <div class="card-body">
                        <h5 class="mb-0">Minal Kanth</h5>
                        <!-- <p class="text-muted">Senior Full-Stack Web Developer</p> -->
                        <p class="text-muted">Head of Technology</p>
                        <div>
                            <a href="" class="btn btn-sm btn-outline-primary rounded-circle me-1"><i class="fab fa-facebook-f"></i></a>
                            <a href="" class="btn btn-sm btn-outline-primary rounded-circle me-1"><i class="fab fa-twitter"></i></a>
                            <a href="" class="btn btn-sm btn-outline-primary rounded-circle"><i class="fab fa-instagram"></i></a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Team End -->


    <!-- ===================== Bootstrap Testimonial Carousel Start ===================== -->

    <div class="container py-5 reveal">
        <div class="text-center mb-5">
            <h6 class="text-secondary text-uppercase">Client Testimonials</h6>
            <h1 class="mb-0">What Our Clients Say</h1>
        </div>

        <div id="testimonialCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-interval="6000">

            <div class="carousel-indicators position-static mb-4">
                <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="0" class="active bg-primary" aria-current="true" aria-label="Slide 1"></button>
                <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="1" class="bg-primary" aria-label="Slide 2"></button>
                <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="2" class="bg-primary" aria-label="Slide 3"></button>
                <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="3" class="bg-primary" aria-label="Slide 4"></button>
            </div>

            <div class="carousel-inner">

                <!-- Testimonial 1 -->
                <div class="carousel-item active">
                    <div class="card border-0 shadow-sm mx-auto p-4 p-md-5" style="max-width:700px;">
                        <i class="fa fa-quote-right fa-2x text-primary mb-3"></i>
                        <p class="fs-5 mb-4">
                            Biome Enterprises handled our Assam to Delhi freight professionally. Their team provided timely updates and ensured safe delivery throughout the journey.
                        </p>
                        <div class="d-flex align-items-center">
                            <img class="rounded-circle flex-shrink-0" src="img/testimonial-1.jpg" style="width:64px;height:64px;object-fit:cover;" loading="lazy">
                            <div class="ms-3">
                                <h5 class="mb-0">Rajesh Sharma</h5>
                                <p class="m-0 text-muted">Manufacturing Business</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Testimonial 2 -->
                <div class="carousel-item">
                    <div class="card border-0 shadow-sm mx-auto p-4 p-md-5" style="max-width:700px;">
                        <i class="fa fa-quote-right fa-2x text-primary mb-3"></i>
                        <p class="fs-5 mb-4">
                            Their legal and compliance team completed our GST and FSSAI registration quickly with complete transparency. Highly recommended for startups.
                        </p>
                        <div class="d-flex align-items-center">
                            <img class="rounded-circle flex-shrink-0" src="img/testimonial-2.jpg" style="width:64px;height:64px;object-fit:cover;" loading="lazy">
                            <div class="ms-3">
                                <h5 class="mb-0">Priya Das</h5>
                                <p class="m-0 text-muted">Food Business Owner</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Testimonial 3 -->
                <div class="carousel-item">
                    <div class="card border-0 shadow-sm mx-auto p-4 p-md-5" style="max-width:700px;">
                        <i class="fa fa-quote-right fa-2x text-primary mb-3"></i>
                        <p class="fs-5 mb-4">
                            Excellent cab booking and hotel arrangements for our business trip across North-East India. The service was reliable and hassle-free.
                        </p>
                        <div class="d-flex align-items-center">
                            <img class="rounded-circle flex-shrink-0" src="img/testimonial-3.jpg" style="width:64px;height:64px;object-fit:cover;" loading="lazy">
                            <div class="ms-3">
                                <h5 class="mb-0">Amit Verma</h5>
                                <p class="m-0 text-muted">Corporate Client</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Testimonial 4 -->
                <div class="carousel-item">
                    <div class="card border-0 shadow-sm mx-auto p-4 p-md-5" style="max-width:700px;">
                        <i class="fa fa-quote-right fa-2x text-primary mb-3"></i>
                        <p class="fs-5 mb-4">
                            We source bamboo materials through Biome Enterprises regularly. Their quality, pricing, and logistics support have always exceeded our expectations.
                        </p>
                        <div class="d-flex align-items-center">
                            <img class="rounded-circle flex-shrink-0" src="img/testimonial-4.jpg" style="width:64px;height:64px;object-fit:cover;" loading="lazy">
                            <div class="ms-3">
                                <h5 class="mb-0">Neha Singh</h5>
                                <p class="m-0 text-muted">Bamboo Industry</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <button class="carousel-control-prev" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="prev">
                <span class="carousel-control-prev-icon bg-primary rounded-circle p-3" aria-hidden="true"></span>
                <span class="visually-hidden">Previous</span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="next">
                <span class="carousel-control-next-icon bg-primary rounded-circle p-3" aria-hidden="true"></span>
                <span class="visually-hidden">Next</span>
            </button>
        </div>
    </div>

    <!-- ===================== Bootstrap Testimonial Carousel End ===================== -->


    <!-- Floating WhatsApp Button -->
    <a href="https://wa.me/919678431656" target="_blank" class="whatsapp-float" aria-label="Chat on WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Sticky Mobile Call Bar -->
    <div id="mobileCallBar">
        <a href="tel:+919678431656" class="btn btn-primary flex-fill"><i class="fa fa-phone me-2"></i>Call Now</a>
        <a href="https://wa.me/919678431656" target="_blank" class="btn btn-success flex-fill"><i class="fab fa-whatsapp me-2"></i>WhatsApp</a>
    </div>

    <!-- Footer  -->
    <?php include __DIR__ . '/footer.php'; ?>
    <!-- Footer end -->


    <!-- Back to Top -->
    <a href="#" class="btn btn-lg btn-primary btn-lg-square rounded-0 back-to-top"><i class="bi bi-arrow-up"></i></a>


    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="lib/wow/wow.min.js"></script>
    <script src="lib/easing/easing.min.js"></script>
    <script src="lib/waypoints/waypoints.min.js"></script>

    <!-- Template Javascript -->
    <script src="js/main.js"></script>

    <!-- ===================== Counter Animation (vanilla JS, 0 -> target) ===================== -->
    <script>
    (function () {
        const counters = document.querySelectorAll('.counter-number');
        const duration = 1800; // ms

        function animateCounter(el) {
            const target = parseInt(el.getAttribute('data-target'), 10) || 0;
            const start = performance.now();

            function step(now) {
                const progress = Math.min((now - start) / duration, 1);
                // easeOutQuad
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
        // Scroll progress bar (rAF-throttled)
        const progressBar = document.getElementById('scrollProgress');
        let progressTicking = false;
        function updateProgress() {
            const h = document.documentElement;
            const scrolled = (h.scrollTop) / (h.scrollHeight - h.clientHeight) * 100;
            if (progressBar) progressBar.style.width = scrolled + '%';
            progressTicking = false;
        }
        window.addEventListener('scroll', function () {
            if (!progressTicking) {
                requestAnimationFrame(updateProgress);
                progressTicking = true;
            }
        }, { passive: true });
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

        // Navbar shadow on scroll (rAF-throttled so it never fights the browser's paint cycle)
        const nav = document.querySelector('nav.navbar, .navbar');
        let navTicking = false;
        function updateNav() {
            if (!nav) return;
            nav.classList.toggle('be-scrolled', window.scrollY > 40);
            navTicking = false;
        }
        window.addEventListener('scroll', function () {
            if (!navTicking) {
                requestAnimationFrame(updateNav);
                navTicking = true;
            }
        }, { passive: true });
        updateNav();

        // Cursor glow (desktop only)
        const glow = document.getElementById('cursorGlow');
        if (glow && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            window.addEventListener('mousemove', function (e) {
                glow.style.left = e.clientX + 'px';
                glow.style.top = e.clientY + 'px';
            }, { passive: true });
        }

        // 3D tilt effect on cards
        const tiltCards = document.querySelectorAll('.tilt-card');
        if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            tiltCards.forEach(function (card) {
                card.addEventListener('mousemove', function (e) {
                    const rect = card.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    const rotateX = ((y / rect.height) - 0.5) * -10;
                    const rotateY = ((x / rect.width) - 0.5) * 10;
                    card.style.transform = 'perspective(800px) rotateX(' + rotateX + 'deg) rotateY(' + rotateY + 'deg) translateY(-8px)';
                });
                card.addEventListener('mouseleave', function () {
                    card.style.transform = '';
                });
            });
        }
    })();
    </script>

</body>

</html>