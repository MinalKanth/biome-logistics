<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Biome Enterprises | Restaurant</title>
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
    <link href="css/style-restaurant.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">
</head>
<style>
    .min-vh-100 {
        min-height: 100vh;
    }
    
    .shadow-lg {
        box-shadow: 0 25px 60px rgba(0, 0, 0, .12)!important;
    }
    
    .rounded-4 {
        border-radius: 22px!important;
    }
    
    .bg-white {
        transition: .4s;
    }
    
    .bg-white:hover {
        transform: translateY(-8px);
        box-shadow: 0 35px 70px rgba(0, 0, 0, .18)!important;
    }
    
    .btn-success {
        transition: .3s;
    }
    
    .btn-success:hover {
        transform: translateY(-4px);
    }
    
    .floating {
        animation: float 3s ease-in-out infinite;
    }
    
    @keyframes float {
        0% {
            transform: translateY(0);
        }
        50% {
            transform: translateY(-12px);
        }
        100% {
            transform: translateY(0);
        }
    }
</style>

<body>



    <!-- Navbar -->
    <!-- <div id="navbar"></div> -->
     <?php include __DIR__ . '/navbar.php'; ?>
    <!-- Navbar End -->


    <!-- Page Header Start -->
    <div class="container-fluid page-header bamboo-header position-relative overflow-hidden py-5">

        <div class="container py-5">

            <h6 class="text-uppercase text-warning fw-bold mb-3 animated slideInDown">
                Coming Soon
            </h6>

            <h1 class="display-3 text-white fw-bold mb-4 animated slideInDown">
                Restaurant
                <span class="text-success">
        Services
    </span>
            </h1>

            <p class="text-light fs-5 mb-4">
                Delicious Food • Fine Dining • Catering • Family Restaurant • Event Dining
            </p>

            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item">
                        <a class="text-white" href="index.php">Home</a>
                    </li>

                    <li class="breadcrumb-item text-white active">
                        Restaurant
                    </li>
                </ol>
            </nav>

        </div>

    </div>
    <!-- Page Header End -->

    <div class="container py-5">
        <div class="row g-4">

            <div class="col-md-4">
                <div class="bg-white rounded-4 shadow p-4 text-center h-100">
                    <i class="fas fa-utensils fa-3x text-success mb-3"></i>
                    <h5>Delicious Cuisine</h5>
                    <p>Freshly prepared meals made with premium ingredients and authentic flavors.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="bg-white rounded-4 shadow p-4 text-center h-100">
                    <i class="fas fa-concierge-bell fa-3x text-success mb-3"></i>
                    <h5>Premium Dining</h5>
                    <p>Enjoy a comfortable dining experience with excellent hospitality and ambience.</p>
                </div>
            </div>

            <div class="col-md-4">
                <div class="bg-white rounded-4 shadow p-4 text-center h-100">
                    <i class="fas fa-motorcycle fa-3x text-success mb-3"></i>
                    <h5>Takeaway & Delivery</h5>
                    <p>Quick takeaway and doorstep food delivery services launching soon.</p>
                </div>
            </div>

        </div>
    </div>

    <!-- Coming Soon Start -->
    <div class="container-fluid py-5 bg-light min-vh-100 d-flex align-items-center">

        <div class="container">

            <div class="row justify-content-center">

                <div class="col-lg-7">

                    <div class="bg-white rounded-4 shadow-lg p-5 text-center">

                        <div class="mb-4">
                            <i class="fas fa-utensils fa-5x text-danger floating"></i>
                        </div>

                        <h6 class="text-uppercase text-danger fw-bold mb-3">
                            Launching Soon
                        </h6>

                        <h1 class="display-4 fw-bold mb-4">
                            Restaurant Services
                            <span class="text-success">
            Coming Soon
        </span>
                        </h1>

                        <p class="text-muted fs-5 mb-4">
                            We are preparing an exciting restaurant experience featuring delicious cuisine, family dining, takeaway, catering and food delivery services. Stay connected as we bring exceptional food and hospitality to you.
                        </p>

                        <div class="row text-center mb-4">

                            <div class="col-4">
                                <i class="fas fa-hamburger fa-2x text-success mb-2"></i>
                                <h6>Fresh Food</h6>
                            </div>

                            <div class="col-4">
                                <i class="fas fa-coffee fa-2x text-success mb-2"></i>
                                <h6>Fine Dining</h6>
                            </div>

                            <div class="col-4">
                                <i class="fas fa-shipping-fast fa-2x text-success mb-2"></i>
                                <h6>Fast Delivery</h6>
                            </div>

                        </div>

                        <a href="contact.php" class="btn btn-success px-5 py-3 rounded-pill">
        Notify Me
    </a>

                    </div>

                </div>

            </div>

        </div>

    </div>
    <!-- Coming Soon End -->
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
        document.addEventListener("DOMContentLoaded", function() {

            // fetch("navbar.php")
            //     .then(res => res.text())
            //     .then(data => {
            //         document.getElementById("navbar").innerHTML = data;
            //     });

            // fetch("footer.php")
            //     .then(res => res.text())
            //     .then(data => {
            //         document.getElementById("footer").innerHTML = data;
            //     });

        });
    </script>
</body>

</html>