<!-- ===================== Premium Hero Carousel Start ===================== -->
    <style>
        /* Hero Carousel Premium Tweaks */
        #heroCarousel .carousel-item {
            height: 100vh;
            height: 100dvh; /* Smart fix for mobile address bar gaps */
            min-height: 500px;
            background-color: #000;
            overflow: hidden; /* Prevents black bleed */
            position: relative;
        }
        
        #heroCarousel .carousel-item img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            min-height: 100%;
            object-fit: cover;
            animation: zoomIn 20s ease infinite alternate;
        }

        @keyframes zoomIn {
            from { transform: scale(1); }
            to { transform: scale(1.1); }
        }

        /* Desktop Dark Overlay */
        .hero-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(to right, rgba(0, 0, 0, 0.8) 0%, rgba(0, 0, 0, 0.3) 100%);
            z-index: 1;
        }

        .carousel-caption {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            right: 0;
            display: flex;
            align-items: center; /* Vertically centered for Desktop */
            justify-content: flex-start;
            z-index: 2;
            text-align: left;
            padding-bottom: 0;
        }

        .hero-title {
            font-weight: 800;
            letter-spacing: 1px;
            text-shadow: 2px 2px 8px rgba(0,0,0,0.5);
        }
        
        .hero-subtitle {
            text-shadow: 1px 1px 4px rgba(0,0,0,0.5);
            max-width: 800px;
        }

        .hero-accent {
            color: #ffc107;
        }

        /* Modern Glass Buttons */
        .btn-hero {
            padding: 12px 30px;
            border-radius: 50px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            transition: all 0.3s ease;
            backdrop-filter: blur(5px);
        }
        
        .btn-hero-primary {
            background: #198754;
            border: 2px solid #198754;
            color: white;
            box-shadow: 0 8px 20px rgba(25, 135, 84, 0.4);
        }
        
        .btn-hero-primary:hover {
            background: #126b40;
            border-color: #126b40;
            transform: translateY(-3px);
            color: white;
        }

        .btn-hero-outline {
            background: rgba(255, 255, 255, 0.1);
            border: 2px solid white;
            color: white;
        }

        .btn-hero-outline:hover {
            background: white;
            color: #333;
            transform: translateY(-3px);
        }

        /* Custom Navigation Arrows */
        .hero-nav-btn {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255,255,255,0.3);
            transition: all 0.3s ease;
        }
        .carousel-control-prev, .carousel-control-next {
            z-index: 5;
            width: 8%;
        }
        .hero-nav-btn i {
            color: white;
            font-size: 1.5rem;
        }
        .carousel-control-prev:hover .hero-nav-btn,
        .carousel-control-next:hover .hero-nav-btn {
            background: #198754;
            transform: scale(1.1);
            border-color: #198754;
        }

        /* ================= MOBILE RESPONSIVE TWEAKS ================= */
        @media (max-width: 768px) {
            #heroCarousel .carousel-item {
                height: 85vh; /* Reduced height to remove black bottom gap */
                min-height: 450px;
            }
            .carousel-caption {
                text-align: center;
                justify-content: center;
                align-items: flex-start; /* Shifts text up instead of center */
                padding-top: 120px; /* Exact space to position text higher up */
                padding-left: 15px;
                padding-right: 15px;
            }
            
            /* Lighter & Cleaner Mobile Overlay */
            .hero-overlay {
                background: linear-gradient(to bottom, rgba(0, 0, 0, 0.4) 0%, rgba(0, 0, 0, 0.6) 100%);
            }
            
            /* Text Size Adjustments for Mobile */
            .hero-title {
                font-size: 2rem !important; 
                line-height: 1.3;
                margin-bottom: 15px !important;
            }
            .hero-subtitle {
                font-size: 1rem !important; 
                margin-bottom: 30px !important;
                padding: 0 10px;
            }
            .carousel-caption h5 {
                font-size: 0.85rem;
                letter-spacing: 1px !important;
                margin-bottom: 10px !important;
            }
            
            /* Compact Buttons for Mobile */
            .btn-hero {
                display: block;
                width: 100%;
                margin-bottom: 12px;
                margin-right: 0 !important;
                padding: 10px 20px;
                font-size: 0.9rem;
            }
            .carousel-control-prev, .carousel-control-next {
                display: none; 
            }
        }
    </style>

    <!-- Removed mb-5 to prevent any extra black margin space at the bottom -->
    <div id="heroCarousel" class="carousel slide" data-bs-ride="carousel" data-bs-interval="5000">

        <!-- Animated Background Blobs -->
        <div class="be-blob d-none d-lg-block" style="width:300px;height:300px;top:-5%;left:-5%;background:rgba(255, 193, 7, 0.6);filter:blur(50px);z-index:3;pointer-events:none;"></div>
        <div class="be-blob d-none d-lg-block" style="width:250px;height:250px;bottom:5%;right:-5%;background:rgba(25, 135, 84, 0.6);filter:blur(50px);animation-delay:2s;z-index:3;pointer-events:none;"></div>

        <div class="carousel-indicators z-3">
            <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="0" class="active" aria-current="true" aria-label="Slide 1"></button>
            <button type="button" data-bs-target="#heroCarousel" data-bs-slide-to="1" aria-label="Slide 2"></button>
        </div>

        <div class="carousel-inner">

            <!-- Slide 1 -->
            <div class="carousel-item active">
                <img src="img/carousel-1.png" alt="Transport and Logistics" fetchpriority="high" loading="eager" decoding="async">
                <div class="hero-overlay"></div>
                
                <div class="carousel-caption">
                    <div class="container">
                        <div class="row justify-content-center justify-content-lg-start">
                            <div class="col-12 col-md-10 col-lg-8">
                                <h5 class="text-white text-uppercase mb-3 fw-bold tracking-wider" style="letter-spacing: 2px;">Transport & Logistics Solution</h5>
                                <h1 class="display-3 text-white mb-4 hero-title">
                                    NORTHEAST INDIA'S LEADING B2B<br>
                                    <span class="hero-accent">LOGISTICS</span> & 
                                    <span class="hero-accent">BAMBOO FIRM</span>
                                </h1>
                                <p class="fs-5 text-light mb-4 hero-subtitle">
                                    Biome Enterprises delivers sophisticated supply chain solutions, industrial bamboo procurement, and corporate fleet oversight across all eight states.
                                </p>
                                <div class="d-flex flex-column flex-md-row mt-3">
                                    <a href="#quote-section" class="btn btn-hero btn-hero-primary me-md-3">BOOK A TRUCK</a>
                                    <a href="#" class="btn btn-hero btn-hero-outline">TRACK CONSOLE</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Slide 2 -->
            <div class="carousel-item">
                <img src="img/carousel-2.png" alt="Bamboo Trading" loading="lazy">
                <div class="hero-overlay"></div>
                
                <div class="carousel-caption">
                    <div class="container">
                        <div class="row justify-content-center justify-content-lg-start">
                            <div class="col-12 col-md-10 col-lg-8">
                                <h5 class="text-white text-uppercase mb-3 fw-bold tracking-wider" style="letter-spacing: 2px;">Premium Bamboo Supplies</h5>
                                <h2 class="display-3 text-white mb-4 hero-title">
                                    SUSTAINABLE TRADING &<br>
                                    <span class="hero-accent">INDUSTRIAL</span> BAMBOO
                                </h2>
                                <p class="fs-5 text-light mb-4 hero-subtitle">
                                    Supplying top-quality raw bamboo, poles, and sustainable materials for construction, handicrafts, and industrial needs pan-India.
                                </p>
                                <div class="d-flex flex-column flex-md-row mt-3">
                                    <a href="#services-section" class="btn btn-hero btn-hero-primary me-md-3">EXPLORE PRODUCTS</a>
                                    <a href="https://wa.me/919678431656" class="btn btn-hero btn-hero-outline">GET A QUOTE</a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <button class="carousel-control-prev" type="button" data-bs-target="#heroCarousel" data-bs-slide="prev">
            <div class="hero-nav-btn">
                <i class="fas fa-chevron-left"></i>
            </div>
            <span class="visually-hidden">Previous</span>
        </button>
        <button class="carousel-control-next" type="button" data-bs-target="#heroCarousel" data-bs-slide="next">
            <div class="hero-nav-btn">
                <i class="fas fa-chevron-right"></i>
            </div>
            <span class="visually-hidden">Next</span>
        </button>
    </div>
    <!-- ===================== Premium Hero Carousel End ===================== -->