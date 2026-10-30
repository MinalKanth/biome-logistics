<?php
/**
 * admin/includes/cab_lib.php
 * ---------------------------------------------------------------
 * Shared helpers for the Cab Services module (admin + public).
 * Needs only a PDO connection, so public pages can use it without
 * starting the admin session. Every function is prefixed cab_ .
 *
 * Re-uses the transport helpers already in your project
 * (tl_inr, tl_dt, tl_next_sequence, tl_send_mail, tl_mail_shell ...).
 */
declare(strict_types=1);

if (defined('CAB_LIB_LOADED')) {
    return;
}
define('CAB_LIB_LOADED', true);

require_once __DIR__ . '/transport_lib.php';

/* -------------------------------------------------------------
   Static lists
------------------------------------------------------------- */
function cab_vehicle_types(): array
{
    return ['Hatchback', 'Sedan', 'SUV', 'Innova Crysta', 'Tempo Traveller', 'Luxury Car'];
}

/**
 * Service types. mode 'day' = priced per day (self-drive / rentals),
 * mode 'km' = priced per km with a daily minimum (car with driver).
 * fixed_days forces the duration (weekly = 7, monthly = 30).
 */
function cab_service_types(): array
{
    return [
        'Self Drive'           => ['mode' => 'day', 'fixed_days' => null, 'driver' => false],
        'Car With Driver'      => ['mode' => 'km',  'fixed_days' => null, 'driver' => true],
        'Rental Car (Daily)'   => ['mode' => 'day', 'fixed_days' => 1,    'driver' => false],
        'Rental Car (Weekly)'  => ['mode' => 'day', 'fixed_days' => 7,    'driver' => false],
        'Rental Car (Monthly)' => ['mode' => 'day', 'fixed_days' => 30,   'driver' => false],
    ];
}

function cab_statuses(): array
{
    return [
        'new'       => ['label' => 'New',        'class' => 'warning', 'icon' => 'fa-inbox'],
        'contacted' => ['label' => 'Contacted',  'class' => 'info',    'icon' => 'fa-phone'],
        'quoted'    => ['label' => 'Quoted',     'class' => 'info',    'icon' => 'fa-file-lines'],
        'confirmed' => ['label' => 'Confirmed',  'class' => 'success', 'icon' => 'fa-circle-check'],
        'assigned'  => ['label' => 'Assigned',   'class' => 'success', 'icon' => 'fa-car-side'],
        'on_trip'   => ['label' => 'On trip',    'class' => 'info',    'icon' => 'fa-route'],
        'completed' => ['label' => 'Completed',  'class' => 'success', 'icon' => 'fa-flag-checkered'],
        'cancelled' => ['label' => 'Cancelled',  'class' => 'danger',  'icon' => 'fa-ban'],
        'closed'    => ['label' => 'Closed',     'class' => 'muted',   'icon' => 'fa-lock'], // legacy value from the old lead form
    ];
}

/** Allowed next statuses for each current status. */
function cab_status_flow(): array
{
    return [
        'new'       => ['contacted', 'quoted', 'confirmed', 'cancelled'],
        'contacted' => ['quoted', 'confirmed', 'cancelled'],
        'quoted'    => ['confirmed', 'cancelled', 'contacted'],
        'confirmed' => ['assigned', 'cancelled'],
        'assigned'  => ['on_trip', 'confirmed', 'cancelled'],
        'on_trip'   => ['completed'],
        'completed' => [],
        'cancelled' => ['new'],
        'closed'    => ['new'],
    ];
}

/** Statuses that hold a vehicle/driver (used for clash detection). */
function cab_active_statuses(): array
{
    return ['confirmed', 'assigned', 'on_trip'];
}

function cab_payment_modes(): array
{
    return ['Cash', 'UPI', 'Bank transfer', 'Card', 'Cheque', 'Other'];
}

function cab_status_badge(string $status): string
{
    $s = cab_statuses()[$status] ?? ['label' => ucfirst(str_replace('_', ' ', $status)), 'class' => 'muted'];
    return '<span class="badge badge-' . tl_e($s['class']) . '">' . tl_e($s['label']) . '</span>';
}

function cab_pay_badge(string $status): string
{
    $map = ['paid' => ['Paid', 'success'], 'partial' => ['Part paid', 'warning'], 'unpaid' => ['Unpaid', 'danger'], 'refunded' => ['Refunded', 'muted']];
    [$l, $c] = $map[$status] ?? [ucfirst($status), 'muted'];
    return '<span class="badge badge-' . $c . '">' . tl_e($l) . '</span>';
}

/* -------------------------------------------------------------
   Settings (key/value table, editable from the admin panel)
------------------------------------------------------------- */
function cab_setting_defaults(): array
{
    return [
        'gst_pct'            => '5',      // confirm the rate applicable to you with your CA
        'sac_code'           => '9964',   // passenger transport services - confirm with your CA
        'advance_pct'        => '25',     // advance asked from customer at confirmation
        'min_lead_hours'     => '2',      // earliest pickup the website accepts
        'max_advance_days'   => '180',    // furthest date the website accepts
        'public_show_price'  => '1',      // show the live estimate on the public form
        'notify_email'       => '',       // admin e-mail for new bookings (falls back to mail settings)
        'whatsapp_number'    => '919678431656',
        'support_phone'      => '+91 96784 31656',
        'terms'              => "Fuel is included in the per-km rate for Car With Driver; self-drive customers return the vehicle with the same fuel level.\nTolls, parking and state entry taxes are payable by the customer unless stated otherwise.\nCancellation within 24 hours of pickup may attract a charge of up to the advance paid.\nKilometres beyond the included limit are billed at the extra-km rate.\nAny traffic challans or damage during the rental period are the customer's responsibility.",
    ];
}

function cab_settings(PDO $pdo, bool $refresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$refresh) {
        return $cache;
    }
    $cache = cab_setting_defaults();
    try {
        foreach ($pdo->query('SELECT setting_key, setting_value FROM cab_settings') as $r) {
            $cache[$r['setting_key']] = (string) $r['setting_value'];
        }
    } catch (Throwable $e) {
        // table not installed yet - defaults are used
    }
    return $cache;
}

function cab_save_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare(
        'INSERT INTO cab_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
    )->execute([':k' => $key, ':v' => $value]);
}

/* -------------------------------------------------------------
   Dates & duration
------------------------------------------------------------- */
function cab_valid_date(?string $d): ?string
{
    if ($d === null || $d === '') {
        return null;
    }
    $x = DateTime::createFromFormat('!Y-m-d', $d);
    return ($x && $x->format('Y-m-d') === $d) ? $d : null;
}

function cab_valid_time(?string $t): ?string
{
    if ($t === null || $t === '') {
        return null;
    }
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : null;
}

/** Number of calendar days a booking spans (inclusive), honouring fixed-duration plans. */
function cab_days(string $service, ?string $from, ?string $to): int
{
    $svc = cab_service_types()[$service] ?? null;
    if ($svc && $svc['fixed_days']) {
        return (int) $svc['fixed_days'];
    }
    if (!$from || !$to) {
        return 1;
    }
    $a = new DateTime($from);
    $b = new DateTime($to);
    if ($b < $a) {
        return 1;
    }
    return max(1, min(90, (int) $a->diff($b)->days + 1));
}

function cab_end_date(string $from, int $days): string
{
    return (new DateTime($from))->modify('+' . max(0, $days - 1) . ' days')->format('Y-m-d');
}

/* -------------------------------------------------------------
   Rate card + fare engine
------------------------------------------------------------- */
function cab_rate(PDO $pdo, string $vehicle, string $service, bool $onlyActive = true): ?array
{
    $sql = 'SELECT * FROM cab_rates WHERE vehicle_type = :v AND service_type = :s' . ($onlyActive ? ' AND is_active = 1' : '') . ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([':v' => $vehicle, ':s' => $service]);
    $r = $st->fetch();
    return $r ?: null;
}

/**
 * Compute a fare breakdown. Always recomputed server-side - the
 * browser's estimate is only a preview.
 *
 * @return array{ok:bool, error?:string, lines?:array, base?:float, days?:int, km?:float, gst_pct?:float, gst?:float, total?:float, advance?:float, deposit?:float, note?:string}
 */
function cab_estimate(PDO $pdo, string $vehicle, string $service, int $days, ?float $km): array
{
    $types = cab_service_types();
    if (!isset($types[$service]) || !in_array($vehicle, cab_vehicle_types(), true)) {
        return ['ok' => false, 'error' => 'Select a vehicle and service type.'];
    }
    $rate = cab_rate($pdo, $vehicle, $service);
    if (!$rate) {
        return ['ok' => false, 'error' => 'Fare on request - our team will send you a quotation.'];
    }
    $S = cab_settings($pdo);
    $days = max(1, $days);
    $lines = [];
    $base = 0.0;
    $note = '';

    if ($types[$service]['mode'] === 'km') {
        $minKm = (float) $rate['min_km_per_day'] * $days;
        $billKm = max($minKm, (float) ($km ?? 0));
        $kmCharge = $billKm * (float) $rate['per_km'];
        $lines[] = ['label' => sprintf('%s km x %s', rtrim(rtrim(number_format($billKm, 1, '.', ''), '0'), '.'), tl_inr($rate['per_km'])), 'amount' => $kmCharge];
        $base += $kmCharge;
        if ((float) $rate['driver_allowance'] > 0) {
            $a = (float) $rate['driver_allowance'] * $days;
            $lines[] = ['label' => sprintf('Driver allowance (%d day%s)', $days, $days > 1 ? 's' : ''), 'amount' => $a];
            $base += $a;
        }
        if ($days > 1 && (float) $rate['night_halt'] > 0) {
            $n = (float) $rate['night_halt'] * ($days - 1);
            $lines[] = ['label' => sprintf('Night halt (%d night%s)', $days - 1, $days > 2 ? 's' : ''), 'amount' => $n];
            $base += $n;
        }
        $note = sprintf('Minimum %d km/day is billed. Tolls, parking and permits extra.', (int) $rate['min_km_per_day']);
    } else {
        $d = (float) $rate['per_day'] * $days;
        $lines[] = ['label' => sprintf('%s x %d day%s', tl_inr($rate['per_day']), $days, $days > 1 ? 's' : ''), 'amount' => $d];
        $base += $d;
        $included = (float) $rate['min_km_per_day'] * $days;
        if ($km !== null && $included > 0 && $km > $included && (float) $rate['extra_km_rate'] > 0) {
            $x = ($km - $included) * (float) $rate['extra_km_rate'];
            $lines[] = ['label' => sprintf('Extra %s km x %s', rtrim(rtrim(number_format($km - $included, 1, '.', ''), '0'), '.'), tl_inr($rate['extra_km_rate'])), 'amount' => $x];
            $base += $x;
        }
        if ($included > 0) {
            $note = sprintf('%d km included per day; extra km at %s/km.', (int) $rate['min_km_per_day'], tl_inr($rate['extra_km_rate']));
        }
    }

    $base = round($base, 2);
    $gstPct = (float) $S['gst_pct'];
    $gst = round($base * $gstPct / 100, 2);
    $total = round($base + $gst, 2);
    return [
        'ok' => true, 'lines' => $lines, 'base' => $base, 'days' => $days, 'km' => (float) ($km ?? 0),
        'gst_pct' => $gstPct, 'gst' => $gst, 'total' => $total,
        'advance' => round($total * (float) $S['advance_pct'] / 100, 2),
        'deposit' => (float) $rate['security_deposit'],
        'note' => $note,
    ];
}

/** Totals for a stored booking (admin can add extras / discount). */
function cab_totals(array $b): array
{
    $base  = (float) ($b['base_fare'] ?? 0);
    $extra = (float) ($b['extra_charges'] ?? 0);
    $disc  = min((float) ($b['discount'] ?? 0), $base + $extra);
    $taxable = round($base + $extra - $disc, 2);
    $pct = (float) ($b['gst_pct'] ?? 0);
    $gst = round($taxable * $pct / 100, 2);
    $total = round($taxable + $gst, 2);
    $paid = (float) ($b['paid_amount'] ?? 0);
    return ['taxable' => $taxable, 'gst' => $gst, 'total' => $total, 'paid' => $paid, 'balance' => max(0.0, round($total - $paid, 2))];
}

function cab_pay_status(float $total, float $paid): string
{
    if ($paid <= 0.0049) {
        return 'unpaid';
    }
    return $paid + 0.005 >= $total && $total > 0 ? 'paid' : 'partial';
}

/** Recalculate cached totals from base/extra/discount and the payments table. */
function cab_recalc(PDO $pdo, int $id): void
{
    $st = $pdo->prepare('SELECT * FROM cab_booking_requests WHERE id = :id');
    $st->execute([':id' => $id]);
    $b = $st->fetch();
    if (!$b) {
        return;
    }
    $p = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN entry_type='refund' THEN -amount ELSE amount END),0) FROM cab_payments WHERE booking_id = :id");
    $p->execute([':id' => $id]);
    $b['paid_amount'] = (float) $p->fetchColumn();
    $t = cab_totals($b);
    $pdo->prepare(
        'UPDATE cab_booking_requests SET gst_amount = :g, total_fare = :t, paid_amount = :p, balance_amount = :bal, payment_status = :ps, updated_at = NOW() WHERE id = :id'
    )->execute([':g' => $t['gst'], ':t' => $t['total'], ':p' => $b['paid_amount'], ':bal' => $t['balance'], ':ps' => cab_pay_status($t['total'], $b['paid_amount']), ':id' => $id]);
}

/* -------------------------------------------------------------
   Availability / clash detection
------------------------------------------------------------- */
/**
 * Bookings that clash with a vehicle and/or driver for the date range.
 * @return array<int,array>
 */
function cab_conflicts(PDO $pdo, ?int $vehicleId, ?int $driverId, string $from, string $to, int $excludeId = 0): array
{
    if (!$vehicleId && !$driverId) {
        return [];
    }
    $act = "'" . implode("','", cab_active_statuses()) . "'";
    $conds = [];
    $par = [':from' => $from, ':to' => $to, ':ex' => $excludeId];
    if ($vehicleId) {
        $conds[] = 'vehicle_id = :vid';
        $par[':vid'] = $vehicleId;
    }
    if ($driverId) {
        $conds[] = 'driver_id = :did';
        $par[':did'] = $driverId;
    }
    $sql = "SELECT id, reference, full_name, journey_date, end_date, vehicle_id, driver_id, status
            FROM cab_booking_requests
            WHERE deleted_at IS NULL AND id <> :ex AND status IN ($act)
              AND journey_date IS NOT NULL AND journey_date <= :to AND COALESCE(end_date, journey_date) >= :from
              AND (" . implode(' OR ', $conds) . ')';
    $st = $pdo->prepare($sql);
    $st->execute($par);
    return $st->fetchAll();
}

/** Vehicles of the requested category that are free for the date range. */
function cab_available_vehicles(PDO $pdo, string $from, string $to, ?string $category = null, int $excludeBooking = 0): array
{
    $sql = "SELECT * FROM cab_vehicles WHERE status = 'available' AND deleted_at IS NULL";
    $par = [];
    if ($category) {
        $sql .= ' AND category = :c';
        $par[':c'] = $category;
    }
    $st = $pdo->prepare($sql . ' ORDER BY name');
    $st->execute($par);
    $out = [];
    foreach ($st->fetchAll() as $v) {
        if (!cab_conflicts($pdo, (int) $v['id'], null, $from, $to, $excludeBooking)) {
            $out[] = $v;
        }
    }
    return $out;
}

/* -------------------------------------------------------------
   Timeline
------------------------------------------------------------- */
function cab_timeline(PDO $pdo, int $bookingId, string $status, string $title, ?string $desc = null, bool $visible = true, ?int $adminId = null): void
{
    $pdo->prepare(
        'INSERT INTO cab_booking_timeline (booking_id, status, title, description, customer_visible, created_by, created_at)
         VALUES (:b, :s, :t, :d, :v, :a, NOW())'
    )->execute([':b' => $bookingId, ':s' => $status, ':t' => $title, ':d' => ($desc !== null && $desc !== '') ? $desc : null, ':v' => $visible ? 1 : 0, ':a' => $adminId]);
}

/* -------------------------------------------------------------
   Reference numbers and links
------------------------------------------------------------- */
/** Must be called inside a transaction (the sequence row is locked FOR UPDATE). */
function cab_new_reference(PDO $pdo): string
{
    return tl_next_sequence($pdo, 'cab_booking', 'CAB');
}

function cab_new_invoice_no(PDO $pdo): string
{
    return tl_next_sequence($pdo, 'cab_invoice', 'CINV');
}

function cab_track_url(string $reference): string
{
    return rtrim((string) tl_settings()['site_url'], '/') . '/cab-track?ref=' . rawurlencode($reference);
}

function cab_clean_phone(?string $p): string
{
    return preg_replace('/\D+/', '', (string) $p) ?? '';
}

function cab_wa_link(?string $phone, string $text = ''): string
{
    $n = cab_clean_phone($phone);
    if (strlen($n) === 10) {
        $n = '91' . $n;
    }
    return 'https://wa.me/' . $n . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/* -------------------------------------------------------------
   E-mail notifications (silently skipped when SMTP is not set up)
------------------------------------------------------------- */
function cab_mail_customer_received(array $b): bool
{
    if (empty($b['email'])) {
        return false;
    }
    $rows = '<p>Hi ' . tl_e($b['full_name']) . ',</p><p>Thank you for choosing Biome Enterprises. We have received your cab booking request.</p>'
        . '<table cellpadding="6" style="font-size:14px;border-collapse:collapse">'
        . '<tr><td><b>Reference</b></td><td>' . tl_e($b['reference']) . '</td></tr>'
        . '<tr><td><b>Service</b></td><td>' . tl_e($b['service_type'] ?? '-') . ' - ' . tl_e($b['vehicle_type'] ?? '-') . '</td></tr>'
        . '<tr><td><b>Pickup</b></td><td>' . tl_e($b['pickup_location'] ?? '-') . '</td></tr>'
        . '<tr><td><b>Date</b></td><td>' . tl_e($b['journey_date'] ?? '-') . '</td></tr>'
        . (!empty($b['total_fare']) && (float) $b['total_fare'] > 0 ? '<tr><td><b>Estimated fare</b></td><td>' . tl_e(tl_inr($b['total_fare'])) . ' (incl. GST)</td></tr>' : '')
        . '</table><p>Our team will call you shortly to confirm availability and the final fare.</p>';
    return tl_send_mail((string) $b['email'], (string) $b['full_name'], 'We received your cab request ' . $b['reference'],
        tl_mail_shell('Booking request received', $rows, cab_track_url((string) $b['reference']), 'Track your booking'));
}

function cab_mail_admin_new(array $b): bool
{
    $m = tl_settings()['mail'];
    $to = (string) ($m['notify_to'] ?: $m['from_email']);
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        $custom = cab_settings($pdo)['notify_email'] ?? '';
        if ($custom !== '') {
            $to = $custom;
        }
    }
    if ($to === '') {
        return false;
    }
    $rows = '<table cellpadding="6" style="font-size:14px;border-collapse:collapse">'
        . '<tr><td><b>Reference</b></td><td>' . tl_e($b['reference']) . '</td></tr>'
        . '<tr><td><b>Customer</b></td><td>' . tl_e($b['full_name']) . ' / ' . tl_e($b['mobile_number']) . '</td></tr>'
        . '<tr><td><b>Service</b></td><td>' . tl_e($b['service_type'] ?? '-') . ' - ' . tl_e($b['vehicle_type'] ?? '-') . '</td></tr>'
        . '<tr><td><b>Route</b></td><td>' . tl_e($b['pickup_location'] ?? '-') . ' &rarr; ' . tl_e($b['destination'] ?? '-') . '</td></tr>'
        . '<tr><td><b>Date</b></td><td>' . tl_e($b['journey_date'] ?? '-') . '</td></tr></table>';
    return tl_send_mail($to, 'Biome Admin', 'New cab booking ' . $b['reference'],
        tl_mail_shell('New cab booking request', $rows, rtrim((string) tl_settings()['site_url'], '/') . '/admin/cab_view.php?id=' . (int) $b['id'], 'Open in admin'));
}

function cab_mail_customer_status(array $b, string $title, string $body): bool
{
    if (empty($b['email'])) {
        return false;
    }
    return tl_send_mail((string) $b['email'], (string) $b['full_name'], $title . ' - ' . $b['reference'],
        tl_mail_shell($title, '<p>Hi ' . tl_e($b['full_name']) . ',</p><p>' . $body . '</p>', cab_track_url((string) $b['reference']), 'View booking'));
}
