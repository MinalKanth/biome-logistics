<?php
/**
 * admin/includes/bamboo_document_template.php
 * Shared printable Quotation / Tax Invoice for bamboo orders.
 * Expects: array $o (bamboo_orders row), array $items (bamboo_order_items rows),
 *          array $payments, string $toolbarHtml, bool $isInvoice
 */
declare(strict_types=1);
require_once __DIR__ . '/bamboo_lib.php';

$cfg = tl_settings(); $co = $cfg['company']; $bank = $cfg['bank']; $bb = bb_settings();
$docNo   = $isInvoice ? $o['invoice_number'] : $o['quote_number'];
$docDate = $isInvoice ? ($o['invoice_date'] ?: date('Y-m-d')) : $o['created_at'];
$paid = (float) $o['paid_amount']; $balance = max(0, (float) $o['grand_total'] - $paid);
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title><?= tl_e($docNo ?: 'Quotation') ?> - <?= tl_e($co['name']) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
 :root{--g:#0f4c2d;--g2:#198754;--gold:#ffc107;--ink:#1a2320;--mut:#6b7a72;--line:#dfe8e2;--soft:#f4f8f6}
 *{box-sizing:border-box}body{margin:0;background:#e9efeb;font-family:Inter,Arial,sans-serif;color:var(--ink);font-size:13px;line-height:1.5}
 .toolbar{max-width:820px;margin:18px auto 0;display:flex;gap:10px;flex-wrap:wrap;justify-content:space-between;padding:0 8px}
 .tbtn{display:inline-flex;align-items:center;gap:8px;background:var(--g2);color:#fff;border:0;border-radius:9px;padding:10px 16px;font-weight:600;font-size:13px;cursor:pointer;text-decoration:none}
 .tbtn.alt{background:#fff;color:var(--g);border:1px solid var(--line)}
 .sheet{max-width:820px;margin:16px auto 40px;background:#fff;box-shadow:0 10px 40px rgba(15,76,45,.15)}
 .band{height:8px;background:linear-gradient(90deg,var(--g),var(--g2),var(--gold))}.pad{padding:30px 38px}
 .head{display:flex;justify-content:space-between;gap:20px;flex-wrap:wrap}
 .brand h1{margin:0;font-size:24px;color:var(--g)}.brand .tag{font-size:10px;text-transform:uppercase;letter-spacing:.16em;color:#b58900;font-weight:700}
 .brand p{margin:6px 0 0;color:var(--mut);max-width:330px}.doc{text-align:right}.doc .title{font-size:22px;font-weight:800;color:var(--g);letter-spacing:.06em}
 .doc table{margin-left:auto;border-collapse:collapse;margin-top:6px}.doc td{padding:2px 0 2px 14px;font-size:12px}.doc td:first-child{color:var(--mut)}
 .meta{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:22px 0 6px}.box{background:var(--soft);border:1px solid var(--line);border-radius:10px;padding:14px 16px}
 .box h4{margin:0 0 6px;font-size:10px;text-transform:uppercase;letter-spacing:.1em;color:var(--mut)}
 table.items{width:100%;border-collapse:collapse;margin-top:14px}table.items th{background:var(--g);color:#fff;text-align:left;padding:9px 12px;font-size:11px;text-transform:uppercase}
 table.items th.r,table.items td.r{text-align:right}table.items td{padding:9px 12px;border-bottom:1px solid var(--line)}
 .totals{display:flex;justify-content:flex-end;margin-top:10px}.sum{min-width:290px}.sum table{width:100%;border-collapse:collapse}
 .sum td{padding:5px 0}.sum td:last-child{text-align:right}.sum tr.grand td{border-top:2px solid var(--g);padding-top:9px;font-size:16px;font-weight:800;color:var(--g)}
 .sum tr.bal td{font-weight:800;color:#b02a20}.sum tr.paid td{color:#14663f}
 h3.sec{font-size:11px;text-transform:uppercase;letter-spacing:.1em;color:var(--g);margin:22px 0 8px;border-bottom:1px solid var(--line);padding-bottom:5px}
 ol.terms{margin:0;padding-left:18px;color:var(--mut);font-size:11.5px}.cols{display:grid;grid-template-columns:1fr 1fr;gap:22px}
 .bank div{display:flex;justify-content:space-between;padding:3px 0;border-bottom:1px dashed var(--line)}.bank span:first-child{color:var(--mut)}
 .sign{text-align:right;margin-top:24px}.sign .line{display:inline-block;border-top:1px solid var(--ink);padding-top:5px;min-width:200px;text-align:center;font-weight:600}
 .foot{background:var(--soft);text-align:center;color:var(--mut);font-size:11px;padding:12px}
 @media(max-width:640px){.pad{padding:20px 16px}.meta,.cols{grid-template-columns:1fr}.doc{text-align:left}.doc table{margin-left:0}}
 @media print{body{background:#fff}.toolbar{display:none!important}.sheet{box-shadow:none;margin:0;max-width:none}@page{size:A4;margin:10mm}}
</style></head><body>
<div class="toolbar"><?= $toolbarHtml ?? '' ?></div>
<div class="sheet"><div class="band"></div><div class="pad">
  <div class="head">
    <div class="brand"><h1><?= tl_e($co['name']) ?></h1><div class="tag"><?= tl_e($co['tagline']) ?> &middot; Bamboo Trading</div>
      <p><?= nl2br(tl_e($co['address'])) ?><br><?= tl_e($co['phone']) ?> &middot; <?= tl_e($co['email']) ?><br>
      <strong>GSTIN:</strong> <?= tl_e($co['gstin']) ?> &nbsp; <strong>PAN:</strong> <?= tl_e($co['pan']) ?></p></div>
    <div class="doc"><div class="title"><?= $isInvoice ? 'TAX INVOICE' : 'QUOTATION' ?></div>
      <table>
        <tr><td><?= $isInvoice ? 'Invoice no.' : 'Quote no.' ?></td><td><strong><?= tl_e($docNo ?: 'Draft') ?></strong></td></tr>
        <tr><td>Date</td><td><?= tl_e(tl_dt($docDate, 'd M Y')) ?></td></tr>
        <?php if (!$isInvoice && $o['valid_until']): ?><tr><td>Valid until</td><td><?= tl_e(tl_dt($o['valid_until'], 'd M Y')) ?></td></tr><?php endif; ?>
        <?php if ($isInvoice && $o['order_number']): ?><tr><td>Order no.</td><td><?= tl_e($o['order_number']) ?></td></tr><?php endif; ?>
      </table></div>
  </div>
  <div class="meta">
    <div class="box"><h4>Billed to</h4><strong><?= tl_e($o['company_name'] ?: $o['customer_name']) ?></strong><br>
      <?php if ($o['company_name']): ?>Attn: <?= tl_e($o['customer_name']) ?><br><?php endif; ?>
      <?= tl_e($o['phone']) ?><?= $o['email'] ? '<br>' . tl_e($o['email']) : '' ?><?= $o['customer_gstin'] ? '<br>GSTIN: ' . tl_e($o['customer_gstin']) : '' ?></div>
    <div class="box"><h4>Deliver to</h4><?= nl2br(tl_e($o['delivery_address'] ?: $o['billing_address'] ?: '—')) ?><br>
      <span style="color:var(--mut)"><?= tl_e(trim(($o['delivery_city'] ?? '') . ', ' . ($o['delivery_state'] ?? '') . ' ' . ($o['delivery_pincode'] ?? ''), ' ,')) ?></span></div>
  </div>
  <table class="items"><thead><tr><th style="width:32px">#</th><th>Item</th><th>HSN</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount (₹)</th></tr></thead>
    <tbody><?php foreach ($items as $i => $it): ?>
      <tr><td><?= $i + 1 ?></td><td><?= tl_e($it['description']) ?></td><td><?= tl_e($it['hsn'] ?: $bb['hsn']) ?></td>
        <td class="r"><?= tl_e(bb_num($it['quantity'])) ?> <?= tl_e(bb_units()[$it['unit']] ?? $it['unit']) ?></td>
        <td class="r"><?= number_format((float) $it['rate'], 2) ?></td><td class="r"><?= number_format((float) $it['amount'], 2) ?></td></tr>
    <?php endforeach; ?></tbody></table>
  <div class="totals"><div class="sum"><table>
    <tr><td>Subtotal</td><td><?= number_format((float) $o['subtotal'], 2) ?></td></tr>
    <?php if ((float) $o['discount'] > 0): ?><tr><td>Discount</td><td>- <?= number_format((float) $o['discount'], 2) ?></td></tr><?php endif; ?>
    <?php if ((float) $o['transport_charge'] > 0): ?><tr><td>Transport charges</td><td><?= number_format((float) $o['transport_charge'], 2) ?></td></tr><?php endif; ?>
    <tr><td>GST @ <?= tl_e(bb_num($o['gst_pct'])) ?>%</td><td><?= number_format((float) $o['gst_amount'], 2) ?></td></tr>
    <tr class="grand"><td>Grand total</td><td><?= tl_e(tl_inr($o['grand_total'])) ?></td></tr>
    <?php if ($isInvoice): ?><tr class="paid"><td>Received</td><td><?= tl_e(tl_inr($paid)) ?></td></tr>
    <tr class="bal"><td>Balance due</td><td><?= tl_e(tl_inr($balance)) ?></td></tr><?php endif; ?>
  </table></div></div>
  <p style="color:var(--mut);font-size:11px;margin-top:2px"><?= tl_e(tl_amount_in_words((float) $o['grand_total'])) ?></p>
  <?php if (!empty($payments)): ?><h3 class="sec">Payments received</h3>
    <table style="width:100%;border-collapse:collapse;font-size:12px"><thead><tr><th style="text-align:left;color:var(--mut);padding:5px 8px;border-bottom:1px solid var(--line)">Date</th>
      <th style="text-align:left;color:var(--mut);padding:5px 8px;border-bottom:1px solid var(--line)">Receipt</th><th style="text-align:left;color:var(--mut);padding:5px 8px;border-bottom:1px solid var(--line)">Mode</th>
      <th style="text-align:right;color:var(--mut);padding:5px 8px;border-bottom:1px solid var(--line)">Amount</th></tr></thead><tbody>
      <?php foreach ($payments as $p): ?><tr><td style="padding:6px 8px;border-bottom:1px dashed var(--line)"><?= tl_e(tl_dt($p['payment_date'], 'd M Y')) ?></td>
        <td style="padding:6px 8px;border-bottom:1px dashed var(--line)"><?= tl_e($p['receipt_number'] ?: '—') ?></td>
        <td style="padding:6px 8px;border-bottom:1px dashed var(--line)"><?= tl_e(ucwords(str_replace('_', ' ', (string) $p['payment_mode']))) ?></td>
        <td style="text-align:right;padding:6px 8px;border-bottom:1px dashed var(--line)"><?= tl_e(tl_inr($p['amount'])) ?></td></tr><?php endforeach; ?>
    </tbody></table><?php endif; ?>
  <div class="cols">
    <div><h3 class="sec">Bank details</h3><div class="bank">
      <div><span>Account name</span><strong><?= tl_e($bank['account_name']) ?></strong></div>
      <div><span>Bank</span><strong><?= tl_e($bank['bank_name']) ?></strong></div>
      <div><span>Account no.</span><strong><?= tl_e($bank['account_no']) ?></strong></div>
      <div><span>IFSC</span><strong><?= tl_e($bank['ifsc']) ?></strong></div>
      <?php if ($bank['upi_id']): ?><div><span>UPI</span><strong><?= tl_e($bank['upi_id']) ?></strong></div><?php endif; ?></div></div>
    <div><h3 class="sec">Terms &amp; conditions</h3><ol class="terms"><?php foreach ((array) $bb['terms'] as $t): ?><li><?= tl_e($t) ?></li><?php endforeach; ?></ol></div>
  </div>
  <div class="sign"><div class="line">For <?= tl_e($co['name']) ?><br><span style="font-weight:400;color:var(--mut)">Authorised signatory</span></div></div>
</div><div class="foot">Computer-generated document &middot; <?= tl_e($co['website']) ?></div></div>
</body></html>