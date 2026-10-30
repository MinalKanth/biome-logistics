<?php
/**
 * admin/cab_rates.php - rate card (per vehicle x service) and cab settings.
 * Rates only reach customers when switched ON.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/cab_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$adminId = (int) $_SESSION['admin_id'];
$types = cab_service_types();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $act = (string) ($_POST['action'] ?? '');
    try {
        if ($act === 'rates') {
            $rows = $_POST['r'] ?? [];
            $up = $pdo->prepare('UPDATE cab_rates SET per_day=:pd, per_km=:pk, min_km_per_day=:mk, extra_km_rate=:ek, driver_allowance=:da, night_halt=:nh, security_deposit=:sd, is_active=:a, updated_at=NOW() WHERE id=:id');
            $n = 0;
            $bad = [];
            foreach ((array) $rows as $rid => $r) {
                $num = static fn($k) => max(0.0, min(9999999.0, round((float) ($r[$k] ?? 0), 2)));
                $active = !empty($r['is_active']) ? 1 : 0;
                $mode = null;
                $info = $pdo->prepare('SELECT service_type, vehicle_type FROM cab_rates WHERE id = :id');
                $info->execute([':id' => (int) $rid]);
                $meta = $info->fetch();
                if (!$meta) { continue; }
                $mode = $types[$meta['service_type']]['mode'] ?? 'day';
                // never allow a live rate that would quote Rs 0
                if ($active && (($mode === 'day' && $num('per_day') <= 0) || ($mode === 'km' && $num('per_km') <= 0))) {
                    $active = 0;
                    $bad[] = $meta['vehicle_type'] . ' / ' . $meta['service_type'];
                }
                $up->execute([':pd' => $num('per_day'), ':pk' => $num('per_km'), ':mk' => $num('min_km_per_day'), ':ek' => $num('extra_km_rate'),
                    ':da' => $num('driver_allowance'), ':nh' => $num('night_halt'), ':sd' => $num('security_deposit'), ':a' => $active, ':id' => (int) $rid]);
                $n++;
            }
            log_activity($adminId, 'cab_rates_saved', "rows={$n}");
            if ($bad) {
                $_SESSION['flash_error_page'] = 'Saved, but these were NOT activated because the main price is 0: ' . implode(', ', $bad);
            } else {
                $_SESSION['flash_success_page'] = 'Rate card saved.';
            }
        } elseif ($act === 'settings') {
            $in = static fn($k, $max = 255) => mb_substr(trim(str_replace("\0", '', (string) ($_POST[$k] ?? ''))), 0, $max);
            $gst = (float) ($_POST['gst_pct'] ?? 0);
            $adv = (float) ($_POST['advance_pct'] ?? 0);
            $lead = (int) ($_POST['min_lead_hours'] ?? 0);
            $max = (int) ($_POST['max_advance_days'] ?? 0);
            $mail = $in('notify_email', 150);
            if ($gst < 0 || $gst > 28 || $adv < 0 || $adv > 100 || $lead < 0 || $lead > 720 || $max < 1 || $max > 730) {
                $_SESSION['flash_error_page'] = 'One of the values is out of range (GST 0-28, advance 0-100, lead time 0-720 h, booking window 1-730 days).';
            } elseif ($mail !== '' && !filter_var($mail, FILTER_VALIDATE_EMAIL)) {
                $_SESSION['flash_error_page'] = 'Notification e-mail is not valid.';
            } else {
                $save = ['gst_pct' => (string) $gst, 'advance_pct' => (string) $adv, 'min_lead_hours' => (string) $lead, 'max_advance_days' => (string) $max,
                    'sac_code' => preg_replace('/\D/', '', $in('sac_code', 10)), 'public_show_price' => !empty($_POST['public_show_price']) ? '1' : '0',
                    'notify_email' => $mail, 'whatsapp_number' => preg_replace('/\D/', '', $in('whatsapp_number', 20)), 'support_phone' => $in('support_phone', 30),
                    'terms' => $in('terms', 3000)];
                foreach ($save as $k => $v) { cab_save_setting($pdo, $k, (string) $v); }
                log_activity($adminId, 'cab_settings_saved');
                $_SESSION['flash_success_page'] = 'Settings saved.';
            }
        }
    } catch (Throwable $e) {
        error_log('cab_rates: ' . $e->getMessage());
        $_SESSION['flash_error_page'] = 'Could not save. Please try again.';
    }
    header('Location: cab_rates.php' . ($act === 'settings' ? '#settings' : ''));
    exit;
}

$rates = $pdo->query('SELECT * FROM cab_rates')->fetchAll();
$byKey = [];
foreach ($rates as $r) { $byKey[$r['vehicle_type']][$r['service_type']] = $r; }
$S = cab_settings($pdo, true);
$anyOn = (int) $pdo->query('SELECT COUNT(*) FROM cab_rates WHERE is_active = 1')->fetchColumn();

tl_shell_top('Rates & settings', 'Rates & settings', ['Cab Services' => 'cab_dashboard.php']);
?>
<?php if (!$anyOn): ?><div class="tx-flash err">No rates are active yet, so customers see &ldquo;fare on request&rdquo;. The values below are <strong>sample numbers</strong> &mdash; replace them with your real prices and tick <em>Live</em>.</div><?php endif; ?>

<form method="post" class="panel"><?= csrf_field() ?><input type="hidden" name="action" value="rates">
  <div class="panel-head"><h3><i class="fa-solid fa-table-list"></i> Rate card</h3><button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save rates</button></div>
  <p style="font-size:.84rem;color:#6b7a72;margin-top:0"><strong>Car With Driver</strong> is billed per km (with a daily minimum), plus driver allowance and night halt. <strong>Self Drive and Rentals</strong> are billed per day with included km and an extra-km charge. Prices exclude GST.</p>
  <?php foreach (cab_vehicle_types() as $veh): ?>
  <h4 style="margin:20px 0 8px"><?= e($veh) ?></h4>
  <div class="tx-scroll"><table class="tx-table"><thead><tr><th>Service</th><th>Per day</th><th>Per km</th><th>Min / incl. km per day</th><th>Extra km</th><th>Driver allowance/day</th><th>Night halt</th><th>Deposit</th><th>Live</th></tr></thead><tbody>
  <?php foreach ($types as $svc => $meta): $r = $byKey[$veh][$svc] ?? null; if (!$r) { continue; } $k = (int) $r['id']; $day = $meta['mode'] === 'day'; ?>
    <tr>
      <td><strong><?= e($svc) ?></strong></td>
      <?php
        $cell = static function (string $name, $val, bool $on) use ($k): string {
            return '<td><input type="number" step="0.01" min="0" name="r[' . $k . '][' . $name . ']" value="' . e((string) $val) . '" style="width:92px;border:1px solid #d4e0d9;border-radius:6px;padding:6px 8px' . ($on ? '' : ';opacity:.35') . '"' . ($on ? '' : ' disabled title="Not used for this service"') . '></td>';
        };
        echo $cell('per_day', $r['per_day'], $day), $cell('per_km', $r['per_km'], !$day), $cell('min_km_per_day', $r['min_km_per_day'], true), $cell('extra_km_rate', $r['extra_km_rate'], $day),
             $cell('driver_allowance', $r['driver_allowance'], $meta['driver']), $cell('night_halt', $r['night_halt'], $meta['driver']), $cell('security_deposit', $r['security_deposit'], !$meta['driver']);
      ?>
      <td><input type="checkbox" name="r[<?= $k ?>][is_active]" value="1" <?= $r['is_active'] ? 'checked' : '' ?>></td>
    </tr>
  <?php endforeach; ?></tbody></table></div>
  <?php endforeach; ?>
</form>

<form method="post" class="panel" id="settings"><?= csrf_field() ?><input type="hidden" name="action" value="settings">
  <div class="panel-head"><h3><i class="fa-solid fa-sliders"></i> Settings</h3></div>
  <div class="tx-form">
    <div><label>GST % on cab fares</label><input type="number" step="0.01" min="0" max="28" name="gst_pct" value="<?= e($S['gst_pct']) ?>"></div>
    <div><label>SAC code (invoice)</label><input name="sac_code" maxlength="10" value="<?= e($S['sac_code']) ?>"></div>
    <div><label>Advance asked (% of fare)</label><input type="number" step="1" min="0" max="100" name="advance_pct" value="<?= e($S['advance_pct']) ?>"></div>
    <div><label>Earliest pickup (hours from now)</label><input type="number" min="0" max="720" name="min_lead_hours" value="<?= e($S['min_lead_hours']) ?>"></div>
    <div><label>Bookable up to (days ahead)</label><input type="number" min="1" max="730" name="max_advance_days" value="<?= e($S['max_advance_days']) ?>"></div>
    <div><label>New-booking alert e-mail</label><input type="email" name="notify_email" maxlength="150" value="<?= e($S['notify_email']) ?>" placeholder="uses mail settings if blank"></div>
    <div><label>WhatsApp number (with country code)</label><input name="whatsapp_number" maxlength="20" value="<?= e($S['whatsapp_number']) ?>"></div>
    <div><label>Support phone (shown to customers)</label><input name="support_phone" maxlength="30" value="<?= e($S['support_phone']) ?>"></div>
    <div style="align-self:end"><label style="display:flex;gap:8px;align-items:center;text-transform:none;font-size:.9rem"><input type="checkbox" name="public_show_price" value="1" style="width:auto" <?= $S['public_show_price'] === '1' ? 'checked' : '' ?>> Show live fare estimate on website</label></div>
    <div class="full"><label>Terms &amp; conditions (one per line; printed on invoice &amp; trip sheet)</label><textarea name="terms" rows="6" maxlength="3000"><?= e($S['terms']) ?></textarea></div>
  </div>
  <p style="font-size:.8rem;color:#6b7a72">GST rate and SAC code are starting values &mdash; confirm what applies to your business with your CA.</p>
  <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
</form>
<?php tl_shell_bottom();
