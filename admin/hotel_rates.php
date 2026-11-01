<?php
/**
 * admin/hotel_rates.php?room=ID - seasonal / weekday / weekend price rules and stop-sell (maintenance) blocks,
 * with a 21-night preview of the price and rooms free.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hotel_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$adminId = (int) $_SESSION['admin_id'];
$roomId = (int) ($_GET['room'] ?? $_POST['room'] ?? 0);
$room = $roomId > 0 ? htl_room($pdo, $roomId) : null;
if (!$room) {
    $_SESSION['flash_error_page'] = 'Choose a room type first.';
    header('Location: hotel_properties.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $act = (string) ($_POST['action'] ?? '');
    try {
        if ($act === 'add_rule') {
            $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 100);
            $s = htl_date((string) ($_POST['start_date'] ?? ''));
            $e2 = htl_date((string) ($_POST['end_date'] ?? ''));
            $rate = (isset($_POST['rate']) && is_numeric($_POST['rate'])) ? round((float) $_POST['rate'], 2) : 0.0;
            $scope = in_array($_POST['day_scope'] ?? '', ['all', 'weekend', 'weekday'], true) ? $_POST['day_scope'] : 'all';
            $prio = (int) ($_POST['priority'] ?? 0);
            if ($name === '') { throw new RuntimeException('Give the rule a name, e.g. "Durga Puja".'); }
            if (!$s || !$e2 || $e2 < $s) { throw new RuntimeException('Enter a valid date range (end date on or after start date).'); }
            if ($rate <= 0 || $rate > 1000000) { throw new RuntimeException('Rate must be greater than zero.'); }
            $pdo->prepare('INSERT INTO hotel_rate_rules (room_type_id, name, start_date, end_date, rate, day_scope, priority, is_active, created_at) VALUES (:r,:n,:s,:e,:rt,:sc,:p,1,NOW())')
                ->execute([':r' => $roomId, ':n' => $name, ':s' => $s, ':e' => $e2, ':rt' => $rate, ':sc' => $scope, ':p' => $prio]);
            log_activity($adminId, 'hotel_rate_rule_added', "room={$roomId} {$name}");
            $_SESSION['flash_success_page'] = 'Rate rule added.';
        } elseif ($act === 'toggle_rule' || $act === 'delete_rule') {
            $rid = (int) ($_POST['id'] ?? 0);
            if ($act === 'delete_rule') {
                $pdo->prepare('DELETE FROM hotel_rate_rules WHERE id = :i AND room_type_id = :r')->execute([':i' => $rid, ':r' => $roomId]);
                $_SESSION['flash_success_page'] = 'Rule deleted.';
            } else {
                $pdo->prepare('UPDATE hotel_rate_rules SET is_active = 1 - is_active WHERE id = :i AND room_type_id = :r')->execute([':i' => $rid, ':r' => $roomId]);
                $_SESSION['flash_success_page'] = 'Rule updated.';
            }
        } elseif ($act === 'add_block') {
            $s = htl_date((string) ($_POST['start_date'] ?? ''));
            $e2 = htl_date((string) ($_POST['end_date'] ?? ''));
            $n = (int) ($_POST['rooms'] ?? 0);
            $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 200);
            if (!$s || !$e2 || $e2 < $s) { throw new RuntimeException('Enter a valid date range for the block.'); }
            if ($n < 1 || $n > (int) $room['total_rooms']) { throw new RuntimeException('Rooms to block must be between 1 and ' . (int) $room['total_rooms'] . '.'); }
            $pdo->beginTransaction();
            $pdo->prepare('SELECT id FROM hotel_room_types WHERE id = :r FOR UPDATE')->execute([':r' => $roomId]);
            $av = htl_availability($pdo, $room, $s, (new DateTime($e2))->modify('+1 day')->format('Y-m-d'));
            if ($av['min'] < $n) {
                throw new RuntimeException('Only ' . $av['min'] . ' room(s) are free on every one of those nights - existing bookings would be affected. Move or cancel them first.');
            }
            $pdo->prepare('INSERT INTO hotel_blocks (room_type_id, start_date, end_date, rooms, reason, created_by, created_at) VALUES (:r,:s,:e,:n,:re,:a,NOW())')
                ->execute([':r' => $roomId, ':s' => $s, ':e' => $e2, ':n' => $n, ':re' => $reason ?: null, ':a' => $adminId]);
            $pdo->commit();
            log_activity($adminId, 'hotel_block_added', "room={$roomId} {$s}..{$e2} x{$n}");
            $_SESSION['flash_success_page'] = 'Rooms blocked.';
        } elseif ($act === 'delete_block') {
            $pdo->prepare('DELETE FROM hotel_blocks WHERE id = :i AND room_type_id = :r')->execute([':i' => (int) ($_POST['id'] ?? 0), ':r' => $roomId]);
            $_SESSION['flash_success_page'] = 'Block removed.';
        }
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $_SESSION['flash_error_page'] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('hotel_rates: ' . $e->getMessage());
        $_SESSION['flash_error_page'] = 'Could not save. Please try again.';
    }
    header('Location: hotel_rates.php?room=' . $roomId);
    exit;
}

$rules = $pdo->prepare('SELECT * FROM hotel_rate_rules WHERE room_type_id = :r ORDER BY end_date DESC, id DESC');
$rules->execute([':r' => $roomId]);
$rules = $rules->fetchAll();
$blocks = $pdo->prepare('SELECT * FROM hotel_blocks WHERE room_type_id = :r AND end_date >= CURDATE() ORDER BY start_date');
$blocks->execute([':r' => $roomId]);
$blocks = $blocks->fetchAll();
$activeRules = htl_rules($pdo, $roomId);
$from = htl_date((string) ($_GET['from'] ?? '')) ?? date('Y-m-d');
$to = (new DateTime($from))->modify('+21 days')->format('Y-m-d');
$av = htl_availability($pdo, $room, $from, $to)['nights'];
$scopeLbl = ['all' => 'Every night', 'weekend' => 'Fri & Sat only', 'weekday' => 'Sun-Thu only'];

tl_shell_top('Rates & blocks', 'Rates & blocks', ['Hotels' => 'hotel_dashboard.php', 'Properties' => 'hotel_properties.php', 'Rooms' => 'hotel_rooms.php?property=' . (int) $room['property_id']]);
?>
<div class="panel"><strong><?= e($room['property_name']) ?> &middot; <?= e($room['name']) ?></strong> <span class="muted">- base <?= e(tl_inr($room['base_rate'])) ?><?= (float) $room['weekend_rate'] > 0 ? ', Fri-Sat ' . e(tl_inr($room['weekend_rate'])) : '' ?>, <?= (int) $room['total_rooms'] ?> room(s)</span></div>

<div class="tx-grid">
  <div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-tags"></i> Price rules</h3></div>
      <p class="muted" style="font-size:.84rem;margin-top:0">A rule replaces the base price on the nights it covers. If several apply, the one with the highest priority wins.</p>
      <form method="post" class="tx-form"><?= csrf_field() ?><input type="hidden" name="action" value="add_rule"><input type="hidden" name="room" value="<?= $roomId ?>">
        <div><label>Name</label><input name="name" required maxlength="100" placeholder="Durga Puja / Winter"></div>
        <div><label>Rate / night (&#8377;)</label><input type="number" name="rate" step="0.01" min="0.01" required></div>
        <div><label>From</label><input type="date" name="start_date" required></div>
        <div><label>To (last night)</label><input type="date" name="end_date" required></div>
        <div><label>Applies to</label><select name="day_scope"><?php foreach ($scopeLbl as $k => $l): ?><option value="<?= $k ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div><label>Priority</label><input type="number" name="priority" value="0"></div>
        <div><button class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add rule</button></div>
      </form>
      <?php if ($rules): ?><div class="tx-scroll" style="margin-top:14px"><table class="tx-table"><thead><tr><th>Rule</th><th>Dates</th><th>Days</th><th class="r">Rate</th><th></th></tr></thead><tbody>
      <?php foreach ($rules as $r): $past = $r['end_date'] < date('Y-m-d'); ?><tr style="<?= (!$r['is_active'] || $past) ? 'opacity:.55' : '' ?>"><td><strong><?= e($r['name']) ?></strong><?= $r['priority'] ? ' <small class="muted">prio ' . (int) $r['priority'] . '</small>' : '' ?></td>
        <td><?= e(date('d M Y', strtotime($r['start_date']))) ?> - <?= e(date('d M Y', strtotime($r['end_date']))) ?></td><td><?= e($scopeLbl[$r['day_scope']] ?? '') ?></td><td class="r"><?= e(tl_inr($r['rate'])) ?></td>
        <td style="white-space:nowrap"><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="toggle_rule"><input type="hidden" name="room" value="<?= $roomId ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-small btn-secondary"><?= $r['is_active'] ? 'Pause' : 'Resume' ?></button></form>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this rule?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_rule"><input type="hidden" name="room" value="<?= $roomId ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button class="btn btn-small btn-danger"><i class="fa-solid fa-trash"></i></button></form></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-calendar-days"></i> Next 21 nights</h3>
        <form method="get" class="tx-filter" style="margin:0"><input type="hidden" name="room" value="<?= $roomId ?>"><input type="date" name="from" value="<?= e($from) ?>"><button class="btn btn-small btn-secondary">Go</button></form></div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:8px">
      <?php foreach ($av as $d => $free): $rate = htl_night_rate($room, $activeRules, $d); $wk = in_array((int) date('N', strtotime($d)), [5, 6], true); ?>
        <div style="border:1px solid #e3ece7;border-radius:10px;padding:8px 10px;<?= $free === 0 ? 'background:#fdecec' : ($wk ? 'background:#f6fbf8' : '') ?>">
          <div style="font-size:.72rem;color:#6b7a72"><?= e(date('D d M', strtotime($d))) ?></div><div style="font-weight:700"><?= e(tl_inr($rate)) ?></div>
          <div style="font-size:.75rem;color:<?= $free === 0 ? '#b02a20' : '#14663f' ?>"><?= $free === 0 ? 'Full / blocked' : $free . ' free' ?></div></div>
      <?php endforeach; ?></div>
    </div>
  </div>

  <div>
    <div class="panel">
      <div class="panel-head"><h3><i class="fa-solid fa-ban"></i> Block rooms</h3></div>
      <p class="muted" style="font-size:.84rem;margin-top:0">Take rooms off sale for maintenance, owner use or an event. Existing bookings are never overridden.</p>
      <form method="post" class="tx-form" style="grid-template-columns:1fr"><?= csrf_field() ?><input type="hidden" name="action" value="add_block"><input type="hidden" name="room" value="<?= $roomId ?>">
        <div><label>From</label><input type="date" name="start_date" required min="<?= e(date('Y-m-d')) ?>"></div>
        <div><label>To (last night)</label><input type="date" name="end_date" required></div>
        <div><label>Rooms</label><input type="number" name="rooms" min="1" max="<?= (int) $room['total_rooms'] ?>" value="1" required></div>
        <div><label>Reason</label><input name="reason" maxlength="200" placeholder="Painting / repairs"></div>
        <button class="btn btn-primary">Block rooms</button>
      </form>
      <?php foreach ($blocks as $b): ?><div style="display:flex;justify-content:space-between;gap:8px;padding:8px 0;border-top:1px dashed #e3ece7;font-size:.85rem">
        <span><strong><?= (int) $b['rooms'] ?> room(s)</strong> &middot; <?= e(date('d M', strtotime($b['start_date']))) ?> - <?= e(date('d M Y', strtotime($b['end_date']))) ?><br><small class="muted"><?= e($b['reason'] ?: '') ?></small></span>
        <form method="post" onsubmit="return confirm('Remove this block?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete_block"><input type="hidden" name="room" value="<?= $roomId ?>"><input type="hidden" name="id" value="<?= (int) $b['id'] ?>"><button class="btn btn-small btn-ghost" style="color:#b02a20"><i class="fa-solid fa-xmark"></i></button></form></div><?php endforeach; ?>
    </div>
  </div>
</div>
<?php tl_shell_bottom();
