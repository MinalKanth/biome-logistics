<?php
/**
 * admin/includes/bamboo_lib.php - shared helpers for the Bamboo Trading module.
 * Builds on transport_lib.php (settings, mail, sequences, amount-in-words).
 * Works from admin pages AND public pages (needs only get_db()).
 */
declare(strict_types=1);

require_once __DIR__ . '/transport_lib.php';

if (defined('BB_LIB_LOADED')) {
    return;
}
define('BB_LIB_LOADED', true);

/* -------------------------------------------------------------
   Settings: transport_settings.php defaults, overridden by values
   saved in Bamboo > Settings (bamboo_settings table).
------------------------------------------------------------- */
function bb_settings(bool $refresh = false): array
{
    static $b = null;
    if ($b !== null && !$refresh) {
        return $b;
    }
    $def = [
        'hsn' => '1401', 'default_gst' => 5, 'quote_valid_days' => 15, 'payment_due_days' => 15,
        'dispatch_address' => '', 'dispatch_city' => '', 'dispatch_state' => 'Assam', 'terms' => [],
        'notify_email' => '', 'whatsapp_number' => '919678431656', 'support_phone' => '+91 96784 31656',
    ];
    $loaded = tl_settings()['bamboo'] ?? [];
    $b = array_merge($def, is_array($loaded) ? $loaded : []);
    if (function_exists('get_db')) {
        try {
            foreach (get_db()->query('SELECT setting_key, setting_value FROM bamboo_settings') as $r) {
                $k = (string) $r['setting_key'];
                $v = (string) $r['setting_value'];
                if ($k === 'terms') {
                    $b['terms'] = array_values(array_filter(array_map('trim', preg_split('/\R/', $v) ?: [])));
                } elseif (in_array($k, ['default_gst', 'quote_valid_days', 'payment_due_days'], true)) {
                    $b[$k] = (float) $v;
                } else {
                    $b[$k] = $v;
                }
            }
        } catch (Throwable $e) {
            // table not installed yet: defaults are used
        }
    }
    return $b;
}

function bb_setting_save(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare('INSERT INTO bamboo_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW())
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()')
        ->execute([':k' => $key, ':v' => $value]);
}

/* -------------------------------------------------------------
   Static lists and workflow
------------------------------------------------------------- */
function bb_enquiry_statuses(): array
{
    return [
        'new'         => ['label' => 'New',         'class' => 'info'],
        'contacted'   => ['label' => 'Contacted',   'class' => 'warning'],
        'quoted'      => ['label' => 'Quoted',      'class' => 'warning'],
        'negotiation' => ['label' => 'Negotiation', 'class' => 'warning'],
        'won'         => ['label' => 'Won',         'class' => 'success'],
        'lost'        => ['label' => 'Lost',        'class' => 'danger'],
        'closed'      => ['label' => 'Closed',      'class' => 'muted'],   // legacy value from the old enquiry list
    ];
}

function bb_order_statuses(): array
{
    return [
        'quotation'  => ['label' => 'Quotation',  'class' => 'muted',   'icon' => 'fa-file-lines'],
        'confirmed'  => ['label' => 'Confirmed',  'class' => 'info',    'icon' => 'fa-circle-check'],
        'processing' => ['label' => 'Processing', 'class' => 'warning', 'icon' => 'fa-gears'],
        'dispatched' => ['label' => 'Dispatched', 'class' => 'warning', 'icon' => 'fa-truck-fast'],
        'delivered'  => ['label' => 'Delivered',  'class' => 'success', 'icon' => 'fa-box-open'],
        'cancelled'  => ['label' => 'Cancelled',  'class' => 'danger',  'icon' => 'fa-ban'],
    ];
}

/** Allowed next statuses. Nothing can skip ahead or move backwards by accident. */
function bb_order_flow(): array
{
    return [
        'quotation'  => ['confirmed', 'cancelled'],
        'confirmed'  => ['processing', 'dispatched', 'cancelled'],
        'processing' => ['dispatched', 'cancelled'],
        'dispatched' => ['delivered', 'cancelled'],
        'delivered'  => [],
        'cancelled'  => ['quotation'],
    ];
}

function bb_units(): array
{
    return ['piece' => 'Piece', 'bundle' => 'Bundle', 'ft' => 'Running ft', 'kg' => 'Kg', 'ton' => 'Ton', 'sqft' => 'Sq ft', 'set' => 'Set', 'nos' => 'Nos'];
}

function bb_payment_modes(): array
{
    return ['cash' => 'Cash', 'upi' => 'UPI', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'card' => 'Card', 'other' => 'Other'];
}

function bb_num($v): string
{
    $s = number_format((float) $v, 2, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}

function bb_valid_date(?string $d): ?string
{
    if ($d === null || $d === '') {
        return null;
    }
    $x = DateTime::createFromFormat('!Y-m-d', $d);
    return ($x && $x->format('Y-m-d') === $d) ? $d : null;
}

function bb_wa_link(?string $phone, string $text = ''): string
{
    $d = preg_replace('/\D/', '', (string) $phone) ?? '';
    if (strlen($d) === 10) {
        $d = '91' . $d;
    }
    return 'https://wa.me/' . $d . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/**
 * Build "(a LIKE :q0 OR b LIKE :q1 ...)" with one placeholder per column.
 * (Your database setting uses native prepared statements, which refuse a repeated :name.)
 */
function bb_like(array $cols, string $q, array &$par, string $prefix = 'q'): string
{
    $parts = [];
    foreach (array_values($cols) as $i => $c) {
        $parts[] = "$c LIKE :$prefix$i";
        $par[":$prefix$i"] = '%' . $q . '%';
    }
    return '(' . implode(' OR ', $parts) . ')';
}

/** Export helper: stops spreadsheet formula injection in CSV cells. */
function bb_csv_safe($v)
{
    return (is_string($v) && $v !== '' && strpos('=+-@', $v[0]) !== false) ? "'" . $v : $v;
}

/* -------------------------------------------------------------
   Money & GST
------------------------------------------------------------- */
/** items = [['quantity'=>, 'rate'=>], ...]  ->  totals (discount can never exceed the subtotal) */
function bb_totals(array $items, float $discount, float $transport, float $gstPct): array
{
    $sub = 0.0;
    foreach ($items as $it) {
        $sub += round((float) $it['quantity'] * (float) $it['rate'], 2);
    }
    $sub      = round($sub, 2);
    $discount = min(max(0.0, $discount), $sub);
    $taxable  = max(0.0, round($sub - $discount + $transport, 2));
    $gst      = round($taxable * $gstPct / 100, 2);
    return ['subtotal' => $sub, 'discount' => $discount, 'transport' => $transport, 'taxable' => $taxable,
            'gst' => $gst, 'grand' => round($taxable + $gst, 2)];
}

/** Intra-state (CGST+SGST) when the goods are delivered in your own state, otherwise inter-state (IGST). */
function bb_tax_type(array $o): string
{
    $own = strtolower(trim((string) (tl_settings()['company']['state'] ?? bb_settings()['dispatch_state'])));
    $to  = strtolower(trim((string) ($o['delivery_state'] ?? '')));
    return ($to === '' || $to === $own) ? 'intra' : 'inter';
}

function bb_tax_split(array $o): array
{
    $gst = (float) $o['gst_amount'];
    $pct = (float) $o['gst_pct'];
    if (bb_tax_type($o) === 'inter') {
        return ['type' => 'inter', 'igst' => $gst, 'cgst' => 0.0, 'sgst' => 0.0, 'half' => 0.0, 'pct' => $pct];
    }
    $c = round($gst / 2, 2);
    return ['type' => 'intra', 'igst' => 0.0, 'cgst' => $c, 'sgst' => round($gst - $c, 2), 'half' => $pct / 2, 'pct' => $pct];
}

function bb_pay_status(float $grand, float $paid): string
{
    return tl_payment_status_for($grand, $paid);
}

/** Recompute paid / balance / payment_status from the payments table (refunds subtract). */
function bb_recalc_payments(PDO $pdo, int $orderId): void
{
    $s = $pdo->prepare('SELECT grand_total FROM bamboo_orders WHERE id = :id');
    $s->execute([':id' => $orderId]);
    $grand = (float) $s->fetchColumn();
    $p = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type = 'refund' THEN -amount ELSE amount END),0) FROM bamboo_payments WHERE order_id = :id");
    $p->execute([':id' => $orderId]);
    $paid = round((float) $p->fetchColumn(), 2);
    $pdo->prepare('UPDATE bamboo_orders SET paid_amount = :p, balance_amount = :b, payment_status = :s, updated_at = NOW() WHERE id = :id')
        ->execute([':p' => $paid, ':b' => round($grand - $paid, 2), ':s' => bb_pay_status($grand, $paid), ':id' => $orderId]);
}

/* -------------------------------------------------------------
   Stock (every movement is written to bamboo_stock_ledger)
------------------------------------------------------------- */
/**
 * Move stock for one product and write the ledger line. Call inside a transaction.
 * @throws RuntimeException when the move would make stock negative (unless $force)
 */
function bb_stock_move(PDO $pdo, int $productId, float $delta, string $reason, ?string $refType = null, ?int $refId = null,
                       ?string $note = null, ?int $adminId = null, ?string $party = null, ?float $unitCost = null, bool $force = false): float
{
    $s = $pdo->prepare('SELECT stock_qty, name FROM bamboo_products WHERE id = :id FOR UPDATE');
    $s->execute([':id' => $productId]);
    $p = $s->fetch();
    if (!$p) {
        throw new RuntimeException('Product not found.');
    }
    $new = round((float) $p['stock_qty'] + $delta, 2);
    if ($new < 0 && !$force) {
        throw new RuntimeException('Not enough stock of ' . $p['name'] . ' (available ' . bb_num($p['stock_qty']) . ').');
    }
    $pdo->prepare('UPDATE bamboo_products SET stock_qty = :q, updated_at = NOW() WHERE id = :id')->execute([':q' => $new, ':id' => $productId]);
    $pdo->prepare('INSERT INTO bamboo_stock_ledger (product_id, qty_change, balance_after, reason, ref_type, ref_id, party, unit_cost, note, created_by, created_at)
                   VALUES (:p, :c, :b, :r, :rt, :ri, :pa, :uc, :n, :a, NOW())')
        ->execute([':p' => $productId, ':c' => $delta, ':b' => $new, ':r' => $reason, ':rt' => $refType, ':ri' => $refId,
                   ':pa' => $party, ':uc' => $unitCost, ':n' => $note, ':a' => $adminId]);
    return $new;
}

/** Tracked products on an order that do not have enough stock: [[name, stock_qty, need], ...] */
function bb_stock_shortages(PDO $pdo, int $orderId): array
{
    $s = $pdo->prepare('SELECT p.name, p.stock_qty, SUM(i.quantity) need
                        FROM bamboo_order_items i JOIN bamboo_products p ON p.id = i.product_id
                        WHERE i.order_id = :o AND p.track_stock = 1 GROUP BY p.id, p.name, p.stock_qty HAVING SUM(i.quantity) > p.stock_qty');
    $s->execute([':o' => $orderId]);
    return $s->fetchAll();
}

/** Deduct (dispatch) or restore (cancel after dispatch) stock for tracked products. Call inside a transaction. */
function bb_apply_stock(PDO $pdo, int $orderId, bool $deduct, ?int $adminId = null, string $label = ''): void
{
    $it = $pdo->prepare('SELECT i.product_id, SUM(i.quantity) q FROM bamboo_order_items i JOIN bamboo_products p ON p.id = i.product_id
                         WHERE i.order_id = :o AND i.product_id IS NOT NULL AND p.track_stock = 1 GROUP BY i.product_id');
    $it->execute([':o' => $orderId]);
    foreach ($it->fetchAll() as $r) {
        bb_stock_move($pdo, (int) $r['product_id'], $deduct ? -(float) $r['q'] : (float) $r['q'],
            $deduct ? 'sale' : 'sale_reversal', 'order', $orderId, $label !== '' ? $label : null, $adminId);
    }
}

/* -------------------------------------------------------------
   Order timeline
------------------------------------------------------------- */
function bb_event(PDO $pdo, int $orderId, string $event, string $title, ?string $desc = null, bool $visible = true, ?int $adminId = null): void
{
    $pdo->prepare('INSERT INTO bamboo_order_events (order_id, event, title, description, customer_visible, created_by, created_at)
                   VALUES (:o, :e, :t, :d, :v, :a, NOW())')
        ->execute([':o' => $orderId, ':e' => $event, ':t' => $title, ':d' => ($desc !== null && $desc !== '') ? $desc : null, ':v' => $visible ? 1 : 0, ':a' => $adminId]);
}

function bb_public_url(string $token): string
{
    return rtrim(tl_site_url(), '/') . '/bamboo-quote?t=' . rawurlencode($token);
}

/* -------------------------------------------------------------
   E-mail (skipped silently when SMTP is not configured)
------------------------------------------------------------- */
function bb_notify_to(): string
{
    $to = (string) (bb_settings()['notify_email'] ?? '');
    if ($to === '') {
        $m = tl_settings()['mail'];
        $to = (string) ($m['notify_to'] ?: $m['from_email']);
    }
    return $to;
}

function bb_mail_admin_enquiry(array $e): bool
{
    $to = bb_notify_to();
    if ($to === '') {
        return false;
    }
    $rows = '<table cellpadding="6" style="font-size:14px;border-collapse:collapse">'
        . '<tr><td><b>Reference</b></td><td>' . tl_e((string) $e['reference']) . '</td></tr>'
        . '<tr><td><b>Customer</b></td><td>' . tl_e((string) $e['full_name']) . ' / ' . tl_e((string) $e['mobile_number']) . '</td></tr>'
        . '<tr><td><b>Company</b></td><td>' . tl_e((string) ($e['company_name'] ?? '-')) . '</td></tr>'
        . '<tr><td><b>Products</b></td><td>' . tl_e((string) ($e['products_selected'] ?? '-')) . '</td></tr>'
        . '<tr><td><b>Quantity</b></td><td>' . tl_e((string) ($e['quantity_required'] ?? '-')) . '</td></tr>'
        . '<tr><td><b>Deliver to</b></td><td>' . tl_e((string) ($e['delivery_location'] ?? '-')) . '</td></tr></table>';
    return tl_send_mail($to, 'Biome Admin', 'New bamboo enquiry ' . $e['reference'],
        tl_mail_shell('New bamboo enquiry', $rows, rtrim(tl_site_url(), '/') . '/admin/bamboo_enquiries.php?q=' . rawurlencode((string) $e['reference']), 'Open in admin'));
}

function bb_mail_customer_enquiry(array $e): bool
{
    if (empty($e['email'])) {
        return false;
    }
    $body = '<p>Hi ' . tl_e((string) $e['full_name']) . ',</p><p>Thank you for your bamboo enquiry <b>' . tl_e((string) $e['reference'])
        . '</b>. Our team will contact you shortly with availability and a quotation.</p>';
    return tl_send_mail((string) $e['email'], (string) $e['full_name'], 'We received your bamboo enquiry ' . $e['reference'], tl_mail_shell('Enquiry received', $body));
}

function bb_mail_customer_quote(array $o): bool
{
    if (empty($o['email'])) {
        return false;
    }
    $body = '<p>Hi ' . tl_e((string) $o['customer_name']) . ',</p><p>Please find your quotation <b>' . tl_e((string) $o['quote_number'])
        . '</b> for <b>' . tl_e(tl_inr($o['grand_total'])) . '</b>'
        . ($o['valid_until'] ? ' (valid until ' . tl_e(date('d M Y', strtotime((string) $o['valid_until']))) . ')' : '')
        . '. You can review it and accept it online using the button below.</p>';
    return tl_send_mail((string) $o['email'], (string) $o['customer_name'], 'Quotation ' . $o['quote_number'] . ' from ' . tl_settings()['company']['name'],
        tl_mail_shell('Your quotation', $body, bb_public_url((string) $o['public_token']), 'View & accept quotation'));
}

function bb_mail_admin_response(array $o, string $kind, string $message = ''): bool
{
    $to = bb_notify_to();
    if ($to === '') {
        return false;
    }
    $what = $kind === 'accepted' ? 'accepted the quotation' : 'asked for changes to the quotation';
    $body = '<p><b>' . tl_e((string) $o['customer_name']) . '</b> (' . tl_e((string) $o['phone']) . ') has ' . $what . ' <b>' . tl_e((string) $o['quote_number']) . '</b> ('
        . tl_e(tl_inr($o['grand_total'])) . ').</p>' . ($message !== '' ? '<p>Message: ' . tl_e($message) . '</p>' : '');
    return tl_send_mail($to, 'Biome Admin', 'Customer ' . $kind . ': ' . $o['quote_number'],
        tl_mail_shell('Quotation ' . $kind, $body, rtrim(tl_site_url(), '/') . '/admin/bamboo_order_view.php?id=' . (int) $o['id'], 'Open order'));
}
