<?php
/**
 * admin/bamboo_enquiries.php - Bamboo enquiry pipeline (REPLACES the old list-and-delete page).
 * New / Contacted / Quoted / Negotiation / Won / Lost, follow-up dates, internal notes,
 * one-click call + WhatsApp, and "Create quotation" straight from an enquiry.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bamboo_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$ST = bb_enquiry_statuses();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $action = (string) ($_POST['action'] ?? '');
    $id = (int) ($_POST['id'] ?? 0);
    try {
        if ($action === 'update' && $id > 0) {
            $status = (string) ($_POST['status'] ?? '');
            $notes  = mb_substr(trim((string) ($_POST['internal_notes'] ?? '')), 0, 3000);
            $fu     = (string) ($_POST['follow_up_date'] ?? '');
            $fuOk   = ($fu !== '' && ($d = DateTime::createFromFormat('!Y-m-d', $fu)) && $d->format('Y-m-d') === $fu) ? $fu : null;
            if (isset($ST[$status])) {
                $pdo->prepare(
                    "UPDATE bamboo_enquiries SET status = :s, internal_notes = :n, follow_up_date = :f,
                        last_contacted_at = IF(:s2 <> 'new' AND last_contacted_at IS NULL, NOW(), last_contacted_at), updated_at = NOW() WHERE id = :id"
                )->execute([':s' => $status, ':s2' => $status, ':n' => $notes ?: null, ':f' => $fuOk, ':id' => $id]);
                log_activity((int) $_SESSION['admin_id'], 'bamboo_enquiry_updated', "id={$id} status={$status}");
                $_SESSION['flash_success_page'] = 'Enquiry updated.';
            }
        } elseif ($action === 'delete' && $id > 0) {
            $pdo->prepare('DELETE FROM bamboo_enquiries WHERE id = :id')->execute([':id' => $id]);
            log_activity((int) $_SESSION['admin_id'], 'bamboo_enquiry_deleted', "id={$id}");
            $_SESSION['flash_success_page'] = 'Enquiry deleted.';
        }
    } catch (Throwable $e) {
        error_log('bamboo enquiries: ' . $e->getMessage());
        $_SESSION['flash_error_page'] = 'Something went wrong. Please try again.';
    }
    header('Location: bamboo_enquiries.php?' . http_build_query(array_intersect_key($_GET, array_flip(['s', 'q', 'page']))));
    exit;
}

$fs   = (string) ($_GET['s'] ?? '');
$fs   = ($fs === 'due' || isset($ST[$fs])) ? $fs : '';
$q    = mb_substr(clean_input((string) ($_GET['q'] ?? '')), 0, 100);
$page = max(1, (int) ($_GET['page'] ?? 1));
$per  = 15;

$where = ['1=1'];
$par = [];
if ($fs === 'due') {
    $where[] = "follow_up_date IS NOT NULL AND follow_up_date <= CURDATE() AND status NOT IN ('won','lost')";
} elseif ($fs !== '') {
    $where[] = 'status = :st';
    $par[':st'] = $fs;
}
if ($q !== '') {
    $where[] = '(full_name LIKE :q OR mobile_number LIKE :q OR email LIKE :q OR company_name LIKE :q OR reference LIKE :q OR products_selected LIKE :q OR city LIKE :q)';
    $par[':q'] = '%' . $q . '%';
}
$wsql = implode(' AND ', $where);

$scalar = function (string $sql, array $p = []) use ($pdo): int {
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return (int) $s->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
};
$counts = [];
foreach (array_keys($ST) as $k) {
    $counts[$k] = $scalar('SELECT COUNT(*) FROM bamboo_enquiries WHERE status = :s', [':s' => $k]);
}
$cDue   = $scalar("SELECT COUNT(*) FROM bamboo_enquiries WHERE follow_up_date IS NOT NULL AND follow_up_date <= CURDATE() AND status NOT IN ('won','lost')");
$cTotal = $scalar('SELECT COUNT(*) FROM bamboo_enquiries');
$cWeek  = $scalar('SELECT COUNT(*) FROM bamboo_enquiries WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)');
$closed = $counts['won'] + $counts['lost'];
$rate   = $closed > 0 ? round($counts['won'] / $closed * 100) : 0;

$total = $scalar("SELECT COUNT(*) FROM bamboo_enquiries WHERE $wsql", $par);
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$rows = [];
try {
    $st = $pdo->prepare("SELECT * FROM bamboo_enquiries WHERE $wsql ORDER BY (status IN ('won','lost')) ASC, id DESC LIMIT :l OFFSET :o");
    foreach ($par as $k => $v) {
        $st->bindValue($k, $v, PDO::PARAM_STR);
    }
    $st->bindValue(':l', $per, PDO::PARAM_INT);
    $st->bindValue(':o', ($page - 1) * $per, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    error_log('bamboo list: ' . $e->getMessage());
    $_SESSION['flash_error_page'] = 'Enquiries could not be loaded. Run admin/bamboo_install.php once.';
}

$qs = static fn(array $x): string => http_build_query(array_merge(['s' => $fs, 'q' => $q], $x));
tl_shell_top('Bamboo Enquiries', 'Bamboo Enquiries', ['Bamboo Trading' => 'bamboo_orders.php'],
    '<a class="btn btn-primary" href="bamboo_order_form.php"><i class="fa-solid fa-file-circle-plus"></i> New quotation</a>');
?>
<div class="tx-kpis">
  <div class="tx-kpi"><div class="l">Total enquiries</div><div class="v"><?= $cTotal ?></div></div>
  <div class="tx-kpi"><div class="l">New (untouched)</div><div class="v"><?= $counts['new'] ?></div></div>
  <div class="tx-kpi <?= $cDue ? 'warn' : '' ?>"><div class="l">Follow-ups due</div><div class="v"><?= $cDue ?></div></div>
  <div class="tx-kpi"><div class="l">Last 7 days</div><div class="v"><?= $cWeek ?></div></div>
  <div class="tx-kpi good"><div class="l">Win rate</div><div class="v"><?= $rate ?>%</div></div>
</div>

<div class="panel">
  <div class="tx-tabs">
    <a href="?<?= e($qs(['s' => '', 'page' => 1])) ?>" class="<?= $fs === '' ? 'on' : '' ?>">All</a>
    <a href="?<?= e($qs(['s' => 'due', 'page' => 1])) ?>" class="<?= $fs === 'due' ? 'on' : '' ?>">Follow-up due (<?= $cDue ?>)</a>
    <?php foreach ($ST as $k => $m): ?><a href="?<?= e($qs(['s' => $k, 'page' => 1])) ?>" class="<?= $fs === $k ? 'on' : '' ?>"><?= e($m['label']) ?> (<?= $counts[$k] ?>)</a><?php endforeach; ?>
  </div>
  <form method="get" class="tx-filter">
    <input type="hidden" name="s" value="<?= e($fs) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, phone, company, reference, product, city" style="flex:1;min-width:240px">
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
  </form>

  <?php if (!$rows): ?>
    <p style="text-align:center;padding:34px;color:#6b7a72">No enquiries match. New requests from the website enquiry form appear here.</p>
  <?php endif; ?>

  <?php foreach ($rows as $r):
      $sm = $ST[$r['status'] ?? 'new'] ?? $ST['new'];
      $overdue = !empty($r['follow_up_date']) && $r['follow_up_date'] <= date('Y-m-d') && !in_array($r['status'], ['won', 'lost'], true);
  ?>
    <details style="border:1px solid #e3ece7;border-radius:12px;margin-bottom:10px;background:#fff" <?= ($r['status'] ?? 'new') === 'new' ? 'open' : '' ?>>
      <summary style="cursor:pointer;padding:14px 16px;display:flex;gap:14px;flex-wrap:wrap;align-items:center;list-style:none">
        <span style="font-family:ui-monospace,monospace;font-size:.78rem;color:#6b7a72"><?= e($r['reference'] ?: '#' . $r['id']) ?></span>
        <strong style="min-width:140px"><?= e($r['full_name']) ?><?= $r['company_name'] ? ' <span style="font-weight:400;color:#6b7a72">· ' . e($r['company_name']) . '</span>' : '' ?></strong>
        <span style="flex:1;min-width:180px;font-size:.86rem;color:#42564b"><?= e($r['products_selected']) ?><?= $r['quantity_required'] ? ' — ' . e($r['quantity_required']) : '' ?></span>
        <?= tl_badge($sm['label'], $sm['class']) ?>
        <?php if ($overdue): ?><?= tl_badge('Follow-up ' . date('d M', strtotime((string) $r['follow_up_date'])), 'danger') ?><?php endif; ?>
        <span style="font-size:.78rem;color:#6b7a72"><?= e(tl_dt($r['created_at'], 'd M Y, h:i A')) ?></span>
      </summary>
      <div style="padding:0 16px 16px;border-top:1px dashed #e3ece7">
        <div class="info-grid" style="margin:14px 0">
          <div class="info-item"><div class="label">Mobile</div><div class="value mono"><a href="tel:<?= e($r['mobile_number']) ?>"><?= e($r['mobile_number']) ?></a></div></div>
          <div class="info-item"><div class="label">E-mail</div><div class="value <?= $r['email'] ? '' : 'muted' ?>"><?= e($r['email'] ?: '—') ?></div></div>
          <div class="info-item"><div class="label">Location</div><div class="value"><?= e(trim(($r['city'] ?? '') . ', ' . ($r['state'] ?? ''), ' ,') ?: '—') ?></div></div>
          <div class="info-item"><div class="label">Deliver to</div><div class="value <?= $r['delivery_location'] ? '' : 'muted' ?>"><?= e($r['delivery_location'] ?: '—') ?></div></div>
        </div>
        <?php if ($r['additional_requirements']): ?><div class="info-item" style="margin-bottom:14px"><div class="label">Requirement</div><div class="value" style="font-weight:500"><?= nl2br(e($r['additional_requirements'])) ?></div></div><?php endif; ?>

        <form method="post" class="tx-form" style="align-items:end">
          <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <div><label>Status</label><select name="status"><?php foreach ($ST as $k => $m): ?><option value="<?= e($k) ?>"<?= ($r['status'] ?? 'new') === $k ? ' selected' : '' ?>><?= e($m['label']) ?></option><?php endforeach; ?></select></div>
          <div><label>Follow-up date</label><input type="date" name="follow_up_date" value="<?= e($r['follow_up_date'] ?? '') ?>"></div>
          <div class="full"><label>Internal notes (only you see this)</label><textarea name="internal_notes" rows="2" maxlength="3000"><?= e($r['internal_notes'] ?? '') ?></textarea></div>
          <div class="full" style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
            <a class="btn btn-secondary" href="tel:<?= e($r['mobile_number']) ?>"><i class="fa-solid fa-phone"></i> Call</a>
            <a class="btn btn-secondary" target="_blank" rel="noopener" href="<?= e(bb_wa_link($r['mobile_number'], 'Hello ' . $r['full_name'] . ', this is Biome Enterprises regarding your bamboo enquiry ' . ($r['reference'] ?? '') . '.')) ?>"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
            <?php if (!empty($r['order_id'])): ?>
              <a class="btn btn-secondary" href="bamboo_order_view.php?id=<?= (int) $r['order_id'] ?>"><i class="fa-solid fa-file-invoice"></i> Open quotation</a>
            <?php else: ?>
              <a class="btn btn-primary" style="background:#0f4c2d" href="bamboo_order_form.php?enquiry_id=<?= (int) $r['id'] ?>"><i class="fa-solid fa-file-circle-plus"></i> Create quotation</a>
            <?php endif; ?>
          </div>
        </form>
        <form method="post" style="margin-top:8px" onsubmit="return confirm('Delete this enquiry permanently?');">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
          <button class="btn btn-small btn-ghost" type="submit" style="color:#b02a20"><i class="fa-solid fa-trash"></i> Delete</button>
        </form>
      </div>
    </details>
  <?php endforeach; ?>

  <?php if ($pages > 1): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:.86rem">
      <span class="muted">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> enquiries</span>
      <span>
        <?php if ($page > 1): ?><a class="btn btn-small btn-ghost" href="?<?= e($qs(['page' => $page - 1])) ?>">← Prev</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn btn-small btn-ghost" href="?<?= e($qs(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
      </span>
    </div>
  <?php endif; ?>
</div>
<?php tl_shell_bottom(); ?>