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
        $stmt = $pdo->prepare('DELETE FROM bamboo_enquiries WHERE id = :id');
        $stmt->execute([':id' => $id]);
        log_activity((int) $_SESSION['admin_id'], 'bamboo_enquiry_deleted', "enquiry_id={$id}");
        $_SESSION['flash_success'] = 'Enquiry deleted successfully.';
    }
    header('Location: bamboo_enquiries.php');
    exit;
}

// ---- Search (name, email, mobile, company) ----
$search = clean_input((string) ($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 150);

// ---- Pagination ----
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = defined('ENQUIRIES_PER_PAGE') ? ENQUIRIES_PER_PAGE : 15;
$offset = ($page - 1) * $perPage;

$selectCols = 'id, full_name, mobile_number, email, company_name, state, city,
               products_selected, quantity_required, delivery_location,
               additional_requirements, ip_address, created_at';

if ($search !== '') {
    $likeTerm = '%' . $search . '%';
    $countStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM bamboo_enquiries
         WHERE full_name LIKE :s OR email LIKE :s OR mobile_number LIKE :s OR company_name LIKE :s'
    );
    $countStmt->execute([':s' => $likeTerm]);
    $totalRows = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT {$selectCols}
         FROM bamboo_enquiries
         WHERE full_name LIKE :s OR email LIKE :s OR mobile_number LIKE :s OR company_name LIKE :s
         ORDER BY id DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':s', $likeTerm, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
} else {
    $totalRows = (int) $pdo->query('SELECT COUNT(*) FROM bamboo_enquiries')->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT {$selectCols}
         FROM bamboo_enquiries
         ORDER BY id DESC
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
}

$enquiries = $stmt->fetchAll();
$totalPages = max(1, (int) ceil($totalRows / $perPage));

// Enquiries received in the last 7 days (for the stat card)
function safe_scalar_bamboo(PDO $pdo, string $sql, $default = 0)
{
    try {
        $val = $pdo->query($sql)->fetchColumn();
        return $val === false ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}
$enquiries7d = (int) safe_scalar_bamboo(
    $pdo,
    "SELECT COUNT(*) FROM bamboo_enquiries WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
);
$enquiriesToday = (int) safe_scalar_bamboo(
    $pdo,
    "SELECT COUNT(*) FROM bamboo_enquiries WHERE DATE(created_at) = CURDATE()"
);

// Best-effort total user count for the sidebar pill (kept consistent with dashboard.php)
$sidebarUserCount = (int) safe_scalar_bamboo($pdo, 'SELECT COUNT(*) FROM users');

$pageTitle = 'Bamboo Enquiries';
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
          <div class="breadcrumb">Biome <span class="sep">/</span> <span class="current">Bamboo Enquiries</span></div>
          <h1>Bamboo trading enquiries</h1>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <div class="datetime btn-ghost btn" style="cursor:default;">
            <i class="fa-solid fa-leaf"></i>
            <?= e(number_format($totalRows)) ?> total
          </div>
        </div>
      </div>

      <!-- Signature element: live activity pulse -->
      <svg class="pulse-divider" viewBox="0 0 1200 34" preserveAspectRatio="none" aria-hidden="true">
        <path d="M0,17 L1200,17"></path>
        <path class="live" d="M0,17 L120,17 L132,4 L144,30 L156,17 L300,17 L312,9 L324,25 L336,17 L520,17 L532,2 L544,32 L556,17 L760,17 L772,7 L784,27 L796,17 L1000,17 L1012,4 L1024,30 L1036,17 L1200,17"></path>
      </svg>

      <!-- ---------- Stat cards ---------- -->
      <div class="stat-grid">
        <div class="stat-card">
          <div class="top-row">
            <div class="icon-wrap"><i class="fa-solid fa-inbox"></i></div>
          </div>
          <h2><?= e(number_format($totalRows)) ?></h2>
          <p class="label">Total enquiries</p>
        </div>
        <div class="stat-card">
          <div class="top-row">
            <div class="icon-wrap"><i class="fa-solid fa-calendar-day"></i></div>
          </div>
          <h2><?= e(number_format($enquiriesToday)) ?></h2>
          <p class="label">Received today</p>
        </div>
        <div class="stat-card accent">
          <div class="top-row">
            <div class="icon-wrap"><i class="fa-solid fa-chart-line"></i></div>
          </div>
          <h2><?= e(number_format($enquiries7d)) ?></h2>
          <p class="label">Last 7 days</p>
        </div>
        <div class="stat-card">
          <div class="top-row">
            <div class="icon-wrap"><i class="fa-solid fa-list-check"></i></div>
          </div>
          <h2>8</h2>
          <p class="label">Product types offered</p>
        </div>
      </div>

      <!-- ---------- Toolbar: search ---------- -->
      <div class="toolbar">
        <form method="get" action="bamboo_enquiries.php" class="search-form">
          <div class="search-field">
            <i class="fa-solid fa-magnifying-glass"></i>
            <input type="text" name="q" placeholder="Search by name, email, mobile or company"
                   value="<?= e($search) ?>" maxlength="150">
          </div>
          <button type="submit" class="btn btn-ghost"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
          <?php if ($search !== ''): ?>
            <a href="bamboo_enquiries.php" class="btn btn-secondary"><svg class="icon"><use href="#icon-name"/></svg> Clear</a>
          <?php endif; ?>
        </form>
      </div>

      <!-- ---------- Enquiries table ---------- -->
      <div class="table-panel">
        <div class="table-caption">
          <span>
            <?php if ($search !== ''): ?>
              Showing results for <strong>&ldquo;<?= e($search) ?>&rdquo;</strong>
            <?php else: ?>
              Showing <strong><?= e((string) count($enquiries)) ?></strong> of <strong><?= e(number_format($totalRows)) ?></strong> enquiries
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
                <th>Contact</th>
                <th>Company</th>
                <th>Location</th>
                <th>Products</th>
                <th>Quantity</th>
                <th>Delivery to</th>
                <th>IP address</th>
                <th>Received</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$enquiries): ?>
                <tr>
                  <td colspan="11">
                    <div class="empty-state">
                      <i class="fa-solid fa-inbox"></i>
                      <p>No enquiries found<?= $search !== '' ? ' for that search' : '' ?>.</p>
                      <span><?= $search !== '' ? 'Try a different name, email, mobile or company.' : 'Submissions from the bamboo trading enquiry form will appear here.' ?></span>
                    </div>
                  </td>
                </tr>
              <?php endif; ?>
              <?php foreach ($enquiries as $en): ?>
                <tr>
                  <td><span class="row-id">#<?= (int) $en['id'] ?></span></td>
                  <td>
                    <div class="user-cell">
                      <div class="av"><?= e(strtoupper(substr($en['full_name'] ?? 'U', 0, 1))) ?></div>
                      <div class="meta"><strong><?= e($en['full_name'] ?? '—') ?></strong></div>
                    </div>
                  </td>
                  <td>
                    <div style="display:flex;flex-direction:column;gap:3px;">
                      <span style="color:var(--text-secondary);font-size:13px;">
                        <i class="fa-solid fa-phone" style="font-size:10px;color:var(--text-muted);margin-right:5px;"></i><?= e($en['mobile_number'] ?? '—') ?>
                      </span>
                      <?php if (!empty($en['email'])): ?>
                        <span style="color:var(--text-muted);font-size:11.5px;">
                          <i class="fa-solid fa-envelope" style="font-size:10px;margin-right:5px;"></i><?= e($en['email']) ?>
                        </span>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td>
                    <?php if (!empty($en['company_name'])): ?>
                      <span style="color:var(--text-secondary);"><?= e($en['company_name']) ?></span>
                    <?php else: ?>
                      <span class="phone-cell empty">Not provided</span>
                    <?php endif; ?>
                  </td>
                  <td style="color:var(--text-secondary);font-size:13px;">
                    <?php
                      $location = trim(($en['city'] ?? '') . ($en['city'] && $en['state'] ? ', ' : '') . ($en['state'] ?? ''));
                      echo $location !== '' ? e($location) : '<span class="phone-cell empty">—</span>';
                    ?>
                  </td>
                  <td style="max-width:220px;">
                    <?php
                      $productList = !empty($en['products_selected']) ? array_map('trim', explode(',', $en['products_selected'])) : [];
                    ?>
                    <?php if ($productList): ?>
                      <div style="display:flex;flex-wrap:wrap;gap:5px;">
                        <?php foreach ($productList as $prod): ?>
                          <span class="badge badge-muted" style="white-space:nowrap;"><?= e($prod) ?></span>
                        <?php endforeach; ?>
                      </div>
                    <?php else: ?>
                      <span class="phone-cell empty">—</span>
                    <?php endif; ?>
                  </td>
                  <td style="color:var(--text-secondary);font-size:13px;">
                    <?= !empty($en['quantity_required']) ? e($en['quantity_required']) : '<span class="phone-cell empty">—</span>' ?>
                  </td>
                  <td style="color:var(--text-secondary);font-size:13px;max-width:180px;">
                    <?= !empty($en['delivery_location']) ? e($en['delivery_location']) : '<span class="phone-cell empty">—</span>' ?>
                  </td>
                  <td><span class="ip-tag"><?= e($en['ip_address'] ?? '—') ?></span></td>
                  <td><span class="mono-time"><?= e($en['created_at'] ?? '—') ?></span></td>
                  <td class="actions">
                    <button type="button"
                            class="btn btn-small btn-secondary js-view-enquiry"
                            data-id="<?= (int) $en['id'] ?>"
                            data-name="<?= e($en['full_name'] ?? '') ?>"
                            data-mobile="<?= e($en['mobile_number'] ?? '') ?>"
                            data-email="<?= e($en['email'] ?? '') ?>"
                            data-company="<?= e($en['company_name'] ?? '') ?>"
                            data-location="<?= e($location !== '' ? $location : '') ?>"
                            data-products="<?= e($productList ? implode(', ', $productList) : '') ?>"
                            data-quantity="<?= e($en['quantity_required'] ?? '') ?>"
                            data-delivery="<?= e($en['delivery_location'] ?? '') ?>"
                            data-message="<?= e($en['additional_requirements'] ?? '') ?>"
                            data-ip="<?= e($en['ip_address'] ?? '') ?>"
                            data-created="<?= e($en['created_at'] ?? '') ?>">
                      <i class="fa-solid fa-eye"></i> View
                    </button>

                    <form method="post" action="bamboo_enquiries.php" class="inline-form js-delete-form">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="id" value="<?= (int) $en['id'] ?>">
                      <button type="button"
                              class="btn btn-small btn-danger js-delete-trigger"
                              data-name="<?= e($en['full_name'] ?? 'this enquiry') ?>">
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
            <a href="bamboo_enquiries.php?page=<?= $page - 1 ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" aria-label="Previous page">
              <i class="fa-solid fa-chevron-left" style="font-size:11px;"></i>
            </a>
          <?php endif; ?>

          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="bamboo_enquiries.php?page=<?= $p ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>"
               class="<?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="bamboo_enquiries.php?page=<?= $page + 1 ?><?= $search !== '' ? '&q=' . urlencode($search) : '' ?>" aria-label="Next page">
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

<!-- ===================== VIEW ENQUIRY MODAL ===================== -->
<div class="modal-overlay" id="viewModal" aria-hidden="true">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="viewModalTitle">
    <div class="modal-head">
      <div>
        <span class="row-id" id="viewModalId">#—</span>
        <h3 id="viewModalTitle">Enquiry details</h3>
      </div>
      <button type="button" class="icon-btn js-modal-close" data-target="viewModal" aria-label="Close">
        <svg class="icon"><use href="#icon-name"/></svg>
      </button>
    </div>

    <div class="modal-body">
      <div class="modal-grid">
        <div class="modal-field">
          <span class="modal-label"><i class="fa-solid fa-user"></i> Full name</span>
          <span class="modal-value" id="viewName">—</span>
        </div>
        <div class="modal-field">
          <span class="modal-label"><i class="fa-solid fa-phone"></i> Mobile number</span>
          <span class="modal-value" id="viewMobile">—</span>
        </div>
        <div class="modal-field">
          <span class="modal-label"><i class="fa-solid fa-envelope"></i> Email</span>
          <span class="modal-value" id="viewEmail">—</span>
        </div>
        <div class="modal-field">
          <span class="modal-label"><i class="fa-solid fa-building"></i> Company</span>
          <span class="modal-value" id="viewCompany">—</span>
        </div>
        <div class="modal-field">
          <span class="modal-label"><i class="fa-solid fa-location-dot"></i> Location</span>
          <span class="modal-value" id="viewLocation">—</span>
        </div>
        <div class="modal-field">
          <span class="modal-label"><i class="fa-solid fa-box"></i> Quantity required</span>
          <span class="modal-value" id="viewQuantity">—</span>
        </div>
        <div class="modal-field" style="grid-column:1 / -1;">
          <span class="modal-label"><i class="fa-solid fa-truck"></i> Delivery location</span>
          <span class="modal-value" id="viewDelivery">—</span>
        </div>
        <div class="modal-field" style="grid-column:1 / -1;">
          <span class="modal-label"><i class="fa-solid fa-leaf"></i> Products selected</span>
          <div class="modal-tags" id="viewProducts"></div>
        </div>
        <div class="modal-field" style="grid-column:1 / -1;">
          <span class="modal-label"><i class="fa-solid fa-message"></i> Additional requirements</span>
          <p class="modal-message" id="viewMessage">—</p>
        </div>
        <div class="modal-field">
          <span class="modal-label"><i class="fa-solid fa-network-wired"></i> IP address</span>
          <span class="modal-value mono-time" id="viewIp">—</span>
        </div>
        <div class="modal-field">
          <span class="modal-label"><i class="fa-regular fa-clock"></i> Received</span>
          <span class="modal-value mono-time" id="viewCreated">—</span>
        </div>
      </div>
    </div>

    <div class="modal-foot">
      <button type="button" class="btn btn-ghost js-modal-close" data-target="viewModal">Close</button>
    </div>
  </div>
</div>

<!-- ===================== DELETE CONFIRMATION MODAL ===================== -->
<div class="modal-overlay" id="deleteModal" aria-hidden="true">
  <div class="modal-box modal-box-sm" role="alertdialog" aria-modal="true" aria-labelledby="deleteModalTitle">
    <div class="modal-confirm-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <h3 id="deleteModalTitle" style="text-align:center;margin-bottom:8px;">Delete this enquiry?</h3>
    <p style="text-align:center;color:var(--text-secondary);font-size:13.5px;margin:0 0 22px;">
      You're about to permanently delete the enquiry from <strong id="deleteModalName" style="color:var(--text-primary);">this person</strong>. This cannot be undone.
    </p>
    <div class="modal-confirm-actions">
      <button type="button" class="btn btn-secondary js-modal-close" data-target="deleteModal" style="flex:1;justify-content:center;">
        Cancel
      </button>
      <button type="button" class="btn btn-danger js-confirm-delete" style="flex:1;justify-content:center;">
        <i class="fa-solid fa-trash"></i> Delete enquiry
      </button>
    </div>
  </div>
</div>


<script src="assets/js/bamboo_enquiries.js"></script>
<script>
  // Best-effort total blog count for the sidebar pill (kept consistent with dashboard.php)
  function safe_scalar_blog(PDO $pdo, string $sql, $default = 0)
  {
      try {
          $val = $pdo->query($sql)->fetchColumn();
          return $val === false ? $default : $val;
      } catch (Throwable $e) {
          return $default;
      }
  }
  const sidebarBlogCount = <?= (int) safe_scalar_blog($pdo, 'SELECT COUNT(*) FROM blog_posts') ?>;
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>