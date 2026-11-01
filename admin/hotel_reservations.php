<?php
/**
 * admin/hotel_reservations.php - all reservations: quick views (arriving / in-house / departing today), status tabs, search, filters, CSV.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hotel_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$ST = htl_statuses();
$view = (string) ($_GET['view'] ?? '');
$view = in_array($view, ['arriving', 'inhouse', 'departing', 'unpaid'], true) ? $view : '';
$fs = (string) ($_GET['status'] ?? '');
$fs = isset($ST[$fs]) ? $fs : '';
$fp = (int) ($_GET['property'] ?? 0);
$q = mb_substr(clean_input((string) ($_GET['q'] ?? '')), 0, 100);
$from = htl_date((string) ($_GET['from'] ?? ''));
$to = htl_date((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$per = 20;

$where = ['r.deleted_at IS NULL'];
$par = [];
if ($view === 'arriving') { $where[] = "r.check_in = CURDATE() AND r.status IN ('pending','confirmed')"; }
if ($view === 'inhouse') { $where[] = "r.status = 'checked_in'"; }
if ($view === 'departing') { $where[] = "r.check_out = CURDATE() AND r.status = 'checked_in'"; }
if ($view === 'unpaid') { $where[] = "r.status IN ('confirmed','checked_in','checked_out') AND r.balance_amount > 0.009"; }
if ($fs !== '') { $where[] = 'r.status = :st'; $par[':st'] = $fs; }
if ($fp > 0) { $where[] = 'r.property_id = :pr'; $par[':pr'] = $fp; }
if ($from) { $where[] = 'r.check_in >= :f'; $par[':f'] = $from; }
if ($to) { $where[] = 'r.check_in <= :t'; $par[':t'] = $to; }
if ($q !== '') {
    $likes = [];
    foreach (['r.guest_name', 'r.phone', 'r.email', 'r.reference', 'r.room_numbers'] as $i => $c) { $likes[] = "$c LIKE :q$i"; $par[":q$i"] = '%' . $q . '%'; }
    $where[] = '(' . implode(' OR ', $likes) . ')';
}
$wsql = implode(' AND ', $where);
$join = 'FROM hotel_reservations r JOIN hotel_properties p ON p.id = r.property_id JOIN hotel_room_types t ON t.id = r.room_type_id';

if (($_GET['export'] ?? '') === 'csv') {
    $ex = $pdo->prepare("SELECT r.reference, r.status, r.guest_name, r.phone, r.email, p.name property, t.name room, r.rooms, r.adults, r.children, r.check_in, r.check_out, r.nights,
        r.room_total, r.extra_total, r.other_charges, r.discount, r.gst_amount, r.total_amount, r.paid_amount, r.balance_amount, r.payment_status, r.source, r.created_at $join WHERE $wsql ORDER BY r.id DESC");
    $ex->execute($par);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hotel-reservations-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $first = true;
    $safe = static fn($v) => (is_string($v) && $v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v;
    while ($r = $ex->fetch()) {
        if ($first) { fputcsv($out, array_keys($r)); $first = false; }
        fputcsv($out, array_map($safe, $r));
    }
    fclose($out);
    exit;
}

$c = $pdo->prepare("SELECT COUNT(*) $join WHERE $wsql");
$c->execute($par);
$total = (int) $c->fetchColumn();
$pages = max(1, (int) ceil($total / $per));
$page = min($page, $pages);
$st = $pdo->prepare("SELECT r.*, p.name AS pname, t.name AS rname $join WHERE $wsql ORDER BY r.check_in DESC, r.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($par);
$rows = $st->fetchAll();

$cnt = array_fill_keys(array_keys($ST), 0);
foreach ($pdo->query('SELECT status, COUNT(*) c FROM hotel_reservations WHERE deleted_at IS NULL GROUP BY status') as $r) { $cnt[$r['status']] = (int) $r['c']; }
$qa = (int) $pdo->query("SELECT COUNT(*) FROM hotel_reservations WHERE deleted_at IS NULL AND check_in = CURDATE() AND status IN ('pending','confirmed')")->fetchColumn();
$qi = (int) $pdo->query("SELECT COUNT(*) FROM hotel_reservations WHERE deleted_at IS NULL AND status = 'checked_in'")->fetchColumn();
$qd = (int) $pdo->query("SELECT COUNT(*) FROM hotel_reservations WHERE deleted_at IS NULL AND check_out = CURDATE() AND status = 'checked_in'")->fetchColumn();
$props = $pdo->query('SELECT id, name FROM hotel_properties WHERE deleted_at IS NULL ORDER BY name')->fetchAll();
$qs = static fn(array $o = []) => http_build_query(array_filter(array_merge(['view' => $view, 'status' => $fs, 'property' => $fp ?: '', 'q' => $q, 'from' => $from, 'to' => $to], $o), static fn($v) => $v !== '' && $v !== null));

tl_shell_top('Reservations', 'Reservations', ['Hotels' => 'hotel_dashboard.php'],
    '<a class="btn btn-secondary" href="?' . e($qs(['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i> Export CSV</a><a class="btn btn-primary" href="hotel_form.php"><i class="fa-solid fa-plus"></i> New reservation</a>');
?>
<div class="panel">
  <div class="tx-tabs">
    <a href="hotel_reservations.php" class="<?= $view === '' && $fs === '' ? 'on' : '' ?>">All</a>
    <a href="?view=arriving" class="<?= $view === 'arriving' ? 'on' : '' ?>">Arriving today (<?= $qa ?>)</a>
    <a href="?view=inhouse" class="<?= $view === 'inhouse' ? 'on' : '' ?>">In-house (<?= $qi ?>)</a>
    <a href="?view=departing" class="<?= $view === 'departing' ? 'on' : '' ?>">Departing today (<?= $qd ?>)</a>
    <a href="?view=unpaid" class="<?= $view === 'unpaid' ? 'on' : '' ?>">Balance due</a>
    <?php foreach ($ST as $k => $m): ?><a href="?status=<?= e($k) ?>" class="<?= $fs === $k ? 'on' : '' ?>"><?= e($m['label']) ?> (<?= $cnt[$k] ?>)</a><?php endforeach; ?>
  </div>
  <form method="get" class="tx-filter">
    <input type="hidden" name="view" value="<?= e($view) ?>"><input type="hidden" name="status" value="<?= e($fs) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search guest, mobile, reference, room no." style="flex:1;min-width:200px">
    <select name="property"><option value="">All properties</option><?php foreach ($props as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $fp === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
    <input type="date" name="from" value="<?= e((string) $from) ?>" title="Check-in from"><input type="date" name="to" value="<?= e((string) $to) ?>" title="Check-in to">
    <button class="btn btn-primary"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
  </form>
  <?php if (!$rows): ?><p style="text-align:center;color:#6b7a72;padding:34px">No reservations match.</p><?php else: ?>
  <div class="tx-scroll"><table class="tx-table"><thead><tr><th>Reference</th><th>Guest</th><th>Property / room</th><th>Stay</th><th class="r">Total</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr>
    <td><a href="hotel_view.php?id=<?= (int) $r['id'] ?>" style="font-weight:700;font-family:ui-monospace,monospace"><?= e($r['reference']) ?></a><br><small class="muted"><?= e(tl_dt($r['created_at'], 'd M, h:i A')) ?> &middot; <?= e($r['source']) ?></small></td>
    <td><strong><?= e($r['guest_name']) ?></strong><br><small><a href="tel:<?= e(htl_clean_phone($r['phone'])) ?>"><?= e($r['phone']) ?></a></small></td>
    <td><?= e($r['pname']) ?><br><small class="muted"><?= e($r['rname']) ?> &times; <?= (int) $r['rooms'] ?><?= $r['room_numbers'] ? ' &middot; #' . e($r['room_numbers']) : '' ?></small></td>
    <td><?= e(date('d M', strtotime($r['check_in']))) ?> &rarr; <?= e(date('d M Y', strtotime($r['check_out']))) ?><br><small class="muted"><?= (int) $r['nights'] ?> night<?= (int) $r['nights'] > 1 ? 's' : '' ?> &middot; <?= (int) $r['adults'] ?>A<?= (int) $r['children'] ? ' ' . (int) $r['children'] . 'C' : '' ?></small></td>
    <td class="r"><?= e(tl_inr($r['total_amount'])) ?><br><?= htl_pay_badge((string) $r['payment_status']) ?></td><td><?= htl_badge((string) $r['status']) ?></td>
    <td style="white-space:nowrap"><a class="btn btn-small btn-secondary" href="hotel_view.php?id=<?= (int) $r['id'] ?>"><i class="fa-solid fa-eye"></i></a>
      <a class="btn btn-small btn-secondary" target="_blank" rel="noopener" href="<?= e(htl_wa_link($r['phone'], 'Hello ' . $r['guest_name'] . ', regarding your booking ' . $r['reference'])) ?>"><i class="fa-brands fa-whatsapp"></i></a></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php if ($pages > 1): ?><div style="display:flex;gap:6px;margin-top:14px;align-items:center;flex-wrap:wrap"><span class="muted" style="margin-right:8px"><?= $total ?> reservations</span>
    <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?><a class="btn btn-small <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>" href="?<?= e($qs(['page' => $i])) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
  <?php endif; ?>
</div>
<?php tl_shell_bottom();
