<?php
/**
 * admin/hotel_view.php?id=X - one reservation: confirm, check-in (rooms + ID), check-out, cancel, no-show,
 * charges & discount, payments / refunds, invoice, notes, timeline.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hotel_lib.php';
require_once __DIR__ . '/includes/transport_shell.php';
require_admin();

$pdo = get_db();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$adminId = (int) $_SESSION['admin_id'];
$ST = htl_statuses();
$FLOW = htl_status_flow();
$sql = 'SELECT r.*, p.name AS property_name, p.check_in_time, p.check_out_time, p.phone AS property_phone, t.name AS room_name FROM hotel_reservations r
        JOIN hotel_properties p ON p.id = r.property_id JOIN hotel_room_types t ON t.id = r.room_type_id WHERE r.id = :id AND r.deleted_at IS NULL';
$load = static function () use ($pdo, $sql, $id) { $s = $pdo->prepare($sql); $s->execute([':id' => $id]); return $s->fetch() ?: null; };
$r = $id > 0 ? $load() : null;
if (!$r) { $_SESSION['flash_error_page'] = 'Reservation not found.'; header('Location: hotel_reservations.php'); exit; }
$ok = static function (string $m): void { $_SESSION['flash_success_page'] = $m; };
$bad = static function (string $m): void { $_SESSION['flash_error_page'] = $m; };
$money = static fn($v): float => (is_numeric($v) && (float) $v >= 0) ? round((float) $v, 2) : 0.0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $act = (string) ($_POST['action'] ?? '');
    try {
        if ($act === 'status') {
            $to = (string) ($_POST['to'] ?? '');
            $note = mb_substr(clean_input((string) ($_POST['note'] ?? '')), 0, 255);
            $pdo->beginTransaction();
            $lk = $pdo->prepare('SELECT * FROM hotel_reservations WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
            $lk->execute([':id' => $id]);
            $cur = $lk->fetch();
            if (!$cur || !isset($ST[$to]) || !in_array($to, $FLOW[$cur['status']] ?? [], true)) {
                throw new RuntimeException('That change is not allowed from "' . ($ST[$cur['status'] ?? '']['label'] ?? '') . '".');
            }
            $sets = ['status = :s', 'updated_at = NOW()'];
            $p = [':s' => $to, ':id' => $id];
            $title = 'Status: ' . $ST[$to]['label'];
            $visible = true;
            $mail = null;
            $today = date('Y-m-d');
            if ($to === 'confirmed') {
                if ((float) $cur['total_amount'] <= 0) { throw new RuntimeException('Set a price before confirming.'); }
                $title = 'Booking confirmed';
                $mail = ['Booking confirmed', '<p>Your stay is confirmed.</p>' . htl_stay_summary_html($r + ['status' => 'confirmed', 'total_amount' => $cur['total_amount']]) . '<p>Check-in from ' . tl_e((string) $r['check_in_time']) . ', check-out by ' . tl_e((string) $r['check_out_time']) . '.</p>'];
            }
            if ($to === 'pending' && $cur['status'] === 'cancelled') {   // re-open: rooms must still be free
                $room = htl_room($pdo, (int) $cur['room_type_id'], true);
                $av = $room ? htl_availability($pdo, $room, (string) $cur['check_in'], (string) $cur['check_out'], $id) : ['min' => 0];
                if (!$room || $av['min'] < (int) $cur['rooms']) { throw new RuntimeException('Those rooms are no longer free for the same dates, so the booking cannot be re-opened.'); }
                $sets[] = 'cancel_reason = NULL';
                $title = 'Booking re-opened';
                $visible = false;
            }
            if ($to === 'checked_in') {
                if ($cur['check_in'] > $today) { throw new RuntimeException('Check-in is on ' . date('d M Y', strtotime((string) $cur['check_in'])) . '. You can check the guest in from that day.'); }
                if ($cur['check_out'] <= $today) { throw new RuntimeException('The stay dates are already over. Edit the dates first.'); }
                $rn = mb_substr(clean_input((string) ($_POST['room_numbers'] ?? '')), 0, 100);
                if ($rn !== '') { $sets[] = 'room_numbers = :rn'; $p[':rn'] = $rn; }
                if (trim((string) $cur['id_number']) === '' && trim((string) ($_POST['id_number'] ?? '')) === '') { throw new RuntimeException('Record the guest\'s ID proof (type and number) at check-in.'); }
                if (trim((string) ($_POST['id_number'] ?? '')) !== '') {
                    $sets[] = 'id_type = :it'; $p[':it'] = mb_substr(clean_input((string) ($_POST['id_type'] ?? '')), 0, 30) ?: 'Other';
                    $sets[] = 'id_number = :in'; $p[':in'] = mb_substr(clean_input((string) $_POST['id_number']), 0, 40);
                }
                $sets[] = 'checked_in_at = NOW()';
                $title = 'Guest checked in' . ($rn !== '' ? ' - room ' . $rn : '');
            }
            if ($to === 'checked_out') {
                $sets[] = 'checked_out_at = NOW()';
                $title = 'Guest checked out';
                $mail = ['Thank you for staying with us', '<p>Thank you for choosing ' . tl_e((string) $r['property_name']) . '. We hope to welcome you again soon.</p>'];
                if (!$cur['invoice_number'] && (float) $cur['total_amount'] > 0) {
                    $sets[] = 'invoice_number = :inv'; $p[':inv'] = tl_next_sequence($pdo, 'hotel_invoice', 'HINV'); $sets[] = 'invoice_date = CURDATE()';
                }
            }
            if ($to === 'cancelled') {
                if ($note === '') { throw new RuntimeException('Please give a cancellation reason.'); }
                $sets[] = 'cancel_reason = :cr'; $p[':cr'] = $note;
                $title = 'Booking cancelled';
                $mail = ['Booking cancelled', '<p>Your booking has been cancelled. Reason: ' . tl_e($note) . '</p>' . ((float) $cur['paid_amount'] > 0.009 ? '<p>We will contact you about your refund.</p>' : '')];
            }
            if ($to === 'no_show') {
                if ($cur['check_in'] > $today) { throw new RuntimeException('A no-show can only be marked from the check-in date.'); }
                $title = 'Marked as no-show';
            }
            $pdo->prepare('UPDATE hotel_reservations SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($p);
            htl_timeline($pdo, $id, $to, $title, $note !== '' ? $note : null, $visible, $adminId);
            $pdo->commit();
            log_activity($adminId, 'hotel_status', "id={$id} {$cur['status']}->{$to}");
            if ($mail) { htl_mail_guest($pdo, $r, $mail[0], $mail[1]); }
            $ok('Status updated to "' . $ST[$to]['label'] . '".' . ($to === 'cancelled' && (float) $cur['paid_amount'] > 0.009 ? ' ' . tl_inr($cur['paid_amount']) . ' was received - record a refund when you return it.' : ''));

        } elseif ($act === 'charges') {
            if (!empty($r['invoice_number'])) { throw new RuntimeException('An invoice has been issued, so charges are locked.'); }
            if (in_array($r['status'], ['cancelled', 'no_show'], true)) { throw new RuntimeException('This reservation is closed.'); }
            $other = $money($_POST['other_charges'] ?? 0);
            $disc = $money($_POST['discount'] ?? 0);
            $gp = (isset($_POST['gst_pct']) && is_numeric($_POST['gst_pct'])) ? (float) $_POST['gst_pct'] : -1;
            if ($gp < 0 || $gp > 28) { throw new RuntimeException('GST % must be between 0 and 28.'); }
            if ($disc > (float) $r['room_total'] + (float) $r['extra_total'] + $other) { throw new RuntimeException('Discount cannot exceed the charges.'); }
            $pdo->prepare('UPDATE hotel_reservations SET other_charges = :o, discount = :d, gst_pct = :g WHERE id = :id')->execute([':o' => $other, ':d' => $disc, ':g' => $gp, ':id' => $id]);
            htl_recalc($pdo, $id);
            htl_timeline($pdo, $id, (string) $r['status'], 'Charges updated', null, false, $adminId);
            $ok('Charges saved.');

        } elseif ($act === 'payment') {
            $type = ($_POST['entry_type'] ?? 'payment') === 'refund' ? 'refund' : 'payment';
            $amt = (isset($_POST['amount']) && is_numeric($_POST['amount'])) ? round((float) $_POST['amount'], 2) : 0.0;
            $mode = isset(htl_payment_modes()[$_POST['payment_mode'] ?? '']) ? (string) $_POST['payment_mode'] : 'cash';
            $date = htl_date((string) ($_POST['payment_date'] ?? ''));
            $ref = mb_substr(clean_input((string) ($_POST['reference'] ?? '')), 0, 100);
            $rem = mb_substr(clean_input((string) ($_POST['remarks'] ?? '')), 0, 255);
            if ($amt <= 0) { throw new RuntimeException('Enter a valid amount.'); }
            if (!$date || $date > date('Y-m-d')) { throw new RuntimeException('Payment date must be a valid date, not in the future.'); }
            $t = htl_totals($r);
            if ($type === 'payment') {
                if ($r['status'] === 'cancelled' || $r['status'] === 'no_show') { throw new RuntimeException('This reservation is closed. Only refunds can be recorded.'); }
                if ($amt > $t['balance'] + 0.005) { throw new RuntimeException('Amount is more than the balance due (' . tl_inr($t['balance']) . ').'); }
            } elseif ($amt > $t['paid'] + 0.005) { throw new RuntimeException('Refund is more than the amount received.'); }
            $pdo->beginTransaction();
            $rc = tl_next_sequence($pdo, 'hotel_receipt', 'HRC');
            $pdo->prepare('INSERT INTO hotel_payments (reservation_id, receipt_number, entry_type, amount, payment_mode, reference, payment_date, remarks, created_by, created_at) VALUES (:r,:rc,:t,:a,:m,:ref,:d,:rem,:by,NOW())')
                ->execute([':r' => $id, ':rc' => $rc, ':t' => $type, ':a' => $amt, ':m' => $mode, ':ref' => $ref ?: null, ':d' => $date . ' ' . date('H:i:s'), ':rem' => $rem ?: null, ':by' => $adminId]);
            htl_recalc($pdo, $id);
            htl_timeline($pdo, $id, (string) $r['status'], ($type === 'refund' ? 'Refund of ' : 'Payment of ') . tl_inr($amt) . ' recorded', htl_payment_modes()[$mode], $type === 'payment', $adminId);
            $pdo->commit();
            log_activity($adminId, 'hotel_' . $type, "id={$id} amt={$amt}");
            $ok(($type === 'refund' ? 'Refund' : 'Payment') . ' recorded (' . $rc . ').');

        } elseif ($act === 'invoice') {
            if ($r['invoice_number']) { throw new RuntimeException('Invoice already issued.'); }
            if (!in_array($r['status'], ['confirmed', 'checked_in', 'checked_out'], true) || (float) $r['total_amount'] <= 0) { throw new RuntimeException('An invoice needs a confirmed booking with a price.'); }
            $pdo->beginTransaction();
            $no = tl_next_sequence($pdo, 'hotel_invoice', 'HINV');
            $pdo->prepare('UPDATE hotel_reservations SET invoice_number = :n, invoice_date = CURDATE() WHERE id = :id')->execute([':n' => $no, ':id' => $id]);
            htl_timeline($pdo, $id, (string) $r['status'], 'Invoice ' . $no . ' issued', null, false, $adminId);
            $pdo->commit();
            $ok('Invoice ' . $no . ' issued. Charges are now locked.');

        } elseif ($act === 'notes') {
            $pdo->prepare('UPDATE hotel_reservations SET internal_notes = :n, room_numbers = :rn, id_type = :it, id_number = :in, updated_at = NOW() WHERE id = :id')
                ->execute([':n' => mb_substr(trim((string) ($_POST['internal_notes'] ?? '')), 0, 3000) ?: null, ':rn' => mb_substr(clean_input((string) ($_POST['room_numbers'] ?? '')), 0, 100) ?: null,
                    ':it' => mb_substr(clean_input((string) ($_POST['id_type'] ?? '')), 0, 30) ?: null, ':in' => mb_substr(clean_input((string) ($_POST['id_number'] ?? '')), 0, 40) ?: null, ':id' => $id]);
            $ok('Saved.');
        }
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $bad($e->getMessage());
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('hotel_view [' . $act . ']: ' . $e->getMessage());
        $bad('Something went wrong. Nothing was changed.');
    }
    header('Location: hotel_view.php?id=' . $id);
    exit;
}

$tl = $pdo->prepare('SELECT t.*, a.full_name AS admin_name FROM hotel_timeline t LEFT JOIN admins a ON a.id = t.created_by WHERE reservation_id = :id ORDER BY t.id DESC');
$tl->execute([':id' => $id]);
$timeline = $tl->fetchAll();
$py = $pdo->prepare('SELECT * FROM hotel_payments WHERE reservation_id = :id ORDER BY id DESC');
$py->execute([':id' => $id]);
$payments = $py->fetchAll();
$t = htl_totals($r);
$next = $FLOW[$r['status']] ?? [];
$locked = !empty($r['invoice_number']);
$closed = in_array($r['status'], ['cancelled', 'no_show'], true);
$gstLbl = rtrim(rtrim(number_format((float) $r['gst_pct'], 2), '0'), '.');
$sc = static fn(string $s) => $ST[$s]['icon'];

tl_shell_top('Reservation ' . $r['reference'], $r['reference'], ['Hotels' => 'hotel_dashboard.php', 'Reservations' => 'hotel_reservations.php'],
    (in_array($r['status'], ['pending', 'confirmed'], true) ? '<a class="btn btn-secondary" href="hotel_form.php?id=' . $id . '"><i class="fa-solid fa-pen"></i> Change</a>' : '')
    . '<a class="btn btn-secondary" target="_blank" href="hotel_invoice.php?id=' . $id . '&amp;doc=card"><i class="fa-solid fa-id-card"></i> Registration card</a>'
    . ($locked ? '<a class="btn btn-secondary" target="_blank" href="hotel_invoice.php?id=' . $id . '"><i class="fa-solid fa-file-invoice"></i> Invoice</a>' : ''));
?>
<div class="tx-hero">
  <div>
    <div class="tid"><?= e($r['reference']) ?></div>
    <div class="ref">Received <?= e(tl_dt($r['created_at'])) ?> &middot; via <?= e($r['source']) ?></div>
    <div class="route"><i class="fa-solid fa-hotel"></i> <?= e($r['property_name']) ?> &middot; <?= e($r['room_name']) ?> &times; <?= (int) $r['rooms'] ?></div>
    <div class="badges"><?= htl_badge((string) $r['status']) ?> <?= htl_pay_badge((string) $r['payment_status']) ?><span class="badge badge-muted"><?= (int) $r['nights'] ?> night<?= (int) $r['nights'] > 1 ? 's' : '' ?></span></div>
  </div>
  <div class="tx-hero-actions">
    <a class="btn btn-secondary" href="tel:<?= e(htl_clean_phone($r['phone'])) ?>"><i class="fa-solid fa-phone"></i> Call</a>
    <a class="btn btn-secondary" target="_blank" rel="noopener" href="<?= e(htl_wa_link($r['phone'], 'Hello ' . $r['guest_name'] . ', regarding your booking ' . $r['reference'] . ' at ' . $r['property_name'] . '.')) ?>"><i class="fa-brands fa-whatsapp"></i> WhatsApp</a>
  </div>
</div>

<div class="tx-grid">
<div>
  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-circle-info"></i> Stay details</h3></div>
    <div class="info-grid">
      <div class="info-item"><div class="label">Guest</div><div class="value"><?= e($r['guest_name']) ?></div></div>
      <div class="info-item"><div class="label">Mobile</div><div class="value"><?= e($r['phone']) ?></div></div>
      <div class="info-item"><div class="label">E-mail</div><div class="value<?= $r['email'] ? '' : ' muted' ?>"><?= e($r['email'] ?: 'Not given') ?></div></div>
      <div class="info-item"><div class="label">Check-in</div><div class="value"><?= e(date('D, d M Y', strtotime($r['check_in']))) ?><br><small class="muted">from <?= e($r['check_in_time']) ?><?= $r['arrival_time'] ? ' · arriving ' . e(substr((string) $r['arrival_time'], 0, 5)) : '' ?></small></div></div>
      <div class="info-item"><div class="label">Check-out</div><div class="value"><?= e(date('D, d M Y', strtotime($r['check_out']))) ?><br><small class="muted">by <?= e($r['check_out_time']) ?></small></div></div>
      <div class="info-item"><div class="label">Guests</div><div class="value"><?= (int) $r['adults'] ?> adult(s), <?= (int) $r['children'] ?> child(ren)</div></div>
      <?php if ($r['room_numbers']): ?><div class="info-item"><div class="label">Room no.</div><div class="value"><?= e($r['room_numbers']) ?></div></div><?php endif; ?>
      <?php if ($r['id_number']): ?><div class="info-item"><div class="label">ID proof</div><div class="value"><?= e(($r['id_type'] ?: 'ID') . ' · ' . $r['id_number']) ?></div></div><?php endif; ?>
    </div>
    <?php if ($r['special_requests']): ?><div class="info-item" style="margin-top:14px"><div class="label">Special requests</div><div class="value" style="font-weight:400;white-space:pre-line"><?= e($r['special_requests']) ?></div></div><?php endif; ?>
    <?php if ($r['cancel_reason']): ?><div class="tx-flash err" style="margin:14px 0 0">Cancelled: <?= e($r['cancel_reason']) ?></div><?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-indian-rupee-sign"></i> Charges &amp; payments</h3>
      <?php if (!$locked && in_array($r['status'], ['confirmed', 'checked_in', 'checked_out'], true) && (float) $r['total_amount'] > 0): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="invoice"><button class="btn btn-small btn-primary" onclick="return confirm('Issue the invoice? Charges will be locked.')"><i class="fa-solid fa-file-invoice"></i> Issue invoice</button></form><?php endif; ?></div>
    <div class="amount-strip" style="margin-bottom:16px">
      <div class="item"><div class="label">Room charges</div><div class="value"><?= e(tl_inr((float) $r['room_total'])) ?></div></div>
      <?php if ((float) $r['extra_total'] > 0): ?><div class="item"><div class="label">Extra guests</div><div class="value"><?= e(tl_inr((float) $r['extra_total'])) ?></div></div><?php endif; ?>
      <?php if ((float) $r['other_charges'] > 0): ?><div class="item"><div class="label">Other charges</div><div class="value"><?= e(tl_inr((float) $r['other_charges'])) ?></div></div><?php endif; ?>
      <?php if ((float) $r['discount'] > 0): ?><div class="item"><div class="label">Discount</div><div class="value">-<?= e(tl_inr((float) $r['discount'])) ?></div></div><?php endif; ?>
      <div class="item"><div class="label">GST <?= e($gstLbl) ?>%</div><div class="value"><?= e(tl_inr($t['gst'])) ?></div></div>
      <div class="item ok"><div class="label">Total</div><div class="value"><?= e(tl_inr($t['total'])) ?></div></div>
      <div class="item ok"><div class="label">Received</div><div class="value"><?= e(tl_inr($t['paid'])) ?></div></div>
      <div class="item <?= $t['balance'] > 0 ? 'due' : 'ok' ?>"><div class="label">Balance</div><div class="value"><?= e(tl_inr($t['balance'])) ?></div></div>
    </div>
    <form method="post" class="tx-form" <?= ($locked || $closed) ? 'style="opacity:.55;pointer-events:none"' : '' ?>><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="charges">
      <div><label>Other charges - food, laundry (&#8377;)</label><input type="number" step="0.01" min="0" name="other_charges" value="<?= e((string) $r['other_charges']) ?>"></div>
      <div><label>Discount (&#8377;)</label><input type="number" step="0.01" min="0" name="discount" value="<?= e((string) $r['discount']) ?>"></div>
      <div><label>GST %</label><input type="number" step="0.01" min="0" max="28" name="gst_pct" value="<?= e((string) $r['gst_pct']) ?>"></div>
      <div style="align-self:end"><button class="btn btn-primary">Save charges</button></div>
    </form>
    <h4 style="margin:22px 0 10px;font-size:.9rem">Record a payment</h4>
    <form method="post" class="tx-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="payment">
      <div><label>Type</label><select name="entry_type"><option value="payment">Payment received</option><option value="refund">Refund given</option></select></div>
      <div><label>Amount (&#8377;)</label><input type="number" step="0.01" min="0.01" name="amount" required value="<?= $t['balance'] > 0 ? e((string) $t['balance']) : '' ?>"></div>
      <div><label>Mode</label><select name="payment_mode"><?php foreach (htl_payment_modes() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div><label>Date</label><input type="date" name="payment_date" max="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>"></div>
      <div><label>Txn / UPI ref</label><input name="reference" maxlength="100"></div>
      <div><label>Remarks</label><input name="remarks" maxlength="255" placeholder="Advance / balance"></div>
      <div><button class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add entry</button></div>
    </form>
    <?php if ($payments): ?><div class="tx-scroll" style="margin-top:14px"><table class="tx-table"><thead><tr><th>Receipt</th><th>Date</th><th>Mode</th><th>Ref</th><th class="r">Amount</th></tr></thead><tbody>
      <?php foreach ($payments as $p): $rf = $p['entry_type'] === 'refund'; ?><tr><td><?= e($p['receipt_number']) ?></td><td><?= e(tl_dt($p['payment_date'], 'd M Y')) ?></td><td><?= e(htl_payment_modes()[$p['payment_mode']] ?? $p['payment_mode']) ?></td><td><?= e($p['reference'] ?: '-') ?></td>
        <td class="r" style="font-weight:700;color:<?= $rf ? '#b02a20' : '#14663f' ?>"><?= $rf ? '-' : '' ?><?= e(tl_inr($p['amount'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div>

  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-note-sticky"></i> Room, ID &amp; internal notes</h3></div>
    <form method="post" class="tx-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="notes">
      <div><label>Room number(s)</label><input name="room_numbers" maxlength="100" value="<?= e($r['room_numbers']) ?>" placeholder="101, 102"></div>
      <div><label>ID proof type</label><select name="id_type"><?php foreach (['', 'Aadhaar', 'Passport', 'Driving licence', 'Voter ID', 'PAN', 'Other'] as $o): ?><option <?= $r['id_type'] === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select></div>
      <div><label>ID number</label><input name="id_number" maxlength="40" value="<?= e($r['id_number']) ?>"></div>
      <div class="full"><label>Internal notes (never shown to the guest)</label><textarea name="internal_notes" rows="3" maxlength="3000"><?= e($r['internal_notes']) ?></textarea></div>
      <div><button class="btn btn-primary">Save</button></div>
    </form>
  </div>
</div>

<div>
  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-diagram-next"></i> Workflow</h3></div>
    <?php if (!$next): ?><p class="muted" style="margin:0">This reservation is <strong><?= e($ST[$r['status']]['label']) ?></strong>. No further steps.</p><?php endif; ?>
    <?php foreach ($next as $to): $inp = 'width:100%;margin-bottom:6px;border:1px solid #d4e0d9;border-radius:8px;padding:8px 10px'; ?>
      <form method="post" style="margin-bottom:10px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="to" value="<?= e($to) ?>">
        <?php if ($to === 'cancelled'): ?><input name="note" placeholder="Cancellation reason (required)" required maxlength="255" style="<?= $inp ?>"><?php endif; ?>
        <?php if ($to === 'checked_in'): ?>
          <input name="room_numbers" placeholder="Room number(s)" maxlength="100" value="<?= e($r['room_numbers']) ?>" style="<?= $inp ?>">
          <?php if (!$r['id_number']): ?><select name="id_type" style="<?= $inp ?>"><?php foreach (['Aadhaar', 'Passport', 'Driving licence', 'Voter ID', 'PAN', 'Other'] as $o): ?><option><?= e($o) ?></option><?php endforeach; ?></select>
          <input name="id_number" placeholder="ID number (required)" maxlength="40" required style="<?= $inp ?>"><?php endif; ?>
        <?php endif; ?>
        <?php if (in_array($to, ['no_show', 'checked_out'], true)): ?><input name="note" placeholder="Note (optional)" maxlength="255" style="<?= $inp ?>"><?php endif; ?>
        <button class="btn <?= in_array($to, ['cancelled', 'no_show'], true) ? 'btn-danger' : 'btn-primary' ?>" style="width:100%;justify-content:center" <?= $to === 'cancelled' ? 'onclick="return confirm(\'Cancel this booking? The rooms will be released.\')"' : '' ?>><i class="fa-solid <?= e($sc($to)) ?>"></i> <?= $to === 'pending' ? 'Re-open booking' : ($to === 'cancelled' ? 'Cancel booking' : 'Mark ' . e(strtolower($ST[$to]['label']))) ?></button>
      </form>
    <?php endforeach; ?>
    <?php if ($r['status'] === 'checked_in' && $t['balance'] > 0.009): ?><p style="font-size:.82rem;color:#b56a00;margin:6px 0 0"><i class="fa-solid fa-triangle-exclamation"></i> Balance of <?= e(tl_inr($t['balance'])) ?> is still due.</p><?php endif; ?>
  </div>
  <div class="panel">
    <div class="panel-head"><h3><i class="fa-solid fa-clock-rotate-left"></i> Timeline</h3></div>
    <div class="mini-timeline"><?php foreach ($timeline as $e): ?><div class="mini-tl-event"><div class="mini-tl-time"><?= e(tl_dt($e['created_at'], 'd M, h:i A')) ?><?= $e['admin_name'] ? ' &middot; ' . e($e['admin_name']) : '' ?><?= $e['customer_visible'] ? '' : ' &middot; <i class="fa-solid fa-eye-slash" title="Internal"></i>' ?></div>
      <div class="mini-tl-title"><?= e($e['title']) ?></div><?php if ($e['description']): ?><div class="mini-tl-desc"><?= e($e['description']) ?></div><?php endif; ?></div><?php endforeach; ?></div>
  </div>
</div>
</div>
<?php tl_shell_bottom();
