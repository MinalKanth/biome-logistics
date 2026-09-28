<?php
declare(strict_types=1);

// =========================================================================
// 1. ELITE SEO ENGINE & HEADERS
// =========================================================================
require_once __DIR__ . '/EliteSeoEngine.php'; 
$seo = new EliteEnterpriseSeoEngine();
$seo->sendEnterpriseHeaders();

require_once __DIR__ . '/admin/config/database.php';
 

// Include PHPMailer library files (Adjust the path as per your server setup)
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer-master/src/Exception.php';
require 'PHPMailer-master/src/PHPMailer.php';
require 'PHPMailer-master/src/SMTP.php';

// =========================================================================
// 2. FORM & SECURE SESSION LOGIC
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

function cf_send_notification_email(string $name, string $email, string $subject, string $message): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com'; 
        $mail->SMTPAuth   = true;
        // FIXED: Added closing quote and removed semicolon from inside the string
        $mail->Username   = 'info@biomeenterprises.com'; 
        $mail->Password   = '&7u9R>Kv@2026#meBIo'; 
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom('info@biomeenterprises.com', 'Biome Enterprises Website');
        $mail->addAddress('director@biomeenterprises.com');
        $mail->addReplyTo($email, $name);

        $mail->isHTML(true);
        $mail->Subject = 'New Transport Enquiry: ' . ($subject !== '' ? $subject : 'General');
        $mail->Body    = '<p><strong>Name:</strong> ' . htmlspecialchars($name) . '</p>'
                        . '<p><strong>Email:</strong> ' . htmlspecialchars($email) . '</p>'
                        . '<p><strong>Service:</strong> ' . htmlspecialchars($subject ?: 'Not specified') . '</p>'
                        . '<p><strong>Message:</strong><br>' . nl2br(htmlspecialchars($message)) . '</p>';
        $mail->AltBody = "Name: {$name}\nEmail: {$email}\nService: {$subject}\nMessage: {$message}";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Contact form email failed: ' . $mail->ErrorInfo);
        return false;
    }
}

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

                // Send email notification after successful DB insert
                cf_send_notification_email($name, $email, $subject, $message); 

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

// Function wrapper to avoid redeclaration fatal errors
if (!function_exists('cf_e')) {
    function cf_e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <?php
    // =========================================================================
    // 🚀 DYNAMIC META GENERATION FOR TRANSPORT SERVICES PAGE
    // =========================================================================
    echo $seo->generateMeta([
        'title'       => 'Logistics & Transport Services | Biome Enterprises Assam',
        'description' => 'Biome Enterprises offers highly reliable transportation, logistics, 32 ft container trucks, and open body trucks for seamless freight operations across North-East India.',
        'keywords'    => 'transport, logistics Assam, container truck, open body truck, freight North-East India, Biome Enterprises logistics',
        'url_path'    => '/transportation', 
        'type'        => 'website'
    ]);
    
    // =========================================================================
    // 🧠 AI SEMANTIC SCHEMAS (JSON-LD)
    // =========================================================================
    echo $seo->schema($seo->coreGraph());
    echo $seo->schema($seo->breadcrumbSchema([
        "Home" => "/",
        "Transport Services" => "/transportation"
    ]));
    ?>

    <meta content="width=device-width, initial-scale=1.0" name="viewport">

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

    <!-- Customized Bootstrap Stylesheet -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">
    
    <!-- Template Stylesheet -->
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
            --be-transition: all .3s cubic-bezier(.25,.8,.25,1);
            
            /* Transport Specific Vars (Preserved logic, linked to master colors) */
            --bio-green: #198754;
            --bio-green-dark: #0f4c2d;
            --bio-gold: #ffc107;
            --bio-dark: #14181a;
            --bio-soft: #f4f8f6;
            --bio-radius: 1rem;
            --bio-transition: all .3s cubic-bezier(.25,.8,.25,1);
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        html, body { max-width: 100%; overflow-x: hidden; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            color: #2c3232;
            background: #f7f9fc;
        }

        img { max-width: 100%; height: auto; }

        /* ---- Animated Background Blobs ---- */
        .be-blob { filter: blur(70px); opacity: 0.4; animation: be-float 10s ease-in-out infinite; }
        @keyframes be-float {
            0%, 100% { transform: translateY(0) translateX(0) scale(1); }
            50% { transform: translateY(-30px) translateX(20px) scale(1.05); }
        }

        /* Fluid type scale — mobile-first, grows with viewport */
        h1 { font-size: clamp(1.6rem, 4vw + 1rem, 3.25rem); }
        h2 { font-size: clamp(1.4rem, 2.6vw + .9rem, 2.25rem); }
        h3 { font-size: clamp(1.2rem, 2vw + .8rem, 1.85rem); }
        h4 { font-size: clamp(1.05rem, 1.4vw + .7rem, 1.4rem); }
        .fs-5 { font-size: clamp(.95rem, .8vw + .75rem, 1.2rem) !important; }

        a { text-decoration: none; }

        /* ---------- Reusable section bits ---------- */
        .section-eyebrow {
            display: inline-block;
            color: var(--bio-green);
            text-transform: uppercase;
            font-weight: 700;
            letter-spacing: .08em;
            font-size: .8rem;
        }

        .section-divider {
            width: 64px; height: 4px; border-radius: 4px;
            background: linear-gradient(90deg, var(--bio-green), var(--bio-gold));
            margin-top: .85rem;
        }

        .reveal-up {
            opacity: 0; transform: translateY(36px);
            transition: opacity .7s ease, transform .7s ease;
        }
        .reveal-up.is-visible { opacity: 1; transform: translateY(0); }

        /* ---------- Buttons ---------- */
        .btn { border-radius: 50px; font-weight: 600; transition: var(--bio-transition); }
        .btn-success { background: var(--bio-green); border-color: var(--bio-green); }
        .btn-success:hover {
            background: var(--bio-green-dark); border-color: var(--bio-green-dark);
            transform: translateY(-3px); box-shadow: 0 14px 26px rgba(25,135,84,.28);
        }
        .btn-outline-success { color: var(--bio-green); border-color: var(--bio-green); }
        .btn-outline-success:hover { background: var(--bio-green); transform: translateY(-2px); color: #fff; }
        .btn-outline-light:hover { transform: translateY(-3px); color: var(--be-dark); background: var(--bio-gold); border-color: var(--bio-gold); }
        .btn-primary { background: var(--bio-gold) !important; border-color: var(--bio-gold) !important; color: var(--be-dark); }
        .btn-primary:hover { background: var(--be-primary-dark) !important; border-color: var(--be-primary-dark) !important; }
        
        /* ---- Navbar glass on scroll ---- */
        .navbar { transition: background .4s ease, box-shadow .4s ease, padding .4s ease; background: transparent !important; box-shadow: none !important; }
        .navbar .navbar-brand h2, .navbar .navbar-brand, .navbar .nav-link, .navbar .dropdown-toggle { color:#fff !important; }
        .navbar .navbar-toggler { border-color: rgba(255,255,255,.35); }
        .navbar .navbar-toggler i, .navbar .navbar-toggler-icon { color:#fff !important; }
        
        .navbar.be-scrolled {
            background: rgba(255,255,255,.98) !important;
            backdrop-filter: blur(14px) saturate(160%);
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            padding-top: .4rem !important; padding-bottom: .4rem !important;
            box-shadow: 0 6px 20px rgba(0,0,0,.08) !important;
        }
        .navbar.be-scrolled .navbar-brand h2, .navbar.be-scrolled .navbar-brand, .navbar.be-scrolled .nav-link, .navbar.be-scrolled .dropdown-toggle { color: var(--be-dark) !important; }

        /* ===================================================
           HERO
        ==================================================== */
        .transport-header {
            position: relative;
            background: linear-gradient(135deg, #1c1602 0%, #3a2e05 55%, #16210f 100%);
            padding-top: 100px; padding-bottom: 80px; overflow: hidden;
        }
        .transport-header::before {
            content: ""; position: absolute; inset: 0;
            background: radial-gradient(circle at 15% 20%, rgba(25,135,84,.25), transparent 55%),
                        radial-gradient(circle at 85% 80%, rgba(255,193,7,.25), transparent 55%);
            pointer-events: none;
        }
        .transport-header::after {
            content: ""; position: absolute; inset: 0;
            background-image: repeating-linear-gradient(45deg, rgba(255,255,255,.025) 0 2px, transparent 2px 26px);
            pointer-events: none;
        }
        .transport-header h6.text-warning {
            color: var(--be-primary) !important; letter-spacing: 3px; display: inline-block;
            padding: .35rem 1rem; border: 1px solid rgba(255,255,255,.35); border-radius: 50px;
            backdrop-filter: blur(6px); background: rgba(255,255,255,.08); position: relative; z-index: 1; margin-bottom: 1rem;
        }
        .transport-header .text-success { color: var(--be-primary) !important; }

        .hero-stat-rail {
            display: grid; grid-template-columns: repeat(2, 1fr); gap: .9rem;
            margin-top: 2rem; max-width: 560px; position: relative; z-index: 1;
        }
        .hero-stat-rail > div {
            background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.12);
            border-radius: .85rem; padding: 1rem .75rem; text-align: center; backdrop-filter: blur(4px);
        }
        .stat-value {
            font-family: 'Roboto Mono', monospace; font-weight: 700;
            font-size: clamp(1.3rem, 1.6vw + 1rem, 2rem); color: var(--bio-gold); line-height: 1.1;
        }
        .stat-label { color: #d7e8df; font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; margin-top: .25rem; }

        .route-strip { position: relative; height: 28px; margin-top: 2rem; display: none; z-index: 1; }
        .route-strip__line {
            position: absolute; top: 50%; left: 0; right: 0; height: 2px;
            background: repeating-linear-gradient(90deg, var(--bio-gold) 0 12px, transparent 12px 22px);
            transform: translateY(-50%);
        }
        .route-strip__truck {
            position: absolute; top: 50%; transform: translateY(-50%);
            color: var(--bio-gold); font-size: 1.1rem; animation: bio-drive 7s linear infinite;
        }
        @keyframes bio-drive { 0% { left: 0%; } 100% { left: 96%; } }

        .breadcrumb { background: rgba(0,0,0,0.25); display: inline-flex; padding: 10px 25px; border-radius: 50px; backdrop-filter: blur(8px); margin: 0; margin-top: 1.75rem; z-index: 1; position: relative;}
        .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,.6); }
        .breadcrumb a:hover { color: var(--bio-gold) !important; }

        /* ===================================================
           FLEET CARDS
        ==================================================== */
        .manifest-card {
            background: #fff; border-radius: var(--bio-radius); overflow: hidden;
            box-shadow: var(--be-shadow-soft); border: 1px solid rgba(25,135,84,.05);
            transition: var(--bio-transition); cursor: pointer; height: 100%;
        }
        .manifest-card:hover { box-shadow: var(--be-shadow-strong); transform: translateY(-6px); }

        .manifest-card__art {
            position: relative; background: linear-gradient(135deg, var(--bio-soft), #e7f3ec);
            padding: 1.5rem; text-align: center; height: 180px; display: flex; align-items: center; justify-content: center;
        }
        .manifest-card__art img {
            max-height: 130px; max-width: 90%; width: auto; height: auto; object-fit: contain; margin: 0 auto; transition: transform .4s ease;
        }
        .manifest-card:hover .manifest-card__art img { transform: scale(1.07) rotate(1deg); }

        .manifest-card__tag {
            position: absolute; top: .85rem; left: .85rem; background: var(--bio-dark); color: var(--bio-gold);
            font-family: 'Roboto Mono', monospace; font-size: .68rem; letter-spacing: .06em; padding: .25rem .6rem; border-radius: 50px;
        }

        .manifest-card__body { padding: 1.5rem; display: flex; flex-direction: column; height: calc(100% - 180px); }
        .manifest-card__body .card-cta { margin-top: auto; }

        .manifest-card__specs { list-style: none; margin: 0 0 1.25rem; padding: 0; border-top: 1px dashed #dbe5e0; }
        .manifest-card__specs li { display: flex; justify-content: space-between; padding: .5rem 0; border-bottom: 1px dashed #dbe5e0; font-size: .88rem; }
        .manifest-card__specs li span:first-child { color: #6c7a73; }
        .manifest-card__specs li span:last-child { font-weight: 600; color: var(--bio-dark); }

        @keyframes bio-pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.04); } }

        /* ===================================================
           WHY CHOOSE US
        ==================================================== */
        .why-strip { display: grid; grid-template-columns: 1fr; gap: 1.5rem; }
        .why-item--large {
            background: #fff; border: 1px solid rgba(25,135,84,.05); border-radius: var(--bio-radius);
            padding: 1.75rem; box-shadow: var(--be-shadow-soft); transition: var(--bio-transition);
        }
        .why-item--large:hover { transform: translateY(-6px); box-shadow: var(--be-shadow-strong); }
        
        .why-item__icon {
            width: 56px; height: 56px; border-radius: 50%; background: linear-gradient(135deg, var(--bio-green), var(--bio-green-dark));
            color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; margin-bottom: 1rem;
            transition: transform 0.3s ease;
        }
        .why-item--large:hover .why-item__icon { transform: rotate(-10deg) scale(1.1); }

        .why-item--large h5 { font-weight: 700; margin-bottom: .65rem; color: var(--be-dark); }
        .why-item--large p { color: #5b6661; font-size: .92rem; }

        .why-item__features { list-style: none; padding: 0; margin: 1rem 0 0; display: grid; gap: .4rem; }
        .why-item__features li { font-size: .85rem; color: #3a4541; }
        .why-item__features i { color: var(--bio-green); margin-right: .4rem; }

        @keyframes bio-bounce { 0%, 100% { transform: translateY(0); } 50% { transform: translateY(-6px); } }

        /* ===================================================
           PROCESS / BOOKING STEPS
        ==================================================== */
        .process-rail { display: grid; grid-template-columns: 1fr; gap: 1rem; align-items: stretch; }
        .process-step {
            background: #fff; border: 1px solid rgba(25,135,84,.05); border-radius: var(--bio-radius);
            padding: 1.5rem; text-align: center; transition: var(--bio-transition); box-shadow: var(--be-shadow-soft);
        }
        .process-step:hover { transform: translateY(-8px); box-shadow: var(--be-shadow-strong); border-color: var(--bio-green); }
        
        .process-step__code {
            display: inline-block; font-family: 'Roboto Mono', monospace; font-size: .72rem; font-weight: 700; color: var(--be-dark);
            background: var(--be-primary); padding: .35rem .8rem; border-radius: 50px; margin-bottom: .85rem;
        }
        .process-step h6 { font-weight: 700; margin-bottom: .4rem; color: var(--be-dark); }
        .process-step p { color: #6c7a73; font-size: .85rem; margin: 0; }
        .process-arrow { display: none; align-items: center; justify-content: center; color: var(--bio-green); font-size: 1.2rem; }

        /* ===================================================
           COVERAGE + TRUST
        ==================================================== */
        .coverage-card {
            background: linear-gradient(150deg, var(--bio-green-dark), var(--bio-green)); color: #fff;
            border-radius: var(--bio-radius); padding: 2rem; height: 100%; box-shadow: var(--be-shadow-strong);
        }
        .coverage-list { list-style: none; padding: 0; margin: 1.5rem 0 0; display: grid; grid-template-columns: repeat(2, 1fr); gap: .6rem .5rem; }
        .coverage-list li { font-size: .9rem; display: flex; align-items: center; gap: .4rem; }
        .coverage-list i { color: var(--bio-gold); }

        .trust-card {
            background: #fff; border: 1px solid rgba(25,135,84,.05); border-radius: var(--bio-radius); padding: 1.5rem; height: 100%;
            box-shadow: var(--be-shadow-soft); transition: var(--bio-transition);
        }
        .trust-card:hover { transform: translateY(-6px); box-shadow: var(--be-shadow-strong); }
        .stars { color: var(--bio-gold); margin-bottom: .6rem; font-size: .9rem; }
        .trust-card p { font-size: .92rem; color: #45504b; font-style: italic; }
        .trust-card__name { font-weight: 700; color: var(--bio-dark); margin-top: .75rem; }
        .trust-card__role { font-size: .8rem; color: #6c7a73; }

        @keyframes bio-spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        @keyframes bio-star { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.15); } }

        /* ===================================================
           QUOTE FORM
        ==================================================== */
        .quote-panel {
            background: #fff; border-radius: var(--bio-radius); padding: 1.75rem;
            box-shadow: var(--be-shadow-strong); border: 1px solid rgba(25,135,84,.05);
        }
        .form-floating > .form-control, .form-floating > .form-select { border-radius: .75rem; border: 1px solid #dbe5e0; }
        .form-floating > .form-control:focus, .form-floating > .form-select:focus { border-color: var(--bio-green); box-shadow: 0 0 0 .25rem rgba(25,135,84,.15); }
        .is-invalid-bio { border-color: #dc3545 !important; box-shadow: 0 0 0 .2rem rgba(220,53,69,.15) !important; }
        .char-counter-bio { font-size: .76rem; color: #6c7a73; display: block; text-align: right; margin-top: .25rem; }

        /* ===================================================
           MOBILE STICKY CTA + BACK TO TOP
        ==================================================== */
        .bio-mobile-cta {
            display: none; position: fixed; left: 0; right: 0; bottom: 0; z-index: 1040;
            background: rgba(255,255,255,.98); backdrop-filter: blur(12px); padding: .75rem 1rem; gap: .75rem;
            box-shadow: 0 -8px 24px rgba(0,0,0,.1); border-top: 1px solid rgba(0,0,0,.05);
        }
        .bio-mobile-cta .btn { flex: 1; font-size: .9rem; border-radius: 50px;}

        .back-to-top {
            border-radius: 50% !important; width: 50px; height: 50px; display: flex; align-items: center; justify-content: center;
            box-shadow: var(--be-shadow-strong); position: fixed !important; right: 24px !important; bottom: 20px !important; left: auto !important; z-index: 1501;
            background: linear-gradient(135deg, var(--be-primary), var(--be-primary-dark)) !important; border: none; transition: var(--be-transition);
        }
        .back-to-top:hover { background: var(--be-success) !important; box-shadow: 0 14px 32px rgba(25,135,84,.4); transform: translateY(-3px); }

        /* ===================================================
           RESPONSIVE — mobile first, scaling up
        ==================================================== */
        @media (min-width: 576px) { .hero-stat-rail { grid-template-columns: repeat(4, 1fr); max-width: 100%; } }
        @media (min-width: 768px) { .why-strip { grid-template-columns: repeat(2, 1fr); } .process-rail { grid-template-columns: repeat(4, 1fr); } }
        @media (min-width: 992px) { .process-rail { grid-template-columns: 1fr auto 1fr auto 1fr auto 1fr; } .process-arrow { display: flex; } .route-strip { display: block; } }
        
        @media (max-width: 575.98px) {
            .transport-header { padding-top: 6rem; padding-bottom: 2.5rem; }
            .hero-stat-rail > div { padding: .75rem .5rem; }
            .stat-value { font-size: 1.15rem; }
            .manifest-card__body, .why-item--large, .quote-panel, .coverage-card { padding: 1.25rem; }
            .process-step { padding: 1.1rem; }
            .coverage-list { grid-template-columns: 1fr 1fr; gap: .5rem .4rem; }
            .bio-mobile-cta { display: flex; }
            body { padding-bottom: 66px; }
            .back-to-top { bottom: 76px !important; right: 12px !important;}
        }

        @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .001ms !important; transition-duration: .001ms !important; } }
    </style>
</head>

<body>

    <!-- Navbar -->
    <!-- <div id="navbar"></div> -->
     <?php include __DIR__ . '/navbar.php'; ?>
    <!-- Navbar End -->


    <!-- ============ HERO ============ -->
    <div class="container-fluid page-header transport-header position-relative overflow-hidden">

        <div class="container py-4 py-md-5 position-relative">

            <h6 class="text-uppercase text-warning fw-bold mb-3 animated slideInDown">
                Logistics & Freight
            </h6>

            <h1 class="text-white fw-bold mb-4 animated slideInDown">
                Transport <span class="text-success">Services</span>
            </h1>

            <p class="text-light fs-5 mb-0" style="max-width: 700px;">
                32 ft. single & multi axle container trucks and open body trucks, moving cargo safely and on schedule across North-East India.
            </p>

            <div class="hero-stat-rail">
                <div>
                    <div class="stat-value" data-count="32">0</div>
                    <div class="stat-label">Ft. Trucks</div>
                </div>
                <div>
                    <div class="stat-value" data-count="8" data-suffix="+">0+</div>
                    <div class="stat-label">States Served</div>
                </div>
                <div>
                    <div class="stat-value" data-count="24" data-suffix="×7">0×7</div>
                    <div class="stat-label">Dispatch Support</div>
                </div>
                <div>
                    <div class="stat-value" data-count="3">0</div>
                    <div class="stat-label">Truck Types</div>
                </div>
            </div>

            <div class="route-strip">
                <div class="route-strip__line"></div>
                <i class="fa fa-truck route-strip__truck"></i>
            </div>

            <div class="d-flex flex-wrap gap-2 mt-4">
                <a href="transport-booking" class="btn btn-success btn-lg px-4">
                    <i class="fa fa-truck me-2"></i> Book Online
                </a>
                <a href="track" class="btn btn-warning btn-lg px-4">
                    <i class="fa fa-map-marker-alt me-2"></i> Track Shipment
                </a>
                <a href="#quote-form" class="btn btn-outline-light btn-lg px-4">
                    <i class="fa fa-paper-plane me-2"></i> Get A Quote
                </a>
                <a href="tel:+919678431656" class="btn btn-outline-light btn-lg px-4">
                    <i class="fa fa-phone me-2"></i> Call Dispatch
                </a>
            </div>

            <nav class="mt-4">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a class="text-white" href="index.php">Home</a></li>
                    <li class="breadcrumb-item text-white active">Transport Services</li>
                </ol>
            </nav>

        </div>

    </div>
    <!-- Hero End -->

    <!-- Quick track strip -->
    <div class="container-lg" style="margin-top:-34px;position:relative;z-index:3;">
        <form action="track" method="get" class="d-flex flex-wrap gap-2 align-items-center bg-white rounded-4 shadow p-3 border" style="max-width:760px;margin:0 auto;">
            <span class="fw-bold text-success px-2"><i class="fa fa-search-location me-2"></i>Track your cargo</span>
            <input type="text" name="id" class="form-control" style="flex:1;min-width:200px;text-transform:uppercase" placeholder="Enter tracking ID e.g. TRK-26-00001" maxlength="40" aria-label="Tracking ID" required>
            <button type="submit" class="btn btn-success px-4">Track</button>
        </form>
    </div>


    <!-- ============ FLEET ============ -->
    <div class="container-lg py-5">

        <div class="text-center mb-5 reveal-up">
            <div class="section-eyebrow">Our Fleet</div>
            <h2 class="fw-bold mt-2">Three Trucks. Every Kind Of Cargo.</h2>
            <div class="section-divider mx-auto"></div>
        </div>

        <div class="row g-4">

            <!-- Single Axle -->
            <div class="col-12 col-md-6 col-lg-4 reveal-up">
                <div class="manifest-card">
                <div class="manifest-card__art">
                    <span class="manifest-card__tag">UNIT 01</span>
                    <img src="img/truck-single-axle.png" alt="32 ft Single Axle Container Truck" class="truck-image">
                </div>
                <div class="manifest-card__body">
                    <h4>32 ft. Single Axle</h4>
                    <p class="text-muted mb-3">Container truck for standard long-haul freight needing weather-protected, secure transit across North-East routes.</p>
                    <ul class="manifest-card__specs">
                        <li><span>Length</span><span>32 ft.</span></li>
                        <li><span>Body Type</span><span>Closed Container</span></li>
                        <li><span>Capacity</span><span>15-18 Tons</span></li>
                        <li><span>Best For</span><span>General Cargo</span></li>
                    </ul>
                    <div class="card-cta">
                        <a href="#quote-form" class="btn btn-outline-success btn-sm">Request This Truck</a>
                    </div>
                </div>
                </div>
            </div>

            <!-- Multi Axle -->
            <div class="col-12 col-md-6 col-lg-4 reveal-up" style="transition-delay: .08s;">
                <div class="manifest-card">
                <div class="manifest-card__art">
                    <span class="manifest-card__tag">UNIT 02</span>
                    <img src="img/truck-multi-axle.png" alt="32 ft Multi Axle Container Truck" class="truck-image">
                </div>
                <div class="manifest-card__body">
                    <h4>32 ft. Multi Axle</h4>
                    <p class="text-muted mb-3">Extra axles for heavier loads, longer routes and superior stability over hilly terrain and mountain passes.</p>
                    <ul class="manifest-card__specs">
                        <li><span>Length</span><span>32 ft.</span></li>
                        <li><span>Body Type</span><span>Closed Container</span></li>
                        <li><span>Capacity</span><span>20-25 Tons</span></li>
                        <li><span>Best For</span><span>Heavy / Bulk Loads</span></li>
                    </ul>
                    <div class="card-cta">
                        <a href="#quote-form" class="btn btn-outline-success btn-sm">Request This Truck</a>
                    </div>
                </div>
                </div>
            </div>

            <!-- Open Body -->
            <div class="col-12 col-md-6 col-lg-4 reveal-up" style="transition-delay: .16s;">
                <div class="manifest-card">
                <div class="manifest-card__art">
                    <span class="manifest-card__tag">UNIT 03</span>
                    <img src="img/truck-open-body.png" alt="32 ft Open Body Truck" class="truck-image">
                </div>
                <div class="manifest-card__body">
                    <h4>32 ft. Open Body</h4>
                    <p class="text-muted mb-3">Open deck for oversized, bulky or industrial cargo that's easiest to load from above or the side without container constraints.</p>
                    <ul class="manifest-card__specs">
                        <li><span>Length</span><span>32 ft.</span></li>
                        <li><span>Body Type</span><span>Open Deck</span></li>
                        <li><span>Capacity</span><span>18-22 Tons</span></li>
                        <li><span>Best For</span><span>Bulk / Oversized Goods</span></li>
                    </ul>
                    <div class="card-cta">
                        <a href="#quote-form" class="btn btn-outline-success btn-sm">Request This Truck</a>
                    </div>
                </div>
                </div>
            </div>

        </div>
    </div>
    <!-- Fleet End -->


    <!-- ============ WHY CHOOSE US ============ -->
    <div class="container-lg py-5">
        <div class="text-center mb-5 reveal-up">
            <div class="section-eyebrow">Why Choose Biome Enterprises</div>
            <h2 class="fw-bold mt-2">What Sets Our Service Apart</h2>
            <div class="section-divider mx-auto"></div>
            <p class="text-muted mt-3" style="max-width: 700px; margin-left: auto; margin-right: auto;">
                We combine industry expertise, modern fleet management, and 24/7 customer support to deliver logistics solutions that exceed expectations.
            </p>
        </div>

        <div class="why-strip reveal-up">

            <!-- Safe Handling -->
            <div class="why-item why-item--large">
                <div class="why-item__icon"><i class="fa fa-shield-alt"></i></div>
                <h5>Safe Handling & Secure Transit</h5>
                <p>
                    Our highly trained drivers bring 10+ years of experience navigating challenging North-East terrain. Every truck is equipped with modern GPS tracking, cargo securing systems, and safety equipment. We guarantee damage-free delivery with our industry-leading
                    track record of zero cargo loss incidents.
                </p>
                <ul class="why-item__features">
                    <li><i class="fa fa-check-circle"></i> Professional driver training & certification</li>
                    <li><i class="fa fa-check-circle"></i> GPS-equipped vehicles with live tracking</li>
                    <li><i class="fa fa-check-circle"></i> Advanced cargo securing equipment</li>
                    <li><i class="fa fa-check-circle"></i> Damage-free delivery guarantee</li>
                    <li><i class="fa fa-check-circle"></i> Insurance coverage up to ₹50 lakhs</li>
                    <li><i class="fa fa-check-circle"></i> 24/7 driver monitoring & support</li>
                </ul>
            </div>

            <!-- On-Time Dispatch -->
            <div class="why-item why-item--large" style="transition-delay: 0.08s;">
                <div class="why-item__icon"><i class="fa fa-clock"></i></div>
                <h5>On-Time Dispatch & Reliability</h5>
                <p>
                    Punctuality is our promise. We use advanced route optimization algorithms to plan efficient paths considering weather, terrain, and traffic patterns. Every shipment has scheduled checkpoints and real-time updates, ensuring your cargo arrives exactly when
                    promised with zero delays.
                </p>
                <ul class="why-item__features">
                    <li><i class="fa fa-check-circle"></i> AI-powered route optimization</li>
                    <li><i class="fa fa-check-circle"></i> Real-time GPS tracking & notifications</li>
                    <li><i class="fa fa-check-circle"></i> On-time delivery guarantee (99.8%)</li>
                    <li><i class="fa fa-check-circle"></i> Scheduled checkpoint updates</li>
                    <li><i class="fa fa-check-circle"></i> Delay compensation clause</li>
                    <li><i class="fa fa-check-circle"></i> Weather-aware routing system</li>
                </ul>
            </div>

            <!-- Transparent Pricing -->
            <div class="why-item why-item--large" style="transition-delay: 0.16s;">
                <div class="why-item__icon"><i class="fa fa-rupee-sign"></i></div>
                <h5>Transparent Pricing & No Hidden Costs</h5>
                <p>
                    What you see is what you pay. Our pricing is broken down by distance, vehicle type, cargo weight, and special requirements. No surprise charges, no hidden fees, no last-minute additions. Bulk shipments get special discounts and long-term partnerships
                    enjoy premium rates.
                </p>
                <ul class="why-item__features">
                    <li><i class="fa fa-check-circle"></i> Clear, itemized pricing structure</li>
                    <li><i class="fa fa-check-circle"></i> Route & distance-based quotes</li>
                    <li><i class="fa fa-check-circle"></i> Zero hidden charges guaranteed</li>
                    <li><i class="fa fa-check-circle"></i> Bulk shipment discounts (5-20%)</li>
                    <li><i class="fa fa-check-circle"></i> Volume-based pricing plans</li>
                    <li><i class="fa fa-check-circle"></i> Competitive rate matching</li>
                </ul>
            </div>

            <!-- 24/7 Support -->
            <div class="why-item why-item--large" style="transition-delay: 0.24s;">
                <div class="why-item__icon"><i class="fa fa-headset"></i></div>
                <h5>24×7 Dispatch Desk & Support</h5>
                <p>
                    Our dedicated dispatch team never sleeps. Round-the-clock customer support handles last-minute changes, emergency routes, shipment queries, and problem resolution. Whether it's midnight or morning, we're here to assist with real-time updates and instant
                    communication.
                </p>
                <ul class="why-item__features">
                    <li><i class="fa fa-check-circle"></i> Round-the-clock dispatch support</li>
                    <li><i class="fa fa-check-circle"></i> Emergency routing & expedited dispatch</li>
                    <li><i class="fa fa-check-circle"></i> Live shipment status updates</li>
                    <li><i class="fa fa-check-circle"></i> Instant booking modifications</li>
                    <li><i class="fa fa-check-circle"></i> Multi-language customer support</li>
                    <li><i class="fa fa-check-circle"></i> WhatsApp & call support available</li>
                </ul>
            </div>

        </div>
    </div>
    <!-- Why End -->


    <!-- ============ HOW BOOKING WORKS ============ -->
    <div class="container-lg py-5">

        <div class="text-center mb-5 reveal-up">
            <div class="section-eyebrow">Booking Process</div>
            <h2 class="fw-bold mt-2">From Call To Delivery</h2>
            <div class="section-divider mx-auto"></div>
        </div>

        <div class="process-rail reveal-up">

            <div class="process-step">
                <div class="process-step__code">STEP 01</div>
                <h6>Share Cargo Details</h6>
                <p>Tell us the load type, weight and pickup-drop locations.</p>
            </div>

            <div class="process-arrow"><i class="fa fa-chevron-right"></i></div>

            <div class="process-step">
                <div class="process-step__code">STEP 02</div>
                <h6>Get A Quotation</h6>
                <p>We match the right truck and send a transparent rate.</p>
            </div>

            <div class="process-arrow"><i class="fa fa-chevron-right"></i></div>

            <div class="process-step">
                <div class="process-step__code">STEP 03</div>
                <h6>Confirm & Load</h6>
                <p>Truck arrives on schedule for loading and dispatch.</p>
            </div>

            <div class="process-arrow"><i class="fa fa-chevron-right"></i></div>

            <div class="process-step">
                <div class="process-step__code">STEP 04</div>
                <h6>Track To Delivery</h6>
                <p>Stay updated until your cargo reaches its destination.</p>
            </div>

        </div>
    </div>
    <!-- Process End -->


    <!-- ============ COVERAGE + TRUST ============ -->
    <div class="container-lg py-5">
        <div class="row g-4">

            <div class="col-lg-5 reveal-up">
                <div class="coverage-card">
                    <div class="section-eyebrow" style="color:#d7f5e6;">Coverage</div>
                    <h3 class="mt-2" style="color: #D4AF37;">Pan North-East India</h3>
                    <p class="mb-0" style="color:#d7f5e6;">Our trucks regularly cover freight routes across all eight North-Eastern states with reliable service and on-time delivery.</p>
                    <ul class="coverage-list">
                        <li><i class="fa fa-map-marker-alt"></i>Assam Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Meghalaya Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Tripura Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Mizoram Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Manipur Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Nagaland Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Arunachal Pradesh Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Sikkim Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Uttar Pradesh Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Uttarakhand Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Punjab Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Haryana Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Delhi NCR Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Gujarat Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Madhya Pradesh Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Maharashtra Operations</li>
                        <li><i class="fa fa-map-marker-alt"></i>Pan-India Service Network</li>
                    </ul>
                </div>
            </div>

            <div class="col-lg-7 reveal-up" style="transition-delay:.1s;">
                <div class="row g-4 h-100">
                    <div class="col-sm-6">
                        <div class="trust-card">
                            <div class="stars"><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i></div>
                            <p>"Booked a multi axle container on short notice and the truck reached right on time. Smooth experience."</p>
                            <p class="trust-card__name mb-0">Rituraj Saikia</p>
                            <span class="trust-card__role">Paper Mill Supplier</span>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="trust-card">
                            <div class="stars"><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i></div>
                            <p>"Needed an open body truck for oversized machinery — loading and unloading was quick and hassle-free."</p>
                            <p class="trust-card__name mb-0">Pranjal Bora</p>
                            <span class="trust-card__role">Construction Contractor</span>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="trust-card">
                            <div class="stars"><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i></div>
                            <p>"Transparent pricing, no surprise charges. Our go-to vendor for inter-state freight now."</p>
                            <p class="trust-card__name mb-0">Manashree Devi</p>
                            <span class="trust-card__role">Wholesale Trader</span>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="trust-card">
                            <div class="stars"><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star"></i><i class="fa fa-star-half-alt"></i></div>
                            <p>"Dispatch desk picked up at 11pm and rerouted our truck the same night. Real 24x7 support."</p>
                            <p class="trust-card__name mb-0">Imran Hussain</p>
                            <span class="trust-card__role">Logistics Manager</span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <!-- Coverage End -->


    <!-- ============ REQUEST A QUOTE ============ -->
    <div id="quote-form" class="container-fluid bg-light py-5">
        <div class="container-lg py-4">

            <div class="row g-5 align-items-start">

                <div class="col-lg-5 reveal-up">
                    <div class="section-eyebrow">Request A Truck</div>
                    <h2 class="fw-bold mt-2 mb-3">Get A Freight Quotation</h2>
                    <p class="text-muted mb-4">Share your cargo and route details — our dispatch desk will respond with the right truck and a clear quote within 2 hours.</p>

                    <div class="d-flex align-items-start mb-3">
                        <i class="fa fa-phone-alt text-success me-3 mt-1"></i>
                        <div>
                            <strong>Call Dispatch</strong>
                            <div>
                                <a href="tel:+919678431656" class="text-muted text-decoration-none">
                +91 96784 31656
            </a>
                            </div>
                        </div>
                    </div>
                    <div class="d-flex align-items-start">
                        <i class="fa fa-map-marker-alt text-success me-3 mt-1"></i>
                        <div>
                            <strong>Based In</strong>
                            <div class="text-muted">Assam, India</div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7 reveal-up" style="transition-delay:.1s;">
                    <div class="quote-panel">
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

                        <form method="post" action="" id="bioQuoteForm" novalidate>
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
                                    <span class="char-counter-bio"><span id="bioMsgCount">0</span>/2000</span>
                                </div>
                                <div class="col-12">
                                    <button class="btn btn-primary w-100 py-3" type="submit" id="bioSubmitBtn">
                                        <span class="spinner-border spinner-border-sm me-2 d-none" role="status" aria-hidden="true" id="bioSubmitSpinner"></span>
                                        <span id="bioSubmitLabel">Request Consultation</span>
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <!-- Quote End -->


    <!-- Footer -->
    <!-- <div id="footer"></div> -->
     <?php include __DIR__ . '/footer.php'; ?>
    <!-- Footer End -->

 
    
    <!-- ===================================================
         PAGE SCRIPT — interactivity for the redesigned,
         self-contained transport page. The contact form
         submits normally to PHP (no preventDefault), with
         a lightweight loading state and inline validation
         layered on top.
    ==================================================== -->
    <script>
        // window.addEventListener("scroll", function() {
        //     const nav = document.querySelector(".navbar");
        //     if (nav) {
        //         nav.classList.toggle("scrolled", window.scrollY > 50);
        //     }
        // });

        (function() {
            'use strict';

            /* ---------- Counter animation ---------- */
            function initCounters() {
                const counters = document.querySelectorAll('[data-count]');
                if (!counters.length) return;

                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            animateCounter(entry.target);
                            observer.unobserve(entry.target);
                        }
                    });
                }, { threshold: 0.5 });

                counters.forEach(c => observer.observe(c));
            }

            function animateCounter(el) {
                const target = parseInt(el.getAttribute('data-count'), 10);
                const suffix = el.getAttribute('data-suffix') || '';
                const duration = 1400;
                const start = performance.now();

                function step(now) {
                    const progress = Math.min((now - start) / duration, 1);
                    const current = Math.floor(target * progress);
                    el.textContent = current + suffix;
                    if (progress < 1) requestAnimationFrame(step);
                    else el.textContent = target + suffix;
                }
                requestAnimationFrame(step);
            }

            /* ---------- Scroll reveal ---------- */
            function initReveal() {
                const elements = document.querySelectorAll('.reveal-up');
                if (!elements.length) return;

                const observer = new IntersectionObserver((entries) => {
                    entries.forEach(entry => {
                        if (entry.isIntersecting) {
                            entry.target.classList.add('is-visible');
                        }
                    });
                }, { threshold: 0.1 });

                elements.forEach(el => observer.observe(el));
            }

            /* ---------- Manifest card click pulse (touch + click friendly) ---------- */
            function initManifestCards() {
                document.querySelectorAll('.manifest-card').forEach(card => {
                    card.addEventListener('click', (e) => {
                        if (e.target.closest('a')) return; // don't fight the CTA link
                        const specs = card.querySelector('.manifest-card__specs');
                        if (specs) {
                            specs.style.animation = 'none';
                            requestAnimationFrame(() => {
                                specs.style.animation = 'bio-pulse .5s ease';
                            });
                        }
                    });
                });
            }

            /* ---------- Why item icon bounce ---------- */
            function initWhyItems() {
                document.querySelectorAll('.why-item__icon').forEach(icon => {
                    const item = icon.closest('.why-item--large');
                    if (!item) return;
                    item.addEventListener('mouseenter', () => {
                        icon.style.animation = 'bio-bounce .6s ease';
                    });
                    item.addEventListener('mouseleave', () => {
                        icon.style.animation = 'none';
                    });
                });
            }

            /* ---------- Coverage list icon spin ---------- */
            function initCoverageList() {
                document.querySelectorAll('.coverage-list li').forEach(li => {
                    const icon = li.querySelector('i');
                    if (!icon) return;
                    li.addEventListener('mouseenter', () => icon.style.animation = 'bio-spin .6s ease');
                    li.addEventListener('mouseleave', () => icon.style.animation = 'none');
                });
            }

            /* ---------- Trust card star pulse ---------- */
            function initTrustCards() {
                document.querySelectorAll('.trust-card').forEach(card => {
                    const stars = card.querySelector('.stars');
                    if (!stars) return;
                    card.addEventListener('mouseenter', () => stars.style.animation = 'bio-star .6s ease');
                    card.addEventListener('mouseleave', () => stars.style.animation = 'none');
                });
            }

            /* ---------- Quote form: live char counter + validation + loading state ---------- */
            function initQuoteForm() {
                const form = document.getElementById('bioQuoteForm');
                if (!form) return;

                const msgField = document.getElementById('message');
                const msgCount = document.getElementById('bioMsgCount');

                function updateCount() {
                    if (msgField && msgCount) msgCount.textContent = msgField.value.length;
                }
                if (msgField) {
                    updateCount();
                    msgField.addEventListener('input', updateCount);
                }

                function markField(field, valid) {
                    field.classList.toggle('is-invalid-bio', !valid);
                }

                const nameField = document.getElementById('name');
                const emailField = document.getElementById('email');

                if (nameField) {
                    nameField.addEventListener('blur', () => markField(nameField, nameField.value.trim().length > 0));
                    nameField.addEventListener('input', () => {
                        if (nameField.value.trim().length > 0) markField(nameField, true);
                    });
                }

                if (emailField) {
                    const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    emailField.addEventListener('blur', () => markField(emailField, pattern.test(emailField.value.trim())));
                }

                const submitBtn = document.getElementById('bioSubmitBtn');
                const spinner = document.getElementById('bioSubmitSpinner');
                const label = document.getElementById('bioSubmitLabel');

                form.addEventListener('submit', function(e) {
                    let valid = true;

                    if (nameField) {
                        const ok = nameField.value.trim().length > 0;
                        markField(nameField, ok);
                        if (!ok) valid = false;
                    }
                    if (emailField) {
                        const pattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        const ok = pattern.test(emailField.value.trim());
                        markField(emailField, ok);
                        if (!ok) valid = false;
                    }

                    if (!valid) {
                        e.preventDefault();
                        const firstInvalid = form.querySelector('.is-invalid-bio');
                        if (firstInvalid) {
                            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            firstInvalid.focus({ preventScroll: true });
                        }
                        return;
                    }

                    // Valid — let the form submit to PHP normally, just show a loading state.
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        if (spinner) spinner.classList.remove('d-none');
                        if (label) label.textContent = 'Sending…';
                    }
                });
            }

            /* ---------- Smooth scroll for in-page anchors ---------- */
            function initSmoothScroll() {
                document.querySelectorAll('a[href^="#"]').forEach(link => {
                    link.addEventListener('click', (e) => {
                        const href = link.getAttribute('href');
                        if (!href || href === '#') return;
                        const target = document.querySelector(href);
                        if (target) {
                            e.preventDefault();
                            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }
                    });
                });
            }

            /* ---------- Back to top ---------- */
            function initBackToTop() {
                const btn = document.querySelector('.back-to-top');
                if (!btn) return;
                window.addEventListener('scroll', () => {
                    const visible = window.scrollY > 300;
                    btn.style.opacity = visible ? '1' : '0';
                    btn.style.pointerEvents = visible ? 'auto' : 'none';
                });
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    window.scrollTo({ top: 0, behavior: 'smooth' });
                });
            }

            /* ---------- Active nav highlight ---------- */
            // function updateActiveNav() {
            //     document.querySelectorAll('.nav-link').forEach(link => {
            //         link.classList.toggle('active', link.href && link.href.includes('transport'));
            //     });
            // }

            function init() {
                initCounters();
                initReveal();
                initManifestCards();
                initWhyItems();
                initCoverageList();
                initTrustCards();
                initQuoteForm();
                initSmoothScroll();
                initBackToTop();
                // updateActiveNav();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }

            if (typeof WOW !== 'undefined') {
                new WOW().init();
            }
        })();
    </script>
</body>

</html>