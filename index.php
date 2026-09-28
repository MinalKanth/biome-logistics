
<?php  
// ======================================================
// PAGE CONFIG
// ======================================================
define('PAGE', 'home');

// ======================================================
// PRODUCTION ERROR SETTINGS
// ======================================================
error_reporting(0);
ini_set('display_errors', '0');

// ======================================================
// SECURITY, DB & ENTERPRISE SEO ENGINE
// ======================================================
require_once 'protection.php';
require_once 'EliteSeoEngine.php';
include 'include/config.php'; // DB Connection moved to top for global access

// ======================================================
// INITIALIZE SEO ENGINE
// ======================================================
$seo = new EliteEnterpriseSeoEngine();
$seo->sendEnterpriseHeaders(filemtime(__FILE__));

// ======================================================
// META PARAMETERS FOR HOMEPAGE
// ======================================================
$metaParams = [
    'title'       => 'Biome Enterprises | Logistics, Bamboo Trading & Compliance Services',
    'description' => 'Biome Enterprises provides reliable transportation, bamboo trading, legal & compliance services, accounting, hospitality, and cab booking solutions across North-East India.',
    'keywords'    => 'Biome Enterprises, transportation, logistics, bamboo trading, legal services, compliance, accounting, hospitality, cab booking, North-East India, Assam',
    'url_path'    => 'https://biomeenterprises.com/img/logo.png',
    'image_path'  => '/img/logo.png',  
    'type'        => 'website'
];
?>







 <!DOCTYPE html>
<html lang="en">

<head>
    <!-- ===================== ELITE SEO ENGINE HOOKS ===================== -->
 
    <?= $seo->generateMeta($metaParams); ?>
    
    
    <?= $seo->schema($seo->coreGraph()); ?>
    
   
    <?= $seo->schema($seo->localBusinessSchema()); ?>
    <!-- ================================================================== -->

    <!-- Favicons -->
    <link rel="icon" href="img/favicon.ico" type="image/x-icon">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="img/favicon-16x16.png">
    <link rel="apple-touch-icon" sizes="180x180" href="img/apple-touch-icon.png">

    <!-- Google Web Fonts (Preconnects are handled by SEO Engine) -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Roboto:wght@500;700&display=swap" rel="stylesheet">

    <!-- Icon Font Stylesheet -->
    <link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <link rel="preload" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
    <noscript>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css">
    </noscript>

    <!-- Critical Assets Preload -->
    <link rel="preload" as="image" href="img/carousel-1.png" fetchpriority="high">

    <!-- Libraries Stylesheet -->
    <link href="lib/animate/animate.min.css" rel="stylesheet">

    <!-- Customized Bootstrap & Template Stylesheets -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <link href="css/style_custom.css" rel="stylesheet">
</head>

<body>

    <div id="scrollProgress"></div>
    <div id="cursorGlow"></div>



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




    <!-- Navbar -->
     <?php include __DIR__ . '/navbar.php'; ?>
    <!-- Navbar End -->


<?php include __DIR__ . '/slider.php'; ?>




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


  <!-- ===================== Service Start ===================== -->
    <style>
        /* Modern Service Section Enhancements */
        .service-card {
            border-radius: 16px;
            background: #ffffff;
            transition: all 0.35s cubic-bezier(0.25, 0.8, 0.25, 1);
            overflow: hidden;
            border: 1px solid rgba(0, 0, 0, 0.04) !important;
        }

        .service-img-wrapper {
            overflow: hidden;
            border-radius: 12px;
        }

        .service-img-wrapper img {
            aspect-ratio: 16 / 10;
            object-fit: cover;
            width: 100%;
            transition: transform 0.5s ease;
        }

        /* Hover effect only for devices that support hover */
        @media (hover: hover) and (pointer: fine) {
            .service-card:hover {
                transform: translateY(-8px);
                box-shadow: 0 18px 40px rgba(0, 0, 0, 0.08) !important;
            }
            .service-card:hover img {
                transform: scale(1.05);
            }
        }

        .service-card .btn-outline-primary {
            border-radius: 50px;
            padding: 8px 20px;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .service-card .btn-outline-primary:hover {
            background-color: #198754;
            border-color: #198754;
            color: #fff;
            transform: translateX(4px);
        }
    </style>

    <div class="container py-5 reveal">
        <div class="text-center mb-5">
            <h6 class="text-secondary text-uppercase fw-bold tracking-wider">Our Services</h6>
            <!-- Fixed H1 to H2 for strict SEO compliance -->
            <h2 class="mb-3 display-6 fw-bold">Explore Our Services</h2>
            <p class="text-muted mx-auto" style="max-width: 700px;">Biome Enterprises delivers integrated supply chain optimization and ethical trade operations across the North-East Indian economic corridor.</p>
        </div>

        <div class="row g-4 reveal reveal-stagger justify-content-center">

            
 
  <!-- Logistics -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm p-3 service-card">
                    <div class="service-img-wrapper mb-3">
                        <img class="card-img-top" src="img/service01.png" alt="Transportation and Logistics Services by Biome Enterprises" loading="lazy">
                    </div>
                    <div class="card-body px-2 pb-2 d-flex flex-column">
                        <h3 class="card-title h5 fw-bold mb-3">Transportation & Logistics</h3>
                        <p class="card-text text-muted mb-4 flex-grow-1">Reliable Pan India transportation with 32-ft open-body and multi-axle container trucks, connecting Assam to major industrial hubs.</p>
                        <div>
                            <a class="btn btn-outline-primary" href="transportation.php" aria-label="Read more about Transportation & Logistics Services">Read More <i class="fa fa-arrow-right ms-1"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bamboo -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm p-3 service-card">
                    <div class="service-img-wrapper mb-3">
                        <img class="card-img-top" src="img/service02.png" alt="Bamboo Trading and Supplies" loading="lazy">
                    </div>
                    <div class="card-body px-2 pb-2 d-flex flex-column">
                        <h3 class="card-title h5 fw-bold mb-3">Bamboo Trading</h3>
                        <p class="card-text text-muted mb-4 flex-grow-1">Premium raw bamboo, long bamboo poles, bamboo pieces, handicraft materials, and sustainable bamboo products supplied across India.</p>
                        <div>
                            <a class="btn btn-outline-primary" href="bamboo.php" aria-label="Read more about Bamboo Trading">Read More <i class="fa fa-arrow-right ms-1"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Legal -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm p-3 service-card">
                    <div class="service-img-wrapper mb-3">
                        <img class="card-img-top" src="img/service03.png" alt="Legal and Compliance Services" loading="lazy">
                    </div>
                    <div class="card-body px-2 pb-2 d-flex flex-column">
                        <h3 class="card-title h5 fw-bold mb-3">Legal & Compliance</h3>
                        <p class="card-text text-muted mb-4 flex-grow-1">GST, FSSAI, MSME, Company Registration, IEC, Accounting, Taxation, Documentation, and complete business compliance services.</p>
                        <div>
                            <a class="btn btn-outline-primary" href="legal.php" aria-label="Read more about Legal & Compliance Services">Read More <i class="fa fa-arrow-right ms-1"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cab -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm p-3 service-card">
                    <div class="service-img-wrapper mb-3">
                        <img class="card-img-top" src="img/service04.png" alt="Cab Rental Services" loading="lazy">
                    </div>
                    <div class="card-body px-2 pb-2 d-flex flex-column">
                        <h3 class="card-title h5 fw-bold mb-3">Cab Rental Services</h3>
                        <p class="card-text text-muted mb-4 flex-grow-1">Self-drive cars, chauffeur-driven vehicles, airport transfers, local travel, and corporate rental services across North-East India.</p>
                        <div>
                            <a class="btn btn-outline-primary" href="cab.php" aria-label="Read more about Cab Rental Services">Read More <i class="fa fa-arrow-right ms-1"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Hotel -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm p-3 service-card">
                    <div class="service-img-wrapper mb-3">
                        <img class="card-img-top" src="img/service05.png" alt="Hotels and Homestays" loading="lazy">
                    </div>
                    <div class="card-body px-2 pb-2 d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h3 class="card-title h5 fw-bold mb-0">Hotels & Homestays</h3>
                            <span class="badge bg-warning text-dark px-2 py-1">Upcoming</span>
                        </div>
                        <p class="card-text text-muted mb-4 flex-grow-1">Book trusted hotels, hill-station stays, premium homestays, and business accommodations across all eight North-East states.</p>
                        <div>
                            <a class="btn btn-outline-primary" href="hotel.php" aria-label="Read more about Hotels & Homestays">Read More <i class="fa fa-arrow-right ms-1"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Restaurant -->
            <div class="col-md-6 col-lg-4">
                <div class="card h-100 shadow-sm p-3 service-card">
                    <div class="service-img-wrapper mb-3">
                        <img class="card-img-top" src="img/service06.png" alt="Restaurant and Ethnic Cuisine" loading="lazy">
                    </div>
                    <div class="card-body px-2 pb-2 d-flex flex-column">
                        <div class="d-flex align-items-center justify-content-between mb-3">
                            <h3 class="card-title h5 fw-bold mb-0">Restaurant & Cuisine</h3>
                            <span class="badge bg-warning text-dark px-2 py-1">Upcoming</span>
                        </div>
                        <p class="card-text text-muted mb-4 flex-grow-1">Experience authentic North-East cuisine, bamboo shoot delicacies, smoked meats, traditional dishes, and local culinary specialties.</p>
                        <div>
                            <a class="btn btn-outline-primary" href="restaurant.php" aria-label="Read more about Restaurant & Cuisine">Read More <i class="fa fa-arrow-right ms-1"></i></a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
    <!-- ===================== Service End ===================== -->

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


<!-- ===================== Team Start ===================== -->

    <style>
        /* Premium Team Card Hover & Responsive Fixes */
        .team-premium-card {
            border-radius: 16px;
            background: #ffffff;
            transition: all 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
            overflow: hidden;
        }
        
        /* Float effect only on devices that support hover (Desktops/Laptops) */
        @media (hover: hover) and (pointer: fine) {
            .team-premium-card:hover {
                transform: translateY(-8px);
                box-shadow: 0 15px 30px rgba(0, 0, 0, 0.1) !important;
            }
        }

        /* Enforce perfectly square images without stretching (Prevents Layout Shift) */
        .team-img-wrapper img {
            aspect-ratio: 1 / 1;
            object-fit: cover;
            width: 100%;
            display: block;
            border-bottom: 3px solid #198754; /* Premium brand accent line */
        }
    </style>

    <div class="container py-5 reveal">
        <div class="text-center mb-5">
            <h6 class="text-secondary text-uppercase fw-bold tracking-wider">Our Team</h6>
            <!-- Fixed H1 to H2 for Strict SEO Rules -->
            <h2 class="mb-0 display-6 fw-bold">Experienced Professionals Behind Every Successful Project</h2>
        </div>
        
        <div class="row g-4 reveal reveal-stagger justify-content-center">
            
            <!-- Team Member 1 -->
            <div class="col-10 col-sm-8 col-md-6 col-lg-3">
                <div class="card border-0 shadow-sm text-center h-100 team-premium-card">
                    <div class="team-img-wrapper">
                        <!-- Added specific ALT tags and explicit sizing for SEO/Speed -->
                        <img class="card-img-top" src="img/team-1.jpeg" alt="Bittu Ali Hazarika - Managing Director at Biome Enterprises" loading="lazy">
                    </div>
                    <div class="card-body p-4">
                        <h5 class="mb-1 fw-bold">Bittu Ali Hazarika</h5>
                        <p class="text-muted small text-uppercase fw-bold mb-3">Managing Director</p>
                        <div class="d-flex justify-content-center gap-2">
                            <!-- Added aria-labels for Accessibility compliance -->
                            <a href="#" class="btn btn-sm btn-outline-primary rounded-circle" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                            <a href="#" class="btn btn-sm btn-outline-primary rounded-circle" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                            <a href="#" class="btn btn-sm btn-outline-primary rounded-circle" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Team Member 2 (Commented as requested, but structured correctly) -->
            <!-- 
            <div class="col-10 col-sm-8 col-md-6 col-lg-3">
                <div class="card border-0 shadow-sm text-center h-100 team-premium-card">
                    <div class="team-img-wrapper">
                        <img class="card-img-top" src="img/team-2.jpg" alt="Operations Head at Biome Enterprises" loading="lazy">
                    </div>
                    <div class="card-body p-4">
                        <h5 class="mb-1 fw-bold">Full Name</h5>
                        <p class="text-muted small text-uppercase fw-bold mb-3">Operations Head</p>
                        <div class="d-flex justify-content-center gap-2">
                            <a href="#" class="btn btn-sm btn-outline-primary rounded-circle" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                            <a href="#" class="btn btn-sm btn-outline-primary rounded-circle" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                            <a href="#" class="btn btn-sm btn-outline-primary rounded-circle" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                        </div>
                    </div>
                </div>
            </div> 
            -->

            <!-- Team Member 3 -->
            <div class="col-10 col-sm-8 col-md-6 col-lg-3">
                <div class="card border-0 shadow-sm text-center h-100 team-premium-card">
                    <div class="team-img-wrapper">
                        <img class="card-img-top" src="img/team-4.png" alt="Pinku Sawra - Data Entry Operator at Biome Enterprises" loading="lazy">
                    </div>
                    <div class="card-body p-4">
                        <h5 class="mb-1 fw-bold">Pinku Sawra</h5>
                        <p class="text-muted small text-uppercase fw-bold mb-3">Data Entry Operator</p>
                        <div class="d-flex justify-content-center gap-2">
                            <a href="#" class="btn btn-sm btn-outline-primary rounded-circle" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                            <a href="#" class="btn btn-sm btn-outline-primary rounded-circle" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                            <a href="#" class="btn btn-sm btn-outline-primary rounded-circle" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Team Member 4 -->
            <div class="col-10 col-sm-8 col-md-6 col-lg-3">
                <div class="card border-0 shadow-sm text-center h-100 team-premium-card">
                    <div class="team-img-wrapper">
                        <img class="card-img-top" src="img/team-3.png" alt="Minal Kanth - Head of Technology at Biome Enterprises" loading="lazy">
                    </div>
                    <div class="card-body p-4">
                        <h5 class="mb-1 fw-bold">Minal Kanth</h5>
                        <p class="text-muted small text-uppercase fw-bold mb-3">Head of Technology</p>
                        <div class="d-flex justify-content-center gap-2">
                            <a href="#" class="btn btn-sm btn-outline-primary rounded-circle" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a>
                            <a href="#" class="btn btn-sm btn-outline-primary rounded-circle" aria-label="Twitter"><i class="fab fa-twitter"></i></a>
                            <a href="#" class="btn btn-sm btn-outline-primary rounded-circle" aria-label="Instagram"><i class="fab fa-instagram"></i></a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- ===================== Team End ===================== -->












<!-- ===================== Bootstrap Testimonial Carousel Start ===================== -->

    <style>
        /* Premium Testimonial Enhancements */
        .testimonial-card {
            border-radius: 20px;
            background: #ffffff;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }
        .testimonial-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 35px rgba(0,0,0,0.08) !important;
        }
        
        /* Custom Premium Navigation Buttons */
        .testimonial-nav-btn {
            width: 50px;
            height: 50px;
            background: #ffffff;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
            transition: all 0.3s ease;
        }
        .carousel-control-prev, .carousel-control-next {
            width: 10%; /* Prevent buttons from overlapping the text on desktop */
            opacity: 1; /* Keep buttons visible */
        }
        .testimonial-nav-btn i {
            color: #198754; /* Biome Green */
            font-size: 1.2rem;
            transition: color 0.3s ease;
        }
        /* Hover Effects */
        .carousel-control-prev:hover .testimonial-nav-btn,
        .carousel-control-next:hover .testimonial-nav-btn {
            background: #198754;
            transform: scale(1.1);
        }
        .carousel-control-prev:hover .testimonial-nav-btn i,
        .carousel-control-next:hover .testimonial-nav-btn i {
            color: #ffffff;
        }
    </style>

    <div class="container py-5 reveal">
        <div class="text-center mb-5">
            <h6 class="text-secondary text-uppercase">Client Testimonials</h6>
            <h2 class="mb-0 display-6">What Our Clients Say</h2>
        </div>

        <div id="testimonialCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-interval="6000">

            <div class="carousel-indicators position-static mb-4">
                <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="0" class="active bg-primary" aria-current="true" aria-label="Slide 1"></button>
                <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="1" class="bg-primary" aria-label="Slide 2"></button>
                <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="2" class="bg-primary" aria-label="Slide 3"></button>
                <button type="button" data-bs-target="#testimonialCarousel" data-bs-slide-to="3" class="bg-primary" aria-label="Slide 4"></button>
            </div>

            <div class="carousel-inner pb-4">

                <!-- Testimonial 1 -->
                <div class="carousel-item active">
                    <div class="card border-0 shadow-sm mx-auto p-4 p-md-5 testimonial-card" style="max-width:700px;">
                        <i class="fa fa-quote-right fa-2x text-primary mb-3" style="opacity: 0.5;"></i>
                        <p class="fs-5 mb-4">
                            Biome Enterprises handled our Assam to Delhi freight professionally. Their team provided timely updates and ensured safe delivery throughout the journey.
                        </p>
                        <div class="d-flex align-items-center">
                            <img class="rounded-circle flex-shrink-0 shadow-sm" alt="Biome Enterprises" src="img/testimonial-1.jpg" style="width:64px;height:64px;object-fit:cover;" loading="lazy">
                            <div class="ms-3">
                                <h5 class="mb-0">Rajesh Sharma</h5>
                                <p class="m-0 text-muted small text-uppercase fw-bold">Manufacturing Business</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Testimonial 2 -->
                <div class="carousel-item">
                    <div class="card border-0 shadow-sm mx-auto p-4 p-md-5 testimonial-card" style="max-width:700px;">
                        <i class="fa fa-quote-right fa-2x text-primary mb-3" style="opacity: 0.5;"></i>
                        <p class="fs-5 mb-4">
                            Their legal and compliance team completed our GST and FSSAI registration quickly with complete transparency. Highly recommended for startups.
                        </p>
                        <div class="d-flex align-items-center">
                            <img class="rounded-circle flex-shrink-0 shadow-sm" alt="Biome Enterprises" src="img/testimonial-2.jpg" style="width:64px;height:64px;object-fit:cover;" loading="lazy">
                            <div class="ms-3">
                                <h5 class="mb-0">Priya Das</h5>
                                <p class="m-0 text-muted small text-uppercase fw-bold">Food Business Owner</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Testimonial 3 -->
                <div class="carousel-item">
                    <div class="card border-0 shadow-sm mx-auto p-4 p-md-5 testimonial-card" style="max-width:700px;">
                        <i class="fa fa-quote-right fa-2x text-primary mb-3" style="opacity: 0.5;"></i>
                        <p class="fs-5 mb-4">
                            Excellent cab booking and hotel arrangements for our business trip across North-East India. The service was reliable and hassle-free.
                        </p>
                        <div class="d-flex align-items-center">
                            <img class="rounded-circle flex-shrink-0 shadow-sm" alt="Biome Enterprises" src="img/testimonial-3.jpg" style="width:64px;height:64px;object-fit:cover;" loading="lazy">
                            <div class="ms-3">
                                <h5 class="mb-0">Amit Verma</h5>
                                <p class="m-0 text-muted small text-uppercase fw-bold">Corporate Client</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Testimonial 4 -->
                <div class="carousel-item">
                    <div class="card border-0 shadow-sm mx-auto p-4 p-md-5 testimonial-card" style="max-width:700px;">
                        <i class="fa fa-quote-right fa-2x text-primary mb-3" style="opacity: 0.5;"></i>
                        <p class="fs-5 mb-4">
                            We source bamboo materials through Biome Enterprises regularly. Their quality, pricing, and logistics support have always exceeded our expectations.
                        </p>
                        <div class="d-flex align-items-center">
                            <img class="rounded-circle flex-shrink-0 shadow-sm" alt="Biome Enterprises" src="img/testimonial-4.jpg" style="width:64px;height:64px;object-fit:cover;" loading="lazy">
                            <div class="ms-3">
                                <h5 class="mb-0">Neha Singh</h5>
                                <p class="m-0 text-muted small text-uppercase fw-bold">Bamboo Industry</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- Custom Navigation Buttons using FontAwesome -->
            <button class="carousel-control-prev" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="prev">
                <div class="testimonial-nav-btn">
                    <i class="fas fa-chevron-left"></i>
                </div>
                <span class="visually-hidden">Previous</span>
            </button>
            <button class="carousel-control-next" type="button" data-bs-target="#testimonialCarousel" data-bs-slide="next">
                <div class="testimonial-nav-btn">
                    <i class="fas fa-chevron-right"></i>
                </div>
                <span class="visually-hidden">Next</span>
            </button>
            
        </div>
    </div>

   <!-- ===================== Bootstrap Testimonial Carousel End ===================== -->

  
    <!-- Footer  -->
    <?php include __DIR__ . '/footer.php'; ?>
    <!-- Footer end -->

    

