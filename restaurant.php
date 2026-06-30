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

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Inter', sans-serif;
            background: #f7f9fc;
            overflow-x: hidden;
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
        .reveal-stagger > * {
            opacity: 0;
            transform: translateY(30px);
            transition: opacity .7s ease, transform .7s ease;
        }
        .reveal-stagger.is-visible > * { opacity: 1; transform: translateY(0); }
        .reveal-stagger.is-visible > *:nth-child(1) { transition-delay: .05s; }
        .reveal-stagger.is-visible > *:nth-child(2) { transition-delay: .15s; }
        .reveal-stagger.is-visible > *:nth-child(3) { transition-delay: .25s; }

        /* ---- Navbar glass on scroll ---- */
        .navbar {
            transition: background .4s ease, box-shadow .4s ease, padding .4s ease;
        }
        .navbar.be-scrolled {
            background: rgba(255,255,255,.78) !important;
            backdrop-filter: blur(14px) saturate(160%);
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            padding-top: .4rem !important;
            padding-bottom: .4rem !important;
            box-shadow: 0 6px 20px rgba(0,0,0,.08) !important;
        }

        /* ---- Page Header ---- */
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
            color: var(--be-primary) !important;
            letter-spacing: 3px;
            display: inline-block;
            padding: .35rem 1rem;
            border: 1px solid rgba(255,255,255,.35);
            border-radius: 50px;
            backdrop-filter: blur(6px);
            background: rgba(255,255,255,.08);
        }
        .page-header .text-success { color: var(--be-primary) !important; }
        .breadcrumb { background: transparent; margin: 0; }
        .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,.6); }

        /* ---- Fluid type ---- */
        h1, .display-3 { font-size: clamp(1.8rem, 4.5vw + .5rem, 3.4rem) !important; }
        .display-4 { font-size: clamp(1.6rem, 4vw + .5rem, 2.8rem) !important; }
        p { font-size: clamp(.92rem, .4vw + .8rem, 1.05rem); }

        /* ---- Cards (replace template hover, gold/green accents) ---- */
        .min-vh-100 {
            min-height: 100vh;
        }

        .shadow-lg {
            box-shadow: var(--be-shadow-strong) !important;
        }

        .rounded-4 {
            border-radius: var(--be-radius) !important;
        }

        .bg-white {
            transition: var(--be-transition);
        }

        .bg-white:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 45px rgba(191, 145, 7, 0.22) !important;
        }

        .bg-white i.text-success {
            transition: var(--be-transition);
        }
        .bg-white:hover i.text-success {
            transform: scale(1.1) rotate(-4deg);
            filter: drop-shadow(0 4px 14px rgba(25,135,84,.45));
        }

        /* ---- Buttons ---- */
        .btn {
            position: relative;
            overflow: hidden;
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

        .btn-success {
            transition: var(--be-transition);
            background: linear-gradient(135deg, var(--be-success), #115d3a);
            border: none;
        }

        .btn-success:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px rgba(25, 135, 84, 0.35);
        }

        /* ---- Floating animation ---- */
        .floating {
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0% { transform: translateY(0); }
            50% { transform: translateY(-12px); }
            100% { transform: translateY(0); }
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
        }
        .back-to-top:hover {
            background: var(--be-success) !important;
            box-shadow: 0 14px 32px rgba(25,135,84,.4);
        }

        ::selection { background: var(--be-primary); color: var(--be-dark); }

        /* ===================== FULL MOBILE RESPONSIVENESS ===================== */

        @media (max-width: 768px) {
            .py-5 { padding-top: 2.5rem !important; padding-bottom: 2.5rem !important; }
            .row.g-4 { row-gap: 1.25rem; }
            .rounded-4 { border-radius: .85rem !important; }
        }

        @media (max-width: 576px) {
            .p-5 { padding: 1.75rem !important; }
            .fa-5x { font-size: 3rem !important; }
            .fa-3x { font-size: 1.9rem !important; }
            .fa-2x { font-size: 1.4rem !important; }
            .whatsapp-float { width: 50px; height: 50px; font-size: 1.3rem; bottom: 76px; right: 16px; }
            .container { padding-left: 1rem; padding-right: 1rem; }
            .btn.px-5.py-3 { padding: .85rem 1.75rem !important; width: 100%; }
        }

        @media (max-width: 400px) {
            .display-3, h1 { font-size: 1.6rem !important; }
        }
    </style>
</head>

<body>

    <div id="scrollProgress"></div>

    <!-- Navbar -->
     <?php include __DIR__ . '/navbar.php'; ?>
    <!-- Navbar End -->


    <!-- Page Header Start -->
    <div class="container-fluid page-header py-5">

        <div class="container py-5">

            <h6 class="text-uppercase text-warning fw-bold mb-3 reveal">
                Coming Soon
            </h6>

            <h1 class="display-3 text-white fw-bold mb-4 reveal">
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
        <div class="row g-4 reveal reveal-stagger">

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

                <div class="col-lg-7 reveal">

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


    <!-- Footer -->
     <?php include __DIR__ . '/footer.php'; ?>
    <!-- Footer End -->


    <!-- Floating WhatsApp Button -->
    <a href="https://wa.me/919678431656" target="_blank" class="whatsapp-float" aria-label="Chat on WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Sticky Mobile Call Bar -->
    <div id="mobileCallBar">
        <a href="tel:+919678431656" class="btn btn-success flex-fill"><i class="fa fa-phone me-2"></i>Call Now</a>
        <a href="https://wa.me/919678431656" target="_blank" class="btn btn-light flex-fill"><i class="fab fa-whatsapp me-2"></i>WhatsApp</a>
    </div>

    <!-- Back to Top -->
    <a href="#" class="btn btn-lg btn-lg-square rounded-0 back-to-top"><i class="bi bi-arrow-up"></i></a>


    <!-- JavaScript Libraries -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="lib/wow/wow.min.js"></script>
    <script src="lib/easing/easing.min.js"></script>
    <script src="lib/waypoints/waypoints.min.js"></script>
    <script src="lib/owlcarousel/owl.carousel.min.js"></script>

    <!-- Template Javascript -->
    <script src="js/main.js"></script>

    <!-- ===================== Premium Interactivity (scroll progress, reveal, navbar) ===================== -->
    <script>
    (function () {
        // Scroll progress bar
        const progressBar = document.getElementById('scrollProgress');
        function updateProgress() {
            const h = document.documentElement;
            const scrolled = (h.scrollTop) / (h.scrollHeight - h.clientHeight) * 100;
            if (progressBar) progressBar.style.width = scrolled + '%';
        }
        window.addEventListener('scroll', updateProgress, { passive: true });
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

        // Navbar glass effect on scroll
        const nav = document.querySelector('nav.navbar, .navbar');
        function updateNav() {
            if (!nav) return;
            if (window.scrollY > 40) {
                nav.classList.add('be-scrolled');
            } else {
                nav.classList.remove('be-scrolled');
            }
        }
        window.addEventListener('scroll', updateNav, { passive: true });
        updateNav();

        // Card mouse-follow glow
        document.querySelectorAll('.bg-white.rounded-4').forEach(function (card) {
            card.addEventListener('mousemove', function (e) {
                const rect = card.getBoundingClientRect();
                const x = e.clientX - rect.left;
                const y = e.clientY - rect.top;
                card.style.background = 'radial-gradient(circle at ' + x + 'px ' + y + 'px, rgba(255,193,7,.10), #fff 65%)';
            });
            card.addEventListener('mouseleave', function () {
                card.style.background = '#fff';
            });
        });
    })();
    </script>
</body>

</html>