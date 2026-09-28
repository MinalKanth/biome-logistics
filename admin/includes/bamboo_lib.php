<?php
/**
 * admin/includes/bamboo_lib.php - shared helpers for the Bamboo Trading module.
 * Builds on transport_lib.php (settings, mail, sequences, amount-in-words).
 */
declare(strict_types=1);

require_once __DIR__ . '/transport_lib.php';

if (defined('BB_LIB_LOADED')) {
    return;
}
define('BB_LIB_LOADED', true);

function bb_settings(): array
{
    static $b = null;
    if ($b !== null) {
        return $b;
    }
    $def = [
        'hsn' => '1401', 'default_gst' => 5, 'quote_valid_days' => 15,
        'dispatch_address' => '', 'dispatch_city' => '', 'dispatch_state' => 'Assam', 'terms' => [],
    ];
    $loaded = tl_settings()['bamboo'] ?? [];
    return $b = array_merge($def, is_array($loaded) ? $loaded : []);
}

function bb_enquiry_statuses(): array
{
    return [
        'new'         => ['label' => 'New',         'class' => 'info'],
        'contacted'   => ['label' => 'Contacted',   'class' => 'warning'],
        'quoted'      => ['label' => 'Quoted',      'class' => 'warning'],
        'negotiation' => ['label' => 'Negotiation', 'class' => 'warning'],
        'won'         => ['label' => 'Won',         'class' => 'success'],
        'lost'        => ['label' => 'Lost',        'class' => 'danger'],
    ];
}

function bb_order_statuses(): array
{
    return [
        'quotation'  => ['label' => 'Quotation',  'class' => 'muted'],
        'confirmed'  => ['label' => 'Confirmed',  'class' => 'info'],
        'processing' => ['label' => 'Processing', 'class' => 'warning'],
        'dispatched' => ['label' => 'Dispatched', 'class' => 'warning'],
        'delivered'  => ['label' => 'Delivered',  'class' => 'success'],
        'cancelled'  => ['label' => 'Cancelled',  'class' => 'danger'],
    ];
}

function bb_units(): array
{
    return ['piece' => 'Piece', 'bundle' => 'Bundle', 'ft' => 'Running ft', 'kg' => 'Kg', 'ton' => 'Ton', 'sqft' => 'Sq ft', 'set' => 'Set', 'nos' => 'Nos'];
}

function bb_num($v): string
{
    $s = number_format((float) $v, 2, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}

function bb_wa_link(?string $phone, string $text = ''): string
{
    $d = preg_replace('/\D/', '', (string) $phone) ?? '';
    if (strlen($d) === 10) {
        $d = '91' . $d;
    }
    return 'https://wa.me/' . $d . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/** items = [['quantity'=>, 'rate'=>], ...]  ->  totals */
function bb_totals(array $items, float $discount, float $transport, float $gstPct): array
{
    $sub = 0.0;
    foreach ($items as $it) {
        $sub += round((float) $it['quantity'] * (float) $it['rate'], 2);
    }
    $sub     = round($sub, 2);
    $taxable = max(0.0, round($sub - $discount + $transport, 2));
    $gst     = round($taxable * $gstPct / 100, 2);
    return ['subtotal' => $sub, 'discount' => $discount, 'transport' => $transport, 'taxable' => $taxable,
            'gst' => $gst, 'grand' => round($taxable + $gst, 2)];
}

/** Payment status from paid vs grand */
function bb_pay_status(float $grand, float $paid): string
{
    return tl_payment_status_for($grand, $paid);
}

/** Deduct or restore stock for tracked products. Call inside a transaction. */
function bb_apply_stock(PDO $pdo, int $orderId, bool $deduct): void
{
    $it = $pdo->prepare('SELECT product_id, quantity FROM bamboo_order_items WHERE order_id = :o AND product_id IS NOT NULL');
    $it->execute([':o' => $orderId]);
    $sign = $deduct ? '-' : '+';
    $up = $pdo->prepare("UPDATE bamboo_products SET stock_qty = stock_qty $sign :q, updated_at = NOW() WHERE id = :p AND track_stock = 1");
    foreach ($it->fetchAll() as $r) {
        $up->execute([':q' => (float) $r['quantity'], ':p' => (int) $r['product_id']]);
    }
}

/** Recompute paid / balance / payment_status from the payments table. */
function bb_recalc_payments(PDO $pdo, int $orderId): void
{
    $s = $pdo->prepare('SELECT grand_total FROM bamboo_orders WHERE id = :id');
    $s->execute([':id' => $orderId]);
    $grand = (float) $s->fetchColumn();
    $p = $pdo->prepare('SELECT COALESCE(SUM(amount),0) FROM bamboo_payments WHERE order_id = :id');
    $p->execute([':id' => $orderId]);
    $paid = round((float) $p->fetchColumn(), 2);
    $pdo->prepare('UPDATE bamboo_orders SET paid_amount = :p, balance_amount = :b, payment_status = :s, updated_at = NOW() WHERE id = :id')
        ->execute([':p' => $paid, ':b' => round($grand - $paid, 2), ':s' => bb_pay_status($grand, $paid), ':id' => $orderId]);
}

function bb_public_url(string $token): string
{
    return tl_site_url() . '/bamboo-quote?t=' . rawurlencode($token);
}