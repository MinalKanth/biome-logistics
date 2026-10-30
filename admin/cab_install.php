<?php
/**
 * admin/cab_install.php - RUN ONCE (safe to re-run), then delete.
 *
 * - Upgrades cab_booking_requests IN PLACE: every existing request is kept.
 * - Creates cab_vehicles, cab_drivers, cab_rates, cab_payments,
 *   cab_booking_timeline, cab_settings.
 * - Gives every existing request a reference number.
 * - Seeds the rate card with SAMPLE rates, switched OFF. Nothing is shown to
 *   customers until you review the rates in Cab > Rates & Settings and activate them.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/cab_lib.php';
require_admin();

$pdo = get_db();
$log = [];
$ran = false;
$M = 'DECIMAL(12,2) NOT NULL DEFAULT 0.00';

$hasCol = function (string $table, string $col) use ($pdo): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
    $s->execute([':t' => $table, ':c' => $col]);
    return (int) $s->fetchColumn() > 0;
};
$hasIdx = function (string $table, string $idx) use ($pdo): bool {
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i');
    $s->execute([':t' => $table, ':i' => $idx]);
    return (int) $s->fetchColumn() > 0;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $ran = true;
    try {
        // ---- new tables -------------------------------------------------
        $pdo->exec("CREATE TABLE IF NOT EXISTS cab_settings (
            setting_key VARCHAR(60) NOT NULL PRIMARY KEY, setting_value TEXT NULL, updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cab_vehicles (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL, category VARCHAR(40) NOT NULL, reg_number VARCHAR(30) NULL,
            seats TINYINT UNSIGNED NOT NULL DEFAULT 4, fuel_type VARCHAR(20) NULL, transmission VARCHAR(20) NULL,
            ac TINYINT(1) NOT NULL DEFAULT 1, color VARCHAR(30) NULL, year_of_make SMALLINT NULL,
            insurance_expiry DATE NULL, permit_expiry DATE NULL, fitness_expiry DATE NULL, puc_expiry DATE NULL,
            odometer INT UNSIGNED NOT NULL DEFAULT 0, status VARCHAR(20) NOT NULL DEFAULT 'available',
            notes TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL,
            UNIQUE KEY uq_reg (reg_number), KEY idx_cat (category), KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cab_drivers (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(150) NOT NULL, phone VARCHAR(20) NOT NULL, alt_phone VARCHAR(20) NULL,
            license_number VARCHAR(40) NULL, license_expiry DATE NULL, badge_number VARCHAR(40) NULL,
            address VARCHAR(255) NULL, joined_on DATE NULL, daily_wage $M,
            status VARCHAR(20) NOT NULL DEFAULT 'active', notes TEXT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL,
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cab_rates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            vehicle_type VARCHAR(40) NOT NULL, service_type VARCHAR(60) NOT NULL,
            per_day $M, per_km $M, min_km_per_day DECIMAL(8,2) NOT NULL DEFAULT 0.00, extra_km_rate $M,
            driver_allowance $M, night_halt $M, security_deposit $M,
            is_active TINYINT(1) NOT NULL DEFAULT 0, updated_at DATETIME NULL,
            UNIQUE KEY uq_rate (vehicle_type, service_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cab_payments (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            booking_id INT UNSIGNED NOT NULL, receipt_number VARCHAR(40) NULL,
            entry_type VARCHAR(10) NOT NULL DEFAULT 'payment', amount $M,
            payment_mode VARCHAR(30) NULL, reference VARCHAR(100) NULL, payment_date DATETIME NULL,
            remarks VARCHAR(255) NULL, created_by INT UNSIGNED NULL, created_at DATETIME NULL,
            KEY idx_booking (booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS cab_booking_timeline (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            booking_id INT UNSIGNED NOT NULL, status VARCHAR(20) NULL, title VARCHAR(160) NOT NULL,
            description TEXT NULL, customer_visible TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT UNSIGNED NULL, created_at DATETIME NULL,
            KEY idx_booking (booking_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $log[] = 'Tables cab_settings, cab_vehicles, cab_drivers, cab_rates, cab_payments, cab_booking_timeline: OK';

        // ---- transport_sequences is shared with the transport module -----
        $pdo->exec("CREATE TABLE IF NOT EXISTS transport_sequences (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sequence_name VARCHAR(60) NOT NULL, prefix VARCHAR(20) NOT NULL,
            current_year INT NOT NULL DEFAULT 0, current_number INT NOT NULL DEFAULT 0, padding TINYINT NOT NULL DEFAULT 5,
            separator_char VARCHAR(2) NOT NULL DEFAULT '-', reset_every_year TINYINT(1) NOT NULL DEFAULT 1, is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE KEY uq_name (sequence_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // ---- upgrade cab_booking_requests in place ----------------------
        $cols = [
            'reference'          => 'VARCHAR(40) NULL',
            'pickup_time'        => 'TIME NULL',
            'return_date'        => 'DATE NULL',
            'end_date'           => 'DATE NULL',
            'days'               => 'SMALLINT UNSIGNED NOT NULL DEFAULT 1',
            'distance_km'        => 'DECIMAL(8,1) NULL',
            'vehicle_id'         => 'INT UNSIGNED NULL',
            'driver_id'          => 'INT UNSIGNED NULL',
            'base_fare'          => $M,
            'extra_charges'      => $M,
            'discount'           => $M,
            'gst_pct'            => 'DECIMAL(5,2) NOT NULL DEFAULT 0.00',
            'gst_amount'         => $M,
            'total_fare'         => $M,
            'paid_amount'        => $M,
            'balance_amount'     => $M,
            'security_deposit'   => $M,
            'payment_status'     => "VARCHAR(20) NOT NULL DEFAULT 'unpaid'",
            'invoice_number'     => 'VARCHAR(40) NULL',
            'invoice_date'       => 'DATE NULL',
            'fare_source'        => "VARCHAR(12) NOT NULL DEFAULT 'none'",   // none | estimate | manual
            'source'             => "VARCHAR(20) NOT NULL DEFAULT 'website'", // website | admin | phone
            'internal_notes'     => 'TEXT NULL',
            'follow_up_date'     => 'DATE NULL',
            'cancel_reason'      => 'VARCHAR(255) NULL',
            'odometer_start'     => 'INT UNSIGNED NULL',
            'odometer_end'       => 'INT UNSIGNED NULL',
            'started_at'         => 'DATETIME NULL',
            'completed_at'       => 'DATETIME NULL',
            'rating'             => 'TINYINT UNSIGNED NULL',
            'feedback'           => 'VARCHAR(500) NULL',
            'created_by'         => 'INT UNSIGNED NULL',
            'updated_at'         => 'DATETIME NULL',
            'deleted_at'         => 'DATETIME NULL',
        ];
        $added = 0;
        foreach ($cols as $name => $def) {
            if (!$hasCol('cab_booking_requests', $name)) {
                $pdo->exec("ALTER TABLE cab_booking_requests ADD COLUMN `$name` $def");
                $added++;
            }
        }
        // old enum('new','contacted','closed') -> VARCHAR so the full workflow fits (values are preserved)
        $pdo->exec("ALTER TABLE cab_booking_requests MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'new'");
        foreach (['idx_status' => 'status', 'idx_journey' => 'journey_date', 'idx_ref' => 'reference', 'idx_vehicle' => 'vehicle_id', 'idx_driver' => 'driver_id'] as $i => $c) {
            if (!$hasIdx('cab_booking_requests', $i)) {
                $pdo->exec("ALTER TABLE cab_booking_requests ADD INDEX `$i` (`$c`)");
            }
        }
        $log[] = "cab_booking_requests upgraded ($added new columns). Existing requests kept.";

        // ---- give every existing request a reference + first timeline row
        $pdo->beginTransaction();
        $old = $pdo->query('SELECT id, status, created_at, journey_date FROM cab_booking_requests WHERE reference IS NULL ORDER BY id')->fetchAll();
        foreach ($old as $r) {
            $ref = cab_new_reference($pdo);
            $pdo->prepare('UPDATE cab_booking_requests SET reference = :r, end_date = COALESCE(end_date, journey_date), source = IF(source = \'\', \'website\', source) WHERE id = :id')
                ->execute([':r' => $ref, ':id' => $r['id']]);
            $pdo->prepare('INSERT INTO cab_booking_timeline (booking_id, status, title, customer_visible, created_at) VALUES (:b, :s, :t, 1, :c)')
                ->execute([':b' => $r['id'], ':s' => $r['status'], ':t' => 'Booking request received', ':c' => $r['created_at'] ?: date('Y-m-d H:i:s')]);
        }
        $pdo->commit();
        $log[] = count($old) . ' existing request(s) given reference numbers.';

        // ---- seed rate card: SAMPLE values, inactive --------------------
        $seed = [
            // vehicle           per_day per_km min_km extra_km drv  night dep
            'Hatchback'       => [1800, 11, 250, 10, 300, 300, 5000],
            'Sedan'           => [2400, 13, 250, 12, 300, 300, 7000],
            'SUV'             => [3600, 17, 250, 15, 400, 400, 10000],
            'Innova Crysta'   => [4200, 19, 250, 17, 400, 400, 10000],
            'Tempo Traveller' => [6500, 26, 300, 24, 600, 600, 15000],
            'Luxury Car'      => [8500, 38, 250, 35, 800, 800, 25000],
        ];
        $ins = $pdo->prepare('INSERT IGNORE INTO cab_rates (vehicle_type, service_type, per_day, per_km, min_km_per_day, extra_km_rate, driver_allowance, night_halt, security_deposit, is_active, updated_at)
                              VALUES (:v, :s, :pd, :pk, :mk, :ek, :da, :nh, :sd, 0, NOW())');
        $seeded = 0;
        foreach ($seed as $veh => [$day, $km, $minKm, $extra, $drv, $night, $dep]) {
            foreach (cab_service_types() as $svc => $meta) {
                $mult = ['Self Drive' => 1.0, 'Car With Driver' => 1.0, 'Rental Car (Daily)' => 1.0, 'Rental Car (Weekly)' => 0.92, 'Rental Car (Monthly)' => 0.80][$svc];
                $ins->execute([
                    ':v' => $veh, ':s' => $svc,
                    ':pd' => $meta['mode'] === 'day' ? round($day * $mult, 0) : 0,
                    ':pk' => $meta['mode'] === 'km' ? $km : 0,
                    ':mk' => $minKm, ':ek' => $extra,
                    ':da' => $meta['driver'] ? $drv : 0, ':nh' => $meta['driver'] ? $night : 0,
                    ':sd' => $meta['driver'] ? 0 : $dep,
                ]);
                $seeded += $ins->rowCount();
            }
        }
        $log[] = "Rate card: $seeded sample rate row(s) added, all INACTIVE. Review them before activating.";

        foreach (cab_setting_defaults() as $k => $v) {
            $pdo->prepare('INSERT IGNORE INTO cab_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW())')->execute([':k' => $k, ':v' => $v]);
        }
        $log[] = 'Default settings stored.';
        log_activity((int) $_SESSION['admin_id'], 'cab_install', 'cab module installed/upgraded');
        $log[] = 'DONE. Delete admin/cab_install.php from the server now.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('cab_install: ' . $e->getMessage());
        $log[] = 'ERROR: ' . $e->getMessage();
    }
}
$pageTitle = 'Cab module installer';
require __DIR__ . '/includes/header.php';
?>
<div style="max-width:720px;margin:40px auto;padding:0 18px;font-family:Inter,sans-serif">
  <h1>Cab Services - installer</h1>
  <p>Upgrades your cab table in place (nothing is deleted) and creates the fleet, driver, rate, payment and settings tables. Safe to run more than once.</p>
  <?php if ($ran): ?>
    <pre style="background:#0f1b15;color:#cfe8da;padding:16px;border-radius:10px;white-space:pre-wrap"><?= e(implode("\n", $log)) ?></pre>
    <p><a href="cab_dashboard.php">Open Cab dashboard &rarr;</a></p>
  <?php else: ?>
    <form method="post"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Install / upgrade cab module</button></form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php';
