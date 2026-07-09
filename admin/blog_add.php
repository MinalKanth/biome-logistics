<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

$pageTitle = 'Add Blog Post';
$errors = [];

$title       = '';
$eventDate   = date('Y-m-d');
$description = '';
$videoUrl    = '';
$status      = 'published';

// Where photos are stored. Public (not under /admin) so blog.php can serve them directly.
define('BLOG_UPLOAD_DIR', __DIR__ . '/../uploads/blog');
define('BLOG_UPLOAD_URL_PREFIX', 'uploads/blog');
const MAX_PHOTO_BYTES = 5 * 1024 * 1024; // 5MB per photo
const ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

function make_slug(PDO $pdo, string $title): string
{
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    $base = $base !== '' ? $base : 'post';
    $slug = $base;
    $i = 1;
    $stmt = $pdo->prepare('SELECT id FROM blog_posts WHERE slug = :s');
    while (true) {
        $stmt->execute([':s' => $slug]);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $i++;
        $slug = $base . '-' . $i;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $title       = clean_input((string) ($_POST['title'] ?? ''));
    $eventDate   = clean_input((string) ($_POST['event_date'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $videoUrl    = clean_input((string) ($_POST['video_url'] ?? ''));
    $status      = (string) ($_POST['status'] ?? 'published');

    if ($title === '' || mb_strlen($title) > 200) {
        $errors[] = 'Title is required and must be under 200 characters.';
    }
    $dateObj = DateTime::createFromFormat('Y-m-d', $eventDate);
    if (!$dateObj || $dateObj->format('Y-m-d') !== $eventDate) {
        $errors[] = 'Please provide a valid event date.';
    }
    if ($description === '' || mb_strlen($description) > 5000) {
        $errors[] = 'Description is required and must be under 5000 characters.';
    }
    if ($videoUrl !== '' && !filter_var($videoUrl, FILTER_VALIDATE_URL)) {
        $errors[] = 'Video link must be a valid URL.';
    }
    if (!in_array($status, ['published', 'draft'], true)) {
        $errors[] = 'Invalid status selected.';
    }

    // Validate any uploaded photos up front so we never save a post with a bad file.
    $photoFiles = [];
    if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        foreach ($_FILES['photos']['name'] as $i => $name) {
            if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue; // empty extra file input slot
            }
            if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) {
                $errors[] = "Upload failed for \"{$name}\".";
                continue;
            }
            $tmpPath = $_FILES['photos']['tmp_name'][$i];
            $size = (int) $_FILES['photos']['size'][$i];
            if ($size > MAX_PHOTO_BYTES) {
                $errors[] = "\"{$name}\" is larger than 5MB.";
                continue;
            }
            $mime = $finfo->file($tmpPath) ?: '';
            if (!isset(ALLOWED_MIME[$mime])) {
                $errors[] = "\"{$name}\" must be a JPG, PNG, or WEBP image.";
                continue;
            }
            $photoFiles[] = ['tmp' => $tmpPath, 'ext' => ALLOWED_MIME[$mime]];
        }
    }

    if (!$errors) {
        if (!is_dir(BLOG_UPLOAD_DIR)) {
            mkdir(BLOG_UPLOAD_DIR, 0755, true);
        }

        $slug = make_slug($pdo, $title);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO blog_posts (title, slug, event_date, description, video_url, status, created_by)
                 VALUES (:t, :s, :d, :desc, :v, :st, :c)'
            )->execute([
                ':t'    => $title,
                ':s'    => $slug,
                ':d'    => $eventDate,
                ':desc' => $description,
                ':v'    => $videoUrl !== '' ? $videoUrl : null,
                ':st'   => $status,
                ':c'    => (int) $_SESSION['admin_id'],
            ]);
            $postId = (int) $pdo->lastInsertId();

            $order = 0;
            $photoStmt = $pdo->prepare(
                'INSERT INTO blog_photos (post_id, file_path, sort_order) VALUES (:p, :f, :o)'
            );
            foreach ($photoFiles as $photo) {
                $filename = bin2hex(random_bytes(16)) . '.' . $photo['ext'];
                $destination = BLOG_UPLOAD_DIR . '/' . $filename;
                if (!move_uploaded_file($photo['tmp'], $destination)) {
                    throw new RuntimeException('Could not save an uploaded photo to disk.');
                }
                $photoStmt->execute([
                    ':p' => $postId,
                    ':f' => BLOG_UPLOAD_URL_PREFIX . '/' . $filename,
                    ':o' => $order++,
                ]);
            }

            $pdo->commit();
            log_activity((int) $_SESSION['admin_id'], 'blog_post_created', "post_id={$postId}, title={$title}");
            $_SESSION['flash_success'] = 'Blog post published successfully.';
            header('Location: blog_add.php');
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Blog post creation failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving the post. Please try again.';
        }
    }
}

// Recent posts, just so the admin can see what's live without leaving the page.
$recent = $pdo->query(
    'SELECT bp.id, bp.title, bp.event_date, bp.status, COUNT(bph.id) AS photo_count
     FROM blog_posts bp
     LEFT JOIN blog_photos bph ON bph.post_id = bp.id
     GROUP BY bp.id
     ORDER BY bp.event_date DESC, bp.id DESC
     LIMIT 8'
)->fetchAll();

require __DIR__ . '/includes/header.php';
?>
<style>
    body { background: #f4f6f5; font-family: 'Inter', Arial, sans-serif; }
    .blog-add-wrap { max-width: 760px; margin: 40px auto; padding: 0 16px; }
    .blog-add-card { background: #fff; border-radius: 12px; padding: 28px; box-shadow: 0 4px 20px rgba(0,0,0,.06); }
    .blog-add-card h1 { font-size: 1.5rem; margin-bottom: 4px; color: #1b3a1e; }
    .blog-add-card .sub { color: #6b7280; margin-bottom: 24px; font-size: .92rem; }
    .form-group { margin-bottom: 18px; }
    label { display: block; font-weight: 600; margin-bottom: 6px; font-size: .9rem; color: #1b3a1e; }
    input[type=text], input[type=date], input[type=url], textarea, select {
        width: 100%; padding: 10px 12px; border: 1px solid #d7ddd8; border-radius: 8px; font-size: .95rem; box-sizing: border-box;
    }
    textarea { resize: vertical; min-height: 120px; }
    input[type=file] { width: 100%; padding: 8px; border: 1px dashed #b7c4b8; border-radius: 8px; background: #fafcfa; }
    .hint { font-size: .8rem; color: #8a8a8a; margin-top: 4px; }
    .btn-submit {
        background: #2e7d32; color: #fff; border: none; padding: 11px 26px; border-radius: 8px;
        font-weight: 600; cursor: pointer; font-size: .95rem;
    }
    .btn-submit:hover { background: #256428; }
    .errors { background: #fdecea; border: 1px solid #f5c2bd; color: #7a271a; padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: .9rem; }
    .errors ul { margin: 0; padding-left: 18px; }
    .recent-table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: .88rem; }
    .recent-table th, .recent-table td { text-align: left; padding: 8px 10px; border-bottom: 1px solid #eee; }
    .badge { padding: 2px 9px; border-radius: 20px; font-size: .75rem; font-weight: 600; }
    .badge-published { background: #e6f4ea; color: #1b7a34; }
    .badge-draft { background: #f1f1f1; color: #666; }
    .top-links { max-width: 760px; margin: 24px auto 0; padding: 0 16px; font-size: .9rem; }
    .top-links a { color: #2e7d32; text-decoration: none; font-weight: 600; }
</style>

<div class="top-links">
    <a href="dashboard.php">&larr; Back to Dashboard</a> &nbsp;|&nbsp; <a href="../blog.php" target="_blank">View public Blog page &#8599;</a>
</div>

<div class="blog-add-wrap">
    <div class="blog-add-card">
        <h1>Add a Blog Post</h1>
        <div class="sub">Share a daily event with photos and an optional video link.</div>

        <?php if ($errors): ?>
            <div class="errors">
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?= e($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" id="title" name="title" maxlength="200" value="<?= e($title) ?>" required>
            </div>

            <div class="form-group">
                <label for="event_date">Event Date</label>
                <input type="date" id="event_date" name="event_date" value="<?= e($eventDate) ?>" required>
            </div>

            <div class="form-group">
                <label for="description">Description</label>
                <textarea id="description" name="description" maxlength="5000" required><?= e($description) ?></textarea>
            </div>

            <div class="form-group">
                <label for="video_url">Video Link <span style="font-weight:400;color:#8a8a8a;">(YouTube/Vimeo, optional)</span></label>
                <input type="url" id="video_url" name="video_url" placeholder="https://www.youtube.com/watch?v=..." value="<?= e($videoUrl) ?>">
            </div>

            <div class="form-group">
                <label for="photos">Photos</label>
                <input type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
                <div class="hint">JPG, PNG or WEBP. Up to 5MB each. You can select multiple photos at once.</div>
            </div>

            <div class="form-group">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published (visible on the blog page)</option>
                    <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft (hidden for now)</option>
                </select>
            </div>

            <button type="submit" class="btn-submit">Publish Post</button>
        </form>

        <?php if ($recent): ?>
            <h2 style="font-size:1.05rem;margin-top:34px;color:#1b3a1e;">Recently Added</h2>
            <table class="recent-table">
                <thead>
                    <tr><th>Title</th><th>Date</th><th>Photos</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($recent as $row): ?>
                        <tr>
                            <td><?= e($row['title']) ?></td>
                            <td><?= e((string) $row['event_date']) ?></td>
                            <td><?= (int) $row['photo_count'] ?></td>
                            <td>
                                <span class="badge badge-<?= e($row['status']) ?>"><?= e($row['status']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
