<?php
/**
 * hotel-stay.php?p=<slug>&ci=&co=&rooms=&adults=&children= - property details, rooms, live availability and price.
 */
declare(strict_types=1);
require_once __DIR__ . '/hotel-shell.php';

$pdo = get_db();
$slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) ($_GET['p'] ?? ''))) ?? '';
$prop = null;
if ($slug !== '') {
    $s = $pdo->prepare('SELECT * FROM hotel_properties WHERE slug = :s AND is_active = 1 AND deleted_at IS NULL');
    $s->execute([':s' => $slug]);
    $prop = $s->fetch() ?: null;
}
if (!$prop) {
    http_response_code(404);
    hs_top('Property not found', '', true, 'Hotels & Homestays', 'Property not found');
    echo '<div class="htl-card pull text-center"><p class="mb-3">We could not find that property.</p><a class="htl-btn green" href="hotel">See all stays</a></div>';
    hs_bottom();
    exit;
}
$sp = hs_search_params($pdo);
$S = htl_settings($pdo);
$rs = $pdo->prepare('SELECT * FROM hotel_room_types WHERE property_id = :p AND is_active = 1 AND deleted_at IS NULL ORDER BY sort_order, base_rate');
$rs->execute([':p' => $prop['id']]);
$rooms = $rs->fetchAll();
$dated = $sp['has'] && !$sp['error'];
foreach ($rooms as $k => $room) {
    $rooms[$k]['q'] = null; $rooms[$k]['left'] = null;
    if ($dated) {
        $av = htl_availability($pdo, $room, (string) $sp['ci'], (string) $sp['co']);
        $rooms[$k]['left'] = $av['min'];
        $rooms[$k]['q'] = htl_quote($pdo, $room, (string) $sp['ci'], (string) $sp['co'], $sp['rooms'], $sp['adults'], $sp['children']);
    }
}
$ld = [
    '@context' => 'https://schema.org', '@type' => $prop['property_type'] === 'homestay' ? 'BedAndBreakfast' : 'Hotel', 'name' => $prop['name'],
    'description' => $prop['short_desc'] ?: $prop['description'], 'address' => ['@type' => 'PostalAddress', 'streetAddress' => $prop['address'], 'addressLocality' => $prop['city'], 'addressRegion' => $prop['state'], 'postalCode' => $prop['pincode'], 'addressCountry' => 'IN'],
    'telephone' => $prop['phone'] ?: $S['support_phone'], 'checkinTime' => $prop['check_in_time'], 'checkoutTime' => $prop['check_out_time'],
];
$policyLines = array_filter(array_map('trim', preg_split('/\R/', (string) $prop['policies']) ?: []));
$terms = array_filter(array_map('trim', preg_split('/\R/', (string) $S['terms']) ?: []));
$fe = (int) $S['free_cancel_hours'];

hs_top($prop['name'] . ' - ' . ($prop['city'] ?: 'Assam'), (string) ($prop['short_desc'] ?: 'Book your stay with Biome Enterprises'), false, htl_property_types()[$prop['property_type']] ?? 'Stay', $prop['name']);
?>
<script type="application/ld+json"><?= json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<div class="htl-card pull">
    <div class="row g-4">
        <div class="col-lg-5"><img src="<?= hs_e(htl_image_url($prop['image'])) ?>" alt="<?= hs_e($prop['name']) ?>" style="width:100%;height:100%;max-height:320px;object-fit:cover;border-radius:12px"></div>
        <div class="col-lg-7">
            <?php if ((int) $prop['star_rating'] > 0): ?><span class="htl-badge warn"><?= str_repeat('&#9733;', (int) $prop['star_rating']) ?></span><?php endif; ?>
            <div class="htl-muted my-2"><i class="fa fa-map-marker-alt text-success me-1"></i><?= hs_e(trim(($prop['address'] ?? '') . ', ' . ($prop['city'] ?? '') . ', ' . ($prop['state'] ?? '') . ' ' . ($prop['pincode'] ?? ''), ' ,')) ?></div>
            <p style="white-space:pre-line"><?= hs_e($prop['description'] ?: $prop['short_desc']) ?></p>
            <div class="htl-kv mb-2"><div><div class="k">Check-in</div><div class="v">from <?= hs_e($prop['check_in_time']) ?></div></div><div><div class="k">Check-out</div><div class="v">by <?= hs_e($prop['check_out_time']) ?></div></div>
                <div><div class="k">Phone</div><div class="v"><a href="tel:<?= hs_e(htl_clean_phone($prop['phone'] ?: $S['support_phone'])) ?>"><?= hs_e($prop['phone'] ?: $S['support_phone']) ?></a></div></div></div>
            <div class="mt-3"><?= hs_amenities($prop['amenities']) ?></div>
        </div>
    </div>
</div>

<div class="htl-card" id="rooms">
    <h4 class="mb-3" style="font-weight:800">Choose your dates</h4>
    <?php hs_search_form($sp, 'hotel-stay', ['p' => $prop['slug']]); ?>
    <?php if ($sp['error']): ?><div class="alert alert-warning mt-3 mb-0"><?= hs_e($sp['error']) ?></div><?php endif; ?>
</div>

<h4 class="mb-3" style="font-weight:800">Rooms</h4>
<?php if (!$rooms): ?><div class="htl-card text-center">Rooms for this property are being set up. Please call <?= hs_e($S['support_phone']) ?>.</div><?php endif; ?>
<?php foreach ($rooms as $room):
    $q = $room['q']; $left = $room['left'];
    $book = 'hotel-book?room=' . (int) $room['id'] . '&ci=' . $sp['ci'] . '&co=' . $sp['co'] . '&rooms=' . $sp['rooms'] . '&adults=' . $sp['adults'] . '&children=' . $sp['children']; ?>
    <div class="htl-room">
        <img src="<?= hs_e(htl_image_url($room['image'])) ?>" alt="<?= hs_e($room['name']) ?>" loading="lazy">
        <div class="body">
            <h5 style="font-weight:800;margin-bottom:4px"><?= hs_e($room['name']) ?></h5>
            <div class="htl-muted mb-2"><?= hs_e(trim(($room['bed_type'] ?: '') . ((int) $room['size_sqft'] ? ' · ' . (int) $room['size_sqft'] . ' sq ft' : '') . ' · Sleeps ' . (int) $room['max_adults'] . ((int) $room['max_children'] ? ' + ' . (int) $room['max_children'] . ' child' : ''), ' ·')) ?></div>
            <?php if ($room['description']): ?><p style="font-size:.9rem;margin-bottom:8px"><?= hs_e($room['description']) ?></p><?php endif; ?>
            <div><?= hs_amenities($room['amenities'], 8) ?></div>
        </div>
        <div class="price">
            <?php if (!$dated): ?>
                <div class="htl-muted">from</div><div class="htl-total">&#8377;<?= hs_e(number_format((float) $room['base_rate'], 0)) ?></div><div class="htl-muted">per night + taxes</div>
                <a class="htl-btn ghost mt-2" href="#rooms">Pick dates</a>
            <?php elseif ($left < 1): ?>
                <span class="htl-badge bad">Sold out</span><div class="htl-muted mt-2">Try other dates</div>
            <?php elseif (!$q['ok']): ?>
                <div class="htl-muted"><?= hs_e($q['error']) ?></div>
            <?php elseif ($left < $sp['rooms']): ?>
                <span class="htl-badge warn">Only <?= (int) $left ?> left</span><div class="htl-muted mt-2">Reduce the number of rooms to book</div>
            <?php else: ?>
                <?php if ($left <= 3): ?><span class="htl-badge warn" style="align-self:flex-end">Only <?= (int) $left ?> left</span><?php endif; ?>
                <div class="htl-muted mt-1"><?= (int) $q['nights'] ?> night<?= $q['nights'] > 1 ? 's' : '' ?>, <?= (int) $sp['rooms'] ?> room<?= $sp['rooms'] > 1 ? 's' : '' ?></div>
                <div class="htl-total">&#8377;<?= hs_e(number_format($q['total'], 0)) ?></div><div class="htl-muted">incl. GST &middot; avg &#8377;<?= hs_e(number_format($q['avg_rate'], 0)) ?>/night</div>
                <a class="htl-btn green mt-2" href="<?= hs_e($book) ?>">Book now</a>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<div class="row g-4">
    <?php if ($policyLines): ?><div class="col-md-6"><div class="htl-card"><h5 style="font-weight:800">House rules</h5><ul class="mb-0 ps-3" style="font-size:.9rem"><?php foreach ($policyLines as $l): ?><li><?= hs_e($l) ?></li><?php endforeach; ?></ul></div></div><?php endif; ?>
    <div class="col-md-6"><div class="htl-card"><h5 style="font-weight:800">Booking policy</h5><ul class="mb-0 ps-3" style="font-size:.9rem">
        <li>Free cancellation until <?= $fe ?> hours before check-in; after that please call us.</li><li>Our team confirms availability<?= $S['auto_confirm'] === '1' ? ' instantly' : ' shortly after you book' ?>. No payment is taken online.</li>
        <?php foreach (array_slice($terms, 0, 4) as $l): ?><li><?= hs_e($l) ?></li><?php endforeach; ?></ul></div></div>
</div>
<?php hs_bottom();
