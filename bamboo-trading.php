<?php
declare(strict_types=1);

require_once __DIR__ . '/admin/config/database.php';

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
    <title>Biome Enterprises | Bamboo Trading</title>
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
    <link href="css/style-bamboo-trading.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">

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

                        <form method="post" action="">
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
                                    <select class="form-select py-3" name="products[]" multiple size="8">
                                        <?php foreach ($bambooAllowedProducts as $product): ?>
                                            <option value="<?= bf_e($product) ?>" <?= in_array($product, $bambooFormValues['products'], true) ? 'selected' : '' ?>>
                                                <?= bf_e($product) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">Hold Ctrl (Windows) or Command (Mac) to select multiple products.</small>
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
                                    <textarea name="message" class="form-control" rows="5" maxlength="2000" placeholder="Write your requirement..."><?= bf_e($bambooFormValues['message']) ?></textarea>
                                </div>

                                <div class="col-12">
                                    <button class="btn btn-success btn-lg rounded-pill px-5 py-3 w-100" type="submit">
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

            /* Spotlight hover */

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

</body>

</html>