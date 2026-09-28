<?php
/**
 * admin/includes/invoice_template.php
 * ---------------------------------------------------------------
 * Renders a complete, print-ready (A4) GST invoice page.
 * Used by admin/invoice.php (admin) and transport-invoice.php (customer).
 *
 * Expects these variables to exist before it is included:
 *   array  $b            the transport_bookings row (with invoice_number already set)
 *   array  $payments     rows from transport_payment_history (may be empty)
 *   string $toolbarHtml  trusted HTML for the on-screen toolbar (hidden when printing)
 */
declare(strict_types=1);
require_once __DIR__ . '/transport_lib.php';

$cfg   = tl_settings();
$co    = $cfg['company'];
$bank  = $cfg['bank'];
$inv   = $cfg['invoice'];
$t     = tl_compute_totals($b);
$gstPct = (isset($b['gst_percentage']) && $b['gst_percentage'] !== '') ? (float) $b['gst_percentage'] : (float) $t['gst_pct'];
$isPaid = ($b['payment_status'] ?? '') === 'paid';
$invDate = !empty($b['invoice_date']) ? $b['invoice_date'] : ($b['updated_at'] ?? date('Y-m-d'));
$split   = (($inv['gst_split'] ?? 'cgst_sgst') === 'igst') ? 'igst' : 'cgst_sgst';

$extras = [];
if ($t['toll'] > 0)   { $extras[] = ['Toll charges', $t['toll']]; }
if ($t['fuel'] > 0)   { $extras[] = ['Fuel charges', $t['fuel']]; }
if ($t['labour'] > 0) { $extras[] = ['Loading / unloading', $t['labour']]; }
if ($t['extra'] > 0)  { $extras[] = ['Other charges', $t['extra']]; }

$weight = ($b['cargo_weight'] !== null && $b['cargo_weight'] !== '')
    ? rtrim(rtrim(number_format((float) $b['cargo_weight'], 2, '.', ''), '0'), '.') . ' ' . ($b['cargo_unit'] ?: 'kg') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Invoice <?= tl_e($b['invoice_number']) ?> - <?= tl_e($co['name']) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
  :root{--g:#0f4c2d;--g2:#198754;--gold:#ffc107;--ink:#1a2320;--mut:#6b7a72;--line:#dfe8e2;--soft:#f4f8f6}
  *{box-sizing:border-box}
  body{margin:0;background:#e9efeb;font-family:Inter,'Segoe UI',Arial,sans-serif;color:var(--ink);font-size:13px;line-height:1.5}
  .toolbar{max-width:820px;margin:18px auto 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;padding:0 8px}
  .toolbar .grp{display:flex;gap:8px;flex-wrap:wrap}
  .tbtn{display:inline-flex;align-items:center;gap:8px;background:var(--g2);color:#fff;border:0;border-radius:9px;padding:10px 16px;font-weight:600;font-size:13px;cursor:pointer;text-decoration:none;font-family:inherit}
  .tbtn.alt{background:#fff;color:var(--g);border:1px solid var(--line)}
  .sheet{max-width:820px;margin:16px auto 40px;background:#fff;box-shadow:0 10px 40px rgba(15,76,45,.15);position:relative;overflow:hidden}
  .band{height:8px;background:linear-gradient(90deg,var(--g),var(--g2),var(--gold))}
  .pad{padding:30px 38px}
  .head{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap;align-items:flex-start}
  .brand h1{margin:0;font-size:24px;color:var(--g);letter-spacing:-.3px}
  .brand .tag{font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:#b58900;font-weight:700}
  .brand p{margin:6px 0 0;color:var(--mut);max-width:330px}
  .doc{text-align:right}
  .doc .title{font-size:22px;font-weight:800;color:var(--g);letter-spacing:.06em}
  .doc table{margin-left:auto;border-collapse:collapse;margin-top:6px}
  .doc td{padding:2px 0 2px 14px;font-size:12px}.doc td:first-child{color:var(--mut)}
  .meta{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:22px 0 6px}
  .box{background:var(--soft);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
  .box h4{margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--mut)}
  .box strong{font-size:14px}
  .route{display:flex;align-items:center;gap:10px;font-weight:700;margin:14px 0 4px}
  .route i{color:#d4a300}
  table.items{width:100%;border-collapse:collapse;margin-top:14px}
  table.items th{background:var(--g);color:#fff;text-align:left;padding:9px 12px;font-size:11px;text-transform:uppercase;letter-spacing:.06em}
  table.items th.r,table.items td.r{text-align:right}
  table.items td{padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:top}
  table.items td small{color:var(--mut);display:block;margin-top:2px}
  .totals{display:flex;justify-content:space-between;gap:24px;flex-wrap:wrap;margin-top:16px}
  .words{flex:1;min-width:240px}
  .words .lbl{font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--mut)}
  .words .txt{font-weight:700;color:var(--g);margin-top:2px}
  .sum{min-width:290px}
  .sum table{width:100%;border-collapse:collapse}
  .sum td{padding:5px 0}.sum td:last-child{text-align:right;font-variant-numeric:tabular-nums}
  .sum tr.grand td{border-top:2px solid var(--g);padding-top:9px;font-size:16px;font-weight:800;color:var(--g)}
  .sum tr.bal td{font-weight:800;color:#b02a20}
  .sum tr.paid td{color:#14663f}
  h3.sec{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--g);margin:24px 0 8px;border-bottom:1px solid var(--line);padding-bottom:5px}
  table.pay{width:100%;border-collapse:collapse;font-size:12px}
  table.pay th{text-align:left;color:var(--mut);font-weight:600;padding:5px 8px;border-bottom:1px solid var(--line)}
  table.pay td{padding:6px 8px;border-bottom:1px dashed var(--line)}
  .cols{display:grid;grid-template-columns:1fr 1fr;gap:22px}
  .bank div{display:flex;justify-content:space-between;gap:10px;padding:3px 0;border-bottom:1px dashed var(--line)}
  .bank span:first-child{color:var(--mut)}
  ol.terms{margin:0;padding-left:18px;color:var(--mut);font-size:11.5px}
  .sign{text-align:right;margin-top:26px}.sign .line{display:inline-block;border-top:1px solid var(--ink);padding-top:5px;min-width:200px;text-align:center;font-weight:600}
  .foot{background:var(--soft);text-align:center;color:var(--mut);font-size:11px;padding:12px}
  .stamp{position:absolute;right:46px;top:150px;transform:rotate(-14deg);border:4px double #14663f;color:#14663f;font-weight:900;font-size:34px;letter-spacing:.14em;padding:4px 22px;border-radius:10px;opacity:.16;pointer-events:none}
  @media(max-width:640px){.pad{padding:20px 16px}.meta,.cols{grid-template-columns:1fr}.doc{text-align:left}.doc table{margin-left:0}}
  @media print{
    body{background:#fff}.toolbar{display:none!important}
    .sheet{box-shadow:none;margin:0;max-width:none}
    @page{size:A4;margin:10mm}
    .tbtn{display:none}
  }
</style>
</head>
<body>
<div class="toolbar"><?= $toolbarHtml ?? '' ?></div>

<div class="sheet">
  <div class="band"></div>
  <?php if ($isPaid): ?><div class="stamp">PAID</div><?php endif; ?>
  <div class="pad">

    <div class="head">
      <div class="brand">
        <h1><?= tl_e($co['name']) ?></h1>
        <div class="tag"><?= tl_e($co['tagline']) ?></div>
        <p><?= nl2br(tl_e($co['address'])) ?><br>
           <?= tl_e($co['phone']) ?> &middot; <?= tl_e($co['email']) ?><br>
           <strong>GSTIN:</strong> <?= tl_e($co['gstin']) ?> &nbsp; <strong>PAN:</strong> <?= tl_e($co['pan']) ?></p>
      </div>
      <div class="doc">
        <div class="title">TAX INVOICE</div>
        <table>
          <tr><td>Invoice no.</td><td><strong><?= tl_e($b['invoice_number']) ?></strong></td></tr>
          <tr><td>Invoice date</td><td><?= tl_e(tl_dt($invDate, 'd M Y')) ?></td></tr>
          <tr><td>Tracking ID</td><td><?= tl_e($b['tracking_id']) ?></td></tr>
          <?php if (!empty($b['lr_number'])): ?><tr><td>LR / GR no.</td><td><?= tl_e($b['lr_number']) ?></td></tr><?php endif; ?>
          <tr><td>Payment status</td><td><strong><?= tl_e(tl_payment_statuses()[$b['payment_status']] ?? $b['payment_status']) ?></strong></td></tr>
        </table>
      </div>
    </div>

    <div class="meta">
      <div class="box">
        <h4>Billed to</h4>
        <strong><?= tl_e($b['company_name'] ?: $b['customer_name']) ?></strong><br>
        <?php if (!empty($b['company_name'])): ?>Attn: <?= tl_e($b['customer_name']) ?><br><?php endif; ?>
        <?= tl_e($b['phone']) ?><?= !empty($b['email']) ? '<br>' . tl_e($b['email']) : '' ?>
      </div>
      <div class="box">
        <h4>Shipment</h4>
        <div class="route"><span><?= tl_e($b['pickup_city']) ?></span><i class="fa-solid fa-arrow-right-long"></i><span><?= tl_e($b['drop_city']) ?></span></div>
        <?= tl_e($b['cargo_type']) ?><?= $weight !== '' ? ' &middot; ' . tl_e($weight) : '' ?><?= !empty($b['number_of_packages']) ? ' &middot; ' . (int) $b['number_of_packages'] . ' pkgs' : '' ?><br>
        <span style="color:var(--mut)">Vehicle: <?= tl_e($b['vehicle_type'] ?: '—') ?> &middot; Pickup: <?= tl_e(tl_dt($b['scheduled_pickup'], 'd M Y')) ?></span>
      </div>
    </div>

    <table class="items">
      <thead><tr><th style="width:36px">#</th><th>Description</th><th>SAC</th><th class="r">Amount (₹)</th></tr></thead>
      <tbody>
        <tr>
          <td>1</td>
          <td>Freight charges &ndash; <?= tl_e($b['pickup_city']) ?> to <?= tl_e($b['drop_city']) ?>
            <small><?= tl_e($b['pickup_address']) ?> &rarr; <?= tl_e($b['drop_address']) ?></small></td>
          <td><?= tl_e($inv['sac_code']) ?></td>
          <td class="r"><?= number_format($t['total'], 2) ?></td>
        </tr>
        <?php foreach ($extras as $i => [$label, $amt]): ?>
          <tr><td><?= $i + 2 ?></td><td><?= tl_e($label) ?></td><td>—</td><td class="r"><?= number_format((float) $amt, 2) ?></td></tr>
        <?php endforeach; ?>
        <?php if ($t['discount'] > 0): ?>
          <tr><td>&nbsp;</td><td>Discount</td><td>—</td><td class="r">- <?= number_format($t['discount'], 2) ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>

    <div class="totals">
      <div class="words">
        <div class="lbl">Amount in words</div>
        <div class="txt"><?= tl_e(tl_amount_in_words($t['grand'])) ?></div>
        <?php if (!empty($b['customer_notes'])): ?>
          <div class="lbl" style="margin-top:12px">Notes</div><div><?= nl2br(tl_e($b['customer_notes'])) ?></div>
        <?php endif; ?>
      </div>
      <div class="sum">
        <table>
          <tr><td>Taxable freight value</td><td><?= number_format($t['total'], 2) ?></td></tr>
          <?php if ($split === 'igst'): ?>
            <tr><td>IGST @ <?= rtrim(rtrim(number_format($gstPct, 2), '0'), '.') ?>%</td><td><?= number_format($t['gst'], 2) ?></td></tr>
          <?php else: ?>
            <tr><td>CGST @ <?= rtrim(rtrim(number_format($gstPct / 2, 2), '0'), '.') ?>%</td><td><?= number_format($t['gst'] / 2, 2) ?></td></tr>
            <tr><td>SGST @ <?= rtrim(rtrim(number_format($gstPct / 2, 2), '0'), '.') ?>%</td><td><?= number_format($t['gst'] / 2, 2) ?></td></tr>
          <?php endif; ?>
          <?php $addOn = $t['toll'] + $t['fuel'] + $t['labour'] + $t['extra']; if ($addOn > 0): ?>
            <tr><td>Additional charges</td><td><?= number_format($addOn, 2) ?></td></tr>
          <?php endif; ?>
          <?php if ($t['discount'] > 0): ?><tr><td>Discount</td><td>- <?= number_format($t['discount'], 2) ?></td></tr><?php endif; ?>
          <tr class="grand"><td>Grand total</td><td><?= tl_e(tl_inr($t['grand'])) ?></td></tr>
          <tr class="paid"><td>Received</td><td><?= tl_e(tl_inr($t['paid'])) ?></td></tr>
          <tr class="bal"><td>Balance due</td><td><?= tl_e(tl_inr(max(0, $t['balance']))) ?></td></tr>
        </table>
      </div>
    </div>

    <?php if (!empty($payments)): ?>
      <h3 class="sec">Payments received</h3>
      <table class="pay">
        <thead><tr><th>Date</th><th>Receipt</th><th>Mode</th><th>Reference</th><th style="text-align:right">Amount</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): $ref = $p['utr_number'] ?: ($p['transaction_id'] ?: ($p['cheque_number'] ?: '—')); $neg = ($p['payment_type'] ?? '') === 'refund'; ?>
          <tr>
            <td><?= tl_e(tl_dt($p['payment_date'], 'd M Y')) ?></td>
            <td><?= tl_e($p['receipt_number'] ?: '—') ?></td>
            <td><?= tl_e(ucwords(str_replace('_', ' ', (string) $p['payment_mode']))) ?></td>
            <td><?= tl_e($ref) ?></td>
            <td style="text-align:right"><?= $neg ? '- ' : '' ?><?= tl_e(tl_inr($p['amount'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <div class="cols">
      <div>
        <h3 class="sec">Bank details</h3>
        <div class="bank">
          <div><span>Account name</span><strong><?= tl_e($bank['account_name']) ?></strong></div>
          <div><span>Bank</span><strong><?= tl_e($bank['bank_name']) ?></strong></div>
          <div><span>Account no.</span><strong><?= tl_e($bank['account_no']) ?></strong></div>
          <div><span>IFSC</span><strong><?= tl_e($bank['ifsc']) ?></strong></div>
          <?php if (!empty($bank['branch'])): ?><div><span>Branch</span><strong><?= tl_e($bank['branch']) ?></strong></div><?php endif; ?>
          <?php if (!empty($bank['upi_id'])): ?><div><span>UPI</span><strong><?= tl_e($bank['upi_id']) ?></strong></div><?php endif; ?>
        </div>
      </div>
      <div>
        <h3 class="sec">Terms &amp; conditions</h3>
        <ol class="terms"><?php foreach ((array) $inv['terms'] as $term): ?><li><?= tl_e($term) ?></li><?php endforeach; ?></ol>
      </div>
    </div>

    <div class="sign"><div class="line">For <?= tl_e($co['name']) ?><br><span style="font-weight:400;color:var(--mut)">Authorised signatory</span></div></div>
  </div>
  <div class="foot">This is a computer-generated invoice. Track your shipment at <?= tl_e($cfg['site_url']) ?>/track &middot; <?= tl_e($co['website']) ?></div>
</div>
</body>
</html>
