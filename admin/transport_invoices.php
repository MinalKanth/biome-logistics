<?php
/**
 * admin/transport_invoices.php - Invoices & Payments hub
 * One list of every billable booking with totals, filters and one-click Invoice / Payment buttons.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/transport_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();

$q      = mb_substr(clean_input((string) ($_GET['q'] ?? '')), 0, 100);
$filter = (string) ($_GET['f'] ?? 'all');
if (!in_array($filter, ['all', 'unpaid', 'partial', 'paid', 'not_invoiced'], true)) {
    $filter = 'all';
}
$page    = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 20;

$where  = ['deleted_at IS NULL', 'grand_total > 0'];
$params = [];
if ($q !== '') {
    $where[] = '(tracking_id LIKE :q OR invoice_number LIKE :q OR customer_name LIKE :q OR company_name LIKE :q OR phone LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if (in_array($filter, ['unpaid', 'partial', 'paid'], true)) {
    $where[] = 'payment_status = :ps';
    $params[':ps'] = $filter;
} elseif ($filter === 'not_invoiced') {
    $where[] = "(invoice_number IS NULL OR invoice_number = '')";
}
$whereSql = implode(' AND ', $where);

function inv_scalar(PDO $pdo, string $sql, array $p = []): float
{
    try {
        $s = $pdo->prepare($sql);
        $s->execute($p);
        return (float) $s->fetchColumn();
    } catch (Throwable $e) {
        error_log('invoices scalar: ' . $e->getMessage());
        return 0.0;
    }
}
$base = 'FROM transport_bookings WHERE deleted_at IS NULL AND grand_total > 0';
$kBilled   = inv_scalar($pdo, "SELECT COALESCE(SUM(grand_total),0) $base AND status <> 'cancelled'");
$kCollect  = inv_scalar($pdo, "SELECT COALESCE(SUM(paid_amount),0) $base");
$kOutstand = inv_scalar($pdo, "SELECT COALESCE(SUM(GREATEST(balance_amount,0)),0) $base AND status <> 'cancelled'");
$kMonth    = inv_scalar($pdo, "SELECT COALESCE(SUM(amount),0) FROM transport_payment_history WHERE payment_type <> 'refund' AND payment_date >= DATE_FORMAT(NOW(), '%Y-%m-01')");

$total = (int) inv_scalar($pdo, "SELECT COUNT(*) FROM transport_bookings WHERE $whereSql", $params);
$pages = max(1, (int) ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$rows = [];
try {
    $st = $pdo->prepare(
        "SELECT id, tracking_id, invoice_number, customer_name, company_name, pickup_city, drop_city, status,
                grand_total, paid_amount, balance_amount, payment_status, created_at
         FROM transport_bookings WHERE $whereSql ORDER BY created_at DESC, id DESC LIMIT :l OFFSET :o"
    );
    foreach ($params as $k => $v) {
        $st->bindValue($k, $v, PDO::PARAM_STR);
    }
    $st->bindValue(':l', $perPage, PDO::PARAM_INT);
    $st->bindValue(':o', $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    error_log('invoices list: ' . $e->getMessage());
}

$recent = [];
try {
    $recent = $pdo->query(
        'SELECT ph.*, tb.customer_name FROM transport_payment_history ph
         LEFT JOIN transport_bookings tb ON tb.id = ph.booking_id ORDER BY ph.payment_date DESC, ph.id DESC LIMIT 8'
    )->fetchAll();
} catch (Throwable $e) {
    error_log('recent payments: ' . $e->getMessage());
}

$qs = static fn(array $extra): string => http_build_query(array_merge(['q' => $q, 'f' => $filter], $extra));

tl_shell_top('Invoices & Payments', 'Invoices & Payments', ['Transport' => 'transport_manage.php']);
?>
<div class="tx-kpis">
  <div class="tx-kpi"><div class="l">Total billed</div><div class="v"><?= e(tl_inr($kBilled)) ?></div></div>
  <div class="tx-kpi good"><div class="l">Collected</div><div class="v"><?= e(tl_inr($kCollect)) ?></div></div>
  <div class="tx-kpi warn"><div class="l">Outstanding</div><div class="v"><?= e(tl_inr($kOutstand)) ?></div></div>
  <div class="tx-kpi"><div class="l">Received this month</div><div class="v"><?= e(tl_inr($kMonth)) ?></div></div>
</div>

<div class="panel">
  <div class="tx-tabs">
    <?php foreach (['all' => 'All', 'unpaid' => 'Unpaid', 'partial' => 'Part-paid', 'paid' => 'Paid', 'not_invoiced' => 'Not invoiced yet'] as $k => $l): ?>
      <a href="?<?= e($qs(['f' => $k, 'page' => 1])) ?>" class="<?= $filter === $k ? 'on' : '' ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
  </div>
  <form method="get" class="tx-filter">
    <input type="hidden" name="f" value="<?= e($filter) ?>">
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search tracking ID, invoice no., customer, phone" style="flex:1;min-width:240px">
    <button class="btn btn-primary" type="submit"><i class="fa-solid fa-magnifying-glass"></i> Search</button>
  </form>

  <div class="tx-scroll">
  <table class="tx-table">
    <thead><tr><th>Tracking</th><th>Invoice</th><th>Customer</th><th>Route</th><th class="r">Total</th><th class="r">Paid</th><th class="r">Balance</th><th>Payment</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="9" style="text-align:center;padding:34px;color:#6b7a72">No bookings with an amount yet. Enter the freight amount when you edit a booking and it will appear here.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="transport_view.php?id=<?= (int) $r['id'] ?>"><strong><?= e($r['tracking_id']) ?></strong></a></td>
        <td><?= $r['invoice_number'] ? e($r['invoice_number']) : '<span style="color:#9aa8a0">—</span>' ?></td>
        <td><?= e($r['company_name'] ?: $r['customer_name']) ?></td>
        <td><?= e($r['pickup_city']) ?> → <?= e($r['drop_city']) ?></td>
        <td class="r"><?= e(tl_inr($r['grand_total'])) ?></td>
        <td class="r"><?= e(tl_inr($r['paid_amount'])) ?></td>
        <td class="r" style="font-weight:700;<?= (float) $r['balance_amount'] > 0.009 ? 'color:#b02a20' : '' ?>"><?= e(tl_inr(max(0, (float) $r['balance_amount']))) ?></td>
        <td><?= tl_payment_badge((string) $r['payment_status']) ?></td>
        <td style="white-space:nowrap">
          <a class="btn btn-small btn-secondary" href="invoice.php?id=<?= (int) $r['id'] ?>"><i class="fa-solid fa-file-invoice"></i> <?= $r['invoice_number'] ? 'Invoice' : 'Generate' ?></a>
          <a class="btn btn-small btn-ghost" href="payment.php?id=<?= (int) $r['id'] ?>"><i class="fa-solid fa-wallet"></i></a>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>

  <?php if ($pages > 1): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;font-size:.86rem">
      <span class="muted">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> bookings</span>
      <span>
        <?php if ($page > 1): ?><a class="btn btn-small btn-ghost" href="?<?= e($qs(['page' => $page - 1])) ?>">← Prev</a><?php endif; ?>
        <?php if ($page < $pages): ?><a class="btn btn-small btn-ghost" href="?<?= e($qs(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
      </span>
    </div>
  <?php endif; ?>
</div>

<div class="panel">
  <div class="panel-head"><h3><i class="fa-solid fa-clock-rotate-left"></i> Latest payments</h3></div>
  <?php if (!$recent): ?><p class="muted" style="font-size:.88rem">No payments recorded yet.</p><?php else: ?>
  <div class="tx-scroll"><table class="tx-table">
    <thead><tr><th>Date</th><th>Receipt</th><th>Customer</th><th>Tracking</th><th>Mode</th><th class="r">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($recent as $p): ?>
      <tr>
        <td><?= e(tl_dt($p['payment_date'], 'd M Y')) ?></td>
        <td><?= e($p['receipt_number'] ?: '—') ?></td>
        <td><?= e($p['customer_name'] ?: '—') ?></td>
        <td><a href="payment.php?id=<?= (int) $p['booking_id'] ?>"><?= e($p['tracking_id']) ?></a></td>
        <td><?= e(ucwords(str_replace('_', ' ', (string) $p['payment_mode']))) ?></td>
        <td class="r" style="font-weight:700"><?= ($p['payment_type'] ?? '') === 'refund' ? '-' : '' ?><?= e(tl_inr($p['amount'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php tl_shell_bottom(); ?>
