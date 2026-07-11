<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

$editId = (int) ($_GET['id'] ?? 0);
$isEdit = $editId > 0;

$user = ['full_name' => '', 'email' => '', 'phone' => '', 'status' => 'active'];
$errors = [];

if ($isEdit) {
    $stmt = $pdo->prepare('SELECT id, full_name, email, phone, status FROM users WHERE id = :id');
    $stmt->execute([':id' => $editId]);
    $found = $stmt->fetch();
    if (!$found) {
        $_SESSION['flash_error'] = 'User not found.';
        header('Location: users.php');
        exit;
    }
    $user = $found;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    $fullName = clean_input((string) ($_POST['full_name'] ?? ''));
    $email    = clean_input((string) ($_POST['email'] ?? ''));
    $phone    = clean_input((string) ($_POST['phone'] ?? ''));
    $status   = (string) ($_POST['status'] ?? 'active');
    $postedId = (int) ($_POST['id'] ?? 0);

    // Re-derive edit mode from the posted ID, not just the query string.
    $isEdit = $postedId > 0;

    if ($fullName === '' || mb_strlen($fullName) > 150) {
        $errors['full_name'] = 'Full name is required and must be under 150 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
        $errors['email'] = 'A valid email address is required.';
    }
    if ($phone !== '' && !preg_match('/^[0-9+\-\s()]{0,30}$/', $phone)) {
        $errors['phone'] = 'Phone number contains invalid characters.';
    }
    if (!in_array($status, ['active', 'inactive'], true)) {
        $errors['status'] = 'Invalid status selected.';
    }

    if (!$errors) {
        // Uniqueness check on email, excluding self when editing.
        if ($isEdit) {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = :e AND id != :id');
            $check->execute([':e' => $email, ':id' => $postedId]);
        } else {
            $check = $pdo->prepare('SELECT id FROM users WHERE email = :e');
            $check->execute([':e' => $email]);
        }
        if ($check->fetch()) {
            $errors['email'] = 'A user with that email already exists.';
        }
    }

    if (!$errors) {
        if ($isEdit) {
            $pdo->prepare(
                'UPDATE users SET full_name = :n, email = :e, phone = :p, status = :s WHERE id = :id'
            )->execute([
                ':n' => $fullName, ':e' => $email, ':p' => $phone ?: null,
                ':s' => $status, ':id' => $postedId,
            ]);
            log_activity((int) $_SESSION['admin_id'], 'user_updated', "user_id={$postedId}");
            $_SESSION['flash_success'] = 'User updated successfully.';
        } else {
            $pdo->prepare(
                'INSERT INTO users (full_name, email, phone, status, created_by) VALUES (:n, :e, :p, :s, :c)'
            )->execute([
                ':n' => $fullName, ':e' => $email, ':p' => $phone ?: null,
                ':s' => $status, ':c' => (int) $_SESSION['admin_id'],
            ]);
            log_activity((int) $_SESSION['admin_id'], 'user_created', "email={$email}");
            $_SESSION['flash_success'] = 'User created successfully.';
        }
        header('Location: users.php');
        exit;
    }

    // Re-populate form with submitted values on validation failure.
    $user = [
        'id' => $postedId, 'full_name' => $fullName, 'email' => $email,
        'phone' => $phone, 'status' => $status,
    ];
}

function safe_scalar_transport(PDO $pdo, string $sql, $default = 0)
{
    try {
        $val = $pdo->query($sql)->fetchColumn();
        return $val === false ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}
$sidebarUserCount = (int) safe_scalar_transport($pdo, 'SELECT COUNT(*) FROM users');

$pageTitle = $isEdit ? 'Edit User' : 'Add User';
require __DIR__ . '/includes/header.php';

/* Small helpers to keep the markup below readable */
function fval(array $data, string $key): string { return e((string) ($data[$key] ?? '')); }
function ferr(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<span class="field-error">' . e($errors[$key]) . '</span>' : '';
}
function fclass(array $errors, string $key): string
{
    return isset($errors[$key]) ? 'has-error' : '';
}
?>

<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/admin-theme-green.css">

<style>
  .panel {
    background: var(--surface, #fff);
    border: 1px solid var(--border, #eee);
    border-radius: 12px;
    padding: 20px 22px;
    margin-bottom: 18px;
  }
  .panel-head {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 16px; padding-bottom: 12px;
    border-bottom: 1px solid var(--border, #eee);
  }
  .panel-head .n {
    width: 26px; height: 26px; border-radius: 50%;
    background: #eef2ff; color: #4b5fd6;
    display: flex; align-items: center; justify-content: center;
    font-size: .78rem; font-weight: 700;
  }
  .panel-head h3 { margin: 0; font-size: 1rem; }
  .panel-head span.sub { font-size: .78rem; color: var(--text-muted); display: block; }

  .form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px 16px;
  }
  .field { display: flex; flex-direction: column; gap: 5px; }
  .field label { font-size: .8rem; font-weight: 600; color: #33415c; }
  .field label .opt { font-weight: 400; color: var(--text-muted); }
  .field input[type=text], .field input[type=email], .field select {
    border: 1px solid var(--border, #ddd);
    border-radius: 8px;
    padding: 9px 11px;
    font-size: .87rem;
    font-family: inherit;
    background: #fff;
  }
  .field.has-error input, .field.has-error select {
    border-color: #e08a83; background: #fff8f7;
  }
  .field-error { font-size: .74rem; color: #c0362c; }

  .form-actions {
    position: sticky; bottom: 0;
    background: rgba(255,255,255,.96);
    backdrop-filter: blur(4px);
    border-top: 1px solid var(--border, #eee);
    padding: 14px 4px;
    display: flex; justify-content: flex-end; gap: 10px;
    margin-top: 6px;
  }
  .general-error {
    background:#fdecea;border:1px solid #f5c2bd;color:#7a271a;
    padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:.9rem;
  }
</style>

<div class="app-shell">

  <!-- ===================== SIDEBAR ===================== -->
  <?php require __DIR__ . '/includes/sidebar.php'; ?>

  <!-- ===================== MAIN COLUMN ===================== -->
  <div class="main-col">

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
        <div class="icon-btn" title="Notifications"><i class="fa-regular fa-bell"></i><span class="dot"></span></div>
        <div class="icon-btn" title="Help &amp; documentation"><i class="fa-regular fa-circle-question"></i></div>
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

    <main class="content">

      <div class="page-head">
        <div>
          <div class="breadcrumb">Biome <span class="sep">/</span> <a href="users.php" style="color:inherit;text-decoration:none;">Users</a> <span class="sep">/</span> <span class="current"><?= $isEdit ? 'Edit User' : 'New User' ?></span></div>
          <h1><?= $isEdit ? 'Edit user' : 'Create a new user' ?></h1>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <a href="users.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to list</a>
        </div>
      </div>

      <?php if ($errors): ?>
        <div class="general-error">Please fix the highlighted fields below and try again.</div>
      <?php endif; ?>

      <form method="post" action="user_form.php<?= $isEdit ? '?id=' . (int) $user['id'] : '' ?>" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($user['id'] ?? 0) ?>">

        <!-- ============ 1. User details ============ -->
        <div class="panel">
          <div class="panel-head">
            <div class="n">1</div>
            <div><h3>User details</h3><span class="sub">Basic account information</span></div>
          </div>
          <div class="form-grid">
            <div class="field <?= fclass($errors, 'full_name') ?>">
              <label>Full name *</label>
              <input type="text" name="full_name" maxlength="150" value="<?= fval($user, 'full_name') ?>" required>
              <?= ferr($errors, 'full_name') ?>
            </div>
            <div class="field <?= fclass($errors, 'email') ?>">
              <label>Email *</label>
              <input type="email" name="email" maxlength="150" value="<?= fval($user, 'email') ?>" required>
              <?= ferr($errors, 'email') ?>
            </div>
            <div class="field <?= fclass($errors, 'phone') ?>">
              <label>Phone <span class="opt">(optional)</span></label>
              <input type="text" name="phone" maxlength="30" value="<?= fval($user, 'phone') ?>">
              <?= ferr($errors, 'phone') ?>
            </div>
            <div class="field <?= fclass($errors, 'status') ?>">
              <label>Status</label>
              <select name="status">
                <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="inactive" <?= $user['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
              </select>
              <?= ferr($errors, 'status') ?>
            </div>
          </div>
        </div>

        <div class="form-actions">
          <a href="users.php" class="btn btn-secondary">Cancel</a>
          <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> <?= $isEdit ? 'Save changes' : 'Create user' ?></button>
        </div>
      </form>

    </main>
  </div>
</div>

<script>
(function () {
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () { sidebar.classList.toggle('open'); });
  }
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>