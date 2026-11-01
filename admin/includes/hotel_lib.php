<?php
/**
 * admin/includes/hotel_lib.php - Hotels & Homestays module (admin + public).
 * Availability, nightly rates, occupancy pricing, GST slabs, payments, timeline, notifications, image upload.
 * Needs only a PDO connection so public pages can use it without the admin session.
 */
declare(strict_types=1);

if (defined('HTL_LIB_LOADED')) {
    return;
}
define('HTL_LIB_LOADED', true);

require_once __DIR__ . '/transport_lib.php';

/* ------------------------------------------------------------- lists */
function htl_property_types(): array
{
    return ['hotel' => 'Hotel', 'homestay' => 'Homestay', 'guesthouse' => 'Guest house', 'resort' => 'Resort', 'lodge' => 'Lodge'];
}

function htl_amenities(): array
{
    return [
        'wifi' => ['Free Wi-Fi', 'fa-wifi'], 'ac' => ['Air conditioning', 'fa-snowflake'], 'parking' => ['Free parking', 'fa-square-parking'],
        'breakfast' => ['Breakfast', 'fa-mug-hot'], 'restaurant' => ['Restaurant', 'fa-utensils'], 'tv' => ['TV', 'fa-tv'],
        'hot_water' => ['Hot water', 'fa-shower'], 'power_backup' => ['Power backup', 'fa-bolt'], 'room_service' => ['Room service', 'fa-bell-concierge'],
        'laundry' => ['Laundry', 'fa-shirt'], 'reception' => ['24h reception', 'fa-clock'], 'cctv' => ['CCTV security', 'fa-video'],
        'garden' => ['Garden', 'fa-tree'], 'bonfire' => ['Bonfire', 'fa-fire'], 'tour_desk' => ['Tour desk', 'fa-map-location-dot'],
        'pickup' => ['Airport / station pickup', 'fa-van-shuttle'], 'family' => ['Family friendly', 'fa-people-roof'], 'balcony' => ['Balcony', 'fa-door-open'],
        'kitchen' => ['Kitchen access', 'fa-kitchen-set'], 'pets' => ['Pets allowed', 'fa-paw'],
    ];
}

function htl_statuses(): array
{
    return [
        'pending'     => ['label' => 'Pending',     'class' => 'warning', 'icon' => 'fa-hourglass-half'],
        'confirmed'   => ['label' => 'Confirmed',   'class' => 'info',    'icon' => 'fa-circle-check'],
        'checked_in'  => ['label' => 'Checked in',  'class' => 'success', 'icon' => 'fa-key'],
        'checked_out' => ['label' => 'Checked out', 'class' => 'muted',   'icon' => 'fa-right-from-bracket'],
        'cancelled'   => ['label' => 'Cancelled',   'class' => 'danger',  'icon' => 'fa-ban'],
        'no_show'     => ['label' => 'No-show',     'class' => 'danger',  'icon' => 'fa-user-slash'],
    ];
}

function htl_status_flow(): array
{
    return [
        'pending'     => ['confirmed', 'cancelled'],
        'confirmed'   => ['checked_in', 'cancelled', 'no_show'],
        'checked_in'  => ['checked_out'],
        'checked_out' => [],
        'cancelled'   => ['pending'],
        'no_show'     => [],
    ];
}

function htl_payment_modes(): array
{
    return ['cash' => 'Cash', 'upi' => 'UPI', 'card' => 'Card', 'bank_transfer' => 'Bank transfer', 'cheque' => 'Cheque', 'other' => 'Other'];
}

function htl_badge(string $status): string
{
    $s = htl_statuses()[$status] ?? ['label' => ucfirst($status), 'class' => 'muted'];
    return '<span class="badge badge-' . tl_e($s['class']) . '">' . tl_e($s['label']) . '</span>';
}

function htl_pay_badge(string $status): string
{
    $m = ['paid' => ['Paid', 'success'], 'partial' => ['Part paid', 'warning'], 'unpaid' => ['Unpaid', 'danger']];
    [$l, $c] = $m[$status] ?? [ucfirst($status), 'muted'];
    return '<span class="badge badge-' . $c . '">' . tl_e($l) . '</span>';
}

/* ---------------------------------------------------------- settings */
function htl_setting_defaults(): array
{
    return [
        // "upto:percent" pairs, ascending. Last entry has no upper limit. CONFIRM WITH YOUR CA - these are the commonly cited slabs.
        'gst_slabs'        => '1000:0,7500:5,*:18',
        'sac_code'         => '996311',
        'advance_pct'      => '25',
        'auto_confirm'     => '0',      // 1 = online bookings are confirmed instantly
        'hold_hours'       => '24',     // unconfirmed online bookings stop blocking rooms after this many hours
        'free_cancel_hours' => '48',    // guests can self-cancel until this many hours before check-in
        'max_nights'       => '30',
        'max_advance_days' => '365',
        'same_day_cutoff'  => '20',     // latest hour (0-23) to accept a booking for today
        'notify_email'     => '',
        'whatsapp_number'  => '919678431656',
        'support_phone'    => '+91 96784 31656',
        'terms'            => "Check-in and check-out times are as shown for each property.\nA valid photo ID is mandatory for every adult guest at check-in.\nEarly check-in and late check-out are subject to availability.\nUnmarried couples / local ID policies follow each property's rules.\nDamage to property will be charged at actual cost.",
    ];
}

function htl_settings(PDO $pdo, bool $refresh = false): array
{
    static $c = null;
    if ($c !== null && !$refresh) {
        return $c;
    }
    $c = htl_setting_defaults();
    try {
        foreach ($pdo->query('SELECT setting_key, setting_value FROM hotel_settings') as $r) {
            $c[$r['setting_key']] = (string) $r['setting_value'];
        }
    } catch (Throwable $e) {
        // not installed yet
    }
    return $c;
}

function htl_save_setting(PDO $pdo, string $k, string $v): void
{
    $pdo->prepare('INSERT INTO hotel_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()')
        ->execute([':k' => $k, ':v' => $v]);
}

/** Parse "1000:0,7500:5,*:18" into [[limit|null, pct], ...] ; returns null when invalid. */
function htl_parse_slabs(string $s): ?array
{
    $out = [];
    $prev = 0.0;
    $parts = array_filter(array_map('trim', explode(',', $s)));
    foreach ($parts as $i => $p) {
        if (!preg_match('/^(\*|\d+(?:\.\d+)?)\s*:\s*(\d+(?:\.\d+)?)$/', $p, $m)) {
            return null;
        }
        $limit = $m[1] === '*' ? null : (float) $m[1];
        $pct = (float) $m[2];
        if ($pct > 40 || ($limit !== null && $limit <= $prev) || ($limit === null && $i !== array_key_last($parts))) {
            return null;
        }
        if ($limit !== null) {
            $prev = $limit;
        }
        $out[] = [$limit, $pct];
    }
    if (!$out || end($out)[0] !== null) {
        return null;   // last slab must be "*"
    }
    return $out;
}

function htl_gst_pct_for(PDO $pdo, float $tariffPerRoomNight): float
{
    $slabs = htl_parse_slabs(htl_settings($pdo)['gst_slabs']) ?? htl_parse_slabs(htl_setting_defaults()['gst_slabs']);
    foreach ($slabs as [$limit, $pct]) {
        if ($limit === null || $tariffPerRoomNight <= $limit) {
            return $pct;
        }
    }
    return 0.0;
}

/* ---------------------------------------------------------- dates */
function htl_date(?string $d): ?string
{
    if ($d === null || $d === '') {
        return null;
    }
    $x = DateTime::createFromFormat('!Y-m-d', $d);
    return ($x && $x->format('Y-m-d') === $d) ? $d : null;
}

function htl_nights(string $ci, string $co): int
{
    return (int) (new DateTime($ci))->diff(new DateTime($co))->days;
}

/** @return string[] every night date from check-in up to (not including) check-out */
function htl_night_dates(string $ci, string $co): array
{
    $out = [];
    for ($d = new DateTime($ci), $e = new DateTime($co); $d < $e; $d->modify('+1 day')) {
        $out[] = $d->format('Y-m-d');
    }
    return $out;
}

/** Validate a stay request. Returns error string or null. */
function htl_check_stay(PDO $pdo, ?string $ci, ?string $co, bool $enforceCutoff = true): ?string
{
    $S = htl_settings($pdo);
    if (!$ci || !$co) {
        return 'Please choose valid check-in and check-out dates.';
    }
    if ($co <= $ci) {
        return 'Check-out must be after check-in.';
    }
    $today = date('Y-m-d');
    if ($ci < $today) {
        return 'Check-in cannot be in the past.';
    }
    if ($enforceCutoff && $ci === $today && (int) date('G') > (int) $S['same_day_cutoff']) {
        return 'Online booking for today has closed. Please call us on ' . $S['support_phone'] . '.';
    }
    if (htl_nights($ci, $co) > (int) $S['max_nights']) {
        return 'Maximum stay online is ' . (int) $S['max_nights'] . ' nights. Please contact us for longer stays.';
    }
    if ($ci > date('Y-m-d', strtotime('+' . (int) $S['max_advance_days'] . ' days'))) {
        return 'We accept bookings up to ' . (int) $S['max_advance_days'] . ' days ahead.';
    }
    return null;
}

/* --------------------------------------------------- rates & availability */
function htl_room(PDO $pdo, int $id, bool $lock = false): ?array
{
    $st = $pdo->prepare('SELECT r.*, p.name AS property_name, p.slug, p.city, p.is_active AS property_active FROM hotel_room_types r JOIN hotel_properties p ON p.id = r.property_id
                         WHERE r.id = :id AND r.deleted_at IS NULL AND p.deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([':id' => $id]);
    return $st->fetch() ?: null;
}

/** Nightly price for ONE room on one date (rate rules first, then weekend rate, then base rate). */
function htl_night_rate(array $room, array $rules, string $date): float
{
    $dow = (int) date('N', strtotime($date));      // 5 = Fri, 6 = Sat (nights people usually pay more)
    $weekend = in_array($dow, [5, 6], true);
    foreach ($rules as $r) {                        // already sorted: highest priority first
        if ($date < $r['start_date'] || $date > $r['end_date']) {
            continue;
        }
        if ($r['day_scope'] === 'weekend' && !$weekend) {
            continue;
        }
        if ($r['day_scope'] === 'weekday' && $weekend) {
            continue;
        }
        return (float) $r['rate'];
    }
    if ($weekend && (float) $room['weekend_rate'] > 0) {
        return (float) $room['weekend_rate'];
    }
    return (float) $room['base_rate'];
}

function htl_rules(PDO $pdo, int $roomId): array
{
    $st = $pdo->prepare('SELECT * FROM hotel_rate_rules WHERE room_type_id = :r AND is_active = 1 ORDER BY priority DESC, id DESC');
    $st->execute([':r' => $roomId]);
    return $st->fetchAll();
}

/**
 * Rooms still free for every night of the stay. Pending bookings only block while within the hold window.
 * @return array{min:int, nights:array<string,int>}
 */
function htl_availability(PDO $pdo, array $room, string $ci, string $co, int $excludeRes = 0): array
{
    $S = htl_settings($pdo);
    $hold = max(0, (int) $S['hold_hours']);
    $rid = (int) $room['id'];
    $bk = $pdo->prepare("SELECT check_in, check_out, rooms FROM hotel_reservations
        WHERE room_type_id = :r AND deleted_at IS NULL AND id <> :ex AND check_in < :co AND check_out > :ci
          AND (status IN ('confirmed','checked_in') OR (status = 'pending' AND created_at > DATE_SUB(NOW(), INTERVAL $hold HOUR)))");
    $bk->execute([':r' => $rid, ':ex' => $excludeRes, ':ci' => $ci, ':co' => $co]);
    $booked = $bk->fetchAll();
    $bl = $pdo->prepare('SELECT start_date, end_date, rooms FROM hotel_blocks WHERE room_type_id = :r AND start_date < :co AND end_date >= :ci');
    $bl->execute([':r' => $rid, ':ci' => $ci, ':co' => $co]);
    $blocks = $bl->fetchAll();
    $nights = [];
    foreach (htl_night_dates($ci, $co) as $d) {
        $used = 0;
        foreach ($booked as $b) {
            if ($b['check_in'] <= $d && $b['check_out'] > $d) {
                $used += (int) $b['rooms'];
            }
        }
        foreach ($blocks as $b) {
            if ($b['start_date'] <= $d && $b['end_date'] >= $d) {
                $used += (int) $b['rooms'];
            }
        }
        $nights[$d] = max(0, (int) $room['total_rooms'] - $used);
    }
    return ['min' => $nights ? min($nights) : 0, 'nights' => $nights];
}

/**
 * Price a stay. Always recalculated on the server.
 * @return array{ok:bool,error?:string,nights?:int,lines?:array,room_total?:float,extra_total?:float,subtotal?:float,gst_pct?:float,gst?:float,total?:float,advance?:float,avg_rate?:float}
 */
function htl_quote(PDO $pdo, array $room, string $ci, string $co, int $rooms, int $adults, int $children): array
{
    if ($rooms < 1 || $rooms > 20) {
        return ['ok' => false, 'error' => 'Choose between 1 and 20 rooms.'];
    }
    if ($adults < 1 || $adults < $rooms) {
        return ['ok' => false, 'error' => 'Each room needs at least one adult.'];
    }
    if ($adults > (int) $room['max_adults'] * $rooms || $children > (int) $room['max_children'] * $rooms) {
        return ['ok' => false, 'error' => 'This room type fits up to ' . (int) $room['max_adults'] . ' adults and ' . (int) $room['max_children'] . ' children per room.'];
    }
    $rules = htl_rules($pdo, (int) $room['id']);
    $extraAdults = max(0, $adults - (int) $room['base_adults'] * $rooms);
    $extraPerNight = $extraAdults * (float) $room['extra_adult_rate'] + $children * (float) $room['child_rate'];
    $lines = [];
    $roomTotal = $extraTotal = $gst = 0.0;
    $nightsList = htl_night_dates($ci, $co);
    foreach ($nightsList as $d) {
        $rate = htl_night_rate($room, $rules, $d);
        if ($rate <= 0) {
            return ['ok' => false, 'error' => 'Rates are not set for these dates yet. Please contact us.'];
        }
        $perRoomTariff = $rate + ($rooms > 0 ? $extraPerNight / $rooms : 0);   // GST slab is decided per room per night
        $pct = htl_gst_pct_for($pdo, $perRoomTariff);
        $lines[] = ['date' => $d, 'rate' => $rate];
        $roomTotal += $rate * $rooms;
        $extraTotal += $extraPerNight;
        $gst += round(($rate * $rooms + $extraPerNight) * $pct / 100, 2);
    }
    $roomTotal = round($roomTotal, 2);
    $extraTotal = round($extraTotal, 2);
    $subtotal = round($roomTotal + $extraTotal, 2);
    $gst = round($gst, 2);
    $S = htl_settings($pdo);
    return [
        'ok' => true, 'nights' => count($nightsList), 'lines' => $lines, 'room_total' => $roomTotal, 'extra_total' => $extraTotal, 'subtotal' => $subtotal,
        'gst_pct' => $subtotal > 0 ? round($gst / $subtotal * 100, 2) : 0.0, 'gst' => $gst, 'total' => round($subtotal + $gst, 2),
        'advance' => round(($subtotal + $gst) * (float) $S['advance_pct'] / 100, 2),
        'avg_rate' => count($nightsList) ? round($roomTotal / count($nightsList) / $rooms, 2) : 0.0,
    ];
}

/** Totals for a stored reservation (staff can add extras and a discount). */
function htl_totals(array $r): array
{
    $taxable = max(0.0, round((float) $r['room_total'] + (float) $r['extra_total'] + (float) $r['other_charges'] - min((float) $r['discount'], (float) $r['room_total'] + (float) $r['extra_total'] + (float) $r['other_charges']), 2));
    $gst = round($taxable * (float) $r['gst_pct'] / 100, 2);
    $total = round($taxable + $gst, 2);
    $paid = (float) $r['paid_amount'];
    return ['taxable' => $taxable, 'gst' => $gst, 'total' => $total, 'paid' => $paid, 'balance' => max(0.0, round($total - $paid, 2))];
}

function htl_pay_status(float $total, float $paid): string
{
    if ($paid <= 0.0049) {
        return 'unpaid';
    }
    return ($paid + 0.005 >= $total && $total > 0) ? 'paid' : 'partial';
}

function htl_recalc(PDO $pdo, int $id): void
{
    $s = $pdo->prepare('SELECT * FROM hotel_reservations WHERE id = :id');
    $s->execute([':id' => $id]);
    $r = $s->fetch();
    if (!$r) {
        return;
    }
    $p = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='refund' THEN -amount ELSE amount END),0) FROM hotel_payments WHERE reservation_id = :id");
    $p->execute([':id' => $id]);
    $r['paid_amount'] = (float) $p->fetchColumn();
    $t = htl_totals($r);
    $pdo->prepare('UPDATE hotel_reservations SET gst_amount = :g, total_amount = :t, paid_amount = :p, balance_amount = :b, payment_status = :s, updated_at = NOW() WHERE id = :id')
        ->execute([':g' => $t['gst'], ':t' => $t['total'], ':p' => $r['paid_amount'], ':b' => $t['balance'], ':s' => htl_pay_status($t['total'], $r['paid_amount']), ':id' => $id]);
}

function htl_timeline(PDO $pdo, int $id, string $status, string $title, ?string $desc = null, bool $visible = true, ?int $adminId = null): void
{
    $pdo->prepare('INSERT INTO hotel_timeline (reservation_id, status, title, description, customer_visible, created_by, created_at) VALUES (:r,:s,:t,:d,:v,:a,NOW())')
        ->execute([':r' => $id, ':s' => $status, ':t' => $title, ':d' => ($desc !== null && $desc !== '') ? $desc : null, ':v' => $visible ? 1 : 0, ':a' => $adminId]);
}

/** Call inside a transaction. */
function htl_new_reference(PDO $pdo): string
{
    return tl_next_sequence($pdo, 'hotel_booking', 'HTL');
}

function htl_clean_phone(?string $p): string
{
    return preg_replace('/\D+/', '', (string) $p) ?? '';
}

function htl_wa_link(?string $phone, string $text = ''): string
{
    $n = htl_clean_phone($phone);
    if (strlen($n) === 10) {
        $n = '91' . $n;
    }
    return 'https://wa.me/' . $n . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

function htl_manage_url(string $ref): string
{
    return rtrim(tl_site_url(), '/') . '/hotel-booking?ref=' . rawurlencode($ref);
}

/* ------------------------------------------------------ image upload */
/**
 * Validate + store an uploaded image in /uploads/hotel. Returns the stored file name, or null when no file was sent.
 * @throws RuntimeException with a user-safe message
 */
function htl_save_image(?array $file): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('The image could not be uploaded (error ' . (int) $file['error'] . ').');
    }
    if ($file['size'] > 4 * 1024 * 1024) {
        throw new RuntimeException('Image is larger than 4 MB.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    $info = @getimagesize($file['tmp_name']);
    if (!$ext || !$info || $info[0] < 200 || $info[1] < 150 || $info[0] > 8000 || $info[1] > 8000) {
        throw new RuntimeException('Please upload a JPG, PNG or WebP photo (at least 200x150 pixels).');
    }
    $dir = dirname(__DIR__, 2) . '/uploads/hotel';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new RuntimeException('Could not create the upload folder.');
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "php_flag engine off\nOptions -ExecCGI -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi)$\">\n  Require all denied\n</FilesMatch>\n");
    }
    $name = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException('Could not save the image on the server.');
    }
    return $name;
}

function htl_delete_image(?string $name): void
{
    if ($name && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp)$/', $name)) {
        @unlink(dirname(__DIR__, 2) . '/uploads/hotel/' . $name);
    }
}

function htl_image_url(?string $name): string
{
    return ($name && preg_match('/^[a-f0-9]{24}\.(jpg|png|webp)$/', $name)) ? 'uploads/hotel/' . $name : 'img/carousel-1.jpg';
}

function htl_slug(string $s): string
{
    $s = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $s) ?? '', '-'));
    return $s !== '' ? substr($s, 0, 80) : 'property';
}

/* ------------------------------------------------------------ mail */
function htl_notify_to(PDO $pdo): string
{
    $to = (string) (htl_settings($pdo)['notify_email'] ?? '');
    if ($to === '') {
        $m = tl_settings()['mail'];
        $to = (string) ($m['notify_to'] ?: $m['from_email']);
    }
    return $to;
}

function htl_mail_guest(PDO $pdo, array $r, string $heading, string $body): bool
{
    if (empty($r['email'])) {
        return false;
    }
    return tl_send_mail((string) $r['email'], (string) $r['guest_name'], $heading . ' - ' . $r['reference'],
        tl_mail_shell($heading, '<p>Hi ' . tl_e((string) $r['guest_name']) . ',</p>' . $body, htl_manage_url((string) $r['reference']), 'View booking'));
}

function htl_stay_summary_html(array $r): string
{
    return '<table cellpadding="6" style="font-size:14px;border-collapse:collapse">'
        . '<tr><td><b>Reference</b></td><td>' . tl_e((string) $r['reference']) . '</td></tr>'
        . '<tr><td><b>Property</b></td><td>' . tl_e((string) ($r['property_name'] ?? '')) . '</td></tr>'
        . '<tr><td><b>Room</b></td><td>' . tl_e((string) ($r['room_name'] ?? '')) . ' x ' . (int) $r['rooms'] . '</td></tr>'
        . '<tr><td><b>Dates</b></td><td>' . tl_e(date('d M Y', strtotime((string) $r['check_in']))) . ' &rarr; ' . tl_e(date('d M Y', strtotime((string) $r['check_out']))) . ' (' . (int) $r['nights'] . ' night' . ((int) $r['nights'] > 1 ? 's' : '') . ')</td></tr>'
        . '<tr><td><b>Guests</b></td><td>' . (int) $r['adults'] . ' adult(s), ' . (int) $r['children'] . ' child(ren)</td></tr>'
        . '<tr><td><b>Total</b></td><td>' . tl_e(tl_inr($r['total_amount'])) . '</td></tr></table>';
}

function htl_mail_admin_new(PDO $pdo, array $r): bool
{
    $to = htl_notify_to($pdo);
    if ($to === '') {
        return false;
    }
    return tl_send_mail($to, 'Biome Admin', 'New hotel booking ' . $r['reference'],
        tl_mail_shell('New hotel booking', '<p>' . tl_e((string) $r['guest_name']) . ' / ' . tl_e((string) $r['phone']) . '</p>' . htl_stay_summary_html($r),
            rtrim(tl_site_url(), '/') . '/admin/hotel_view.php?id=' . (int) $r['id'], 'Open in admin'));
}

/**
 * Create a reservation safely: locks the room type, re-checks availability and re-prices on the server.
 * Used by the website and by the admin form.
 * @param array $d room_type_id, guest_name, phone, email, adults, children, rooms, check_in, check_out, arrival_time, special_requests, id_type, id_number
 * @throws RuntimeException with a user-safe message
 */
function htl_create_reservation(PDO $pdo, array $d, string $source, ?int $adminId, string $status = 'pending', bool $enforceCutoff = true): array
{
    $ci = htl_date((string) ($d['check_in'] ?? ''));
    $co = htl_date((string) ($d['check_out'] ?? ''));
    if ($err = htl_check_stay($pdo, $ci, $co, $enforceCutoff)) {
        throw new RuntimeException($err);
    }
    $own = !$pdo->inTransaction();
    if ($own) {
        $pdo->beginTransaction();
    }
    try {
        $room = htl_room($pdo, (int) ($d['room_type_id'] ?? 0), true);   // FOR UPDATE: serialises competing bookings
        if (!$room || !(int) $room['is_active'] || ($source === 'website' && !(int) $room['property_active'])) {
            throw new RuntimeException('That room is not available for booking.');
        }
        $rooms = (int) ($d['rooms'] ?? 1);
        $adults = (int) ($d['adults'] ?? 1);
        $children = (int) ($d['children'] ?? 0);
        $av = htl_availability($pdo, $room, $ci, $co);
        if ($av['min'] < $rooms) {
            throw new RuntimeException($av['min'] > 0 ? 'Only ' . $av['min'] . ' room(s) of this type are free for those dates.' : 'Sorry, this room type is sold out for those dates.');
        }
        $q = htl_quote($pdo, $room, $ci, $co, $rooms, $adults, $children);
        if (!$q['ok']) {
            throw new RuntimeException($q['error']);
        }
        $ref = htl_new_reference($pdo);
        $pdo->prepare('INSERT INTO hotel_reservations (reference, property_id, room_type_id, guest_name, phone, email, adults, children, rooms, check_in, check_out, nights, arrival_time, status, source,
                special_requests, id_type, id_number, room_total, extra_total, gst_pct, gst_amount, total_amount, balance_amount, payment_status, ip_address, created_by, created_at, updated_at)
            VALUES (:ref,:p,:r,:n,:ph,:em,:a,:c,:rm,:ci,:co,:ni,:at,:st,:src,:sr,:it,:in,:rt,:et,:gp,:ga,:ta,:ta2,\'unpaid\',:ip,:cb,NOW(),NOW())')
            ->execute([':ref' => $ref, ':p' => $room['property_id'], ':r' => $room['id'], ':n' => mb_substr(trim((string) $d['guest_name']), 0, 150), ':ph' => mb_substr(trim((string) $d['phone']), 0, 20),
                ':em' => (($d['email'] ?? '') !== '') ? mb_substr((string) $d['email'], 0, 150) : null, ':a' => $adults, ':c' => $children, ':rm' => $rooms, ':ci' => $ci, ':co' => $co, ':ni' => $q['nights'],
                ':at' => htl_valid_time($d['arrival_time'] ?? null), ':st' => $status, ':src' => $source, ':sr' => (($d['special_requests'] ?? '') !== '') ? mb_substr((string) $d['special_requests'], 0, 1500) : null,
                ':it' => (($d['id_type'] ?? '') !== '') ? mb_substr((string) $d['id_type'], 0, 30) : null, ':in' => (($d['id_number'] ?? '') !== '') ? mb_substr((string) $d['id_number'], 0, 40) : null,
                ':rt' => $q['room_total'], ':et' => $q['extra_total'], ':gp' => $q['gst_pct'], ':ga' => $q['gst'], ':ta' => $q['total'], ':ta2' => $q['total'],
                ':ip' => $source === 'website' ? ($_SERVER['REMOTE_ADDR'] ?? null) : null, ':cb' => $adminId]);
        $id = (int) $pdo->lastInsertId();
        htl_timeline($pdo, $id, $status, $source === 'website' ? 'Booking request received' : 'Booking created by staff', null, true, $adminId);
        if ($own) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    $s = $pdo->prepare('SELECT r.*, p.name AS property_name, t.name AS room_name FROM hotel_reservations r JOIN hotel_properties p ON p.id = r.property_id JOIN hotel_room_types t ON t.id = r.room_type_id WHERE r.id = :id');
    $s->execute([':id' => $id]);
    return $s->fetch();
}

function htl_valid_time(?string $t): ?string
{
    return ($t !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) ? $t : null;
}

function htl_phone_ok(string $p): bool
{
    return (bool) preg_match('/^[0-9+\-\s()]{7,20}$/', $p) && strlen(htl_clean_phone($p)) >= 10;
}
