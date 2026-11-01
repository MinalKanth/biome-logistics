<?php
/**
 * admin/hotel_settings.php - GST slabs, booking rules, alerts, terms.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hotel_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $in = static fn(string $k, int $max = 255): string => mb_substr(trim(str_replace("\0", '', (string) ($_POST[$k] ?? ''))), 0, $max);
    $slabs = preg_replace('/\s+/', '', $in('gst_slabs', 120)) ?? '';
    $adv = (float) ($_POST['advance_pct'] ?? -1);
    $hold = (int) ($_POST['hold_hours'] ?? -1);
    $free = (int) ($_POST['free_cancel_hours'] ?? -1);
    $maxN = (int) ($_POST['max_nights'] ?? 0);
    $maxA = (int) ($_POST['max_advance_days'] ?? 0);
    $cut = (int) ($_POST['same_day_cutoff'] ?? -1);
    $mail = $in('notify_email', 150);
    if (htl_parse_slabs($slabs) === null) { $errors[] = 'GST slabs are not valid. Use the format 1000:0,7500:5,*:18 (limits ascending, last entry *).'; }
    if ($adv < 0 || $adv > 100) { $errors[] = 'Advance must be 0-100%.'; }
    if ($hold < 0 || $hold > 720) { $errors[] = 'Hold time must be 0-720 hours.'; }
    if ($free < 0 || $free > 720) { $errors[] = 'Free-cancellation window must be 0-720 hours.'; }
    if ($maxN < 1 || $maxN > 365) { $errors[] = 'Maximum nights must be 1-365.'; }
    if ($maxA < 1 || $maxA > 730) { $errors[] = 'Booking window must be 1-730 days.'; }
    if ($cut < 0 || $cut > 23) { $errors[] = 'Same-day cut-off hour must be 0-23.'; }
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Alert e-mail is not valid.'; }
    if (!$errors) {
        try {
            $save = ['gst_slabs' => $slabs, 'sac_code' => preg_replace('/\D/', '', $in('sac_code', 10)), 'advance_pct' => (string) $adv, 'auto_confirm' => !empty($_POST['auto_confirm']) ? '1' : '0',
                'hold_hours' => (string) $hold, 'free_cancel_hours' => (string) $free, 'max_nights' => (string) $maxN, 'max_advance_days' => (string) $maxA, 'same_day_cutoff' => (string) $cut,
                'notify_email' => $mail, 'whatsapp_number' => preg_replace('/\D/', '', $in('whatsapp_number', 20)), 'support_phone' => $in('support_phone', 30), 'terms' => $in('terms', 3000)];
            foreach ($save as $k => $v) { htl_save_setting($pdo, $k, (string) $v); }
            log_activity((int) $_SESSION['admin_id'], 'hotel_settings_saved');
            $_SESSION['flash_success_page'] = 'Settings saved.';
            header('Location: hotel_settings.php');
            exit;
        } catch (Throwable $e) {
            error_log('hotel_settings: ' . $e->getMessage());
            $errors[] = 'Could not save. Has hotel_install.php been run?';
        }
    }
}
$S = htl_settings($pdo, true);
$g = static fn(string $k) => $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST[$k] ?? '') : (string) ($S[$k] ?? '');

tl_shell_top('Hotel settings', 'Settings', ['Hotels' => 'hotel_dashboard.php']);
?>
<?php if ($errors): ?><div class="tx-flash err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
<form method="post" class="panel"><?= csrf_field() ?>
  <div class="panel-head"><h3><i class="fa-solid fa-percent"></i> GST</h3></div>
  <div class="tx-form" style="margin-bottom:8px">
    <div class="full"><label>GST slabs on room tariff per room per night</label><input name="gst_slabs" value="<?= e($g('gst_slabs')) ?>" placeholder="1000:0,7500:5,*:18">
      <small class="muted">Format: <code>up-to-amount:percent</code>, comma separated, last entry <code>*</code>. Default means: up to &#8377;1,000 = 0%, up to &#8377;7,500 = 5%, above = 18%.</small></div>
    <div><label>SAC code (invoice)</label><input name="sac_code" maxlength="10" value="<?= e($g('sac_code')) ?>"></div>
  </div>
  <p class="muted" style="font-size:.82rem;margin-top:0">Published sources differ on details (for example whether the &#8377;1,000 exemption still applies and whether the slab uses the declared or the charged tariff), so <strong>confirm these slabs with your CA</strong> before relying on them.</p>
  <div class="panel-head"><h3><i class="fa-solid fa-calendar-check"></i> Booking rules</h3></div>
  <div class="tx-form" style="margin-bottom:22px">
    <div><label>Advance asked (% of total)</label><input type="number" step="1" min="0" max="100" name="advance_pct" value="<?= e($g('advance_pct')) ?>"></div>
    <div><label>Hold unconfirmed online bookings for (hours)</label><input type="number" min="0" max="720" name="hold_hours" value="<?= e($g('hold_hours')) ?>"></div>
    <div><label>Guest can self-cancel up to (hours before check-in)</label><input type="number" min="0" max="720" name="free_cancel_hours" value="<?= e($g('free_cancel_hours')) ?>"></div>
    <div><label>Longest online stay (nights)</label><input type="number" min="1" max="365" name="max_nights" value="<?= e($g('max_nights')) ?>"></div>
    <div><label>Bookable up to (days ahead)</label><input type="number" min="1" max="730" name="max_advance_days" value="<?= e($g('max_advance_days')) ?>"></div>
    <div><label>Same-day booking closes at (hour, 0-23)</label><input type="number" min="0" max="23" name="same_day_cutoff" value="<?= e($g('same_day_cutoff')) ?>"></div>
    <div class="full"><label style="display:flex;gap:8px;align-items:center;text-transform:none;letter-spacing:0"><input type="checkbox" name="auto_confirm" value="1" style="width:auto" <?= $g('auto_confirm') === '1' ? 'checked' : '' ?>> Confirm online bookings automatically (otherwise your team confirms each one)</label></div>
  </div>
  <div class="panel-head"><h3><i class="fa-solid fa-bell"></i> Alerts &amp; contact</h3></div>
  <div class="tx-form" style="margin-bottom:22px">
    <div><label>E-mail for new bookings</label><input type="email" name="notify_email" maxlength="150" value="<?= e($g('notify_email')) ?>" placeholder="uses mail settings if blank"></div>
    <div><label>WhatsApp number (with country code)</label><input name="whatsapp_number" maxlength="20" value="<?= e($g('whatsapp_number')) ?>"></div>
    <div><label>Support phone (shown to guests)</label><input name="support_phone" maxlength="30" value="<?= e($g('support_phone')) ?>"></div>
    <div class="full"><label>Terms &amp; conditions (one per line; shown at booking and on the invoice)</label><textarea name="terms" rows="6" maxlength="3000"><?= e($g('terms')) ?></textarea></div>
  </div>
  <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
</form>
<?php tl_shell_bottom();
