<!-- ===================================================
     FOOTER STYLES — self-contained, mobile-first,
     namespaced under "bio-footer" so nothing here
     conflicts with other page styles.
==================================================== -->
<style>
    :root {
        --bio-foot-green: #198754;
        --bio-foot-green-dark: #0f4c2d;
        --bio-foot-gold: #f0a500;
        --bio-foot-dark: #14181a;
    }

    .bio-footer {
        background: linear-gradient(180deg, var(--bio-foot-dark) 0%, #0c0f0d 100%);
        position: relative;
        overflow: hidden;
        height: auto !important;
        min-height: 0 !important;
    }

    .bio-footer *,
    .bio-footer *::before,
    .bio-footer *::after {
        box-sizing: border-box;
    }

    .bio-footer::before {
        content: "";
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--bio-foot-green), var(--bio-foot-gold), var(--bio-foot-green));
    }

    .bio-footer h3,
    .bio-footer h4 {
        font-weight: 700;
    }

    .bio-footer h4 {
        font-size: 1.05rem;
        position: relative;
        padding-bottom: .65rem;
    }

    .bio-footer h4::after {
        content: "";
        position: absolute;
        left: 0;
        bottom: 0;
        width: 36px;
        height: 3px;
        border-radius: 3px;
        background: var(--bio-foot-green);
    }

    .bio-footer p,
    .bio-footer a {
        font-size: .92rem;
    }

    .bio-footer .text-light {
        color: #c9d4ce !important;
    }

    /* ---------- Contact rows ---------- */
    .bio-footer-contact {
        display: flex;
        align-items: flex-start;
        gap: .75rem;
        margin-bottom: .9rem;
    }

    .bio-footer-contact i {
        width: 34px;
        height: 34px;
        flex-shrink: 0;
        border-radius: 50%;
        background: rgba(25, 135, 84, .15);
        color: var(--bio-foot-green);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: .85rem;
    }

    .bio-footer-contact a,
    .bio-footer-contact span {
        color: #c9d4ce;
        transition: color .2s ease;
    }

    .bio-footer-contact a:hover {
        color: var(--bio-foot-gold);
    }

    /* ---------- Social icons ---------- */
    .bio-footer .btn-social {
        width: 40px;
        height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 0;
        border-color: rgba(25, 135, 84, .4);
        color: #fff;
        transition: var(--bio-transition, all .25s ease);
    }

    .bio-footer .btn-social:hover {
        background: var(--bio-foot-green);
        border-color: var(--bio-foot-green);
        transform: translateY(-3px);
        color: #fff;
    }

    /* ---------- Link lists ---------- */
    .bio-footer-links {
        display: flex;
        flex-direction: column;
    }

    .bio-footer .btn-link {
        color: #c9d4ce;
        text-decoration: none;
        text-align: left;
        padding: .4rem 0;
        font-weight: 500;
        border: none;
        transition: color .2s ease, transform .2s ease, padding-left .2s ease;
    }

    .bio-footer .btn-link::before {
        content: "\f105";
        font-family: "Font Awesome 5 Free";
        font-weight: 900;
        color: var(--bio-foot-green);
        margin-right: .5rem;
        opacity: 0;
        transition: opacity .2s ease;
    }

    .bio-footer .btn-link:hover {
        color: var(--bio-foot-gold);
        padding-left: .25rem;
    }

    .bio-footer .btn-link:hover::before {
        opacity: 1;
    }

    /* ---------- CTA card ---------- */
    .bio-footer-cta {
        background: rgba(255, 255, 255, .04);
        border: 1px solid rgba(25, 135, 84, .25);
        border-radius: 1rem;
        padding: 1.5rem;
    }

    .bio-footer-cta .btn {
        border-radius: 50px;
        font-weight: 600;
        transition: transform .25s ease, box-shadow .25s ease;
    }

    .bio-footer-cta .btn-success:hover {
        transform: translateY(-3px);
        box-shadow: 0 14px 26px rgba(25, 135, 84, .3);
    }

    .bio-footer-cta .btn-outline-light:hover {
        transform: translateY(-3px);
        background: #fff;
        color: var(--bio-foot-dark);
    }

    /* ---------- Bottom bar ---------- */
    .bio-footer-bottom {
        border-top: 1px solid rgba(255, 255, 255, .1);
        font-size: .85rem;
        color: #a9b6af;
    }

    .bio-footer-bottom strong {
        color: #fff;
    }

    /* ===================================================
       RESPONSIVE — mobile-first stacking & spacing
    ==================================================== */
    @media (max-width: 991.98px) {
        .bio-footer .row.g-5 {
            --bs-gutter-y: 1.5rem;
        }

        .bio-footer .row.g-5 > div {
            margin-bottom: 0;
        }
    }

    @media (max-width: 767.98px) {
        .bio-footer {
            margin-top: 3rem !important;
            text-align: center;
        }

        .bio-footer .container.py-5 {
            padding-top: 2.5rem !important;
            padding-bottom: 1.5rem !important;
        }

        .bio-footer h4::after {
            left: 50%;
            transform: translateX(-50%);
        }

        .bio-footer-contact {
            justify-content: center;
            text-align: left;
            max-width: 320px;
            margin-left: auto;
            margin-right: auto;
        }

        .bio-footer .d-flex.social-row {
            justify-content: center;
        }

        .bio-footer-links {
            align-items: center;
        }

        .bio-footer .btn-link::before {
            display: none;
        }

        .bio-footer-cta {
            margin-top: 0;
        }
    }

    @media (max-width: 575.98px) {
        .bio-footer .container,
        .bio-footer .container-fluid {
            padding-left: 1.1rem !important;
            padding-right: 1.1rem !important;
        }

        .bio-footer h3 {
            font-size: 1.4rem;
        }

        .bio-footer-cta {
            padding: 1.25rem;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .bio-footer * {
            transition-duration: .001ms !important;
        }
    }
</style>

<!-- Footer Start -->
<div class="container-fluid bio-footer text-light pt-5 wow fadeIn" data-wow-delay="0.1s" style="margin-top:6rem;">
    <div class="container py-5">
        <div class="row g-5 reveal reveal-stagger">

            <!-- Company Info -->
            <div class="col-lg-4 col-md-6">
                <h3 class="text-white mb-4">
                    <span class="text-success">Biome</span> Enterprises
                </h3>

                <p class="mb-4">
                    Your trusted partner for transportation & logistics, bamboo trading, legal & compliance, accounting, cab rentals, and hospitality solutions across North-East India and major business hubs nationwide.
                </p>

                <div class="bio-footer-contact">
                    <i class="fa fa-map-marker-alt"></i>
                    <span>Assam, India</span>
                </div>

                <div class="bio-footer-contact">
                    <i class="fa fa-phone-alt"></i>
                    <a href="tel:+919678431656" class="text-decoration-none">
                        +91 96784 31656
                    </a>
                </div>

                <div class="bio-footer-contact">
                    <i class="fa fa-envelope"></i>
                    <a href="mailto:info@biomeenterprises.com" class="text-decoration-none">
                        info@biomeenterprises.com
                    </a>
                </div>

                <div class="d-flex social-row mt-4">

                    <a class="btn btn-outline-success btn-social rounded-circle me-2" href="#" aria-label="Facebook">
                        <i class="fab fa-facebook-f"></i>
                    </a>

                    <a class="btn btn-outline-success btn-social rounded-circle me-2" href="#" aria-label="Instagram">
                        <i class="fab fa-instagram"></i>
                    </a>

                    <a class="btn btn-outline-success btn-social rounded-circle me-2" href="#" aria-label="LinkedIn">
                        <i class="fab fa-linkedin-in"></i>
                    </a>

                    <a class="btn btn-outline-success btn-social rounded-circle" href="#" aria-label="WhatsApp">
                        <i class="fab fa-whatsapp"></i>
                    </a>

                </div>
            </div>

            <!-- Services -->

            <div class="col-lg-2 col-md-6">

                <h4 class="text-white mb-4">Our Services</h4>

                <div class="bio-footer-links">
                    <a class="btn btn-link" href="transportation.php">Transportation</a>
                    <a class="btn btn-link" href="bamboo.php">Bamboo Trading</a>
                    <a class="btn btn-link" href="legal.php">Legal & Compliance</a>
                    <a class="btn btn-link" href="cab.php">Cab Rental</a>
                    <a class="btn btn-link" href="hotel.php">Hotels & Homestays</a>
                    <a class="btn btn-link" href="restaurant.php">Restaurant Booking</a>
                </div>

            </div>

            <!-- Quick Links -->

            <div class="col-lg-3 col-md-6">

                <h4 class="text-white mb-4">Quick Links</h4>

                <div class="bio-footer-links">
                    <a class="btn btn-link" href="index.php">Home</a>
                    <a class="btn btn-link" href="about.php">About Us</a>
                    <a class="btn btn-link" href="service.php">Our Services</a>
                    <a class="btn btn-link" href="quote.php">Get a Quote</a>
                    <a class="btn btn-link" href="contact.php">Contact Us</a>
                    <a class="btn btn-link" href="privacy-policy.php">Privacy Policy</a>
                </div>

            </div>

            <!-- Contact CTA -->

            <div class="col-lg-3 col-md-6">

                <div class="bio-footer-cta">
                    <h4 class="text-white mb-4">Need Assistance?</h4>

                    <p>
                        Looking for transportation, bamboo supply, business registration, or travel services? Contact our experts today.
                    </p>

                    <a href="quote.php" class="btn btn-success w-100 py-3 mb-3">
                        Request Free Quote
                    </a>

                    <a href="tel:+919678431656" class="btn btn-outline-light w-100 py-3">
                        <i class="fa fa-phone me-2"></i> Call Now
                    </a>
                </div>

            </div>

        </div>
    </div>

    <!-- Bottom Footer -->

    <div class="container bio-footer-bottom pt-4 pb-4">

        <div class="row align-items-center g-2">

            <div class="col-md-6 text-center text-md-start">

                &copy; 2026 <strong>  <a href="https://biomeenterprises.com" >  Biome Enterprises </a></strong>. All Rights Reserved.

            </div>

            <div class="col-md-6 text-center text-md-end">

                Serving Businesses Across
                <span class="text-success">
                    North-East India & Pan India
                </span>

            </div>

        </div>

    </div>
</div>
<!-- Footer End -->


    <!-- Footer  -->
    <?php   include __DIR__ . '/widget.php'; ?>
    <!-- Footer end -->




    <!-- JavaScript Libraries (Added defer for better page load performance) -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js" defer></script>
    
    
    
    
    
     <!--<script src="js/navbar-active-state.js"></script>-->
    
    
    
    
    <!-- Template Javascript -->
    <!-- <script src="js/main.js"></script>  -->
    
    
    
    <!-- ===================== Counter Animation (Optimized Vanilla JS) ===================== -->
    <script>
    (function () {
        const counters = document.querySelectorAll('.counter-number');
        if (counters.length === 0) return;

        const duration = 1800; // ms

        function animateCounter(el) {
            const target = parseInt(el.getAttribute('data-target'), 10) || 0;
            const start = performance.now();

            function step(now) {
                const progress = Math.min((now - start) / duration, 1);
                // easeOutQuad calculation
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
            counters.forEach(animateCounter); // Fallback for older browsers
        }
    })();
    </script>

    <!-- ===================== Premium Interactivity (Scroll progress, Reveal, Navbar, UI) ===================== -->
    <script>
    (function () {
        
        // 1. Hero Image Zoom Logic
        const heroImg = document.querySelector('#heroCarousel .active img');
        if (heroImg) {
            if (heroImg.complete) {
                heroImg.classList.add('zoomed');
            } else {
                heroImg.addEventListener('load', function () {
                    this.classList.add('zoomed');
                });
            }
        }

        // 2. Scroll Progress Bar (rAF-throttled for high performance)
        const progressBar = document.getElementById('scrollProgress');
        let progressTicking = false;
        function updateProgress() {
            const h = document.documentElement;
            const scrolled = (h.scrollTop) / (h.scrollHeight - h.clientHeight) * 100;
            if (progressBar) progressBar.style.width = scrolled + '%';
            progressTicking = false;
        }

        // 3. Navbar Shadow & Back-to-Top Button Toggle
        const nav = document.querySelector('nav.navbar, .navbar');
        const backToTopBtn = document.querySelector('.back-to-top');
        let navTicking = false;
        
        function updateNavAndScroll() {
            const scrollY = window.scrollY;
            
            // Navbar toggle
            if (nav) {
                nav.classList.toggle('be-scrolled', scrollY > 40);
            }
            
            // Back to top toggle
            if (backToTopBtn) {
                if (scrollY > 300) {
                    backToTopBtn.style.opacity = '1';
                    backToTopBtn.style.visibility = 'visible';
                } else {
                    backToTopBtn.style.opacity = '0';
                    backToTopBtn.style.visibility = 'hidden';
                }
            }
            navTicking = false;
        }

        window.addEventListener('scroll', function () {
            if (!progressTicking) {
                requestAnimationFrame(updateProgress);
                progressTicking = true;
            }
            if (!navTicking) {
                requestAnimationFrame(updateNavAndScroll);
                navTicking = true;
            }
        }, { passive: true });
        
        // Initial calls
        updateProgress();
        updateNavAndScroll();

        // Smooth Scroll for Back-to-Top
        if(backToTopBtn) {
            backToTopBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        }

        // 4. Reveal-on-scroll Sections
        const reveals = document.querySelectorAll('.reveal');
        if (reveals.length > 0) {
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
        }

        // 5. Hardware Accelerated Cursor Glow (Desktop Only)
        const glow = document.getElementById('cursorGlow');
        if (glow && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            window.addEventListener('mousemove', function (e) {
                // Using transform is much smoother for rendering than left/top
                glow.style.transform = `translate3d(${e.clientX}px, ${e.clientY}px, 0)`;
            }, { passive: true });
        }

        // 6. 3D Tilt Effect on Cards (SaaS-style premium feel)
        const tiltCards = document.querySelectorAll('.tilt-card');
        if (tiltCards.length > 0 && window.matchMedia('(hover: hover) and (pointer: fine)').matches) {
            tiltCards.forEach(function (card) {
                card.addEventListener('mousemove', function (e) {
                    const rect = card.getBoundingClientRect();
                    const x = e.clientX - rect.left;
                    const y = e.clientY - rect.top;
                    const rotateX = ((y / rect.height) - 0.5) * -10;
                    const rotateY = ((x / rect.width) - 0.5) * 10;
                    card.style.transform = `perspective(800px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) translateY(-8px)`;
                });
                card.addEventListener('mouseleave', function () {
                    card.style.transform = '';
                });
            });
        }
    })();
    </script>

 




<script>
    // Magnetic hover on footer social icons (desktop/hover-capable only)
    (function () {
        if (!window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        document.querySelectorAll('.bio-footer .btn-social').forEach(function (btn) {
            btn.addEventListener('mousemove', function (e) {
                const rect = btn.getBoundingClientRect();
                const x = (e.clientX - rect.left - rect.width / 2) * 0.35;
                const y = (e.clientY - rect.top - rect.height / 2) * 0.35;
                btn.style.transform = 'translate(' + x + 'px,' + (y - 3) + 'px)';
            });
            btn.addEventListener('mouseleave', function () {
                btn.style.transform = '';
            });
        });
    })();
</script>