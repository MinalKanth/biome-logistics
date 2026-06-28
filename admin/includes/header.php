<?php
/** includes/header.php - shared page chrome. Expects $pageTitle to be set. */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle ?? APP_NAME) ?> | <?= e(APP_NAME) ?></title>
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