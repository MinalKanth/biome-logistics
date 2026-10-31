<?php
/**
 * admin/includes/transport_shell.php
 * Shared page frame for the transport admin pages:  tl_shell_top() ... content ... tl_shell_bottom()
 * Requires bootstrap.php + transport_lib.php to be loaded and $pdo to exist.
 */
declare(strict_types=1);

function tl_shell_top(string $pageTitle, string $heading, array $crumbs = [], string $actionsHtml = ''): void
{
    global $pdo;
    $GLOBALS['pageTitle'] = $pageTitle;
    require __DIR__ . '/header.php';
    echo '<link rel="stylesheet" href="assets/css/dashboard.css">' . "\n";
    echo '<link rel="stylesheet" href="assets/css/admin-theme-green.css">' . "\n";
    echo '<link rel="stylesheet" href="assets/css/transport-admin.css">' . "\n";
    echo '<div class="app-shell">';
    require __DIR__ . '/sidebar.php';
    $initial = strtoupper(substr((string) ($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'A'), 0, 1));
    ?>
    <div class="main-col">
      <header class="topbar">
        <div style="display:flex;align-items:center;gap:14px;">
          <div class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></div>
        </div>
        <div class="topbar-right">
          <div class="profile-chip">
            <div class="avatar"><?= e($initial) ?></div>
            <div class="who"><strong><?= e($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Administrator') ?></strong><span>Admin</span></div>
          </div>
        </div>
      </header>
      <main class="content">
        <div class="page-head">
          <div>
            <div class="breadcrumb">Biome <span class="sep">/</span>
              <?php foreach ($crumbs as $label => $href): ?>
                <a href="<?= e($href) ?>" style="text-decoration:none;"><?= e($label) ?></a> <span class="sep">/</span>
              <?php endforeach; ?>
              <span class="current"><?= e($heading) ?></span>
            </div>
            <h1><?= e($heading) ?></h1>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;"><?= $actionsHtml ?></div>
        </div>
        <?php if (!empty($_SESSION['flash_success_page'])): ?>
          <div class="tx-flash ok"><?= e($_SESSION['flash_success_page']) ?></div><?php unset($_SESSION['flash_success_page']); ?>
        <?php endif; ?>
        <?php if (!empty($_SESSION['flash_error_page'])): ?>
          <div class="tx-flash err"><?= e($_SESSION['flash_error_page']) ?></div><?php unset($_SESSION['flash_error_page']); ?>
        <?php endif; ?>
    <?php
}

function tl_shell_bottom(): void
{
    ?>
      </main>
    </div>
    </div>
    <script>
    (function () {
      var t = document.getElementById('menuToggle'), s = document.getElementById('sidebar');
      if (t && s) { t.addEventListener('click', function () { s.classList.toggle('open'); }); }
    })();
    </script>
    <?php
    require __DIR__ . '/footer.php';
}

function tl_badge(string $text, string $class = 'muted'): string
{
    return '<span class="badge badge-' . e($class) . '">' . e($text) . '</span>';
}

function tl_status_badge(string $status): string
{
    $s = tl_statuses();
    $m = $s[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'class' => 'muted'];
    return tl_badge($m['label'], $m['class']);
}

function tl_payment_badge(string $ps): string
{
    $cls = ['unpaid' => 'danger', 'partial' => 'warning', 'paid' => 'success', 'refunded' => 'muted'];
    return tl_badge(tl_payment_statuses()[$ps] ?? $ps, $cls[$ps] ?? 'muted');
}
