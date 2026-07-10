<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

$bookingId = (int) ($_GET['id'] ?? 0);
if ($bookingId <= 0) {
    header('Location: transport_manage.php');
    exit;
}

$stmt = $pdo->prepare('SELECT * FROM transport_bookings WHERE id = :id');
$stmt->execute([':id' => $bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    $_SESSION['flash_error'] = 'That booking could not be found.';
    header('Location: transport_manage.php');
    exit;
}

function next_sequence_code(PDO $pdo, string $sequenceName, string $defaultPrefix): string
{
    $year = (int) date('Y');
    $stmt = $pdo->prepare('SELECT * FROM transport_sequences WHERE sequence_name = :n FOR UPDATE');
    $stmt->execute([':n' => $sequenceName]);
    $row = $stmt->fetch();

    if (!$row) {
        $ins = $pdo->prepare(
            'INSERT INTO transport_sequences
                (sequence_name, prefix, current_year, current_number, padding, separator_char, reset_every_year, is_active, created_at, updated_at)
             VALUES (:n, :p, :y, 1, 5, :sep, 1, 1, NOW(), NOW())'
        );
        $ins->execute([':n' => $sequenceName, ':p' => $defaultPrefix, ':y' => $year, ':sep' => '-']);
        $number = 1; $prefix = $defaultPrefix; $sep = '-'; $pad = 5;
    } else {
        $resetEveryYear = (bool) $row['reset_every_year'];
        $number = ($resetEveryYear && (int) $row['current_year'] !== $year) ? 1 : (int) $row['current_number'] + 1;
        $upd = $pdo->prepare('UPDATE transport_sequences SET current_number = :num, current_year = :y, updated_at = NOW() WHERE id = :id');
        $upd->execute([':num' => $number, ':y' => $year, ':id' => $row['id']]);
        $prefix = $row['prefix']; $sep = $row['separator_char'] ?? '-'; $pad = (int) ($row['padding'] ?: 5);
    }
    $yy = date('y');
    return $prefix . $sep . $yy . $sep . str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
}

function inr(?float $amount): string
{
    return '₹' . number_format((float) $amount, 2);
}

// Generate the invoice number the first time this booking's invoice is opened.
if (empty($booking['invoice_number'])) {
    $invoiceNumber = next_sequence_code($pdo, 'invoice', 'INV');
    $pdo->prepare('UPDATE transport_bookings SET invoice_number = :inv, updated_at = NOW() WHERE id = :id')
        ->execute([':inv' => $invoiceNumber, ':id' => $bookingId]);
    $booking['invoice_number'] = $invoiceNumber;
}

$paymentsStmt = $pdo->prepare('SELECT * FROM transport_payment_history WHERE booking_id = :id AND payment_type != "refund" ORDER BY payment_date ASC, id ASC');
$paymentsStmt->execute([':id' => $bookingId]);
$payments = $paymentsStmt->fetchAll();

$totalAmount    = (float) ($booking['total_amount'] ?? 0);
$discountAmount = (float) ($booking['discount_amount'] ?? 0);
$otherCharges   = (float) ($booking['other_charges'] ?? 0);
$gstPercentage  = (float) ($booking['gst_percentage'] ?? 0);
$gstAmount      = (float) ($booking['gst_amount'] ?? 0);
$netAmount      = (float) ($booking['net_amount'] ?? $totalAmount);
$paidAmount     = (float) ($booking['paid_amount'] ?? 0);
$balanceAmount  = (float) ($booking['balance_amount'] ?? ($netAmount - $paidAmount));

$PAYMENT_STATUS_LIST = [
    'unpaid'   => 'Unpaid',
    'partial'  => 'Partially Paid',
    'paid'     => 'Paid',
    'refunded' => 'Refunded',
];

$pageTitle = 'Invoice — ' . $booking['invoice_number'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= e($pageTitle) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  * { box-sizing: border-box; }
  body { font-family: 'Segoe UI', Arial, sans-serif; background:#eef0ee; margin:0; padding:24px; color:#23261f; }
  .toolbar { max-width: 860px; margin: 0 auto 16px; display:flex; justify-content:flex-end; gap:10px; }
  .btn { display:inline-flex; align-items:center; gap:8px; padding:9px 16px; border-radius:8px; font-size:.85rem; font-weight:600; cursor:pointer; border:1px solid #d7dad5; background:#fff; color:#23261f; text-decoration:none; }
  .btn-primary { background:#1b7a34; border-color:#1b7a34; color:#fff; }
  .sheet { max-width: 860px; margin: 0 auto; background:#fff; border-radius:12px; box-shadow:0 2px 16px rgba(0,0,0,.06); padding:44px 48px; }
  .inv-head { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #23261f; padding-bottom:20px; margin-bottom:24px; }
  .inv-head h1 { margin:0 0 4px; font-size:1.5rem; }
  .inv-head .brand-sub { color:#666; font-size:.82rem; }
  .inv-head .meta { text-align:right; font-size:.85rem; }
  .inv-head .meta .num { font-size:1.15rem; font-weight:700; color:#1b7a34; }
  .badge { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.72rem; font-weight:700; text-transform:uppercase; }
  .badge-success { background:#e6f4ea; color:#1b7a34; }
  .badge-warning { background:#fff4e0; color:#a6650f; }
  .badge-danger  { background:#fdecea; color:#a52a20; }
  .two-col { display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:26px; }
  .box h3 { margin:0 0 8px; font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; color:#888; }
  .box p { margin:2px 0; font-size:.88rem; line-height:1.5; }
  table { width:100%; border-collapse:collapse; margin-bottom:20px; }
  th, td { text-align:left; padding:9px 8px; font-size:.85rem; border-bottom:1px solid #eee; }
  th { background:#f4f6f2; font-size:.72rem; text-transform:uppercase; color:#666; }
  .num-col { text-align:right; }
  .totals { width:320px; margin-left:auto; font-size:.88rem; }
  .totals div { display:flex; justify-content:space-between; padding:6px 0; }
  .totals .grand { border-top:2px solid #23261f; margin-top:6px; padding-top:10px; font-weight:700; font-size:1.05rem; }
  .totals .due { color:#c0362c; font-weight:700; }
  .footer-note { margin-top:30px; font-size:.78rem; color:#888; border-top:1px solid #eee; padding-top:14px; }
  @media print {
    body { background:#fff; padding:0; }
    .toolbar { display:none; }
    .sheet { box-shadow:none; padding:0; max-width:100%; }
  }
</style>
</head>
<body>

  <div class="toolbar">
    <a href="payment.php?id=<?= $bookingId ?>" class="btn"><i class="fa-solid fa-arrow-left"></i> Back to Payments</a>
    <a href="#" onclick="window.print(); return false;" class="btn btn-primary"><i class="fa-solid fa-print"></i> Print / Save PDF</a>
  </div>

  <div class="sheet">

    <div class="inv-head">
      <div>
        <h1>Biome Transport</h1>
        <div class="brand-sub">Logistics &amp; Transport Services</div>
        <div class="brand-sub">Tracking ID: <?= e($booking['tracking_id']) ?></div>
      </div>
      <div class="meta">
        <div class="num">Invoice #<?= e($booking['invoice_number']) ?></div>
        <p>Date: <?= e(date('d M Y', strtotime((string) $booking['created_at']))) ?></p>
        <span class="badge badge-<?= $booking['payment_status'] === 'paid' ? 'success' : ($booking['payment_status'] === 'partial' ? 'warning' : 'danger') ?>">
          <?= e($PAYMENT_STATUS_LIST[$booking['payment_status']] ?? $booking['payment_status']) ?>
        </span>
      </div>
    </div>

    <div class="two-col">
      <div class="box">
        <h3>Billed To</h3>
        <p><strong><?= e($booking['customer_name']) ?></strong></p>
        <?php if (!empty($booking['company_name'])): ?><p><?= e($booking['company_name']) ?></p><?php endif; ?>
        <p><?= e($booking['phone']) ?><?= !empty($booking['alternate_phone']) ? ' / ' . e($booking['alternate_phone']) : '' ?></p>
        <?php if (!empty($booking['email'])): ?><p><?= e($booking['email']) ?></p><?php endif; ?>
        <?php if (!empty($booking['customer_code'])): ?><p>Customer Code: <?= e($booking['customer_code']) ?></p><?php endif; ?>
      </div>
      <div class="box">
        <h3>Shipment</h3>
        <p><strong>From:</strong> <?= e($booking['pickup_address']) ?><?= !empty($booking['pickup_city']) ? ', ' . e($booking['pickup_city']) : '' ?></p>
        <p><strong>To:</strong> <?= e($booking['delivery_address']) ?><?= !empty($booking['delivery_city']) ? ', ' . e($booking['delivery_city']) : '' ?></p>
        <p><strong>Vehicle:</strong> <?= e($booking['vehicle_type_requested']) ?><?= !empty($booking['registration_number']) ? ' (' . e($booking['registration_number']) . ')' : '' ?></p>
        <p><strong>Pickup date:</strong> <?= e($booking['pickup_date']) ?></p>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Description</th>
          <th>Cargo Type</th>
          <th class="num-col">Weight</th>
          <th class="num-col">Packages</th>
          <th class="num-col">Amount</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td><?= e($booking['cargo_description'] ?: 'Transport service — ' . $booking['tracking_id']) ?></td>
          <td><?= e($booking['cargo_type'] ?? '—') ?></td>
          <td class="num-col"><?= e((string) ($booking['cargo_weight'] ?? '—')) ?> <?= e($booking['cargo_weight_unit'] ?? '') ?></td>
          <td class="num-col"><?= e((string) ($booking['package_count'] ?? '—')) ?></td>
          <td class="num-col"><?= e(inr($totalAmount)) ?></td>
        </tr>
        <?php if (!empty($booking['other_charges'])): ?>
        <tr>
          <td colspan="4">Other charges</td>
          <td class="num-col"><?= e(inr($otherCharges)) ?></td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="totals">
      <div><span>Subtotal</span><span><?= e(inr($totalAmount + $otherCharges)) ?></span></div>
      <?php if ($discountAmount > 0): ?>
        <div><span>Discount</span><span>−<?= e(inr($discountAmount)) ?></span></div>
      <?php endif; ?>
      <?php if ($gstAmount > 0 || $gstPercentage > 0): ?>
        <div><span>GST (<?= e(rtrim(rtrim(number_format($gstPercentage, 2), '0'), '.')) ?>%)</span><span><?= e(inr($gstAmount)) ?></span></div>
      <?php endif; ?>
      <div class="grand"><span>Net Amount</span><span><?= e(inr($netAmount)) ?></span></div>
      <div><span>Paid</span><span><?= e(inr($paidAmount)) ?></span></div>
      <div class="due"><span>Balance Due</span><span><?= e(inr($balanceAmount)) ?></span></div>
    </div>

    <?php if ($payments): ?>
    <h3 style="font-size:.8rem;text-transform:uppercase;color:#888;margin:24px 0 8px;">Payments Received</h3>
    <table>
      <thead>
        <tr><th>Receipt #</th><th>Date</th><th>Mode</th><th class="num-col">Amount</th></tr>
      </thead>
      <tbody>
        <?php foreach ($payments as $p): ?>
        <tr>
          <td><?= e($p['receipt_number']) ?></td>
          <td><?= e((string) $p['payment_date']) ?></td>
          <td><?= e(ucfirst(str_replace('_', ' ', (string) $p['payment_mode']))) ?></td>
          <td class="num-col"><?= e(inr((float) $p['amount'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

    <div class="footer-note">
      This is a system-generated invoice for booking <?= e($booking['tracking_id']) ?>. For queries, contact support with your invoice number.
    </div>
  </div>

</body>
</html>