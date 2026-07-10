<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

$pageTitle = 'Edit Blog Post';
$errors = [];

define('BLOG_UPLOAD_DIR', __DIR__ . '/../uploads/blog');
define('BLOG_UPLOAD_URL_PREFIX', 'uploads/blog');
const MAX_PHOTO_BYTES = 5 * 1024 * 1024; // 5MB per photo
const ALLOWED_MIME = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

$postId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($postId <= 0) {
    header('Location: blog_manage.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM blog_posts WHERE id = :id');
$stmt->execute([':id' => $postId]);
$post = $stmt->fetch();

if (!$post) {
    $_SESSION['flash_error'] = 'That blog post could not be found.';
    header('Location: blog_manage.php');
    exit;
}

function make_slug_excluding(PDO $pdo, string $title, int $excludeId): string
{
    $base = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    $base = $base !== '' ? $base : 'post';
    $slug = $base;
    $i = 1;
    $stmt = $pdo->prepare('SELECT id FROM blog_posts WHERE slug = :s AND id != :ex');
    while (true) {
        $stmt->execute([':s' => $slug, ':ex' => $excludeId]);
        if (!$stmt->fetch()) {
            return $slug;
        }
        $i++;
        $slug = $base . '-' . $i;
    }
}

$title       = (string) $post['title'];
$eventDate   = (string) $post['event_date'];
$description = (string) $post['description'];
$videoUrl    = (string) ($post['video_url'] ?? '');
$status      = (string) $post['status'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $title       = clean_input((string) ($_POST['title'] ?? ''));
    $eventDate   = clean_input((string) ($_POST['event_date'] ?? ''));
    $description = trim((string) ($_POST['description'] ?? ''));
    $videoUrl    = clean_input((string) ($_POST['video_url'] ?? ''));
    $status      = (string) ($_POST['status'] ?? 'published');
    $deletePhotoIds = array_map('intval', (array) ($_POST['delete_photos'] ?? []));

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

    // Validate any newly uploaded photos.
    $photoFiles = [];
    if (!empty($_FILES['photos']) && is_array($_FILES['photos']['name'])) {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        foreach ($_FILES['photos']['name'] as $i => $name) {
            if ($_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
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
        // Regenerate slug only if the title actually changed.
        $slug = $post['slug'];
        if ($title !== $post['title']) {
            $slug = make_slug_excluding($pdo, $title, $postId);
        }

        // Look up file paths for photos marked for deletion, so we can unlink after commit.
        $filesToUnlink = [];
        if ($deletePhotoIds) {
            $in = implode(',', array_fill(0, count($deletePhotoIds), '?'));
            $delStmt = $pdo->prepare("SELECT id, file_path FROM blog_photos WHERE post_id = ? AND id IN ({$in})");
            $delStmt->execute(array_merge([$postId], $deletePhotoIds));
            foreach ($delStmt->fetchAll() as $row) {
                $filesToUnlink[] = $row['file_path'];
            }
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE blog_posts
                 SET title = :t, slug = :s, event_date = :d, description = :desc,
                     video_url = :v, status = :st
                 WHERE id = :id'
            )->execute([
                ':t'    => $title,
                ':s'    => $slug,
                ':d'    => $eventDate,
                ':desc' => $description,
                ':v'    => $videoUrl !== '' ? $videoUrl : null,
                ':st'   => $status,
                ':id'   => $postId,
            ]);

            if ($deletePhotoIds) {
                $in = implode(',', array_fill(0, count($deletePhotoIds), '?'));
                $pdo->prepare("DELETE FROM blog_photos WHERE post_id = ? AND id IN ({$in})")
                    ->execute(array_merge([$postId], $deletePhotoIds));
            }

            if ($photoFiles) {
                $maxOrderStmt = $pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) FROM blog_photos WHERE post_id = :id');
                $maxOrderStmt->execute([':id' => $postId]);
                $order = ((int) $maxOrderStmt->fetchColumn()) + 1;

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
            }

            $pdo->commit();

            foreach ($filesToUnlink as $relativePath) {
                $abs = __DIR__ . '/../' . $relativePath;
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }

            log_activity((int) $_SESSION['admin_id'], 'blog_post_updated', "post_id={$postId}, title={$title}");
            $_SESSION['flash_success'] = 'Blog post updated successfully.';
            header('Location: blog_edit.php?id=' . $postId);
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Blog post update failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving the post. Please try again.';
        }
    }
}

// Current photos (re-fetch in case we just deleted/added some).
$photoStmt = $pdo->prepare('SELECT id, file_path FROM blog_photos WHERE post_id = :id ORDER BY sort_order ASC, id ASC');
$photoStmt->execute([':id' => $postId]);
$photos = $photoStmt->fetchAll();

function safe_scalar_blog(PDO $pdo, string $sql, $default = 0)
{
    try {
        $val = $pdo->query($sql)->fetchColumn();
        return $val === false ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}
$sidebarUserCount = (int) safe_scalar_blog($pdo, 'SELECT COUNT(*) FROM users');
$sidebarBlogCount = (int) safe_scalar_blog($pdo, 'SELECT COUNT(*) FROM blog_posts');

require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/css/dashboard.css">

<style>
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
  .card-panel h2 { font-size: .95rem; margin: 0 0 4px; color: var(--text-primary, #1b1b1b); }
  .card-panel .card-sub { font-size: .8rem; color: var(--text-muted); margin-bottom: 16px; }

  .photo-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
  .photo-tile { position: relative; border-radius: 8px; overflow: hidden; border: 1px solid var(--border, #e9ece9); aspect-ratio: 1 / 1; background: var(--surface-muted, #f4f6f5); }
  .photo-tile img { width: 100%; height: 100%; object-fit: cover; display: block; }
  .photo-tile .photo-check {
    position: absolute; top: 6px; right: 6px; display: flex; align-items: center; gap: 5px;
    background: rgba(0,0,0,.55); color: #fff; padding: 4px 7px; border-radius: 6px; font-size: .72rem;
  }
  .photo-tile .photo-check input { margin: 0; }
  .form-actions { display: flex; align-items: center; gap: 12px; margin-top: 4px; }

  .info-list { display: flex; flex-direction: column; gap: 10px; font-size: .85rem; }
  .info-list .info-row { display: flex; justify-content: space-between; gap: 10px; padding: 8px 0; border-bottom: 1px solid var(--border, #eee); }
  .info-list .info-row:last-child { border-bottom: none; }
  .info-list .info-label { color: var(--text-muted); }
</style>

<div class="app-shell">

  <!-- ===================== SIDEBAR ===================== -->
  <aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
      <div class="mark">A</div>
      <div class="name">Biome<br><small>Control Panel</small></div>
    </div>

    <div class="sidebar-section-label">Overview</div>
    <nav class="sidebar-nav">
      <ul>
        <li><a href="dashboard.php" class="nav-item"><i class="fa-solid fa-grid-2"></i> Dashboard</a></li>
        <li><a href="analytics.php" class="nav-item"><i class="fa-solid fa-chart-line"></i> Analytics</a></li>
        <li><a href="reports.php" class="nav-item"><i class="fa-solid fa-file-lines"></i> Reports</a></li>
      </ul>

      <div class="sidebar-section-label">Manage</div>
      <ul>
        <li><a href="users.php" class="nav-item"><i class="fa-solid fa-users"></i> Users <span class="pill"><?= e((string) $sidebarUserCount) ?></span></a></li>
        <li><a href="admins.php" class="nav-item"><i class="fa-solid fa-user-shield"></i> Admins</a></li>
        <li><a href="bamboo-enquiries.php" class="nav-item"><i class="fa-solid fa-user-shield"></i> Bamboo Trading</a></li>
        <li><a href="roles.php" class="nav-item"><i class="fa-solid fa-key"></i> Roles &amp; Permissions</a></li>
        <li><a href="content.php" class="nav-item"><i class="fa-solid fa-layer-group"></i> Content</a></li>
        <li><a href="blog_manage.php" class="nav-item active"><i class="fa-solid fa-newspaper"></i> Blog Posts <span class="pill"><?= e((string) $sidebarBlogCount) ?></span></a></li>
        <li><a href="billing.php" class="nav-item"><i class="fa-solid fa-credit-card"></i> Billing</a></li>
      </ul>

      <div class="sidebar-section-label">System</div>
      <ul>
        <li><a href="logs.php" class="nav-item"><i class="fa-solid fa-clock-rotate-left"></i> Activity Logs</a></li>
        <li><a href="notifications.php" class="nav-item"><i class="fa-solid fa-bell"></i> Notifications</a></li>
        <li><a href="security.php" class="nav-item"><i class="fa-solid fa-shield-halved"></i> Security</a></li>
        <li><a href="settings.php" class="nav-item"><i class="fa-solid fa-gear"></i> Settings</a></li>
      </ul>
    </nav>

    <div class="sidebar-foot">
      <div class="sidebar-upgrade">
        <div class="label">System status</div>
        <p>All services operational. Last checked just now.</p>
        <a href="security.php" class="btn btn-outline-accent" style="width:100%;justify-content:center;">
          <i class="fa-solid fa-circle-check"></i> View status page
        </a>
      </div>
    </div>
  </aside>

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
          <div class="breadcrumb">Biome <span class="sep">/</span> <a href="blog_manage.php" style="color:inherit;text-decoration:none;">Blog Posts</a> <span class="sep">/</span> <span class="current">Edit</span></div>
          <h1>Edit blog post</h1>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <a href="blog_manage.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to list</a>
        </div>
      </div>

      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="flash-success" style="background:#e6f4ea;border:1px solid #bfe4c9;color:#1b7a34;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:.9rem;">
          <?= e($_SESSION['flash_success']) ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
      <?php endif; ?>

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
          <div class="card-sub">Update the post's content, status, and photos.</div>

          <form method="post" enctype="multipart/form-data" novalidate>
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $postId ?>">

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
              <label>Current photos</label>
              <?php if (!$photos): ?>
                <div class="field-hint">No photos uploaded yet.</div>
              <?php else: ?>
                <div class="photo-grid">
                  <?php foreach ($photos as $photo): ?>
                    <div class="photo-tile">
                      <img src="../<?= e($photo['file_path']) ?>" alt="Post photo">
                      <label class="photo-check">
                        <input type="checkbox" name="delete_photos[]" value="<?= (int) $photo['id'] ?>">
                        Delete
                      </label>
                    </div>
                  <?php endforeach; ?>
                </div>
                <div class="field-hint">Check "Delete" on any photo you want to remove, then save.</div>
              <?php endif; ?>
            </div>

            <div class="form-group">
              <label for="photos">Add more photos</label>
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
              <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save changes</button>
              <a href="blog_manage.php" class="btn btn-secondary">Cancel</a>
            </div>
          </form>
        </div>

        <!-- ---------- Post info ---------- -->
        <div class="card-panel">
          <h2>Post info</h2>
          <div class="card-sub">Reference details for this post.</div>

          <div class="info-list">
            <div class="info-row">
              <span class="info-label">Post ID</span>
              <span>#<?= (int) $postId ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">Slug</span>
              <span><?= e($post['slug']) ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">Status</span>
              <span class="badge badge-<?= $status === 'published' ? 'success' : 'muted' ?>"><?= e($status) ?></span>
            </div>
            <div class="info-row">
              <span class="info-label">Photos</span>
              <span><?= count($photos) ?></span>
            </div>
            <?php if (!empty($post['created_at'])): ?>
            <div class="info-row">
              <span class="info-label">Created</span>
              <span><?= e((string) $post['created_at']) ?></span>
            </div>
            <?php endif; ?>
          </div>

          <div style="margin-top:16px;">
            <a href="../blog.php#<?= e($post['slug']) ?>" target="_blank" class="btn btn-ghost" style="width:100%;justify-content:center;">
              <i class="fa-solid fa-arrow-up-right-from-square"></i> View on public blog
            </a>
          </div>
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