<?php
/**
 * bamboo-quote.php -> https://biomeenterprises.com/bamboo-quote?t=<token>
 * Private link sent to the customer. They can read the quotation, accept it, ask for changes,
 * print the quotation / invoice and follow the order. The 160-bit token is the only key.
 */
declare(strict_types=1);

require_once __DIR__ . '/admin/config/database.php';
require_once __DIR__ . '/admin/includes/bamboo_lib.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

$pdo = get_db();
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$token = preg_match('/^[a-f0-9]{40}$/', $token) ? $token : '';
$o = null;
if ($token !== '') {
    $s = $pdo->prepare('SELECT * FROM bamboo_orders WHERE public_token = :t AND deleted_at IS NULL LIMIT 1');
    $s->execute([':t' => $token]);
    $o = $s->fetch() ?: null;
}
if (!$o) {
    http_response_code(404);
}
$co = tl_settings()['company'];
$bb = bb_settings();
$notice = '';
$noticeType = 'success';

if (empty($_SESSION['bq_csrf'])) {
    $_SESSION['bq_csrf'] = bin2hex(random_bytes(32));
}

/* ---------- printable documents (same template the admin uses) ---------- */
if ($o && isset($_GET['doc']) && in_array($_GET['doc'], ['quote', 'invoice'], true)) {
    $isInvoice = $_GET['doc'] === 'invoice';
    if ($isInvoice && (!$o['invoice_number'] || !in_array($o['status'], ['dispatched', 'delivered'], true))) {
        http_response_code(404);
        exit('Invoice not available yet.');
    }
    $it = $pdo->prepare('SELECT * FROM bamboo_order_items WHERE order_id = :id ORDER BY sort_order, id');
    $it->execute([':id' => $o['id']]);
    $items = $it->fetchAll();
    $payments = [];
    if ($isInvoice) {
        $p = $pdo->prepare('SELECT * FROM bamboo_payments WHERE order_id = :id ORDER BY payment_date ASC, id ASC');
        $p->execute([':id' => $o['id']]);
        $payments = $p->fetchAll();
    }
    $toolbarHtml = '<div class="grp"><a class="tbtn alt" href="bamboo-quote?t=' . tl_e($token) . '">&larr; Back</a></div><div class="grp"><button class="tbtn" type="button" onclick="window.print()">Print / Save PDF</button></div>';
    require __DIR__ . '/admin/includes/bamboo_document_template.php';
    exit;
}

/* ---------- customer actions ---------- */
if ($o && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!hash_equals((string) $_SESSION['bq_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $notice = 'Your session expired. Please try again.';
        $noticeType = 'danger';
    } else {
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT * FROM bamboo_orders WHERE id = :id FOR UPDATE');
            $lock->execute([':id' => $o['id']]);
            $cur = $lock->fetch();
            $expired = $cur['valid_until'] && $cur['valid_until'] < date('Y-m-d');
            if ($cur['status'] !== 'quotation') {
                $pdo->rollBack();
                $notice = 'This quotation has already been processed.';
                $noticeType = 'danger';
            } elseif ($action === 'accept') {
                if ($expired) {
                    $pdo->rollBack();
                    $notice = 'This quotation has expired. Please contact us for a fresh one.';
                    $noticeType = 'danger';
                } elseif ((float) $cur['grand_total'] <= 0) {
                    $pdo->rollBack();
                    $notice = 'This quotation cannot be accepted online. Please contact us.';
                    $noticeType = 'danger';
                } else {
                    $on = $cur['order_number'] ?: tl_next_sequence($pdo, 'bamboo_order', 'BOR');
                    $pdo->prepare("UPDATE bamboo_orders SET status = 'confirmed', order_number = :on, accepted_at = NOW(), customer_response = 'accepted', responded_at = NOW(), updated_at = NOW() WHERE id = :id")
                        ->execute([':on' => $on, ':id' => $cur['id']]);
                    bb_event($pdo, (int) $cur['id'], 'confirmed', 'Quotation accepted by customer - order confirmed', 'Order ' . $on, true, null);
                    if ($cur['enquiry_id']) {
                        $pdo->prepare("UPDATE bamboo_enquiries SET status = 'won', updated_at = NOW() WHERE id = :e")->execute([':e' => $cur['enquiry_id']]);
                    }
                    $pdo->commit();
                    $_SESSION['bq_flash'] = ['success', 'Thank you! Your order ' . $on . ' is confirmed. We will contact you about dispatch and payment.'];
                    $cur['order_number'] = $on;
                    header('Location: bamboo-quote?t=' . $token);
                    session_write_close();
                    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
                    elseif (function_exists('litespeed_finish_request')) { litespeed_finish_request(); }
                    try { bb_mail_admin_response($cur, 'accepted'); } catch (Throwable $e) { error_log('bq mail: ' . $e->getMessage()); }
                    exit;
                }
            } elseif ($action === 'changes') {
                $msg = mb_substr(trim(str_replace("\0", '', (string) ($_POST['message'] ?? ''))), 0, 1000);
                if (mb_strlen($msg) < 5) {
                    $pdo->rollBack();
                    $notice = 'Please tell us what you would like changed.';
                    $noticeType = 'danger';
                } else {
                    $pdo->prepare("UPDATE bamboo_orders SET customer_response = 'changes', customer_message = :m, responded_at = NOW(), updated_at = NOW() WHERE id = :id")
                        ->execute([':m' => $msg, ':id' => $cur['id']]);
                    bb_event($pdo, (int) $cur['id'], 'changes', 'Customer asked for changes', $msg, false, null);
                    $pdo->commit();
                    $_SESSION['bq_flash'] = ['success', 'Thank you. We have received your request and will send a revised quotation shortly.'];
                    header('Location: bamboo-quote?t=' . $token);
                    session_write_close();
                    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
                    elseif (function_exists('litespeed_finish_request')) { litespeed_finish_request(); }
                    try { bb_mail_admin_response($cur, 'changes', $msg); } catch (Throwable $e) { error_log('bq mail: ' . $e->getMessage()); }
                    exit;
                }
            } else {
                $pdo->rollBack();
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('bamboo-quote: ' . $e->getMessage());
            $notice = 'Something went wrong. Please try again or call us.';
            $noticeType = 'danger';
        }
    }
    $s = $pdo->prepare('SELECT * FROM bamboo_orders WHERE id = :id');
    $s->execute([':id' => $o['id']]);
    $o = $s->fetch();
}
if (!empty($_SESSION['bq_flash'])) {
    [$noticeType, $notice] = $_SESSION['bq_flash'];
    unset($_SESSION['bq_flash']);
}

$items = $events = $payments = [];
$truck = null;
if ($o) {
    if (!$o['quote_viewed_at'] && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $pdo->prepare('UPDATE bamboo_orders SET quote_viewed_at = NOW() WHERE id = :id AND quote_viewed_at IS NULL')->execute([':id' => $o['id']]);
    }
    $it = $pdo->prepare('SELECT * FROM bamboo_order_items WHERE order_id = :id ORDER BY sort_order, id');
    $it->execute([':id' => $o['id']]);
    $items = $it->fetchAll();
    $ev = $pdo->prepare('SELECT title, description, created_at FROM bamboo_order_events WHERE order_id = :id AND customer_visible = 1 ORDER BY id DESC');
    $ev->execute([':id' => $o['id']]);
    $events = $ev->fetchAll();
    if ($o['transport_booking_id']) {
        $tb = $pdo->prepare('SELECT tracking_id FROM transport_bookings WHERE id = :id');
        $tb->execute([':id' => $o['transport_booking_id']]);
        $truck = $tb->fetchColumn() ?: null;
    }
}

$tax = $o ? bb_tax_split($o) : null;
$steps = ['quotation' => 'Quotation', 'confirmed' => 'Confirmed', 'processing' => 'Processing', 'dispatched' => 'Dispatched', 'delivered' => 'Delivered'];
$order = array_keys($steps);
$curIdx = $o ? array_search($o['status'], $order, true) : false;
$cancelled = $o && $o['status'] === 'cancelled';
$expired = $o && $o['status'] === 'quotation' && $o['valid_until'] && $o['valid_until'] < date('Y-m-d');
$balance = $o ? max(0.0, (float) $o['grand_total'] - (float) $o['paid_amount']) : 0.0;
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= $o ? 'Quotation ' . tl_e((string) $o['quote_number']) : 'Quotation not found' ?> | <?= tl_e($co['name']) ?></title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">
    <link href="lib/animate/animate.min.css" rel="stylesheet">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <style>
        body{font-family:Inter,sans-serif;background:#f4f8f6}
        .bq-hero{background:linear-gradient(135deg,#0f4c2d,#198754);color:#fff;padding:48px 0 64px}
        .bq-hero h1{font-weight:800;margin:6px 0 4px}.bq-eyebrow{text-transform:uppercase;letter-spacing:.12em;font-size:.78rem;color:#ffd666;font-weight:700}
        .bq-card{background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(15,76,45,.1);padding:26px;margin-bottom:24px}
        .bq-card.pull{margin-top:-36px}
        .bq-steps{display:flex;justify-content:space-between;position:relative;margin:22px 0 6px}
        .bq-steps:before{content:'';position:absolute;top:17px;left:6%;right:6%;height:4px;background:#dfe9e3}
        .bq-step{position:relative;text-align:center;flex:1;font-size:.78rem;color:#6b7a72}
        .bq-step .d{width:36px;height:36px;border-radius:50%;background:#dfe9e3;color:#fff;margin:0 auto 8px;display:flex;align-items:center;justify-content:center;position:relative;z-index:1}
        .bq-step.done .d{background:#198754}.bq-step.now .d{background:#f0a500;box-shadow:0 0 0 5px rgba(240,165,0,.25)}.bq-step.done,.bq-step.now{color:#14181a;font-weight:600}
        .bq-tbl{width:100%;border-collapse:collapse;font-size:.92rem}.bq-tbl th{background:#f1f6f3;text-align:left;padding:10px;font-size:.74rem;text-transform:uppercase;letter-spacing:.05em}
        .bq-tbl td{padding:10px;border-bottom:1px solid #e6eee9}.bq-tbl .r{text-align:right}
        .bq-tot td{border:0;padding:4px 10px}.bq-tot .grand td{font-size:1.1rem;font-weight:700;border-top:2px solid #0f4c2d;padding-top:10px}
        .bq-tl{border-left:2px solid #dfe9e3;margin-left:6px;padding-left:20px}.bq-tl .e{position:relative;padding-bottom:16px}
        .bq-tl .e:before{content:'';position:absolute;left:-27px;top:4px;width:12px;height:12px;border-radius:50%;background:#198754;border:2px solid #fff;box-shadow:0 0 0 2px #198754}
        @media(max-width:640px){.bq-step span{display:none}.bq-card{padding:18px}}
    </style>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>

<?php if (!$o): ?>
<section class="bq-hero"><div class="container"><div class="bq-eyebrow">Bamboo Trading</div><h1>Quotation not found</h1></div></section>
<div class="container pb-5"><div class="bq-card pull text-center"><i class="fa fa-link fa-2x mb-3" style="color:#198754"></i>
    <p class="mb-3">This link is not valid. Please use the exact link we sent you, or contact us.</p>
    <a class="btn btn-success rounded-pill" href="bamboo-trading#enquiry">Send an enquiry</a></div></div>
<?php else: ?>
<section class="bq-hero">
    <div class="container">
        <div class="bq-eyebrow">Bamboo Trading &middot; <?= $o['order_number'] ? 'Order ' . tl_e((string) $o['order_number']) : 'Quotation' ?></div>
        <h1><?= $o['order_number'] ? 'Your order' : 'Your quotation' ?> <?= tl_e((string) ($o['order_number'] ?: $o['quote_number'])) ?></h1>
        <div style="opacity:.85">Prepared for <?= tl_e((string) ($o['company_name'] ?: $o['customer_name'])) ?></div>
    </div>
</section>
<div class="container pb-5">
    <?php if ($notice): ?><div class="alert alert-<?= $noticeType === 'danger' ? 'danger' : 'success' ?> pull" style="margin-top:-30px;position:relative;z-index:2"><?= tl_e($notice) ?></div><?php endif; ?>

    <div class="bq-card <?= $notice ? '' : 'pull' ?>">
        <?php if ($cancelled): ?>
            <div class="alert alert-danger mb-0">This order was cancelled. Please contact us on <?= tl_e((string) $bb['support_phone']) ?> if you have questions.</div>
        <?php elseif ($curIdx !== false): ?>
            <div class="bq-steps">
            <?php foreach ($steps as $k => $label): $i = array_search($k, $order, true); $cls = $i < $curIdx ? 'done' : ($i === $curIdx ? 'now' : ''); ?>
                <div class="bq-step <?= $cls ?>"><div class="d"><i class="fa <?= $i < $curIdx || ($i === $curIdx && $k === 'delivered') ? 'fa-check' : 'fa-circle' ?>" style="font-size:.8rem"></i></div><span><?= tl_e($label) ?></span></div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="bq-card">
                <div class="d-flex flex-wrap justify-content-between mb-3">
                    <div><div class="text-muted small">Quotation no.</div><strong><?= tl_e((string) $o['quote_number']) ?></strong></div>
                    <div><div class="text-muted small">Date</div><strong><?= tl_e(date('d M Y', strtotime((string) $o['created_at']))) ?></strong></div>
                    <?php if ($o['valid_until'] && $o['status'] === 'quotation'): ?><div><div class="text-muted small">Valid until</div><strong class="<?= $expired ? 'text-danger' : '' ?>"><?= tl_e(date('d M Y', strtotime((string) $o['valid_until']))) ?><?= $expired ? ' (expired)' : '' ?></strong></div><?php endif; ?>
                </div>
                <div style="overflow-x:auto"><table class="bq-tbl"><thead><tr><th>Item</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th></tr></thead><tbody>
                <?php foreach ($items as $it): ?><tr><td><?= tl_e((string) $it['description']) ?></td><td class="r"><?= tl_e(bb_num($it['quantity'])) ?> <?= tl_e(bb_units()[$it['unit']] ?? (string) $it['unit']) ?></td><td class="r"><?= tl_e(number_format((float) $it['rate'], 2)) ?></td><td class="r"><?= tl_e(number_format((float) $it['amount'], 2)) ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
                <table class="bq-tot" style="margin-left:auto;margin-top:10px;min-width:280px">
                    <tr><td>Subtotal</td><td class="r"><?= tl_e(tl_inr($o['subtotal'])) ?></td></tr>
                    <?php if ((float) $o['discount'] > 0): ?><tr><td>Discount</td><td class="r">-<?= tl_e(tl_inr($o['discount'])) ?></td></tr><?php endif; ?>
                    <?php if ((float) $o['transport_charge'] > 0): ?><tr><td>Transport</td><td class="r"><?= tl_e(tl_inr($o['transport_charge'])) ?></td></tr><?php endif; ?>
                    <?php if ($tax['type'] === 'intra'): ?><tr><td>CGST <?= tl_e(bb_num($tax['half'])) ?>%</td><td class="r"><?= tl_e(tl_inr($tax['cgst'])) ?></td></tr><tr><td>SGST <?= tl_e(bb_num($tax['half'])) ?>%</td><td class="r"><?= tl_e(tl_inr($tax['sgst'])) ?></td></tr>
                    <?php else: ?><tr><td>IGST <?= tl_e(bb_num($tax['pct'])) ?>%</td><td class="r"><?= tl_e(tl_inr($tax['igst'])) ?></td></tr><?php endif; ?>
                    <tr class="grand"><td>Total</td><td class="r"><?= tl_e(tl_inr($o['grand_total'])) ?></td></tr>
                    <?php if ((float) $o['paid_amount'] > 0): ?><tr><td>Paid</td><td class="r"><?= tl_e(tl_inr($o['paid_amount'])) ?></td></tr><tr><td><strong>Balance</strong></td><td class="r"><strong><?= tl_e(tl_inr($balance)) ?></strong></td></tr><?php endif; ?>
                </table>
                <p class="text-muted small mt-2 mb-0"><?= tl_e(tl_amount_in_words((float) $o['grand_total'])) ?></p>
                <?php if ($o['delivery_address'] || $o['delivery_city']): ?><hr><div class="small"><span class="text-muted">Deliver to:</span> <?= tl_e(trim(implode(', ', array_filter([(string) $o['delivery_address'], (string) $o['delivery_city'], (string) $o['delivery_state'], (string) $o['delivery_pincode']])))) ?></div><?php endif; ?>
                <?php if (!empty($bb['terms'])): ?><hr><div class="small text-muted"><strong>Terms</strong><ol class="mb-0 ps-3"><?php foreach ((array) $bb['terms'] as $t): ?><li><?= tl_e((string) $t) ?></li><?php endforeach; ?></ol></div><?php endif; ?>
                <div class="mt-3 d-flex flex-wrap gap-2">
                    <a class="btn btn-outline-success btn-sm rounded-pill" target="_blank" rel="noopener" href="bamboo-quote?t=<?= tl_e($token) ?>&amp;doc=quote"><i class="fa fa-print me-1"></i> Print quotation</a>
                    <?php if ($o['invoice_number'] && in_array($o['status'], ['dispatched', 'delivered'], true)): ?><a class="btn btn-outline-success btn-sm rounded-pill" target="_blank" rel="noopener" href="bamboo-quote?t=<?= tl_e($token) ?>&amp;doc=invoice"><i class="fa fa-file-invoice me-1"></i> Tax invoice</a><?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <?php if ($o['status'] === 'quotation'): ?>
            <div class="bq-card">
                <h5 class="mb-3">Your response</h5>
                <?php if ($expired): ?>
                    <div class="alert alert-warning mb-0">This quotation has expired. Please contact us for an updated one.</div>
                <?php else: ?>
                    <form method="post" onsubmit="return confirm('Accept this quotation and confirm the order?');">
                        <input type="hidden" name="t" value="<?= tl_e($token) ?>"><input type="hidden" name="csrf" value="<?= tl_e((string) $_SESSION['bq_csrf']) ?>"><input type="hidden" name="action" value="accept">
                        <button class="btn btn-success w-100 rounded-pill py-2 mb-3" type="submit"><i class="fa fa-check me-2"></i>Accept &amp; confirm order</button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="t" value="<?= tl_e($token) ?>"><input type="hidden" name="csrf" value="<?= tl_e((string) $_SESSION['bq_csrf']) ?>"><input type="hidden" name="action" value="changes">
                        <label class="form-label small fw-bold" for="bqMsg">Need something changed?</label>
                        <textarea id="bqMsg" name="message" class="form-control mb-2" rows="3" maxlength="1000" placeholder="Quantity, rate, delivery date..."></textarea>
                        <button class="btn btn-outline-success w-100 rounded-pill" type="submit">Request changes</button>
                    </form>
                    <?php if ($o['customer_response'] === 'changes'): ?><p class="small text-muted mt-3 mb-0"><i class="fa fa-info-circle me-1"></i>We have your earlier change request and will revert shortly.</p><?php endif; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($truck): ?><div class="bq-card"><h6>Shipment tracking</h6><p class="small mb-2">Tracking ID <strong><?= tl_e((string) $truck) ?></strong></p><a class="btn btn-success btn-sm rounded-pill" href="track?id=<?= tl_e(rawurlencode((string) $truck)) ?>"><i class="fa fa-truck me-1"></i> Track shipment</a></div><?php endif; ?>

            <div class="bq-card">
                <h5 class="mb-3">Updates</h5>
                <div class="bq-tl"><?php foreach ($events as $e): ?><div class="e"><div class="text-muted small"><?= tl_e(date('d M Y, h:i A', strtotime((string) $e['created_at']))) ?></div><strong><?= tl_e((string) $e['title']) ?></strong><?php if ($e['description']): ?><div class="text-muted small"><?= tl_e((string) $e['description']) ?></div><?php endif; ?></div><?php endforeach; ?></div>
                <hr>
                <a class="btn btn-success btn-sm rounded-pill" href="tel:<?= tl_e(preg_replace('/\D/', '', (string) $bb['support_phone']) ?? '') ?>"><i class="fa fa-phone me-1"></i> Call us</a>
                <a class="btn btn-outline-success btn-sm rounded-pill" target="_blank" rel="noopener" href="https://wa.me/<?= tl_e((string) $bb['whatsapp_number']) ?>?text=<?= tl_e(rawurlencode('Hello, regarding ' . ($o['order_number'] ?: $o['quote_number']))) ?>"><i class="fab fa-whatsapp me-1"></i> WhatsApp</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
