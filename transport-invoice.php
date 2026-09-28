<?php
/**
 * transport-invoice.php  (site root)  ->  /transport-invoice?id=TRK-26-00001
 * ---------------------------------------------------------------
 * Customer copy of the GST invoice. It only opens after the customer has
 * unlocked the shipment on /track with the last 4 digits of their mobile number
 * (the unlock lasts 30 minutes). Otherwise the visitor is sent back to /track.
 */
declare(strict_types=1);

require_once __DIR__ . '/admin/config/database.php';
require_once __DIR__ . '/admin/includes/transport_lib.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

$tid = tl_clean_tracking_id((string) ($_GET['id'] ?? ''));
if ($tid === '' || empty($_SESSION['track_ok'][$tid]) || (int) $_SESSION['track_ok'][$tid] < time()) {
    header('Location: track' . ($tid !== '' ? '?id=' . rawurlencode($tid) : ''));
    exit;
}

try {
    $pdo = get_db();
    $s = $pdo->prepare('SELECT * FROM transport_bookings WHERE tracking_id = :t AND deleted_at IS NULL AND tracking_enabled = 1 LIMIT 1');
    $s->execute([':t' => $tid]);
    $b = $s->fetch();

    if (!$b || empty($b['invoice_number'])) {
        header('Location: track?id=' . rawurlencode($tid));
        exit;
    }

    $p = $pdo->prepare("SELECT * FROM transport_payment_history WHERE booking_id = :id AND verified = 'verified' ORDER BY payment_date ASC, id ASC");
    $p->execute([':id' => $b['id']]);
    $payments = $p->fetchAll();
} catch (Throwable $e) {
    error_log('transport-invoice.php: ' . $e->getMessage());
    http_response_code(500);
    exit('The invoice is temporarily unavailable. Please try again shortly.');
}

ob_start();
?>
<div class="grp">
  <a class="tbtn alt" href="track?id=<?= rawurlencode($tid) ?>"><i class="fa-solid fa-arrow-left"></i> Back to tracking</a>
</div>
<div class="grp">
  <button class="tbtn" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save as PDF</button>
</div>
<?php
$toolbarHtml = ob_get_clean();

require __DIR__ . '/admin/includes/invoice_template.php';
