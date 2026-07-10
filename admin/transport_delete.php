<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

/* This endpoint only ever handles a POST — there is no GET view for it. */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: transport_manage.php');
    exit;
}

csrf_require_valid();

$id       = (int) ($_POST['id'] ?? 0);
$redirect = (string) ($_POST['redirect'] ?? 'transport_manage.php');

// Only ever redirect back into this admin area — never follow an
// externally supplied URL.
if (!preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[a-zA-Z0-9_=&%\-\.]*)?$/', $redirect)) {
    $redirect = 'transport_manage.php';
}

if ($id <= 0) {
    $_SESSION['flash_error'] = 'Invalid booking.';
    header('Location: ' . $redirect);
    exit;
}

$stmt = $pdo->prepare('SELECT id, tracking_id, deleted_at FROM transport_bookings WHERE id = :id');
$stmt->execute([':id' => $id]);
$booking = $stmt->fetch();

if (!$booking) {
    $_SESSION['flash_error'] = 'Booking not found.';
    header('Location: ' . $redirect);
    exit;
}

if ($booking['deleted_at'] !== null) {
    $_SESSION['flash_error'] = 'That booking has already been deleted.';
    header('Location: ' . $redirect);
    exit;
}

try {
    $upd = $pdo->prepare(
        'UPDATE transport_bookings SET deleted_at = NOW(), updated_by = :uid, updated_at = NOW() WHERE id = :id'
    );
    $upd->execute([':uid' => $_SESSION['admin_id'], ':id' => $id]);

    log_activity((int) $_SESSION['admin_id'], 'transport_booking_deleted', "booking_id={$id} tracking_id={$booking['tracking_id']}");
    $_SESSION['flash_success'] = "Booking {$booking['tracking_id']} moved to trash.";
} catch (Throwable $e) {
    error_log('Transport booking deletion failed: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'Something went wrong while deleting the booking.';
}

header('Location: ' . $redirect);
exit;