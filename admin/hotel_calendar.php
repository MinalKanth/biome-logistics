<?php
/**
 * admin/hotel_calendar.php - occupancy calendar: every room type x 30 days, showing rooms free per night.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hotel_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$from = htl_date((string) ($_GET['from'] ?? '')) ?? date('Y-m-d');
$days = 30;
$to = (new DateTime($from))->modify("+$days days")->format('Y-m-d');
$prop = (int) ($_GET['property'] ?? 0);
$props = $pdo->query('SELECT id, name FROM hotel_properties WHERE deleted_at IS NULL ORDER BY sort_order, name')->fetchAll();

$sql = 'SELECT r.*, p.name AS property_name FROM hotel_room_types r JOIN hotel_properties p ON p.id = r.property_id WHERE r.deleted_at IS NULL AND p.deleted_at IS NULL AND r.is_active = 1';
$par = [];
if ($prop > 0) { $sql .= ' AND r.property_id = :p'; $par[':p'] = $prop; }
$st = $pdo->prepare($sql . ' ORDER BY p.sort_order, p.name, r.sort_order, r.name');
$st->execute($par);
$rooms = $st->fetchAll();
$dates = htl_night_dates($from, $to);
$grid = [];
foreach ($rooms as $room) { $grid[(int) $room['id']] = htl_availability($pdo, $room, $from, $to)['nights']; }

$prev = (new DateTime($from))->modify('-30 days')->format('Y-m-d');
$nextD = (new DateTime($from))->modify('+30 days')->format('Y-m-d');
tl_shell_top('Availability calendar', 'Availability calendar', ['Hotels' => 'hotel_dashboard.php'],
    '<a class="btn btn-secondary" href="?from=' . e($prev) . '&amp;property=' . $prop . '"><i class="fa-solid fa-chevron-left"></i> Previous</a><a class="btn btn-secondary" href="?property=' . $prop . '">Today</a><a class="btn btn-secondary" href="?from=' . e($nextD) . '&amp;property=' . $prop . '">Next <i class="fa-solid fa-chevron-right"></i></a>');
?>
<form method="get" class="panel tx-filter"><select name="property" onchange="this.form.submit()"><option value="">All properties</option><?php foreach ($props as $p): ?><option value="<?= (int) $p['id'] ?>" <?= $prop === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
  <input type="date" name="from" value="<?= e($from) ?>"><button class="btn btn-primary">Go</button>
  <span class="muted" style="font-size:.82rem">Numbers show rooms still free. <span style="color:#b02a20">Red</span> = sold out / blocked.</span></form>
<?php if (!$rooms): ?><div class="panel"><p style="margin:0;text-align:center;color:#6b7a72;padding:20px">No bookable room types yet.</p></div><?php else: ?>
<div class="panel" style="padding:0"><div style="overflow-x:auto"><table style="border-collapse:collapse;font-size:.78rem;min-width:100%">
  <thead><tr><th style="position:sticky;left:0;background:#f1f6f3;text-align:left;padding:8px 12px;min-width:190px">Room type</th>
    <?php foreach ($dates as $d): $wk = in_array((int) date('N', strtotime($d)), [5, 6], true); $today = $d === date('Y-m-d'); ?>
      <th style="padding:6px 2px;text-align:center;min-width:38px;background:<?= $today ? '#fff3cd' : ($wk ? '#e9f4ee' : '#f1f6f3') ?>"><?= e(date('d', strtotime($d))) ?><br><span style="font-weight:400;color:#6b7a72"><?= e(date('D', strtotime($d))) ?></span></th><?php endforeach; ?></tr></thead>
  <tbody>
  <?php foreach ($rooms as $room): $rid = (int) $room['id']; ?><tr>
    <td style="position:sticky;left:0;background:#fff;padding:8px 12px;border-bottom:1px solid #e8efeb"><strong><?= e($room['name']) ?></strong><br><span class="muted"><?= e($room['property_name']) ?> &middot; <?= (int) $room['total_rooms'] ?> rooms</span></td>
    <?php foreach ($dates as $d): $free = $grid[$rid][$d] ?? 0; $tot = (int) $room['total_rooms']; ?>
      <td style="text-align:center;padding:6px 2px;border-bottom:1px solid #e8efeb;border-left:1px solid #f0f4f2;font-weight:600;background:<?= $free === 0 ? '#fdecec' : ($free < $tot ? '#fff8e6' : '#f1faf5') ?>;color:<?= $free === 0 ? '#b02a20' : '#14663f' ?>" title="<?= e($room['name'] . ' - ' . date('d M', strtotime($d)) . ': ' . $free . ' of ' . $tot . ' free') ?>"><?= $free ?></td><?php endforeach; ?></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>
<?php tl_shell_bottom();
