<?php
declare(strict_types=1);
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
    <title>Biome Enterprises | Blog</title>
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <meta name="description" content="Photos, videos and updates from daily events at Biome Enterprises.">

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
    <link href="css/navbar-active-state.css" rel="stylesheet">
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
        .reveal-stagger.is-visible > *:nth-child(4) { transition-delay: .35s; }
        .reveal-stagger.is-visible > *:nth-child(5) { transition-delay: .45s; }

        /* ---- Page Header (same family as Contact page) ---- */
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
        .page-header::after {
            content: "";
            position: absolute; inset: 0;
            background-image: url("img/carousel-1.jpg");
            background-size: cover;
            background-position: center;
            opacity: .16;
            mix-blend-mode: luminosity;
            pointer-events: none;
        }
        .page-header h6.text-warning {
            letter-spacing: 3px;
            display: inline-block;
            padding: .35rem 1rem;
            border: 1px solid rgba(255,255,255,.35);
            border-radius: 50px;
            backdrop-filter: blur(6px);
            background: rgba(255,255,255,.08);
            position: relative;
            z-index: 1;
        }
        .page-header .text-success { color: #ffc107 !important; }
        .page-header h1, .page-header p, .page-header nav { position: relative; z-index: 1; }
        .breadcrumb { background: transparent; margin: 0; }
        .breadcrumb-item + .breadcrumb-item::before { color: rgba(255,255,255,.6); }

        /* ---- Stat strip under header ---- */
        .blog-stats {
            margin-top: -3rem;
            position: relative;
            z-index: 2;
        }
        .blog-stat-card {
            background: #fff;
            border-radius: var(--be-radius);
            box-shadow: var(--be-shadow-soft);
            padding: 1.4rem 1rem;
            text-align: center;
            transition: var(--be-transition);
        }
        .blog-stat-card:hover { transform: translateY(-6px); box-shadow: var(--be-shadow-strong); }
        .blog-stat-card .num {
            font-family: 'Roboto', sans-serif;
            font-weight: 700;
            font-size: 1.9rem;
            background: linear-gradient(135deg, var(--be-primary-dark), var(--be-success));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }
        .blog-stat-card .lbl {
            font-size: .78rem;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #7a7a7a;
            font-weight: 600;
        }

        /* ---- Section heading ---- */
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

        .blog-feed { padding: 20px 0 70px; background: #f7f9fc; }

        /* ---- Timeline rail ---- */
        .blog-timeline { position: relative; }
        .blog-timeline::before {
            content: "";
            position: absolute;
            left: 22px; top: 10px; bottom: 10px;
            width: 2px;
            background: linear-gradient(180deg, var(--be-primary), var(--be-success));
            opacity: .35;
        }
        @media (max-width: 576px) { .blog-timeline::before { left: 14px; } }

        /* ---- Cards ---- */
        .blog-card {
            position: relative;
            background: #fff;
            border-radius: var(--be-radius);
            box-shadow: var(--be-shadow-soft);
            margin-bottom: 42px;
            margin-left: 54px;
            overflow: hidden;
            transition: var(--be-transition);
        }
        @media (max-width: 576px) { .blog-card { margin-left: 34px; } }
        .blog-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--be-shadow-strong);
        }
        .blog-card::before {
            content: "";
            position: absolute;
            left: -40px; top: 30px;
            width: 14px; height: 14px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--be-primary), var(--be-success));
            box-shadow: 0 0 0 4px #fff, 0 0 0 5px rgba(255,193,7,.35);
        }
        @media (max-width: 576px) { .blog-card::before { left: -26px; width: 10px; height: 10px; } }

        .blog-card-body { padding: 28px 28px 10px; }
        .blog-date-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,193,7,.14);
            color: var(--be-primary-dark);
            font-weight: 700;
            font-size: .78rem;
            letter-spacing: .5px;
            padding: 5px 14px;
            border-radius: 30px;
            margin-bottom: 14px;
        }
        .blog-card h2 {
            font-size: 1.4rem;
            font-weight: 700;
            color: var(--be-dark);
            margin-bottom: 12px;
        }
        .blog-card p.desc {
            color: #555;
            line-height: 1.75;
            margin-bottom: 6px;
            max-height: 4.9em;
            overflow: hidden;
            position: relative;
            transition: max-height .4s ease;
        }
        .blog-card p.desc.is-expanded { max-height: 60em; }
        .blog-card .desc-toggle {
            background: none;
            border: none;
            padding: 0;
            font-weight: 700;
            font-size: .85rem;
            color: var(--be-success);
            margin-bottom: 18px;
            cursor: pointer;
        }
        .blog-card .desc-toggle:hover { color: var(--be-primary-dark); }

        /* ---- Gallery ---- */
        .blog-gallery {
            display: grid;
            gap: 6px;
            padding: 0 28px 24px;
            grid-template-columns: repeat(4, 1fr);
        }
        @media (max-width: 576px) {
            .blog-gallery { grid-template-columns: repeat(2, 1fr); }
        }
        .blog-gallery button {
            position: relative;
            border: 0;
            padding: 0;
            background: none;
            cursor: pointer;
            aspect-ratio: 1 / 1;
            overflow: hidden;
            border-radius: .65rem;
        }
        .blog-gallery img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform .45s cubic-bezier(.25,.8,.25,1), filter .45s ease;
        }
        .blog-gallery button:hover img { transform: scale(1.1); filter: brightness(.85); }
        .blog-gallery button::after {
            content: "\f00e";
            font-family: "Font Awesome 5 Free";
            font-weight: 900;
            position: absolute; inset: 0;
            display: flex; align-items: center; justify-content: center;
            color: #fff;
            font-size: 1.1rem;
            opacity: 0;
            transition: opacity .3s ease;
            background: rgba(39,30,1,.28);
        }
        .blog-gallery button:hover::after { opacity: 1; }
        .blog-gallery .more-overlay {
            position: absolute; inset: 0;
            background: rgba(39,30,1,.62);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: 1.15rem;
            z-index: 1;
        }

        /* ---- Video ---- */
        .blog-video-wrap { padding: 0 28px 28px; }
        .blog-video-frame {
            position: relative;
            width: 100%;
            padding-top: 56.25%;
            border-radius: .8rem;
            overflow: hidden;
            background: #000;
            box-shadow: var(--be-shadow-soft);
        }
        .blog-video-frame iframe {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            border: 0;
        }
        .blog-video-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--be-success);
            font-weight: 700;
            text-decoration: none;
        }
        .blog-video-link:hover { color: var(--be-primary-dark); }

        /* ---- Card footer / share ---- */
        .blog-card-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 28px;
            border-top: 1px solid #f0f0f0;
        }
        .blog-share-btn {
            border: none;
            background: #f7f9fc;
            color: var(--be-dark);
            width: 36px; height: 36px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: var(--be-transition);
            font-size: .9rem;
        }
        .blog-share-btn:hover {
            background: linear-gradient(135deg, var(--be-primary), var(--be-success));
            color: #fff;
            transform: translateY(-3px);
        }
        .blog-copied-toast {
            font-size: .75rem;
            font-weight: 600;
            color: var(--be-success);
            opacity: 0;
            transition: opacity .3s ease;
        }
        .blog-copied-toast.show { opacity: 1; }

        .blog-empty {
            text-align: center;
            padding: 100px 20px;
            color: #777;
        }
        .blog-empty i {
            font-size: 2.6rem;
            background: linear-gradient(135deg, var(--be-primary), var(--be-success));
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        /* ---- Lightbox ---- */
        #blogLightbox .modal-content { background: transparent; border: 0; }
        #blogLightbox .modal-body { padding: 0; text-align: center; position: relative; }
        #blogLightbox img {
            max-height: 82vh;
            max-width: 100%;
            border-radius: .8rem;
            box-shadow: var(--be-shadow-strong);
        }
        #blogLightbox .btn-close { filter: invert(1); position: absolute; top: -44px; right: 0; z-index: 3; }
        .lightbox-nav {
            position: absolute;
            top: 50%;
            transform: translateY(-50%);
            width: 46px; height: 46px;
            border-radius: 50%;
            border: none;
            background: rgba(255,255,255,.15);
            color: #fff;
            font-size: 1.1rem;
            display: flex; align-items: center; justify-content: center;
            backdrop-filter: blur(6px);
            transition: var(--be-transition);
            z-index: 3;
        }
        .lightbox-nav:hover { background: linear-gradient(135deg, var(--be-primary), var(--be-success)); }
        .lightbox-prev { left: -10px; }
        .lightbox-next { right: -10px; }
        @media (max-width: 576px) {
            .lightbox-prev { left: -4px; width: 38px; height: 38px; }
            .lightbox-next { right: -4px; width: 38px; height: 38px; }
        }
        .lightbox-counter {
            position: absolute;
            bottom: -34px; left: 50%;
            transform: translateX(-50%);
            color: #fff;
            font-size: .85rem;
            letter-spacing: 1px;
            font-weight: 600;
            opacity: .85;
        }

        /* ---- Floating WhatsApp ---- */
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
            gap: .6rem;
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(12px);
            box-shadow: 0 -8px 24px rgba(0,0,0,.12);
            padding: .6rem 1rem;
        }
        @media (max-width: 576px) {
            #mobileCallBar { display: flex; }
            body { padding-bottom: 64px; }
        }
        .btn { border-radius: 50px; transition: var(--be-transition); }
        .btn-primary {
            background: linear-gradient(135deg, var(--be-primary), var(--be-primary-dark));
            border: none;
            color: #271e01;
        }
        .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 12px 24px rgba(255,193,7,.35); color: #271e01; }

        /* ---- Back to top ---- */
        .back-to-top {
            border-radius: 50% !important;
            width: 50px; height: 50px;
            display: flex; align-items: center; justify-content: center;
            box-shadow: var(--be-shadow-strong);
            position: fixed !important;
            right: 24px !important;
            bottom: 20px !important;
            left: auto !important;
            z-index: 1501;
        }
        .back-to-top:hover {
            background: var(--be-success) !important;
            box-shadow: 0 14px 32px rgba(25,135,84,.4);
        }

        /* ---- Navbar glass on scroll ---- */
        .navbar { transition: background .4s ease, box-shadow .4s ease, padding .4s ease; background: transparent !important; box-shadow: none !important; }
        .navbar .navbar-brand h2, .navbar .navbar-brand, .navbar .nav-link, .navbar .dropdown-toggle { color:#fff !important; }
        .navbar .navbar-toggler { border-color: rgba(255,255,255,.35); }
        .navbar .navbar-toggler i, .navbar .navbar-toggler-icon { color:#fff !important; }
        .navbar.be-scrolled {
            background: rgba(255,255,255,.78) !important;
            backdrop-filter: blur(14px) saturate(160%);
            -webkit-backdrop-filter: blur(14px) saturate(160%);
            padding-top: .4rem !important;
            padding-bottom: .4rem !important;
            box-shadow: 0 6px 20px rgba(0,0,0,.08) !important;
        }
        .navbar.be-scrolled .navbar-brand h2, .navbar.be-scrolled .navbar-brand, .navbar.be-scrolled .nav-link, .navbar.be-scrolled .dropdown-toggle { color:#fff !important; }

        ::selection { background: var(--be-primary); color: #271e01; }

        @media (max-width: 768px) {
            .card { border-radius: .85rem !important; }
        }
    </style>
</head>

<body>

    <div id="scrollProgress"></div>

    <?php include __DIR__ . '/navbar.php'; ?>

    <!-- Page Header Start -->
    <div class="container-fluid page-header py-5">
        <div class="container py-5">
            <h6 class="text-uppercase text-warning fw-bold mb-3 animated slideInDown">From The Field</h6>
            <h1 class="display-3 text-white fw-bold mb-4 animated slideInDown">
                Our <span class="text-success">Blog</span>
            </h1>
            <p class="text-light fs-5 mb-4">
                Photos, videos and updates from our day-to-day work &mdash; plantations, logistics, community visits and more.
            </p>
            <nav>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a class="text-white" href="index.php">Home</a></li>
                    <li class="breadcrumb-item text-white active">Blog</li>
                </ol>
            </nav>
        </div>
    </div>
    <!-- Page Header End -->

    <?php if ($posts): ?>
    <!-- Stats strip -->
    <div class="container blog-stats">
        <div class="row g-4 reveal reveal-stagger">
            <div class="col-4">
                <div class="blog-stat-card">
                    <div class="num"><?= count($posts) ?></div>
                    <div class="lbl">Updates</div>
                </div>
            </div>
            <div class="col-4">
                <div class="blog-stat-card">
                    <div class="num"><?= array_sum(array_map(fn($p) => count($photosByPost[$p['id']] ?? []), $posts)) ?></div>
                    <div class="lbl">Photos</div>
                </div>
            </div>
            <div class="col-4">
                <div class="blog-stat-card">
                    <div class="num"><?= count(array_filter($posts, fn($p) => !empty($p['video_url']))) ?></div>
                    <div class="lbl">Videos</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="blog-feed">
        <div class="container" style="max-width: 820px;">
            <?php if (!$posts): ?>
                <div class="blog-empty reveal">
                    <i class="bi bi-images"></i>
                    <p class="mt-3 mb-0">No posts yet. Check back soon!</p>
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
                            <span class="blog-date-badge">
                                <i class="bi bi-calendar-event"></i>
                                <?= h(date('d M Y', strtotime((string) $post['event_date']))) ?>
                            </span>
                            <h2><?= h($post['title']) ?></h2>
                            <p class="desc" id="desc-<?= (int) $post['id'] ?>"><?= nl2br(h($post['description'])) ?></p>
                            <button type="button" class="desc-toggle" onclick="toggleDesc(<?= (int) $post['id'] ?>, this)">Read more</button>
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
                                        <iframe src="<?= h($embedUrl) ?>" title="<?= h($post['title']) ?> video"
                                                loading="lazy" allowfullscreen
                                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"></iframe>
                                    </div>
                                <?php else: ?>
                                    <a class="blog-video-link" href="<?= h($post['video_url']) ?>" target="_blank" rel="noopener">
                                        <i class="bi bi-play-circle-fill"></i> Watch video
                                    </a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="blog-card-footer">
                            <span class="blog-copied-toast" id="toast-<?= (int) $post['id'] ?>">Link copied!</span>
                            <div class="d-flex gap-2 ms-auto">
                                <button type="button" class="blog-share-btn" title="Copy link" onclick="shareBlogPost(<?= (int) $post['id'] ?>, '<?= $postUrlSafe ?>')">
                                    <i class="bi bi-link-45deg"></i>
                                </button>
                                <a class="blog-share-btn" title="Share on WhatsApp" target="_blank" rel="noopener"
                                   href="https://wa.me/?text=<?= urlencode($post['title'] . ' - Biome Enterprises Blog') ?>">
                                    <i class="fab fa-whatsapp"></i>
                                </a>
                            </div>
                        </div>
                    </article>

                    <!-- photo data for this post's lightbox -->
                    <script type="application/json" id="blog-photos-<?= (int) $post['id'] ?>">
                        <?= json_encode(array_values($photos), JSON_UNESCAPED_SLASHES) ?>
                    </script>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Lightbox modal -->
    <div class="modal fade" id="blogLightbox" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="modal-body">
                    <button type="button" class="lightbox-nav lightbox-prev" onclick="stepBlogLightbox(-1)" aria-label="Previous photo"><i class="bi bi-chevron-left"></i></button>
                    <img id="blogLightboxImg" src="" alt="">
                    <button type="button" class="lightbox-nav lightbox-next" onclick="stepBlogLightbox(1)" aria-label="Next photo"><i class="bi bi-chevron-right"></i></button>
                    <span class="lightbox-counter" id="blogLightboxCounter"></span>
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/footer.php'; ?>

    <!-- Floating WhatsApp Button -->
    <a href="https://wa.me/919678431656" target="_blank" class="whatsapp-float" aria-label="Chat on WhatsApp">
        <i class="fab fa-whatsapp"></i>
    </a>

    <!-- Sticky Mobile Call Bar -->
    <div id="mobileCallBar">
        <a href="tel:+919678431656" class="btn btn-primary flex-fill"><i class="fa fa-phone me-2"></i>Call Now</a>
        <a href="https://wa.me/919678431656" target="_blank" class="btn btn-success flex-fill"><i class="fab fa-whatsapp me-2"></i>WhatsApp</a>
    </div>

    <a href="#" class="btn btn-lg btn-primary btn-lg-square rounded-0 back-to-top"><i class="bi bi-arrow-up"></i></a>

    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="lib/wow/wow.min.js"></script>
    <script src="lib/easing/easing.min.js"></script>
    <script src="lib/waypoints/waypoints.min.js"></script>
    <script src="js/main.js"></script>

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
            }, { threshold: 0.1 });
            reveals.forEach(function (el) { revealObserver.observe(el); });
        } else {
            reveals.forEach(function (el) { el.classList.add('is-visible'); });
        }

        // Navbar glass effect on scroll
        const nav = document.querySelector('nav.navbar, .navbar');
        function updateNav() {
            if (!nav) return;
            if (window.scrollY > 40) nav.classList.add('be-scrolled');
            else nav.classList.remove('be-scrolled');
        }
        window.addEventListener('scroll', updateNav, { passive: true });
        updateNav();

        // Trim "Read more" toggles to only show on truly overflowing text
        document.querySelectorAll('.desc').forEach(function (p) {
            const btn = p.nextElementSibling;
            if (!btn || !btn.classList.contains('desc-toggle')) return;
            requestAnimationFrame(function () {
                if (p.scrollHeight <= p.clientHeight + 4) {
                    btn.style.display = 'none';
                }
            });
        });
    })();

    function toggleDesc(id, btn) {
        const p = document.getElementById('desc-' + id);
        if (!p) return;
        const expanded = p.classList.toggle('is-expanded');
        btn.textContent = expanded ? 'Show less' : 'Read more';
    }

    // ---- Lightbox with prev/next navigation ----
    let blogLightboxPhotos = [];
    let blogLightboxIndex = 0;

    function openBlogLightbox(postId, index) {
        const dataEl = document.getElementById('blog-photos-' + postId);
        if (!dataEl) return;
        blogLightboxPhotos = JSON.parse(dataEl.textContent);
        if (!blogLightboxPhotos.length) return;
        blogLightboxIndex = index;
        renderBlogLightbox();
        const modal = new bootstrap.Modal(document.getElementById('blogLightbox'));
        modal.show();
    }

    function renderBlogLightbox() {
        document.getElementById('blogLightboxImg').src = blogLightboxPhotos[blogLightboxIndex];
        const counter = document.getElementById('blogLightboxCounter');
        counter.textContent = (blogLightboxIndex + 1) + ' / ' + blogLightboxPhotos.length;
        const multi = blogLightboxPhotos.length > 1;
        document.querySelector('.lightbox-prev').style.display = multi ? 'flex' : 'none';
        document.querySelector('.lightbox-next').style.display = multi ? 'flex' : 'none';
    }

    function stepBlogLightbox(dir) {
        if (!blogLightboxPhotos.length) return;
        blogLightboxIndex = (blogLightboxIndex + dir + blogLightboxPhotos.length) % blogLightboxPhotos.length;
        renderBlogLightbox();
    }

    document.addEventListener('keydown', function (e) {
        const modalEl = document.getElementById('blogLightbox');
        if (!modalEl.classList.contains('show')) return;
        if (e.key === 'ArrowLeft') stepBlogLightbox(-1);
        if (e.key === 'ArrowRight') stepBlogLightbox(1);
    });

    // ---- Copy link share ----
    function shareBlogPost(id, safeId) {
        const url = window.location.origin + window.location.pathname + '#post-' + safeId;
        navigator.clipboard.writeText(url).then(function () {
            const toast = document.getElementById('toast-' + id);
            if (!toast) return;
            toast.classList.add('show');
            setTimeout(function () { toast.classList.remove('show'); }, 1800);
        }).catch(function () {});
    }
    </script>
</body>

</html>