<?php
/**
 * hotel-book.php?room=&ci=&co=&rooms=&adults=&children= - guest details + price summary -> creates the reservation.
 * Availability and price are re-checked on the server at the moment of booking (row-locked, so no double booking).
 */
declare(strict_types=1);
require_once __DIR__ . '/hotel-shell.php';

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['htl_csrf'])) { $_SESSION['htl_csrf'] = bin2hex(random_bytes(32)); }

$pdo = get_db();
$S = htl_settings($pdo);
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$roomId = (int) ($src['room'] ?? 0);
$ci = htl_date((string) ($src['ci'] ?? ''));
$co = htl_date((string) ($src['co'] ?? ''));
$rooms = max(1, min(10, (int) ($src['rooms'] ?? 1)));
$adults = max($rooms, min(40, (int) ($src['adults'] ?? 2)));
$children = max(0, min(20, (int) ($src['children'] ?? 0)));
$room = $roomId > 0 ? htl_room($pdo, $roomId) : null;
if (!$room || !(int) $room['is_active'] || !(int) $room['property_active']) {
    http_response_code(404);
    hs_top('Room not available', '', true, 'Hotels & Homestays', 'Room not available');
    echo '<div class="htl-card pull text-center"><p class="mb-3">That room is not available for booking.</p><a class="htl-btn green" href="hotel">See all stays</a></div>';
    hs_bottom();
    exit;
}
$prop = $pdo->prepare('SELECT * FROM hotel_properties WHERE id = :i');
$prop->execute([':i' => $room['property_id']]);
$prop = $prop->fetch();
$back = 'hotel-stay?p=' . rawurlencode((string) $room['slug']) . '&ci=' . $ci . '&co=' . $co . '&rooms=' . $rooms . '&adults=' . $adults . '&children=' . $children;

$err = htl_check_stay($pdo, $ci, $co);
$q = null; $left = 0;
if (!$err) {
    $left = htl_availability($pdo, $room, (string) $ci, (string) $co)['min'];
    $q = htl_quote($pdo, $room, (string) $ci, (string) $co, $rooms, $adults, $children);
    if (!$q['ok']) { $err = $q['error']; }
    elseif ($left < $rooms) { $err = $left > 0 ? 'Only ' . $left . ' room(s) of this type are left for those dates.' : 'Sorry, this room type has just sold out for those dates.'; }
}

$v = ['guest_name' => '', 'phone' => '', 'email' => '', 'arrival_time' => '', 'special_requests' => ''];
$errors = [];
if (!$err && $_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) { $v[$k] = trim(str_replace("\0", '', (string) ($_POST[$k] ?? ''))); }
    if (!hash_equals((string) $_SESSION['htl_csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        header('Location: hotel');   // bot trap: pretend all is well
        exit;
    } else {
        if ($v['guest_name'] === '' || mb_strlen($v['guest_name']) > 150) { $errors[] = 'Please enter your full name.'; }
        if (!htl_phone_ok($v['phone'])) { $errors[] = 'Please enter a valid mobile number (at least 10 digits).'; }
        if ($v['email'] !== '' && (!filter_var($v['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($v['email']) > 150)) { $errors[] = 'Please enter a valid e-mail address.'; }
        if ($v['arrival_time'] !== '' && !htl_valid_time($v['arrival_time'])) { $errors[] = 'Please enter a valid arrival time.'; }
        if (mb_strlen($v['special_requests']) > 1500) { $errors[] = 'Special requests are too long (max 1500 characters).'; }
        if (empty($_POST['agree'])) { $errors[] = 'Please accept the booking terms.'; }
        $now = time();
        $bk = $_SESSION['htl_rl'] ?? ['n' => 0, 's' => $now];
        if ($now - $bk['s'] > 600) { $bk = ['n' => 0, 's' => $now]; }
        $bk['n']++;
        $_SESSION['htl_rl'] = $bk;
        if ($bk['n'] > 4) { $errors[] = 'Too many attempts. Please wait a few minutes or call us on ' . $S['support_phone'] . '.'; }
        if (!$errors) {
            try {
                $status = $S['auto_confirm'] === '1' ? 'confirmed' : 'pending';
                $row = htl_create_reservation($pdo, ['room_type_id' => $roomId, 'check_in' => $ci, 'check_out' => $co, 'rooms' => $rooms, 'adults' => $adults, 'children' => $children] + $v, 'website', null, $status);
                $_SESSION['htl_ok'][$row['reference']] = time();
                $_SESSION['htl_csrf'] = bin2hex(random_bytes(32));
                header('Location: hotel-booking?ref=' . rawurlencode((string) $row['reference']) . '&new=1');
                session_write_close();
                if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
                elseif (function_exists('litespeed_finish_request')) { litespeed_finish_request(); }
                else { header('Connection: close'); header('Content-Length: 0'); @ob_end_flush(); @flush(); }
                try {
                    $conf = $status === 'confirmed';
                    htl_mail_guest($pdo, $row, $conf ? 'Booking confirmed' : 'Booking request received',
                        '<p>' . ($conf ? 'Your stay is confirmed.' : 'We have received your request. Our team will confirm it shortly.') . '</p>' . htl_stay_summary_html($row)
                        . '<p>Check-in from ' . tl_e((string) $prop['check_in_time']) . ', check-out by ' . tl_e((string) $prop['check_out_time']) . '. Pay at the property unless our team asks for an advance.</p>');
                    htl_mail_admin_new($pdo, $row);
                } catch (Throwable $m) { error_log('hotel mail: ' . $m->getMessage()); }
                exit;
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            } catch (Throwable $e) {
                error_log('hotel-book: ' . $e->getMessage());
                $errors[] = 'Something went wrong on our side. Please try again or call us on ' . $S['support_phone'] . '.';
            }
        }
    }
}
$terms = array_filter(array_map('trim', preg_split('/\R/', (string) $S['terms']) ?: []));
hs_top('Complete your booking', 'Complete your hotel booking', true, 'Hotels & Homestays', 'Complete your booking');
?>
<?php if ($err): ?>
  <div class="htl-card pull text-center"><div class="alert alert-warning"><?= hs_e($err) ?></div><a class="htl-btn green" href="<?= hs_e($back) ?>">&larr; Change dates or room</a></div>
<?php else: ?>
<div class="row g-4">
  <div class="col-lg-7">
    <form method="post" class="htl-card pull" id="htlBook" novalidate>
      <input type="hidden" name="csrf" value="<?= hs_e($_SESSION['htl_csrf']) ?>">
      <?php foreach (['room' => $roomId, 'ci' => $ci, 'co' => $co, 'rooms' => $rooms, 'adults' => $adults, 'children' => $children] as $k => $val): ?><input type="hidden" name="<?= $k ?>" value="<?= hs_e((string) $val) ?>"><?php endforeach; ?>
      <div style="position:absolute;left:-9999px;height:0;overflow:hidden" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
      <h4 class="mb-3" style="font-weight:800">Guest details</h4>
      <?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e2): ?><li><?= hs_e($e2) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label fw-bold" for="bkName">Full name *</label><input id="bkName" name="guest_name" class="form-control py-2" required maxlength="150" autocomplete="name" value="<?= hs_e($v['guest_name']) ?>"></div>
        <div class="col-md-6"><label class="form-label fw-bold" for="bkPhone">Mobile number *</label><input id="bkPhone" name="phone" type="tel" inputmode="tel" class="form-control py-2" required maxlength="20" autocomplete="tel" value="<?= hs_e($v['phone']) ?>"></div>
        <div class="col-md-6"><label class="form-label fw-bold" for="bkEmail">E-mail <small class="text-muted fw-normal">(for confirmation)</small></label><input id="bkEmail" name="email" type="email" class="form-control py-2" maxlength="150" autocomplete="email" value="<?= hs_e($v['email']) ?>"></div>
        <div class="col-md-6"><label class="form-label fw-bold" for="bkTime">Expected arrival time</label><input id="bkTime" name="arrival_time" type="time" class="form-control py-2" value="<?= hs_e($v['arrival_time']) ?>"></div>
        <div class="col-12"><label class="form-label fw-bold" for="bkReq">Special requests</label><textarea id="bkReq" name="special_requests" rows="3" maxlength="1500" class="form-control" placeholder="Late arrival, extra bed, dietary needs..."><?= hs_e($v['special_requests']) ?></textarea></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="agree" value="1" id="bkAgree" required <?= !empty($_POST['agree']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="bkAgree" style="font-size:.88rem">I agree to the booking terms and the house rules. A valid photo ID is required for every adult at check-in.</label></div></div>
        <div class="col-12"><button class="htl-btn green w-100 py-3" type="submit" id="bkBtn"><i class="fa fa-check mr-2 me-2"></i><?= $S['auto_confirm'] === '1' ? 'Confirm booking' : 'Request booking' ?></button>
          <p class="htl-muted text-center mt-2 mb-0">No payment now. <?= $S['auto_confirm'] === '1' ? 'Your booking is confirmed instantly.' : 'Our team confirms your booking shortly.' ?></p></div>
      </div>
    </form>
  </div>
  <div class="col-lg-5">
    <div class="htl-card" style="position:sticky;top:90px">
      <img src="<?= hs_e(htl_image_url($room['image'] ?: $prop['image'])) ?>" alt="" style="width:100%;height:150px;object-fit:cover;border-radius:10px;margin-bottom:12px">
      <h5 style="font-weight:800;margin-bottom:2px"><?= hs_e($prop['name']) ?></h5><div class="htl-muted mb-2"><?= hs_e($room['name']) ?> &times; <?= $rooms ?></div>
      <div class="htl-kv mb-3"><div><div class="k">Check-in</div><div class="v"><?= hs_e(date('D, d M Y', strtotime((string) $ci))) ?></div></div><div><div class="k">Check-out</div><div class="v"><?= hs_e(date('D, d M Y', strtotime((string) $co))) ?></div></div>
        <div><div class="k">Guests</div><div class="v"><?= $adults ?> adult<?= $adults > 1 ? 's' : '' ?><?= $children ? ', ' . $children . ' child' . ($children > 1 ? 'ren' : '') : '' ?></div></div></div>
      <table class="htl-bd"><tr><td><?= (int) $q['nights'] ?> night<?= $q['nights'] > 1 ? 's' : '' ?> &times; <?= $rooms ?> room<?= $rooms > 1 ? 's' : '' ?></td><td class="r">&#8377;<?= hs_e(number_format($q['room_total'], 2)) ?></td></tr>
        <?php if ($q['extra_total'] > 0): ?><tr><td>Extra guests</td><td class="r">&#8377;<?= hs_e(number_format($q['extra_total'], 2)) ?></td></tr><?php endif; ?>
        <tr><td>GST (<?= hs_e(rtrim(rtrim(number_format($q['gst_pct'], 2), '0'), '.')) ?>%)</td><td class="r">&#8377;<?= hs_e(number_format($q['gst'], 2)) ?></td></tr>
        <tr class="grand"><td>Total</td><td class="r">&#8377;<?= hs_e(number_format($q['total'], 2)) ?></td></tr></table>
      <?php if ((float) $S['advance_pct'] > 0): ?><p class="htl-muted mt-2 mb-0">An advance of about <?= hs_e(rtrim(rtrim(number_format((float) $S['advance_pct'], 0), '0'), '.')) ?>% (&#8377;<?= hs_e(number_format($q['advance'], 0)) ?>) may be requested to hold the room.</p><?php endif; ?>
      <p class="htl-muted mt-2 mb-0">Free cancellation until <?= (int) $S['free_cancel_hours'] ?> hours before check-in.</p>
    </div>
    <?php if ($terms): ?><div class="htl-card"><h6 style="font-weight:800">Terms</h6><ul class="mb-0 ps-3" style="font-size:.82rem"><?php foreach ($terms as $t): ?><li><?= hs_e($t) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
  </div>
</div>
<script>(function(){var f=document.getElementById('htlBook');if(!f)return;f.addEventListener('submit',function(e){var b=document.getElementById('bkBtn');if(!f.checkValidity()){e.preventDefault();f.reportValidity();return;}b.disabled=true;b.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Please wait...';});})();</script>
<?php endif; hs_bottom();
