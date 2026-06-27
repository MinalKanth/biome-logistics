<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="utf-8">

    <title>Biome Enterprises | NGO & Sustainability</title>

    <meta content="width=device-width, initial-scale=1.0" name="viewport">

    <meta name="keywords" content="NGO, Sustainability, Environment, Bamboo Plantation, Green India, CSR, Community Development, Biome Enterprises, Assam">

    <meta name="description" content="Biome Enterprises is committed to sustainability, environmental conservation, bamboo plantation, community development, skill development, waste management, and creating a greener future through impactful NGO initiatives.">

    <!-- Favicon -->
    <link href="img/favicon.ico" rel="icon">

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">

    <!-- Font Awesome -->

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">

    <!-- Bootstrap Icons -->

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Libraries -->

    <link href="lib/animate/animate.min.css" rel="stylesheet">

    <link href="lib/owlcarousel/assets/owl.carousel.min.css" rel="stylesheet">

    <!-- Bootstrap -->

    <link href="css/bootstrap.min.css" rel="stylesheet">

    <!-- Main CSS -->

    <link href="css/style.css" rel="stylesheet">

    <link href="css/style-ngo.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">

    <style>
         :root {
            --primary: #198754;
            --secondary: #0d6efd;
            --dark: #1f2937;
            --light: #f8f9fa;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f8fafc;
            overflow-x: hidden;
        }
        
        .page-header {
            position: relative;
            background: linear-gradient(rgba(0, 35, 20, .75), rgba(0, 35, 20, .75)), url("img/ngo-banner.jpg");
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            overflow: hidden;
        }
        
        .page-header::before {
            content: "";
            position: absolute;
            width: 500px;
            height: 500px;
            background: rgba(25, 135, 84, .18);
            border-radius: 50%;
            top: -220px;
            right: -150px;
            animation: floatBlob 10s ease-in-out infinite alternate;
        }
        
        .page-header::after {
            content: "";
            position: absolute;
            width: 300px;
            height: 300px;
            background: rgba(255, 255, 255, .08);
            border-radius: 50%;
            left: -80px;
            bottom: -80px;
            animation: floatBlob2 8s ease-in-out infinite alternate;
        }
        
        @keyframes floatBlob {
            from {
                transform: translateY(0);
            }
            to {
                transform: translateY(50px);
            }
        }
        
        @keyframes floatBlob2 {
            from {
                transform: translateX(0);
            }
            to {
                transform: translateX(50px);
            }
        }
        
        .glass-card {
            background: rgba(255, 255, 255, .15);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, .2);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .15);
        }
        /* Use on plain/light backgrounds so the glass effect and white text stay readable */
        
        .glass-card-dark {
            background: linear-gradient(135deg, rgba(15, 60, 40, .92), rgba(10, 40, 28, .92));
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, .15);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .15);
        }
        
        .section-title {
            font-weight: 700;
            position: relative;
            padding-bottom: 15px;
        }
        
        .section-title::after {
            content: "";
            position: absolute;
            width: 80px;
            height: 4px;
            background: var(--primary);
            left: 50%;
            bottom: 0;
            transform: translateX(-50%);
            border-radius: 50px;
        }
        
        .btn-success {
            border-radius: 50px;
            padding: 14px 34px;
            transition: .35s;
        }
        
        .btn-success:hover {
            transform: translateY(-4px);
        }
        
        .floating {
            animation: floating 3.5s ease-in-out infinite;
        }
        
        @keyframes floating {
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

</head>

<body>

    <!-- ===========================
            NAVBAR
    ============================ -->

    <!-- <div id="navbar"></div> -->
     <?php include __DIR__ . '/navbar.php'; ?>

    <!-- Navbar End -->


    <!-- ===========================
            HERO SECTION
    ============================ -->

    <div class="container-fluid page-header position-relative overflow-hidden py-5">

        <div class="container py-5 position-relative">

            <div class="row align-items-center">

                <div class="col-lg-7 wow fadeInLeft" data-wow-delay="0.1s">

                    <h6 class="text-uppercase text-warning fw-bold mb-3">

                        Biome Foundation

                    </h6>

                    <h1 class="display-3 text-white fw-bold mb-4">

                        NGO &
                        <span class="text-success">
                            Sustainability
                        </span>

                    </h1>

                    <p class="text-light fs-5 mb-4">

                        Building a greener tomorrow through environmental conservation, bamboo plantation, community empowerment, education and sustainable development initiatives across North-East India.

                    </p>

                    <div class="d-flex flex-wrap gap-3">

                        <a href="#mission" class="btn btn-success">

                            Explore Our Mission

                        </a>

                        <a href="contact.php" class="btn btn-outline-light rounded-pill px-4 py-3">

                            Become a Volunteer

                        </a>

                    </div>

                </div>


                <div class="col-lg-5 text-center mt-5 mt-lg-0 wow fadeInRight" data-wow-delay="0.3s">

                    <div class="glass-card p-5 floating">

                        <i class="fas fa-seedling fa-5x text-success mb-4"></i>

                        <h3 class="text-white fw-bold">

                            Together We Can Create A Greener Future

                        </h3>

                        <p class="text-light mb-0">

                            Every tree planted, every community empowered, every life transformed contributes to a sustainable tomorrow.

                        </p>

                    </div>

                </div>

            </div>

            <nav class="mt-5">

                <ol class="breadcrumb">

                    <li class="breadcrumb-item">

                        <a class="text-white" href="index.php">

                            Home

                        </a>

                    </li>

                    <li class="breadcrumb-item text-white active">

                        NGO & Sustainability

                    </li>

                </ol>

            </nav>

        </div>

    </div>

    <!-- Hero End -->


    <!-- ===========================
        QUICK IMPACT SECTION
    ============================ -->

    <div class="container py-5">

        <div class="row g-4">

            <div class="col-md-3">

                <div class="glass-card bg-white text-center p-4 h-100 wow fadeInUp">

                    <i class="fas fa-tree fa-3x text-success mb-3"></i>

                    <h2 class="fw-bold text-success">

                        10K+

                    </h2>

                    <h6>

                        Trees To Be Planted

                    </h6>

                </div>

            </div>


            <div class="col-md-3">

                <div class="glass-card bg-white text-center p-4 h-100 wow fadeInUp" data-wow-delay=".2s">

                    <i class="fas fa-users fa-3x text-success mb-3"></i>

                    <h2 class="fw-bold text-success">

                        500+

                    </h2>

                    <h6>

                        Families To Support

                    </h6>

                </div>

            </div>


            <div class="col-md-3">

                <div class="glass-card bg-white text-center p-4 h-100 wow fadeInUp" data-wow-delay=".4s">

                    <i class="fas fa-hands-helping fa-3x text-success mb-3"></i>

                    <h2 class="fw-bold text-success">

                        100+

                    </h2>

                    <h6>

                        Volunteers

                    </h6>

                </div>

            </div>


            <div class="col-md-3">

                <div class="glass-card bg-white text-center p-4 h-100 wow fadeInUp" data-wow-delay=".6s">

                    <i class="fas fa-globe-asia fa-3x text-success mb-3"></i>

                    <h2 class="fw-bold text-success">

                        20+

                    </h2>

                    <h6>

                        Sustainability Programs

                    </h6>

                </div>

            </div>

        </div>

    </div>



    <!-- ===========================
            MISSION SECTION
    ============================ -->

    <section id="mission" class="py-5">

        <div class="container">

            <div class="text-center mb-5">

                <h6 class="text-success text-uppercase fw-bold">

                    Our Mission

                </h6>

                <h2 class="section-title">

                    Creating Sustainable Communities

                </h2>

                <p class="text-muted mt-4">

                    Biome Enterprises is committed to promoting sustainable development by protecting the environment, supporting rural communities, encouraging bamboo cultivation, empowering youth, and creating long-term social impact through responsible initiatives.

                </p>

            </div>

            <div class="row">

                <div class="col-lg-6 wow fadeInLeft" data-wow-delay="0.1s">

                    <div class="pe-lg-5">

                        <h6 class="text-success text-uppercase fw-bold mb-3">

                            Our Purpose

                        </h6>

                        <h2 class="display-5 fw-bold mb-4">

                            Building A Better Future Through Sustainable Development

                        </h2>

                        <p class="mb-4">

                            At <strong>Biome Enterprises</strong>, sustainability is more than a responsibility—it is our foundation. Through our NGO initiatives, we strive to create meaningful environmental, social and economic impact by empowering communities
                            and preserving natural resources for future generations.

                        </p>

                        <p class="mb-4">

                            We collaborate with local communities, educational institutions, government agencies, NGOs and corporate partners to implement sustainable solutions that improve livelihoods while protecting the environment.

                        </p>

                        <div class="row g-4 mt-3">

                            <div class="col-sm-6">

                                <div class="d-flex">

                                    <i class="fas fa-check-circle text-success fa-2x me-3 mt-1"></i>

                                    <div>

                                        <h5 class="fw-bold">

                                            Environmental Protection

                                        </h5>

                                        <p class="mb-0 text-muted">

                                            Promoting conservation, afforestation and climate resilience initiatives.

                                        </p>

                                    </div>

                                </div>

                            </div>

                            <div class="col-sm-6">

                                <div class="d-flex">

                                    <i class="fas fa-check-circle text-success fa-2x me-3 mt-1"></i>

                                    <div>

                                        <h5 class="fw-bold">

                                            Community Development

                                        </h5>

                                        <p class="mb-0 text-muted">

                                            Supporting education, healthcare and livelihood opportunities.

                                        </p>

                                    </div>

                                </div>

                            </div>

                            <div class="col-sm-6">

                                <div class="d-flex">

                                    <i class="fas fa-check-circle text-success fa-2x me-3 mt-1"></i>

                                    <div>

                                        <h5 class="fw-bold">

                                            Sustainable Agriculture

                                        </h5>

                                        <p class="mb-0 text-muted">

                                            Encouraging eco-friendly farming and bamboo-based livelihoods.

                                        </p>

                                    </div>

                                </div>

                            </div>

                            <div class="col-sm-6">

                                <div class="d-flex">

                                    <i class="fas fa-check-circle text-success fa-2x me-3 mt-1"></i>

                                    <div>

                                        <h5 class="fw-bold">

                                            Youth Empowerment

                                        </h5>

                                        <p class="mb-0 text-muted">

                                            Building skills, entrepreneurship and employment opportunities.

                                        </p>

                                    </div>

                                </div>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-lg-6 wow fadeInRight" data-wow-delay="0.3s">

                    <div class="glass-card-dark p-5 h-100">

                        <div class="text-center mb-4">

                            <i class="fas fa-leaf fa-5x text-success mb-4 floating"></i>

                            <h3 class="fw-bold text-white">

                                Our Vision

                            </h3>

                        </div>

                        <p class="text-light mb-4">

                            To become a leading force in sustainable development by creating environmentally responsible businesses and empowering communities through innovation, collaboration and social responsibility.

                        </p>

                        <hr class="bg-light">

                        <div class="d-flex mb-4">

                            <i class="fas fa-seedling text-success fa-2x me-3 mt-1"></i>

                            <div>

                                <h5 class="text-white">

                                    Green Environment

                                </h5>

                                <p class="text-light mb-0">

                                    Plantation drives, biodiversity conservation and eco-restoration.

                                </p>

                            </div>

                        </div>

                        <div class="d-flex mb-4">

                            <i class="fas fa-recycle text-success fa-2x me-3 mt-1"></i>

                            <div>

                                <h5 class="text-white">

                                    Circular Economy

                                </h5>

                                <p class="text-light mb-0">

                                    Waste reduction, recycling and sustainable resource utilization.

                                </p>

                            </div>

                        </div>

                        <div class="d-flex">

                            <i class="fas fa-hands-helping text-success fa-2x me-3 mt-1"></i>

                            <div>

                                <h5 class="text-white">

                                    Inclusive Growth

                                </h5>

                                <p class="text-light mb-0">

                                    Empowering women, youth, artisans and rural communities through sustainable opportunities.

                                </p>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>

    <!-- Mission Section End -->


    <!-- ===========================
        CORE VALUES SECTION
=========================== -->

    <section class="py-5 bg-light">

        <div class="container">

            <div class="text-center mb-5">

                <h6 class="text-success text-uppercase fw-bold">

                    Our Core Values

                </h6>

                <h2 class="section-title">

                    Principles That Guide Every Initiative

                </h2>

                <p class="text-muted mt-4">

                    We believe meaningful change begins with responsible actions, transparent governance and active community participation.

                </p>

            </div>

            <div class="row g-4">

                <div class="col-lg-4 col-md-6 wow fadeInUp">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-seedling fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">

                            Environmental Care

                        </h4>

                        <p class="text-muted mb-0">

                            Promoting afforestation, bamboo cultivation, biodiversity conservation and sustainable environmental practices.

                        </p>

                    </div>

                </div>

                <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay=".2s">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-users fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">

                            Community First

                        </h4>

                        <p class="text-muted mb-0">

                            Supporting education, healthcare, employment generation and rural development programs.

                        </p>

                    </div>

                </div>

                <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay=".4s">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-handshake fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">

                            Collaboration

                        </h4>

                        <p class="text-muted mb-0">

                            Working together with government, NGOs, educational institutions and corporate partners.

                        </p>

                    </div>

                </div>

                <div class="col-lg-4 col-md-6 wow fadeInUp">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-lightbulb fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">

                            Innovation

                        </h4>

                        <p class="text-muted mb-0">

                            Developing sustainable and practical solutions for long-term social and environmental impact.

                        </p>

                    </div>

                </div>

                <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay=".2s">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-recycle fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">

                            Sustainability

                        </h4>

                        <p class="text-muted mb-0">

                            Encouraging responsible resource management and circular economy practices.

                        </p>

                    </div>

                </div>

                <div class="col-lg-4 col-md-6 wow fadeInUp" data-wow-delay=".4s">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-globe-asia fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">

                            Global Responsibility

                        </h4>

                        <p class="text-muted mb-0">

                            Aligning our initiatives with the United Nations Sustainable Development Goals (SDGs).

                        </p>

                    </div>

                </div>

            </div>

        </div>

    </section>



    <!-- ===========================
        SUSTAINABILITY PILLARS
=========================== -->

    <section class="py-5">

        <div class="container">

            <div class="text-center mb-5">

                <h6 class="text-success text-uppercase fw-bold">

                    Sustainability Pillars

                </h6>

                <h2 class="section-title">

                    Areas We Focus On

                </h2>

            </div>

            <div class="row g-4">

                <div class="col-lg-3 col-md-6">

                    <div class="glass-card-dark p-4 text-center h-100">

                        <i class="fas fa-tree fa-3x text-success mb-4"></i>

                        <h5 class="text-white">

                            Tree Plantation

                        </h5>

                        <p class="text-light mb-0">

                            Restoring green cover through plantation drives and awareness campaigns.

                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="glass-card-dark p-4 text-center h-100">

                        <i class="fas fa-leaf fa-3x text-success mb-4"></i>

                        <h5 class="text-white">

                            Bamboo Mission

                        </h5>

                        <p class="text-light mb-0">

                            Promoting bamboo cultivation as a sustainable livelihood and eco-friendly resource.

                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="glass-card-dark p-4 text-center h-100">

                        <i class="fas fa-book-reader fa-3x text-success mb-4"></i>

                        <h5 class="text-white">

                            Education

                        </h5>

                        <p class="text-light mb-0">

                            Skill development, awareness programs and educational support.

                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="glass-card-dark p-4 text-center h-100">

                        <i class="fas fa-hands-helping fa-3x text-success mb-4"></i>

                        <h5 class="text-white">

                            Social Welfare

                        </h5>

                        <p class="text-light mb-0">

                            Empowering rural communities through healthcare, livelihood and social initiatives.

                        </p>

                    </div>

                </div>

            </div>

        </div>

    </section>

    <!-- ===========================
      PART 2 STARTS HERE
=========================== -->
    <!-- ===========================
        SDGs SECTION
=========================== -->

    <section class="py-5 bg-light">

        <div class="container">

            <div class="text-center mb-5">

                <h6 class="text-success text-uppercase fw-bold">

                    United Nations SDGs

                </h6>

                <h2 class="section-title">

                    Sustainable Development Goals We Support

                </h2>

                <p class="text-muted mt-4">

                    Our initiatives align with the United Nations Sustainable Development Goals to create a cleaner environment, stronger communities and a more sustainable future.

                </p>

            </div>

            <div class="row g-4">

                <div class="col-lg-3 col-md-6 wow fadeInUp">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-heart fa-3x text-danger mb-3"></i>

                        <h5>Good Health</h5>

                        <p class="mb-0">

                            Supporting community healthcare, sanitation and awareness initiatives.

                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6 wow fadeInUp" data-wow-delay=".2s">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-graduation-cap fa-3x text-primary mb-3"></i>

                        <h5>Quality Education</h5>

                        <p class="mb-0">

                            Skill development, literacy and educational empowerment programs.

                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6 wow fadeInUp" data-wow-delay=".4s">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-leaf fa-3x text-success mb-3"></i>

                        <h5>Climate Action</h5>

                        <p class="mb-0">

                            Plantation drives, biodiversity conservation and environmental awareness.

                        </p>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6 wow fadeInUp" data-wow-delay=".6s">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-hands-helping fa-3x text-warning mb-3"></i>

                        <h5>Partnerships</h5>

                        <p class="mb-0">

                            Working with organizations, institutions and communities for sustainable impact.

                        </p>

                    </div>

                </div>

            </div>

        </div>

    </section>



    <!-- ===========================
        OUR PROGRAMS
=========================== -->

    <section class="py-5">

        <div class="container">

            <div class="text-center mb-5">

                <h6 class="text-success text-uppercase fw-bold">

                    Our Programs

                </h6>

                <h2 class="section-title">

                    Creating Long-Term Social Impact

                </h2>

            </div>

            <div class="row g-4">

                <div class="col-lg-6 wow fadeInLeft">

                    <div class="bg-white rounded-4 shadow p-4 h-100">

                        <div class="d-flex mb-4">

                            <div class="me-4">

                                <i class="fas fa-tree fa-3x text-success"></i>

                            </div>

                            <div>

                                <h4 class="fw-bold">

                                    Tree Plantation Drives

                                </h4>

                                <p class="mb-0">

                                    Organizing plantation campaigns in schools, villages, institutions and public spaces to increase green cover and promote environmental awareness.

                                </p>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-lg-6 wow fadeInRight">

                    <div class="bg-white rounded-4 shadow p-4 h-100">

                        <div class="d-flex mb-4">

                            <div class="me-4">

                                <i class="fas fa-seedling fa-3x text-success"></i>

                            </div>

                            <div>

                                <h4 class="fw-bold">

                                    Bamboo Development

                                </h4>

                                <p class="mb-0">

                                    Promoting bamboo cultivation, processing and entrepreneurship as an eco-friendly and sustainable livelihood opportunity.

                                </p>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-lg-6 wow fadeInLeft" data-wow-delay=".2s">

                    <div class="bg-white rounded-4 shadow p-4 h-100">

                        <div class="d-flex mb-4">

                            <div class="me-4">

                                <i class="fas fa-book-reader fa-3x text-success"></i>

                            </div>

                            <div>

                                <h4 class="fw-bold">

                                    Education & Awareness

                                </h4>

                                <p class="mb-0">

                                    Conducting workshops, awareness campaigns and educational programs focused on sustainability and environmental protection.

                                </p>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-lg-6 wow fadeInRight" data-wow-delay=".2s">

                    <div class="bg-white rounded-4 shadow p-4 h-100">

                        <div class="d-flex mb-4">

                            <div class="me-4">

                                <i class="fas fa-users fa-3x text-success"></i>

                            </div>

                            <div>

                                <h4 class="fw-bold">

                                    Community Empowerment

                                </h4>

                                <p class="mb-0">

                                    Supporting rural communities, women entrepreneurs, artisans and youth through skill development and livelihood initiatives.

                                </p>

                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>

    <!-- ===========================
      PART 2B STARTS HERE
=========================== -->

    <!-- ===========================
        ENVIRONMENTAL INITIATIVES
=========================== -->

    <section class="py-5 bg-light">

        <div class="container">

            <div class="text-center mb-5">

                <h6 class="text-success text-uppercase fw-bold">
                    Environmental Initiatives
                </h6>

                <h2 class="section-title">
                    Protecting Nature Through Action
                </h2>

                <p class="text-muted mt-4">
                    Our environmental initiatives focus on restoring ecosystems, promoting sustainable living and encouraging active community participation for a greener future.
                </p>

            </div>

            <div class="row g-4">

                <div class="col-lg-4 wow fadeInUp">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-tree fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">
                            Tree Plantation
                        </h4>

                        <p class="mb-0">
                            Organizing plantation drives in schools, villages and public spaces to improve biodiversity and reduce carbon emissions.
                        </p>

                    </div>

                </div>

                <div class="col-lg-4 wow fadeInUp" data-wow-delay=".2s">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-recycle fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">
                            Waste Management
                        </h4>

                        <p class="mb-0">
                            Promoting waste segregation, recycling and responsible disposal through awareness programs.
                        </p>

                    </div>

                </div>

                <div class="col-lg-4 wow fadeInUp" data-wow-delay=".4s">

                    <div class="bg-white rounded-4 shadow p-4 h-100 text-center">

                        <i class="fas fa-water fa-3x text-success mb-4"></i>

                        <h4 class="fw-bold mb-3">
                            Water Conservation
                        </h4>

                        <p class="mb-0">
                            Encouraging rainwater harvesting, water conservation and responsible use of natural resources.
                        </p>

                    </div>

                </div>

            </div>

        </div>

    </section>



    <!-- ===========================
        COMMUNITY DEVELOPMENT
=========================== -->

    <section class="py-5">

        <div class="container">

            <div class="row align-items-center">

                <div class="col-lg-6 wow fadeInLeft">

                    <h6 class="text-success text-uppercase fw-bold">

                        Community Development

                    </h6>

                    <h2 class="display-5 fw-bold mb-4">

                        Empowering Communities Through Sustainable Growth

                    </h2>

                    <p class="mb-4">

                        We work closely with local communities to improve education, healthcare, employment opportunities and sustainable livelihoods through impactful social programs.

                    </p>

                    <div class="row g-3">

                        <div class="col-6">

                            <div class="d-flex">

                                <i class="fas fa-check-circle text-success me-3 mt-1"></i>

                                <span>

                                Skill Development

                            </span>

                            </div>

                        </div>

                        <div class="col-6">

                            <div class="d-flex">

                                <i class="fas fa-check-circle text-success me-3 mt-1"></i>

                                <span>

                                Women Empowerment

                            </span>

                            </div>

                        </div>

                        <div class="col-6">

                            <div class="d-flex">

                                <i class="fas fa-check-circle text-success me-3 mt-1"></i>

                                <span>

                                Rural Development

                            </span>

                            </div>

                        </div>

                        <div class="col-6">

                            <div class="d-flex">

                                <i class="fas fa-check-circle text-success me-3 mt-1"></i>

                                <span>

                                Youth Engagement

                            </span>

                            </div>

                        </div>

                        <div class="col-6">

                            <div class="d-flex">

                                <i class="fas fa-check-circle text-success me-3 mt-1"></i>

                                <span>

                                Health Awareness

                            </span>

                            </div>

                        </div>

                        <div class="col-6">

                            <div class="d-flex">

                                <i class="fas fa-check-circle text-success me-3 mt-1"></i>

                                <span>

                                Environmental Education

                            </span>

                            </div>

                        </div>

                    </div>

                </div>

                <div class="col-lg-6 wow fadeInRight">

                    <div class="glass-card-dark p-5">

                        <h3 class="text-white fw-bold mb-4">

                            Our Focus Areas

                        </h3>

                        <div class="mb-4">

                            <h5 class="text-white">

                                🌱 Sustainable Agriculture

                            </h5>

                            <p class="text-light mb-0">

                                Promoting eco-friendly farming and sustainable cultivation practices.

                            </p>

                        </div>

                        <hr>

                        <div class="mb-4">

                            <h5 class="text-white">

                                🎋 Bamboo Economy

                            </h5>

                            <p class="text-light mb-0">

                                Supporting bamboo cultivation, processing and value-added products.

                            </p>

                        </div>

                        <hr>

                        <div class="mb-4">

                            <h5 class="text-white">

                                🤝 Social Responsibility

                            </h5>

                            <p class="text-light mb-0">

                                Building inclusive communities through partnerships and volunteer initiatives.

                            </p>

                        </div>

                        <hr>

                        <div>

                            <h5 class="text-white">

                                🌍 Climate Action

                            </h5>

                            <p class="text-light mb-0">

                                Reducing environmental impact through awareness, conservation and green practices.

                            </p>

                        </div>

                    </div>

                </div>

            </div>

        </div>

    </section>



    <!-- ===========================
      PART 2C STARTS HERE
=========================== -->

    <!-- ===========================
        IMPACT STATISTICS
=========================== -->

    <section class="py-5 bg-light">

        <div class="container">

            <div class="text-center mb-5">

                <h6 class="text-success text-uppercase fw-bold">

                    Our Impact

                </h6>

                <h2 class="section-title">

                    Together We Are Making A Difference

                </h2>

                <p class="text-muted mt-4">

                    Every initiative contributes towards building a greener, healthier and more sustainable future for our communities.

                </p>

            </div>

            <div class="row g-4">

                <div class="col-lg-3 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-tree fa-3x text-success mb-3"></i>

                        <h2 class="fw-bold text-success counter">10000</h2>

                        <h6>Trees Planned</h6>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-users fa-3x text-success mb-3"></i>

                        <h2 class="fw-bold text-success counter">500</h2>

                        <h6>Families Supported</h6>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-hands-helping fa-3x text-success mb-3"></i>

                        <h2 class="fw-bold text-success counter">100</h2>

                        <h6>Volunteers</h6>

                    </div>

                </div>

                <div class="col-lg-3 col-md-6">

                    <div class="bg-white rounded-4 shadow p-4 text-center h-100">

                        <i class="fas fa-leaf fa-3x text-success mb-3"></i>

                        <h2 class="fw-bold text-success counter">20</h2>

                        <h6>Green Initiatives</h6>

                    </div>

                </div>

            </div>

        </div>

    </section>



    <!-- ===========================
        GALLERY
=========================== -->

    <section class="py-5">
        <div class="container">

            <div class="text-center mb-5">
                <h6 class="text-success text-uppercase fw-bold">
                    Gallery
                </h6>

                <h2 class="section-title">
                    Our Vision In Action
                </h2>

                <p class="text-muted mt-3">
                    A glimpse of our environmental conservation, community development and sustainability initiatives.
                </p>
            </div>

            <div class="row g-4">

                <div class="col-lg-8">
                    <div class="gallery-card">
                        <img src="img/ngo1.png" alt="NGO Activity">
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="gallery-card">
                        <img src="img/ngo2.png" alt="Tree Plantation">
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="gallery-card">
                        <img src="img/ngo3.png" alt="Community Support">
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="gallery-card">
                        <img src="img/ngo4.png" alt="Bamboo Initiative">
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="gallery-card">
                        <img src="img/ngo5.png" alt="Environmental Program">
                    </div>
                </div>

            </div>

        </div>
    </section>



    <!-- ===========================
        CALL TO ACTION
=========================== -->

    <section class="py-5 bg-success text-white">

        <div class="container text-center">

            <h6 class="text-uppercase text-warning fw-bold">

                Join Our Mission

            </h6>

            <h2 class="display-5 text-white fw-bold mb-4">

                Together We Can Build A Sustainable Future

            </h2>

            <p class="lead mb-5">

                Whether you are an individual, organization or corporate partner, your support can help us create meaningful environmental and social impact.

            </p>

            <div class="d-flex justify-content-center flex-wrap gap-3">

                <a href="contact.php" class="btn btn-light btn-lg rounded-pill px-5">

                Become A Volunteer

            </a>

                <a href="contact.php" class="btn btn-outline-light btn-lg rounded-pill px-5">

                Partner With Us

            </a>

            </div>

        </div>

    </section>



    <!-- ===========================
        FOOTER
=========================== -->

    <!-- <div id="footer"></div> -->
     <?php include __DIR__ . '/footer.php'; ?>



    <!-- Back To Top -->

    <a href="#" class="btn btn-lg btn-primary btn-lg-square rounded-0 back-to-top">

        <i class="bi bi-arrow-up"></i>

    </a>



    <!-- JavaScript Libraries -->

    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>

    <script src="lib/wow/wow.min.js"></script>

    <script src="lib/easing/easing.min.js"></script>

    <script src="lib/waypoints/waypoints.min.js"></script>

    <script src="lib/counterup/counterup.min.js"></script>

    <script src="lib/owlcarousel/owl.carousel.min.js"></script>

    <script src="js/main.js"></script>

    <script src="js/navbar-active-state.js"></script>

    <script>
        new WOW().init();

        // fetch("navbar.php")
        //     .then(res => res.text())
        //     .then(data => {
        //         document.getElementById("navbar").innerHTML = data;

        //         document.querySelectorAll(".dropdown-toggle").forEach(function(el) {
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