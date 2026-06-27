<?php
/**
 * Place this block at the very TOP of index.php, before any HTML output.
 *
 * It expects your existing admin panel's config/database.php to be
 * reachable. Adjust the require path below to match where index.php
 * lives relative to your admin-panel folder. Example assumes:
 *
 *   /htdocs/admin/index.php          <- your site, this file
 *   /htdocs/admin/config/database.php <- already exists from the admin panel
 *
 * If your public site is a SEPARATE folder from the admin panel,
 * change the path accordingly (see the comment below).
 */

declare(strict_types=1);

// ---- Adjust this path if index.php is not inside the admin-panel folder ----
require_once __DIR__ . '/admin/config/database.php';

// ---------------------------------------------------------------
// CSRF token for this form (separate, lightweight session - this
// is a public page, so we don't need the full admin session guard,
// just a token to stop cross-site forged submissions).
// ---------------------------------------------------------------
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

                // $quoteFormSuccess = true;
                // // Clear values so the form shows empty after a successful submit.
                // $quoteFormValues = array_fill_keys(array_keys($quoteFormValues), '');
                // // Rotate token so the form can't be resubmitted by hitting back+refresh.
                // $_SESSION['quote_csrf_token'] = bin2hex(random_bytes(32));
                // $quoteCsrfToken = $_SESSION['quote_csrf_token'];
                // Use session flash + redirect (POST/Redirect/GET) so reloading
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
    <title>Biome Enterprises | Home</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

    <!-- Favicon -->
    <link href="img/favicon.ico" rel="icon">

    <!-- Google Web Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Roboto:wght@500;700&display=swap" rel="stylesheet">

    <!-- Icon Font Stylesheet -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Libraries Stylesheet -->
    <link href="lib/animate/animate.min.css" rel="stylesheet">
    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">

    <!-- Customized Bootstrap Stylesheet -->
    <link href="css/bootstrap.min.css" rel="stylesheet">

    <!-- Template Stylesheet -->
    <link href="css/style.css" rel="stylesheet">
    <link href="css/style-custom.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">
</head>


<body>

    <!-- Navbar -->
    <!-- <div id="navbar"></div> -->
     <?php include __DIR__ . '/navbar.php'; ?>
    <!-- Navbar End -->
    <!-- test -->
    <!-- Carousel Start -->

    <div class="container-fluid p-0 pb-5">
        <div class="owl-carousel header-carousel position-relative mb-5">

            <!-- Slide 1 -->
            <div class="owl-carousel-item position-relative">

                <div class="hero-image">
                    <img class="img-fluid lazyload" src="img/carousel-1.png" alt="" loading="lazy">
                </div>

                <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center hero-overlay">
                    <div class="container">
                        <div class="row justify-content-start">
                            <div class="col-10 col-lg-8 hero-content">
                                <h5 class="text-white text-uppercase mb-3 animated slideInDown">
                                    Transport & Logistics Solution
                                </h5>

                                <h1 class="display-3 text-white animated slideInDown mb-4">
                                    NORTHEAST INDIA'S LEADING B2B
                                    <span class="text-primary">LOGISTICS</span> &
                                    <span class="text-primary">BAMBOO FIRM</span>
                                </h1>

                                <p class="fs-5 fw-medium text-white mb-4 pb-2">
                                    Biome Enterprises delivers sophisticated supply chain solutions, industrial bamboo procurement, and corporate fleet oversight across all eight states.
                                </p>

                                <a href="" class="btn btn-success py-md-3 px-md-5 me-3 animated slideInLeft">
                                BOOK A TRUCK
                            </a>

                                <a href="" class="btn btn-secondary py-md-3 px-md-5 animated slideInRight">
                                TRACK CONSOLE
                            </a>

                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Slide 2 -->

            <div class="owl-carousel-item position-relative">

                <div class="hero-image">
                    <img class="img-fluid lazyload" src="img/carousel-2.png" alt="" loading="lazy">
                </div>

                <div class="position-absolute top-0 start-0 w-100 h-100 d-flex align-items-center hero-overlay">
                    <div class="container">
                        <div class="row justify-content-start">
                            <div class="col-10 col-lg-8 hero-content">

                                <h5 class="text-white text-uppercase mb-3 animated slideInDown">
                                    Transport & Logistics Solution
                                </h5>

                                <h1 class="display-3 text-white animated slideInDown mb-4">
                                    NORTHEAST INDIA'S LEADING B2B
                                    <span class="text-primary">LOGISTICS</span> &
                                    <span class="text-primary">BAMBOO FIRM</span>
                                </h1>

                                <p class="fs-5 fw-medium text-white mb-4 pb-2">
                                    Biome Enterprises delivers sophisticated supply chain solutions, industrial bamboo procurement, and corporate fleet oversight across all eight states.
                                </p>

                                <a href="" class="btn btn-primary py-md-3 px-md-5 me-3 animated slideInLeft">
                                BOOK A TRUCK
                            </a>

                                <a href="" class="btn btn-secondary py-md-3 px-md-5 animated slideInRight">
                                TRACK CONSOLE
                            </a>

                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div>
    </div>


    <!-- Carousel End -->


    <!-- About Start -->

    <div class="container-fluid overflow-hidden py-5 px-lg-0">
        <div class="container about py-5 px-lg-0">
            <div class="row g-5 mx-lg-0">
                <div class="col-lg-6 ps-lg-0 wow fadeInLeft" data-wow-delay="0.1s" style="min-height: 400px;">
                    <div class="position-relative h-100">
                        <img class="position-absolute img-fluid lazyload w-100 h-100" src="img/about-us.png" style="object-fit: cover;" alt="">
                    </div>
                </div>
                <div class="col-lg-6 about-text wow fadeInUp" data-wow-delay="0.3s">
                    <h6 class="text-secondary text-uppercase mb-3">Why Choose Us</h6>
                    <h1 class="mb-5">Complete Logistics & Business Solutions Across India</h1>
                    <p class="mb-5">Biome Enterprises is your trusted partner for transportation, bamboo trading, legal compliance, accounting, hospitality, and travel services. With our strategic base in North-East India and a growing Pan India network, we deliver reliable,
                        timely, and cost-effective solutions for businesses and individuals.</p>
                    <div class="row g-4 mb-5">
                        <div class="col-sm-6 wow fadeIn" data-wow-delay="0.5s">
                            <i class="fa fa-globe fa-3x text-primary mb-3"></i>
                            <h5>Pan India Coverage</h5>
                            <p class="m-0">Connecting Assam with major commercial hubs including Delhi, Punjab, Haryana, Uttar Pradesh, Uttarakhand, Gujarat, Maharashtra, Madhya Pradesh, West Bengal, and other key destinations.</p>
                        </div>
                        <div class="col-sm-6 wow fadeIn" data-wow-delay="0.7s">
                            <i class="fa fa-shipping-fast fa-3x text-primary mb-3"></i>
                            <h5>Reliable & On-Time Service</h5>
                            <p class="m-0"> We prioritize timely deliveries, transparent communication, and professional service, ensuring dependable logistics, compliance, and travel solutions every time.</p>
                        </div>
                    </div>
                    <a href="" class="btn btn-primary py-3 px-5">Explore More</a>
                </div>
            </div>
        </div>
    </div>

    <!-- About End -->


    <!-- Fact Start -->

    <div class="container-xxl py-5">
        <div class="container py-5">
            <div class="row g-5">
                <div class="col-lg-6 wow fadeInUp" data-wow-delay="0.1s">
                    <h6 class="text-secondary text-uppercase mb-3">Some Facts</h6>
                    <h1 class="mb-5">Your Trusted Partner for Logistics & Business Solutions Across India</h1>
                    <p class="mb-5">Biome Enterprises provides reliable transportation, bamboo trading, legal & compliance services, accounting, hospitality, and cab booking solutions. Based in Assam, we proudly connect North-East India with major business hubs across
                        the country through dependable, customer-focused services.</p>
                    <div class="d-flex align-items-center">
                        <i class="fa fa-headphones fa-2x flex-shrink-0 bg-primary p-3 text-white"></i>
                        <div class="ps-4">
                            <h6>Call for Any Query</h6>
                            <h3 class="text-primary m-0">+91 96784 31656</h3>
                        </div>
                    </div>
                </div>
                <div class="col-lg-6">
                    <div class="row g-4">

                        <!-- Card 1 -->
                        <div class="col-6">
                            <div class="bg-primary rounded-4 shadow-lg text-center p-4 h-100 wow fadeInUp" data-wow-delay="0.2s">
                                <div class="mb-3">
                                    <i class="fa fa-users fa-3x text-white"></i>
                                </div>
                                <h2 class="text-white fw-bold mb-2">
                                    <span data-toggle="counter-up">100</span>+
                                </h2>
                                <p class="text-white mb-0">Satisfied Clients</p>
                            </div>
                        </div>

                        <!-- Card 2 -->
                        <div class="col-6">
                            <div class="bg-success rounded-4 shadow-lg text-center p-4 h-100 wow fadeInUp" data-wow-delay="0.4s">
                                <div class="mb-3">
                                    <i class="fa fa-truck fa-3x text-white"></i>
                                </div>
                                <h2 class="text-white fw-bold mb-2">
                                    <span data-toggle="counter-up">150</span>+
                                </h2>
                                <p class="text-white mb-0">Fleet & Deliveries</p>
                            </div>
                        </div>

                        <!-- Card 3 -->
                        <div class="col-6">
                            <div class="bg-dark rounded-4 shadow-lg text-center p-4 h-100 wow fadeInUp" data-wow-delay="0.6s">
                                <div class="mb-3">
                                    <i class="fa fa-map-marker fa-3x text-warning"></i>
                                </div>
                                <h2 class="text-white fw-bold mb-2">
                                    <span data-toggle="counter-up">28</span>
                                </h2>
                                <p class="text-white mb-0">States Connected</p>
                            </div>
                        </div>

                        <!-- Card 4 -->
                        <div class="col-6">
                            <div class="bg-secondary rounded-4 shadow-lg text-center p-4 h-100 wow fadeInUp" data-wow-delay="0.8s">
                                <div class="mb-3">
                                    <i class="fa fa-check-circle fa-3x text-white"></i>
                                </div>
                                <h2 class="text-white fw-bold mb-2">
                                    <span data-toggle="counter-up">99</span>%
                                </h2>
                                <p class="text-white mb-0">On-Time Delivery</p>
                            </div>
                        </div>

                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Fact End -->


    <!-- Service Start -->

    <div class="container-xxl py-5">
        <div class="container py-5">
            <div class="text-center wow fadeInUp" data-wow-delay="0.1s">
                <h6 class="text-secondary text-uppercase">Our Services</h6>
                <h1 class="mb-5">Explore Our Services</h1>
                <p class="mb-5">Biome Enterprises delivers integrated supply chain optimization and ethical trade operations across the North-East Indian economic corridor.</p>
            </div>
            <div class="row g-4">

                <!-- Logistics -->
                <div class="col-md-6 col-lg-4 wow fadeInUp" data-wow-delay="0.1s">
                    <div class="service-item p-4">
                        <div class="overflow-hidden mb-4">
                            <img class="img-fluid lazyload" src="img/service01.png" alt="Transportation Services" loading="lazy">
                        </div>
                        <h4 class="mb-3">Transportation & Logistics</h4>
                        <p>Reliable Pan India transportation with 32-ft open-body and multi-axle container trucks, connecting Assam to major industrial hubs.</p>
                        <a class="btn-slide mt-2" href="transportation.php"><i class="fa fa-arrow-right"></i><span>Read More</span></a>
                    </div>
                </div>

                <!-- Bamboo -->
                <div class="col-md-6 col-lg-4 wow fadeInUp" data-wow-delay="0.2s">
                    <div class="service-item p-4">
                        <div class="overflow-hidden mb-4">
                            <img class="img-fluid lazyload" src="img/service02.png" alt="Bamboo Trading" loading="lazy">
                        </div>
                        <h4 class="mb-3">Bamboo Trading</h4>
                        <p>Premium raw bamboo, long bamboo poles, bamboo pieces, handicraft materials, and sustainable bamboo products supplied across India.</p>
                        <a class="btn-slide mt-2" href="bamboo.php"><i class="fa fa-arrow-right"></i><span>Read More</span></a>
                    </div>
                </div>

                <!-- Legal -->
                <div class="col-md-6 col-lg-4 wow fadeInUp" data-wow-delay="0.3s">
                    <div class="service-item p-4">
                        <div class="overflow-hidden mb-4">
                            <img class="img-fluid lazyload" src="img/service03.png" alt="Legal & Compliance" loading="lazy">
                        </div>
                        <h4 class="mb-3">Legal & Compliance</h4>
                        <p>GST, FSSAI, MSME, Company Registration, IEC, Accounting, Taxation, Documentation, and complete business compliance services.</p>
                        <a class="btn-slide mt-2" href="legal.php"><i class="fa fa-arrow-right"></i><span>Read More</span></a>
                    </div>
                </div>

                <!-- Cab -->
                <div class="col-md-6 col-lg-4 wow fadeInUp" data-wow-delay="0.4s">
                    <div class="service-item p-4">
                        <div class="overflow-hidden mb-4">
                            <img class="img-fluid lazyload" src="img/service04.png" alt="Cab Rental" loading="lazy">
                        </div>
                        <h4 class="mb-3">Cab Rental Services</h4>
                        <p>Self-drive cars, chauffeur-driven vehicles, airport transfers, local travel, and corporate rental services across North-East India.</p>
                        <a class="btn-slide mt-2" href="cab.php"><i class="fa fa-arrow-right"></i><span>Read More</span></a>
                    </div>
                </div>

                <!-- Hotel -->
                <div class="col-md-6 col-lg-4 wow fadeInUp" data-wow-delay="0.5s">
                    <div class="service-item p-4">
                        <div class="overflow-hidden mb-4">
                            <img class="img-fluid lazyload" src="img/service05.png" alt="Hotels & Homestays" loading="lazy">
                        </div>
                        <h4 class="mb-3">Hotels & Homestays</h4><span class="text-warning">(Upcoming)</span>
                        <p>Book trusted hotels, hill-station stays, premium homestays, and business accommodations across all eight North-East states.</p>
                        <a class="btn-slide mt-2" href="hotel.php"><i class="fa fa-arrow-right"></i><span>Read More</span></a>
                    </div>
                </div>

                <!-- Restaurant -->
                <div class="col-md-6 col-lg-4 wow fadeInUp" data-wow-delay="0.6s">
                    <div class="service-item p-4">
                        <div class="overflow-hidden mb-4">
                            <img class="img-fluid lazyload" src="img/service06.png" alt="Restaurant" loading="lazy">
                        </div>
                        <h4 class="mb-3">Restaurant & Ethnic Cuisine</h4><span class="text-warning">(Upcoming)</span>
                        <p>Experience authentic North-East cuisine, bamboo shoot delicacies, smoked meats, traditional dishes, and local culinary specialties.</p>
                        <a class="btn-slide mt-2" href="restaurant.php"><i class="fa fa-arrow-right"></i><span>Read More</span></a>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Service End -->


    <!-- Feature Start -->

    <div class="container-fluid overflow-hidden py-5 px-lg-0">
        <div class="container feature py-5 px-lg-0">
            <div class="row g-5 mx-lg-0">
                <div class="col-lg-5 feature-text wow fadeInUp" data-wow-delay="0.1s">
                    <h6 class="text-secondary text-uppercase mb-3">Why Choose Biome Enterprises</h6>
                    <h1 class="mb-5">Complete Business Solutions Under One Roof</h1>

                    <!-- Logistics -->
                    <div class="d-flex mb-5 wow fadeInUp" data-wow-delay="0.2s">
                        <i class="fas fa-truck-moving text-primary fa-3x flex-shrink-0"></i>
                        <div class="ms-4">
                            <h5>Pan India Logistics Network</h5>
                            <p class="mb-0">
                                Reliable freight transportation using 32-ft open-body and multi-axle container trucks connecting Assam with major industrial hubs across India.
                            </p>
                        </div>
                    </div>

                    <!-- Bamboo -->
                    <div class="d-flex mb-5 wow fadeInUp" data-wow-delay="0.4s">
                        <i class="fas fa-seedling text-primary fa-3x flex-shrink-0"></i>
                        <div class="ms-4">
                            <h5>Bamboo Trading Solutions</h5>
                            <p class="mb-0">
                                Supplying premium raw bamboo, long bamboo poles, bamboo pieces, and sustainable bamboo products for industries, construction, and handicrafts.
                            </p>
                        </div>
                    </div>

                    <!-- Legal -->
                    <div class="d-flex mb-5 wow fadeInUp" data-wow-delay="0.6s">
                        <i class="fas fa-balance-scale text-primary fa-3x flex-shrink-0"></i>
                        <div class="ms-4">
                            <h5>Legal & Compliance Services</h5>
                            <p class="mb-0">
                                GST, FSSAI, MSME (Udyam), IEC, Company Registration, Accounting, Taxation, Documentation, and Financial Compliance.
                            </p>
                        </div>
                    </div>

                    <!-- Hospitality -->
                    <div class="d-flex mb-5 wow fadeInUp" data-wow-delay="0.8s">
                        <i class="fas fa-hotel text-primary fa-3x flex-shrink-0"></i>
                        <div class="ms-4">
                            <h5>Hospitality & Travel Services</h5>
                            <p class="mb-0">
                                Book trusted hotels, homestays, restaurants, self-drive cars, chauffeur-driven vehicles, and rental cabs across North-East India.
                            </p>
                        </div>
                    </div>

                    <!-- Support -->
                    <div class="d-flex wow fadeInUp" data-wow-delay="1s">
                        <i class="fas fa-headset text-primary fa-3x flex-shrink-0"></i>
                        <div class="ms-4">
                            <h5>Dedicated Customer Support</h5>
                            <p class="mb-0">
                                Fast quotations, transparent communication, and expert assistance to ensure smooth logistics, compliance, and travel services.
                            </p>
                        </div>
                    </div>

                </div>



                <div class="col-lg-7 pe-lg-0 wow fadeInRight" data-wow-delay="0.1s">
                    <div class="feature-image-wrapper">
    <img class="img-fluid lazyload" src="img/feature.png" alt="Biome Enterprises Features">
</div>
                </div>
            </div>
        </div>
    </div>



    <!-- Feature End -->


    <!-- Quote Start -->

    <div class="container-xxl py-5 quote-section position-relative overflow-hidden">
        <div class="container py-5">
            <div class="row g-5 align-items-center">

                <div class="col-lg-7">
                    <div class="quote-card p-5 wow fadeInRight" data-wow-delay="0.5s" data-tilt data-tilt-max="6" data-tilt-speed="500" data-tilt-glare="true" data-tilt-max-glare="0.25">
                        <form method="post" action="">
                            <input type="hidden" name="csrf_token" value="<?= qf_e($quoteCsrfToken) ?>">
                            <input type="hidden" name="quote_form_submit" value="1">

                            <div class="row g-3">

                                <!-- Name -->
                                <div class="col-md-6">
                                    <input type="text" name="full_name" class="form-control border-0" placeholder="Full Name *"
                                        style="height:55px;" maxlength="150" required
                                        value="<?= qf_e($quoteFormValues['full_name']) ?>">
                                </div>

                                <!-- Mobile -->
                                <div class="col-md-6">
                                    <input type="tel" name="mobile_number" class="form-control border-0" placeholder="Mobile Number *"
                                        style="height:55px;" maxlength="20" required
                                        value="<?= qf_e($quoteFormValues['mobile_number']) ?>">
                                </div>

                                <!-- Email -->
                                <div class="col-md-6">
                                    <input type="email" name="email" class="form-control border-0" placeholder="Email Address"
                                        style="height:55px;" maxlength="150"
                                        value="<?= qf_e($quoteFormValues['email']) ?>">
                                </div>

                                <!-- Location -->
                                <div class="col-md-6">
                                    <input type="text" name="city_state" class="form-control border-0" placeholder="City / State"
                                        style="height:55px;" maxlength="150"
                                        value="<?= qf_e($quoteFormValues['city_state']) ?>">
                                </div>

                                <!-- Service -->
                                <div class="col-md-6">
                                    <select name="service_required" class="form-select border-0" style="height:55px;">
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
                                    <input type="text" name="company_name" class="form-control border-0" placeholder="Company / Business Name"
                                        style="height:55px;" maxlength="150"
                                        value="<?= qf_e($quoteFormValues['company_name']) ?>">
                                </div>

                                <!-- Message -->
                                <div class="col-12">
                                    <textarea name="message" class="form-control border-0" rows="5" maxlength="2000"
                                            placeholder="Describe Your Requirement"><?= qf_e($quoteFormValues['message']) ?></textarea>
                                </div>

                                <!-- Button -->
                                <div class="col-12">
                                    <button class="btn btn-primary w-100 py-3" type="submit">
                                        Request Free Quote
                                    </button>
                                </div>

                            </div>
                        </form>
                    </div>
                </div>
                <div class="col-lg-5 wow fadeInUp" data-wow-delay="0.1s">
                    <h6 class="text-secondary text-uppercase mb-3">Get A Quote</h6>
                    <h1 class="mb-5">Request a Free Consultation & Quotation</h1>
                    <p class="mb-5">Looking for reliable transportation, premium bamboo trading, legal & compliance assistance, hotel bookings, restaurant reservations, or cab rental services? Share your requirements with us and our team will provide a customized solution
                        and competitive quotation tailored to your business or personal needs.</p>
                    <div class="contact-card d-flex align-items-center justify-content-between flex-wrap">

                        <div class="d-flex align-items-center">

                            <div class="contact-icon">
                                <i class="fa fa-headphones"></i>
                            </div>

                            <div class="ms-4">
                                <span class="small text-uppercase text-secondary fw-bold">24/7 Customer Support</span>
                                <h5 class="mb-1 fw-bold">Need Immediate Assistance?</h5>
                                <h3 class="text-primary fw-bold mb-0">
                                    <a href="tel:+919678431656" class="phone-link">
                                        +91 96784 31656
                                    </a>
                                </h3>
                            </div>

                        </div>

                        <a href="https://wa.me/919678431656" target="_blank" class="whatsapp-btn">
                            <i class="fab fa-whatsapp"></i>
                        </a>

                    </div>
                </div>
            </div>
        </div>
    </div>



    <!-- Quote End -->


    <!-- Team Start -->

    <div class="container-xxl py-5 team-section position-relative overflow-hidden">
        <div class="container py-5">
            <div class="text-center wow fadeInUp" data-wow-delay="0.1s">
                <h6 class="text-secondary text-uppercase">Our Team</h6>
                <h1 class="mb-5">Experienced Professionals Behind Every Successful Project</h1>
            </div>
            <div class="row g-4">
                <div class="col-lg-3 col-md-6 wow fadeInUp" data-wow-delay="0.3s">
                    <div class="team-item p-4">
                        <div class="overflow-hidden mb-4"><img class="img-fluid lazyload" src="img/team-1.jpg" alt="" loading="lazy"></div>
                        <h5 class="mb-0">Bittu Ali Hazarika</h5>
                        <p>Managing Director</p>
                        <div class="btn-slide mt-1"><i class="fa fa-share"></i><span><a href=""><i class="fab fa-facebook-f"></i></a><a href=""><i class="fab fa-twitter"></i></a><a href=""><i class="fab fa-instagram"></i></a></span></div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 wow fadeInUp" data-wow-delay="0.5s">
                    <div class="team-item p-4">
                        <div class="overflow-hidden mb-4"><img class="img-fluid lazyload" src="img/team-2.jpg" alt="" loading="lazy"></div>
                        <h5 class="mb-0">Full Name</h5>
                        <p>Operations Head</p>
                        <div class="btn-slide mt-1"><i class="fa fa-share"></i><span><a href=""><i class="fab fa-facebook-f"></i></a><a href=""><i class="fab fa-twitter"></i></a><a href=""><i class="fab fa-instagram"></i></a></span></div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 wow fadeInUp" data-wow-delay="0.7s">
                    <div class="team-item p-4">
                        <div class="overflow-hidden mb-4"><img class="img-fluid lazyload" src="img/team-3.jpg" alt="" loading="lazy"></div>
                        <h5 class="mb-0">Full Name</h5>
                        <p>Legal & Compliance Expert</p>
                        <div class="btn-slide mt-1"><i class="fa fa-share"></i><span><a href=""><i class="fab fa-facebook-f"></i></a><a href=""><i class="fab fa-twitter"></i></a><a href=""><i class="fab fa-instagram"></i></a></span></div>
                    </div>
                </div>
                <div class="col-lg-3 col-md-6 wow fadeInUp" data-wow-delay="0.9s">
                    <div class="team-item p-4">
                        <div class="overflow-hidden mb-4"><img class="img-fluid lazyload" src="img/team-4.jpg" alt="" loading="lazy"></div>
                        <h5 class="mb-0">Full Name</h5>
                        <p>Customer Success Manager</p>
                        <div class="btn-slide mt-1"><i class="fa fa-share"></i><span><a href=""><i class="fab fa-facebook-f"></i></a><a href=""><i class="fab fa-twitter"></i></a><a href=""><i class="fab fa-instagram"></i></a></span></div>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <!-- Team End -->
    <!-- Testimonial Start -->

    <div class="container-xxl py-5 testimonial-section wow fadeInUp" data-wow-delay="0.1s">
        <div class="container py-5">
            <div class="text-center">
                <h6 class="text-secondary text-uppercase">Client Testimonials</h6>
                <h1 class="mb-0">What Our Clients Say</h1>
            </div>

            <div class="owl-carousel testimonial-carousel wow fadeInUp" data-wow-delay="0.1s">

                <!-- Testimonial 1 -->
                <div class="testimonial-item p-4 my-5">
                    <i class="fa fa-quote-right fa-3x text-light position-absolute top-0 end-0 mt-n3 me-4"></i>
                    <div class="d-flex align-items-end mb-4">
                        <img class="img-fluid flex-shrink-0" src="img/testimonial-1.jpg" style="width:80px;height:80px;" loading="lazy">
                        <div class="ms-4">
                            <h5 class="mb-1">Rajesh Sharma</h5>
                            <p class="m-0">Manufacturing Business</p>
                        </div>
                    </div>
                    <p class="mb-0">
                        Biome Enterprises handled our Assam to Delhi freight professionally. Their team provided timely updates and ensured safe delivery throughout the journey.
                    </p>
                    </br>
                </div>

                <!-- Testimonial 2 -->
                <div class="testimonial-item p-4 my-5">
                    <i class="fa fa-quote-right fa-3x text-light position-absolute top-0 end-0 mt-n3 me-4"></i>
                    <div class="d-flex align-items-end mb-4">
                        <img class="img-fluid flex-shrink-0" src="img/testimonial-2.jpg" style="width:80px;height:80px;" loading="lazy">
                        <div class="ms-4">
                            <h5 class="mb-1">Priya Das</h5>
                            <p class="m-0">Food Business Owner</p>
                        </div>
                    </div>
                    <p class="mb-0">
                        Their legal and compliance team completed our GST and FSSAI registration quickly with complete transparency. Highly recommended for startups.
                    </p>
                    </br>
                </div>

                <!-- Testimonial 3 -->
                <div class="testimonial-item p-4 my-5">
                    <i class="fa fa-quote-right fa-3x text-light position-absolute top-0 end-0 mt-n3 me-4"></i>
                    <div class="d-flex align-items-end mb-4">
                        <img class="img-fluid flex-shrink-0" src="img/testimonial-3.jpg" style="width:80px;height:80px;" loading="lazy">
                        <div class="ms-4">
                            <h5 class="mb-1">Amit Verma</h5>
                            <p class="m-0">Corporate Client</p>
                        </div>
                    </div>
                    <p class="mb-0">
                        Excellent cab booking and hotel arrangements for our business trip across North-East India. The service was reliable and hassle-free.
                    </p>
                    </br>
                </div>

                <!-- Testimonial 4 -->
                <div class="testimonial-item p-4 my-5">
                    <i class="fa fa-quote-right fa-3x text-light position-absolute top-0 end-0 mt-n3 me-4"></i>
                    <div class="d-flex align-items-end mb-4">
                        <img class="img-fluid lazyload flex-shrink-0" src="img/testimonial-4.jpg" style="width:80px;height:80px;" loading="lazy">
                        <div class="ms-4">
                            <h5 class="mb-1">Neha Singh</h5>
                            <p class="m-0">Bamboo Industry</p>
                        </div>
                    </div>
                    <p class="mb-0">
                        We source bamboo materials through Biome Enterprises regularly. Their quality, pricing, and logistics support have always exceeded our expectations.
                    </p>
                    </br>
                </div>

            </div>
        </div>
    </div>

    <!-- Testimonial End -->


    <!-- Footer  -->
    <!-- <div id="footer"></div> -->
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
    <script src="lib/counterup/counterup.min.js"></script>
    <script src="lib/owlcarousel/owl.carousel.min.js"></script>

    <!-- Template Javascript -->
    <script src="js/main.js"></script>

    <script src="js/navbar-active-state.js"></script>
    <script>
        // Navbar scroll effect

        window.addEventListener("scroll", function() {
            const nav = document.querySelector(".navbar");
            if (!nav) return;
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

</body>

</html>