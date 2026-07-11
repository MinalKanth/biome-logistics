<?php
declare(strict_types=1);

// Enable full error reporting (Development only)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

$bookingId = (int) ($_GET['id'] ?? $_POST['booking_id'] ?? 0);
if ($bookingId <= 0) {
    header('Location: transport_manage.php');
    exit;
}

$PAYMENT_TYPE_LIST = [
    'advance'    => 'Advance',
    'partial'    => 'Partial',
    'final'      => 'Final',
    'refund'     => 'Refund',
    'adjustment' => 'Adjustment',
];
$PAYMENT_MODE_LIST = [
    'cash'          => 'Cash',
    'upi'           => 'UPI',
    'bank_transfer' => 'Bank Transfer',
    'cheque'        => 'Cheque',
    'card'          => 'Card',
    'wallet'        => 'Wallet',
];
$PAYMENT_STATUS_LIST = [
    'unpaid'   => 'Unpaid',
    'partial'  => 'Partially Paid',
    'paid'     => 'Paid',
    'refunded' => 'Refunded',
];

function next_sequence_code(PDO $pdo, string $sequenceName, string $defaultPrefix): string
{
    $year = (int) date('Y');
    $stmt = $pdo->prepare('SELECT * FROM transport_sequences WHERE sequence_name = :n FOR UPDATE');
    $stmt->execute([':n' => $sequenceName]);
    $row = $stmt->fetch();

    if (!$row) {
        $ins = $pdo->prepare(
            'INSERT INTO transport_sequences
                (sequence_name, prefix, current_year, current_number, padding, separator_char, reset_every_year, is_active, created_at, updated_at)
             VALUES (:n, :p, :y, 1, 5, :sep, 1, 1, NOW(), NOW())'
        );
        $ins->execute([':n' => $sequenceName, ':p' => $defaultPrefix, ':y' => $year, ':sep' => '-']);
        $number = 1; $prefix = $defaultPrefix; $sep = '-'; $pad = 5;
    } else {
        $resetEveryYear = (bool) $row['reset_every_year'];
        $number = ($resetEveryYear && (int) $row['current_year'] !== $year) ? 1 : (int) $row['current_number'] + 1;
        $upd = $pdo->prepare('UPDATE transport_sequences SET current_number = :num, current_year = :y, updated_at = NOW() WHERE id = :id');
        $upd->execute([':num' => $number, ':y' => $year, ':id' => $row['id']]);
        $prefix = $row['prefix']; $sep = $row['separator_char'] ?? '-'; $pad = (int) ($row['padding'] ?: 5);
    }
    $yy = date('y');
    return $prefix . $sep . $yy . $sep . str_pad((string) $number, $pad, '0', STR_PAD_LEFT);
}

function inr(float $amount): string
{
    return '₹' . number_format($amount, 2);
}

/* =====================================================================
   FETCH BOOKING
===================================================================== */
$stmt = $pdo->prepare('SELECT * FROM transport_bookings WHERE id = :id');
$stmt->execute([':id' => $bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    $_SESSION['flash_error'] = 'That booking could not be found.';
    header('Location: transport_manage.php');
    exit;
}

$errors = [];

/* =====================================================================
   HANDLE: record a new payment
===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_payment') {
    csrf_require_valid();

    $paymentType   = (string) ($_POST['payment_type'] ?? '');
    $paymentMode   = (string) ($_POST['payment_mode'] ?? '');
    $amount        = (float) ($_POST['amount'] ?? 0);
    $paymentDate   = clean_input((string) ($_POST['payment_date'] ?? date('Y-m-d\TH:i')));
    $transactionId = clean_input((string) ($_POST['transaction_id'] ?? ''));
    $utrNumber     = clean_input((string) ($_POST['utr_number'] ?? ''));
    $chequeNumber  = clean_input((string) ($_POST['cheque_number'] ?? ''));
    $bankName      = clean_input((string) ($_POST['bank_name'] ?? ''));
    $remarks       = trim((string) ($_POST['remarks'] ?? ''));

    if (!array_key_exists($paymentType, $PAYMENT_TYPE_LIST)) $errors[] = 'Select a valid payment type.';
    if (!array_key_exists($paymentMode, $PAYMENT_MODE_LIST)) $errors[] = 'Select a valid payment mode.';
    if ($amount <= 0) $errors[] = 'Enter a payment amount greater than zero.';
    if ($paymentDate === '') $errors[] = 'Payment date is required.';

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $receiptNumber = next_sequence_code($pdo, 'receipt', 'RCPT');

            $pdo->prepare(
                'INSERT INTO transport_payment_history
                    (booking_id, tracking_id, receipt_number, invoice_number, payment_type, payment_mode,
                     amount, transaction_id, utr_number, cheque_number, bank_name, payment_date,
                     verified, remarks, created_by, created_at)
                 VALUES
                    (:bid, :tid, :receipt, :inv, :ptype, :pmode,
                     :amount, :txn, :utr, :cheque, :bank, :pdate,
                     :verified, :remarks, :admin, NOW())'
            )->execute([
                ':bid'      => $bookingId,
                ':tid'      => $booking['tracking_id'],
                ':receipt'  => $receiptNumber,
                ':inv'      => $booking['invoice_number'] ?: null,
                ':ptype'    => $paymentType,
                ':pmode'    => $paymentMode,
                ':amount'   => $amount,
                ':txn'      => $transactionId ?: null,
                ':utr'      => $utrNumber ?: null,
                ':cheque'   => $chequeNumber ?: null,
                ':bank'     => $bankName ?: null,
                ':pdate'    => str_replace('T', ' ', $paymentDate),
                ':verified' => $paymentType === 'refund' ? 'pending' : 'verified',
                ':remarks'  => $remarks ?: null,
                ':admin'    => $_SESSION['admin_id'],
            ]);

            // Recalculate running totals on the booking itself.
            $delta = $paymentType === 'refund' ? -$amount : $amount;
            $newPaid = max(0, (float) $booking['paid_amount'] + $delta);
            $netAmount = (float) ($booking['net_amount'] ?? $booking['total_amount'] ?? 0);
            $newBalance = round($netAmount - $newPaid, 2);

            if ($newPaid <= 0) {
                $newPaymentStatus = 'unpaid';
            } elseif ($newBalance > 0.009) {
                $newPaymentStatus = 'partial';
            } else {
                $newPaymentStatus = 'paid';
            }

            $pdo->prepare(
                'UPDATE transport_bookings
                 SET paid_amount = :paid, balance_amount = :balance, payment_status = :pstatus,
                     updated_by = :admin, updated_at = NOW()
                 WHERE id = :id'
            )->execute([
                ':paid'    => $newPaid,
                ':balance' => $newBalance,
                ':pstatus' => $newPaymentStatus,
                ':admin'   => $_SESSION['admin_id'],
                ':id'      => $bookingId,
            ]);

            // Drop a customer-visible timeline entry for the payment.
            $label = $PAYMENT_TYPE_LIST[$paymentType];
            $pdo->prepare(
                'INSERT INTO transport_booking_timeline
                    (booking_id, tracking_id, status, title, description, is_customer_visible, created_by_admin_id, created_at)
                 VALUES (:bid, :tid, :status, :title, :desc, 1, :admin, NOW())'
            )->execute([
                ':bid'    => $bookingId,
                ':tid'    => $booking['tracking_id'],
                ':status' => $booking['status'],
                ':title'  => $paymentType === 'refund' ? 'Refund Processed' : $label . ' Payment Received',
                ':desc'   => ($paymentType === 'refund' ? 'Refund of ' : 'Payment of ') . inr($amount)
                             . ' received via ' . $PAYMENT_MODE_LIST[$paymentMode] . '.',
                ':admin'  => $_SESSION['admin_id'],
            ]);

            $pdo->commit();

            log_activity((int) $_SESSION['admin_id'], 'transport_payment_recorded',
                "booking_id={$bookingId} receipt={$receiptNumber} amount={$amount}");
            $_SESSION['flash_success'] = "Payment of " . inr($amount) . " recorded (Receipt {$receiptNumber}).";
            header('Location: payment.php?id=' . $bookingId);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Payment recording failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong while saving the payment. Please try again.';
        }
    }
}

/* =====================================================================
   HANDLE: verify a pending payment (e.g. a refund awaiting approval)
===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'verify_payment') {
    csrf_require_valid();
    $paymentId = (int) ($_POST['payment_id'] ?? 0);
    if ($paymentId > 0) {
        $pdo->prepare(
            'UPDATE transport_payment_history SET verified = :v, verified_by = :admin, verified_at = NOW() WHERE id = :pid AND booking_id = :bid'
        )->execute([
            ':v'     => 'verified',
            ':admin' => $_SESSION['admin_id'],
            ':pid'   => $paymentId,
            ':bid'   => $bookingId,
        ]);
        log_activity((int) $_SESSION['admin_id'], 'transport_payment_verified', "payment_id={$paymentId}");
        $_SESSION['flash_success'] = 'Payment marked as verified.';
    }
    header('Location: payment.php?id=' . $bookingId);
    exit;
}

// Refresh booking after any update above
$stmt = $pdo->prepare('SELECT * FROM transport_bookings WHERE id = :id');
$stmt->execute([':id' => $bookingId]);
$booking = $stmt->fetch();

$paymentsStmt = $pdo->prepare('SELECT * FROM transport_payment_history WHERE booking_id = :id ORDER BY payment_date DESC, id DESC');
$paymentsStmt->execute([':id' => $bookingId]);
$payments = $paymentsStmt->fetchAll();

function safe_scalar_transport(PDO $pdo, string $sql, $default = 0)
{
    try { $val = $pdo->query($sql)->fetchColumn(); return $val === false ? $default : $val; }
    catch (Throwable $e) { return $default; }
}
$sidebarUserCount = (int) safe_scalar_transport($pdo, 'SELECT COUNT(*) FROM users');
$sidebarBlogCount = (int) safe_scalar_transport($pdo, 'SELECT COUNT(*) FROM blog_posts');
$sidebarTransportCount = (int) safe_scalar_transport($pdo, 'SELECT COUNT(*) FROM transport_bookings WHERE deleted_at IS NULL');

$netAmount = (float) ($booking['net_amount'] ?? $booking['total_amount'] ?? 0);
$paidAmount = (float) $booking['paid_amount'];
$balanceAmount = (float) $booking['balance_amount'];

$pageTitle = 'Record Payment — ' . $booking['tracking_id'];
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/admin-theme-green.css">

<style>
  .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 22px; }
  @media (max-width: 900px) { .summary-grid { grid-template-columns: repeat(2, 1fr); } }
  .summary-card { background: var(--surface, #fff); border: 1px solid var(--border, #eee); border-radius: 12px; padding: 16px 18px; }
  .summary-card .label { font-size: .76rem; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: .03em; margin-bottom: 6px; }
  .summary-card .value { font-size: 1.35rem; font-weight: 700; }
  .summary-card.due .value { color: #c0362c; }
  .summary-card.paid .value { color: #1b7a34; }

  .pay-grid { display: grid; grid-template-columns: 1fr 1.3fr; gap: 20px; align-items: start; }
  @media (max-width: 980px) { .pay-grid { grid-template-columns: 1fr; } }

  .card-panel { background: var(--surface, #fff); border: 1px solid var(--border, #e9ece9); border-radius: 12px; padding: 22px; }
  .card-panel h2 { font-size: .95rem; margin: 0 0 4px; }
  .card-panel .card-sub { font-size: .8rem; color: var(--text-muted); margin-bottom: 16px; }

  .form-group { margin-bottom: 16px; }
  .form-group label { display: block; font-weight: 600; margin-bottom: 6px; font-size: .85rem; }
  .form-group input, .form-group select, .form-group textarea {
    width: 100%; box-sizing: border-box; padding: 10px 12px; border-radius: 8px;
    border: 1px solid var(--border, #e2e5e3); font-size: .9rem; font-family: inherit;
  }
  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }

  .form-errors { background: #fdecea; border: 1px solid #f5c2bd; color: #7a271a; padding: 12px 16px; border-radius: 8px; margin-bottom: 18px; font-size: .88rem; }
  .form-errors ul { margin: 0; padding-left: 18px; }

  .pay-history { display: flex; flex-direction: column; gap: 10px; }
  .pay-row { border: 1px solid var(--border, #eee); border-radius: 10px; padding: 12px 14px; font-size: .85rem; }
  .pay-row .top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
  .pay-row .amt { font-weight: 700; font-size: 1.05rem; }
  .pay-row .amt.refund { color: #c0362c; }
  .pay-row .meta { color: var(--text-muted); font-size: .78rem; }
  .pay-row .receipt { font-family: ui-monospace, monospace; font-size: .74rem; background: #f4f6fb; border: 1px solid #e4e8f2; border-radius: 5px; padding: 2px 6px; }
</style>

<div class="app-shell">

  <!-- ===================== SIDEBAR ===================== -->
  <?php require __DIR__ . '/includes/sidebar.php'; ?>

  <!-- ===================== MAIN COLUMN ===================== -->
  <div class="main-col">
    <header class="topbar">
      <div style="display:flex;align-items:center;gap:14px;">
        <div class="menu-toggle" id="menuToggle"><i class="fa-solid fa-bars"></i></div>
        <div class="topbar-search">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" placeholder="Search users, logs, settings…">
          <kbd>⌘K</kbd>
        </div>
      </div>
      <div class="topbar-right">
        <div class="icon-btn" title="Notifications"><i class="fa-regular fa-bell"></i><span class="dot"></span></div>
        <div class="icon-btn" title="Help &amp; documentation"><i class="fa-regular fa-circle-question"></i></div>
        <div class="topbar-divider"></div>
        <div class="profile-chip">
          <div class="avatar"><?= e(strtoupper(substr($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'A', 0, 1))) ?></div>
          <div class="who">
            <strong><?= e($_SESSION['admin_name'] ?? $_SESSION['admin_username'] ?? 'Administrator') ?></strong>
            <span><?= e($_SESSION['admin_role'] ?? 'Super Admin') ?></span>
          </div>
          <i class="fa-solid fa-chevron-down" style="font-size:10px;color:var(--text-muted);"></i>
        </div>
      </div>
    </header>

    <main class="content">
      <div class="page-head">
        <div>
          <div class="breadcrumb">Biome <span class="sep">/</span> <a href="transport_manage.php" style="color:inherit;text-decoration:none;">Transport Bookings</a> <span class="sep">/</span> <span class="current"><?= e($booking['tracking_id']) ?></span></div>
          <h1>Payments — <?= e($booking['tracking_id']) ?></h1>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <a href="timeline.php?id=<?= $bookingId ?>" class="btn btn-ghost"><i class="fa-solid fa-timeline"></i> Timeline</a>
          <a href="invoice.php?id=<?= $bookingId ?>" class="btn btn-ghost" target="_blank"><i class="fa-solid fa-file-invoice"></i> Invoice</a>
          <a href="transport_edit.php?id=<?= $bookingId ?>" class="btn btn-secondary"><i class="fa-solid fa-pen"></i> Edit Booking</a>
          <a href="transport_manage.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back</a>
        </div>
      </div>

      <?php if (!empty($_SESSION['flash_success'])): ?>
        <div style="background:#e6f4ea;border:1px solid #bfe4c9;color:#1b7a34;padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:.9rem;"><?= e($_SESSION['flash_success']) ?></div>
        <?php unset($_SESSION['flash_success']); ?>
      <?php endif; ?>

      <div class="summary-grid">
        <div class="summary-card"><div class="label">Net Amount</div><div class="value"><?= e(inr($netAmount)) ?></div></div>
        <div class="summary-card paid"><div class="label">Paid Amount</div><div class="value"><?= e(inr($paidAmount)) ?></div></div>
        <div class="summary-card due"><div class="label">Balance Due</div><div class="value"><?= e(inr($balanceAmount)) ?></div></div>
        <div class="summary-card">
          <div class="label">Payment Status</div>
          <div class="value" style="font-size:1rem;">
            <span class="badge badge-<?= $booking['payment_status'] === 'paid' ? 'success' : ($booking['payment_status'] === 'partial' ? 'warning' : 'danger') ?>">
              <?= e($PAYMENT_STATUS_LIST[$booking['payment_status']] ?? $booking['payment_status']) ?>
            </span>
          </div>
        </div>
      </div>

      <?php if ($errors): ?>
        <div class="form-errors"><ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
      <?php endif; ?>

      <div class="pay-grid">
        <!-- ---------- Record payment form ---------- -->
        <div class="card-panel">
          <h2>Record a payment</h2>
          <div class="card-sub">Updates the booking's paid amount, balance and status automatically.</div>

          <form method="post" action="payment.php?id=<?= $bookingId ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="booking_id" value="<?= $bookingId ?>">

            <div class="form-row">
              <div class="form-group">
                <label>Payment type</label>
                <select name="payment_type" required>
                  <?php foreach ($PAYMENT_TYPE_LIST as $k => $lbl): ?>
                    <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label>Payment mode</label>
                <select name="payment_mode" required>
                  <?php foreach ($PAYMENT_MODE_LIST as $k => $lbl): ?>
                    <option value="<?= e($k) ?>"><?= e($lbl) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label>Amount (₹)</label>
                <input type="number" step="0.01" name="amount" min="0.01" required>
              </div>
              <div class="form-group">
                <label>Payment date &amp; time</label>
                <input type="datetime-local" name="payment_date" value="<?= e(date('Y-m-d\TH:i')) ?>" required>
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label>Transaction / UPI ref <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
                <input type="text" name="transaction_id" maxlength="150">
              </div>
              <div class="form-group">
                <label>UTR number <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
                <input type="text" name="utr_number" maxlength="150">
              </div>
            </div>

            <div class="form-row">
              <div class="form-group">
                <label>Cheque number <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
                <input type="text" name="cheque_number" maxlength="100">
              </div>
              <div class="form-group">
                <label>Bank name <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
                <input type="text" name="bank_name" maxlength="150">
              </div>
            </div>

            <div class="form-group">
              <label>Remarks <span style="font-weight:400;color:var(--text-muted);">(optional)</span></label>
              <textarea name="remarks" rows="3"></textarea>
            </div>

            <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;">
              <i class="fa-solid fa-indian-rupee-sign"></i> Record payment
            </button>
          </form>
        </div>

        <!-- ---------- Payment history ---------- -->
        <div class="card-panel">
          <h2>Payment history</h2>
          <div class="card-sub"><?= count($payments) ?> payment<?= count($payments) === 1 ? '' : 's' ?> recorded.</div>

          <?php if (!$payments): ?>
            <div class="empty-state">
              <i class="fa-solid fa-receipt"></i>
              <p>No payments recorded yet.</p>
              <span>Payments you record will show up here.</span>
            </div>
          <?php else: ?>
            <div class="pay-history">
              <?php foreach ($payments as $p): ?>
                <div class="pay-row">
                  <div class="top">
                    <span class="amt <?= $p['payment_type'] === 'refund' ? 'refund' : '' ?>">
                      <?= $p['payment_type'] === 'refund' ? '−' : '+' ?><?= e(inr((float) $p['amount'])) ?>
                    </span>
                    <span class="receipt"><?= e($p['receipt_number']) ?></span>
                  </div>
                  <div class="meta">
                    <?= e($PAYMENT_TYPE_LIST[$p['payment_type']] ?? $p['payment_type']) ?> via
                    <?= e($PAYMENT_MODE_LIST[$p['payment_mode']] ?? $p['payment_mode']) ?>
                    &middot; <?= e((string) $p['payment_date']) ?>
                    &middot;
                    <span class="badge badge-<?= $p['verified'] === 'verified' ? 'success' : ($p['verified'] === 'rejected' ? 'danger' : 'muted') ?>" style="font-size:.68rem;">
                      <?= e(ucfirst($p['verified'])) ?>
                    </span>
                  </div>
                  <?php if ($p['remarks']): ?>
                    <div class="meta" style="margin-top:4px;"><?= e($p['remarks']) ?></div>
                  <?php endif; ?>
                  <?php if ($p['verified'] === 'pending'): ?>
                    <form method="post" action="payment.php?id=<?= $bookingId ?>" style="margin-top:8px;">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="verify_payment">
                      <input type="hidden" name="payment_id" value="<?= (int) $p['id'] ?>">
                      <button type="submit" class="btn btn-small btn-secondary"><i class="fa-solid fa-check"></i> Mark verified</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="dash-footer">
        <span>&copy; <?= date('Y') ?> Biome Control Panel. All rights reserved.</span>
        <span><a href="settings.php">Settings</a> &nbsp;·&nbsp; <a href="security.php">Security</a> &nbsp;·&nbsp; <a href="logs.php">Activity logs</a></span>
      </div>
    </main>
  </div>
</div>

<script>
(function () {
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) toggle.addEventListener('click', () => sidebar.classList.toggle('open'));
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>