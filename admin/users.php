<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

// ---- Handle delete (POST only, CSRF-checked) ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    csrf_require_valid();

    $id = (int) ($_POST['id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = :id');
        $stmt->execute([':id' => $id]);
        log_activity((int) $_SESSION['admin_id'], 'user_deleted', "user_id={$id}");
        $_SESSION['flash_success'] = 'User deleted successfully.';
    }
    header('Location: users.php');
    exit;
}

// ---- Search ----
$search = clean_input((string) ($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 150);

// ---- Pagination ----
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = USERS_PER_PAGE;
$offset = ($page - 1) * $perPage;

if ($search !== '') {
    $likeTerm = '%' . $search . '%';
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE full_name LIKE :s OR email LIKE :s');
    $countStmt->execute([':s' => $likeTerm]);
    $totalRows = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT id, full_name, email, phone, status, created_at
         FROM users
         WHERE full_name LIKE :s OR email LIKE :s
         ORDER BY id DESC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':s', $likeTerm, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $totalRows = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT id, full_name, email, phone, status, created_at
         FROM users
         ORDER BY id DESC
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
}

$users = $stmt->fetchAll();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

// Best-effort total user count for the sidebar pill (kept consistent with dashboard.php)
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

$pageTitle = 'Manage Users';
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/admin-theme-green.css">

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
          <div class="breadcrumb">Biome <span class="sep">/</span> <span class="current">Users</span></div>
          <h1>Manage users</h1>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <div class="datetime btn-ghost btn" style="cursor:default;">
            <i class="fa-solid fa-users"></i>
            <?= e(number_format($totalRows)) ?> total
          </div>
          <a href="user_form.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add new user</a>
        </div>
      </div>

      <!-- Signature element: live activity pulse -->
      <svg class="pulse-divider" viewBox="0 0 1200 34" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0,17 L1200,17"></path>
        <path class="live" d="M0,17 L120,17 L132,4 L144,30 L156,17 L300,17 L312,9 L324,25 L336,17 L520,17 L532,2 L544,32 L556,17 L760,17 L772,7 L784,27 L796,17 L1000,17 L1012,4 L1024,30 L1036,17 L1200,17"></path>
      </svg>

      <!-- ---------- Toolbar: search + filters ---------- -->
      <div class="toolbar">
        <form method="get" action="users.php" class="search-form">
          <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="q" placeholder="Search by name or email"
                   value="<?= e($search) ?>" maxlength="150">
          </div>
          <button type="submit" class="btn btn-ghost"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
          <?php if ($search !== ''): ?>
            <a href="users.php" class="btn btn-secondary"><svg class="icon"><use href="#icon-name"/></svg> Clear</a>
          <?php endif; ?>
        </form>

        <div class="filter-pills">
          <span class="filter-pill active">All users</span>
          <span class="filter-pill">Active</span>
          <span class="filter-pill">Inactive</span>
        </div>
      </div>

      <!-- ---------- Users table ---------- -->
      <div class="table-panel">
        <div class="table-caption">
          <span>
            <?php if ($search !== ''): ?>
              Showing results for <strong>&ldquo;<?= e($search) ?>&rdquo;</strong>
            <?php else: ?>
              Showing <strong><?= e((string) count($users)) ?></strong> of <strong><?= e(number_format($totalRows)) ?></strong> users
            <?php endif; ?>
          </span>
          <span>Page <?= e((string) $page) ?> of <?= e((string) $totalPages) ?></span>
        </div>

        <div class="table-scroll">
          <table class="data-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Status</th>
                <th>Created</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$users): ?>
                <tr>
                  <td colspan="7">
                    <div class="empty-state">
                      <i class="fa-solid fa-user-slash"></i>
                      <p>No users found<?= $search !== '' ? ' for that search' : '' ?>.</p>
                      <span><?= $search !== '' ? 'Try a different name or email.' : 'New users will appear here once added.' ?></span>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
              <?php foreach ($users as $u): ?>
                <tr>
                  <td><span class="row-id">#<?= (int) $u['id'] ?></span></td>
                  <td>
                    <div class="user-cell">
                      <div class="av"><?= e(strtoupper(substr($u['full_name'] ?? 'U', 0, 1))) ?></div>
                      <div class="meta"><strong><?= e($u['full_name']) ?></strong></div>
                    </div>
                  </td>
                  <td style="color:var(--text-secondary);"><?= e($u['email']) ?></td>
                  <td>
                    <?php if (!empty($u['phone'])): ?>
                      <span class="phone-cell"><?= e($u['phone']) ?></span>
                    <?php else: ?>
                      <span class="phone-cell empty">Not provided</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <span class="badge badge-<?= $u['status'] === 'active' ? 'success' : 'muted' ?>">
                      <?= e($u['status']) ?>
                    </span>
                  </td>
                  <td><span class="mono-time"><?= e($u['created_at']) ?></span></td>
                  <td class="actions">
                    <a href="user_form.php?id=<?= (int) $u['id'] ?>" class="btn btn-small btn-secondary">
                      <i class="fa-solid fa-pen"></i> Edit
                    </a>
                    <form method="post" action="users.php" class="inline-form"
                          onsubmit="return confirm('Delete this user? This cannot be undone.');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
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
            <a href="users.php?page=<?= $page - 1 ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" aria-label="Previous page">
              <i class="fa-solid fa-chevron-left" style="font-size:11px;"></i>
            </a>
          <?php endif; ?>

          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="users.php?page=<?= $p ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
               class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="users.php?page=<?= $page + 1 ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" aria-label="Next page">
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