<?php
/**
 * admin/includes/transport_lib.php
 * ---------------------------------------------------------------
 * Shared helpers for the whole transport module (admin + public).
 * Standalone: it needs only a PDO connection, so public pages can
 * use it without starting the admin session.
 *
 * All functions are prefixed tl_ so they can never clash with the
 * helpers already declared inside your older admin pages.
 */
declare(strict_types=1);

if (defined('TL_LIB_LOADED')) {
    return;
}
define('TL_LIB_LOADED', true);

/* -------------------------------------------------------------
   Basics
------------------------------------------------------------- */
function tl_e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tl_inr($amount): string
{
    return '₹' . number_format((float) $amount, 2);
}

function tl_dt($value, string $fmt = 'd M Y, h:i A'): string
{
    if (!$value || strpos((string) $value, '0000-00-00') === 0) {
        return '—';
    }
    $ts = strtotime((string) $value);
    return $ts ? date($fmt, $ts) : '—';
}

function tl_client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function tl_mask_phone(?string $phone): string
{
    $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
    if (strlen($digits) < 4) {
        return '—';
    }
    return str_repeat('•', max(0, strlen($digits) - 4)) . substr($digits, -4);
}

/** Last 4 digits of a phone number, used as a lightweight "proof it's you" check. */
function tl_phone_tail(?string $phone): string
{
    $digits = preg_replace('/\D/', '', (string) $phone) ?? '';
    return strlen($digits) >= 4 ? substr($digits, -4) : '';
}

/** Tracking IDs are only ever A-Z 0-9 and dashes. */
function tl_clean_tracking_id(string $raw): string
{
    $id = strtoupper(trim($raw));
    $id = preg_replace('/[^A-Z0-9\-]/', '', $id) ?? '';
    return substr($id, 0, 40);
}

/* -------------------------------------------------------------
   Settings (admin/config/transport_settings.php)
------------------------------------------------------------- */
function tl_settings(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }
    $defaults = [
        'site_url' => 'https://biomeenterprises.com',
        'company'  => ['name' => 'Biome Enterprises', 'tagline' => 'Transport & Logistics', 'address' => '',
                       'phone' => '', 'email' => '', 'website' => '', 'gstin' => '', 'pan' => '',
                       'state' => 'Assam', 'state_code' => '18'],
        'invoice'  => ['sac_code' => '9965', 'default_gst' => 18, 'gst_split' => 'cgst_sgst', 'terms' => []],
        'bank'     => ['account_name' => '', 'bank_name' => '', 'account_no' => '', 'ifsc' => '', 'branch' => '', 'upi_id' => ''],
        'mail'     => ['host' => '', 'port' => 465, 'secure' => 'ssl', 'username' => '', 'password' => '',
                       'from_email' => '', 'from_name' => 'Biome Enterprises', 'notify_to' => ''],
    ];
    $file = __DIR__ . '/../config/transport_settings.php';
    $loaded = is_file($file) ? require $file : [];
    if (!is_array($loaded)) {
        $loaded = [];
    }
    $cfg = $defaults;
    foreach ($loaded as $k => $v) {
        $cfg[$k] = (is_array($v) && isset($defaults[$k]) && is_array($defaults[$k])) ? array_merge($defaults[$k], $v) : $v;
    }
    return $cfg;
}

function tl_site_url(): string
{
    return rtrim((string) tl_settings()['site_url'], '/');
}

function tl_track_url(string $trackingId): string
{
    return tl_site_url() . '/track?id=' . rawurlencode($trackingId);
}

/* -------------------------------------------------------------
   Lookup lists
------------------------------------------------------------- */
function tl_statuses(): array
{
    return [
        'pending'          => ['label' => 'Pending',          'class' => 'muted',   'icon' => 'fa-clock',
                               'msg' => 'We have received your booking and will confirm it shortly.'],
        'confirmed'        => ['label' => 'Confirmed',        'class' => 'info',    'icon' => 'fa-clipboard-check',
                               'msg' => 'Your booking is confirmed.'],
        'driver_assigned'  => ['label' => 'Driver Assigned',  'class' => 'info',    'icon' => 'fa-id-card',
                               'msg' => 'A driver and vehicle have been assigned to your shipment.'],
        'picked_up'        => ['label' => 'Picked Up',        'class' => 'warning', 'icon' => 'fa-box',
                               'msg' => 'Your cargo has been picked up.'],
        'in_transit'       => ['label' => 'In Transit',       'class' => 'warning', 'icon' => 'fa-truck',
                               'msg' => 'Your cargo is on the road.'],
        'out_for_delivery' => ['label' => 'Out For Delivery', 'class' => 'warning', 'icon' => 'fa-route',
                               'msg' => 'Your cargo is out for delivery.'],
        'delivered'        => ['label' => 'Delivered',        'class' => 'success', 'icon' => 'fa-check-circle',
                               'msg' => 'Your cargo has been delivered.'],
        'cancelled'        => ['label' => 'Cancelled',        'class' => 'danger',  'icon' => 'fa-times-circle',
                               'msg' => 'This booking has been cancelled.'],
        'returned'         => ['label' => 'Returned',         'class' => 'danger',  'icon' => 'fa-undo',
                               'msg' => 'This shipment has been returned to sender.'],
    ];
}

/** Forward lifecycle used to draw the progress bar. */
function tl_status_flow(): array
{
    return ['pending', 'confirmed', 'driver_assigned', 'picked_up', 'in_transit', 'out_for_delivery', 'delivered'];
}

function tl_payment_statuses(): array
{
    return ['unpaid' => 'Unpaid', 'partial' => 'Partially Paid', 'paid' => 'Paid', 'refunded' => 'Refunded'];
}

function tl_vehicle_types(): array
{
    return ['32 ft Single Axle Container', '32 ft Multi Axle Container', '32 ft Open Body', 'Not sure - please advise'];
}

function tl_cargo_types(): array
{
    return ['General Cargo', 'Bamboo / Timber', 'Construction Material', 'FMCG / Retail Goods',
            'Machinery / Equipment', 'Agricultural Produce', 'Household / Relocation', 'Other'];
}

function tl_states(): array
{
    return ['Arunachal Pradesh', 'Assam', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland', 'Sikkim', 'Tripura',
            'West Bengal', 'Bihar', 'Jharkhand', 'Odisha', 'Uttar Pradesh', 'Delhi', 'Haryana', 'Punjab',
            'Rajasthan', 'Gujarat', 'Maharashtra', 'Madhya Pradesh', 'Karnataka', 'Tamil Nadu', 'Kerala',
            'Telangana', 'Andhra Pradesh', 'Uttarakhand', 'Himachal Pradesh', 'Chhattisgarh', 'Other'];
}

/* -------------------------------------------------------------
   Sequences (TRK-26-00001, ENQ-..., INV-..., RCPT-...)
   Call inside a transaction: the row is locked with FOR UPDATE.
------------------------------------------------------------- */
function tl_next_sequence(PDO $pdo, string $name, string $defaultPrefix): string
{
    $year = (int) date('Y');
    $stmt = $pdo->prepare('SELECT * FROM transport_sequences WHERE sequence_name = :n FOR UPDATE');
    $stmt->execute([':n' => $name]);
    $row = $stmt->fetch();

    if (!$row) {
        $pdo->prepare(
            'INSERT INTO transport_sequences
                (sequence_name, prefix, current_year, current_number, padding, separator_char, reset_every_year, is_active, created_at, updated_at)
             VALUES (:n, :p, :y, 1, 5, :sep, 1, 1, NOW(), NOW())'
        )->execute([':n' => $name, ':p' => $defaultPrefix, ':y' => $year, ':sep' => '-']);
        $number = 1;
        $prefix = $defaultPrefix;
        $sep    = '-';
        $pad    = 5;
    } else {
        $reset  = (bool) $row['reset_every_year'];
        $number = ($reset && (int) $row['current_year'] !== $year) ? 1 : (int) $row['current_number'] + 1;
        $pdo->prepare('UPDATE transport_sequences SET current_number = :num, current_year = :y, updated_at = NOW() WHERE id = :id')
            ->execute([':num' => $number, ':y' => $year, ':id' => $row['id']]);
        $prefix = (string) $row['prefix'];
        $sep    = (string) ($row['separator_char'] ?? '-');
        $pad    = (int) ($row['padding'] ?: 5);
    }
    return $prefix . $sep . date('y') . $sep . str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
}

/* -------------------------------------------------------------
   Timeline
------------------------------------------------------------- */
function tl_add_timeline(
    PDO $pdo,
    int $bookingId,
    string $trackingId,
    string $status,
    string $title,
    ?string $description = null,
    ?string $location = null,
    bool $customerVisible = true,
    ?int $adminId = null
): void {
    $pdo->prepare(
        'INSERT INTO transport_booking_timeline
            (booking_id, tracking_id, status, title, description, current_location, customer_visible, created_by, created_at)
         VALUES (:bid, :tid, :st, :title, :descr, :loc, :vis, :admin, NOW())'
    )->execute([
        ':bid'   => $bookingId,
        ':tid'   => $trackingId,
        ':st'    => $status,
        ':title' => $title,
        ':descr' => ($description !== null && $description !== '') ? $description : null,
        ':loc'   => ($location !== null && $location !== '') ? $location : null,
        ':vis'   => $customerVisible ? 1 : 0,
        ':admin' => $adminId,
    ]);
}

/* -------------------------------------------------------------
   Money
------------------------------------------------------------- */
/** Indian-style amount in words: "Rupees One Lakh Twenty Thousand Only". */
function tl_amount_in_words(float $amount): string
{
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve',
             'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

    $two = function (int $n) use ($ones, $tens): string {
        return $n < 20 ? $ones[$n] : trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
    };
    $three = function (int $n) use ($ones, $two): string {
        $h = intdiv($n, 100);
        $r = $n % 100;
        $s = $h ? $ones[$h] . ' Hundred' : '';
        if ($r) {
            $s .= ($s !== '' ? ' ' : '') . $two($r);
        }
        return $s;
    };

    $amount = round(max(0.0, $amount), 2);
    $rupees = (int) floor($amount);
    $paise  = (int) round(($amount - $rupees) * 100);
    if ($paise === 100) {
        $rupees++;
        $paise = 0;
    }
    if ($rupees === 0 && $paise === 0) {
        return 'Zero Rupees Only';
    }

    $parts = [];
    $crore = intdiv($rupees, 10000000);
    $rupees %= 10000000;
    $lakh = intdiv($rupees, 100000);
    $rupees %= 100000;
    $thousand = intdiv($rupees, 1000);
    $rupees %= 1000;

    if ($crore)    { $parts[] = $three($crore) . ' Crore'; }
    if ($lakh)     { $parts[] = $two($lakh) . ' Lakh'; }
    if ($thousand) { $parts[] = $two($thousand) . ' Thousand'; }
    if ($rupees)   { $parts[] = $three($rupees); }

    $words = $parts ? 'Rupees ' . implode(' ', $parts) : '';
    if ($paise) {
        $words .= ($words !== '' ? ' and ' : '') . $two($paise) . ' Paise';
    }
    return trim($words) . ' Only';
}

/** Recompute totals from the money columns already on the booking row (same maths as transport_add.php). */
function tl_compute_totals(array $b): array
{
    $total    = (float) ($b['total_amount'] ?? 0);
    $gst      = (float) ($b['gst_amount'] ?? 0);
    $toll     = (float) ($b['toll_amount'] ?? 0);
    $fuel     = (float) ($b['fuel_charge'] ?? 0);
    $labour   = (float) ($b['labour_charge'] ?? 0);
    $extra    = (float) ($b['extra_charge'] ?? 0);
    $discount = (float) ($b['discount'] ?? 0);
    $grand    = round($total + $gst + $toll + $fuel + $labour + $extra - $discount, 2);
    $paid     = (float) ($b['paid_amount'] ?? 0);
    return [
        'total' => $total, 'gst' => $gst, 'toll' => $toll, 'fuel' => $fuel, 'labour' => $labour,
        'extra' => $extra, 'discount' => $discount, 'grand' => $grand, 'paid' => $paid,
        'balance' => round($grand - $paid, 2),
        'gst_pct' => $total > 0 ? round($gst / $total * 100, 2) : 0.0,
    ];
}

/** Payment status from what has been paid vs. what is due. */
function tl_payment_status_for(float $grand, float $paid): string
{
    if ($paid <= 0.009) {
        return 'unpaid';
    }
    return ($grand - $paid) > 0.009 ? 'partial' : 'paid';
}

/* -------------------------------------------------------------
   Mail (PHPMailer already exists in /PHPMailer-master)
------------------------------------------------------------- */
function tl_send_mail(string $to, string $toName, string $subject, string $htmlBody, ?string $replyTo = null): bool
{
    $m = tl_settings()['mail'];
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL) || (string) $m['password'] === '') {
        return false; // mail switched off or no valid recipient
    }
    $base = dirname(__DIR__, 2) . '/PHPMailer-master/src/';
    if (!is_file($base . 'PHPMailer.php')) {
        error_log('tl_send_mail: PHPMailer not found at ' . $base);
        return false;
    }
    require_once $base . 'Exception.php';
    require_once $base . 'PHPMailer.php';
    require_once $base . 'SMTP.php';

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = (string) $m['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = (string) $m['username'];
        $mail->Password   = (string) $m['password'];
        $mail->SMTPSecure = ($m['secure'] === 'tls')
            ? \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS
            : \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port    = (int) $m['port'];
        $mail->CharSet = 'UTF-8';
        $mail->setFrom((string) $m['from_email'], (string) $m['from_name']);
        $mail->addAddress($to, $toName);
        if ($replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $mail->addReplyTo($replyTo);
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = trim(html_entity_decode(strip_tags(preg_replace('/<br\s*\/?>|<\/p>/i', "\n", $htmlBody) ?? $htmlBody)));
        $mail->send();
        return true;
    } catch (\Throwable $e) {
        error_log('tl_send_mail failed: ' . $e->getMessage());
        return false;
    }
}

/** Branded, table-based e-mail shell (works in Gmail/Outlook). */
function tl_mail_shell(string $heading, string $innerHtml, ?string $ctaUrl = null, ?string $ctaLabel = null): string
{
    $co = tl_settings()['company'];
    $cta = ($ctaUrl && $ctaLabel)
        ? '<p style="margin:26px 0 6px"><a href="' . tl_e($ctaUrl) . '" style="background:#198754;color:#fff;text-decoration:none;'
          . 'padding:13px 26px;border-radius:8px;font-weight:600;display:inline-block">' . tl_e($ctaLabel) . '</a></p>'
        : '';
    return '<div style="background:#f4f8f6;padding:24px 12px;font-family:Arial,Helvetica,sans-serif">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;margin:0 auto;background:#fff;'
        . 'border-radius:12px;overflow:hidden;border:1px solid #e3ece7">'
        . '<tr><td style="background:#0f4c2d;color:#fff;padding:22px 28px"><div style="font-size:20px;font-weight:700">'
        . tl_e($co['name']) . '</div><div style="font-size:12px;color:#ffc107;letter-spacing:1px;text-transform:uppercase">'
        . tl_e($co['tagline']) . '</div></td></tr>'
        . '<tr><td style="padding:28px;color:#1c2b24;font-size:15px;line-height:1.6"><h2 style="margin:0 0 14px;font-size:20px;color:#0f4c2d">'
        . tl_e($heading) . '</h2>' . $innerHtml . $cta . '</td></tr>'
        . '<tr><td style="padding:16px 28px;background:#f4f8f6;color:#6b7a72;font-size:12px">'
        . tl_e($co['name']) . ' &middot; ' . tl_e($co['phone']) . ' &middot; ' . tl_e($co['email']) . '</td></tr></table></div>';
}
