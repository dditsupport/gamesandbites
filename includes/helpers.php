<?php
declare(strict_types=1);

/** Escape HTML output */
function e(?string $s): string {
    return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8');
}

/** Load all settings as an associative array */
function get_settings(PDO $pdo): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $rows = $pdo->query("SELECT key_name, value FROM settings")->fetchAll();
    $cache = [];
    foreach ($rows as $r) $cache[$r['key_name']] = $r['value'];
    return $cache;
}

function set_setting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("INSERT INTO settings (key_name, value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE value = VALUES(value)");
    $stmt->execute([$key, $value]);
}

/** CSRF token: generate and verify */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}
function csrf_check(): void {
    $token = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        die('Invalid security token. Please refresh and try again.');
    }
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Format time stored as HH:MM:SS to "9:00 PM" */
function fmt_time(string $time): string {
    return date('g:i A', strtotime($time));
}

/** Format slot label like "9:00 PM – 11:00 PM (2h)" */
function slot_label(array $slot): string {
    $start = strtotime($slot['start_time']);
    $end   = strtotime($slot['end_time']);
    // Compute real duration accounting for midnight cross
    if (!empty($slot['crosses_midnight']) || $end <= $start) {
        $duration = (86400 - $start) + $end;
    } else {
        $duration = $end - $start;
    }
    $hours = (int) round($duration / 3600);
    return fmt_time($slot['start_time']) . ' – ' . fmt_time($slot['end_time'])
        . ' (' . $hours . 'h)';
}

/** Generate a short booking code like GNB-XK7Q4 */
function generate_booking_code(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no confusing 0/O/1/I
    $code = '';
    for ($i = 0; $i < 5; $i++) {
        $code .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return 'GNB-' . $code;
}

/** Validate Indian mobile number (10 digits, may have +91 prefix) */
function normalize_mobile(string $raw): ?string {
    $digits = preg_replace('/\D+/', '', $raw);
    if ($digits === null) return null;
    // Strip leading 91 if 12 digits, leading 0 if 11 digits
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    } elseif (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) !== 10 || !preg_match('/^[6-9]\d{9}$/', $digits)) {
        return null;
    }
    return $digits;
}

/** Check if a slot is already booked on a given date (pending or confirmed both block) */
function is_slot_taken(PDO $pdo, int $slotId, string $date, ?int $excludeBookingId = null): bool {
    $sql = "SELECT COUNT(*) FROM bookings
            WHERE slot_id = ? AND booking_date = ? AND status IN ('pending','confirmed')";
    $params = [$slotId, $date];
    if ($excludeBookingId !== null) {
        $sql .= " AND id != ?";
        $params[] = $excludeBookingId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return ((int) $stmt->fetchColumn()) > 0;
}

/** Build UPI deep link */
function build_upi_link(string $upiId, string $payeeName, float $amount, string $note): string {
    return 'upi://pay?' . http_build_query([
        'pa' => $upiId,
        'pn' => $payeeName,
        'am' => number_format($amount, 2, '.', ''),
        'tn' => $note,
        'cu' => 'INR',
    ]);
}

/** Send a push notification via ntfy.sh. Silent if not configured. */
function ntfy_notify(PDO $pdo, string $title, string $message, array $opts = []): void {
    $settings = get_settings($pdo);
    $topic = trim($settings['ntfy_topic'] ?? '');
    if ($topic === '') return; // not configured, skip silently

    $server = rtrim(trim($settings['ntfy_server'] ?? 'https://ntfy.sh'), '/');
    $url = $server . '/' . rawurlencode($topic);

    $headers = [
        'Title: ' . $title,
        'Priority: ' . ($opts['priority'] ?? 'default'),
        'Tags: ' . ($opts['tags'] ?? 'cricket'),
        'Content-Type: text/plain; charset=utf-8',
    ];
    if (!empty($opts['click'])) {
        $headers[] = 'Click: ' . $opts['click'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $message,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ]);
    curl_exec($ch); // fire and forget; ignore failures so booking still succeeds
    curl_close($ch);
}

/** Format INR amount */
function inr(float $amount): string {
    return '₹' . number_format($amount, 2);
}

/** Day-of-week column prefixes — PHP date('N') is 1=Mon ... 7=Sun */
const SLOT_DAYS = [1=>'mon', 2=>'tue', 3=>'wed', 4=>'thu', 5=>'fri', 6=>'sat', 7=>'sun'];
const SLOT_DAY_LABELS = [1=>'Mon', 2=>'Tue', 3=>'Wed', 4=>'Thu', 5=>'Fri', 6=>'Sat', 7=>'Sun'];

/** Return the day-prefix ('mon'..'sun') for a Y-m-d date string */
function day_prefix_for(string $date): string {
    $n = (int)date('N', strtotime($date));
    return SLOT_DAYS[$n] ?? 'mon';
}

/** Check if a slot is enabled on a given date based on its day-flags */
function slot_enabled_on(array $slot, string $date): bool {
    $p = day_prefix_for($date);
    return !empty($slot[$p . '_enabled']);
}

/** Get the rate for a slot on a specific date (falls back to legacy `rate` if day rate is 0) */
function slot_rate_for(array $slot, string $date): float {
    $p = day_prefix_for($date);
    $r = (float)($slot[$p . '_rate'] ?? 0);
    if ($r > 0) return $r;
    return (float)($slot['rate'] ?? 0); // fallback to legacy single rate
}

/**
 * Validate a coupon code. Returns ['ok'=>bool, 'coupon'=>array|null, 'error'=>string]
 * Caller should re-validate at booking-insert time to avoid race conditions.
 */
function validate_coupon(PDO $pdo, string $code, float $slotRate): array {
    $code = strtoupper(trim($code));
    if ($code === '') {
        return ['ok' => false, 'coupon' => null, 'error' => ''];
    }
    $stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = ?");
    $stmt->execute([$code]);
    $c = $stmt->fetch();

    if (!$c) {
        return ['ok' => false, 'coupon' => null, 'error' => 'Invalid coupon code.'];
    }
    if (!$c['is_active']) {
        return ['ok' => false, 'coupon' => null, 'error' => 'This coupon is no longer active.'];
    }
    if ($c['expires_on'] && $c['expires_on'] < date('Y-m-d')) {
        return ['ok' => false, 'coupon' => null, 'error' => 'This coupon has expired.'];
    }
    if ($c['usage_limit'] !== null && (int)$c['used_count'] >= (int)$c['usage_limit']) {
        return ['ok' => false, 'coupon' => null, 'error' => 'This coupon has reached its usage limit.'];
    }
    if ((float)$c['discount_amount'] >= $slotRate) {
        return ['ok' => false, 'coupon' => null, 'error' => 'Discount exceeds slot price.'];
    }
    return ['ok' => true, 'coupon' => $c, 'error' => ''];
}

/** Redirect helper */
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/** Flash messages */
function flash_set(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function flash_get(): ?array {
    if (empty($_SESSION['flash'])) return null;
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}
function render_flash(): string {
    $f = flash_get();
    if (!$f) return '';
    $cls = $f['type'] === 'error' ? 'flash flash-error' : 'flash flash-success';
    return '<div class="' . $cls . '">' . e($f['msg']) . '</div>';
}
