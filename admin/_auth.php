<?php
require __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['admin_id'])) {
    redirect('login.php');
}

// Re-fetch admin
$stmt = $pdo->prepare("SELECT id, username FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch();
if (!$admin) {
    session_destroy();
    redirect('login.php');
}

$settings = get_settings($pdo);

// Current page for nav highlight
$currentPage = basename($_SERVER['SCRIPT_NAME'], '.php');
