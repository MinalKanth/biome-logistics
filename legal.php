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

    <link href="css/style-accounting.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">

</head>

<body>

    <!-- Navbar -->
    <!-- <div id="navbar"></div> -->
    <?php include __DIR__ . '/navbar.php'; ?>
    <!-- Navbar End -->



    <!-- ==============================
            PAGE HEADER
    ===============================-->

    <div class="container-fluid page-header bamboo-header position-relative overflow-hidden py-5">

        <div class="container py-5">

            <div class="row align-items-center">

                <div class="col-lg-8">

                    <h6 class="text-uppercase text-success fw-bold mb-3 animated slideInDown">

                        Business Compliance Experts

                    </h6>

                    <h1 class="display-3 text-white fw-bold mb-4 animated slideInDown">

                        Legal &
                        <span class="text-success">
                            Accounting Services
                        </span>

                    </h1>

                    <p class="text-light fs-5 mb-4 wow fadeInUp">

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

        <div class="text-center mb-5">

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

        <div class="row g-4">

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

        <div class="row align-items-center">

            <div class="col-lg-6">

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

            <div class="col-lg-6">

                <!-- Part 2 continues from here -->
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

                                    <h2 class="text-success fw-bold">

                                        100+

                                    </h2>

                                    <p class="mb-0">

                                        Businesses Assisted

                                    </p>

                                </div>

                            </div>

                            <div class="col-6">

                                <div class="border rounded-4 p-3 text-center">

                                    <h2 class="text-success fw-bold">

                                        20+

                                    </h2>

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

                                    <h2 class="text-success fw-bold">

                                        100%

                                    </h2>

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

            <div class="text-center mb-5">

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

                <div class="col-lg-6">

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

                <div class="col-lg-6 mt-4 mt-lg-0">

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

            <div class="col-lg-6">

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

            <div class="col-lg-6">

                <!-- PART 3 STARTS FROM HERE -->
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

        <div class="text-center mb-5">

            <h6 class="text-success text-uppercase">
                Frequently Asked Questions
            </h6>

            <h2 class="display-5 fw-bold">
                Common Questions
            </h2>

        </div>

        <div class="accordion" id="faqAccordion">

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

        <div class="container text-center">

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

    <!-- <div id="footer"></div> -->
    <?php include __DIR__ . '/footer.php'; ?>



    <!-- Back To Top -->

    <a href="#" class="btn btn-lg btn-primary btn-lg-square rounded-0 back-to-top">

        <i class="bi bi-arrow-up"></i>

    </a>





</body>

</html>


</div>

</div>

</div>











<!-- JavaScript Libraries -->

<script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>

<script src="lib/wow/wow.min.js"></script>

<script src="lib/easing/easing.min.js"></script>

<script src="lib/waypoints/waypoints.min.js"></script>

<script src="lib/counterup/counterup.min.js"></script>

<script src="lib/owlcarousel/owl.carousel.min.js"></script>

<script src="js/main.js"></script>

<script src="js/navbar-active-state.js"></script>



<script>
    // fetch("navbar.php")
    //     .then(response => response.text())
    //     .then(data => {

    //         document.getElementById("navbar").innerHTML = data;

    //         document.querySelectorAll(".dropdown-toggle").forEach(function(el) {

    //             new bootstrap.Dropdown(el);

    //         });

    //     });

    // fetch("footer.php")
    //     .then(response => response.text())
    //     .then(data => {

    //         document.getElementById("footer").innerHTML = data;

    //     });

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

    new WOW().init();



    document.addEventListener("DOMContentLoaded", function() {

        /*====================================================
            INITIALIZE WOW
        ====================================================*/

        if (typeof WOW !== "undefined") {
            new WOW().init();
        }


        /*====================================================
            SCROLL PROGRESS BAR
        ====================================================*/

        const progress = document.createElement("div");

        progress.id = "scrollProgress";

        progress.style.position = "fixed";
        progress.style.top = "0";
        progress.style.left = "0";
        progress.style.height = "4px";
        progress.style.width = "0%";
        progress.style.background = "#198754";
        progress.style.zIndex = "99999";
        progress.style.transition = "width .15s linear";

        document.body.appendChild(progress);

        window.addEventListener("scroll", function() {

            let scroll =
                (window.scrollY /
                    (document.body.scrollHeight - window.innerHeight)) * 100;

            progress.style.width = scroll + "%";

        });


        /*====================================================
            PARALLAX HEADER
        ====================================================*/

        const header = document.querySelector(".page-header");

        window.addEventListener("scroll", function() {

            if (header) {

                header.style.backgroundPositionY =
                    window.scrollY * 0.45 + "px";

            }

        });


        /*====================================================
            NAVBAR SHADOW
        ====================================================*/

        window.addEventListener("scroll", function() {

            const nav = document.querySelector(".navbar");

            if (!nav) return;

            if (window.scrollY > 80) {

                nav.style.background = "#ffffff";
                nav.style.boxShadow = "0 12px 35px rgba(0,0,0,.08)";
                nav.style.transition = ".35s";

            } else {

                nav.style.background = "";
                nav.style.boxShadow = "";

            }

        });


        /*====================================================
            FLOATING BUTTONS
        ====================================================*/

        document.querySelectorAll(".btn").forEach(btn => {

            btn.addEventListener("mouseenter", function() {

                this.style.transform = "translateY(-5px) scale(1.03)";

            });

            btn.addEventListener("mouseleave", function() {

                this.style.transform = "";

            });

        });


        /*====================================================
            RIPPLE EFFECT
        ====================================================*/

        document.querySelectorAll(".btn").forEach(button => {

            button.style.position = "relative";
            button.style.overflow = "hidden";

            button.addEventListener("click", function(e) {

                const ripple = document.createElement("span");

                const size = Math.max(this.clientWidth, this.clientHeight);

                ripple.style.width = ripple.style.height = size + "px";

                ripple.style.position = "absolute";

                ripple.style.borderRadius = "50%";

                ripple.style.background =
                    "rgba(255,255,255,.4)";

                ripple.style.left =
                    e.offsetX - size / 2 + "px";

                ripple.style.top =
                    e.offsetY - size / 2 + "px";

                ripple.style.transform = "scale(0)";

                ripple.style.animation =
                    "ripple .6s linear";

                this.appendChild(ripple);

                setTimeout(() => {

                    ripple.remove();

                }, 600);

            });

        });

        const rippleStyle = document.createElement("style");

        rippleStyle.innerHTML = `

@keyframes ripple{

0%{

transform:scale(0);

opacity:.8;

}

100%{

transform:scale(4);

opacity:0;

}

}`;

        document.head.appendChild(rippleStyle);


        /*====================================================
            CARD HOVER TILT
        ====================================================*/

        document.querySelectorAll(".rounded-4").forEach(card => {

            card.addEventListener("mousemove", function(e) {

                const rect = this.getBoundingClientRect();

                const x = e.clientX - rect.left;

                const y = e.clientY - rect.top;

                const rotateY =
                    ((x / rect.width) - .5) * 14;

                const rotateX =
                    ((y / rect.height) - .5) * -14;

                this.style.transform =
                    `perspective(900px)
                 rotateX(${rotateX}deg)
                 rotateY(${rotateY}deg)
                 translateY(-8px)`;

            });

            card.addEventListener("mouseleave", function() {

                this.style.transform =
                    "perspective(900px) rotateX(0) rotateY(0)";

            });

        });


        /*====================================================
            SPOTLIGHT EFFECT
        ====================================================*/

        document.querySelectorAll(".rounded-4").forEach(card => {

            card.addEventListener("mousemove", function(e) {

                const rect = this.getBoundingClientRect();

                const x = e.clientX - rect.left;

                const y = e.clientY - rect.top;

                this.style.background =
                    `radial-gradient(circle at ${x}px ${y}px,
                rgba(25,135,84,.15),
                #ffffff 70%)`;

            });

            card.addEventListener("mouseleave", function() {

                this.style.background = "#ffffff";

            });

        });


        /*====================================================
            REVEAL ON SCROLL
        ====================================================*/

        const reveals =
            document.querySelectorAll(
                ".rounded-4,.list-group,.accordion-item,.contact-form"
            );

        const revealSection = function() {

            reveals.forEach(item => {

                const top = item.getBoundingClientRect().top;

                if (top < window.innerHeight - 100) {

                    item.style.opacity = "1";

                    item.style.transform =
                        "translateY(0)";

                }

            });

        };

        reveals.forEach(item => {

            item.style.opacity = "0";

            item.style.transform = "translateY(40px)";

            item.style.transition =
                ".7s ease";

        });

        revealSection();

        window.addEventListener("scroll", revealSection);


        /*====================================================
            ICON FLOAT
        ====================================================*/

        document.querySelectorAll("i").forEach(icon => {

            icon.addEventListener("mouseenter", function() {

                this.style.transform =
                    "translateY(-8px) scale(1.15)";

            });

            icon.addEventListener("mouseleave", function() {

                this.style.transform = "";

            });

        });


        /*====================================================
            COUNTER
        ====================================================*/

        document.querySelectorAll("h2").forEach(counter => {

            const value =
                parseInt(counter.innerText);

            if (isNaN(value)) return;

            let start = 0;

            const speed = value / 70;

            const update = () => {

                start += speed;

                if (start < value) {

                    counter.innerText =
                        Math.floor(start);

                    requestAnimationFrame(update);

                } else {

                    counter.innerText =
                        value + "+";

                }

            };

            const observer = new IntersectionObserver(entries => {

                entries.forEach(entry => {

                    if (entry.isIntersecting) {

                        update();

                        observer.disconnect();

                    }

                });

            });

            observer.observe(counter);

        });


    });


    document.addEventListener("DOMContentLoaded", function() {

        /*====================================================
            BACK TO TOP BUTTON
        ====================================================*/

        const backToTop = document.querySelector(".back-to-top");

        if (backToTop) {

            window.addEventListener("scroll", function() {

                if (window.scrollY > 350) {

                    backToTop.style.opacity = "1";
                    backToTop.style.visibility = "visible";
                    backToTop.style.transform = "translateY(0)";

                } else {

                    backToTop.style.opacity = "0";
                    backToTop.style.visibility = "hidden";
                    backToTop.style.transform = "translateY(40px)";

                }

            });

        }


        /*====================================================
            FLOATING LABEL EFFECT
        ====================================================*/

        document.querySelectorAll(".form-control,.form-select").forEach(input => {

            input.addEventListener("focus", function() {

                this.style.transform = "translateY(-3px)";
                this.style.boxShadow = "0 0 20px rgba(25,135,84,.18)";

            });

            input.addEventListener("blur", function() {

                this.style.transform = "";
                this.style.boxShadow = "";

            });

        });


        /*====================================================
            SIMPLE FORM VALIDATION
        ====================================================*/

        const form = document.querySelector("form");

        // if (form) {

        //     form.addEventListener("submit", function(e) {

        //         e.preventDefault();

        //         const name = form.querySelector("input[type='text']");
        //         const email = form.querySelector("input[type='email']");
        //         const phone = form.querySelector("input[type='tel']");
        //         const btn = form.querySelector("button");

        //         if (!name.value || !email.value || !phone.value) {

        //             alert("Please fill all required fields.");

        //             return;

        //         }

        //         btn.disabled = true;

        //         const oldText = btn.innerHTML;

        //         btn.innerHTML =
        //             '<i class="fa fa-spinner fa-spin me-2"></i>Submitting...';

        //         setTimeout(function() {

        //             btn.innerHTML =
        //                 '<i class="fa fa-check me-2"></i>Request Submitted';

        //             btn.classList.remove("btn-success");
        //             btn.classList.add("btn-primary");

        //             setTimeout(function() {

        //                 btn.innerHTML = oldText;

        //                 btn.classList.remove("btn-primary");
        //                 btn.classList.add("btn-success");

        //                 btn.disabled = false;

        //                 form.reset();

        //             }, 2500);

        //         }, 1800);

        //     });

        // }


        /*====================================================
            FAQ ICON ANIMATION
        ====================================================*/

        document.querySelectorAll(".accordion-button").forEach(item => {

            item.addEventListener("click", function() {

                this.classList.toggle("active");

            });

        });


        /*====================================================
            SERVICE CARD GLOW
        ====================================================*/

        document.querySelectorAll(".rounded-4").forEach(card => {

            card.addEventListener("mouseenter", function() {

                this.style.boxShadow =
                    "0 25px 60px rgba(25,135,84,.20)";

            });

            card.addEventListener("mouseleave", function() {

                this.style.boxShadow = "";

            });

        });


        /*====================================================
            IMAGE PARALLAX
        ====================================================*/

        const images = document.querySelectorAll("img");

        window.addEventListener("mousemove", function(e) {

            const x = (window.innerWidth / 2 - e.pageX) / 60;

            const y = (window.innerHeight / 2 - e.pageY) / 60;

            images.forEach(img => {

                img.style.transform =
                    `translate(${x}px,${y}px)`;

            });

        });


        /*====================================================
            BUTTON MAGNET EFFECT
        ====================================================*/

        document.querySelectorAll(".btn").forEach(button => {

            button.addEventListener("mousemove", function(e) {

                const rect = this.getBoundingClientRect();

                const x = e.clientX - rect.left - rect.width / 2;

                const y = e.clientY - rect.top - rect.height / 2;

                this.style.transform =
                    `translate(${x * .15}px,${y * .15}px)`;

            });

            button.addEventListener("mouseleave", function() {

                this.style.transform = "";

            });

        });


        /*====================================================
            FADE IN PAGE
        ====================================================*/

        document.body.style.opacity = "0";

        document.body.style.transition = "opacity .8s";

        window.onload = function() {

            document.body.style.opacity = "1";

        };


        /*====================================================
            FLOATING PARTICLES
        ====================================================*/

        for (let i = 0; i < 15; i++) {

            let particle = document.createElement("span");

            particle.style.position = "fixed";
            particle.style.width = "8px";
            particle.style.height = "8px";
            particle.style.background = "rgba(25,135,84,.15)";
            particle.style.borderRadius = "50%";
            particle.style.left = Math.random() * 100 + "%";
            particle.style.top = Math.random() * 100 + "%";
            particle.style.pointerEvents = "none";
            particle.style.zIndex = "-1";
            particle.style.animation =
                `particle ${5 + Math.random() * 5}s linear infinite`;

            document.body.appendChild(particle);

        }

        const particleStyle = document.createElement("style");

        particleStyle.innerHTML = `

@keyframes particle{

0%{

transform:translateY(0) scale(1);

opacity:.4;

}

50%{

opacity:1;

}

100%{

transform:translateY(-180px) scale(0);

opacity:0;

}

}`;

        document.head.appendChild(particleStyle);


        /*====================================================
            ACTIVE MENU HIGHLIGHT
        ====================================================*/

        document.querySelectorAll(".navbar-nav .nav-link").forEach(link => {

            if (window.location.href.includes(link.getAttribute("href"))) {

                link.classList.add("active");

            }

        });


        /*====================================================
            SMOOTH ANCHOR SCROLL
        ====================================================*/

        document.querySelectorAll("a[href^='#']").forEach(anchor => {

            anchor.addEventListener("click", function(e) {

                const target = document.querySelector(this.getAttribute("href"));

                if (target) {

                    e.preventDefault();

                    target.scrollIntoView({

                        behavior: "smooth",
                        block: "start"

                    });

                }

            });

        });


        /*====================================================
            PAGE LOADER (OPTIONAL)
        ====================================================*/

        const loader = document.getElementById("pageLoader");

        if (loader) {

            window.addEventListener("load", function() {

                loader.style.opacity = "0";

                setTimeout(function() {

                    loader.remove();

                }, 600);

            });

        }

    });
</script>

</body>

</html>