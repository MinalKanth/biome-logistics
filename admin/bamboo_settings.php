<?php
/**
 * admin/bamboo_settings.php - GST defaults, quote validity, payment terms, dispatch point, alerts, terms & conditions.
 * Saved values override the defaults in config/transport_settings.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bamboo_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $in = static fn(string $k, int $max = 255): string => mb_substr(trim(str_replace("\0", '', (string) ($_POST[$k] ?? ''))), 0, $max);
    $gst = (float) ($_POST['default_gst'] ?? -1);
    $valid = (int) ($_POST['quote_valid_days'] ?? -1);
    $due = (int) ($_POST['payment_due_days'] ?? -1);
    $mail = $in('notify_email', 150);
    $hsn = preg_replace('/\D/', '', $in('hsn', 12)) ?? '';
    if ($gst < 0 || $gst > 28) { $errors[] = 'Default GST must be between 0 and 28.'; }
    if ($valid < 1 || $valid > 365) { $errors[] = 'Quotation validity must be 1-365 days.'; }
    if ($due < 0 || $due > 365) { $errors[] = 'Payment due days must be 0-365.'; }
    if ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Alert e-mail is not valid.'; }
    if ($hsn === '') { $errors[] = 'HSN code is required.'; }
    if (!$errors) {
        try {
            $save = [
                'default_gst' => (string) $gst, 'quote_valid_days' => (string) $valid, 'payment_due_days' => (string) $due, 'hsn' => $hsn,
                'dispatch_address' => $in('dispatch_address', 500), 'dispatch_city' => $in('dispatch_city', 100), 'dispatch_state' => $in('dispatch_state', 100),
                'notify_email' => $mail, 'whatsapp_number' => preg_replace('/\D/', '', $in('whatsapp_number', 20)) ?? '', 'support_phone' => $in('support_phone', 30),
                'terms' => $in('terms', 3000),
            ];
            foreach ($save as $k => $v) { bb_setting_save($pdo, $k, $v); }
            log_activity((int) $_SESSION['admin_id'], 'bamboo_settings_saved');
            $_SESSION['flash_success_page'] = 'Settings saved.';
            header('Location: bamboo_settings.php');
            exit;
        } catch (Throwable $e) {
            error_log('bamboo_settings: ' . $e->getMessage());
            $errors[] = 'Could not save. Has bamboo_install.php been run?';
        }
    }
}
$S = bb_settings(true);
$g = static fn(string $k) => $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST[$k] ?? '') : (string) ($S[$k] ?? '');
$terms = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['terms'] ?? '') : implode("\n", (array) $S['terms']);

tl_shell_top('Bamboo settings', 'Settings', ['Bamboo Trading' => 'bamboo_dashboard.php']);
?>
<?php if ($errors): ?><div class="tx-flash err"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
<form method="post" class="panel"><?= csrf_field() ?>
  <div class="panel-head"><h3><i class="fa-solid fa-percent"></i> Tax &amp; terms</h3></div>
  <div class="tx-form" style="margin-bottom:22px">
    <div><label>Default GST %</label><input type="number" step="0.01" min="0" max="28" name="default_gst" value="<?= e($g('default_gst')) ?>"></div>
    <div><label>Default HSN code</label><input name="hsn" maxlength="12" value="<?= e($g('hsn')) ?>"></div>
    <div><label>Quotation valid for (days)</label><input type="number" min="1" max="365" name="quote_valid_days" value="<?= e($g('quote_valid_days')) ?>"></div>
    <div><label>Payment due after invoice (days)</label><input type="number" min="0" max="365" name="payment_due_days" value="<?= e($g('payment_due_days')) ?>"></div>
    <div class="full"><label>Terms &amp; conditions (one per line - printed on quotations and invoices)</label><textarea name="terms" rows="6" maxlength="3000"><?= e($terms) ?></textarea></div>
  </div>
  <div class="panel-head"><h3><i class="fa-solid fa-warehouse"></i> Dispatch point (pickup address for truck bookings)</h3></div>
  <div class="tx-form" style="margin-bottom:22px">
    <div class="full"><label>Address</label><textarea name="dispatch_address" rows="2" maxlength="500"><?= e($g('dispatch_address')) ?></textarea></div>
    <div><label>City</label><input name="dispatch_city" maxlength="100" value="<?= e($g('dispatch_city')) ?>"></div>
    <div><label>State</label><input name="dispatch_state" maxlength="100" value="<?= e($g('dispatch_state')) ?>"></div>
  </div>
  <div class="panel-head"><h3><i class="fa-solid fa-bell"></i> Alerts &amp; contact</h3></div>
  <div class="tx-form">
    <div><label>E-mail for new enquiries / quote responses</label><input type="email" name="notify_email" maxlength="150" value="<?= e($g('notify_email')) ?>" placeholder="uses mail settings if blank"></div>
    <div><label>WhatsApp number (with country code)</label><input name="whatsapp_number" maxlength="20" value="<?= e($g('whatsapp_number')) ?>"></div>
    <div><label>Support phone (shown to customers)</label><input name="support_phone" maxlength="30" value="<?= e($g('support_phone')) ?>"></div>
  </div>
  <p class="muted" style="font-size:.8rem">GST rate and HSN are starting values - confirm what applies to your goods with your CA. Intra-state sales split into CGST+SGST; sales to other states show IGST automatically.</p>
  <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
</form>
<?php tl_shell_bottom();
