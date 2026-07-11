<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

define('BLOG_UPLOAD_DIR', __DIR__ . '/../uploads/blog');

// ---- Handle delete (POST only, CSRF-checked) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_require_valid();

    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        // Grab file paths first so we can clean up disk after the DB rows are gone.
        $photoStmt = $pdo->prepare('SELECT file_path FROM blog_photos WHERE post_id = :id');
        $photoStmt->execute([':id' => $id]);
        $filesToDelete = $photoStmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM blog_photos WHERE post_id = :id')->execute([':id' => $id]);
            $pdo->prepare('DELETE FROM blog_posts WHERE id = :id')->execute([':id' => $id]);
            $pdo->commit();

            foreach ($filesToDelete as $relativePath) {
                // file_path is stored like "uploads/blog/xxxx.jpg" — resolve relative to admin/../
                $abs = __DIR__ . '/../' . $relativePath;
                if (is_file($abs)) {
                    @unlink($abs);
                }
            }

            log_activity((int) $_SESSION['admin_id'], 'blog_post_deleted', "post_id={$id}");
            $_SESSION['flash_success'] = 'Blog post deleted successfully.';
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('Blog post deletion failed: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Something went wrong while deleting the post.';
        }
    }
    header('Location: blog_manage.php');
    exit;
}

// ---- Search ----
$search = clean_input((string) ($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 150);

// ---- Pagination ----
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = defined('USERS_PER_PAGE') ? USERS_PER_PAGE : 15;
$offset = ($page - 1) * $perPage;

if ($search !== '') {
    $likeTerm = '%' . $search . '%';
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM blog_posts WHERE title LIKE :s');
    $countStmt->execute([':s' => $likeTerm]);
    $totalRows = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT bp.id, bp.title, bp.slug, bp.event_date, bp.status,
                COUNT(bph.id) AS photo_count
         FROM blog_posts bp
         LEFT JOIN blog_photos bph ON bph.post_id = bp.id
         WHERE bp.title LIKE :s
         GROUP BY bp.id
         ORDER BY bp.event_date DESC, bp.id DESC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':s', $likeTerm, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $totalRows = (int) $pdo->query('SELECT COUNT(*) FROM blog_posts')->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT bp.id, bp.title, bp.slug, bp.event_date, bp.status,
                COUNT(bph.id) AS photo_count
         FROM blog_posts bp
         LEFT JOIN blog_photos bph ON bph.post_id = bp.id
         GROUP BY bp.id
         ORDER BY bp.event_date DESC, bp.id DESC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
}

$posts = $stmt->fetchAll();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

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

$pageTitle = 'Manage Blog Posts';
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/css/dashboard.css">

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
          <div class="breadcrumb">Biome <span class="sep">/</span> <span class="current">Blog Posts</span></div>
          <h1>Manage blog posts</h1>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <div class="datetime btn-ghost btn" style="cursor:default;">
            <i class="fa-solid fa-newspaper"></i>
            <?= e(number_format($totalRows)) ?> total
          </div>
          <a href="../blog.php" target="_blank" class="btn btn-ghost"><i class="fa-solid fa-arrow-up-right-from-square"></i> View public blog</a>
          <a href="blog_add.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add new post</a>
        </div>
      </div>

      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div class="flash-success" style="background:#e6f4ea;border:1px solid #bfe4c9;color:#1b7a34;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:.9rem;">
          <?= e($_SESSION['flash_success']) ?>
        </div>
        <?php unset($_SESSION['flash_success']); ?>
      <?php endif; ?>
      <?php if (!empty($_SESSION['flash_error'])): ?>
        <div class="flash-error" style="background:#fdecea;border:1px solid #f5c2bd;color:#7a271a;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:.9rem;">
          <?= e($_SESSION['flash_error']) ?>
        </div>
        <?php unset($_SESSION['flash_error']); ?>
      <?php endif; ?>

      <!-- Signature element: live activity pulse -->
      <svg class="pulse-divider" viewBox="0 0 1200 34" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0,17 L1200,17"></path>
        <path class="live" d="M0,17 L120,17 L132,4 L144,30 L156,17 L300,17 L312,9 L324,25 L336,17 L520,17 L532,2 L544,32 L556,17 L760,17 L772,7 L784,27 L796,17 L1000,17 L1012,4 L1024,30 L1036,17 L1200,17"></path>
      </svg>

      <!-- ---------- Toolbar: search ---------- -->
      <div class="toolbar">
        <form method="get" action="blog_manage.php" class="search-form">
          <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="q" placeholder="Search by title"
                   value="<?= e($search) ?>" maxlength="150">
          </div>
          <button type="submit" class="btn btn-ghost"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
          <?php if ($search !== ''): ?>
            <a href="blog_manage.php" class="btn btn-secondary">Clear</a>
          <?php endif; ?>
        </form>

        <div class="filter-pills">
          <span class="filter-pill active">All posts</span>
          <span class="filter-pill">Published</span>
          <span class="filter-pill">Draft</span>
        </div>
      </div>

      <!-- ---------- Posts table ---------- -->
      <div class="table-panel">
        <div class="table-caption">
          <span>
            <?php if ($search !== ''): ?>
              Showing results for <strong>&ldquo;<?= e($search) ?>&rdquo;</strong>
            <?php else: ?>
              Showing <strong><?= e((string) count($posts)) ?></strong> of <strong><?= e(number_format($totalRows)) ?></strong> posts
            <?php endif; ?>
          </span>
          <span>Page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?></span>
        </div>

        <div class="table-scroll">
          <table class="data-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Title</th>
                <th>Event date</th>
                <th>Photos</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$posts): ?>
                <tr>
                  <td colspan="6">
                    <div class="empty-state">
                      <i class="fa-solid fa-image"></i>
                      <p>No blog posts found<?= $search !== '' ? ' for that search' : '' ?>.</p>
                      <span><?= $search !== '' ? 'Try a different title.' : 'New posts will appear here once added.' ?></span>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
              <?php foreach ($posts as $p): ?>
                <tr>
                  <td><span class="row-id">#<?= (int) $p['id'] ?></span></td>
                  <td>
                    <div class="user-cell">
                      <div class="av"><i class="fa-solid fa-image" style="font-size:12px;"></i></div>
                      <div class="meta">
                        <strong><?= e($p['title']) ?></strong>
                      </div>
                    </div>
                  </td>
                  <td><span class="mono-time"><?= e((string) $p['event_date']) ?></span></td>
                  <td><?= (int) $p['photo_count'] ?></td>
                  <td>
                    <span class="badge badge-<?= $p['status'] === 'published' ? 'success' : 'muted' ?>">
                      <?= e($p['status']) ?>
                    </span>
                  </td>
                  <td class="actions">
                    <a href="blog_edit.php?id=<?= (int) $p['id'] ?>" class="btn btn-small btn-secondary">
                      <i class="fa-solid fa-pen"></i> Edit
                    </a>
                    <form method="post" action="blog_manage.php" class="inline-form"
                          onsubmit="return confirm('Delete this post and all of its photos? This cannot be undone.');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                      <button type="submit" class="btn btn-small btn-danger">
                        <i class="fa-solid fa-trash"></i> Delete
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- ---------- Pagination ---------- -->
      <?php if ($totalPages > 1): ?>
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="blog_manage.php?page=<?= $page - 1 ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" aria-label="Previous page">
              <i class="fa-solid fa-chevron-left" style="font-size:11px;"></i>
            </a>
          <?php endif; ?>

          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="blog_manage.php?page=<?= $p ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
               class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="blog_manage.php?page=<?= $page + 1 ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" aria-label="Next page">
              <i class="fa-solid fa-chevron-right" style="font-size:11px;"></i>
            </a>
          <?php endif; ?>
        </div>
      <?php endif; ?>

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