<?php
/**
 * admin/hotel_invoice.php?id= -> tax invoice ; &doc=card -> guest registration card. Print / Save as PDF from the browser.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hotel_lib.php';
require_admin();

$pdo = get_db();
$id = (int) ($_GET['id'] ?? 0);
$doc = ($_GET['doc'] ?? 'invoice') === 'card' ? 'card' : 'invoice';
$s = $pdo->prepare('SELECT r.*, p.name pname, p.address paddr, p.city pcity, p.state pstate, p.pincode ppin, p.phone pphone, p.check_in_time cit, p.check_out_time cot, p.policies, t.name rname
    FROM hotel_reservations r JOIN hotel_properties p ON p.id = r.property_id JOIN hotel_room_types t ON t.id = r.room_type_id WHERE r.id = :id AND r.deleted_at IS NULL');
$s->execute([':id' => $id]);
$r = $s->fetch();
if (!$r || ($doc === 'invoice' && !$r['invoice_number'])) {
    http_response_code(404);
    exit('Document not available. Issue the invoice from the reservation page first.');
}
$cfg = tl_settings();
$co = $cfg['company'];
$bank = $cfg['bank'];
$S = htl_settings($pdo);
$t = htl_totals($r);
$pct = (float) $r['gst_pct'];
$intra = strtolower(trim((string) $r['pstate'])) === strtolower(trim((string) $co['state'])) || trim((string) $r['pstate']) === '';
$half = rtrim(rtrim(number_format($pct / 2, 2, '.', ''), '0'), '.');
$terms = array_filter(array_map('trim', preg_split('/\R/', $S['terms']) ?: []));
header('X-Robots-Tag: noindex');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'self'");
?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">
<title><?= $doc === 'invoice' ? e($r['invoice_number']) : 'Registration card ' . e($r['reference']) ?></title>
<style>
*{box-sizing:border-box}body{font:14px/1.5 Arial,Helvetica,sans-serif;color:#1d2b24;margin:0;background:#eef2ef}
.sheet{max-width:820px;margin:20px auto;background:#fff;padding:36px 40px;border-radius:6px}
.top{display:flex;justify-content:space-between;gap:20px;border-bottom:3px solid #198754;padding-bottom:16px}
h1{margin:0;font-size:22px;color:#0f4c2d}h2{margin:0 0 6px;font-size:13px;text-transform:uppercase;letter-spacing:.06em;color:#6b7a72}
.muted{color:#6b7a72;font-size:12px}.right{text-align:right}.grid{display:grid;grid-template-columns:1fr 1fr;gap:24px;margin:20px 0}
table{width:100%;border-collapse:collapse;margin:14px 0}th{background:#f1f6f3;text-align:left;font-size:12px;text-transform:uppercase;padding:9px 10px}
td{padding:9px 10px;border-bottom:1px solid #e6eee9}td.r,th.r{text-align:right}
.tot td{border:0;padding:4px 10px}.tot .grand td{font-size:16px;font-weight:700;border-top:2px solid #0f4c2d;padding-top:10px}
.box{border:1px solid #d9e5de;border-radius:6px;padding:12px 14px}ul{margin:6px 0 0;padding-left:18px;font-size:12px;color:#4b5d54}
.sign{display:flex;justify-content:space-between;margin-top:64px;font-size:12px}.sign div{border-top:1px solid #1d2b24;padding-top:6px;width:210px;text-align:center}
.line{border-bottom:1px solid #1d2b24;min-height:22px;margin-bottom:12px}
.bar{max-width:820px;margin:14px auto 0;text-align:right}.bar button{background:#198754;color:#fff;border:0;border-radius:6px;padding:9px 18px;font-size:14px;cursor:pointer}
@media print{body{background:#fff}.sheet{margin:0;max-width:none;padding:0}.bar{display:none}}
</style></head><body>
<div class="bar"><button onclick="window.print()">Print / Save as PDF</button></div>
<div class="sheet">
  <div class="top">
    <div><h1><?= e($co['name']) ?></h1><div class="muted"><?= e($r['pname']) ?><br><?= e(trim($r['paddr'] . ', ' . $r['pcity'] . ', ' . $r['pstate'] . ' ' . $r['ppin'], ' ,')) ?><br><?= e($r['pphone'] ?: $co['phone']) ?> &middot; <?= e($co['email']) ?><br>GSTIN: <?= e($co['gstin']) ?></div></div>
    <div class="right"><h1><?= $doc === 'invoice' ? 'TAX INVOICE' : 'REGISTRATION CARD' ?></h1>
      <div class="muted"><?php if ($doc === 'invoice'): ?>Invoice no: <strong><?= e($r['invoice_number']) ?></strong><br>Date: <?= e(date('d M Y', strtotime((string) $r['invoice_date']))) ?><br><?php endif; ?>Booking: <strong><?= e($r['reference']) ?></strong><?= $doc === 'invoice' ? '<br>SAC: ' . e($S['sac_code']) : '' ?></div></div>
  </div>
  <div class="grid">
    <div><h2>Guest</h2><strong><?= e($r['guest_name']) ?></strong><br><?= e($r['phone']) ?><?= $r['email'] ? '<br>' . e($r['email']) : '' ?></div>
    <div><h2>Stay</h2><?= e($r['rname']) ?> &times; <?= (int) $r['rooms'] ?><?= $r['room_numbers'] ? ' (Room ' . e($r['room_numbers']) . ')' : '' ?><br>
      <?= e(date('d M Y', strtotime($r['check_in']))) ?> &rarr; <?= e(date('d M Y', strtotime($r['check_out']))) ?> (<?= (int) $r['nights'] ?> night<?= (int) $r['nights'] > 1 ? 's' : '' ?>)<br><?= (int) $r['adults'] ?> adult(s), <?= (int) $r['children'] ?> child(ren)</div>
  </div>

<?php if ($doc === 'invoice'): ?>
  <table><thead><tr><th>Description</th><th class="r">Amount</th></tr></thead><tbody>
    <tr><td>Room accommodation - <?= e($r['rname']) ?> &times; <?= (int) $r['rooms'] ?> room(s), <?= (int) $r['nights'] ?> night(s)</td><td class="r"><?= e(tl_inr($r['room_total'])) ?></td></tr>
    <?php if ((float) $r['extra_total'] > 0): ?><tr><td>Extra guest charges</td><td class="r"><?= e(tl_inr($r['extra_total'])) ?></td></tr><?php endif; ?>
    <?php if ((float) $r['other_charges'] > 0): ?><tr><td>Other charges</td><td class="r"><?= e(tl_inr($r['other_charges'])) ?></td></tr><?php endif; ?>
    <?php if ((float) $r['discount'] > 0): ?><tr><td>Discount</td><td class="r">-<?= e(tl_inr($r['discount'])) ?></td></tr><?php endif; ?>
  </tbody></table>
  <table class="tot" style="width:320px;margin-left:auto">
    <tr><td>Taxable value</td><td class="r"><?= e(tl_inr($t['taxable'])) ?></td></tr>
    <?php if ($intra): ?><tr><td>CGST @ <?= e($half) ?>%</td><td class="r"><?= e(tl_inr(round($t['gst'] / 2, 2))) ?></td></tr><tr><td>SGST @ <?= e($half) ?>%</td><td class="r"><?= e(tl_inr($t['gst'] - round($t['gst'] / 2, 2))) ?></td></tr>
    <?php else: ?><tr><td>IGST @ <?= e(rtrim(rtrim(number_format($pct, 2, '.', ''), '0'), '.')) ?>%</td><td class="r"><?= e(tl_inr($t['gst'])) ?></td></tr><?php endif; ?>
    <tr class="grand"><td>Total</td><td class="r"><?= e(tl_inr($t['total'])) ?></td></tr>
    <tr><td>Received</td><td class="r"><?= e(tl_inr($t['paid'])) ?></td></tr>
    <tr><td><strong>Balance due</strong></td><td class="r"><strong><?= e(tl_inr($t['balance'])) ?></strong></td></tr>
  </table>
  <p class="muted"><?= e(tl_amount_in_words($t['total'])) ?></p>
  <?php if ($bank['account_no'] || $bank['upi_id']): ?><div class="box"><h2>Pay to</h2><?= e($bank['account_name']) ?> &middot; <?= e($bank['bank_name']) ?> &middot; A/c <?= e($bank['account_no']) ?> &middot; IFSC <?= e($bank['ifsc']) ?><?= $bank['upi_id'] ? ' &middot; UPI ' . e($bank['upi_id']) : '' ?></div><?php endif; ?>
<?php else: ?>
  <div class="grid">
    <div><h2>ID proof</h2><div class="line"><?= e(trim(($r['id_type'] ?? '') . ' ' . ($r['id_number'] ?? ''))) ?></div><h2>Address</h2><div class="line"></div><div class="line"></div><h2>Vehicle no.</h2><div class="line"></div></div>
    <div><h2>Arrival</h2><div class="line"><?= e(date('d M Y', strtotime($r['check_in']))) ?> &nbsp; <?= e($r['cit']) ?></div><h2>Departure</h2><div class="line"><?= e(date('d M Y', strtotime($r['check_out']))) ?> &nbsp; <?= e($r['cot']) ?></div><h2>Purpose of visit</h2><div class="line"></div><h2>Payment</h2><div class="line">Total <?= e(tl_inr($t['total'])) ?> &middot; Balance <?= e(tl_inr($t['balance'])) ?></div></div>
  </div>
  <?php if ($r['special_requests']): ?><div class="box"><h2>Special requests</h2><?= nl2br(e($r['special_requests'])) ?></div><?php endif; ?>
  <?php if ($r['policies']): ?><div style="margin-top:16px"><h2>House rules</h2><div class="muted"><?= nl2br(e($r['policies'])) ?></div></div><?php endif; ?>
<?php endif; ?>
  <?php if ($terms): ?><div style="margin-top:18px"><h2>Terms</h2><ul><?php foreach ($terms as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  <div class="sign"><div>Guest signature</div><div>For <?= e($co['name']) ?></div></div>
</div></body></html>
