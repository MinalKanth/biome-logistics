<?php
/**
 * admin/invoice.php?id=BOOKING_ID
 * ---------------------------------------------------------------
 * Generates (once) and shows the GST invoice for a booking.
 * REWRITTEN: the old version read columns that do not exist
 * (net_amount, discount_amount, other_charges, gst_percentage) so amounts were wrong.
 * It now reads the same columns transport_add.php saves (grand_total, discount, extra_charge ...).
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/transport_lib.php';
require_admin();

$pdo = get_db();
$bookingId = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if ($bookingId <= 0) {
    header('Location: transport_invoices.php');
    exit;
}

$load = function () use ($pdo, $bookingId) {
    $s = $pdo->prepare('SELECT * FROM transport_bookings WHERE id = :id AND deleted_at IS NULL');
    $s->execute([':id' => $bookingId]);
    return $s->fetch();
};
$b = $load();
if (!$b) {
    $_SESSION['flash_error'] = 'That booking could not be found.';
    header('Location: transport_manage.php');
    exit;
}

$notice = '';
$error  = '';

/* ---------- e-mail the customer ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'email_invoice') {
    csrf_require_valid();
    if (empty($b['email'])) {
        $error = 'This customer has no e-mail address on the booking.';
    } elseif (empty($b['invoice_number'])) {
        $error = 'Generate the invoice first.';
    } else {
        $t = tl_compute_totals($b);
        $inner = '<p>Hi ' . tl_e($b['customer_name']) . ',</p>'
            . '<p>Your invoice <strong>' . tl_e($b['invoice_number']) . '</strong> for shipment <strong>' . tl_e($b['tracking_id']) . '</strong> ('
            . tl_e($b['pickup_city']) . ' &rarr; ' . tl_e($b['drop_city']) . ') is ready.</p>'
            . '<table role="presentation" cellpadding="6" style="background:#f4f8f6;border-radius:8px;width:100%;margin:14px 0">'
            . '<tr><td style="color:#6b7a72">Invoice total</td><td><strong>' . tl_e(tl_inr($t['grand'])) . '</strong></td></tr>'
            . '<tr><td style="color:#6b7a72">Paid</td><td>' . tl_e(tl_inr($t['paid'])) . '</td></tr>'
            . '<tr><td style="color:#6b7a72">Balance due</td><td><strong>' . tl_e(tl_inr(max(0, $t['balance']))) . '</strong></td></tr></table>'
            . '<p style="color:#6b7a72;font-size:13px">Open the link below, enter the last 4 digits of your mobile number when asked, then choose "View / print invoice".</p>';
        $ok = tl_send_mail((string) $b['email'], (string) $b['customer_name'], 'Invoice ' . $b['invoice_number'] . ' - ' . $b['tracking_id'],
            tl_mail_shell('Your invoice is ready', $inner, tl_track_url((string) $b['tracking_id']), 'Open my shipment & invoice'));
        if ($ok) {
            $notice = 'Invoice link e-mailed to ' . $b['email'] . '.';
            log_activity((int) $_SESSION['admin_id'], 'transport_invoice_emailed', 'booking_id=' . $bookingId);
        } else {
            $error = 'E-mail could not be sent. Check the SMTP password in admin/config/transport_settings.php.';
        }
    }
}

/* ---------- generate invoice number on first open ---------- */
if (empty($b['invoice_number'])) {
    $t = tl_compute_totals($b);
    if ($t['grand'] <= 0) {
        $pageTitle = 'Invoice';
        require __DIR__ . '/includes/header.php';
        ?>
        <div style="max-width:560px;margin:80px auto;background:#fff;border:1px solid #dfeadf;border-radius:14px;padding:32px;text-align:center;font-family:Inter,Arial,sans-serif">
          <h2 style="color:#0f4c2d">No amount to invoice yet</h2>
          <p style="color:#5b6b63">Booking <strong><?= e($b['tracking_id']) ?></strong> has a total of ₹0. Enter the freight amount first, then generate the invoice.</p>
          <a href="transport_edit.php?id=<?= $bookingId ?>" style="background:#198754;color:#fff;padding:11px 20px;border-radius:8px;text-decoration:none;font-weight:600">Edit booking &amp; set amount</a>
        </div>
        <?php
        require __DIR__ . '/includes/footer.php';
        exit;
    }
    try {
        $pdo->beginTransaction();
        $fresh = $pdo->prepare('SELECT invoice_number FROM transport_bookings WHERE id = :id FOR UPDATE');
        $fresh->execute([':id' => $bookingId]);
        if (empty($fresh->fetchColumn())) {
            $num = tl_next_sequence($pdo, 'invoice', 'INV');
            $pdo->prepare('UPDATE transport_bookings SET invoice_number = :n, invoice_date = CURDATE(), updated_by = :a, updated_at = NOW() WHERE id = :id')
                ->execute([':n' => $num, ':a' => $_SESSION['admin_id'], ':id' => $bookingId]);
            log_activity((int) $_SESSION['admin_id'], 'transport_invoice_generated', "booking_id={$bookingId} invoice={$num}");
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Invoice generation failed: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Could not generate the invoice. Please try again.';
        header('Location: transport_view.php?id=' . $bookingId);
        exit;
    }
    $b = $load();
} elseif (empty($b['invoice_date'])) {
    $pdo->prepare('UPDATE transport_bookings SET invoice_date = DATE(created_at) WHERE id = :id AND invoice_date IS NULL')->execute([':id' => $bookingId]);
    $b = $load();
}

$ps = $pdo->prepare('SELECT * FROM transport_payment_history WHERE booking_id = :id ORDER BY payment_date ASC, id ASC');
$ps->execute([':id' => $bookingId]);
$payments = $ps->fetchAll();

$msg = $notice ?: $error;
ob_start();
?>
<div class="grp">
  <a class="tbtn alt" href="transport_view.php?id=<?= $bookingId ?>"><i class="fa-solid fa-arrow-left"></i> Booking</a>
  <a class="tbtn alt" href="payment.php?id=<?= $bookingId ?>"><i class="fa-solid fa-wallet"></i> Record payment</a>
  <a class="tbtn alt" href="../track?id=<?= rawurlencode((string) $b['tracking_id']) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-location-dot"></i> Customer view</a>
</div>
<div class="grp">
  <?php if ($msg): ?><span style="align-self:center;font-weight:600;color:<?= $error ? '#b02a20' : '#14663f' ?>"><?= e($msg) ?></span><?php endif; ?>
  <form method="post" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $bookingId ?>">
    <input type="hidden" name="action" value="email_invoice">
    <button class="tbtn alt" type="submit"<?= empty($b['email']) ? ' disabled title="No e-mail on this booking"' : '' ?>><i class="fa-solid fa-envelope"></i> E-mail customer</button>
  </form>
  <button class="tbtn" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save PDF</button>
</div>
<?php
$toolbarHtml = ob_get_clean();

require __DIR__ . '/includes/invoice_template.php';
