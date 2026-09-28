<?php
/**
 * includes/header.php - shared page chrome. Expects $pageTitle to be set.
 * CHANGED: now loads Font Awesome 6 (the sidebar/buttons use fa-solid icons but
 * the old header never loaded the icon font) and the Inter typeface.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle ?? APP_NAME) ?> | <?= e(APP_NAME) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php if (!empty($_SESSION['flash_success'])): ?>
  <div class="alert alert-success" style="position:fixed;top:18px;right:18px;z-index:9999;"><?= e($_SESSION['flash_success']) ?></div>
  <?php unset($_SESSION['flash_success']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?>
  <div class="alert alert-error" style="position:fixed;top:18px;right:18px;z-index:9999;"><?= e($_SESSION['flash_error']) ?></div>
  <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>
