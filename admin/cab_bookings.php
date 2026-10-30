<?php
/**
 * admin/cab_bookings.php - all cab bookings: status tabs, search, date range,
 * payment filter, pagination, CSV export, soft delete.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/cab_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$ST  = cab_statuses();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $id = (int) ($_POST['id'] ?? 0);
    if (($_POST['action'] ?? '') === 'delete' && $id > 0) {
        try {
            $pdo->prepare('UPDATE cab_booking_requests SET deleted_at = NOW() WHERE id = :id AND deleted_at IS NULL')->execute([':id' => $id]);
            log_activity((int) $_SESSION['admin_id'], 'cab_booking_deleted', "id={$id}");
            $_SESSION['flash_success_page'] = 'Booking moved to trash.';
        } catch (Throwable $e) {
            error_log('cab delete: ' . $e->getMessage());
            $_SESSION['flash_error_page'] = 'Could not delete the booking.';
        }
    }
    header('Location: cab_bookings.php?' . http_build_query(array_intersect_key($_GET, array_flip(['status', 'q', 'pay', 'from', 'to', 'page']))));
    exit;
}

$fs   = (string) ($_GET['status'] ?? '');
$fs   = isset($ST[$fs]) ? $fs : '';
$pay  = (string) ($_GET['pay'] ?? '');
$pay  = in_array($pay, ['unpaid', 'partial', 'paid'], true) ? $pay : '';
$q    = mb_substr(clean_input((string) ($_GET['q'] ?? '')), 0, 100);
$from = cab_valid_date((string) ($_GET['from'] ?? ''));
$to   = cab_valid_date((string) ($_GET['to'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$per  = 20;

$where = ['b.deleted_at IS NULL'];
$par = [];
if ($fs !== '') {
    $where[] = 'b.status = :st';
    $par[':st'] = $fs;
}
if ($pay !== '') {
    $where[] = 'b.payment_status = :ps';
    $par[':ps'] = $pay;
}
if ($q !== '') {
    $cols = ['b.full_name', 'b.mobile_number', 'b.email', 'b.reference', 'b.pickup_location', 'b.destination'];
    $likes = [];
    foreach ($cols as $i => $c) {
        $likes[] = "$c LIKE :q$i";
        $par[":q$i"] = '%' . $q . '%';
    }
    $where[] = '(' . implode(' OR ', $likes) . ')';
}
if ($from) {
    $where[] = 'b.journey_date >= :f';
    $par[':f'] = $from;
}
if ($to) {
    $where[] = 'b.journey_date <= :t';
    $par[':t'] = $to;
}
$wsql = implode(' AND ', $where);

/* ---- CSV export (same filters) ---- */
if (($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare("SELECT b.reference, b.full_name, b.mobile_number, b.email, b.service_type, b.vehicle_type, b.pickup_location, b.destination,
                                b.journey_date, b.end_date, b.days, b.passengers, b.status, b.total_fare, b.paid_amount, b.balance_amount, b.payment_status,
                                v.name AS vehicle, d.full_name AS driver, b.created_at
                         FROM cab_booking_requests b LEFT JOIN cab_vehicles v ON v.id = b.vehicle_id LEFT JOIN cab_drivers d ON d.id = b.driver_id
                         WHERE $wsql ORDER BY b.id DESC");
    $st->execute($par);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cab-bookings-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $first = true;
    $safe = static fn($v) => (is_string($v) && $v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v; // stop CSV/Excel formula injection
    while ($r = $st->fetch()) {
        if ($first) {
            fputcsv($out, array_keys($r));
            $first = false;
        }
        fputcsv($out, array_map($safe, $r));
    }
    fclose($out);
    exit;
}

$cnt = $pdo->prepare("SELECT COUNT(*) FROM cab_booking_requests b WHERE $wsql");
$cnt->execute($par);
$total = (int) $cnt->fetchColumn();
$pages = max(1, (int) ceil($total / $per));
$page  = min($page, $pages);
$off   = ($page - 1) * $per;

$st = $pdo->prepare(
    "SELECT b.*, v.name AS vehicle_name, v.reg_number, d.full_name AS driver_name
     FROM cab_booking_requests b
     LEFT JOIN cab_vehicles v ON v.id = b.vehicle_id LEFT JOIN cab_drivers d ON d.id = b.driver_id
     WHERE $wsql
     ORDER BY b.id DESC LIMIT $per OFFSET $off"
);
$st->execute($par);
$rows = $st->fetchAll();

$counts = array_fill_keys(array_keys($ST), 0);
foreach ($pdo->query('SELECT status, COUNT(*) c FROM cab_booking_requests WHERE deleted_at IS NULL GROUP BY status') as $r) {
    $counts[$r['status']] = (int) $r['c'];
}
$all = array_sum($counts);
$kOut = (float) $pdo->query("SELECT COALESCE(SUM(balance_amount),0) FROM cab_booking_requests WHERE deleted_at IS NULL AND status NOT IN ('cancelled','new','contacted','closed') AND total_fare > 0")->fetchColumn();
$kMonth = (float) $pdo->query("SELECT COALESCE(SUM(CASE WHEN entry_type='refund' THEN -amount ELSE amount END),0) FROM cab_payments WHERE payment_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetchColumn();

$qs = static fn(array $over = []) => http_build_query(array_filter(array_merge(['status' => $fs, 'q' => $q, 'pay' => $pay, 'from' => $from, 'to' => $to], $over), static fn($v) => $v !== '' && $v !== null));

tl_shell_top('Cab bookings', 'Cab bookings', ['Cab Services' => 'cab_dashboard.php'],
    '<a class="btn btn-secondary" href="cab_bookings.php?' . e($qs(['export' => 'csv'])) . '"><i class="fa-solid fa-file-csv"></i> Export CSV</a>
     <a class="btn btn-primary" href="cab_form.php"><i class="fa-solid fa-plus"></i> New booking</a>');
?>
<div class="tx-kpis">
  <div class="tx-kpi"><div class="l">All bookings</div><div class="v"><?= $all ?></div></div>
  <div class="tx-kpi warn"><div class="l">Awaiting action</div><div class="v"><?= $counts['new'] ?></div></div>
  <div class="tx-kpi"><div class="l">On trip</div><div class="v"><?= $counts['on_trip'] ?></div></div>
  <div class="tx-kpi warn"><div class="l">Outstanding balance</div><div class="v"><?= e(tl_inr($kOut)) ?></div></div>
  <div class="tx-kpi good"><div class="l">Received this month</div><div class="v"><?= e(tl_inr($kMonth)) ?></div></div>
</div>

<div class="panel">
  <div class="tx-tabs">
    <a href="cab_bookings.php" class="<?= $fs === '' ? 'on' : '' ?>">All (<?= $all ?>)</a>
    <?php foreach ($ST as $k => $m): if ($k === 'closed' && $counts[$k] === 0) { continue; } ?>
      <a href="cab_bookings.php?<?= e(http_build_query(['status' => $k])) ?>" class="<?= $fs === $k ? 'on' : '' ?>"><?= e($m['label']) ?> (<?= $counts[$k] ?>)</a>
    <?php endforeach; ?>
  </div>
  <form method="get" class="tx-filter">
    <input type="hidden" name="status" value="<?= e($fs) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search name, mobile, reference, place..." style="flex:1;min-width:220px">
    <select name="pay"><option value="">Any payment</option>
      <?php foreach (['unpaid' => 'Unpaid', 'partial' => 'Part paid', 'paid' => 'Paid'] as $k => $l): ?><option value="<?= $k ?>" <?= $pay === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select>
    <input type="date" name="from" value="<?= e($from) ?>" title="Journey from">
    <input type="date" name="to" value="<?= e($to) ?>" title="Journey to">
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Filter</button>
    <?php if ($q !== '' || $pay !== '' || $from || $to): ?><a class="btn btn-ghost" href="cab_bookings.php?<?= e(http_build_query(['status' => $fs])) ?>">Reset</a><?php endif; ?>
  </form>

  <?php if (!$rows): ?>
    <div class="empty-state" style="padding:40px;text-align:center;color:#6b7a72"><i class="fa-solid fa-taxi" style="font-size:2rem"></i><p>No bookings match these filters.</p></div>
  <?php else: ?>
  <div class="tx-scroll"><table class="tx-table">
    <thead><tr><th>Reference</th><th>Customer</th><th>Service</th><th>Trip</th><th>Journey</th><th>Vehicle / driver</th><th class="r">Fare</th><th>Status</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="cab_view.php?id=<?= (int) $r['id'] ?>" style="font-weight:700;font-family:ui-monospace,monospace"><?= e($r['reference']) ?></a><br><small style="color:#6b7a72"><?= e(tl_dt($r['created_at'], 'd M, h:i A')) ?></small></td>
        <td><strong><?= e($r['full_name']) ?></strong><br><small><a href="tel:<?= e(cab_clean_phone($r['mobile_number'])) ?>"><?= e($r['mobile_number']) ?></a></small></td>
        <td><?= e($r['service_type'] ?: '-') ?><br><small style="color:#6b7a72"><?= e($r['vehicle_type'] ?: '-') ?></small></td>
        <td><?= e($r['pickup_location'] ?: '-') ?><?= $r['destination'] ? ' &rarr; ' . e($r['destination']) : '' ?></td>
        <td><?= $r['journey_date'] ? e(date('d M Y', strtotime($r['journey_date']))) : '-' ?><?= (int) $r['days'] > 1 ? '<br><small style="color:#6b7a72">' . (int) $r['days'] . ' days</small>' : '' ?></td>
        <td><?php if ($r['vehicle_name']): ?><?= e($r['vehicle_name']) ?><br><small style="color:#6b7a72"><?= e($r['driver_name'] ?: 'No driver') ?></small><?php else: ?><span style="color:#9aa8a0">Not assigned</span><?php endif; ?></td>
        <td class="r"><?= (float) $r['total_fare'] > 0 ? e(tl_inr($r['total_fare'])) . '<br>' . cab_pay_badge((string) $r['payment_status']) : '<span style="color:#9aa8a0">-</span>' ?></td>
        <td><?= cab_status_badge((string) $r['status']) ?></td>
        <td style="white-space:nowrap">
          <a class="btn btn-small btn-secondary" href="cab_view.php?id=<?= (int) $r['id'] ?>" title="Open"><i class="fa-solid fa-eye"></i></a>
          <a class="btn btn-small btn-secondary" target="_blank" rel="noopener" href="<?= e(cab_wa_link($r['mobile_number'], 'Hello ' . $r['full_name'] . ', regarding your cab booking ' . $r['reference'])) ?>" title="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
          <form method="post" style="display:inline" onsubmit="return confirm('Move this booking to trash?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-small btn-danger" title="Delete"><i class="fa-solid fa-trash"></i></button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
  <div class="pagination" style="margin-top:16px;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
    <span style="color:#6b7a72;font-size:.85rem;margin-right:8px"><?= $total ?> bookings</span>
    <?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?>
      <a class="btn btn-small <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>" href="cab_bookings.php?<?= e($qs(['page' => $i])) ?>"><?= $i ?></a>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php tl_shell_bottom();
