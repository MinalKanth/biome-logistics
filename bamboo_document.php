<?php
/**
 * admin/bamboo_document.php?id=X&mode=quote|invoice - printable quotation or tax invoice.
 */
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/bamboo_lib.php';
require_admin();

$pdo = get_db();
$id = (int) ($_GET['id'] ?? 0);
$isInvoice = ($_GET['mode'] ?? 'quote') === 'invoice';
if ($id <= 0) { header('Location: bamboo_orders.php'); exit; }

$s = $pdo->prepare('SELECT * FROM bamboo_orders WHERE id = :id AND deleted_at IS NULL');
$s->execute([':id' => $id]);
$o = $s->fetch();
if (!$o || ($isInvoice && !$o['invoice_number'])) { header('Location: bamboo_order_view.php?id=' . $id); exit; }

$it = $pdo->prepare('SELECT * FROM bamboo_order_items WHERE order_id = :id ORDER BY sort_order, id');
$it->execute([':id' => $id]); $items = $it->fetchAll();

$payments = [];
if ($isInvoice) {
    $p = $pdo->prepare('SELECT * FROM bamboo_payments WHERE order_id = :id ORDER BY payment_date ASC, id ASC');
    $p->execute([':id' => $id]); $payments = $p->fetchAll();
}

ob_start(); ?>
<div class="grp"><a class="tbtn alt" href="bamboo_order_view.php?id=<?= $id ?>"><i class="fa-solid fa-arrow-left"></i> Order</a></div>
<div class="grp"><button class="tbtn" type="button" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save PDF</button></div>
<?php $toolbarHtml = ob_get_clean();
require __DIR__ . '/includes/bamboo_document_template.php';