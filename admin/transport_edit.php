<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();

$bookingId = (int) ($_GET['id'] ?? 0);
if ($bookingId <= 0) {
    $_SESSION['flash_error'] = 'Invalid booking.';
    header('Location: transport_manage.php');
    exit;
}

$existing = $pdo->prepare('SELECT * FROM transport_bookings WHERE id = :id AND deleted_at IS NULL');
$existing->execute([':id' => $bookingId]);
$booking = $existing->fetch();

if (!$booking) {
    $_SESSION['flash_error'] = 'Booking not found or has been deleted.';
    header('Location: transport_manage.php');
    exit;
}

/* =====================================================================
   LOOKUP DATA — kept in sync with transport_manage.php / transport_add.php
===================================================================== */
$STATUS_LIST = [
    'pending'          => 'Pending',
    'confirmed'        => 'Confirmed',
    'driver_assigned'  => 'Driver Assigned',
    'picked_up'        => 'Picked Up',
    'in_transit'       => 'In Transit',
    'out_for_delivery' => 'Out For Delivery',
    'delivered'        => 'Delivered',
    'cancelled'        => 'Cancelled',
    'returned'         => 'Returned',
];
$PAYMENT_STATUS_LIST = [
    'unpaid'   => 'Unpaid',
    'partial'  => 'Partially Paid',
    'paid'     => 'Paid',
    'refunded' => 'Refunded',
];
$PRIORITY_LIST = [
    'low'    => 'Low',
    'normal' => 'Normal',
    'high'   => 'High',
    'urgent' => 'Urgent',
];
$CARGO_WEIGHT_UNITS = ['kg' => 'kg', 'ton' => 'ton', 'lb' => 'lb'];

/* =====================================================================
   REFERENCE LISTS FOR SELECTS
===================================================================== */
$drivers = $pdo->query(
    "SELECT id, full_name AS driver_name, mobile AS phone FROM transport_drivers WHERE employment_status = 'active' OR id = " . (int) $booking['driver_id'] . " ORDER BY full_name"
)->fetchAll();

$vehicles = $pdo->query(
    "SELECT id, registration_number, vehicle_type FROM transport_vehicles WHERE status = 'active' OR id = " . (int) $booking['vehicle_id'] . " ORDER BY registration_number"
)->fetchAll();



/* =====================================================================
   FORM DEFAULTS — pre-filled from the existing booking
===================================================================== */
$editableFields = [
    'customer_name', 'company_name', 'email',
    'phone', 'alternate_phone', 'service_type',
    'pickup_contact_person', 'pickup_contact_number',
    'drop_contact_person', 'drop_contact_number',
    'pickup_address', 'pickup_city', 'pickup_state', 'pickup_pincode',
    'drop_address', 'drop_city', 'drop_state', 'drop_pincode',
    'cargo_type', 'cargo_description', 'cargo_weight', 'cargo_unit',
    'cargo_value', 'number_of_packages',
    'fragile', 'hazardous', 'temperature_controlled',
    'truck_type', 'vehicle_type',
    'status', 'priority',
    'driver_id', 'vehicle_id',
    'distance_km', 'estimated_duration', 'expected_days',
    'pickup_date', 'pickup_time', 'expected_delivery_date', 'expected_delivery_time',
    'total_amount', 'gst_percentage', 'discount_amount', 'other_charges',
    'advance_amount', 'paid_amount', 'payment_status', 'payment_mode',
    'quotation_number', 'invoice_number', 'lr_number',
    'tracking_enabled',
    'customer_notes', 'internal_notes',
];

$data = [];
foreach ($editableFields as $key) {
    $data[$key] = (string) ($booking[$key] ?? '');
}
// Dates come back from MySQL sometimes as full datetimes — trim to the input's expected format.
$scheduledPickupRaw   = (string) ($booking['scheduled_pickup'] ?? '');
$expectedDeliveryRaw  = (string) ($booking['expected_delivery'] ?? '');
$data['pickup_date']  = $scheduledPickupRaw ? substr($scheduledPickupRaw, 0, 10) : '';
$data['pickup_time']  = $scheduledPickupRaw ? substr($scheduledPickupRaw, 11, 5) : '';
$data['expected_delivery_date'] = $expectedDeliveryRaw ? substr($expectedDeliveryRaw, 0, 10) : '';
$data['expected_delivery_time'] = $expectedDeliveryRaw ? substr($expectedDeliveryRaw, 11, 5) : '';

$originalStatus = $booking['status'];
$errors = [];

/* =====================================================================
   HANDLE SUBMIT
===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    foreach ($editableFields as $key) {
        if (in_array($key, ['fragile', 'hazardous', 'temperature_controlled', 'tracking_enabled'], true)) {
            $data[$key] = isset($_POST[$key]) ? 1 : 0;
        } else {
            $data[$key] = clean_input((string) ($_POST[$key] ?? ''));
        }
    }

    // ---- Validation ----
    if ($data['customer_name'] === '') $errors['customer_name'] = 'Customer name is required.';
    if ($data['phone'] === '')         $errors['phone'] = 'Phone number is required.';
    if ($data['pickup_address'] === '') $errors['pickup_address'] = 'Pickup address is required.';
    if ($data['pickup_city'] === '')    $errors['pickup_city'] = 'Pickup city is required.';
    if ($data['drop_address'] === '') $errors['drop_address'] = 'Delivery address is required.';
    if ($data['drop_city'] === '')    $errors['drop_city'] = 'Delivery city is required.';
    if ($data['cargo_type'] === '')   $errors['cargo_type'] = 'Cargo type is required.';
    if ($data['vehicle_type'] === '') $errors['vehicle_type'] = 'Requested vehicle type is required.';
    if ($data['pickup_date'] === '')      $errors['pickup_date'] = 'Pickup date is required.';
    if ($data['total_amount'] === '' || !is_numeric($data['total_amount'])) $errors['total_amount'] = 'Enter a valid total amount.';
    if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    if (!array_key_exists($data['status'], $STATUS_LIST)) $errors['status'] = 'Invalid status.';
    if (!array_key_exists($data['priority'], $PRIORITY_LIST)) $errors['priority'] = 'Invalid priority.';
    if (!array_key_exists($data['payment_status'], $PAYMENT_STATUS_LIST)) $errors['payment_status'] = 'Invalid payment status.';

    // ---- Derived amounts (server-side, authoritative) ----
    $totalAmount   = (float) ($data['total_amount'] ?: 0);
    $gstPercentage = (float) ($data['gst_percentage'] ?: 0);
    $discount      = (float) ($data['discount_amount'] ?: 0);
    $otherCharges  = (float) ($data['other_charges'] ?: 0);
    $advance       = (float) ($data['advance_amount'] ?: 0);
    $paid          = (float) ($data['paid_amount'] ?: 0);

    $gstAmount  = round($totalAmount * $gstPercentage / 100, 2);
    $tollAmount = (float) ($booking['toll_amount'] ?? 0);
    $fuelCharge = (float) ($booking['fuel_charge'] ?? 0);
    $labourCharge = (float) ($booking['labour_charge'] ?? 0);
    $grandTotal = round($totalAmount + $gstAmount + $tollAmount + $fuelCharge + $labourCharge + $otherCharges - $discount, 2);
    $balance    = round($grandTotal - $paid, 2);

    $scheduledPickup = $data['pickup_date'] !== ''
        ? $data['pickup_date'] . ' ' . ($data['pickup_time'] !== '' ? $data['pickup_time'] . ':00' : '00:00:00')
        : null;
    $expectedDelivery = $data['expected_delivery_date'] !== ''
        ? $data['expected_delivery_date'] . ' ' . ($data['expected_delivery_time'] !== '' ? $data['expected_delivery_time'] . ':00' : '00:00:00')
        : null;

    if (!$errors) {
        try {
            $pdo->beginTransaction();

           $sql = "UPDATE transport_bookings SET
                        customer_name = :customer_name, company_name = :company_name, email = :email,
                        phone = :phone, alternate_phone = :alternate_phone, service_type = :service_type,
                        pickup_contact_person = :pickup_contact_person, pickup_contact_number = :pickup_contact_number,
                        drop_contact_person = :drop_contact_person, drop_contact_number = :drop_contact_number,
                        pickup_address = :pickup_address, pickup_city = :pickup_city, pickup_state = :pickup_state, pickup_pincode = :pickup_pincode,
                        drop_address = :drop_address, drop_city = :drop_city, drop_state = :drop_state, drop_pincode = :drop_pincode,
                        cargo_type = :cargo_type, cargo_description = :cargo_description, cargo_weight = :cargo_weight, cargo_unit = :cargo_unit,
                        cargo_value = :cargo_value, number_of_packages = :number_of_packages,
                        fragile = :fragile, hazardous = :hazardous, temperature_controlled = :temperature_controlled,
                        truck_type = :truck_type, vehicle_type = :vehicle_type,
                        status = :status, priority = :priority, driver_id = :driver_id, vehicle_id = :vehicle_id,
                        distance_km = :distance_km, expected_days = :expected_days, estimated_duration = :estimated_duration,
                        scheduled_pickup = :scheduled_pickup, expected_delivery = :expected_delivery,
                        total_amount = :total_amount, gst_amount = :gst_amount,
                        discount = :discount, extra_charge = :extra_charge,
                        grand_total = :grand_total, advance_paid = :advance_paid, paid_amount = :paid_amount,
                        balance_amount = :balance_amount, payment_status = :payment_status, payment_mode = :payment_mode,
                        quotation_number = :quotation_number, invoice_number = :invoice_number, lr_number = :lr_number,
                        tracking_enabled = :tracking_enabled,
                        customer_notes = :customer_notes, internal_notes = :internal_notes,
                        updated_by = :updated_by, updated_at = NOW()
                    WHERE id = :id";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':customer_name' => $data['customer_name'],
                ':company_name' => $data['company_name'] ?: null,
                ':email' => $data['email'] ?: null,
                ':phone' => $data['phone'],
                ':alternate_phone' => $data['alternate_phone'] ?: null,
                ':service_type' => $data['service_type'] ?: null,
                ':pickup_contact_person' => $data['pickup_contact_person'] ?: null,
                ':pickup_contact_number' => $data['pickup_contact_number'] ?: null,
                ':drop_contact_person' => $data['drop_contact_person'] ?: null,
                ':drop_contact_number' => $data['drop_contact_number'] ?: null,
                ':pickup_address' => $data['pickup_address'],
                ':pickup_city' => $data['pickup_city'],
                ':pickup_state' => $data['pickup_state'] ?: null,
                ':pickup_pincode' => $data['pickup_pincode'] ?: null,
                ':drop_address' => $data['drop_address'],
                ':drop_city' => $data['drop_city'],
                ':drop_state' => $data['drop_state'] ?: null,
                ':drop_pincode' => $data['drop_pincode'] ?: null,
                ':cargo_type' => $data['cargo_type'],
                ':cargo_description' => $data['cargo_description'] ?: null,
                ':cargo_weight' => $data['cargo_weight'] !== '' ? (float) $data['cargo_weight'] : null,
                ':cargo_unit' => $data['cargo_unit'] ?: 'kg',
                ':cargo_value' => $data['cargo_value'] !== '' ? (float) $data['cargo_value'] : null,
                ':number_of_packages' => $data['number_of_packages'] !== '' ? (int) $data['number_of_packages'] : null,
                ':fragile' => (int) $data['fragile'],
                ':hazardous' => (int) $data['hazardous'],
                ':temperature_controlled' => (int) $data['temperature_controlled'],
                ':truck_type' => $data['truck_type'] ?: null,
                ':vehicle_type' => $data['vehicle_type'],
                ':status' => $data['status'],
                ':priority' => $data['priority'],
                ':driver_id' => $data['driver_id'] !== '' ? (int) $data['driver_id'] : null,
                ':vehicle_id' => $data['vehicle_id'] !== '' ? (int) $data['vehicle_id'] : null,
                ':distance_km' => $data['distance_km'] !== '' ? (float) $data['distance_km'] : null,
                ':expected_days' => $data['expected_days'] !== '' ? (int) $data['expected_days'] : null,
                ':estimated_duration' => $data['estimated_duration'] !== '' ? (int) $data['estimated_duration'] : null,
                ':scheduled_pickup' => $scheduledPickup,
                ':expected_delivery' => $expectedDelivery,
                ':total_amount' => $totalAmount,
                ':gst_amount' => $gstAmount,
                ':discount' => $discount,
                ':extra_charge' => $otherCharges,
                ':grand_total' => $grandTotal,
                ':advance_paid' => $advance,
                ':paid_amount' => $paid,
                ':balance_amount' => $balance,
                ':payment_status' => $data['payment_status'],
                ':payment_mode' => $data['payment_mode'] ?: null,
                ':quotation_number' => $data['quotation_number'] ?: null,
                ':invoice_number' => $data['invoice_number'] ?: null,
                ':lr_number' => $data['lr_number'] ?: null,
                ':tracking_enabled' => (int) $data['tracking_enabled'],
                ':customer_notes' => $data['customer_notes'] ?: null,
                ':internal_notes' => $data['internal_notes'] ?: null,
                ':updated_by' => $_SESSION['admin_id'],
                ':id' => $bookingId,
            ]);

            // Only log a timeline entry when the status actually changed —
            // avoids flooding the customer tracking view with no-op saves.
            if ($data['status'] !== $originalStatus) {
                $tl = $pdo->prepare(
                    'INSERT INTO transport_booking_timeline
                        (booking_id, tracking_id, status, title, description, current_location, customer_visible, created_by, created_at)
                     VALUES (:bid, :tid, :status, :title, :desc, :loc, 1, :admin, NOW())'
                );
                $tl->execute([
                    ':bid' => $bookingId,
                    ':tid' => $booking['tracking_id'],
                    ':status' => $data['status'],
                    ':title' => $STATUS_LIST[$data['status']] ?? ucfirst($data['status']),
                    ':desc' => 'Status updated from "' . ($STATUS_LIST[$originalStatus] ?? $originalStatus) . '" to "' . ($STATUS_LIST[$data['status']] ?? $data['status']) . '".',
                    ':loc' => $data['drop_city'],
                    ':admin' => $_SESSION['admin_id'],
                ]);
            }

            $pdo->commit();

            log_activity((int) $_SESSION['admin_id'], 'transport_booking_updated', "booking_id={$bookingId} tracking_id={$booking['tracking_id']}");
            $_SESSION['flash_success'] = "Booking {$booking['tracking_id']} updated successfully.";
            header('Location: transport_manage.php');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Transport booking update failed: ' . $e->getMessage());
            $errors['__general'] = 'Something went wrong while saving the booking. Please try again.';
        }
    }
}

function safe_scalar_transport(PDO $pdo, string $sql, $default = 0)
{
    try {
        $val = $pdo->query($sql)->fetchColumn();
        return $val === false ? $default : $val;
    } catch (Throwable $e) {
        return $default;
    }
}
$sidebarUserCount = (int) safe_scalar_transport($pdo, 'SELECT COUNT(*) FROM users');

$pageTitle = 'Edit Transport Booking';
require __DIR__ . '/includes/header.php';

/* Small helpers to keep the markup below readable */
function fval(array $data, string $key): string { return e((string) ($data[$key] ?? '')); }
function ferr(array $errors, string $key): string
{
    return isset($errors[$key]) ? '<span class="field-error">' . e($errors[$key]) . '</span>' : '';
}
function fclass(array $errors, string $key): string
{
    return isset($errors[$key]) ? 'has-error' : '';
}
?>

<link rel="stylesheet" href="assets/css/dashboard.css">
<link rel="stylesheet" href="assets/css/admin-theme-green.css">

<style>
  .panel {
    background: var(--surface, #fff);
    border: 1px solid var(--border, #eee);
    border-radius: 12px;
    padding: 20px 22px;
    margin-bottom: 18px;
  }
  .panel-head {
    display: flex; align-items: center; gap: 10px;
    margin-bottom: 16px; padding-bottom: 12px;
    border-bottom: 1px solid var(--border, #eee);
  }
  .panel-head .n {
    width: 26px; height: 26px; border-radius: 50%;
    background: #eef2ff; color: #4b5fd6;
    display: flex; align-items: center; justify-content: center;
    font-size: .78rem; font-weight: 700;
  }
  .panel-head h3 { margin: 0; font-size: 1rem; }
  .panel-head span.sub { font-size: .78rem; color: var(--text-muted); display: block; }

  .form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 14px 16px;
  }
  .form-grid.cols-3 { grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); }
  .field { display: flex; flex-direction: column; gap: 5px; }
  .field.span-2 { grid-column: span 2; }
  .field label { font-size: .8rem; font-weight: 600; color: #33415c; }
  .field label .opt { font-weight: 400; color: var(--text-muted); }
  .field input[type=text], .field input[type=email], .field input[type=number],
  .field input[type=date], .field input[type=time], .field select, .field textarea {
    border: 1px solid var(--border, #ddd);
    border-radius: 8px;
    padding: 9px 11px;
    font-size: .87rem;
    font-family: inherit;
    background: #fff;
  }
  .field textarea { resize: vertical; min-height: 70px; }
  .field.has-error input, .field.has-error select, .field.has-error textarea {
    border-color: #e08a83; background: #fff8f7;
  }
  .field-error { font-size: .74rem; color: #c0362c; }
  .field-hint { font-size: .74rem; color: var(--text-muted); }
  .field input[readonly] { background: #f4f6fb; color: #556; }

  .checkbox-row {
    display: flex; flex-wrap: wrap; gap: 18px;
    align-items: center;
  }
  .checkbox-row label {
    display: flex; align-items: center; gap: 7px;
    font-size: .85rem; font-weight: 500; cursor: pointer;
  }

  .amount-summary {
    background: #f7f9fd; border: 1px dashed #d7deef;
    border-radius: 10px; padding: 14px 16px;
    display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 10px; margin-top: 4px;
  }
  .amount-summary .item { font-size: .8rem; }
  .amount-summary .item strong { display: block; font-size: 1rem; }
  .amount-summary .item.balance strong { color: #c0362c; }

  .form-actions {
    position: sticky; bottom: 0;
    background: rgba(255,255,255,.96);
    backdrop-filter: blur(4px);
    border-top: 1px solid var(--border, #eee);
    padding: 14px 4px;
    display: flex; justify-content: space-between; align-items: center; gap: 10px;
    margin-top: 6px;
  }
  .general-error {
    background:#fdecea;border:1px solid #f5c2bd;color:#7a271a;
    padding:12px 16px;border-radius:8px;margin-bottom:18px;font-size:.9rem;
  }
  .status-change-note {
    font-size: .78rem; color: var(--text-muted); display: flex; align-items: center; gap: 6px;
  }
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
          <div class="breadcrumb">Biome <span class="sep">/</span> <a href="transport_manage.php" class="current" style="text-decoration:none;">Transport Bookings</a> <span class="sep">/</span> <span class="current">Edit Booking</span></div>
          <h1>Edit booking <span class="tracking-id-badge" style="font-size:.9rem;vertical-align:middle;"><?= e($booking['tracking_id']) ?></span></h1>
        </div>
        <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
          <a href="transport_track.php?tracking_id=<?= urlencode($booking['tracking_id']) ?>" class="btn btn-ghost" target="_blank"><i class="fa-solid fa-timeline"></i> View timeline</a>
          <a href="transport_manage.php" class="btn btn-ghost"><i class="fa-solid fa-arrow-left"></i> Back to list</a>
        </div>
      </div>

      <?php if (!empty($errors['__general'])): ?>
        <div class="general-error"><?= e($errors['__general']) ?></div>
      <?php elseif ($errors): ?>
        <div class="general-error">Please fix the highlighted fields below and try again.</div>
      <?php endif; ?>

      <form method="post" action="transport_edit.php?id=<?= $bookingId ?>" id="bookingForm" novalidate>
        <?= csrf_field() ?>

        <!-- ============ Identification (read-only) ============ -->
        <div class="panel">
          <div class="panel-head">
            <div class="n"><i class="fa-solid fa-lock" style="font-size:.7rem;"></i></div>
            <div><h3>Booking identity</h3><span class="sub">Generated automatically — cannot be changed</span></div>
          </div>
          <div class="form-grid cols-3">
            <div class="field">
              <label>Tracking ID</label>
              <input type="text" value="<?= e($booking['tracking_id']) ?>" readonly>
            </div>
            <div class="field">
              <label>Enquiry reference</label>
              <input type="text" value="<?= e($booking['enquiry_reference']) ?>" readonly>
            </div>
            <div class="field">
              <label>Created</label>
              <input type="text" value="<?= e((string) $booking['created_at']) ?>" readonly>
            </div>
          </div>
        </div>

        <!-- ============ 1. Customer Information ============ -->
        <div class="panel">
          <div class="panel-head">
            <div class="n">1</div>
            <div><h3>Customer information</h3><span class="sub">Who this booking is for</span></div>
          </div>
          <div class="form-grid">

            <div class="field <?= fclass($errors, 'customer_name') ?>">
              <label>Customer name *</label>
              <input type="text" name="customer_name" id="customer_name" value="<?= fval($data, 'customer_name') ?>" maxlength="150" required>
              <?= ferr($errors, 'customer_name') ?>
            </div>
            <div class="field">
              <label>Company name <span class="opt">(optional)</span></label>
              <input type="text" name="company_name" id="company_name" value="<?= fval($data, 'company_name') ?>" maxlength="150">
            </div>
            <div class="field <?= fclass($errors, 'phone') ?>">
              <label>Phone *</label>
              <input type="text" name="phone" id="phone" value="<?= fval($data, 'phone') ?>" maxlength="20" required>
              <?= ferr($errors, 'phone') ?>
            </div>
            <div class="field">
              <label>Alternate phone <span class="opt">(optional)</span></label>
              <input type="text" name="alternate_phone" value="<?= fval($data, 'alternate_phone') ?>" maxlength="20">
            </div>
            <div class="field <?= fclass($errors, 'email') ?>">
              <label>Email <span class="opt">(optional)</span></label>
              <input type="email" name="email" id="email" value="<?= fval($data, 'email') ?>" maxlength="150">
              <?= ferr($errors, 'email') ?>
            </div>
            <div class="field">
              <label>Service type <span class="opt">(optional)</span></label>
              <input type="text" name="service_type" value="<?= fval($data, 'service_type') ?>" maxlength="100">
            </div>
          </div>
        </div>

        <!-- ============ 2. Pickup Details ============ -->
        <div class="panel">
          <div class="panel-head">
            <div class="n">2</div>
            <div><h3>Pickup details</h3><span class="sub">Where the shipment starts</span></div>
          </div>
          <div class="form-grid">
            <div class="field">
              <label>Pickup contact person <span class="opt">(optional)</span></label>
              <input type="text" name="pickup_contact_person" value="<?= fval($data, 'pickup_contact_person') ?>" maxlength="100">
            </div>
            <div class="field">
              <label>Pickup contact number <span class="opt">(optional)</span></label>
              <input type="text" name="pickup_contact_number" value="<?= fval($data, 'pickup_contact_number') ?>" maxlength="20">
            </div>
            <div class="field span-2 <?= fclass($errors, 'pickup_address') ?>">
              <label>Pickup address *</label>
              <textarea name="pickup_address" required><?= fval($data, 'pickup_address') ?></textarea>
              <?= ferr($errors, 'pickup_address') ?>
            </div>
            <div class="field <?= fclass($errors, 'pickup_city') ?>">
              <label>Pickup city *</label>
              <input type="text" name="pickup_city" value="<?= fval($data, 'pickup_city') ?>" maxlength="100" required>
              <?= ferr($errors, 'pickup_city') ?>
            </div>
            <div class="field">
              <label>Pickup state <span class="opt">(optional)</span></label>
              <input type="text" name="pickup_state" value="<?= fval($data, 'pickup_state') ?>" maxlength="100">
            </div>
            <div class="field">
              <label>Pickup pincode <span class="opt">(optional)</span></label>
              <input type="text" name="pickup_pincode" value="<?= fval($data, 'pickup_pincode') ?>" maxlength="10">
            </div>
          </div>
        </div>

        <!-- ============ 3. Delivery Details ============ -->
        <div class="panel">
          <div class="panel-head">
            <div class="n">3</div>
            <div><h3>Delivery details</h3><span class="sub">Where the shipment ends up</span></div>
          </div>
          <div class="form-grid">
            <div class="field">
              <label>Delivery contact person <span class="opt">(optional)</span></label>
              <input type="text" name="drop_contact_person" value="<?= fval($data, 'drop_contact_person') ?>" maxlength="100">
            </div>
            <div class="field">
              <label>Delivery contact number <span class="opt">(optional)</span></label>
              <input type="text" name="drop_contact_number" value="<?= fval($data, 'drop_contact_number') ?>" maxlength="20">
            </div>
            <div class="field span-2 <?= fclass($errors, 'drop_address') ?>">
              <label>Delivery address *</label>
              <textarea name="drop_address" required><?= fval($data, 'drop_address') ?></textarea>
              <?= ferr($errors, 'drop_address') ?>
            </div>
            <div class="field <?= fclass($errors, 'drop_city') ?>">
              <label>Delivery city *</label>
              <input type="text" name="drop_city" value="<?= fval($data, 'drop_city') ?>" maxlength="100" required>
              <?= ferr($errors, 'drop_city') ?>
            </div>
            <div class="field">
              <label>Delivery state <span class="opt">(optional)</span></label>
              <input type="text" name="drop_state" value="<?= fval($data, 'drop_state') ?>" maxlength="100">
            </div>
            <div class="field">
              <label>Delivery pincode <span class="opt">(optional)</span></label>
              <input type="text" name="drop_pincode" value="<?= fval($data, 'drop_pincode') ?>" maxlength="10">
            </div>
          </div>
        </div>

        <!-- ============ 4. Cargo Details ============ -->
        <div class="panel">
          <div class="panel-head">
            <div class="n">4</div>
            <div><h3>Cargo details</h3><span class="sub">What's being shipped</span></div>
          </div>
          <div class="form-grid cols-3">
            <div class="field <?= fclass($errors, 'cargo_type') ?>">
              <label>Cargo type *</label>
              <input type="text" name="cargo_type" value="<?= fval($data, 'cargo_type') ?>" maxlength="100" required>
              <?= ferr($errors, 'cargo_type') ?>
            </div>
            <div class="field">
              <label>Number of packages <span class="opt">(optional)</span></label>
              <input type="number" name="number_of_packages" value="<?= fval($data, 'number_of_packages') ?>" min="0">
            </div>
            <div class="field">
              <label>Cargo value (₹) <span class="opt">(optional)</span></label>
              <input type="number" step="0.01" name="cargo_value" value="<?= fval($data, 'cargo_value') ?>" min="0">
            </div>
            <div class="field">
              <label>Weight <span class="opt">(optional)</span></label>
              <input type="number" step="0.01" name="cargo_weight" value="<?= fval($data, 'cargo_weight') ?>" min="0">
            </div>
            <div class="field">
              <label>Weight unit</label>
              <select name="cargo_unit">
                <?php foreach ($CARGO_WEIGHT_UNITS as $k => $lbl): ?>
                  <option value="<?= e($k) ?>" <?= $data['cargo_unit'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            
            <div class="field span-2">
              <label>Cargo description <span class="opt">(optional)</span></label>
              <textarea name="cargo_description"><?= fval($data, 'cargo_description') ?></textarea>
            </div>
            <div class="field">
              <label>Special handling</label>
              <div class="checkbox-row" style="margin-top:6px;">
                <label><input type="checkbox" name="fragile" value="1" <?= $data['fragile'] ? 'checked' : '' ?>> Fragile</label>
                <label><input type="checkbox" name="hazardous" value="1" <?= $data['hazardous'] ? 'checked' : '' ?>> Hazardous</label>
                <label><input type="checkbox" name="temperature_controlled" value="1" <?= $data['temperature_controlled'] ? 'checked' : '' ?>> Temp-controlled</label>
              </div>
            </div>
          </div>
        </div>

        <!-- ============ 5. Vehicle, Driver & Schedule ============ -->
        <div class="panel">
          <div class="panel-head">
            <div class="n">5</div>
            <div><h3>Vehicle, driver &amp; schedule</h3><span class="sub">Assignment and timing</span></div>
          </div>
          <div class="form-grid cols-3">
            <div class="field <?= fclass($errors, 'vehicle_type') ?>">
              <label>Vehicle type requested *</label>
              <input type="text" name="vehicle_type" value="<?= fval($data, 'vehicle_type') ?>" maxlength="100" required>
              <?= ferr($errors, 'vehicle_type') ?>
            </div>
            <div class="field">
              <label>Truck type <span class="opt">(optional)</span></label>
              <input type="text" name="truck_type" value="<?= fval($data, 'truck_type') ?>" maxlength="100">
            </div>
            <div class="field">
              <label>Priority</label>
              <select name="priority">
                <?php foreach ($PRIORITY_LIST as $k => $lbl): ?>
                  <option value="<?= e($k) ?>" <?= $data['priority'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label>Assign vehicle <span class="opt">(optional)</span></label>
              <select name="vehicle_id">
                <option value="">— Unassigned —</option>
                <?php foreach ($vehicles as $v): ?>
                  <option value="<?= (int) $v['id'] ?>" <?= (string) $v['id'] === $data['vehicle_id'] ? 'selected' : '' ?>>
                    <?= e($v['registration_number']) ?> (<?= e($v['vehicle_type']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Assign driver <span class="opt">(optional)</span></label>
              <select name="driver_id">
                <option value="">— Unassigned —</option>
                <?php foreach ($drivers as $d): ?>
                  <option value="<?= (int) $d['id'] ?>" <?= (string) $d['id'] === $data['driver_id'] ? 'selected' : '' ?>>
                    <?= e($d['driver_name']) ?> (<?= e($d['phone']) ?>)
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Status</label>
              <select name="status" id="statusSelect" data-original="<?= e($originalStatus) ?>">
                <?php foreach ($STATUS_LIST as $k => $lbl): ?>
                  <option value="<?= e($k) ?>" <?= $data['status'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
              <span class="field-hint" id="statusChangeHint" style="display:none;">
                <i class="fa-solid fa-circle-info"></i> This will add a new entry to the customer-visible timeline.
              </span>
            </div>

            <div class="field <?= fclass($errors, 'pickup_date') ?>">
              <label>Pickup date *</label>
              <input type="date" name="pickup_date" value="<?= fval($data, 'pickup_date') ?>" required>
              <?= ferr($errors, 'pickup_date') ?>
            </div>
            <div class="field">
              <label>Pickup time <span class="opt">(optional)</span></label>
              <input type="time" name="pickup_time" value="<?= fval($data, 'pickup_time') ?>">
            </div>
            <div class="field">
              <label>Expected delivery date <span class="opt">(optional)</span></label>
              <input type="date" name="expected_delivery_date" value="<?= fval($data, 'expected_delivery_date') ?>">
            </div>
            <div class="field">
              <label>Expected delivery time <span class="opt">(optional)</span></label>
              <input type="time" name="expected_delivery_time" value="<?= fval($data, 'expected_delivery_time') ?>">
            </div>
            <div class="field">
              <label>Distance (km) <span class="opt">(optional)</span></label>
              <input type="number" step="0.1" name="distance_km" value="<?= fval($data, 'distance_km') ?>" min="0">
            </div>
            <div class="field">
              <label>Expected transit (days) <span class="opt">(optional)</span></label>
              <input type="number" name="expected_days" value="<?= fval($data, 'expected_days') ?>" min="0">
            </div>
            <div class="field">
              <label>Estimated duration (mins) <span class="opt">(optional)</span></label>
              <input type="number" name="estimated_duration" value="<?= fval($data, 'estimated_duration') ?>" min="0">
            </div>
          </div>
        </div>

        <!-- ============ 6. Pricing & Payment ============ -->
        <div class="panel">
          <div class="panel-head">
            <div class="n">6</div>
            <div><h3>Pricing &amp; payment</h3><span class="sub">GST, discounts and balance are calculated automatically</span></div>
          </div>
          <div class="form-grid cols-3">
            <div class="field <?= fclass($errors, 'total_amount') ?>">
              <label>Total amount (₹) *</label>
              <input type="number" step="0.01" name="total_amount" id="total_amount" value="<?= fval($data, 'total_amount') ?>" min="0" required>
              <?= ferr($errors, 'total_amount') ?>
            </div>
            <div class="field">
              <label>GST %</label>
              <input type="number" step="0.01" name="gst_percentage" id="gst_percentage" value="<?= fval($data, 'gst_percentage') ?>" min="0" max="100">
            </div>
            <div class="field">
              <label>Discount (₹) <span class="opt">(optional)</span></label>
              <input type="number" step="0.01" name="discount_amount" id="discount_amount" value="<?= fval($data, 'discount_amount') ?>" min="0">
            </div>
            <div class="field">
              <label>Other charges (₹) <span class="opt">(optional)</span></label>
              <input type="number" step="0.01" name="other_charges" id="other_charges" value="<?= fval($data, 'other_charges') ?>" min="0">
            </div>
            <div class="field">
              <label>Advance amount (₹) <span class="opt">(optional)</span></label>
              <input type="number" step="0.01" name="advance_amount" id="advance_amount" value="<?= fval($data, 'advance_amount') ?>" min="0">
            </div>
            <div class="field">
              <label>Paid amount (₹)</label>
              <input type="number" step="0.01" name="paid_amount" id="paid_amount" value="<?= fval($data, 'paid_amount') ?>" min="0">
            </div>
            <div class="field">
              <label>Payment status</label>
              <select name="payment_status" id="payment_status">
                <?php foreach ($PAYMENT_STATUS_LIST as $k => $lbl): ?>
                  <option value="<?= e($k) ?>" <?= $data['payment_status'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="field">
              <label>Customer tracking</label>
              <div class="checkbox-row" style="margin-top:6px;">
                <label><input type="checkbox" name="tracking_enabled" value="1" <?= $data['tracking_enabled'] ? 'checked' : '' ?>> Enable customer tracking page</label>
              </div>
            </div>
          </div>

          <div class="amount-summary">
            <div class="item"><span>GST amount</span><strong id="sumGst">₹0.00</strong></div>
            <div class="item"><span>Grand total</span><strong id="sumNet">₹0.00</strong></div>
            <div class="item balance"><span>Balance due</span><strong id="sumBalance">₹0.00</strong></div>
          </div>
        </div>

        <!-- ============ 7. Notes ============ -->
        <div class="panel">
          <div class="panel-head">
            <div class="n">7</div>
            <div><h3>Notes &amp; instructions</h3><span class="sub">Internal notes are never shown to the customer</span></div>
          </div>
          <div class="form-grid">
            <div class="field span-2">
              <label>Customer notes <span class="opt">(customer-visible, optional)</span></label>
              <textarea name="customer_notes"><?= fval($data, 'customer_notes') ?></textarea>
            </div>
            <div class="field span-2">
              <label>Internal notes <span class="opt">(admin-only, optional)</span></label>
              <textarea name="internal_notes"><?= fval($data, 'internal_notes') ?></textarea>
            </div>
          </div>
        </div>

        <div class="form-actions">
          <span class="status-change-note"><i class="fa-regular fa-clock"></i> Last updated <?= e((string) $booking['updated_at']) ?></span>
          <div style="display:flex;gap:10px;">
            <a href="transport_manage.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save changes</button>
          </div>
        </div>
      </form>

    </main>
  </div>
</div>

<script>
(function () {
  const toggle = document.getElementById('menuToggle');
  const sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () { sidebar.classList.toggle('open'); });
  }

  // Warn when status is being changed (a new timeline entry will be created)
  const statusSelect = document.getElementById('statusSelect');
  const statusHint = document.getElementById('statusChangeHint');
  if (statusSelect && statusHint) {
    const toggleHint = () => {
      statusHint.style.display = statusSelect.value !== statusSelect.dataset.original ? 'block' : 'none';
    };
    statusSelect.addEventListener('change', toggleHint);
    toggleHint();
  }

  // Live GST / net / balance calculation
  const ids = ['total_amount', 'gst_percentage', 'discount_amount', 'other_charges', 'paid_amount'];
  const fmt = (n) => '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  function recalc() {
    const total    = parseFloat(document.getElementById('total_amount').value) || 0;
    const gstPct   = parseFloat(document.getElementById('gst_percentage').value) || 0;
    const discount = parseFloat(document.getElementById('discount_amount').value) || 0;
    const other    = parseFloat(document.getElementById('other_charges').value) || 0;
    const paid     = parseFloat(document.getElementById('paid_amount').value) || 0;

    const gstAmount = total * gstPct / 100;
    const net       = total + gstAmount - discount + other;
    const balance   = net - paid;

    document.getElementById('sumGst').textContent = fmt(gstAmount);
    document.getElementById('sumNet').textContent = fmt(net);
    document.getElementById('sumBalance').textContent = fmt(balance);
  }

  ids.forEach(function (id) {
    const el = document.getElementById(id);
    if (el) el.addEventListener('input', recalc);
  });
  recalc();
})();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>