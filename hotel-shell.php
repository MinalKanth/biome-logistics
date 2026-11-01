<?php
/**
 * hotel-shell.php - shared header/footer + styles for the public hotel pages
 * (hotel-stay, hotel-book, hotel-booking). Keeps your site navbar and footer.
 */
declare(strict_types=1);

if (defined('HTL_SHELL_LOADED')) {
    return;
}
define('HTL_SHELL_LOADED', true);

require_once __DIR__ . '/admin/config/database.php';
require_once __DIR__ . '/admin/includes/hotel_lib.php';

function hs_e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hs_security_headers(bool $private): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    if ($private) {
        header('Cache-Control: no-store, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
    }
}

function hs_css(): void
{
    echo "<style>\n";
    echo <<<'CSS'
        body{font-family:Inter,sans-serif;background:#f4f8f6}
        .htl-hero{background:linear-gradient(135deg,#0f4c2d,#198754);color:#fff;padding:46px 0 70px}
        .htl-hero h1{font-weight:800;margin:6px 0 4px;font-size:clamp(1.6rem,4vw,2.4rem)}.htl-eyebrow{text-transform:uppercase;letter-spacing:.12em;font-size:.78rem;color:#ffd666;font-weight:700}
        .htl-card{background:#fff;border-radius:16px;box-shadow:0 10px 30px rgba(15,76,45,.1);padding:24px;margin-bottom:24px}.htl-card.pull{margin-top:-40px}
        .htl-search{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:12px;align-items:end}
        .htl-search label{font-size:.74rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#6b7a72;margin-bottom:4px;display:block}
        .htl-search input,.htl-search select{width:100%;border:1px solid #d4e0d9;border-radius:10px;padding:11px 12px;font-size:.95rem;background:#fff}
        .htl-btn{border:0;border-radius:10px;background:#f0a500;color:#14181a;font-weight:700;padding:12px 22px;cursor:pointer;display:inline-block;text-decoration:none;text-align:center}
        .htl-btn:hover{background:#d99500;color:#14181a}.htl-btn.green{background:#198754;color:#fff}.htl-btn.green:hover{background:#14663f;color:#fff}
        .htl-btn.ghost{background:#fff;border:1px solid #198754;color:#198754}.htl-btn[disabled]{opacity:.5;cursor:not-allowed}
        .htl-prop{display:grid;grid-template-columns:300px 1fr auto;gap:0;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 8px 24px rgba(15,76,45,.09);margin-bottom:20px}
        .htl-prop img{width:100%;height:100%;object-fit:cover;min-height:200px}.htl-prop .body{padding:20px 22px}.htl-prop .price{padding:20px 22px;text-align:right;display:flex;flex-direction:column;justify-content:center;border-left:1px dashed #dfe9e3;min-width:200px}
        .htl-badge{display:inline-block;font-size:.72rem;font-weight:700;border-radius:99px;padding:3px 10px;background:#e8f5ee;color:#14663f;margin-right:4px}.htl-badge.warn{background:#fff3cd;color:#8a5a00}.htl-badge.bad{background:#fdecec;color:#a12a2a}
        .htl-am{display:inline-flex;align-items:center;gap:6px;font-size:.82rem;color:#4b5d54;margin:0 12px 6px 0}.htl-am i{color:#198754;width:16px;text-align:center}
        .htl-room{display:grid;grid-template-columns:240px 1fr 230px;gap:0;border:1px solid #e3ece7;border-radius:14px;overflow:hidden;margin-bottom:16px;background:#fff}
        .htl-room img{width:100%;height:100%;object-fit:cover;min-height:170px}.htl-room .body{padding:16px 18px}.htl-room .price{padding:16px 18px;border-left:1px dashed #dfe9e3;text-align:right;display:flex;flex-direction:column;justify-content:center}
        .htl-total{font-size:1.5rem;font-weight:800;color:#0f4c2d}.htl-muted{color:#6b7a72;font-size:.85rem}
        .htl-steps{display:flex;justify-content:space-between;position:relative;margin:20px 0 6px}.htl-steps:before{content:'';position:absolute;top:17px;left:6%;right:6%;height:4px;background:#dfe9e3}
        .htl-step{position:relative;text-align:center;flex:1;font-size:.78rem;color:#6b7a72}.htl-step .d{width:36px;height:36px;border-radius:50%;background:#dfe9e3;color:#fff;margin:0 auto 8px;display:flex;align-items:center;justify-content:center;position:relative;z-index:1}
        .htl-step.done .d{background:#198754}.htl-step.now .d{background:#f0a500;box-shadow:0 0 0 5px rgba(240,165,0,.25)}.htl-step.done,.htl-step.now{color:#14181a;font-weight:600}
        .htl-tl{border-left:2px solid #dfe9e3;margin-left:6px;padding-left:20px}.htl-tl .e{position:relative;padding-bottom:16px}
        .htl-tl .e:before{content:'';position:absolute;left:-27px;top:4px;width:12px;height:12px;border-radius:50%;background:#198754;border:2px solid #fff;box-shadow:0 0 0 2px #198754}
        .htl-kv{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px}.htl-kv .k{font-size:.72rem;text-transform:uppercase;color:#6b7a72;letter-spacing:.06em}.htl-kv .v{font-weight:600}
        .htl-bd{width:100%;font-size:.9rem}.htl-bd td{padding:5px 0}.htl-bd .r{text-align:right}.htl-bd .grand td{font-weight:800;font-size:1.05rem;border-top:2px solid #0f4c2d;padding-top:9px}
        @media(max-width:860px){.htl-prop,.htl-room{grid-template-columns:1fr}.htl-prop img,.htl-room img{height:190px}.htl-prop .price,.htl-room .price{border-left:0;border-top:1px dashed #dfe9e3;text-align:left}.htl-step span{display:none}}
        @media print{.htl-hero,nav,footer,.no-print{display:none!important}.htl-card{box-shadow:none}}
CSS;
    echo "\n</style>\n";
}

function hs_top(string $title, string $description = '', bool $private = false, string $eyebrow = 'Hotels & Homestays', string $heading = ''): void
{
    hs_security_headers($private);
    $co = tl_settings()['company'];
    ?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= hs_e($title) ?> | <?= hs_e($co['name']) ?></title>
    <?php if ($description !== ''): ?><meta name="description" content="<?= hs_e($description) ?>"><?php endif; ?>
    <?php if ($private): ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="/favicon.ico" type="image/x-icon">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.10.0/css/all.min.css" rel="stylesheet">
    <link href="lib/animate/animate.min.css" rel="stylesheet">
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/navbar-active-state.css" rel="stylesheet">
    <link href="css/style.css" rel="stylesheet">
    <?php hs_css(); ?>
</head>
<body>
<?php include __DIR__ . '/navbar.php'; ?>
<section class="htl-hero">
    <div class="container">
        <div class="htl-eyebrow"><?= hs_e($eyebrow) ?></div>
        <h1><?= hs_e($heading !== '' ? $heading : $title) ?></h1>
    </div>
</section>
<div class="container pb-5">
<?php
}

function hs_bottom(): void
{
    ?></div>
<?php include __DIR__ . '/footer.php'; ?>
</body>
</html>
<?php
}

/** Search-bar values taken from the query string, validated. */
function hs_search_params(PDO $pdo): array
{
    $ci = htl_date((string) ($_GET['ci'] ?? ''));
    $co = htl_date((string) ($_GET['co'] ?? ''));
    $rooms = max(1, min(10, (int) ($_GET['rooms'] ?? 1)));
    $adults = max(1, min(40, (int) ($_GET['adults'] ?? 2)));
    $children = max(0, min(20, (int) ($_GET['children'] ?? 0)));
    $adults = max($adults, $rooms);
    $has = $ci !== null && $co !== null;
    return ['ci' => $ci, 'co' => $co, 'rooms' => $rooms, 'adults' => $adults, 'children' => $children, 'has' => $has,
            'error' => $has ? htl_check_stay($pdo, $ci, $co) : null];
}

function hs_search_form(array $p, string $action, array $hidden = []): void
{
    $min = date('Y-m-d');
    ?><form method="get" action="<?= hs_e($action) ?>" class="htl-search">
        <?php foreach ($hidden as $k => $v): ?><input type="hidden" name="<?= hs_e($k) ?>" value="<?= hs_e($v) ?>"><?php endforeach; ?>
        <div><label for="hsCi">Check-in</label><input id="hsCi" type="date" name="ci" min="<?= hs_e($min) ?>" value="<?= hs_e((string) $p['ci']) ?>" required></div>
        <div><label for="hsCo">Check-out</label><input id="hsCo" type="date" name="co" min="<?= hs_e($min) ?>" value="<?= hs_e((string) $p['co']) ?>" required></div>
        <div><label for="hsRooms">Rooms</label><select id="hsRooms" name="rooms"><?php for ($i = 1; $i <= 10; $i++): ?><option value="<?= $i ?>" <?= $p['rooms'] === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
        <div><label for="hsAd">Adults</label><select id="hsAd" name="adults"><?php for ($i = 1; $i <= 20; $i++): ?><option value="<?= $i ?>" <?= $p['adults'] === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
        <div><label for="hsCh">Children</label><select id="hsCh" name="children"><?php for ($i = 0; $i <= 10; $i++): ?><option value="<?= $i ?>" <?= $p['children'] === $i ? 'selected' : '' ?>><?= $i ?></option><?php endfor; ?></select></div>
        <div><button class="htl-btn" type="submit" style="width:100%"><i class="fa fa-search mr-2 me-2"></i>Search</button></div>
    </form>
    <script>
    (function(){var a=document.getElementById('hsCi'),b=document.getElementById('hsCo');if(!a||!b)return;
      function fix(){if(a.value){var d=new Date(a.value);d.setDate(d.getDate()+1);var m=d.toISOString().slice(0,10);b.min=m;if(!b.value||b.value<=a.value)b.value=m;}}
      a.addEventListener('change',fix);})();
    </script>
<?php
}

/** Amenity chips from a comma list. */
function hs_amenities(?string $csv, int $limit = 0): string
{
    $all = htl_amenities();
    $out = '';
    $n = 0;
    foreach (array_filter(explode(',', (string) $csv)) as $k) {
        if (!isset($all[$k])) { continue; }
        $out .= '<span class="htl-am"><i class="fa ' . hs_e(hs_fa5($all[$k][1])) . '"></i>' . hs_e($all[$k][0]) . '</span>';
        if ($limit && ++$n >= $limit) { break; }
    }
    return $out;
}

/** The site loads Font Awesome 5; map the FA6 names used in the admin to FA5 equivalents. */
function hs_fa5(string $icon): string
{
    $map = ['fa-square-parking' => 'fa-parking', 'fa-bell-concierge' => 'fa-concierge-bell', 'fa-shirt' => 'fa-tshirt', 'fa-map-location-dot' => 'fa-map-marked-alt',
        'fa-van-shuttle' => 'fa-shuttle-van', 'fa-people-roof' => 'fa-users', 'fa-kitchen-set' => 'fa-utensils', 'fa-door-open' => 'fa-door-open', 'fa-snowflake' => 'fa-snowflake',
        'fa-mug-hot' => 'fa-mug-hot', 'fa-shower' => 'fa-shower', 'fa-clock' => 'fa-clock', 'fa-video' => 'fa-video', 'fa-tree' => 'fa-tree', 'fa-fire' => 'fa-fire', 'fa-paw' => 'fa-paw'];
    return $map[$icon] ?? $icon;
}
