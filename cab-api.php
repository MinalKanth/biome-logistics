<?php
/**
 * cab-api.php - public, read-only fare estimate used by the booking form on cab.php.
 * GET ?vehicle=&service=&date=&return=&km=
 * Never trusts the browser: the booking handler recalculates the fare again on submit.
 */
declare(strict_types=1);

require_once __DIR__ . '/admin/config/database.php';
require_once __DIR__ . '/admin/includes/cab_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
// simple per-session throttle: 60 lookups / 10 minutes
$now = time();
$b = $_SESSION['cab_api_rl'] ?? ['n' => 0, 's' => $now];
if ($now - $b['s'] > 600) {
    $b = ['n' => 0, 's' => $now];
}
$b['n']++;
$_SESSION['cab_api_rl'] = $b;
session_write_close();
if ($b['n'] > 60) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'Too many requests. Please wait a moment.']);
    exit;
}

try {
    $pdo = get_db();
    $S = cab_settings($pdo);
    if (($S['public_show_price'] ?? '1') !== '1') {
        echo json_encode(['ok' => false, 'error' => 'Fare on request - our team will send you a quotation.']);
        exit;
    }
    $vehicle = trim((string) ($_GET['vehicle'] ?? ''));
    $service = trim((string) ($_GET['service'] ?? ''));
    $date = cab_valid_date((string) ($_GET['date'] ?? ''));
    $ret  = cab_valid_date((string) ($_GET['return'] ?? ''));
    $kmRaw = trim((string) ($_GET['km'] ?? ''));
    $km = ($kmRaw !== '' && is_numeric($kmRaw) && (float) $kmRaw >= 0 && (float) $kmRaw <= 20000) ? (float) $kmRaw : null;

    $est = cab_estimate($pdo, $vehicle, $service, cab_days($service, $date, $ret), $km);
    if (!$est['ok']) {
        echo json_encode(['ok' => false, 'error' => $est['error']]);
        exit;
    }
    foreach ($est['lines'] as &$l) {
        $l['amount'] = round((float) $l['amount'], 2);
    }
    unset($l);
    echo json_encode($est, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('cab-api: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Estimate unavailable right now.']);
}
