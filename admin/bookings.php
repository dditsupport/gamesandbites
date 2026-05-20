<?php
require __DIR__ . '/_auth.php';
$pageTitle = 'Bookings';

$status = $_GET['status'] ?? 'all';
$search = trim($_GET['q'] ?? '');
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-2 days'));
$dateTo   = $_GET['to'] ?? date('Y-m-d', strtotime('+7 days'));

$where  = [];
$params = [];

if (in_array($status, ['pending','confirmed','cancelled'], true)) {
    $where[] = 'b.status = ?';
    $params[] = $status;
}
if ($search !== '') {
    $where[] = '(b.customer_name LIKE ? OR b.customer_mobile LIKE ? OR b.booking_code LIKE ?)';
    $like = '%' . $search . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $where[] = 'b.booking_date >= ?';
    $params[] = $dateFrom;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $where[] = 'b.booking_date <= ?';
    $params[] = $dateTo;
}

$sql = "SELECT b.*, s.start_time, s.end_time, s.crosses_midnight
        FROM bookings b JOIN slots s ON s.id = b.slot_id";
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY b.booking_date DESC, s.start_time DESC, b.created_at DESC LIMIT 200';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

// Totals across the filtered list, excluding cancelled bookings.
$sumReceived = 0.0; $sumPending = 0.0; $sumAdjusted = 0.0;
foreach ($bookings as $b) {
    if ($b['status'] === 'cancelled') continue;
    $ft = max(0, (float)$b['slot_rate'] - (float)$b['discount_amount'] - (float)($b['extra_discount'] ?? 0));
    $rc = (float)$b['advance_amount'] + (float)($b['pending_amount'] ?? 0);
    $sumReceived += $rc;
    $sumPending  += max(0, $ft - $rc);
    $sumAdjusted += (float)($b['extra_discount'] ?? 0);
}

require __DIR__ . '/_layout_top.php';
?>

<div class="admin-header">
  <h1>Bookings <small class="muted">(<?= count($bookings) ?>)</small></h1>
</div>

<form method="get" class="card" style="margin-bottom:16px">
  <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;align-items:end">
    <div class="field" style="margin:0">
      <label>Search</label>
      <input type="text" name="q" value="<?= e($search) ?>" placeholder="Name, mobile, code">
    </div>
    <div class="field" style="margin:0">
      <label>Status</label>
      <select name="status">
        <option value="all">All</option>
        <option value="pending"   <?= $status==='pending'?'selected':'' ?>>Pending</option>
        <option value="confirmed" <?= $status==='confirmed'?'selected':'' ?>>Confirmed</option>
        <option value="cancelled" <?= $status==='cancelled'?'selected':'' ?>>Cancelled</option>
      </select>
    </div>
    <div class="field" style="margin:0">
      <label>From</label>
      <input type="date" name="from" value="<?= e($dateFrom) ?>">
    </div>
    <div class="field" style="margin:0">
      <label>To</label>
      <input type="date" name="to" value="<?= e($dateTo) ?>">
    </div>
    <div>
      <button class="btn btn-primary btn-block">Filter</button>
    </div>
  </div>
</form>

<div class="stat-grid" style="margin-bottom:16px">
  <div class="stat">
    <div class="stat-label">Total received <small class="muted">(excl. cancelled)</small></div>
    <div class="stat-value" style="color:var(--success)"><?= inr($sumReceived) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">Total pending <small class="muted">(excl. cancelled)</small></div>
    <div class="stat-value" style="color:var(--warning)"><?= inr($sumPending) ?></div>
  </div>
  <div class="stat">
    <div class="stat-label">Total adjusted <small class="muted">(excl. cancelled)</small></div>
    <div class="stat-value" style="color:var(--ink-soft)"><?= inr($sumAdjusted) ?></div>
  </div>
</div>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th>Code</th>
        <th>Customer</th>
        <th>Date · Slot</th>
        <th>Payment</th>
        <th>Proof</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$bookings): ?>
      <tr><td colspan="7" style="text-align:center;padding:32px;color:var(--muted)">No bookings match.</td></tr>
    <?php endif; ?>
    <?php foreach ($bookings as $b):
      $slot = ['start_time'=>$b['start_time'],'end_time'=>$b['end_time'],'crosses_midnight'=>$b['crosses_midnight']];
    ?>
      <tr>
        <td><strong><?= e($b['booking_code']) ?></strong></td>
        <td>
          <?= e($b['customer_name']) ?><br>
          <small class="muted"><a href="tel:<?= e($b['customer_mobile']) ?>"><?= e($b['customer_mobile']) ?></a></small>
        </td>
        <td>
          <?= e(date('D, M j Y', strtotime($b['booking_date']))) ?><br>
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
          <?php if ((float)$b['discount_amount'] > 0): ?>
            <br><small style="color:var(--success)"><?= e($b['coupon_code']) ?> −<?= inr((float)$b['discount_amount']) ?></small>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($b['payment_method'] === 'upi'): ?>
            <?php if ($b['upi_screenshot']): ?>
              <a href="../<?= e($b['upi_screenshot']) ?>" target="_blank">📎 image</a><br>
            <?php endif; ?>
            <?php if ($b['upi_utr']): ?>
              <small class="muted">UTR: <?= e($b['upi_utr']) ?></small>
            <?php endif; ?>
            <?php if (!$b['upi_screenshot'] && !$b['upi_utr']): ?>
              <small class="muted">—</small>
            <?php endif; ?>
          <?php else: ?>
            <small class="muted">Cash</small>
          <?php endif; ?>
        </td>
        <td><span class="pill pill-<?= e($b['status']) ?>"><?= e($b['status']) ?></span></td>
        <td><a href="booking.php?id=<?= (int)$b['id'] ?>" class="btn btn-secondary btn-sm">Open</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
