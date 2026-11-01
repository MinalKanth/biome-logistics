<?php
/**
 * admin/hotel_install.php - RUN ONCE (safe to re-run), then delete.
 * Creates the Hotels & Homestays tables. Nothing existing is changed.
 * No sample properties are created: the public page keeps saying "coming soon" until you add and activate one.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/hotel_lib.php';
require_admin();

$pdo = get_db();
$log = [];
$ran = false;
$M = 'DECIMAL(12,2) NOT NULL DEFAULT 0.00';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $ran = true;
    try {
        $T = [
            'hotel_settings' => "setting_key VARCHAR(60) NOT NULL PRIMARY KEY, setting_value TEXT NULL, updated_at DATETIME NULL",
            'hotel_properties' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL, slug VARCHAR(90) NOT NULL,
                property_type VARCHAR(20) NOT NULL DEFAULT 'hotel', city VARCHAR(100) NULL, state VARCHAR(100) NULL, address VARCHAR(255) NULL, pincode VARCHAR(10) NULL,
                short_desc VARCHAR(255) NULL, description TEXT NULL, phone VARCHAR(30) NULL, email VARCHAR(150) NULL, star_rating TINYINT UNSIGNED NULL,
                check_in_time VARCHAR(5) NOT NULL DEFAULT '12:00', check_out_time VARCHAR(5) NOT NULL DEFAULT '11:00',
                amenities VARCHAR(500) NULL, policies TEXT NULL, image VARCHAR(60) NULL, is_active TINYINT(1) NOT NULL DEFAULT 0, sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL, UNIQUE KEY uq_slug (slug), KEY idx_active (is_active)",
            'hotel_room_types' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, property_id INT UNSIGNED NOT NULL, name VARCHAR(120) NOT NULL, description TEXT NULL,
                bed_type VARCHAR(40) NULL, size_sqft INT UNSIGNED NULL, max_adults TINYINT UNSIGNED NOT NULL DEFAULT 2, max_children TINYINT UNSIGNED NOT NULL DEFAULT 1,
                base_adults TINYINT UNSIGNED NOT NULL DEFAULT 2, base_rate $M, weekend_rate $M, extra_adult_rate $M, child_rate $M,
                total_rooms SMALLINT UNSIGNED NOT NULL DEFAULT 1, amenities VARCHAR(500) NULL, image VARCHAR(60) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 0,
                created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL, KEY idx_prop (property_id)",
            'hotel_rate_rules' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, room_type_id INT UNSIGNED NOT NULL, name VARCHAR(100) NOT NULL,
                start_date DATE NOT NULL, end_date DATE NOT NULL, rate $M, day_scope VARCHAR(10) NOT NULL DEFAULT 'all', priority INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NULL, KEY idx_room (room_type_id, start_date, end_date)",
            'hotel_blocks' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, room_type_id INT UNSIGNED NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL,
                rooms SMALLINT UNSIGNED NOT NULL DEFAULT 1, reason VARCHAR(200) NULL, created_by INT UNSIGNED NULL, created_at DATETIME NULL, KEY idx_room (room_type_id, start_date, end_date)",
            'hotel_reservations' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, reference VARCHAR(40) NOT NULL, property_id INT UNSIGNED NOT NULL, room_type_id INT UNSIGNED NOT NULL,
                guest_name VARCHAR(150) NOT NULL, phone VARCHAR(20) NOT NULL, email VARCHAR(150) NULL, adults TINYINT UNSIGNED NOT NULL DEFAULT 1, children TINYINT UNSIGNED NOT NULL DEFAULT 0,
                rooms TINYINT UNSIGNED NOT NULL DEFAULT 1, check_in DATE NOT NULL, check_out DATE NOT NULL, nights SMALLINT UNSIGNED NOT NULL DEFAULT 1, arrival_time VARCHAR(5) NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pending', source VARCHAR(20) NOT NULL DEFAULT 'website', special_requests TEXT NULL,
                id_type VARCHAR(30) NULL, id_number VARCHAR(40) NULL, room_numbers VARCHAR(100) NULL,
                room_total $M, extra_total $M, other_charges $M, discount $M, gst_pct DECIMAL(5,2) NOT NULL DEFAULT 0.00, gst_amount $M, total_amount $M,
                paid_amount $M, balance_amount $M, payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid', invoice_number VARCHAR(40) NULL, invoice_date DATE NULL,
                cancel_reason VARCHAR(255) NULL, internal_notes TEXT NULL, checked_in_at DATETIME NULL, checked_out_at DATETIME NULL, ip_address VARCHAR(45) NULL, created_by INT UNSIGNED NULL,
                created_at DATETIME NULL, updated_at DATETIME NULL, deleted_at DATETIME NULL,
                UNIQUE KEY uq_ref (reference), KEY idx_status (status), KEY idx_in (check_in), KEY idx_room (room_type_id, check_in, check_out), KEY idx_prop (property_id)",
            'hotel_payments' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, reservation_id INT UNSIGNED NOT NULL, receipt_number VARCHAR(40) NULL, entry_type VARCHAR(10) NOT NULL DEFAULT 'payment',
                amount $M, payment_mode VARCHAR(30) NULL, reference VARCHAR(100) NULL, payment_date DATETIME NULL, remarks VARCHAR(255) NULL, created_by INT UNSIGNED NULL, created_at DATETIME NULL, KEY idx_res (reservation_id)",
            'hotel_timeline' => "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, reservation_id INT UNSIGNED NOT NULL, status VARCHAR(20) NULL, title VARCHAR(180) NOT NULL, description TEXT NULL,
                customer_visible TINYINT(1) NOT NULL DEFAULT 1, created_by INT UNSIGNED NULL, created_at DATETIME NULL, KEY idx_res (reservation_id)",
        ];
        foreach ($T as $name => $cols) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS $name ($cols) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
        $log[] = 'Tables created / verified: ' . implode(', ', array_keys($T));

        $pdo->exec("CREATE TABLE IF NOT EXISTS transport_sequences (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, sequence_name VARCHAR(60) NOT NULL, prefix VARCHAR(20) NOT NULL,
            current_year INT NOT NULL DEFAULT 0, current_number INT NOT NULL DEFAULT 0, padding TINYINT NOT NULL DEFAULT 5,
            separator_char VARCHAR(2) NOT NULL DEFAULT '-', reset_every_year TINYINT(1) NOT NULL DEFAULT 1, is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL, UNIQUE KEY uq_name (sequence_name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach (htl_setting_defaults() as $k => $v) {
            $pdo->prepare('INSERT IGNORE INTO hotel_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, NOW())')->execute([':k' => $k, ':v' => $v]);
        }
        $log[] = 'Default settings stored (GST slabs are editable in Hotels > Settings).';

        $dir = dirname(__DIR__) . '/uploads/hotel';
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $log[] = 'WARNING: could not create ' . $dir . ' - create it manually (writable) so photo upload works.';
        } else {
            @file_put_contents($dir . '/.htaccess', "php_flag engine off\nOptions -ExecCGI -Indexes\n<FilesMatch \"\\.(php|phtml|phar|pl|py|cgi)$\">\n  Require all denied\n</FilesMatch>\n");
            $log[] = 'Upload folder ready: uploads/hotel (scripts cannot run there).';
        }
        log_activity((int) $_SESSION['admin_id'], 'hotel_install', 'hotel module installed');
        $log[] = 'DONE. Delete admin/hotel_install.php from the server now.';
    } catch (Throwable $e) {
        error_log('hotel_install: ' . $e->getMessage());
        $log[] = 'ERROR: ' . $e->getMessage();
    }
}
$pageTitle = 'Hotel module installer';
require __DIR__ . '/includes/header.php';
?>
<div style="max-width:720px;margin:40px auto;padding:0 18px;font-family:Inter,sans-serif">
  <h1>Hotels &amp; Homestays - installer</h1>
  <p>Creates the property, room, rate, reservation and payment tables. Safe to run more than once. Nothing existing is changed.</p>
  <?php if ($ran): ?>
    <pre style="background:#0f1b15;color:#cfe8da;padding:16px;border-radius:10px;white-space:pre-wrap"><?= e(implode("\n", $log)) ?></pre>
    <p><a href="hotel_dashboard.php">Open Hotel dashboard &rarr;</a></p>
  <?php else: ?>
    <form method="post"><?= csrf_field() ?><button class="btn btn-primary" type="submit">Install hotel module</button></form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php';
