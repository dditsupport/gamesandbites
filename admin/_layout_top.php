<?php
/** @var array $admin */
/** @var array $settings */
/** @var string $currentPage */
/** @var string $pageTitle */
$pageTitle = $pageTitle ?? 'Admin';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?> · Admin</title>
<link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="admin">
<div class="admin-shell">
  <aside class="admin-side">
    <div class="brand">
      <div class="brand-name">🏏 <?= e($settings['venue_name']) ?></div>
      <div class="brand-sub">Admin · @<?= e($admin['username']) ?></div>
    </div>
    <nav>
      <a href="dashboard.php" class="<?= $currentPage === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
      <a href="bookings.php" class="<?= $currentPage === 'bookings' || $currentPage === 'booking' ? 'active' : '' ?>">Bookings</a>
      <a href="slots.php" class="<?= $currentPage === 'slots' ? 'active' : '' ?>">Slots & Rates</a>
      <a href="coupons.php" class="<?= $currentPage === 'coupons' ? 'active' : '' ?>">Coupons</a>
      <a href="settings.php" class="<?= $currentPage === 'settings' ? 'active' : '' ?>">Settings</a>
      <a href="../index.php" target="_blank">↗ View site</a>
    </nav>
    <div class="logout">
      <a href="logout.php" class="btn btn-ghost btn-sm btn-block">Log out</a>
    </div>
  </aside>

  <main class="admin-main">
    <?= render_flash() ?>
