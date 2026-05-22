<?php
require __DIR__ . '/_auth.php';
$pageTitle = 'Settings';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_general') {
        $keys = ['venue_name','venue_address','contact_phone','advance_amount','booking_window_days'];
        foreach ($keys as $k) {
            $val = trim($_POST[$k] ?? '');
            if ($k === 'advance_amount') {
                $val = (string)max(0, (float)$val);
            } elseif ($k === 'booking_window_days') {
                $val = (string)max(1, min(365, (int)$val));
            }
            set_setting($pdo, $k, $val);
        }
        flash_set('success', 'General settings saved.');
        redirect('settings.php');
    }

    if ($action === 'save_payment_methods') {
        $cash = isset($_POST['cash_enabled']) ? '1' : '0';
        $upi  = isset($_POST['upi_enabled'])  ? '1' : '0';
        if ($cash === '0' && $upi === '0') {
            flash_set('error', 'At least one payment method must stay enabled, otherwise customers can\'t book.');
            redirect('settings.php');
        }
        set_setting($pdo, 'cash_enabled', $cash);
        set_setting($pdo, 'upi_enabled',  $upi);
        flash_set('success', 'Payment methods updated.');
        redirect('settings.php');
    }

    if ($action === 'save_upi') {
        set_setting($pdo, 'upi_id', trim($_POST['upi_id'] ?? ''));
        set_setting($pdo, 'upi_payee_name', trim($_POST['upi_payee_name'] ?? ''));

        // Handle QR upload
        if (!empty($_FILES['upi_qr']['tmp_name']) && $_FILES['upi_qr']['error'] === UPLOAD_ERR_OK) {
            $f = $_FILES['upi_qr'];
            if ($f['size'] > 3 * 1024 * 1024) {
                flash_set('error', 'QR image must be under 3 MB.');
                redirect('settings.php');
            }
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($f['tmp_name']);
            $allowed = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
            if (!isset($allowed[$mime])) {
                flash_set('error', 'QR must be JPG, PNG, or WebP.');
                redirect('settings.php');
            }
            $ext = $allowed[$mime];
            $fname = 'upi_qr_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = __DIR__ . '/../assets/uploads/' . $fname;
            if (move_uploaded_file($f['tmp_name'], $dest)) {
                set_setting($pdo, 'upi_qr_image', 'assets/uploads/' . $fname);
            }
        }
        flash_set('success', 'UPI settings saved.');
        redirect('settings.php');
    }

    if ($action === 'save_ntfy') {
        $topic  = trim($_POST['ntfy_topic'] ?? '');
        $server = trim($_POST['ntfy_server'] ?? 'https://ntfy.sh');
        if ($server === '') $server = 'https://ntfy.sh';
        set_setting($pdo, 'ntfy_topic', $topic);
        set_setting($pdo, 'ntfy_server', rtrim($server, '/'));
        flash_set('success', 'Notification settings saved.');
        redirect('settings.php');
    }

    if ($action === 'test_ntfy') {
        // Refresh cached settings (helpers cache them statically per-request, fine here since redirect)
        ntfy_notify($pdo,
            'Test notification 🏏',
            "If you got this, ntfy is working.\nYou'll get a push for every new booking.",
            ['priority' => 'default', 'tags' => 'bell']
        );
        $topic = trim(get_settings($pdo)['ntfy_topic'] ?? '');
        if ($topic === '') {
            flash_set('error', 'Set an ntfy topic first.');
        } else {
            flash_set('success', 'Test sent. Check your ntfy app subscribed to topic "' . $topic . '".');
        }
        redirect('settings.php');
    }

    if ($action === 'change_password') {
        $current = $_POST['current'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $stmt = $pdo->prepare("SELECT password_hash FROM admins WHERE id = ?");
        $stmt->execute([$admin['id']]);
        $hash = $stmt->fetchColumn();

        if (!password_verify($current, $hash)) {
            flash_set('error', 'Current password is wrong.');
        } elseif (strlen($new) < 6) {
            flash_set('error', 'New password must be at least 6 characters.');
        } elseif ($new !== $confirm) {
            flash_set('error', 'New passwords do not match.');
        } else {
            $newHash = password_hash($new, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
            $stmt->execute([$newHash, $admin['id']]);
            flash_set('success', 'Password changed.');
        }
        redirect('settings.php');
    }
}

// Reload settings after potential write
$settings = get_settings($pdo);

require __DIR__ . '/_layout_top.php';
?>

<div class="admin-header">
  <h1>Settings</h1>
</div>

<div class="card">
  <h2>General</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_general">
    <div class="field">
      <label>Venue name</label>
      <input type="text" name="venue_name" value="<?= e($settings['venue_name']) ?>">
    </div>
    <div class="field">
      <label>Venue address</label>
      <input type="text" name="venue_address" value="<?= e($settings['venue_address']) ?>">
    </div>
    <div class="field">
      <label>Contact phone (shown to customers)</label>
      <input type="tel" name="contact_phone" value="<?= e($settings['contact_phone']) ?>">
    </div>
    <div class="field">
      <label>Advance amount (₹)</label>
      <input type="number" name="advance_amount" min="0" step="50" value="<?= e($settings['advance_amount']) ?>">
    </div>
    <div class="field">
      <label>Booking window (days)</label>
      <input type="number" name="booking_window_days" min="1" max="365"
             value="<?= e($settings['booking_window_days'] ?? '7') ?>">
      <small class="muted">How many days in advance customers can book. Default 7. Today + next (N−1) days.</small>
    </div>
    <button class="btn btn-primary">Save general</button>
  </form>
</div>

<div class="card">
  <h2>Payment methods</h2>
  <p class="muted small">Turn each method on or off for customers. At least one must stay enabled.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_payment_methods">
    <div class="toggle-row">
      <label class="toggle-card">
        <input type="checkbox" name="upi_enabled" <?= !empty($settings['upi_enabled']) ? 'checked' : '' ?>>
        <span class="toggle-content">
          <strong>UPI</strong>
          <small>GPay, PhonePe, Paytm — customers pay online with screenshot/UTR proof</small>
        </span>
      </label>
      <label class="toggle-card">
        <input type="checkbox" name="cash_enabled" <?= !empty($settings['cash_enabled']) ? 'checked' : '' ?>>
        <span class="toggle-content">
          <strong>Cash</strong>
          <small>Customer pays the ₹<?= e($settings['advance_amount']) ?> advance at venue</small>
        </span>
      </label>
    </div>
    <button class="btn btn-primary" style="margin-top:14px">Save payment methods</button>
  </form>
</div>

<div class="card">
  <h2>UPI payment</h2>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_upi">
    <div class="field">
      <label>UPI ID (e.g. yourname@upi)</label>
      <input type="text" name="upi_id" value="<?= e($settings['upi_id']) ?>" placeholder="yourname@upi">
    </div>
    <div class="field">
      <label>Payee name (shown in customer's UPI app)</label>
      <input type="text" name="upi_payee_name" value="<?= e($settings['upi_payee_name']) ?>" placeholder="Games N Bites">
    </div>
    <div class="field">
      <label>UPI QR code image (optional)</label>
      <input type="file" name="upi_qr" accept="image/jpeg,image/png,image/webp" class="js-compress">
      <?php if (!empty($settings['upi_qr_image'])): ?>
        <div style="margin-top:8px">
          <img src="../<?= e($settings['upi_qr_image']) ?>" alt="Current QR" style="max-width:140px;border:1px solid var(--line);border-radius:6px;padding:4px;background:#fff">
        </div>
      <?php endif; ?>
    </div>
    <button class="btn btn-primary">Save UPI</button>
  </form>
</div>

<div class="card">
  <h2>Push notifications (ntfy)</h2>
  <p class="muted small">
    Free push alerts on every new booking. Install the
    <a href="https://ntfy.sh" target="_blank">ntfy app</a> on your phone, subscribe to the topic below.
    Pick a long, random topic name (anyone who knows it can read your alerts).
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_ntfy">
    <div class="field">
      <label>ntfy topic</label>
      <input type="text" name="ntfy_topic" value="<?= e($settings['ntfy_topic']) ?>"
             placeholder="gnb-bookings-x7k9q-secret-string">
      <small class="muted">Then in your ntfy app, subscribe to this exact topic.</small>
    </div>
    <div class="field">
      <label>Server (leave default unless self-hosting)</label>
      <input type="text" name="ntfy_server" value="<?= e($settings['ntfy_server']) ?>" placeholder="https://ntfy.sh">
    </div>
    <button class="btn btn-primary">Save notifications</button>
  </form>
  <form method="post" style="margin-top:10px">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="test_ntfy">
    <button class="btn btn-secondary">Send test notification</button>
  </form>
</div>

<div class="card">
  <h2>Change admin password</h2>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <div class="field">
      <label>Current password</label>
      <input type="password" name="current" required autocomplete="current-password">
    </div>
    <div class="field">
      <label>New password (min 6 chars)</label>
      <input type="password" name="new_password" required minlength="6" autocomplete="new-password">
    </div>
    <div class="field">
      <label>Confirm new password</label>
      <input type="password" name="confirm_password" required minlength="6" autocomplete="new-password">
    </div>
    <button class="btn btn-primary">Change password</button>
  </form>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
