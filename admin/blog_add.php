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
        // mkdir()'s mode is filtered by umask, so chmod explicitly afterward to
        // guarantee the web server user can actually write into the folder.
        if (!is_dir(BLOG_UPLOAD_DIR)) {
            if (!@mkdir(BLOG_UPLOAD_DIR, 0775, true) && !is_dir(BLOG_UPLOAD_DIR)) {
                $errors[] = 'Could not create upload directory: ' . BLOG_UPLOAD_DIR;
            } else {
                @chmod(BLOG_UPLOAD_DIR, 0775);
            }
        }

        if (!$errors && !is_writable(BLOG_UPLOAD_DIR)) {
            @chmod(BLOG_UPLOAD_DIR, 0775);
            if (!is_writable(BLOG_UPLOAD_DIR)) {
                $errors[] = 'Upload directory is not writable: ' . BLOG_UPLOAD_DIR
                    . '. Run: chmod -R 775 "' . BLOG_UPLOAD_DIR . '"';
            }
        }
    }

    if (!$errors) {
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
                if (!@move_uploaded_file($photo['tmp'], $destination)) {
                    $lastErr = error_get_last();
                    throw new RuntimeException(
                        'Could not save uploaded photo to "' . $destination . '". '
                        . ($lastErr['message'] ?? 'Unknown filesystem error.')
                    );
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

// Best-effort total user count for the sidebar pill (kept consistent with dashboard.php / users.php)
function safe_scalar_users(PDO $pdo, string $sql, $default = 0)
{
    try {
        $val = $pdo->query($sql)->fetchColumn();
        return $val === false ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}
$sidebarUserCount = (int) safe_scalar_users($pdo, 'SELECT COUNT(*) FROM users');

require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/admin-theme-green.css">

<style>
  /* Scoped additions for the blog form — reuses the dashboard.css design tokens
     (--text-muted, --text-secondary, --border, --accent, etc.) so it inherits
     the same palette as the rest of the admin panel. */
  .blog-form-grid { display: grid; grid-template-columns: 1.6fr 1fr; gap: 20px; align-items: start; }
  @media (max-width: 980px) { .blog-form-grid { grid-template-columns: 1fr; } }

  .form-group { margin-bottom: 18px; }
  .form-group label {
    display: block; font-weight: 600; margin-bottom: 6px; font-size: .85rem; color: var(--text-primary, #1b1b1b);
  }
  .form-group .optional { font-weight: 400; color: var(--text-muted); }
  .form-group input[type=text],
  .form-group input[type=date],
  .form-group input[type=url],
  .form-group textarea,
  .form-group select {
    width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 8px;
    border: 1px solid var(--border, #e2e5e3); font-size: .92rem; font-family: inherit;
    background: var(--surface, #fff); color: inherit;
  }
  .form-group input:focus, .form-group textarea:focus, .form-group select:focus {
    outline: none; border-color: var(--accent, #2e7d32);
    box-shadow: 0 0 0 3px rgba(46,125,50,.12);
  }
  .form-group textarea { resize: vertical; min-height: 140px; }
  .form-group input[type=file] {
    width: 100%; padding: 10px; border: 1px dashed var(--border, #cfd6d1); border-radius: 8px;
    background: var(--surface-muted, #fafcfa); font-size: .88rem;
  }
  .field-hint { font-size: .78rem; color: var(--text-muted); margin-top: 5px; }

  .form-errors {
    background: #fdecea; border: 1px solid #f5c2bd; color: #7a271a;
    padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: .88rem;
  }
  .form-errors ul { margin: 0; padding-left: 18px; }

  .card-panel {
    background: var(--surface, #fff); border: 1px solid var(--border, #e9ece9);
    border-radius: 12px; padding: 22px;
  }
  .card-panel + .card-panel { margin-top: 20px; }
  .card-panel h2 {
    font-size: .95rem; margin: 0 0 4px; color: var(--text-primary, #1b1b1b);
  }
  .card-panel .card-sub { font-size: .8rem; color: var(--text-muted); margin-bottom: 16px; }

  .recent-list { display: flex; flex-direction: column; gap: 10px; }
  .recent-row {
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    padding: 10px 12px; border-radius: 8px; background: var(--surface-muted, #f7f9f7);
  }
  .recent-row .rt-title { font-weight: 600; font-size: .85rem; }
  .recent-row .rt-meta { font-size: .76rem; color: var(--text-muted); margin-top: 2px; }

  .form-actions { display: flex; align-items: center; gap: 12px; margin-top: 4px; }
</style>

<div class="app-shell">

  <!-- ===================== SIDEBAR ===================== -->
  <?php require __DIR__ . '/includes/sidebar.php'; ?>

  <!-- ===================== MAIN COLUMN ===================== -->
  <div class="main-col">

    <!-- ---------- Topbar ---------- -->
    <header class="topbar">
      <div style="display:flex;align-items:center;gap:14px;">
        <div class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></div>
        <div class="topbar-search">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" placeholder="Search users, logs, settings…">
          <kbd>⌘K</kbd>
        </div>
      </div>

      <div class="topbar-right">
        <div class="icon-btn" title="Notifications">
          <i class="fa-regular fa-bell"></i>
          <span class="dot"></span>
        </div>
        <div class="icon-btn" title="Help &amp; documentation">
          <i class="fa-regular fa-circle-question"></i>
        </div>
        <div class="topbar-divider"></div>
        <div class="profile-chip">
          <div class="avatar"><?= e(strtoupper(substr($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'A', 0, 1))) ?></div>
          <div class="who">
            <strong><?= e($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Administrator') ?></strong>
            <span><?= e($_SESSION['admin_role'] ?? 'Super Admin') ?></span>
          </div>
          <i class="fa-solid fa-chevron-down" style="font-size:10px;color:var(--text-muted);"></i>
        </div>
      </div>
    </header>

    <!-- ---------- Content ---------- -->
    <main class="content">

      <div class="page-head">
        <div>
          <div class="breadcrumb">Biome <span class="sep">/</span> <a href="content.php" style="color:inherit;text-decoration:none;">Content</a> <span class="sep">/</span> <span class="current">Add Blog Post</span></div>
          <h1>Add a blog post</h1>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <a href="../blog.php" target="_blank" class="btn btn-ghost"><i class="fa-solid fa-arrow-up-right-from-square"></i> View public blog</a>
        </div>
      </div>

      <!-- Signature element: live activity pulse -->
      <svg class="pulse-divider" viewBox="0 0 1200 34" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0,17 L1200,17"></path>
        <path class="live" d="M0,17 L120,17 L132,4 L144,30 L156,17 L300,17 L312,9 L324,25 L336,17 L520,17 L532,2 L544,32 L556,17 L760,17 L772,7 L784,27 L796,17 L1000,17 L1012,4 L1024,30 L1036,17 L1200,17"></path>
      </svg>

      <?php if ($errors): ?>
        <div class="form-errors">
          <ul>
            <?php foreach ($errors as $err): ?>
              <li><?= e($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <div class="blog-form-grid">

        <!-- ---------- Form ---------- -->
        <div class="card-panel">
          <h2>Post details</h2>
          <div class="card-sub">Share a daily event with photos and an optional video link.</div>

          <form method="post" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>

            <div class="form-group">
              <label for="title">Title</label>
              <input type="text" id="title" name="title" maxlength="200" value="<?= e($title) ?>" required>
            </div>

            <div class="form-group">
              <label for="event_date">Event date</label>
              <input type="date" id="event_date" name="event_date" value="<?= e($eventDate) ?>" required>
            </div>

            <div class="form-group">
              <label for="description">Description</label>
              <textarea id="description" name="description" maxlength="5000" required><?= e($description) ?></textarea>
            </div>

            <div class="form-group">
              <label for="video_url">Video link <span class="optional">(YouTube/Vimeo, optional)</span></label>
              <input type="url" id="video_url" name="video_url" placeholder="https://www.youtube.com/watch?v=..." value="<?= e($videoUrl) ?>">
            </div>

            <div class="form-group">
              <label for="photos">Photos</label>
              <input type="file" id="photos" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
              <div class="field-hint">JPG, PNG or WEBP. Up to 5MB each. You can select multiple photos at once.</div>
            </div>

            <div class="form-group">
              <label for="status">Status</label>
              <select id="status" name="status">
                <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Published (visible on the blog page)</option>
                <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Draft (hidden for now)</option>
              </select>
            </div>

            <div class="form-actions">
              <button type="submit" class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Publish post</button>
              <a href="content.php" class="btn btn-secondary">Cancel</a>
            </div>
          </form>
        </div>

        <!-- ---------- Recently added ---------- -->
        <div class="card-panel">
          <h2>Recently added</h2>
          <div class="card-sub">Last 8 posts, newest first.</div>

          <?php if (!$recent): ?>
            <div class="empty-state">
              <i class="fa-solid fa-image"></i>
              <p>No posts yet.</p>
              <span>Published posts will show up here.</span>
            </div>
          <?php else: ?>
            <div class="recent-list">
              <?php foreach ($recent as $row): ?>
                <div class="recent-row">
                  <div>
                    <div class="rt-title"><?= e($row['title']) ?></div>
                    <div class="rt-meta"><?= e((string) $row['event_date']) ?> &middot; <?= (int) $row['photo_count'] ?> photo<?= (int) $row['photo_count'] === 1 ? '' : 's' ?></div>
                  </div>
                  <span class="badge badge-<?= $row['status'] === 'published' ? 'success' : 'muted' ?>"><?= e($row['status']) ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      </div>

      <!-- ---------- Footer ---------- -->
      <div class="dash-footer">
        <span>&copy; <?= date('Y') ?> Biome Control Panel. All rights reserved.</span>
        <span>
          <a href="settings.php">Settings</a> &nbsp;·&nbsp;
          <a href="security.php">Security</a> &nbsp;·&nbsp;
          <a href="logs.php">Activity logs</a>
        </span>
      </div>

    </main>
  </div>
</div>

<script>
(function () {
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('open');
    });
  }
})();
function safe_scalar_blog(PDO $pdo, string $sql, $default = 0)
{
    try {
        $val = $pdo->query($sql)->fetchColumn();
        return $val === false ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}
$sidebarBlogCount = (int) safe_scalar_blog($pdo, 'SELECT COUNT(*) FROM blog_posts');
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>