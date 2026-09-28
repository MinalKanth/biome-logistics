<?php
declare(strict_types=1);

// 1. Elite SEO Engine include karein
require_once __DIR__ . '/EliteSeoEngine.php'; 
$seo = new EliteEnterpriseSeoEngine();

// 2. Performance aur Security headers send karein (HTML output se pehle)
$seo->sendEnterpriseHeaders();

require_once __DIR__ . '/admin/config/database.php';

/** Convert a pasted YouTube/Vimeo link into an embeddable player URL, or null if unsupported. */
function blog_video_embed_url(string $url): ?string
{
    if (preg_match('~youtu\.be/([A-Za-z0-9_-]+)~i', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    if (preg_match('~youtube\.com/watch\?v=([A-Za-z0-9_-]+)~i', $url, $m)) {
        return 'https://www.youtube.com/embed/' . $m[1];
    }
    if (preg_match('~youtube\.com/embed/[A-Za-z0-9_-]+~i', $url)) {
        return $url;
    }
    if (preg_match('~vimeo\.com/(\d+)~i', $url, $m)) {
        return 'https://player.vimeo.com/video/' . $m[1];
    }
    return null;
}

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

$pdo = get_db();

$posts = $pdo->query(
    "SELECT id, title, event_date, description, video_url
     FROM blog_posts
     WHERE status = 'published'
     ORDER BY event_date DESC, id DESC"
)->fetchAll();

$photosByPost = [];
if ($posts) {
    $ids = array_column($posts, 'id');
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT post_id, file_path FROM blog_photos WHERE post_id IN ($in) ORDER BY post_id, sort_order");
    $stmt->execute($ids);
    foreach ($stmt->fetchAll() as $row) {
        $photosByPost[$row['post_id']][] = $row['file_path'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <?php
    // =========================================================================
    // 🚀 DYNAMIC META GENERATION FOR BLOG PAGE
    // =========================================================================
    echo $seo->generateMeta([
        'title'       => 'Our Blog | Biome Enterprises - News, Updates & Operations',
        'description' => 'Explore the latest photos, videos, and updates from our day-to-day work at Biome Enterprises, including bamboo plantations, logistics operations, and community visits.',
        'keywords'    => 'Biome Enterprises blog, logistics news Northeast India, bamboo plantation updates, corporate community visits, Assam logistics company news, business updates',
        'url_path'    => '/blog.php', 
        'type'        => 'blog'
    ]);
    
    // =========================================================================
    // 🧠 AI SEMANTIC SCHEMAS (JSON-LD)
    // =========================================================================
    echo $seo->schema($seo->coreGraph());
    echo $seo->schema($seo->breadcrumbSchema([
        "Home" => "/",
        "Blog" => "/blog.php"
    ]));
    ?>

    <meta content="width=device-width, initial-scale=1.0" name="viewport">

    <!-- Favicon -->
    <link rel="icon" href="/favicon.ico" type="image/x-icon">

    <!-- Google Web Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Roboto:wght@500;700;800&display=swap" rel="stylesheet">

    <!-- Icon Font Stylesheet -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">

    <!-- Libraries & Custom Stylesheets -->
    <link href="lib/animate/animate.min.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">

    <style>
        :root {
            --be-primary: #ffc107;
            --be-primary-dark: #bf9107;
            --be-success: #198754;
            --be-dark: #271e01;
            --be-radius: 1.2rem;
            --be-shadow-soft: 0 10px 30px rgba(57, 51, 10, 0.05);
            --be-shadow-strong: 0 20px 45px rgba(194, 157, 8, 0.15);
            --be-transition: all .35s cubic-bezier(.25,.8,.25,1);
        }

        html { scroll-behavior: smooth; }
        body { font-family: 'Inter', sans-serif; background: #f7f9fc; overflow-x: hidden; color: #4a5568;}
        h1, h2, h3, h4, h5, h6 { font-family: 'Roboto', sans-serif; }

        /* ---- Scroll progress bar ---- */
        #scrollProgress {
            position: fixed; top: 0; left: 0; height: 4px; width: 0%;
            background: linear-gradient(90deg, var(--be-primary), var(--be-success));
            z-index: 2000; transition: width .1s ease-out;
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


        /* ---- Reveal Animations ---- */
        .reveal { opacity: 0; transform: translateY(40px); transition: opacity .8s ease, transform .8s ease; }
        .reveal.is-visible { opacity: 1; transform: translateY(0); }
        .reveal-stagger > * { opacity: 0; transform: translateY(30px); transition: opacity .7s ease, transform .7s ease; }
        .reveal-stagger.is-visible > * { opacity: 1; transform: translateY(0); }
        .reveal-stagger.is-visible > *:nth-child(1) { transition-delay: .05s; }
        .reveal-stagger.is-visible > *:nth-child(2) { transition-delay: .15s; }
        .reveal-stagger.is-visible > *:nth-child(3) { transition-delay: .25s; }

        /* ---- Page Header (Original Dark Theme Maintained) ---- */
        .page-header {
            position: relative;
            background: linear-gradient(135deg, #1c1602 0%, #3a2e05 55%, #16210f 100%);
            overflow: hidden;
            padding: 100px 0 80px 0;
        }
        .page-header::before {
            content: ""; position: absolute; inset: 0;
            background: radial-gradient(circle at 15% 20%, rgba(255,193,7,.25), transparent 55%),
                        radial-gradient(circle at 85% 80%, rgba(25,135,84,.3), transparent 55%);
            pointer-events: none;
        }
        .page-header::after {
            content: ""; position: absolute; inset: 0;
            background-image: url("img/carousel-1.jpg");
            background-size: cover; background-position: center;
            opacity: .16; mix-blend-mode: luminosity; pointer-events: none;
        }
        .page-header h6.text-warning {
            letter-spacing: 3px; display: inline-block; padding: .35rem 1rem;
            border: 1px solid rgba(255,255,255,.35); border-radius: 50px;
            backdrop-filter: blur(6px); background: rgba(255,255,255,.08);
            position: relative; z-index: 1; margin-bottom: 1rem;
        }
        .page-header .text-success { color: #198754 !important; }
        .page-header h1 { font-size: clamp(2rem, 5vw, 3.5rem); letter-spacing: -1px; }
        .page-header p { font-size: clamp(1rem, 2vw, 1.15rem); max-width: 800px; }
        .breadcrumb { background: rgba(0,0,0,0.25); display: inline-flex; padding: 10px 25px; border-radius: 50px; backdrop-filter: blur(8px); margin: 0; }
        .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,.6); }

        /* ---- Stat strip under header ---- */
        .blog-stats { margin-top: -3.5rem; position: relative; z-index: 2; }
        .blog-stat-card {
            background: #fff; border-radius: var(--be-radius);
            box-shadow: var(--be-shadow-soft); padding: 1.5rem 1rem;
            text-align: center; transition: var(--be-transition);
        }
        .blog-stat-card:hover { transform: translateY(-8px); box-shadow: var(--be-shadow-strong); }
        .blog-stat-card .num {
            font-family: 'Roboto', sans-serif; font-weight: 800; font-size: 2.2rem;
            background: linear-gradient(135deg, var(--be-primary-dark), var(--be-success));
            -webkit-background-clip: text; background-clip: text; color: transparent; line-height: 1;
        }
        .blog-stat-card .lbl { font-size: .8rem; letter-spacing: 1px; text-transform: uppercase; color: #7a7a7a; font-weight: 700; margin-top: 5px; }

        /* =========================================================================
           ✨ POLISHED BLOG TIMELINE & FEED ✨
        ========================================================================= */
        .blog-feed { padding: 50px 0 80px; background: #f7f9fc; }
        
        .blog-timeline { position: relative; padding-left: 30px; }
        .blog-timeline::before {
            content: ""; position: absolute; left: 34px; top: 15px; bottom: 15px; width: 4px;
            background: linear-gradient(180deg, var(--be-primary), var(--be-success)); 
            opacity: 0.25; border-radius: 4px;
        }
        @media (max-width: 576px) { .blog-timeline { padding-left: 15px; } .blog-timeline::before { left: 19px; } }

        /* Card Container */
        .blog-card {
            position: relative; background: #fff; border-radius: var(--be-radius);
            box-shadow: 0 5px 20px rgba(0,0,0,0.03); margin-bottom: 3.5rem; margin-left: 60px;
            overflow: hidden; transition: var(--be-transition); border: 1px solid rgba(0,0,0,0.04);
        }
        @media (max-width: 576px) { .blog-card { margin-left: 40px; margin-bottom: 2.5rem; } }
        .blog-card:hover { transform: translateY(-5px); box-shadow: var(--be-shadow-soft); border-color: rgba(25, 135, 84, 0.1); }
        
        /* Premium Timeline Dot */
        .blog-card::before {
            content: ""; position: absolute; left: -54px; top: 40px; width: 18px; height: 18px; border-radius: 50%;
            background: linear-gradient(135deg, var(--be-primary), var(--be-success));
            box-shadow: 0 0 0 5px #f7f9fc, 0 0 0 9px rgba(255,193,7,.2); z-index: 2;
        }
        @media (max-width: 576px) { .blog-card::before { left: -36px; width: 14px; height: 14px; box-shadow: 0 0 0 4px #f7f9fc, 0 0 0 7px rgba(255,193,7,.2); } }

        /* Typography & Spacing Inside Card */
        .blog-card-body { padding: 2.5rem 2.5rem 1rem; }
        @media (max-width: 576px) { .blog-card-body { padding: 1.5rem 1.5rem 0.5rem; } }

        .blog-date-badge {
            display: inline-flex; align-items: center; gap: 8px;
            background: rgba(25, 135, 84, 0.08); color: var(--be-success);
            font-weight: 700; font-size: .85rem; letter-spacing: .5px;
            padding: 8px 18px; border-radius: 50px; margin-bottom: 1.2rem;
            border: 1px solid rgba(25, 135, 84, 0.1);
        }
        
        .blog-card h3 { font-size: 1.75rem; font-weight: 800; color: var(--be-dark); margin-bottom: 12px; line-height: 1.3; }
        @media (max-width: 576px) { .blog-card h3 { font-size: 1.4rem; } }

        .blog-card p.desc { color: #555; line-height: 1.8; margin-bottom: 10px; max-height: 5.4em; overflow: hidden; position: relative; transition: max-height .5s ease; font-size: 1.05rem; }
        @media (max-width: 576px) { .blog-card p.desc { font-size: 0.95rem; } }
        .blog-card p.desc.is-expanded { max-height: 100em; }
        
        .blog-card .desc-toggle {
            background: none; border: none; padding: 0; font-weight: 700; font-size: .95rem;
            color: var(--be-primary-dark); margin-bottom: 20px; cursor: pointer; text-decoration: none;
            display: inline-flex; align-items: center; gap: 5px; transition: color 0.3s ease;
        }
        .blog-card .desc-toggle:hover { color: var(--be-success); text-decoration: underline; text-underline-offset: 4px; }

        /* Gallery Grid */
        .blog-gallery { display: grid; gap: 10px; padding: 0 2.5rem 2rem; grid-template-columns: repeat(4, 1fr); }
        @media (max-width: 576px) { .blog-gallery { grid-template-columns: repeat(2, 1fr); padding: 0 1.5rem 1.5rem; gap: 8px; } }
        
        .blog-gallery button {
            position: relative; border: 0; padding: 0; background: #eee; cursor: pointer;
            aspect-ratio: 1 / 1; overflow: hidden; border-radius: .8rem; box-shadow: 0 4px 10px rgba(0,0,0,0.03);
        }
        .blog-gallery img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .5s cubic-bezier(.25,.8,.25,1), filter .5s ease; }
        .blog-gallery button:hover img { transform: scale(1.1); filter: brightness(.8); }
        .blog-gallery button::after {
            content: "\f00e"; font-family: "Font Awesome 5 Free"; font-weight: 900;
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: 1.4rem; opacity: 0; transition: opacity .3s ease; background: rgba(25, 135, 84, 0.3);
        }
        .blog-gallery button:hover::after { opacity: 1; }
        .blog-gallery .more-overlay {
            position: absolute; inset: 0; background: rgba(0,0,0,.65); color: #fff;
            display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1.5rem; z-index: 1; backdrop-filter: blur(3px);
        }

        /* Video Frame */
        .blog-video-wrap { padding: 0 2.5rem 2.5rem; }
        @media (max-width: 576px) { .blog-video-wrap { padding: 0 1.5rem 1.5rem; } }
        .blog-video-frame {
            position: relative; width: 100%; padding-top: 56.25%;
            border-radius: 1rem; overflow: hidden; background: #000; box-shadow: 0 8px 25px rgba(0,0,0,0.08);
        }
        .blog-video-frame iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: 0; }
        .blog-video-link { display: inline-flex; align-items: center; gap: 8px; color: var(--be-success); font-weight: 700; text-decoration: none; padding: 12px 24px; border: 2px solid var(--be-success); border-radius: 50px; transition: var(--be-transition); }
        .blog-video-link:hover { background: var(--be-success); color: #fff; box-shadow: 0 8px 20px rgba(25,135,84,0.2); }

        /* Card Share Footer */
        .blog-card-footer {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1.2rem 2.5rem; border-top: 1px solid rgba(0,0,0,0.04); background: #fcfcfc;
        }
        @media (max-width: 576px) { .blog-card-footer { padding: 1rem 1.5rem; } }
        
        .blog-share-btn {
            border: 1px solid rgba(0,0,0,0.08); background: #fff; color: #555;
            width: 42px; height: 42px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
            transition: var(--be-transition); font-size: 1.1rem; box-shadow: 0 2px 5px rgba(0,0,0,0.02);
        }
        .blog-share-btn:hover { background: var(--be-success); color: #fff; transform: translateY(-3px); box-shadow: 0 6px 15px rgba(25,135,84,0.25); border-color: transparent; }
        .blog-copied-toast { font-size: .85rem; font-weight: 700; color: var(--be-success); opacity: 0; transition: opacity .3s ease; }
        .blog-copied-toast.show { opacity: 1; }

        /* Lightbox UI */
        #blogLightbox .modal-content { background: transparent; border: 0; }
        #blogLightbox .modal-body { padding: 0; text-align: center; position: relative; }
        #blogLightbox img { max-height: 85vh; max-width: 100%; border-radius: 1rem; box-shadow: 0 20px 60px rgba(0,0,0,0.4); }
        #blogLightbox .btn-close { filter: invert(1); position: absolute; top: -45px; right: 0; z-index: 10; opacity: 0.8; }
        .lightbox-nav {
            position: absolute; top: 50%; transform: translateY(-50%); width: 50px; height: 50px; border-radius: 50%; border: none;
            background: rgba(255,255,255,.15); color: #fff; font-size: 1.4rem; display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(8px); transition: var(--be-transition); z-index: 3;
        }
        .lightbox-nav:hover { background: var(--be-success); }
        .lightbox-prev { left: -25px; } .lightbox-next { right: -25px; }
        @media (max-width: 576px) { .lightbox-prev { left: 5px; width: 40px; height: 40px; } .lightbox-next { right: 5px; width: 40px; height: 40px; } }
        .lightbox-counter { position: absolute; bottom: -35px; left: 50%; transform: translateX(-50%); color: #fff; font-size: .9rem; letter-spacing: 1px; font-weight: 700; }
        
        /* Navbar Fixes */
        .navbar { transition: background .4s ease, box-shadow .4s ease, padding .4s ease; background: transparent !important; box-shadow: none !important; }
        .navbar .navbar-brand h2, .navbar .navbar-brand, .navbar .nav-link, .navbar .dropdown-toggle { color:#fff !important; }
        .navbar .navbar-toggler { border-color: rgba(255,255,255,.35); }
        .navbar .navbar-toggler i, .navbar .navbar-toggler-icon { color:#fff !important; }
        .navbar.be-scrolled { background: rgba(255,255,255,.98) !important; backdrop-filter: blur(14px); padding-top: .4rem !important; padding-bottom: .4rem !important; box-shadow: 0 6px 20px rgba(0,0,0,.08) !important; }
        .navbar.be-scrolled .navbar-brand h2, .navbar.be-scrolled .navbar-brand, .navbar.be-scrolled .nav-link, .navbar.be-scrolled .dropdown-toggle { color: var(--be-dark) !important; }
    
    /* ---- Animated Background Blobs ---- */
        .be-blob {
            filter: blur(70px);
            opacity: 0.4;
            animation: be-float 10s ease-in-out infinite;
        }
        
        @keyframes be-float {
            0%, 100% { 
                transform: translateY(0) translateX(0) scale(1); 
            }
            50% { 
                transform: translateY(-30px) translateX(20px) scale(1.05); 
            }
        }
    
    </style>
</head>

<body>

     

    <?php include __DIR__ . '/navbar.php'; ?>

    <!-- =========================
         PAGE HEADER START
    ========================= -->
    <div class="container-fluid page-header py-5 position-relative overflow-hidden">
        <div class="be-blob position-absolute rounded-circle d-none d-md-block" style="width:280px;height:280px;top:8%;left:-6%;background:#ffc107; z-index: 0; pointer-events: none;"></div>
        <div class="be-blob position-absolute rounded-circle d-none d-md-block" style="width:220px;height:220px;bottom:5%;right:-4%;background:#198754;animation-delay:2s; z-index: 0; pointer-events: none;"></div>

        <div class="container py-5 position-relative z-1 text-center text-lg-start">
            <h6 class="text-uppercase text-warning fw-bold mb-3 reveal">From The Field</h6>
            
            <h1 class="text-white fw-bold mb-4 reveal" style="line-height: 1.2;">
                Our <span class="text-success">Blog</span>
            </h1>
            
            <p class="text-light mb-4 reveal mx-auto mx-lg-0 opacity-75" style="max-width: 800px;">
                Photos, videos and updates from our day-to-day work &mdash; plantations, logistics, community visits and more.
            </p>
            
            <nav class="reveal d-flex justify-content-center justify-content-lg-start">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a class="text-white text-decoration-none opacity-75" href="index.php">Home</a></li>
                    <li class="breadcrumb-item text-white fw-bold active" aria-current="page">Blog</li>
                </ol>
            </nav>
        </div>
    </div>
    <!-- PAGE HEADER END -->

    <?php if ($posts): ?>
    <!-- =========================
         STATS STRIP
    ========================= -->
    <div class="container blog-stats">
        <div class="row g-4 reveal reveal-stagger justify-content-center">
            <div class="col-4 col-md-3">
                <div class="blog-stat-card">
                    <div class="num"><?= count($posts) ?></div>
                    <div class="lbl">Updates</div>
                </div>
            </div>
            <div class="col-4 col-md-3">
                <div class="blog-stat-card">
                    <div class="num"><?= array_sum(array_map(fn($p) => count($photosByPost[$p['id']] ?? []), $posts)) ?></div>
                    <div class="lbl">Photos</div>
                </div>
            </div>
            <div class="col-4 col-md-3">
                <div class="blog-stat-card">
                    <div class="num"><?= count(array_filter($posts, fn($p) => !empty($p['video_url']))) ?></div>
                    <div class="lbl">Videos</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- =========================
         BLOG TIMELINE FEED
    ========================= -->
    <div class="blog-feed">
        <div class="container" style="max-width: 900px;">
            <?php if (!$posts): ?>
                <div class="text-center py-5 reveal">
                    <i class="bi bi-images text-muted" style="font-size: 4rem;"></i>
                    <h4 class="mt-3 text-muted">No posts yet. Check back soon!</h4>
                </div>
            <?php else: ?>
                <div class="blog-timeline">
                    <?php foreach ($posts as $post):
                        $photos = $photosByPost[$post['id']] ?? [];
                        $visiblePhotos = array_slice($photos, 0, 8);
                        $remaining = count($photos) - count($visiblePhotos);
                        $embedUrl = $post['video_url'] ? blog_video_embed_url($post['video_url']) : null;
                        $postUrlSafe = h((string) $post['id']);
                    ?>
                    <article class="blog-card reveal">
                        <div class="blog-card-body">
                            <span class="blog-date-badge shadow-sm">
                                <i class="bi bi-calendar2-event-fill"></i>
                                <?= h(date('d F Y', strtotime((string) $post['event_date']))) ?>
                            </span>
                            <h3><?= h($post['title']) ?></h3>
                            <p class="desc" id="desc-<?= (int) $post['id'] ?>"><?= nl2br(h($post['description'])) ?></p>
                            <button type="button" class="desc-toggle" onclick="toggleDesc(<?= (int) $post['id'] ?>, this)">Read more <i class="bi bi-chevron-down ms-1"></i></button>
                        </div>

                        <?php if ($photos): ?>
                            <div class="blog-gallery">
                                <?php foreach ($visiblePhotos as $i => $photo): ?>
                                    <button type="button" onclick="openBlogLightbox(<?= (int) $post['id'] ?>, <?= (int) $i ?>)">
                                        <?php if ($remaining > 0 && $i === count($visiblePhotos) - 1): ?>
                                            <span class="more-overlay">+<?= $remaining ?></span>
                                        <?php endif; ?>
                                        <img src="<?= h($photo) ?>" alt="<?= h($post['title']) ?> photo <?= $i + 1 ?>" loading="lazy">
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($post['video_url']): ?>
                            <div class="blog-video-wrap">
                                <?php if ($embedUrl): ?>
                                    <div class="blog-video-frame">
                                        <iframe src="<?= h($embedUrl) ?>" title="<?= h($post['title']) ?> video" loading="lazy" allowfullscreen allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>
                                    </div>
                                <?php else: ?>
                                    <a class="blog-video-link" href="<?= h($post['video_url']) ?>" target="_blank" rel="noopener">
                                        <i class="bi bi-play-circle-fill fs-4"></i> Watch video
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="blog-card-footer">
                            <span class="blog-copied-toast" id="toast-<?= (int) $post['id'] ?>"><i class="bi bi-check-circle-fill me-1"></i> Link copied!</span>
                            <div class="d-flex gap-2 ms-auto">
                                <button type="button" class="blog-share-btn" title="Copy link" onclick="shareBlogPost(<?= (int) $post['id'] ?>, '<?= $postUrlSafe ?>')">
                                    <i class="bi bi-link-45deg fs-4"></i>
                                </button>
                                <a class="blog-share-btn" title="Share on WhatsApp" target="_blank" rel="noopener" href="https://wa.me/?text=<?= urlencode($post['title'] . ' - Biome Enterprises Blog') ?>">
                                    <i class="fab fa-whatsapp fs-5"></i>
                                </a>
                            </div>
                        </div>
                    </article>

                    <script type="application/json" id="blog-photos-<?= (int) $post['id'] ?>">
                        <?= json_encode(array_values($photos), JSON_UNESCAPED_SLASHES) ?>
                    </script>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- =========================
         LIGHTBOX MODAL
    ========================= -->
    <div class="modal fade" id="blogLightbox" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="modal-body">
                    <button type="button" class="lightbox-nav lightbox-prev" onclick="stepBlogLightbox(-1)" aria-label="Previous photo"><i class="bi bi-chevron-left"></i></button>
                    <img id="blogLightboxImg" src="" alt="Expanded Image">
                    <button type="button" class="lightbox-nav lightbox-next" onclick="stepBlogLightbox(1)" aria-label="Next photo"><i class="bi bi-chevron-right"></i></button>
                    <span class="lightbox-counter" id="blogLightboxCounter"></span>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/footer.php'; ?>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- Interactions Script -->
    <script>
    

        // Read More Toggle
        function toggleDesc(id, btn) {
            const p = document.getElementById('desc-' + id);
            if (p.classList.contains('is-expanded')) {
                p.classList.remove('is-expanded');
                btn.innerHTML = 'Read more <i class="bi bi-chevron-down ms-1"></i>';
            } else {
                p.classList.add('is-expanded');
                btn.innerHTML = 'Show less <i class="bi bi-chevron-up ms-1"></i>';
            }
        }

        // Share Link functionality
        function shareBlogPost(id, urlPath) {
            const fullUrl = window.location.origin + window.location.pathname + '?post=' + urlPath;
            navigator.clipboard.writeText(fullUrl).then(() => {
                const toast = document.getElementById('toast-' + id);
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 2000);
            });
        }

        // Lightbox functionality
        let lbPhotos = [];
        let lbIndex = 0;
        const lbModal = new bootstrap.Modal(document.getElementById('blogLightbox'));
        const lbImg = document.getElementById('blogLightboxImg');
        const lbCount = document.getElementById('blogLightboxCounter');

        function openBlogLightbox(postId, startIndex) {
            const script = document.getElementById('blog-photos-' + postId);
            if(!script) return;
            lbPhotos = JSON.parse(script.textContent);
            lbIndex = startIndex;
            updateLightbox();
            lbModal.show();
        }

        function stepBlogLightbox(dir) {
            lbIndex += dir;
            if (lbIndex < 0) lbIndex = lbPhotos.length - 1;
            if (lbIndex >= lbPhotos.length) lbIndex = 0;
            updateLightbox();
        }

        function updateLightbox() {
            lbImg.src = lbPhotos[lbIndex];
            lbCount.innerText = (lbIndex + 1) + " / " + lbPhotos.length;
        }
    </script>
</body>
</html>