<!-- ===================================================
     NAVBAR STYLES — self-contained, mobile-first.
     Uses its own "bio-nav" namespace so it won't clash 
     with bg-primary / border-primary used elsewhere.
==================================================== -->
<style>
    :root {
        --bio-nav-green: #198754;
        --bio-nav-green-dark: #0f4c2d;
        --bio-nav-gold: #f0a500;
        --bio-nav-dark: #14181a;
    }

    .bio-navbar {
        background: #fff;
        border-bottom: 1px solid rgba(25, 135, 84, 0.15);
        box-shadow: 0 4px 18px rgba(20, 30, 25, .08);
        transition: box-shadow .3s ease, background .3s ease; 
        padding: 0;
        width: 100%;
        max-width: 100vw;
        overflow-x: clip;
    }

    .bio-navbar.scrolled {
        background: rgba(255, 255, 255, 0.96);
        box-shadow: 0 8px 24px rgba(20, 30, 25, .14);
    }

    /* ---------- Brand ---------- */
    .bio-navbar .navbar-brand {
        background: linear-gradient(135deg, var(--bio-nav-green-dark), var(--bio-nav-green));
        border-radius: 0 0 1rem 0;
        margin: 0;
        padding: .8rem 1rem;
        transition: background .3s ease;
    }

    .bio-navbar .navbar-brand:hover {
        background: linear-gradient(135deg, var(--bio-nav-green), var(--bio-nav-green-dark));
    }
    
    /* .bio-brand-logo {
        height: 42px; 
        width: auto;
        object-fit: contain;
        flex-shrink: 0;
        filter: brightness(0) invert(1);
    } */
        .bio-navbar .bio-brand-logo{
    height:42px;
    width:auto;
    object-fit:contain;
    flex-shrink:0;

    filter: brightness(0) invert(1) !important;
    -webkit-filter: brightness(0) invert(1) !important;
}

.bio-navbar .navbar-brand{
    margin-left:0 !important;
    padding-left:1rem !important;
}

    .bio-brand-name {
        color: #fff; 
        font-weight: 700;
        font-size: clamp(1.05rem, 2.2vw + .6rem, 1.6rem);
        line-height: 1.15;
        margin: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        max-width: 56vw;
    }

    .bio-brand-tagline {
        color: rgba(255, 255, 255, 0.9);
        font-size: 11px;
        letter-spacing: .06em;
        text-transform: uppercase;
    }

    /* ---------- Toggler ---------- */
    .bio-navbar .navbar-toggler {
        border: 2px solid var(--bio-nav-green);
        border-radius: .5rem; 
        padding: .5rem .6rem;
        width: 44px;
        height: 40px;
        position: relative;
        background: transparent;
    }

    .bio-navbar .navbar-toggler:focus {
        box-shadow: 0 0 0 .2rem rgba(25, 135, 84, .25);
    }

    /* Custom 3-line -> X animated icon, replaces Bootstrap's default SVG icon */
    .bio-navbar .navbar-toggler-icon {
        background-image: none !important;
        position: relative;
        display: block;
        width: 20px;
        height: 2px;
        background: var(--bio-nav-green);
        border-radius: 2px;
        transition: background-color .2s ease .1s;
    }

    .bio-navbar .navbar-toggler-icon::before,
    .bio-navbar .navbar-toggler-icon::after {
        content: "";
        position: absolute;
        left: 0;
        width: 20px;
        height: 2px;
        background: var(--bio-nav-green);
        border-radius: 2px;
        transition: transform .3s ease, top .3s ease;
    }

    .bio-navbar .navbar-toggler-icon::before { top: -6px; }
    .bio-navbar .navbar-toggler-icon::after { top: 6px; }

    .bio-navbar .navbar-toggler[aria-expanded="true"] .navbar-toggler-icon {
        background: transparent;
    }

    .bio-navbar .navbar-toggler[aria-expanded="true"] .navbar-toggler-icon::before {
        top: 0;
        transform: rotate(45deg);
    }

    .bio-navbar .navbar-toggler[aria-expanded="true"] .navbar-toggler-icon::after {
        top: 0;
        transform: rotate(-45deg);
    }

    /* Smooth open/close for the mobile menu instead of Bootstrap's abrupt collapse */
    @media (max-width: 991.98px) {
        .bio-navbar .navbar-collapse.collapsing {
            transition: height .3s ease;
        }
    }

    /* ---------- Nav links ---------- */
    .bio-navbar .nav-link {
        color: var(--bio-nav-dark) !important;
        font-weight: 600;
        font-size: .95rem;
        padding: 1.1rem 1rem !important;
        position: relative;
        transition: color .25s ease;
    }

    .bio-navbar .nav-link i {
        color: var(--bio-nav-green);  
    }

    .bio-navbar .nav-link::after {
        content: "";
        position: absolute;
        left: 1rem;
        right: 1rem;
        bottom: .55rem; 
        height: 2px;
        background: linear-gradient(90deg, var(--bio-nav-green), var(--bio-nav-gold));
        border-radius: 2px;
        transform: scaleX(0);
        transform-origin: left;
        transition: transform .25s ease;
    }

    .bio-navbar .nav-link:hover,
    .bio-navbar .nav-link.active {
        color: var(--bio-nav-green) !important;
    }

    .bio-navbar .nav-link:hover::after,
    .bio-navbar .nav-link.active::after {
        transform: scaleX(1);
    }

    /* ---------- Dropdown ---------- */
    .bio-navbar .dropdown-menu {
        border: none; 
        border-radius: .9rem;
        box-shadow: 0 18px 36px rgba(20, 30, 25, .16);
        padding: .6rem;
        margin-top: .25rem;
    }

    .bio-navbar .dropdown-item {
        border-radius: .6rem;
        padding: .65rem .85rem;
        font-weight: 500;
        font-size: .92rem;
        transition: background .2s ease, transform .2s ease;
    }

    .bio-navbar .dropdown-item:hover,
    .bio-navbar .dropdown-item:focus {
        background: rgba(25, 135, 84, .08);
        transform: translateX(4px);
    }

    /* ---------- Desktop phone CTA ---------- */
    .bio-nav-phone {
        display: flex;
        align-items: center;
        gap: .6rem;
        background: rgba(25, 135, 84, .08);
        border-radius: 50px;
        padding: .55rem 1.1rem;
        font-weight: 700;  
        color: var(--bio-nav-dark);
        transition: background .25s ease, transform .25s ease;
    }

    .bio-nav-phone i {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        background: var(--bio-nav-green);
        color: #fff;
        display: flex;
        align-items: center; 
        justify-content: center;
        font-size: .8rem;
        margin: 0;
    }

    .bio-nav-phone:hover {
        background: rgba(25, 135, 84, .16);
        transform: translateY(-2px);
        color: var(--bio-nav-dark);
    }

    /* ---------- Mobile menu polish ---------- */
    @media (max-width: 991.98px) {
        .bio-navbar .navbar-collapse {
            background: #fff;
            border-top: 1px solid rgba(25, 135, 84, .12); 
            box-shadow: 0 16px 30px rgba(20, 30, 25, .12);
            max-height: calc(100vh - 70px);
            overflow-y: auto;
        }

        .bio-navbar .navbar-nav {
            padding: .5rem 1rem 1rem !important;
        }

        .bio-navbar .nav-link {
            padding: .85rem .5rem !important;
            border-bottom: 1px solid rgba(20, 30, 25, .06);
        }

        .bio-navbar .nav-link::after {
            display: none;
        }

        .bio-navbar .dropdown-menu {
            box-shadow: none;
            background: var(--bio-nav-dark, #f4f8f6);
            background: #f4f8f6;
            margin: .3rem 0 .5rem;
        }

        .bio-mobile-phone {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .6rem;
            background: var(--bio-nav-green);
            color: #fff;
            font-weight: 700;
            border-radius: 50px;
            padding: .75rem 1.25rem;
            margin-top: .5rem;
            text-decoration: none;
        }

        .bio-mobile-phone:hover {
            background: var(--bio-nav-green-dark);
            color: #fff;
        }
    }

    @media (min-width: 992px) {
        .bio-mobile-phone {
            display: none;
        }
    }

    /* ---------- Small phones ---------- */
    @media (max-width: 575.98px) {
        .bio-navbar .navbar-brand {
            padding: .65rem .85rem;
        }

        .bio-brand-logo {
            height: 34px !important;
        }

        .bio-brand-tagline {
            display: none;  
        }
    }
</style>

<!-- Navbar Start -->
<nav class="navbar navbar-expand-lg navbar-light bio-navbar sticky-top py-lg-0">
    <div class="container-fluid">
        
        <a href="index.php" class="navbar-brand d-flex align-items-center">
            <img src="img/logo.png" alt="Biome Enterprises Logo" class="bio-brand-logo me-2 me-md-3">
            <div class="d-flex flex-column">
                <span class="bio-brand-name">Biome Enterprises</span>
                <small class="bio-brand-tagline">Logistics &bull; Bamboo &bull; Compliance</small>
            </div>
        </a>

        <button type="button" class="navbar-toggler me-3 me-lg-4" data-bs-toggle="collapse" data-bs-target="#navbarCollapse" aria-controls="navbarCollapse" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarCollapse">
            <div class="navbar-nav ms-auto align-items-lg-center">

                <!-- Home Link --> 
                <a href="index.php" class="nav-item nav-link">
                    <i class="fa fa-home me-2"></i> Home
                </a>

                <!-- About Link -->
                <a href="about.php" class="nav-item nav-link">
                    <i class="fa fa-info-circle me-2"></i> About 
                </a>
                
                <!-- Services Dropdown -->
                <div class="nav-item dropdown">
                    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown" role="button" aria-expanded="false">
                        <i class="fa fa-concierge-bell me-2"></i> Services
                    </a>

                    <div class="dropdown-menu fade-up m-0">
                        
                        <a href="transportation.php" class="dropdown-item">
                            <i class="fa fa-truck text-primary me-2"></i> Transportation &amp; Logistics
                        </a>

                        <a href="bamboo-trading.php" class="dropdown-item">
                            <i class="fa fa-leaf text-success me-2"></i> Bamboo Trading 
                        </a>
                        
                        <a href="legal.php" class="dropdown-item">
                            <i class="fa fa-balance-scale text-warning me-2"></i> Legal &amp; Compliance
                        </a>

                        <a href="cab.php" class="dropdown-item">
                            <i class="fa fa-car text-info me-2"></i> Cab Rental
                        </a>
                        
                        <a href="hotel.php" class="dropdown-item">
                            <i class="fa fa-hotel text-danger me-2"></i> Hotels &amp; Homestays  
                        </a>

                        <a href="restaurant.php" class="dropdown-item">
                            <i class="fa fa-utensils text-secondary me-2"></i> Restaurant
                        </a>
                        
                    </div>
                </div>

                <!-- Contact Link -->
                <a href="contact.php" class="nav-item nav-link">
                    <i class="fa fa-phone me-2"></i> Contact
                </a>

                <!-- NGO & Sustainability Link --> 
                <a href="ngo.php" class="nav-item nav-link">
                    <i class="fa fa-seedling me-2"></i> NGO
                </a>

                 <!-- Blog Link -->
                <a href="blog.php" class="nav-item nav-link">
                    <i class="fa fa-camera-retro me-2"></i> Blog
                </a>

                <!-- Phone CTA (mobile / collapsed menu only) -->
                <a href="tel:+919678431656" class="bio-mobile-phone">
                    <i class="fa fa-phone-alt"></i> +91 96784 31656  
                </a>

            </div>

            <!-- Phone Number (Desktop Only) -->
            <a href="tel:+919678431656" class="bio-nav-phone ms-lg-4 d-none d-lg-flex">
                <i class="fa fa-phone-alt"></i> 
                <span>+91 96784 31656</span>
            </a>
        </div>
    </div>
</nav>
<!-- Navbar End -->

<!-- Note: the scroll-triggered "scrolled" look is now driven entirely by the
     single rAF-throttled listener in index.php (adds .be-scrolled), so this
     navbar no longer runs its own separate, unthrottled scroll handler. -->
<style>
    /* Keep the same scrolled visual treatment, just driven by .be-scrolled now */
    .bio-navbar.be-scrolled {
        background: rgba(255, 255, 255, 0.96);
        box-shadow: 0 8px 24px rgba(20, 30, 25, .14);
    }
</style>

<script>
    // Add a subtle shadow and background blur once the page is scrolled (cosmetic)
    (function() {
        const nav = document.querySelector('.bio-navbar');
        if (!nav) return;
        window.addEventListener('scroll', function() {
            nav.classList.toggle('scrolled', window.scrollY > 20); 
        });
    })();
</script>