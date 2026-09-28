<?php
/**
 * admin/transport_install.php
 * ---------------------------------------------------------------
 * RUN ONCE (log in to /admin first, then open /admin/transport_install.php).
 *
 * - Creates any missing transport tables.
 * - Adds any missing columns to tables that already exist (nothing is
 *   ever dropped or renamed, so your existing bookings are safe).
 * - Relaxes stray NOT NULL columns that would make inserts fail.
 * - Seeds the number sequences (TRK / ENQ / INV / RCPT).
 *
 * It is safe to run repeatedly. DELETE THIS FILE when you are done.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_admin();

$pdo = get_db();
$log = [];
$ran = false;

/* -------------------------------------------------------------
   Canonical schema. Column names match transport_add.php /
   transport_edit.php / transport_manage.php exactly.
------------------------------------------------------------- */
$M = 'DECIMAL(12,2) NOT NULL DEFAULT 0.00';

$SCHEMA = [
    'transport_bookings' => [
        'tracking_id' => 'VARCHAR(40) NULL', 'enquiry_reference' => 'VARCHAR(40) NULL',
        'customer_name' => 'VARCHAR(150) NULL', 'company_name' => 'VARCHAR(150) NULL',
        'email' => 'VARCHAR(150) NULL', 'phone' => 'VARCHAR(30) NULL', 'alternate_phone' => 'VARCHAR(30) NULL',
        'service_type' => 'VARCHAR(100) NULL', 'truck_type' => 'VARCHAR(100) NULL', 'vehicle_type' => 'VARCHAR(100) NULL',
        'cargo_type' => 'VARCHAR(100) NULL', 'cargo_description' => 'TEXT NULL',
        'cargo_weight' => 'DECIMAL(12,2) NULL', 'cargo_unit' => "VARCHAR(10) NOT NULL DEFAULT 'kg'",
        'number_of_packages' => 'INT NULL', 'cargo_value' => 'DECIMAL(14,2) NULL',
        'fragile' => 'TINYINT(1) NOT NULL DEFAULT 0', 'hazardous' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'temperature_controlled' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'pickup_address' => 'TEXT NULL', 'pickup_city' => 'VARCHAR(100) NULL', 'pickup_state' => 'VARCHAR(100) NULL',
        'pickup_pincode' => 'VARCHAR(12) NULL', 'pickup_contact_person' => 'VARCHAR(150) NULL', 'pickup_contact_number' => 'VARCHAR(30) NULL',
        'drop_address' => 'TEXT NULL', 'drop_city' => 'VARCHAR(100) NULL', 'drop_state' => 'VARCHAR(100) NULL',
        'drop_pincode' => 'VARCHAR(12) NULL', 'drop_contact_person' => 'VARCHAR(150) NULL', 'drop_contact_number' => 'VARCHAR(30) NULL',
        'distance_km' => 'DECIMAL(9,2) NULL', 'expected_days' => 'INT NULL', 'estimated_duration' => 'INT NULL',
        'status' => "VARCHAR(30) NOT NULL DEFAULT 'pending'", 'priority' => "VARCHAR(20) NOT NULL DEFAULT 'normal'",
        'driver_id' => 'INT UNSIGNED NULL', 'vehicle_id' => 'INT UNSIGNED NULL',
        'total_amount' => $M, 'gst_amount' => $M, 'toll_amount' => $M, 'fuel_charge' => $M, 'labour_charge' => $M,
        'extra_charge' => $M, 'discount' => $M, 'grand_total' => $M, 'advance_paid' => $M, 'paid_amount' => $M, 'balance_amount' => $M,
        'payment_status' => "VARCHAR(20) NOT NULL DEFAULT 'unpaid'", 'payment_mode' => 'VARCHAR(30) NULL',
        'invoice_number' => 'VARCHAR(40) NULL', 'lr_number' => 'VARCHAR(40) NULL', 'quotation_number' => 'VARCHAR(40) NULL',
        'scheduled_pickup' => 'DATETIME NULL', 'expected_delivery' => 'DATETIME NULL',
        'remarks' => 'TEXT NULL', 'internal_notes' => 'TEXT NULL', 'customer_notes' => 'TEXT NULL',
        'tracking_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'created_by' => 'INT UNSIGNED NULL', 'updated_by' => 'INT UNSIGNED NULL',
        'created_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL', 'deleted_at' => 'DATETIME NULL',
        // ---- new in this upgrade ----
        'source' => "VARCHAR(20) NOT NULL DEFAULT 'admin'",
        'source_ip' => 'VARCHAR(45) NULL',
        'gst_percentage' => 'DECIMAL(5,2) NULL',
        'invoice_date' => 'DATE NULL',
        'delivered_at' => 'DATETIME NULL',
        'received_by' => 'VARCHAR(150) NULL',
    ],
    'transport_booking_timeline' => [
        'booking_id' => 'INT UNSIGNED NOT NULL', 'tracking_id' => 'VARCHAR(40) NULL', 'status' => 'VARCHAR(30) NULL',
        'title' => 'VARCHAR(200) NULL', 'description' => 'TEXT NULL', 'current_location' => 'VARCHAR(200) NULL',
        'customer_visible' => 'TINYINT(1) NOT NULL DEFAULT 1', 'created_by' => 'INT UNSIGNED NULL', 'created_at' => 'DATETIME NULL',
    ],
    'transport_payment_history' => [
        'booking_id' => 'INT UNSIGNED NOT NULL', 'tracking_id' => 'VARCHAR(40) NULL', 'receipt_number' => 'VARCHAR(40) NULL',
        'invoice_number' => 'VARCHAR(40) NULL', 'payment_type' => 'VARCHAR(20) NULL', 'payment_mode' => 'VARCHAR(30) NULL',
        'amount' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00', 'transaction_id' => 'VARCHAR(100) NULL', 'utr_number' => 'VARCHAR(100) NULL',
        'cheque_number' => 'VARCHAR(50) NULL', 'bank_name' => 'VARCHAR(100) NULL', 'payment_date' => 'DATETIME NULL',
        'verified' => "VARCHAR(20) NOT NULL DEFAULT 'pending'", 'verified_by' => 'INT UNSIGNED NULL', 'verified_at' => 'DATETIME NULL',
        'remarks' => 'TEXT NULL', 'created_by' => 'INT UNSIGNED NULL', 'created_at' => 'DATETIME NULL',
    ],
    'transport_documents' => [
        'booking_id' => 'INT UNSIGNED NOT NULL', 'tracking_id' => 'VARCHAR(40) NULL', 'document_type' => 'VARCHAR(50) NULL',
        'file_name' => 'VARCHAR(255) NULL', 'stored_name' => 'VARCHAR(255) NULL', 'mime_type' => 'VARCHAR(100) NULL',
        'file_size' => 'INT UNSIGNED NULL', 'uploaded_by' => 'INT UNSIGNED NULL', 'created_at' => 'DATETIME NULL',
    ],
    'transport_sequences' => [
        'sequence_name' => 'VARCHAR(50) NOT NULL', 'prefix' => 'VARCHAR(20) NOT NULL', 'current_year' => 'INT NULL',
        'current_number' => 'INT NOT NULL DEFAULT 0', 'padding' => 'TINYINT NOT NULL DEFAULT 5',
        'separator_char' => "VARCHAR(5) NOT NULL DEFAULT '-'", 'reset_every_year' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1', 'created_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL',
    ],
    'transport_drivers' => [
        'full_name' => 'VARCHAR(150) NOT NULL', 'mobile' => 'VARCHAR(30) NULL', 'license_number' => 'VARCHAR(50) NULL',
        'license_expiry' => 'DATE NULL', 'address' => 'TEXT NULL', 'employment_status' => "VARCHAR(20) NOT NULL DEFAULT 'active'",
        'notes' => 'TEXT NULL', 'photo' => 'VARCHAR(255) NULL', 'created_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL',
    ],
    'transport_verify_attempts' => [
        'tracking_id' => 'VARCHAR(40) NULL', 'ip' => 'VARCHAR(45) NULL', 'created_at' => 'DATETIME NULL',
    ],
    'transport_vehicles' => [
        'registration_number' => 'VARCHAR(30) NOT NULL', 'vehicle_type' => 'VARCHAR(100) NULL', 'make_model' => 'VARCHAR(100) NULL',
        'capacity_tons' => 'DECIMAL(6,2) NULL', 'insurance_expiry' => 'DATE NULL', 'fitness_expiry' => 'DATE NULL',
        'permit_expiry' => 'DATE NULL', 'status' => "VARCHAR(20) NOT NULL DEFAULT 'active'", 'notes' => 'TEXT NULL',
        'created_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL',
    ],
];

/* Helpful indexes (name => [table, columns]) */
$INDEXES = [
    'idx_tb_tracking' => ['transport_bookings', 'tracking_id'],
    'idx_tb_status'   => ['transport_bookings', 'status, deleted_at'],
    'idx_tb_phone'    => ['transport_bookings', 'phone'],
    'idx_tb_ip'       => ['transport_bookings', 'source_ip, created_at'],
    'idx_tl_booking'  => ['transport_booking_timeline', 'booking_id, created_at'],
    'idx_ph_booking'  => ['transport_payment_history', 'booking_id'],
    'idx_td_booking'  => ['transport_documents', 'booking_id'],
    'idx_seq_name'    => ['transport_sequences', 'sequence_name'],
    'idx_va_track'    => ['transport_verify_attempts', 'tracking_id, created_at'],
    'idx_va_ip'       => ['transport_verify_attempts', 'ip, created_at'],
];

/* -------------------------------------------------------------
   Helpers
------------------------------------------------------------- */
function inst_table_exists(PDO $pdo, string $t): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
    $s->execute([':t' => $t]);
    return (int) $s->fetchColumn() > 0;
}

function inst_columns(PDO $pdo, string $t): array
{
    $s = $pdo->prepare(
        'SELECT column_name AS n, column_type AS t, data_type AS dt, is_nullable AS nl, column_default AS d, extra AS x, column_key AS k
         FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t'
    );
    $s->execute([':t' => $t]);
    $out = [];
    foreach ($s->fetchAll() as $r) {
        $out[strtolower((string) $r['n'])] = $r;
    }
    return $out;
}

function inst_index_exists(PDO $pdo, string $t, string $name): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i');
    $s->execute([':t' => $t, ':i' => $name]);
    return (int) $s->fetchColumn() > 0;
}

/* -------------------------------------------------------------
   Run
------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $ran = true;

    foreach ($SCHEMA as $table => $cols) {
        try {
            if (!inst_table_exists($pdo, $table)) {
                $defs = ['`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'];
                foreach ($cols as $c => $ddl) {
                    $defs[] = "`$c` $ddl";
                }
                $pdo->exec("CREATE TABLE `$table` (" . implode(', ', $defs) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
                $log[] = ['ok', "Created table $table"];
                continue;
            }

            $existing = inst_columns($pdo, $table);
            $added = 0;
            foreach ($cols as $c => $ddl) {
                if (!isset($existing[strtolower($c)])) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$c` $ddl");
                    $log[] = ['ok', "Added column $table.$c"];
                    $added++;
                }
            }

            // Relax unknown NOT NULL columns without a default (they would break our INSERTs).
            $existing = inst_columns($pdo, $table);
            $known = array_change_key_case($cols, CASE_LOWER);
            foreach ($existing as $name => $r) {
                if ($name === 'id' || isset($known[$name])) {
                    continue;
                }
                $isAuto = stripos((string) $r['x'], 'auto_increment') !== false;
                $isGen  = stripos((string) $r['x'], 'generated') !== false;
                if ($r['nl'] === 'NO' && $r['d'] === null && !$isAuto && !$isGen && $r['k'] !== 'PRI') {
                    $pdo->exec("ALTER TABLE `$table` MODIFY `{$r['n']}` {$r['t']} NULL DEFAULT NULL");
                    $log[] = ['ok', "Relaxed NOT NULL on $table.{$r['n']} (old column, now optional)"];
                }
            }
            if ($added === 0) {
                $log[] = ['ok', "$table is already up to date"];
            }
        } catch (Throwable $e) {
            error_log('transport_install ' . $table . ': ' . $e->getMessage());
            $log[] = ['err', "$table: " . $e->getMessage()];
        }
    }

    // payment_history.verified must hold the words 'verified' / 'pending'
    try {
        $c = inst_columns($pdo, 'transport_payment_history');
        if (isset($c['verified']) && in_array(strtolower((string) $c['verified']['dt']), ['tinyint', 'smallint', 'int', 'bit'], true)) {
            $pdo->exec("ALTER TABLE transport_payment_history MODIFY `verified` VARCHAR(20) NOT NULL DEFAULT 'pending'");
            $pdo->exec("UPDATE transport_payment_history SET verified = 'verified' WHERE verified = '1'");
            $pdo->exec("UPDATE transport_payment_history SET verified = 'pending' WHERE verified = '0'");
            $log[] = ['ok', 'Converted transport_payment_history.verified to text (verified / pending)'];
        }
    } catch (Throwable $e) {
        $log[] = ['err', 'verified column: ' . $e->getMessage()];
    }

    // Old timeline columns from earlier code versions: make them optional if present.
    try {
        $c = inst_columns($pdo, 'transport_booking_timeline');
        foreach (['is_customer_visible', 'created_by_admin_id'] as $legacy) {
            if (isset($c[$legacy]) && $c[$legacy]['nl'] === 'NO') {
                $pdo->exec("ALTER TABLE transport_booking_timeline MODIFY `$legacy` {$c[$legacy]['t']} NULL DEFAULT NULL");
                $log[] = ['ok', "Made legacy column transport_booking_timeline.$legacy optional"];
            }
        }
        // Rows that were saved with the old visibility column stay visible to customers.
        if (isset($c['is_customer_visible'])) {
            $pdo->exec('UPDATE transport_booking_timeline SET customer_visible = 1 WHERE is_customer_visible = 1 AND customer_visible = 0');
        }
    } catch (Throwable $e) {
        $log[] = ['err', 'legacy timeline columns: ' . $e->getMessage()];
    }

    // Indexes
    foreach ($INDEXES as $name => [$table, $cols]) {
        try {
            if (inst_table_exists($pdo, $table) && !inst_index_exists($pdo, $table, $name)) {
                $pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` ($cols)");
                $log[] = ['ok', "Added index $name"];
            }
        } catch (Throwable $e) {
            $log[] = ['err', "index $name: " . $e->getMessage()];
        }
    }

    // Sequences
    $seqs = ['tracking_id' => 'TRK', 'enquiry_reference' => 'ENQ', 'invoice' => 'INV', 'receipt' => 'RCPT'];
    foreach ($seqs as $name => $prefix) {
        try {
            $s = $pdo->prepare('SELECT COUNT(*) FROM transport_sequences WHERE sequence_name = :n');
            $s->execute([':n' => $name]);
            if ((int) $s->fetchColumn() === 0) {
                $pdo->prepare(
                    "INSERT INTO transport_sequences (sequence_name, prefix, current_year, current_number, padding, separator_char, reset_every_year, is_active, created_at, updated_at)
                     VALUES (:n, :p, :y, 0, 5, '-', 1, 1, NOW(), NOW())"
                )->execute([':n' => $name, ':p' => $prefix, ':y' => (int) date('Y')]);
                $log[] = ['ok', "Seeded number sequence '$name' ($prefix)"];
            }
        } catch (Throwable $e) {
            $log[] = ['err', "sequence $name: " . $e->getMessage()];
        }
    }

    // Uploads folder for POD / documents
    $dir = __DIR__ . '/uploads/transport';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (is_dir($dir)) {
        @file_put_contents(__DIR__ . '/uploads/transport/.htaccess', "Order Allow,Deny\nDeny from all\n");
        $log[] = ['ok', 'Document folder admin/uploads/transport is ready and web-blocked'];
    } else {
        $log[] = ['err', 'Could not create admin/uploads/transport - create it manually (chmod 755)'];
    }

    log_activity((int) $_SESSION['admin_id'], 'transport_install_run', 'schema check completed');
}

$errCount = count(array_filter($log, fn($l) => $l[0] === 'err'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Transport Installer</title>
<style>
  body{font-family:Inter,Arial,sans-serif;background:#f4f8f4;color:#1c2b24;margin:0;padding:32px}
  .card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #dfeadf;border-radius:14px;padding:28px;box-shadow:0 10px 30px rgba(15,76,45,.08)}
  h1{margin:0 0 6px;color:#0f4c2d;font-size:1.5rem} p{color:#5b6b63;line-height:1.6}
  button{background:#198754;color:#fff;border:0;border-radius:8px;padding:12px 22px;font-weight:600;font-size:1rem;cursor:pointer}
  ul{list-style:none;padding:0;margin:18px 0} li{padding:8px 12px;border-radius:8px;margin-bottom:6px;font-size:.92rem}
  li.ok{background:#e8f5ee;color:#14663f} li.err{background:#fdecec;color:#a12a2a}
  .warn{background:#fff8e1;border:1px solid #ffe08a;padding:12px 16px;border-radius:8px;color:#7a5b00;font-size:.9rem}
  a{color:#198754}
</style>
</head>
<body>
<div class="card">
  <h1>Transport module installer</h1>
  <p>Creates or repairs the transport tables so every page uses the same columns. Existing bookings are not touched. Safe to run more than once.</p>

  <?php if (!$ran): ?>
    <form method="post">
      <?= csrf_field() ?>
      <button type="submit">Run installer now</button>
    </form>
  <?php else: ?>
    <ul>
      <?php foreach ($log as [$type, $msg]): ?>
        <li class="<?= $type === 'err' ? 'err' : 'ok' ?>"><?= e($msg) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php if ($errCount === 0): ?>
      <p><strong>All done.</strong> Now <strong>delete this file</strong> (<code>admin/transport_install.php</code>) from your server, then open the
      <a href="transport_manage.php">Transport dashboard</a>.</p>
    <?php else: ?>
      <div class="warn"><?= (int) $errCount ?> step(s) failed (red above). Copy those lines and send them to me - do not delete this file yet.</div>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
