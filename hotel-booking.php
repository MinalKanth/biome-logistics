<?php
/**
 * hotel-booking.php?ref=HTL-26-00001 - guest's booking page: confirmation, status, price, timeline, print, self-cancel.
 * Access: straight after booking (same browser session), or reference + last 4 digits of the mobile number.
 * Lookups are rate-limited per IP in the database so the 4 digits cannot be brute-forced.
 */
declare(strict_types=1);
require_once __DIR__ . '/hotel-shell.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['htl_track_csrf'])) { $_SESSION['htl_track_csrf'] = bin2hex(random_bytes(32)); }

$pdo = get_db();
$S = htl_settings($pdo);
$ref = strtoupper(trim((string) ($_GET['ref'] ?? $_POST['ref'] ?? '')));
$ref = preg_match('/^[A-Z]{2,6}-\d{2}-\d{3,8}$/', $ref) ? $ref : '';
$tail = preg_replace('/\D/', '', (string) ($_POST['last4'] ?? '')) ?? '';
$ip = tl_client_ip();
$error = '';
$notice = '';
$r = null;

$failed = static function () use ($pdo, $ip): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE action = 'hotel_track_fail' AND ip_address = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $s->execute([':ip' => $ip]);
    return (int) $s->fetchColumn();
};
$load = static function (string $ref) use ($pdo): ?array {
    $s = $pdo->prepare('SELECT r.*, p.name AS property_name, p.phone AS property_phone, p.address AS paddr, p.city AS pcity, p.check_in_time, p.check_out_time, t.name AS room_name
        FROM hotel_reservations r JOIN hotel_properties p ON p.id = r.property_id JOIN hotel_room_types t ON t.id = r.room_type_id WHERE r.reference = :r AND r.deleted_at IS NULL');
    $s->execute([':r' => $ref]);
    return $s->fetch() ?: null;
};

$authed = $ref !== '' && isset($_SESSION['htl_ok'][$ref]) && (time() - (int) $_SESSION['htl_ok'][$ref]) < 7200;
if ($ref !== '' && $authed) {
    $r = $load($ref);
} elseif ($ref !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'lookup') {
    if (!hash_equals((string) $_SESSION['htl_track_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } elseif (strlen($tail) !== 4) {
        $error = 'Enter the last 4 digits of the mobile number used for the booking.';
    } elseif ($failed() >= 8) {
        $error = 'Too many unsuccessful attempts. Please try again in 15 minutes or call us.';
    } else {
        $row = $load($ref);
        if ($row && hash_equals(substr(htl_clean_phone($row['phone']), -4), $tail)) {
            $_SESSION['htl_ok'][$ref] = time();
            $r = $row;
            $authed = true;
        } else {
            $pdo->prepare("INSERT INTO activity_log (admin_id, action, details, ip_address) VALUES (NULL, 'hotel_track_fail', :d, :ip)")->execute([':d' => substr($ref, 0, 40), ':ip' => $ip]);
            $error = 'We could not find a booking with those details. Please check and try again.';
        }
    }
}

/** Free self-cancellation allowed? */
$canCancel = static function (array $r) use ($S): bool {
    if (!in_array($r['status'], ['pending', 'confirmed'], true)) { return false; }
    $start = strtotime($r['check_in'] . ' ' . ($r['check_in_time'] ?: '12:00'));
    return ($start - time()) >= ((int) $S['free_cancel_hours']) * 3600;
};

if ($r && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (!hash_equals((string) $_SESSION['htl_track_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $error = 'Your session expired. Please try again.';
    } else {
        try {
            $pdo->beginTransaction();
            $lk = $pdo->prepare('SELECT * FROM hotel_reservations WHERE id = :id FOR UPDATE');
            $lk->execute([':id' => $r['id']]);
            $cur = $lk->fetch() + $r;
            if (!$canCancel($cur)) {
                $pdo->rollBack();
                $error = 'This booking can no longer be cancelled online. Please call us on ' . $S['support_phone'] . '.';
            } else {
                $pdo->prepare("UPDATE hotel_reservations SET status = 'cancelled', cancel_reason = 'Cancelled by guest', updated_at = NOW() WHERE id = :id")->execute([':id' => $r['id']]);
                htl_timeline($pdo, (int) $r['id'], 'cancelled', 'Booking cancelled by guest', (float) $cur['paid_amount'] > 0.009 ? 'Our team will process your refund as per the cancellation policy.' : null, true, null);
                $pdo->commit();
                $notice = 'Your booking has been cancelled.' . ((float) $cur['paid_amount'] > 0.009 ? ' Our team will contact you about your refund.' : '');
                $r = $load($ref);
                $to = htl_notify_to($pdo);
                if ($to !== '') {
                    tl_send_mail($to, 'Biome Admin', 'Guest cancelled ' . $r['reference'], tl_mail_shell('Booking cancelled by guest', '<p>' . tl_e((string) $r['guest_name']) . ' cancelled ' . tl_e((string) $r['reference'])
                        . ((float) $r['paid_amount'] > 0.009 ? '. <b>' . tl_e(tl_inr($r['paid_amount'])) . ' was received - refund due.</b>' : '.') . '</p>', rtrim(tl_site_url(), '/') . '/admin/hotel_view.php?id=' . (int) $r['id'], 'Open in admin'));
                }
                htl_mail_guest($pdo, $r, 'Booking cancelled', '<p>Your booking has been cancelled as requested.</p>');
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('hotel-booking cancel: ' . $e->getMessage());
            $error = 'Something went wrong. Please call us to cancel.';
        }
    }
}
if ($r && !empty($_GET['new']) && !$notice) {
    $notice = $r['status'] === 'confirmed' ? 'Your booking is confirmed. Thank you!' : 'Thank you! We have received your request and will confirm it shortly.';
}

$steps = ['pending' => 'Requested', 'confirmed' => 'Confirmed', 'checked_in' => 'Checked in', 'checked_out' => 'Checked out'];
$order = array_keys($steps);
$idx = $r ? array_search($r['status'], $order, true) : false;
$cancelled = $r && in_array($r['status'], ['cancelled', 'no_show'], true);
$tl = [];
if ($r) {
    $t = $pdo->prepare('SELECT title, description, created_at FROM hotel_timeline WHERE reservation_id = :i AND customer_visible = 1 ORDER BY id DESC');
    $t->execute([':i' => $r['id']]);
    $tl = $t->fetchAll();
    $tot = htl_totals($r);
}
hs_top($r ? 'Booking ' . $r['reference'] : 'Find your booking', '', true, 'Hotels & Homestays', $r ? 'Your booking' : 'Find your booking');
?>
<?php if ($notice): ?><div class="alert alert-success pull" style="margin-top:-30px;position:relative;z-index:2"><?= hs_e($notice) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger pull" style="margin-top:-30px;position:relative;z-index:2"><?= hs_e($error) ?></div><?php endif; ?>

<?php if (!$r): ?>
<div class="htl-card <?= ($notice || $error) ? '' : 'pull' ?>" style="max-width:560px;margin-left:auto;margin-right:auto">
  <p class="htl-muted">Enter your booking reference (shown after booking and sent by e-mail) and the last 4 digits of your mobile number.</p>
  <form method="post" action="hotel-booking"><input type="hidden" name="csrf" value="<?= hs_e($_SESSION['htl_track_csrf']) ?>"><input type="hidden" name="action" value="lookup">
    <div class="mb-3"><label class="form-label fw-bold" for="lkRef">Booking reference</label><input id="lkRef" name="ref" class="form-control py-2" placeholder="HTL-26-00001" maxlength="20" required value="<?= hs_e($ref) ?>" autocomplete="off"></div>
    <div class="mb-3"><label class="form-label fw-bold" for="lk4">Last 4 digits of mobile</label><input id="lk4" name="last4" class="form-control py-2" inputmode="numeric" pattern="\d{4}" maxlength="4" required autocomplete="off"></div>
    <button class="htl-btn green w-100 py-2" type="submit">Find booking</button></form>
</div>
<?php else: ?>
<div class="htl-card <?= ($notice || $error) ? '' : 'pull' ?>">
  <div class="d-flex flex-wrap justify-content-between gap-2">
    <div><div class="htl-muted">Booking reference</div><h3 style="font-family:monospace;margin:0"><?= hs_e($r['reference']) ?></h3>
      <span class="htl-badge <?= $cancelled ? 'bad' : '' ?>" style="margin-top:6px"><?= hs_e(htl_statuses()[$r['status']]['label'] ?? $r['status']) ?></span><?php if ($r['payment_status'] !== 'unpaid'): ?><span class="htl-badge warn"><?= $r['payment_status'] === 'paid' ? 'Paid' : 'Part paid' ?></span><?php endif; ?></div>
    <div class="no-print"><button class="htl-btn ghost" onclick="window.print()" type="button"><i class="fa fa-print me-1"></i> Print</button></div>
  </div>
  <?php if ($cancelled): ?><div class="alert alert-danger mt-3 mb-0">This booking is <?= $r['status'] === 'no_show' ? 'marked as a no-show' : 'cancelled' ?>. Call <?= hs_e($S['support_phone']) ?> if this is unexpected.</div>
  <?php elseif ($idx !== false): ?><div class="htl-steps"><?php foreach ($steps as $k => $l): $i = array_search($k, $order, true); $c = $i < $idx ? 'done' : ($i === $idx ? 'now' : ''); ?>
    <div class="htl-step <?= $c ?>"><div class="d"><i class="fa <?= $i < $idx || ($i === $idx && $k === 'checked_out') ? 'fa-check' : 'fa-circle' ?>" style="font-size:.8rem"></i></div><span><?= hs_e($l) ?></span></div><?php endforeach; ?></div><?php endif; ?>
</div>

<div class="row g-4">
  <div class="col-lg-7">
    <div class="htl-card"><h5 style="font-weight:800" class="mb-3"><?= hs_e($r['property_name']) ?></h5>
      <div class="htl-kv">
        <div><div class="k">Room</div><div class="v"><?= hs_e($r['room_name']) ?> &times; <?= (int) $r['rooms'] ?></div></div>
        <div><div class="k">Check-in</div><div class="v"><?= hs_e(date('D, d M Y', strtotime($r['check_in']))) ?><br><small class="htl-muted">from <?= hs_e($r['check_in_time']) ?></small></div></div>
        <div><div class="k">Check-out</div><div class="v"><?= hs_e(date('D, d M Y', strtotime($r['check_out']))) ?><br><small class="htl-muted">by <?= hs_e($r['check_out_time']) ?></small></div></div>
        <div><div class="k">Guests</div><div class="v"><?= (int) $r['adults'] ?> adult(s), <?= (int) $r['children'] ?> child(ren)</div></div>
        <div><div class="k">Guest</div><div class="v"><?= hs_e($r['guest_name']) ?></div></div>
        <?php if ($r['room_numbers'] && in_array($r['status'], ['checked_in', 'checked_out'], true)): ?><div><div class="k">Room no.</div><div class="v"><?= hs_e($r['room_numbers']) ?></div></div><?php endif; ?>
      </div>
      <hr><div class="htl-muted"><i class="fa fa-map-marker-alt text-success me-1"></i><?= hs_e(trim(($r['paddr'] ?? '') . ', ' . ($r['pcity'] ?? ''), ' ,')) ?> &middot; <a href="tel:<?= hs_e(htl_clean_phone($r['property_phone'] ?: $S['support_phone'])) ?>"><?= hs_e($r['property_phone'] ?: $S['support_phone']) ?></a></div>
    </div>
    <div class="htl-card"><h5 style="font-weight:800" class="mb-3">Price</h5>
      <table class="htl-bd"><tr><td>Room charges (<?= (int) $r['nights'] ?> night<?= (int) $r['nights'] > 1 ? 's' : '' ?>)</td><td class="r">&#8377;<?= hs_e(number_format((float) $r['room_total'], 2)) ?></td></tr>
        <?php if ((float) $r['extra_total'] > 0): ?><tr><td>Extra guests</td><td class="r">&#8377;<?= hs_e(number_format((float) $r['extra_total'], 2)) ?></td></tr><?php endif; ?>
        <?php if ((float) $r['other_charges'] > 0): ?><tr><td>Other charges</td><td class="r">&#8377;<?= hs_e(number_format((float) $r['other_charges'], 2)) ?></td></tr><?php endif; ?>
        <?php if ((float) $r['discount'] > 0): ?><tr><td>Discount</td><td class="r">-&#8377;<?= hs_e(number_format((float) $r['discount'], 2)) ?></td></tr><?php endif; ?>
        <tr><td>GST</td><td class="r">&#8377;<?= hs_e(number_format($tot['gst'], 2)) ?></td></tr>
        <tr class="grand"><td>Total</td><td class="r">&#8377;<?= hs_e(number_format($tot['total'], 2)) ?></td></tr>
        <?php if ($tot['paid'] > 0): ?><tr><td>Paid</td><td class="r">&#8377;<?= hs_e(number_format($tot['paid'], 2)) ?></td></tr><tr><td><strong>Balance</strong></td><td class="r"><strong>&#8377;<?= hs_e(number_format($tot['balance'], 2)) ?></strong></td></tr><?php endif; ?></table>
    </div>
  </div>
  <div class="col-lg-5">
    <?php if (in_array($r['status'], ['pending', 'confirmed'], true)): ?>
    <div class="htl-card no-print"><h5 style="font-weight:800">Need to cancel?</h5>
      <?php if ($canCancel($r)): ?>
        <p class="htl-muted">Free cancellation is available until <?= (int) $S['free_cancel_hours'] ?> hours before check-in.</p>
        <form method="post" onsubmit="return confirm('Cancel this booking? This cannot be undone.');"><input type="hidden" name="csrf" value="<?= hs_e($_SESSION['htl_track_csrf']) ?>"><input type="hidden" name="ref" value="<?= hs_e($r['reference']) ?>"><input type="hidden" name="action" value="cancel">
          <button class="htl-btn ghost" style="border-color:#b02a20;color:#b02a20" type="submit">Cancel booking</button></form>
      <?php else: ?><p class="htl-muted mb-0">Online cancellation closed <?= (int) $S['free_cancel_hours'] ?> hours before check-in. Please call us on <?= hs_e($S['support_phone']) ?>.</p><?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="htl-card"><h5 style="font-weight:800" class="mb-3">Updates</h5>
      <div class="htl-tl"><?php foreach ($tl as $e): ?><div class="e"><div class="htl-muted"><?= hs_e(date('d M Y, h:i A', strtotime((string) $e['created_at']))) ?></div><strong><?= hs_e($e['title']) ?></strong><?php if ($e['description']): ?><div class="htl-muted"><?= hs_e($e['description']) ?></div><?php endif; ?></div><?php endforeach; ?></div>
      <hr class="no-print"><div class="no-print"><a class="htl-btn green" style="padding:8px 16px" href="tel:<?= hs_e(htl_clean_phone($S['support_phone'])) ?>"><i class="fa fa-phone me-1"></i> Call us</a>
        <a class="htl-btn ghost" style="padding:8px 16px" target="_blank" rel="noopener" href="https://wa.me/<?= hs_e($S['whatsapp_number']) ?>?text=<?= hs_e(rawurlencode('Hello, regarding my booking ' . $r['reference'])) ?>"><i class="fab fa-whatsapp me-1"></i> WhatsApp</a></div>
    </div>
  </div>
</div>
<?php endif; hs_bottom();
