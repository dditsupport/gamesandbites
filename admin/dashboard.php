<?php
require __DIR__ . '/_auth.php';
$pageTitle = 'Dashboard';

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

<?php require __DIR__ . '/_layout_bottom.php'; ?>
