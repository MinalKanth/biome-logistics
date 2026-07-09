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

    <link rel="icon" href="/favicon.ico" type="image/x-icon">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Roboto:wght@500;700&display=swap" rel="stylesheet">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.4.1/font/bootstrap-icons.css" rel="stylesheet">

    <link href="lib/animate/animate.min.css" rel="stylesheet">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">

    <style>
        body { overflow-x: hidden; }

        .blog-header {
            position: relative;
            background: linear-gradient(rgba(6, 3, 21, .68), rgba(6, 3, 21, .68)), url("img/carousel-1.jpg");
            background-size: cover;
            background-position: center;
            background-attachment: fixed;
            padding: 100px 0 70px;
            text-align: center;
            color: #fff;
        }
        @media (max-width: 768px) {
            .blog-header { background-attachment: scroll; padding: 70px 0 50px; }
        }
        .blog-header h1 { font-weight: 700; font-size: 2.4rem; }
        .blog-header p { color: #e7e7e7; max-width: 560px; margin: 10px auto 0; }

        .blog-feed { padding: 60px 0; background: #f7f9fc; }

        .blog-card {
            background: #fff;
            border-radius: 14px;
            box-shadow: 0 6px 24px rgba(0,0,0,.06);
            margin-bottom: 40px;
            overflow: hidden;
        }
        .blog-card-body { padding: 28px 28px 8px; }
        .blog-date-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #eaf4ec;
            color: var(--primary, #2e7d32);
            font-weight: 600;
            font-size: .8rem;
            padding: 5px 14px;
            border-radius: 30px;
            margin-bottom: 14px;
        }
        .blog-card h2 {
            font-size: 1.4rem;
            font-weight: 700;
            color: #1b3a1e;
            margin-bottom: 12px;
        }
        .blog-card p.desc {
            color: #555;
            line-height: 1.7;
            margin-bottom: 20px;
        }

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
            border: 0;
            padding: 0;
            background: none;
            cursor: pointer;
            aspect-ratio: 1 / 1;
            overflow: hidden;
            border-radius: 8px;
        }
        .blog-gallery img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            transition: transform .35s ease;
        }
        .blog-gallery button:hover img { transform: scale(1.06); }

        .blog-video-wrap {
            padding: 0 28px 28px;
        }
        .blog-video-frame {
            position: relative;
            width: 100%;
            padding-top: 56.25%;
            border-radius: 10px;
            overflow: hidden;
            background: #000;
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
            color: var(--primary, #2e7d32);
            font-weight: 600;
            text-decoration: none;
        }

        .blog-empty {
            text-align: center;
            padding: 80px 20px;
            color: #777;
        }

        #blogLightbox .modal-content { background: transparent; border: 0; }
        #blogLightbox .modal-body { padding: 0; text-align: center; }
        #blogLightbox img { max-height: 82vh; max-width: 100%; border-radius: 8px; }
        #blogLightbox .btn-close { filter: invert(1); position: absolute; top: -40px; right: 0; }
    </style>
</head>

<body>

    <?php include __DIR__ . '/navbar.php'; ?>

    <div class="blog-header">
        <div class="container">
            <h1>From the Field</h1>
            <p>Photos, videos and updates from our day-to-day work &mdash; plantations, logistics, community visits and more.</p>
        </div>
    </div>

    <div class="blog-feed">
        <div class="container" style="max-width: 820px;">
            <?php if (!$posts): ?>
                <div class="blog-empty">
                    <i class="bi bi-images" style="font-size:2.4rem;"></i>
                    <p class="mt-3 mb-0">No posts yet. Check back soon!</p>
                </div>
            <?php else: ?>
                <?php foreach ($posts as $post):
                    $photos = $photosByPost[$post['id']] ?? [];
                    $embedUrl = $post['video_url'] ? blog_video_embed_url($post['video_url']) : null;
                ?>
                <article class="blog-card">
                    <div class="blog-card-body">
                        <span class="blog-date-badge">
                            <i class="bi bi-calendar-event"></i>
                            <?= h(date('d M Y', strtotime((string) $post['event_date']))) ?>
                        </span>
                        <h2><?= h($post['title']) ?></h2>
                        <p class="desc"><?= nl2br(h($post['description'])) ?></p>
                    </div>

                    <?php if ($photos): ?>
                        <div class="blog-gallery">
                            <?php foreach ($photos as $i => $photo): ?>
                                <button type="button"
                                        onclick="openBlogLightbox(<?= (int) $post['id'] ?>, <?= (int) $i ?>)">
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
                </article>

                <!-- photo data for this post's lightbox -->
                <script type="application/json" id="blog-photos-<?= (int) $post['id'] ?>">
                    <?= json_encode(array_values($photos), JSON_UNESCAPED_SLASHES) ?>
                </script>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Lightbox modal -->
    <div class="modal fade" id="blogLightbox" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                <div class="modal-body">
                    <img id="blogLightboxImg" src="" alt="">
                </div>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/footer.php'; ?>

    <a href="#" class="btn btn-lg btn-primary btn-lg-square rounded-0 back-to-top"><i class="bi bi-arrow-up"></i></a>

    <script src="https://code.jquery.com/jquery-3.4.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="lib/wow/wow.min.js"></script>
    <script src="lib/easing/easing.min.js"></script>
    <script src="lib/waypoints/waypoints.min.js"></script>
    <script src="js/main.js"></script>

    <script>
        function openBlogLightbox(postId, index) {
            var dataEl = document.getElementById('blog-photos-' + postId);
            if (!dataEl) return;
            var photos = JSON.parse(dataEl.textContent);
            if (!photos.length) return;
            document.getElementById('blogLightboxImg').src = photos[index];
            var modal = new bootstrap.Modal(document.getElementById('blogLightbox'));
            modal.show();
        }
    </script>
</body>

</html>
