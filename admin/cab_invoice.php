<?php
/**
 * admin/cab_invoice.php - printable tax invoice (?id=) and driver trip sheet (?id=&doc=tripsheet).
 * Use the browser's Print > Save as PDF.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/cab_lib.php';
require_admin();

$pdo = get_db();
$id = (int) ($_GET['id'] ?? 0);
$doc = ($_GET['doc'] ?? 'invoice') === 'tripsheet' ? 'tripsheet' : 'invoice';
$s = $pdo->prepare('SELECT b.*, v.name AS vehicle_name, v.reg_number, v.seats, d.full_name AS driver_name, d.phone AS driver_phone
                    FROM cab_booking_requests b LEFT JOIN cab_vehicles v ON v.id = b.vehicle_id LEFT JOIN cab_drivers d ON d.id = b.driver_id
                    WHERE b.id = :id AND b.deleted_at IS NULL');
$s->execute([':id' => $id]);
$b = $s->fetch();
if (!$b || ($doc === 'invoice' && !$b['invoice_number'])) {
    http_response_code(404);
    exit('Document not available. Issue the invoice from the booking page first.');
}
$cfg = tl_settings();
$co = $cfg['company'];
$bank = $cfg['bank'];
$S = cab_settings($pdo);
$t = cab_totals($b);
$pct = (float) $b['gst_pct'];
$split = ($cfg['invoice']['gst_split'] ?? 'cgst_sgst') === 'igst' ? 'igst' : 'cgst_sgst';
$terms = array_filter(array_map('trim', preg_split('/\R/', $S['terms']) ?: []));
$fmtPct = rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.');
header('X-Robots-Tag: noindex');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self'");
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">
<title><?= $doc === 'invoice' ? e($b['invoice_number']) : 'Trip sheet ' . e($b['reference']) ?></title>
<style>
*{box-sizing:border-box}body{font:14px/1.5 Arial,Helvetica,sans-serif;color:#1d2b24;margin:0;background:#eef2ef}
.sheet{max-width:820px;margin:20px auto;background:#fff;padding:36px 40px;border-radius:6px}
.top{display:flex;justify-content:space-between;gap:20px;border-bottom:3px solid #198754;padding-bottom:16px}
h1{margin:0;font-size:22px;color:#0f4c2d}h2{margin:0 0 6px;font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#6b7a72}
.muted{color:#6b7a72;font-size:12px}.right{text-align:right}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin:20px 0}
table{width:100%;border-collapse:collapse;margin:14px 0}th{background:#f1f6f3;text-align:left;font-size:12px;text-transform:uppercase;padding:9px 10px}
td{padding:9px 10px;border-bottom:1px solid #e6eee9}td.r,th.r{text-align:right}
.tot td{border:0;padding:4px 10px}.tot .grand td{font-size:16px;font-weight:700;border-top:2px solid #0f4c2d;padding-top:10px}
.box{border:1px solid #d9e5de;border-radius:6px;padding:12px 14px}ul{margin:6px 0 0;padding-left:18px;font-size:12px;color:#4b5d54}
.sign{display:flex;justify-content:space-between;margin-top:60px;font-size:12px}.sign div{border-top:1px solid #1d2b24;padding-top:6px;width:200px;text-align:center}
.bar{max-width:820px;margin:14px auto 0;text-align:right}.bar button{background:#198754;color:#fff;border:0;border-radius:6px;padding:9px 18px;font-size:14px;cursor:pointer}
@media print{body{background:#fff}.sheet{margin:0;max-width:none;padding:0}.bar{display:none}}
</style></head><body>
<div class="bar"><button onclick="window.print()">Print / Save as PDF</button></div>
<div class="sheet">
  <div class="top">
    <div><h1><?= e($co['name']) ?></h1><div class="muted"><?= e($co['tagline']) ?><br><?= nl2br(e($co['address'])) ?><br><?= e($co['phone']) ?> &middot; <?= e($co['email']) ?><br>GSTIN: <?= e($co['gstin']) ?> &middot; PAN: <?= e($co['pan']) ?></div></div>
    <div class="right"><h1><?= $doc === 'invoice' ? 'TAX INVOICE' : 'TRIP SHEET' ?></h1>
      <div class="muted"><?php if ($doc === 'invoice'): ?>Invoice no: <strong><?= e($b['invoice_number']) ?></strong><br>Date: <?= e(date('d M Y', strtotime((string) $b['invoice_date']))) ?><br><?php endif; ?>Booking: <strong><?= e($b['reference']) ?></strong><br>SAC: <?= e($S['sac_code']) ?></div></div>
  </div>

  <div class="grid">
    <div><h2>Billed to</h2><strong><?= e($b['full_name']) ?></strong><br><?= e($b['mobile_number']) ?><?= $b['email'] ? '<br>' . e($b['email']) : '' ?></div>
    <div><h2>Trip</h2><?= e($b['service_type'] ?: '-') ?> &middot; <?= e($b['vehicle_type'] ?: '-') ?><br>
      <?= e($b['pickup_location'] ?: '-') ?><?= $b['destination'] ? ' &rarr; ' . e($b['destination']) : '' ?><br>
      <?= $b['journey_date'] ? e(date('d M Y', strtotime((string) $b['journey_date']))) : '-' ?><?= $b['pickup_time'] ? ', ' . e(substr((string) $b['pickup_time'], 0, 5)) : '' ?><?= (int) $b['days'] > 1 ? ' (' . (int) $b['days'] . ' days)' : '' ?></div>
  </div>

<?php if ($doc === 'invoice'): ?>
  <table><thead><tr><th>Description</th><th class="r">Amount</th></tr></thead><tbody>
    <tr><td><?= e($b['service_type'] ?: 'Cab service') ?> - <?= e($b['vehicle_type'] ?: '') ?><?= $b['vehicle_name'] ? ' (' . e($b['vehicle_name'] . ($b['reg_number'] ? ', ' . $b['reg_number'] : '')) . ')' : '' ?></td><td class="r"><?= e(tl_inr($b['base_fare'])) ?></td></tr>
    <?php if ((float) $b['extra_charges'] > 0): ?><tr><td>Tolls, parking &amp; other charges</td><td class="r"><?= e(tl_inr($b['extra_charges'])) ?></td></tr><?php endif; ?>
    <?php if ((float) $b['discount'] > 0): ?><tr><td>Discount</td><td class="r">-<?= e(tl_inr($b['discount'])) ?></td></tr><?php endif; ?>
  </tbody></table>
  <table class="tot" style="width:320px;margin-left:auto">
    <tr><td>Taxable value</td><td class="r"><?= e(tl_inr($t['taxable'])) ?></td></tr>
    <?php if ($split === 'cgst_sgst'): ?>
      <tr><td>CGST @ <?= e(rtrim(rtrim(number_format($pct / 2, 2, '.', ''), '0'), '.')) ?>%</td><td class="r"><?= e(tl_inr($t['gst'] / 2)) ?></td></tr>
      <tr><td>SGST @ <?= e(rtrim(rtrim(number_format($pct / 2, 2, '.', ''), '0'), '.')) ?>%</td><td class="r"><?= e(tl_inr($t['gst'] - round($t['gst'] / 2, 2))) ?></td></tr>
    <?php else: ?><tr><td>IGST @ <?= e($fmtPct) ?>%</td><td class="r"><?= e(tl_inr($t['gst'])) ?></td></tr><?php endif; ?>
    <tr class="grand"><td>Total</td><td class="r"><?= e(tl_inr($t['total'])) ?></td></tr>
    <tr><td>Received</td><td class="r"><?= e(tl_inr($t['paid'])) ?></td></tr>
    <tr><td><strong>Balance due</strong></td><td class="r"><strong><?= e(tl_inr($t['balance'])) ?></strong></td></tr>
  </table>
  <p class="muted"><?= e(tl_amount_in_words($t['total'])) ?></p>
  <?php if ($bank['account_no'] || $bank['upi_id']): ?><div class="box"><h2>Pay to</h2><?= e($bank['account_name']) ?> &middot; <?= e($bank['bank_name']) ?> &middot; A/c <?= e($bank['account_no']) ?> &middot; IFSC <?= e($bank['ifsc']) ?><?= $bank['upi_id'] ? ' &middot; UPI ' . e($bank['upi_id']) : '' ?></div><?php endif; ?>
<?php else: ?>
  <div class="grid">
    <div class="box"><h2>Vehicle</h2><?= $b['vehicle_name'] ? '<strong>' . e($b['vehicle_name']) . '</strong><br>' . e($b['reg_number'] ?: '-') : 'Not assigned' ?></div>
    <div class="box"><h2>Driver</h2><?= $b['driver_name'] ? '<strong>' . e($b['driver_name']) . '</strong><br>' . e($b['driver_phone']) : 'Not assigned' ?></div>
  </div>
  <table><tbody>
    <tr><td>Passengers</td><td><?= e($b['passengers'] ?: '-') ?></td><td>Est. distance</td><td><?= $b['distance_km'] !== null ? e((string) $b['distance_km']) . ' km' : '-' ?></td></tr>
    <tr><td>Starting odometer</td><td><?= $b['odometer_start'] !== null ? e((string) $b['odometer_start']) : '________' ?></td><td>Closing odometer</td><td><?= $b['odometer_end'] !== null ? e((string) $b['odometer_end']) : '________' ?></td></tr>
    <tr><td>Fare</td><td><?= (float) $b['total_fare'] > 0 ? e(tl_inr($t['total'])) : '-' ?></td><td>Balance to collect</td><td><strong><?= e(tl_inr($t['balance'])) ?></strong></td></tr>
    <tr><td>Tolls / parking</td><td>________</td><td>Customer signature</td><td>________</td></tr>
  </tbody></table>
  <?php if ($b['message']): ?><div class="box"><h2>Customer requirements</h2><?= nl2br(e($b['message'])) ?></div><?php endif; ?>
<?php endif; ?>

  <?php if ($terms): ?><div style="margin-top:18px"><h2>Terms</h2><ul><?php foreach ($terms as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <div class="sign"><div>Customer</div><div>For <?= e($co['name']) ?></div></div>
</div></body></html>
