<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <title>Biome Enterprises | About</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta content="" name="keywords">
    <meta content="" name="description">

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

        /* ---- Cursor glow (desktop only) ---- */
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

        /* ---- Reveal-on-scroll ---- */
        .reveal {
            opacity: 0;
            transform: translateY(40px);
            transition: opacity .8s ease, transform .8s ease;
        }
        .reveal.is-visible { opacity: 1; transform: translateY(0); }

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

        /* ---- Fluid typography ---- */
        h1 { font-size: clamp(1.7rem, 4vw + .5rem, 3.2rem); }
        h2 { font-size: clamp(1.4rem, 2.6vw + .5rem, 2.2rem); }
        .display-3 { font-size: clamp(1.9rem, 5vw + .5rem, 3.6rem) !important; }
        p { font-size: clamp(.92rem, .4vw + .8rem, 1.05rem); }

        /* ---- Gradient accent text ---- */
        .text-primary {
            background: linear-gradient(135deg, var(--be-primary), var(--be-success));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .btn .text-primary, .badge.text-primary { -webkit-text-fill-color: initial; background: none; }

        /* ---- Page header (Bootstrap based, no owl/parallax-image dependency) ---- */
        .page-header {
            position: relative;
            background: linear-gradient(135deg, #1c1605 0%, #2c2308 55%, #14110a 100%);
            overflow: hidden;
        }
        .page-header::before {
            content: "";
            position: absolute; inset: 0;
            background: radial-gradient(circle at 20% 20%, rgba(255,193,7,.18), transparent 55%),
                        radial-gradient(circle at 80% 80%, rgba(25,135,84,.18), transparent 55%);
            pointer-events: none;
        }
        .page-header .be-blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(70px);
            opacity: .35;
            pointer-events: none;
            animation: be-float 9s ease-in-out infinite;
        }
        @keyframes be-float {
            0%, 100% { transform: translateY(0) translateX(0); }
            50% { transform: translateY(-25px) translateX(15px); }
        }
        .page-header h6 {
            letter-spacing: 3px;
            font-weight: 700;
            display: inline-block;
            padding: .35rem 1rem;
            border: 1px solid rgba(255,255,255,.3);
            border-radius: 50px;
            backdrop-filter: blur(6px);
            background: rgba(255,255,255,.06);
            -webkit-text-fill-color: initial;
            color: var(--be-primary) !important;
        }
        .page-header .breadcrumb {
            background: rgba(255,255,255,.08);
            display: inline-flex;
            padding: .5rem 1rem;
            border-radius: 50px;
            backdrop-filter: blur(6px);
        }
        .page-header .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,.5); }
        .page-header a { text-decoration: none; transition: var(--be-transition); }
        .page-header a:hover { color: var(--be-primary) !important; }

        /* ---- Cards / tilt cards ---- */
        .card, .info-card, .counter-box {
            border-radius: var(--be-radius) !important;
            transition: var(--be-transition);
        }
        .card:hover, .info-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 20px 45px rgba(191, 145, 7, 0.22) !important;
        }
        .tilt-card { transform-style: preserve-3d; will-change: transform; }
        .tilt-card:hover { box-shadow: 0 22px 48px rgba(191, 145, 7, 0.26) !important; }

        .info-card { background: #fff; border: 0; box-shadow: var(--be-shadow-soft); overflow: hidden; position: relative; }
        .info-card i { transition: var(--be-transition); }
        .info-card:hover i { transform: scale(1.12) rotate(-4deg); filter: drop-shadow(0 4px 14px rgba(255,193,7,.5)); }

        /* ---- Image ---- */
        .about-img-wrap { border-radius: var(--be-radius); overflow: hidden; box-shadow: var(--be-shadow-strong); }
        .about-img-wrap img { transition: transform .6s ease; }
        .about-img-wrap:hover img { transform: scale(1.06); }

        /* ---- Buttons ---- */
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
        .counter-box .counter-number { font-size: 2.5rem; line-height: 1; font-weight: 800; }
        .counter-box:hover {
            transform: translateY(-8px) scale(1.02);
            box-shadow: 0 18px 40px rgba(191, 145, 7, 0.3) !important;
        }

        /* ---- Navbar premium ---- */
        .navbar { transition: background .4s ease, box-shadow .4s ease, padding .4s ease; }
        .navbar.be-scrolled {
            background: rgba(255,255,255,.78) !important;
            backdrop-filter: blur(14px) saturate(160%);
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            box-shadow: 0 6px 20px rgba(0,0,0,.08) !important;
            padding-top: .4rem !important;
            padding-bottom: .4rem !important;
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
            transition: var(--be-transition);
        }
        .back-to-top:hover {
            background: var(--be-success) !important;
            box-shadow: 0 14px 32px rgba(25,135,84,.4);
        }

        ::selection { background: var(--be-primary); color: #271e01; }

        /* ---- Mobile responsiveness ---- */
        @media (max-width: 991px) {
            .page-header { text-align: center; }
            .page-header .breadcrumb { justify-content: center; }
        }
        @media (max-width: 768px) {
            .py-5 { padding-top: 2.5rem !important; padding-bottom: 2.5rem !important; }
            .row.g-5 { --bs-gutter-y: 2rem; }
            .card, .info-card { border-radius: .85rem !important; }
        }
        @media (max-width: 576px) {
            .container { padding-left: 1.1rem; padding-right: 1.1rem; }
            .counter-box .counter-number { font-size: 1.8rem; }
            .counter-box i.fa-3x { font-size: 1.6rem !important; }
            .whatsapp-float { width: 50px; height: 50px; font-size: 1.3rem; bottom: 76px; right: 16px; }
            .page-header { padding-top: 3rem !important; padding-bottom: 3rem !important; }
        }
        @media (max-width: 380px) {
            h1 { font-size: 1.5rem; }
        }
    </style>
</head>

<body>

    <div id="scrollProgress"></div>
    <div id="cursorGlow"></div>

    <!-- Navbar -->
    <?php include __DIR__ . '/navbar.php'; ?>
    <!-- Navbar End -->


    <!-- =========================
     PAGE HEADER START
========================= -->
    <div class="container-fluid page-header py-5">

        <div class="be-blob" style="width:280px;height:280px;top:8%;left:-6%;background:#ffc107;"></div>
        <div class="be-blob" style="width:220px;height:220px;bottom:5%;right:-4%;background:#198754;animation-delay:2s;"></div>

        <div class="container py-5 position-relative">

            <h6 class="mb-3 reveal">About Biome Enterprises</h6>

            <h1 class="display-3 text-white fw-bold mb-4 reveal">
                Building Trust Through
                <span class="text-primary">Logistics & Business Solutions</span>
            </h1>

            <p class="text-light fs-5 mb-4 reveal">
                Transportation • Bamboo Trading • Legal & Compliance • Accounting • Cab Rentals • Hospitality Services
            </p>

            <nav class="reveal">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a class="text-white" href="index.php">Home</a></li>
                    <li class="breadcrumb-item text-white active" aria-current="page">About Us</li>
                </ol>
            </nav>

        </div>

    </div>
    <!-- PAGE HEADER END -->


    <!-- ABOUT START -->
    <div class="container py-5 reveal">
        <div class="row g-5 align-items-center">

            <!-- Image -->
            <div class="col-lg-6">
                <div class="about-img-wrap tilt-card" style="height:450px;">
                    <img class="img-fluid w-100 h-100" src="img/about-us.png" style="object-fit:cover;" alt="Biome Enterprises">
                </div>
            </div>

            <!-- Content -->
            <div class="col-lg-6">
                <h6 class="text-secondary text-uppercase mb-3">Who We Are</h6>
                <h1 class="mb-4">Delivering Reliable Business Solutions Across India</h1>

                <p class="mb-4">
                    Biome Enterprises is a rapidly growing multi-service company headquartered in Assam, committed to providing dependable transportation, premium bamboo trading, legal compliance, accounting, business registration, hospitality, and travel solutions. Our goal is to simplify business operations by delivering multiple professional services under one trusted brand.
                </p>

                <p class="mb-5">
                    With a strong logistics network connecting North-East India to major industrial hubs across the country, we proudly serve manufacturers, traders, exporters, startups, MSMEs, corporate organizations, and individual customers through quality-driven services, transparent communication, and customer-first support.
                </p>

                <div class="row g-4 reveal reveal-stagger">

                    <div class="col-sm-6">
                        <div class="info-card tilt-card rounded-4 p-4 h-100">
                            <i class="fa fa-truck fa-3x text-primary mb-3"></i>
                            <h5>Pan India Logistics</h5>
                            <p class="mb-0">Reliable transportation solutions connecting North-East India with major business destinations across the country.</p>
                        </div>
                    </div>

                    <div class="col-sm-6">
                        <div class="info-card tilt-card rounded-4 p-4 h-100">
                            <i class="fa fa-leaf fa-3x text-success mb-3"></i>
                            <h5>Bamboo Trading</h5>
                            <p class="mb-0">Premium quality bamboo products supplied for construction, furniture, handicrafts, industrial and export purposes.</p>
                        </div>
                    </div>

                    <div class="col-sm-6">
                        <div class="info-card tilt-card rounded-4 p-4 h-100">
                            <i class="fa fa-balance-scale fa-3x text-primary mb-3"></i>
                            <h5>Business Compliance</h5>
                            <p class="mb-0">GST, MSME, Company Registration, Accounting, Taxation and complete legal documentation services.</p>
                        </div>
                    </div>

                    <div class="col-sm-6">
                        <div class="info-card tilt-card rounded-4 p-4 h-100">
                            <i class="fa fa-headset fa-3x text-success mb-3"></i>
                            <h5>Dedicated Support</h5>
                            <p class="mb-0">Professional guidance, transparent pricing and quick response for every customer enquiry.</p>
                        </div>
                    </div>

                </div>

            </div>

        </div>
    </div>
    <!-- ABOUT END -->


    <!-- FACTS START -->
    <div class="container py-5 reveal">
        <div class="row g-5">

            <div class="col-lg-6">
                <h6 class="text-secondary text-uppercase mb-3">Our Journey</h6>
                <h1 class="mb-4">Empowering Businesses Through Reliable Services</h1>
                <p class="mb-5">
                    Our mission is to provide dependable logistics, sustainable bamboo products, professional business consulting and customer-focused solutions that help businesses grow efficiently across India.
                </p>

                <div class="d-flex align-items-center">
                    <i class="fa fa-headphones fa-2x flex-shrink-0 bg-primary p-3 text-white rounded"></i>
                    <div class="ps-4">
                        <h6 class="mb-1">Need Assistance?</h6>
                        <h3 class="m-0">
                            <a href="tel:+919678431656" class="text-primary text-decoration-none">
                                <i class="fa fa-phone-alt me-2"></i>+91 96784 31656
                            </a>
                        </h3>
                        <a href="https://wa.me/919678431656" target="_blank" class="btn btn-success btn-sm mt-3">
                            <i class="fab fa-whatsapp me-2"></i>Chat on WhatsApp
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="row g-4 reveal reveal-stagger">

                    <div class="col-6">
                        <div class="counter-box bg-primary shadow text-center p-4 h-100 tilt-card">
                            <i class="fa fa-users fa-3x text-white mb-3"></i>
                            <h2 class="text-white fw-bold mb-1">
                                <span class="counter-number" data-target="100">0</span>+
                            </h2>
                            <p class="text-white mb-0">Happy Clients</p>
                        </div>
                    </div>

                    <div class="col-6">
                        <div class="counter-box bg-success shadow text-center p-4 h-100 tilt-card">
                            <i class="fa fa-truck fa-3x text-white mb-3"></i>
                            <h2 class="text-white fw-bold mb-1">
                                <span class="counter-number" data-target="150">0</span>+
                            </h2>
                            <p class="text-white mb-0">Deliveries Completed</p>
                        </div>
                    </div>

                    <div class="col-6">
                        <div class="counter-box bg-dark shadow text-center p-4 h-100 tilt-card">
                            <i class="fa fa-map-marker-alt fa-3x text-warning mb-3"></i>
                            <h2 class="text-white fw-bold mb-1">
                                <span class="counter-number" data-target="28">0</span>
                            </h2>
                            <p class="text-white mb-0">States Connected</p>
                        </div>
                    </div>

                    <div class="col-6">
                        <div class="counter-box bg-secondary shadow text-center p-4 h-100 tilt-card">
                            <i class="fa fa-check-circle fa-3x text-white mb-3"></i>
                            <h2 class="text-white fw-bold mb-1">
                                <span class="counter-number" data-target="99">0</span>%
                            </h2>
                            <p class="text-white mb-0">Customer Satisfaction</p>
                        </div>
                    </div>

                </div>
            </div>

        </div>
    </div>
    <!-- FACTS END -->


    <!-- Floating WhatsApp Button -->
    <a href="https://wa.me/919678431656" target="_blank" class="whatsapp-float" aria-label="Chat on WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Sticky Mobile Call Bar -->
    <div id="mobileCallBar">
        <a href="tel:+919678431656" class="btn btn-primary flex-fill"><i class="fa fa-phone me-2"></i>Call Now</a>
        <a href="https://wa.me/919678431656" target="_blank" class="btn btn-success flex-fill"><i class="fab fa-whatsapp me-2"></i>WhatsApp</a>
    </div>

    <!-- Footer -->
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

    <!-- Template Javascript -->
    <script src="js/main.js"></script>

    <!-- ===================== Counter Animation (vanilla JS, 0 -> target) ===================== -->
    <script>
    (function () {
        const counters = document.querySelectorAll('.counter-number');
        const duration = 1800;

        function animateCounter(el) {
            const target = parseInt(el.getAttribute('data-target'), 10) || 0;
            const start = performance.now();

            function step(now) {
                const progress = Math.min((now - start) / duration, 1);
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

    <!-- ===================== Premium Interactivity (scroll progress, reveal, navbar, cursor, tilt) ===================== -->
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

        // Reveal-on-scroll
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

        // Navbar shadow / glass on scroll
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

        // Cursor glow (desktop only)
        const glow = document.getElementById('cursorGlow');
        if (glow && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            window.addEventListener('mousemove', function (e) {
                glow.style.left = e.clientX + 'px';
                glow.style.top = e.clientY + 'px';
            }, { passive: true });
        }

        // 3D tilt effect on cards / image
        const tiltCards = document.querySelectorAll('.tilt-card');
        if (window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            tiltCards.forEach(function (card) {
                card.addEventListener('mousemove', function (e) {
                    const rect = card.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    const rotateX = ((y / rect.height) - 0.5) * -8;
                    const rotateY = ((x / rect.width) - 0.5) * 8;
                    card.style.transform = 'perspective(800px) rotateX(' + rotateX + 'deg) rotateY(' + rotateY + 'deg) translateY(-6px)';
                });
                card.addEventListener('mouseleave', function () {
                    card.style.transform = '';
                });
            });
        }

        // Subtle parallax on page header blobs
        const header = document.querySelector('.page-header');
        window.addEventListener('scroll', function () {
            if (!header) return;
            const offset = window.scrollY * 0.15;
            header.querySelectorAll('.be-blob').forEach(function (blob) {
                blob.style.transform = 'translateY(' + offset + 'px)';
            });
        }, { passive: true });
    })();
    </script>

</body>

</html>