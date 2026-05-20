<?php
require __DIR__ . '/includes/bootstrap.php';

$settings = get_settings($pdo);

// Booking window (admin-configurable). Default 7 days.
$windowDays = max(1, (int)($settings['booking_window_days'] ?? 7));

// Payment methods
$cashEnabled = !empty($settings['cash_enabled']);
$upiEnabled  = !empty($settings['upi_enabled']);
$paymentLabel = '';
if ($cashEnabled && $upiEnabled)      $paymentLabel = 'Cash or UPI';
elseif ($upiEnabled)                   $paymentLabel = 'UPI';
elseif ($cashEnabled)                  $paymentLabel = 'Cash';

// Date the customer is viewing (default today, clamped to window)
$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}
$today   = date('Y-m-d');
$minDate = $today;
$maxDate = date('Y-m-d', strtotime('+' . ($windowDays - 1) . ' days'));
if ($selectedDate < $minDate) $selectedDate = $minDate;
if ($selectedDate > $maxDate) $selectedDate = $maxDate;

// Fetch active slots
$allSlots = $pdo->query("SELECT * FROM slots WHERE is_active = 1 ORDER BY sort_order, start_time")->fetchAll();

// Filter to slots enabled on the selected date's weekday
$slots = [];
foreach ($allSlots as $s) {
    if (slot_enabled_on($s, $selectedDate)) {
        // Inject the rate for THIS date so downstream code uses it
        $s['rate_today'] = slot_rate_for($s, $selectedDate);
        $slots[] = $s;
    }
}

// Find which are taken on the selected date
$taken = [];
if ($slots) {
    $stmt = $pdo->prepare("SELECT slot_id FROM bookings
        WHERE booking_date = ? AND status IN ('pending','confirmed')");
    $stmt->execute([$selectedDate]);
    foreach ($stmt->fetchAll() as $r) $taken[(int)$r['slot_id']] = true;
}

// Past-slot detection: only relevant when viewing TODAY.
// A slot is "past" if its start time on today is <= now.
$isToday = ($selectedDate === $today);
$nowTs = time();
$past = [];
if ($isToday && $slots) {
    foreach ($slots as $s) {
        $slotStartTs = strtotime($selectedDate . ' ' . $s['start_time']);
        if ($slotStartTs !== false && $slotStartTs <= $nowTs) {
            $past[(int)$s['id']] = true;
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($settings['venue_name']) ?> · Book a Slot</title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="customer">
<header class="topbar">
  <div class="brand">
    <span class="brand-mark">🏏</span>
    <div>
      <div class="brand-name"><?= e($settings['venue_name']) ?></div>
      <div class="brand-sub"><?= e($settings['venue_address']) ?></div>
    </div>
  </div>
</header>

<main class="container">
  <?= render_flash() ?>

  <section class="card">
    <h1>Book your slot</h1>
    <p class="muted">Pick a date, choose an available slot, pay <?= inr((float)$settings['advance_amount']) ?> advance to confirm. Bookings open for the next <?= (int)$windowDays ?> day<?= $windowDays > 1 ? 's' : '' ?>.</p>

    <form method="get" class="date-picker">
      <label for="date">Date</label>
      <input type="date" id="date" name="date"
             value="<?= e($selectedDate) ?>"
             min="<?= e($minDate) ?>" max="<?= e($maxDate) ?>"
             onchange="this.form.submit()">
    </form>

    <div class="day-label">
      <?= e(date('l, F j, Y', strtotime($selectedDate))) ?>
      <?php if ($isToday): ?> · Today<?php endif; ?>
    </div>

    <?php if (empty($allSlots)): ?>
      <div class="empty-state">
        <p>No slots configured yet. Please check back soon.</p>
      </div>
    <?php elseif (empty($slots)): ?>
      <div class="empty-state">
        <p>No slots available on <?= e(date('l', strtotime($selectedDate))) ?>s. Try another day.</p>
      </div>
    <?php else: ?>
      <div class="slot-grid">
        <?php foreach ($slots as $slot):
          $slotId  = (int)$slot['id'];
          $isPast  = isset($past[$slotId]);
          $isTaken = isset($taken[$slotId]);
          $todayRate = (float)$slot['rate_today'];
        ?>
          <?php if ($isPast): ?>
            <div class="slot slot-past" aria-disabled="true">
              <div class="slot-time"><?= e(slot_label($slot)) ?></div>
              <div class="slot-rate"><?= inr($todayRate) ?></div>
              <div class="slot-status">Past</div>
            </div>
          <?php elseif ($isTaken): ?>
            <div class="slot slot-taken" aria-disabled="true">
              <div class="slot-time"><?= e(slot_label($slot)) ?></div>
              <div class="slot-rate"><?= inr($todayRate) ?></div>
              <div class="slot-status">Booked</div>
            </div>
          <?php else: ?>
            <a class="slot slot-available"
               href="book.php?slot=<?= $slotId ?>&date=<?= e($selectedDate) ?>">
              <div class="slot-time"><?= e(slot_label($slot)) ?></div>
              <div class="slot-rate"><?= inr($todayRate) ?></div>
              <div class="slot-status">Tap to book →</div>
            </a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="card info">
    <h2>How it works</h2>
    <ol>
      <li>Pick a slot above and enter your name + mobile.</li>
      <?php if ($paymentLabel !== ''): ?>
        <li>Pay <strong><?= inr((float)$settings['advance_amount']) ?> advance</strong> via <?= e($paymentLabel) ?>.</li>
      <?php endif; ?>
      <?php if ($upiEnabled): ?>
        <li>For UPI, upload screenshot or enter UTR. We verify within minutes.</li>
      <?php endif; ?>
      <li>Pay the balance at the venue before your slot starts.</li>
    </ol>
    <?php if (!empty($settings['contact_phone'])): ?>
      <p class="muted">Questions? Call <a href="tel:<?= e($settings['contact_phone']) ?>"><?= e($settings['contact_phone']) ?></a></p>
    <?php endif; ?>
  </section>

  <section class="rules-section">
    <img src="assets/rules.jpg" alt="Games N Bites Box Cricket — Rules & Regulations" class="rules-image">
  </section>
</main>

<footer class="footer">
  <small>© <?= date('Y') ?> <?= e($settings['venue_name']) ?></small>
</footer>
</body>
</html>
