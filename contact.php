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
    <title>Biome Enterprises | Contact Us</title>
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
    <link href="css/style-contact.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">
</head>

<body>



    <!-- Navbar -->
    <div id="navbar"></div>
    <?php include __DIR__ . '/navbar.php'; ?>
    <!-- Navbar End -->


    <!-- Page Header Start -->
    <div class="container-fluid page-header bamboo-header position-relative overflow-hidden py-5">

        <div class="container py-5">

            <h6 class="text-uppercase text-warning fw-bold mb-3 animated slideInDown">
                Get In Touch
            </h6>

            <h1 class="display-3 text-white fw-bold mb-4 animated slideInDown">
                Contact
                <span class="text-success">
                Biome Enterprises
            </span>
            </h1>

            <p class="text-light fs-5 mb-4">
                Transportation • Bamboo Trading • Legal Services • Cab Rentals • Business Registration
            </p>

            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a class="text-white" href="index.php">Home</a>
                    </li>

                    <li class="breadcrumb-item text-white active">
                        Contact
                    </li>
                </ol>
            </nav>

        </div>

    </div>
    <!-- Page Header End -->

    <div class="row g-4 mb-5">

        <div class="col-md-4">

            <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                <i class="fa fa-phone fa-3x text-success mb-3"></i>

                <h5>Call</h5>

                <p class="mb-0">
                    <a href="tel:+919678431656" class="text-dark text-decoration-none fw-semibold">
            +91 96784 31656
        </a>
                </p>

            </div>

        </div>

        <div class="col-md-4">

    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

        <i class="fa fa-envelope fa-3x text-success mb-3"></i>

        <h5>Email</h5>

        <p class="mb-0">
            <a href="mailto:info@biomeenterprises.com" class="text-dark text-decoration-none">
                info@biomeenterprises.com
            </a>
        </p>

    </div>

</div>

        <div class="col-md-4">

            <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                <i class="fa fa-map-marker-alt fa-3x text-success mb-3"></i>

                <h5>Location</h5>

                <p>Assam, India</p>

            </div>

        </div>

    </div>
    <!-- Contact Start -->
    <div class="container-fluid overflow-hidden py-5 px-lg-0">
        <div class="container contact-page py-5 px-lg-0">
            <div class="row g-5 mx-lg-0">
                <div class="col-md-6 contact-form wow fadeIn" data-wow-delay="0.1s">
                    <h6 class="text-secondary text-uppercase">Let's Build Your Business Together</h6>
                    <h1 class="mb-4">Need Transportation, Bamboo or Business Services?</h1>
                    <p class="mb-4">Whether you're looking for logistics, premium bamboo products, GST registration, MSME registration, company incorporation, accounting services or cab rentals, our team is ready to assist you with reliable and professional solutions..</p>
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
                                    <button class="btn btn-primary w-100 py-3" type="submit">Request Consultation</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="col-md-6 pe-lg-0 wow fadeInRight" data-wow-delay="0.1s">
                    <div class="position-relative h-100">
                        <iframe class="position-absolute w-100 h-100" style="object-fit: cover;" src="https://www.google.com/maps?q=Assam,India&output=embed" frameborder="0" allowfullscreen="" aria-hidden="false" tabindex="0"></iframe>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Contact End -->


    <!-- Footer -->
    <!-- <div id="footer"></div> -->
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
    <script src="lib/counterup/counterup.min.js"></script>
    <script src="lib/owlcarousel/owl.carousel.min.js"></script>

    <!-- Template Javascript -->
    <script src="js/main.js"></script>

    <script src="js/navbar-active-state.js"></script>

    <script>
        /*==============================
                                                                                        CONTACT PAGE INTERACTIONS
                                                                                        ==============================*/

        document.addEventListener("DOMContentLoaded", function() {

            /* Hover animation on cards */

            document.querySelectorAll(".row.mb-5 .bg-white").forEach(card => {

                card.addEventListener("mousemove", function(e) {

                    const rect = this.getBoundingClientRect();

                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;

                    this.style.background =
                        `radial-gradient(circle at ${x}px ${y}px,
            rgba(25,135,84,.08),
            #fff 65%)`;

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

            /* Form validation */

            const form = document.querySelector("form");

            

            /* Floating labels */

            document.querySelectorAll(".form-control,.form-select").forEach(input => {

                input.addEventListener("focus", () => {

                    input.parentElement.style.transform = "translateY(-3px)";

                });

                input.addEventListener("blur", () => {

                    input.parentElement.style.transform = "translateY(0)";

                });

            });

            /* Parallax banner */

            const banner = document.querySelector(".page-header");

            window.addEventListener("scroll", () => {

                if (banner) {

                    banner.style.backgroundPositionY = window.scrollY * 0.35 + "px";

                }

            });

        });

        // fetch("navbar.php")
        //     .then(res => res.text())
        //     .then(data => {
        //         document.getElementById("navbar").innerHTML = data;

        //         document.querySelectorAll('.dropdown-toggle').forEach(function(el) {
        //             new bootstrap.Dropdown(el);
        //         });
        //     });

        // fetch("footer.php")
        //     .then(res => res.text())
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
    </script>
</body>

</html>