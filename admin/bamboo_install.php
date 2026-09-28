<?php
/**
 * admin/bamboo_install.php - RUN ONCE, then delete.
 * Upgrades bamboo_enquiries (keeps all existing rows), creates products / orders / items / payments tables,
 * seeds your 8 products and the document number sequences. Safe to run repeatedly.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bamboo_lib.php';
require_admin();

$pdo = get_db();
$log = [];
$ran = false;
$M = 'DECIMAL(14,2) NOT NULL DEFAULT 0.00';

$SCHEMA = [
    'bamboo_enquiries' => [
        'full_name' => 'VARCHAR(150) NULL', 'mobile_number' => 'VARCHAR(30) NULL', 'email' => 'VARCHAR(150) NULL',
        'company_name' => 'VARCHAR(150) NULL', 'state' => 'VARCHAR(100) NULL', 'city' => 'VARCHAR(100) NULL',
        'products_selected' => 'TEXT NULL', 'quantity_required' => 'VARCHAR(100) NULL', 'delivery_location' => 'VARCHAR(200) NULL',
        'additional_requirements' => 'TEXT NULL', 'ip_address' => 'VARCHAR(45) NULL', 'created_at' => 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP',
        // new
        'reference' => 'VARCHAR(40) NULL', 'status' => "VARCHAR(20) NOT NULL DEFAULT 'new'", 'follow_up_date' => 'DATE NULL',
        'internal_notes' => 'TEXT NULL', 'order_id' => 'INT UNSIGNED NULL', 'last_contacted_at' => 'DATETIME NULL',
        'source' => "VARCHAR(20) NOT NULL DEFAULT 'website'", 'updated_at' => 'DATETIME NULL',
    ],
    'bamboo_products' => [
        'name' => 'VARCHAR(150) NOT NULL', 'category' => 'VARCHAR(80) NULL', 'description' => 'TEXT NULL', 'hsn' => 'VARCHAR(12) NULL',
        'unit' => "VARCHAR(12) NOT NULL DEFAULT 'piece'", 'price' => $M, 'min_order' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'stock_qty' => 'DECIMAL(14,2) NOT NULL DEFAULT 0.00', 'low_stock_alert' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
        'track_stock' => 'TINYINT(1) NOT NULL DEFAULT 0', 'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'sort_order' => 'INT NOT NULL DEFAULT 0', 'created_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL',
    ],
    'bamboo_orders' => [
        'quote_number' => 'VARCHAR(40) NULL', 'order_number' => 'VARCHAR(40) NULL', 'invoice_number' => 'VARCHAR(40) NULL', 'invoice_date' => 'DATE NULL',
        'public_token' => 'VARCHAR(64) NULL', 'enquiry_id' => 'INT UNSIGNED NULL',
        'customer_name' => 'VARCHAR(150) NULL', 'company_name' => 'VARCHAR(150) NULL', 'phone' => 'VARCHAR(30) NULL', 'email' => 'VARCHAR(150) NULL',
        'customer_gstin' => 'VARCHAR(20) NULL', 'billing_address' => 'TEXT NULL',
        'delivery_address' => 'TEXT NULL', 'delivery_city' => 'VARCHAR(100) NULL', 'delivery_state' => 'VARCHAR(100) NULL', 'delivery_pincode' => 'VARCHAR(12) NULL',
        'status' => "VARCHAR(20) NOT NULL DEFAULT 'quotation'",
        'subtotal' => $M, 'discount' => $M, 'transport_charge' => $M, 'gst_pct' => 'DECIMAL(5,2) NOT NULL DEFAULT 0.00', 'gst_amount' => $M,
        'grand_total' => $M, 'paid_amount' => $M, 'balance_amount' => $M, 'payment_status' => "VARCHAR(20) NOT NULL DEFAULT 'unpaid'",
        'valid_until' => 'DATE NULL', 'expected_dispatch' => 'DATE NULL', 'dispatched_at' => 'DATETIME NULL', 'delivered_at' => 'DATETIME NULL',
        'accepted_at' => 'DATETIME NULL', 'transport_booking_id' => 'INT UNSIGNED NULL', 'stock_deducted' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'terms' => 'TEXT NULL', 'notes' => 'TEXT NULL', 'internal_notes' => 'TEXT NULL',
        'created_by' => 'INT UNSIGNED NULL', 'created_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL', 'deleted_at' => 'DATETIME NULL',
    ],
    'bamboo_order_items' => [
        'order_id' => 'INT UNSIGNED NOT NULL', 'product_id' => 'INT UNSIGNED NULL', 'description' => 'VARCHAR(255) NULL', 'hsn' => 'VARCHAR(12) NULL',
        'quantity' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00', 'unit' => "VARCHAR(12) NOT NULL DEFAULT 'piece'",
        'rate' => $M, 'amount' => $M, 'sort_order' => 'INT NOT NULL DEFAULT 0',
    ],
    'bamboo_payments' => [
        'order_id' => 'INT UNSIGNED NOT NULL', 'receipt_number' => 'VARCHAR(40) NULL', 'amount' => $M, 'payment_mode' => 'VARCHAR(30) NULL',
        'reference' => 'VARCHAR(100) NULL', 'payment_date' => 'DATETIME NULL', 'remarks' => 'TEXT NULL',
        'created_by' => 'INT UNSIGNED NULL', 'created_at' => 'DATETIME NULL',
    ],
    // shared with the transport module (created here too, so this installer works on its own)
    'transport_sequences' => [
        'sequence_name' => 'VARCHAR(50) NOT NULL', 'prefix' => 'VARCHAR(20) NOT NULL', 'current_year' => 'INT NULL',
        'current_number' => 'INT NOT NULL DEFAULT 0', 'padding' => 'TINYINT NOT NULL DEFAULT 5',
        'separator_char' => "VARCHAR(5) NOT NULL DEFAULT '-'", 'reset_every_year' => 'TINYINT(1) NOT NULL DEFAULT 1',
        'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1', 'created_at' => 'DATETIME NULL', 'updated_at' => 'DATETIME NULL',
    ],
];
$INDEXES = [
    'idx_be_status' => ['bamboo_enquiries', 'status, created_at'], 'idx_be_follow' => ['bamboo_enquiries', 'follow_up_date'],
    'idx_bo_status' => ['bamboo_orders', 'status, deleted_at'], 'idx_bo_token' => ['bamboo_orders', 'public_token'],
    'idx_bo_enq' => ['bamboo_orders', 'enquiry_id'], 'idx_boi_order' => ['bamboo_order_items', 'order_id'],
    'idx_bp_order' => ['bamboo_payments', 'order_id'],
];

function bi_table(PDO $pdo, string $t): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t');
    $s->execute([':t' => $t]);
    return (int) $s->fetchColumn() > 0;
}
function bi_cols(PDO $pdo, string $t): array
{
    $s = $pdo->prepare('SELECT column_name n, column_type t, is_nullable nl, column_default d, extra x, column_key k FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t');
    $s->execute([':t' => $t]);
    $o = [];
    foreach ($s->fetchAll() as $r) {
        $o[strtolower((string) $r['n'])] = $r;
    }
    return $o;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $ran = true;

    foreach ($SCHEMA as $table => $cols) {
        try {
            if (!bi_table($pdo, $table)) {
                $defs = ['`id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY'];
                foreach ($cols as $c => $ddl) {
                    $defs[] = "`$c` $ddl";
                }
                $pdo->exec("CREATE TABLE `$table` (" . implode(', ', $defs) . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
                $log[] = ['ok', "Created table $table"];
                continue;
            }
            $ex = bi_cols($pdo, $table);
            $added = 0;
            foreach ($cols as $c => $ddl) {
                if (!isset($ex[strtolower($c)])) {
                    $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$c` $ddl");
                    $log[] = ['ok', "Added column $table.$c"];
                    $added++;
                }
            }
            $ex = bi_cols($pdo, $table);
            $known = array_change_key_case($cols, CASE_LOWER);
            foreach ($ex as $name => $r) {
                if ($name === 'id' || isset($known[$name])) {
                    continue;
                }
                if ($r['nl'] === 'NO' && $r['d'] === null && stripos((string) $r['x'], 'auto_increment') === false && $r['k'] !== 'PRI') {
                    $pdo->exec("ALTER TABLE `$table` MODIFY `{$r['n']}` {$r['t']} NULL DEFAULT NULL");
                    $log[] = ['ok', "Relaxed NOT NULL on $table.{$r['n']}"];
                }
            }
            if (!$added) {
                $log[] = ['ok', "$table is up to date"];
            }
        } catch (Throwable $e) {
            error_log('bamboo_install ' . $table . ': ' . $e->getMessage());
            $log[] = ['err', "$table: " . $e->getMessage()];
        }
    }

    foreach ($INDEXES as $name => [$table, $cols]) {
        try {
            $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :t AND index_name = :i');
            $s->execute([':t' => $table, ':i' => $name]);
            if ((int) $s->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` ($cols)");
                $log[] = ['ok', "Added index $name"];
            }
        } catch (Throwable $e) {
            $log[] = ['err', "index $name: " . $e->getMessage()];
        }
    }

    // Give old enquiries a reference number and a status
    try {
        $rows = $pdo->query("SELECT id FROM bamboo_enquiries WHERE reference IS NULL OR reference = '' ORDER BY id")->fetchAll();
        if ($rows) {
            $pdo->beginTransaction();
            $set = $pdo->prepare('UPDATE bamboo_enquiries SET reference = :r WHERE id = :id');
            foreach ($rows as $r) {
                $set->execute([':r' => tl_next_sequence($pdo, 'bamboo_enquiry', 'BEQ'), ':id' => $r['id']]);
            }
            $pdo->commit();
            $log[] = ['ok', 'Gave reference numbers to ' . count($rows) . ' existing enquiries'];
        }
        $pdo->exec("UPDATE bamboo_enquiries SET status = 'new' WHERE status IS NULL OR status = ''");
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $log[] = ['err', 'enquiry references: ' . $e->getMessage()];
    }

    // Sequences
    foreach (['bamboo_enquiry' => 'BEQ', 'bamboo_quote' => 'BQT', 'bamboo_order' => 'BOR', 'bamboo_invoice' => 'BINV', 'bamboo_receipt' => 'BRC'] as $n => $pfx) {
        try {
            $s = $pdo->prepare('SELECT COUNT(*) FROM transport_sequences WHERE sequence_name = :n');
            $s->execute([':n' => $n]);
            if ((int) $s->fetchColumn() === 0) {
                $pdo->prepare("INSERT INTO transport_sequences (sequence_name, prefix, current_year, current_number, padding, separator_char, reset_every_year, is_active, created_at, updated_at) VALUES (:n,:p,:y,0,5,'-',1,1,NOW(),NOW())")
                    ->execute([':n' => $n, ':p' => $pfx, ':y' => (int) date('Y')]);
                $log[] = ['ok', "Seeded number sequence $n ($pfx)"];
            }
        } catch (Throwable $e) {
            $log[] = ['err', "sequence $n: " . $e->getMessage()];
        }
    }

    // Seed products once
    try {
        if ((int) $pdo->query('SELECT COUNT(*) FROM bamboo_products')->fetchColumn() === 0) {
            $seed = [['Raw Long Bamboo', 'Raw', 'piece'], ['Raw Bamboo Pieces', 'Raw', 'piece'], ['Bamboo Poles', 'Raw', 'piece'], ['Bamboo Sticks', 'Raw', 'bundle'],
                     ['Bamboo Fence', 'Finished', 'sqft'], ['Bamboo Mats', 'Finished', 'sqft'], ['Bamboo Furniture', 'Finished', 'nos'], ['Bamboo Handicrafts', 'Finished', 'nos']];
            $ins = $pdo->prepare('INSERT INTO bamboo_products (name, category, hsn, unit, price, is_active, sort_order, created_at, updated_at) VALUES (:n,:c,:h,:u,0,1,:s,NOW(),NOW())');
            foreach ($seed as $i => [$n, $c, $u]) {
                $ins->execute([':n' => $n, ':c' => $c, ':h' => bb_settings()['hsn'], ':u' => $u, ':s' => $i + 1]);
            }
            $log[] = ['ok', 'Added your 8 products (set their prices under Bamboo > Products & Stock)'];
        }
    } catch (Throwable $e) {
        $log[] = ['err', 'seed products: ' . $e->getMessage()];
    }
    log_activity((int) $_SESSION['admin_id'], 'bamboo_install_run', 'schema check completed');
}
$errCount = count(array_filter($log, fn($l) => $l[0] === 'err'));
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow">
<title>Bamboo installer</title>
<style>body{font-family:Inter,Arial,sans-serif;background:#f4f8f4;color:#1c2b24;margin:0;padding:32px}.card{max-width:760px;margin:0 auto;background:#fff;border:1px solid #dfeadf;border-radius:14px;padding:28px}
h1{margin:0 0 6px;color:#0f4c2d}button{background:#198754;color:#fff;border:0;border-radius:8px;padding:12px 22px;font-weight:600;cursor:pointer}ul{list-style:none;padding:0}
li{padding:8px 12px;border-radius:8px;margin-bottom:6px;font-size:.92rem}li.ok{background:#e8f5ee;color:#14663f}li.err{background:#fdecec;color:#a12a2a}a{color:#198754}</style></head>
<body><div class="card"><h1>Bamboo trading installer</h1>
<p>Upgrades your enquiries table (existing enquiries are kept) and creates products, orders, invoices and payments. Safe to run more than once.</p>
<?php if (!$ran): ?><form method="post"><?= csrf_field() ?><button type="submit">Run installer now</button></form>
<?php else: ?><ul><?php foreach ($log as [$t, $m]): ?><li class="<?= $t === 'err' ? 'err' : 'ok' ?>"><?= e($m) ?></li><?php endforeach; ?></ul>
<?php if (!$errCount): ?><p><strong>All done.</strong> Delete <code>admin/bamboo_install.php</code> from the server, then open <a href="bamboo_enquiries.php">Bamboo enquiries</a>.</p>
<?php else: ?><p style="color:#a12a2a"><?= (int) $errCount ?> step(s) failed. Send me the red lines; do not delete this file yet.</p><?php endif; endif; ?>
</div></body></html>