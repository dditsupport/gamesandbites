<?php
// ============================================================
// Copy this file to config.php and fill in your values.
// (Find DB values in cPanel > MySQL Databases after creating a DB+user)
// ============================================================
return [
    'db_host'    => 'localhost',
    'db_name'    => 'your_db_name',
    'db_user'    => 'your_db_user',
    'db_pass'    => 'your_db_password',
    'db_charset' => 'utf8mb4',

    // Set to your domain in production. Used for absolute URLs in some places.
    'base_url'   => '',

    // App timezone. Affects all dates, times, and DB timestamps.
    // Common values: 'Asia/Kolkata' (IST), 'UTC', 'America/New_York'.
    // Full list: https://www.php.net/manual/en/timezones.php
    'timezone'   => 'Asia/Kolkata',

    // Set false on live server. true shows PHP errors for debugging only.
    'debug'      => false,
];
