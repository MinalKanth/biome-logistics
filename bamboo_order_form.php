<?php
/**
 * admin/bamboo_order_form.php - create/edit a bamboo quotation.
 * ?enquiry_id=X pre-fills customer details from an enquiry.
 * ?id=X edits an existing order (only while status = quotation).
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bamboo_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$id = (int) ($_GET['id'] ?? 0);
$enquiryId = (int) ($_GET['enquiry_id'] ?? 0);
$errors = [];
$UNITS = bb_units();

$order = ['id' => 0, 'customer_name' => '', 'company_name' => '', 'phone' => '', 'email' => '', 'customer_gstin' => '',
    'billing_address' => '', 'delivery_address' => '', 'delivery_city' => '', 'delivery_state' => 'Assam', 'delivery_pincode' => '',
    'discount' => 0, 'transport_charge' => 0, 'gst_pct' => bb_settings()['default_gst'], 'notes' => ''];
$itemRows = [['product_id' => '', 'description' => '', 'hsn' => bb_settings()['hsn'], 'quantity' => 1, 'unit' => 'piece', 'rate' => 0]];

if ($id > 0) {
    $s = $pdo->prepare("SELECT * FROM bamboo_orders WHERE id = :id AND deleted_at IS NULL");
    $s->execute([':id' => $id]);
    $found = $s->fetch();
    if (!$found) {
        header('Location: bamboo_orders.php');
        exit;
    }
    if ($found['status'] !== 'quotation') {
        header('Location: bamboo_order_view.php?id=' . $id);
        exit;
    }
    $order = $found;
    $it = $pdo->prepare('SELECT * FROM bamboo_order_items WHERE order_id = :id ORDER BY sort_order, id');
    $it->execute([':id' => $id]);
    $rows = $it->fetchAll();
    if ($rows) {
        $itemRows = $rows;
    }
} elseif ($enquiryId > 0) {
    $s = $pdo->prepare('SELECT * FROM bamboo_enquiries WHERE id = :id');
    $s->execute([':id' => $enquiryId]);
    if ($e = $s->fetch()) {
        $order['customer_name'] = (string) $e['full_name'];
        $order['company_name']  = (string) $e['company_name'];
        $order['phone']         = (string) $e['mobile_number'];
        $order['email']         = (string) $e['email'];
        $order['delivery_address'] = (string) ($e['delivery_location'] ?: '');
        $order['delivery_city']    = (string) ($e['city'] ?: '');
        $order['delivery_state']   = (string) ($e['state'] ?: 'Assam');
        $order['notes']         = trim(((string) $e['products_selected']) . ' — ' . ((string) $e['quantity_required']) . "\n" . (string) $e['additional_requirements']);
        $itemRows[0]['description'] = (string) $e['products_selected'];
    }
}

$products = [];
try {
    $products = $pdo->query('SELECT id, name, unit, price, hsn FROM bamboo_products WHERE is_active = 1 ORDER BY sort_order, name')->fetchAll();
} catch (Throwable $e) {
    $errors[] = 'Run admin/bamboo_install.php once first.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $f = static fn(string $k, int $len = 255): string => mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $len);
    $order['customer_name']   = $f('customer_name', 150);
    $order['company_name']    = $f('company_name', 150);
    $order['phone']           = $f('phone', 30);
    $order['email']           = $f('email', 150);
    $order['customer_gstin']  = strtoupper($f('customer_gstin', 20));
    $order['billing_address'] = $f('billing_address', 500);
    $order['delivery_address']= $f('delivery_address', 500);
    $order['delivery_city']   = $f('delivery_city', 100);
    $order['delivery_state']  = $f('delivery_state', 100);
    $order['delivery_pincode']= $f('delivery_pincode', 12);
    $order['notes']           = $f('notes', 2000);
    $discount  = (isset($_POST['discount']) && is_numeric($_POST['discount'])) ? max(0, (float) $_POST['discount']) : 0.0;
    $transport = (isset($_POST['transport_charge']) && is_numeric($_POST['transport_charge'])) ? max(0, (float) $_POST['transport_charge']) : 0.0;
    $gstPct    = (isset($_POST['gst_pct']) && is_numeric($_POST['gst_pct'])) ? max(0, min(100, (float) $_POST['gst_pct'])) : (float) bb_settings()['default_gst'];
    $validUntil = (string) ($_POST['valid_until'] ?? '');

    $items = [];
    $descs = $_POST['item_description'] ?? [];
    foreach ((array) $descs as $i => $desc) {
        $desc = mb_substr(trim((string) $desc), 0, 255);
        $qty  = isset($_POST['item_quantity'][$i]) && is_numeric($_POST['item_quantity'][$i]) ? (float) $_POST['item_quantity'][$i] : 0;
        $rate = isset($_POST['item_rate'][$i]) && is_numeric($_POST['item_rate'][$i]) ? (float) $_POST['item_rate'][$i] : 0;
        if ($desc === '' || $qty <= 0) {
            continue;
        }
        $unit = array_key_exists((string) ($_POST['item_unit'][$i] ?? ''), $UNITS) ? (string) $_POST['item_unit'][$i] : 'piece';
        $pid  = (isset($_POST['item_product_id'][$i]) && ctype_digit((string) $_POST['item_product_id'][$i]) && (int) $_POST['item_product_id'][$i] > 0) ? (int) $_POST['item_product_id'][$i] : null;
        $hsn  = mb_substr(trim((string) ($_POST['item_hsn'][$i] ?? '')), 0, 12);
        $items[] = ['product_id' => $pid, 'description' => $desc, 'hsn' => $hsn ?: bb_settings()['hsn'], 'quantity' => $qty, 'unit' => $unit, 'rate' => max(0, $rate)];
    }
    $itemRows = $items ?: $itemRows;

    if ($order['customer_name'] === '') { $errors[] = 'Customer name is required.'; }
    if (strlen(preg_replace('/\D/', '', $order['phone']) ?? '') < 10) { $errors[] = 'Enter a valid mobile number.'; }
    if (!$items) { $errors[] = 'Add at least one item with a quantity greater than zero.'; }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $t = bb_totals($items, $discount, $transport, $gstPct);
            $p = [':cn' => $order['customer_name'], ':co' => $order['company_name'] ?: null, ':ph' => $order['phone'],
                  ':em' => $order['email'] ?: null, ':gs' => $order['customer_gstin'] ?: null, ':ba' => $order['billing_address'] ?: null,
                  ':da' => $order['delivery_address'] ?: null, ':dc' => $order['delivery_city'] ?: null, ':ds' => $order['delivery_state'] ?: null,
                  ':dp' => $order['delivery_pincode'] ?: null, ':sub' => $t['subtotal'], ':disc' => $t['discount'], ':tr' => $t['transport'],
                  ':gp' => $gstPct, ':ga' => $t['gst'], ':gt' => $t['grand'], ':vu' => $validUntil ?: null, ':nt' => $order['notes'] ?: null];

            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE bamboo_orders SET customer_name=:cn, company_name=:co, phone=:ph, email=:em, customer_gstin=:gs,
                        billing_address=:ba, delivery_address=:da, delivery_city=:dc, delivery_state=:ds, delivery_pincode=:dp,
                        subtotal=:sub, discount=:disc, transport_charge=:tr, gst_pct=:gp, gst_amount=:ga, grand_total=:gt,
                        balance_amount = :gt - paid_amount, payment_status = IF(paid_amount>0,"partial","unpaid"),
                        valid_until=:vu, notes=:nt, updated_at=NOW() WHERE id=:id'
                )->execute($p + [':id' => $id]);
                $pdo->prepare('DELETE FROM bamboo_order_items WHERE order_id = :id')->execute([':id' => $id]);
                $orderId = $id;
            } else {
                $token = bin2hex(random_bytes(20));
                $qn = tl_next_sequence($pdo, 'bamboo_quote', 'BQT');
                $pdo->prepare(
                    'INSERT INTO bamboo_orders (quote_number, public_token, enquiry_id, customer_name, company_name, phone, email, customer_gstin,
                        billing_address, delivery_address, delivery_city, delivery_state, delivery_pincode, status,
                        subtotal, discount, transport_charge, gst_pct, gst_amount, grand_total, paid_amount, balance_amount, payment_status,
                        valid_until, notes, created_by, created_at, updated_at)
                     VALUES (:qn, :tok, :eid, :cn, :co, :ph, :em, :gs, :ba, :da, :dc, :ds, :dp, "quotation",
                        :sub, :disc, :tr, :gp, :ga, :gt, 0, :gt, "unpaid", :vu, :nt, :admin, NOW(), NOW())'
                )->execute($p + [':qn' => $qn, ':tok' => $token, ':eid' => $enquiryId ?: null, ':admin' => $_SESSION['admin_id']]);
                $orderId = (int) $pdo->lastInsertId();
                if ($enquiryId > 0) {
                    $pdo->prepare("UPDATE bamboo_enquiries SET order_id = :oid, status = 'quoted', updated_at = NOW() WHERE id = :eid")
                        ->execute([':oid' => $orderId, ':eid' => $enquiryId]);
                }
            }

            $ins = $pdo->prepare('INSERT INTO bamboo_order_items (order_id, product_id, description, hsn, quantity, unit, rate, amount, sort_order) VALUES (:o,:p,:d,:h,:q,:u,:r,:a,:s)');
            foreach ($items as $i => $it) {
                $ins->execute([':o' => $orderId, ':p' => $it['product_id'], ':d' => $it['description'], ':h' => $it['hsn'],
                    ':q' => $it['quantity'], ':u' => $it['unit'], ':r' => $it['rate'], ':a' => round($it['quantity'] * $it['rate'], 2), ':s' => $i]);
            }
            $pdo->commit();
            log_activity((int) $_SESSION['admin_id'], 'bamboo_quotation_saved', "order_id={$orderId}");
            header('Location: bamboo_order_view.php?id=' . $orderId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('bamboo_order_form: ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving. Please try again.';
        }
    }
}

tl_shell_top($id ? 'Edit Quotation' : 'New Quotation', $id ? 'Edit Quotation' : 'New Quotation', ['Bamboo Trading' => 'bamboo_orders.php']);
?>
<?php if ($errors): ?><div class="tx-flash err"><?php foreach ($errors as $m): ?><div><?= e($m) ?></div><?php endforeach; ?></div><?php endif; ?>
<form method="post" id="orderForm">
  <?= csrf_field() ?>
  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-user"></i> Customer &amp; delivery</h3></div>
    <div class="tx-form">
      <div><label>Customer name *</label><input name="customer_name" maxlength="150" required value="<?= e($order['customer_name']) ?>"></div>
      <div><label>Company</label><input name="company_name" maxlength="150" value="<?= e($order['company_name']) ?>"></div>
      <div><label>Mobile *</label><input name="phone" type="tel" maxlength="30" required value="<?= e($order['phone']) ?>"></div>
      <div><label>E-mail</label><input name="email" type="email" maxlength="150" value="<?= e($order['email']) ?>"></div>
      <div><label>Customer GSTIN</label><input name="customer_gstin" maxlength="20" value="<?= e($order['customer_gstin']) ?>"></div>
      <div><label>Quote valid until</label><input name="valid_until" type="date" value="<?= e($order['valid_until'] ?? date('Y-m-d', strtotime('+' . bb_settings()['quote_valid_days'] . ' days'))) ?>"></div>
      <div class="full"><label>Billing address</label><textarea name="billing_address" rows="2" maxlength="500"><?= e($order['billing_address']) ?></textarea></div>
      <div class="full"><label>Delivery address</label><textarea name="delivery_address" rows="2" maxlength="500"><?= e($order['delivery_address']) ?></textarea></div>
      <div><label>City</label><input name="delivery_city" maxlength="100" value="<?= e($order['delivery_city']) ?>"></div>
      <div><label>State</label><input name="delivery_state" maxlength="100" value="<?= e($order['delivery_state']) ?>"></div>
      <div><label>PIN code</label><input name="delivery_pincode" maxlength="12" value="<?= e($order['delivery_pincode']) ?>"></div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-list"></i> Items</h3><button type="button" class="btn btn-small btn-secondary" id="addItem"><i class="fa-solid fa-plus"></i> Add item</button></div>
    <div class="tx-scroll"><table class="tx-table" id="itemsTable">
      <thead><tr><th style="min-width:170px">Product</th><th>Description</th><th style="width:90px">HSN</th><th style="width:90px">Qty</th><th style="width:110px">Unit</th><th style="width:120px">Rate (₹)</th><th class="r" style="width:110px">Amount</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($itemRows as $r): ?>
        <tr>
          <td><select class="prodSel" style="width:100%;border:1px solid #d4e0d9;border-radius:8px;padding:7px"><option value="">— custom —</option>
              <?php foreach ($products as $p): ?><option value="<?= (int) $p['id'] ?>" data-desc="<?= e($p['name']) ?>" data-unit="<?= e($p['unit']) ?>" data-rate="<?= e($p['price']) ?>" data-hsn="<?= e($p['hsn']) ?>"<?= (string) ($r['product_id'] ?? '') === (string) $p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?>
            </select><input type="hidden" name="item_product_id[]" class="prodId" value="<?= e($r['product_id'] ?? '') ?>"></td>
          <td><input name="item_description[]" class="itDesc" style="width:100%;border:1px solid #d4e0d9;border-radius:8px;padding:7px" maxlength="255" value="<?= e($r['description']) ?>"></td>
          <td><input name="item_hsn[]" class="itHsn" style="width:100%;border:1px solid #d4e0d9;border-radius:8px;padding:7px" maxlength="12" value="<?= e($r['hsn'] ?: bb_settings()['hsn']) ?>"></td>
          <td><input name="item_quantity[]" class="itQty" type="number" step="0.01" min="0" style="width:100%;border:1px solid #d4e0d9;border-radius:8px;padding:7px" value="<?= e($r['quantity']) ?>"></td>
          <td><select name="item_unit[]" class="itUnit" style="width:100%;border:1px solid #d4e0d9;border-radius:8px;padding:7px"><?php foreach ($UNITS as $k => $l): ?><option value="<?= e($k) ?>"<?= ($r['unit'] ?? 'piece') === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></td>
          <td><input name="item_rate[]" class="itRate" type="number" step="0.01" min="0" style="width:100%;border:1px solid #d4e0d9;border-radius:8px;padding:7px" value="<?= e($r['rate']) ?>"></td>
          <td class="r itAmt">0.00</td>
          <td><button type="button" class="btn btn-small btn-ghost rmItem" style="color:#b02a20"><i class="fa-solid fa-trash"></i></button></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-calculator"></i> Charges &amp; total</h3></div>
    <div class="tx-form">
      <div><label>Discount (₹)</label><input name="discount" id="discount" type="number" step="0.01" min="0" value="<?= e($order['discount']) ?>"></div>
      <div><label>Transport charge (₹)</label><input name="transport_charge" id="transport" type="number" step="0.01" min="0" value="<?= e($order['transport_charge']) ?>"></div>
      <div><label>GST %</label><input name="gst_pct" id="gstPct" type="number" step="0.01" min="0" max="100" value="<?= e($order['gst_pct']) ?>"></div>
    </div>
    <div class="amount-strip" style="margin-top:14px">
      <div class="item"><div class="label">Subtotal</div><div class="value" id="sumSub">₹0.00</div></div>
      <div class="item"><div class="label">GST</div><div class="value" id="sumGst">₹0.00</div></div>
      <div class="item ok"><div class="label">Grand total</div><div class="value" id="sumGrand">₹0.00</div></div>
    </div>
    <div class="tx-form" style="margin-top:14px"><div class="full"><label>Notes</label><textarea name="notes" rows="2" maxlength="2000"><?= e($order['notes']) ?></textarea></div></div>
    <button class="btn btn-primary" type="submit" style="margin-top:14px"><i class="fa-solid fa-floppy-disk"></i> Save quotation</button>
  </div>
</form>

<script>
(function () {
  var tbody = document.querySelector('#itemsTable tbody');
  var units = <?= json_encode($UNITS) ?>;

  function rowTemplate() {
    var opts = document.querySelector('.prodSel').cloneNode(true); opts.value = '';
    var unitOpts = Object.keys(units).map(function (k) { return '<option value="' + k + '">' + units[k] + '</option>'; }).join('');
    var tr = document.createElement('tr');
    tr.innerHTML = '<td></td>' +
      '<td><input name="item_description[]" class="itDesc" style="width:100%;border:1px solid #d4e0d9;border-radius:8px;padding:7px" maxlength="255"></td>' +
      '<td><input name="item_hsn[]" class="itHsn" style="width:100%;border:1px solid #d4e0d9;border-radius:8px;padding:7px" maxlength="12" value="<?= e(bb_settings()['hsn']) ?>"></td>' +
      '<td><input name="item_quantity[]" class="itQty" type="number" step="0.01" min="0" style="width:100%;border:1px solid #d4e0d9;border-radius:8px;padding:7px" value="1"></td>' +
      '<td><select name="item_unit[]" class="itUnit" style="width:100%;border:1px solid #d4e0d9;border-radius:8px;padding:7px">' + unitOpts + '</select></td>' +
      '<td><input name="item_rate[]" class="itRate" type="number" step="0.01" min="0" style="width:100%;border:1px solid #d4e0d9;border-radius:8px;padding:7px" value="0"></td>' +
      '<td class="r itAmt">0.00</td>' +
      '<td><button type="button" class="btn btn-small btn-ghost rmItem" style="color:#b02a20"><i class="fa-solid fa-trash"></i></button></td>';
    tr.firstElementChild.appendChild(opts);
    var hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'item_product_id[]'; hidden.className = 'prodId';
    tr.firstElementChild.appendChild(hidden);
    return tr;
  }

  document.getElementById('addItem').addEventListener('click', function () { tbody.appendChild(rowTemplate()); recalc(); });

  function recalc() {
    var sub = 0;
    tbody.querySelectorAll('tr').forEach(function (tr) {
      var q = parseFloat(tr.querySelector('.itQty').value) || 0;
      var r = parseFloat(tr.querySelector('.itRate').value) || 0;
      var amt = q * r;
      tr.querySelector('.itAmt').textContent = amt.toFixed(2);
      sub += amt;
    });
    var disc = parseFloat(document.getElementById('discount').value) || 0;
    var trans = parseFloat(document.getElementById('transport').value) || 0;
    var gstPct = parseFloat(document.getElementById('gstPct').value) || 0;
    var taxable = Math.max(0, sub - disc + trans);
    var gst = taxable * gstPct / 100;
    var grand = taxable + gst;
    document.getElementById('sumSub').textContent = '₹' + sub.toFixed(2);
    document.getElementById('sumGst').textContent = '₹' + gst.toFixed(2);
    document.getElementById('sumGrand').textContent = '₹' + grand.toFixed(2);
  }

  document.body.addEventListener('input', function (e) {
    if (e.target.matches('.itQty,.itRate,#discount,#transport,#gstPct')) { recalc(); }
  });
  document.body.addEventListener('change', function (e) {
    if (e.target.matches('.prodSel')) {
      var tr = e.target.closest('tr');
      var opt = e.target.selectedOptions[0];
      tr.querySelector('.prodId').value = e.target.value;
      if (e.target.value) {
        tr.querySelector('.itDesc').value = opt.dataset.desc || '';
        tr.querySelector('.itUnit').value = opt.dataset.unit || 'piece';
        tr.querySelector('.itRate').value = opt.dataset.rate || 0;
        tr.querySelector('.itHsn').value = opt.dataset.hsn || '';
      }
      recalc();
    }
  });
  document.body.addEventListener('click', function (e) {
    var btn = e.target.closest('.rmItem');
    if (btn) { var rows = tbody.querySelectorAll('tr'); if (rows.length > 1) { btn.closest('tr').remove(); recalc(); } }
  });
  recalc();
})();
</script>
<?php tl_shell_bottom(); ?>