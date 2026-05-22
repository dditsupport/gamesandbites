<?php
require __DIR__ . '/includes/bootstrap.php';

$settings = get_settings($pdo);
$bookingId = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT b.*, s.start_time, s.end_time, s.crosses_midnight
    FROM bookings b
    JOIN slots s ON s.id = b.slot_id
    WHERE b.id = ?");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

if (!$booking) {
    flash_set('error', 'Booking not found.');
    redirect('index.php');
}

// Handle UPI proof submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $booking['payment_method'] === 'upi') {
    csrf_check();

    $utr = trim($_POST['utr'] ?? '');
    $screenshotPath = $booking['upi_screenshot'];

    // Handle file upload
    if (!empty($_FILES['screenshot']['tmp_name']) && $_FILES['screenshot']['error'] === UPLOAD_ERR_OK) {
        $f = $_FILES['screenshot'];
        if ($f['size'] > 5 * 1024 * 1024) {
            flash_set('error', 'Screenshot must be under 5 MB.');
            redirect('confirm.php?id=' . $bookingId);
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($f['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime])) {
            flash_set('error', 'Upload a JPG, PNG, or WebP image.');
            redirect('confirm.php?id=' . $bookingId);
        }
        $ext = $allowed[$mime];
        $fname = $booking['booking_code'] . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = __DIR__ . '/assets/uploads/' . $fname;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            flash_set('error', 'Could not save screenshot. Try again.');
            redirect('confirm.php?id=' . $bookingId);
        }
        $screenshotPath = 'assets/uploads/' . $fname;
    }

    if ($utr !== '') {
        $utr = preg_replace('/[^A-Za-z0-9]/', '', $utr);
        if (strlen($utr) > 30) $utr = substr($utr, 0, 30);
    }

    $stmt = $pdo->prepare("UPDATE bookings SET upi_utr = ?, upi_screenshot = ? WHERE id = ?");
    $stmt->execute([$utr ?: null, $screenshotPath ?: null, $bookingId]);

    // Ping admin again that proof was submitted
    ntfy_notify($pdo,
        'Payment proof: ' . $booking['booking_code'],
        ($utr ? "UTR: $utr\n" : '') . ($screenshotPath ? "Screenshot uploaded.\n" : '') . 'Verify now.',
        ['priority' => 'high', 'tags' => 'moneybag',
         'click' => ($settings['base_url'] ?: '') . '/admin/booking.php?id=' . $bookingId]
    );

    flash_set('success', 'Payment proof submitted. We\'ll verify within minutes.');
    redirect('confirm.php?id=' . $bookingId);
}

// Refresh booking after possible update
$stmt = $pdo->prepare("SELECT b.*, s.start_time, s.end_time, s.crosses_midnight
    FROM bookings b JOIN slots s ON s.id = b.slot_id WHERE b.id = ?");
$stmt->execute([$bookingId]);
$booking = $stmt->fetch();

$slot = [
    'start_time' => $booking['start_time'],
    'end_time'   => $booking['end_time'],
    'crosses_midnight' => $booking['crosses_midnight'],
];

$upiLink = '';
if ($booking['payment_method'] === 'upi' && !empty($settings['upi_id'])) {
    $upiLink = build_upi_link(
        $settings['upi_id'],
        $settings['upi_payee_name'] ?: $settings['venue_name'],
        (float)$booking['advance_amount'],
        $booking['booking_code']
    );
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Order placed · <?= e($booking['booking_code']) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="customer">
<header class="topbar">
  <div class="brand">
    <a href="index.php" class="brand-mark" title="Home" aria-label="Home" style="text-decoration:none">🏏</a>
    <div>
      <div class="brand-name"><?= e($settings['venue_name']) ?></div>
      <div class="brand-sub">Booking <?= e($booking['booking_code']) ?></div>
    </div>
  </div>
</header>

<main class="container">
  <?= render_flash() ?>

  <section class="card status-card">
    <?php if ($booking['status'] === 'confirmed'): ?>
      <div class="status-badge status-confirmed">✓ Confirmed</div>
      <h1>Your slot is confirmed</h1>
      <p>See you at the venue. Pay the balance before your slot starts.</p>
    <?php elseif ($booking['status'] === 'cancelled'): ?>
      <div class="status-badge status-cancelled">Cancelled</div>
      <h1>This booking was cancelled</h1>
      <?php if (!empty($booking['admin_note'])): ?>
        <p class="muted"><?= e($booking['admin_note']) ?></p>
      <?php endif; ?>
    <?php else: ?>
      <div class="status-badge status-pending">⏳ In progress</div>
      <h1>Order placed</h1>
      <p>Your booking is being processed. We'll confirm once payment is verified.</p>
    <?php endif; ?>
  </section>

  <section class="card">
    <h2>Booking details</h2>
    <?php $finalTotal = max(0, (float)$booking['slot_rate'] - (float)$booking['discount_amount']); ?>
    <div class="summary">
      <div class="summary-row"><span>Booking ID</span><strong><?= e($booking['booking_code']) ?></strong></div>
      <div class="summary-row"><span>Name</span><strong><?= e($booking['customer_name']) ?></strong></div>
      <div class="summary-row"><span>Mobile</span><strong><?= e($booking['customer_mobile']) ?></strong></div>
      <div class="summary-row"><span>Date</span><strong><?= e(date('D, M j, Y', strtotime($booking['booking_date']))) ?></strong></div>
      <div class="summary-row"><span>Slot</span><strong><?= e(slot_label($slot)) ?></strong></div>
      <div class="summary-row"><span>Slot price</span><strong><?= inr((float)$booking['slot_rate']) ?></strong></div>
      <?php if ((float)$booking['discount_amount'] > 0): ?>
        <div class="summary-row" style="color: var(--success)">
          <span>Coupon <?= e($booking['coupon_code']) ?></span>
          <strong>− <?= inr((float)$booking['discount_amount']) ?></strong>
        </div>
      <?php endif; ?>
      <div class="summary-row"><span>Total amount</span><strong><?= inr($finalTotal) ?></strong></div>
      <div class="summary-row highlight"><span>Advance</span><strong><?= inr((float)$booking['advance_amount']) ?></strong></div>
      <div class="summary-row"><span>Balance at venue</span><strong><?= inr(max(0, $finalTotal - (float)$booking['advance_amount'])) ?></strong></div>
    </div>
  </section>

  <?php if ($booking['status'] === 'pending'): ?>
    <?php if ($booking['payment_method'] === 'cash'): ?>
      <section class="card">
        <h2>Pay by Cash</h2>
        <p>Pay <strong><?= inr((float)$booking['advance_amount']) ?></strong> at the venue counter to confirm your booking. Show this booking ID:</p>
        <div class="big-code"><?= e($booking['booking_code']) ?></div>
        <p class="muted small">Your slot is held for you until the day of booking. If advance isn't paid, the slot may be released.</p>
      </section>
    <?php else: ?>
      <section class="card">
        <h2>Pay by UPI</h2>
        <p>Pay <strong><?= inr((float)$booking['advance_amount']) ?></strong> to <strong><?= e($settings['upi_id']) ?></strong></p>
        <p class="muted small" style="color:var(--warning)">
          ⏳ Please pay and submit proof within <?= (int)SLOT_HOLD_MINUTES ?> minutes, or this slot is automatically released for others.
        </p>

        <?php if ($upiLink): ?>
          <a href="<?= e($upiLink) ?>" class="btn btn-primary btn-block">
            Open UPI app · Pay <?= inr((float)$booking['advance_amount']) ?>
          </a>
          <p class="muted small">Works only on mobile. On desktop, scan the QR or send manually.</p>
        <?php endif; ?>

        <?php if (!empty($settings['upi_qr_image'])): ?>
          <div class="qr-wrap">
            <img src="<?= e($settings['upi_qr_image']) ?>" alt="UPI QR code" class="qr-img">
            <small class="muted">Scan with any UPI app</small>
          </div>
        <?php endif; ?>

        <h3 style="margin-top:24px">After paying, submit proof:</h3>
        <form method="post" enctype="multipart/form-data">
          <?= csrf_field() ?>

          <div class="field">
            <label for="utr">UTR / Transaction ID <span class="muted">(optional)</span></label>
            <input type="text" id="utr" name="utr" maxlength="30"
                   value="<?= e($booking['upi_utr'] ?? '') ?>"
                   placeholder="e.g. 412345678901">
          </div>

          <div class="field">
            <label for="screenshot">Payment screenshot <span class="muted">(JPG/PNG, max 5MB)</span></label>
            <input type="file" id="screenshot" name="screenshot" accept="image/jpeg,image/png,image/webp" class="js-compress">
            <?php if (!empty($booking['upi_screenshot'])): ?>
              <small class="muted">Already uploaded — re-upload to replace.</small>
            <?php endif; ?>
          </div>

          <button type="submit" class="btn btn-primary btn-block">Submit payment proof</button>
          <p class="muted small">Provide either UTR, screenshot, or both. We'll verify and confirm shortly.</p>
        </form>
      </section>
    <?php endif; ?>
  <?php endif; ?>

  <section class="card info">
    <p>Save this page or screenshot your booking ID: <strong><?= e($booking['booking_code']) ?></strong></p>
    <a href="index.php" class="btn btn-secondary btn-block">Book another slot</a>
  </section>
</main>

<footer class="footer">
  <small>© <?= date('Y') ?> <?= e($settings['venue_name']) ?></small>
</footer>
<script src="assets/js/img-compress.js"></script>
</body>
</html>
