<?php
// Bootstrap: DB connection, session, helpers. Included by every page.
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';

if ($config['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');
}

// Set PHP timezone — affects date(), time(), strtotime(), and all date functions.
// Defaults to Asia/Kolkata (IST). Override via $config['timezone'] in config.php.
$timezone = $config['timezone'] ?? 'Asia/Kolkata';
date_default_timezone_set($timezone);

// Secure session cookie. Must be called before session_start.
if (session_status() === PHP_SESSION_NONE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// PDO database connection
try {
    $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset={$config['db_charset']}";
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Sync MySQL session timezone to PHP's, so CURRENT_TIMESTAMP, NOW(), and
    // auto-update columns (created_at, updated_at) use the same wall clock.
    // We compute the current UTC offset (e.g. "+05:30" for IST) and pass it,
    // because shared hosts often don't have named tz tables loaded.
    try {
        $offset = (new DateTime('now', new DateTimeZone($timezone)))->format('P');
        $pdo->exec("SET time_zone = '" . $offset . "'");
    } catch (Throwable $e) {
        // Non-fatal — if this fails, app still works, timestamps just shift
    }
} catch (PDOException $e) {
    http_response_code(500);
    if ($config['debug']) {
        die('DB connection failed: ' . htmlspecialchars($e->getMessage()));
    }
    die('Database connection failed. Please contact administrator.');
}

require __DIR__ . '/helpers.php';
