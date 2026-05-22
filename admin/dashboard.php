<?php
require __DIR__ . '/_auth.php';
$pageTitle = 'Dashboard';

// Update the rules image shown on the customer booking page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_rules_image') {
    csrf_check();
    if (empty($_FILES['rules_image']['tmp_name']) || $_FILES['rules_image']['error'] !== UPLOAD_ERR_OK) {
        flash_set('error', 'Please choose an image to upload.');
        redirect('dashboard.php');
    }
    $f = $_FILES['rules_image'];
    if ($f['size'] > 5 * 1024 * 1024) {
        flash_set('error', 'Image must be under 5 MB.');
        redirect('dashboard.php');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        flash_set('error', 'Upload a JPG, PNG, or WebP image.');
        redirect('dashboard.php');
    }
    $ext   = $allowed[$mime];
    $fname = 'rules_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dest  = __DIR__ . '/../assets/uploads/' . $fname;
    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        flash_set('error', 'Could not save image. Try again.');
        redirect('dashboard.php');
    }
    $oldPath = get_settings($pdo)['rules_image'] ?? '';
    set_setting($pdo, 'rules_image', 'assets/uploads/' . $fname);
    // Clean up the previously uploaded rules image (never the bundled assets/rules.jpg)
    if ($oldPath && str_starts_with($oldPath, 'assets/uploads/') && is_file(__DIR__ . '/../' . $oldPath)) {
        @unlink(__DIR__ . '/../' . $oldPath);
    }
    flash_set('success', 'Rules image updated.');
    redirect('dashboard.php');
}

// Current rules image (falls back to the bundled file)
$rulesImg = !empty($settings['rules_image']) ? $settings['rules_image'] : 'assets/rules.jpg';

// Stats
$today = date('Y-m-d');
$stats = [
    'today_bookings'    => (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE booking_date = '$today' AND status IN ('pending','confirmed')")->fetchColumn(),
    'pending_count'     => (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn(),
    'confirmed_total'   => (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'confirmed'")->fetchColumn(),
    'month_revenue'     => (float)$pdo->query("SELECT COALESCE(SUM(slot_rate - discount_amount - extra_discount),0) FROM bookings
        WHERE status = 'confirmed' AND DATE_FORMAT(booking_date,'%Y-%m') = '" . date('Y-m') . "'")->fetchColumn(),
];

// Recent bookings
$recent = $pdo->query("SELECT b.*, s.start_time, s.end_time, s.crosses_midnight
    FROM bookings b JOIN slots s ON s.id = b.slot_id
    ORDER BY b.created_at DESC LIMIT 10")->fetchAll();

require __DIR__ . '/_layout_top.php';
?>

<div class="admin-header">
  <h1>Dashboard</h1>
  <div><span class="muted small">Today is <?= e(date('D, M j, Y')) ?></span></div>
</div>

<div class="stat-grid">
  <div class="stat">
    <div class="stat-label">Today's bookings</div>
    <div class="stat-value"><?= $stats['today_bookings'] ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">Pending verification</div>
    <div class="stat-value"><?= $stats['pending_count'] ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">Confirmed (all-time)</div>
    <div class="stat-value"><?= $stats['confirmed_total'] ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">This month revenue</div>
    <div class="stat-value"><?= inr($stats['month_revenue']) ?></div>
  </div>
</div>

<div class="admin-header">
  <h2 style="font-size:18px;margin:0">Recent bookings</h2>
  <a href="bookings.php" class="btn btn-secondary btn-sm">View all →</a>
</div>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th>Booking</th>
        <th>Customer</th>
        <th>Date · Slot</th>
        <th>Payment</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$recent): ?>
        <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)">No bookings yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($recent as $b):
        $slot = ['start_time'=>$b['start_time'],'end_time'=>$b['end_time'],'crosses_midnight'=>$b['crosses_midnight']];
      ?>
        <tr>
          <td><strong><?= e($b['booking_code']) ?></strong></td>
          <td>
            <?= e($b['customer_name']) ?><br>
            <small class="muted"><?= e($b['customer_mobile']) ?></small>
          </td>
          <td>
            <?= e(date('D, M j', strtotime($b['booking_date']))) ?><br>
            <small class="muted"><?= e(slot_label($slot)) ?></small>
          </td>
          <td>
            <?= strtoupper($b['payment_method']) ?><br>
            <?php
              $finalTotal = max(0, (float)$b['slot_rate'] - (float)$b['discount_amount'] - (float)($b['extra_discount'] ?? 0));
              $received   = (float)$b['advance_amount'] + (float)($b['pending_amount'] ?? 0);
              $fullyPaid  = $received >= $finalTotal;
              $payColor   = $fullyPaid ? 'var(--success)' : 'var(--warning)';
            ?>
            <small style="color:<?= $payColor ?>;font-weight:600">
              <?= inr($received) ?> / <?= inr($finalTotal) ?>
            </small>
          </td>
          <td><span class="pill pill-<?= e($b['status']) ?>"><?= e($b['status']) ?></span></td>
          <td><a href="booking.php?id=<?= (int)$b['id'] ?>" class="btn btn-secondary btn-sm">Open</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="margin-top:20px">
  <h2 style="font-size:18px;margin-top:0">Rules image</h2>
  <p class="muted small">This graphic shows on the customer booking page (left/bottom). Upload a new one to replace it — JPG, PNG, or WebP, max 5 MB.</p>
  <div style="margin:12px 0">
    <img src="../<?= e($rulesImg) ?>?t=<?= @filemtime(__DIR__ . '/../' . $rulesImg) ?>"
         alt="Current rules image"
         style="max-width:320px;width:100%;border:1px solid var(--line);border-radius:8px;display:block">
  </div>
  <form method="post" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_rules_image">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end">
      <div class="field" style="margin:0">
        <label for="rules_image">New rules image</label>
        <input type="file" id="rules_image" name="rules_image" accept="image/jpeg,image/png,image/webp" class="js-compress" required>
      </div>
      <div>
        <button class="btn btn-primary btn-block">↑ Update rules image</button>
      </div>
    </div>
  </form>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
