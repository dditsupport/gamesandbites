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

// Is booking disabled entirely for this date?
$dateBlocked = is_date_blocked($pdo, $selectedDate);
$blockReason = $dateBlocked ? blocked_date_reason($pdo, $selectedDate) : null;

// Short date shown as a prefix on each slot's timing, e.g. "Thu 21 May"
$datePrefix = fmt_date_short($selectedDate);

// Rules image (admin-uploadable; falls back to the bundled file)
$rulesImg = !empty($settings['rules_image']) ? $settings['rules_image'] : 'assets/rules.jpg';

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

// Find which slots are held on the selected date, split by lifecycle stage:
//   confirmed                       -> "Booked" (+ customer name)
//   pending + payment proof / cash  -> "Payment done · awaiting confirmation" (held)
//   pending + no proof (UPI)        -> "In process" (auto-releases after the grace window)
$booked = [];      // slot_id => customer name
$awaiting = [];    // slot_id => 'paid' | 'cash'
$inProcess = [];   // slot_id => true
if ($slots) {
    $stmt = $pdo->prepare("SELECT slot_id, status, payment_method, customer_name,
            (upi_utr IS NOT NULL OR upi_screenshot IS NOT NULL) AS has_proof
        FROM bookings
        WHERE booking_date = ? AND " . slot_hold_sql() . "
        ORDER BY FIELD(status,'confirmed','pending')");
    $stmt->execute([$selectedDate]);
    foreach ($stmt->fetchAll() as $r) {
        $sid = (int)$r['slot_id'];
        if (isset($booked[$sid])) continue; // confirmed wins
        if ($r['status'] === 'confirmed') {
            $booked[$sid] = $r['customer_name'];
            unset($awaiting[$sid], $inProcess[$sid]);
        } elseif ($r['payment_method'] === 'cash' || !empty($r['has_proof'])) {
            if (!isset($awaiting[$sid])) {
                $awaiting[$sid] = !empty($r['has_proof']) ? 'paid' : 'cash';
            }
            unset($inProcess[$sid]);
        } elseif (!isset($awaiting[$sid])) {
            $inProcess[$sid] = true;
        }
    }
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
    <a href="index.php" class="brand-mark" title="Home" aria-label="Home" style="text-decoration:none">🏏</a>
    <div>
      <div class="brand-name"><?= e($settings['venue_name']) ?></div>
      <div class="brand-sub"><?= e($settings['venue_address']) ?></div>
    </div>
  </div>
</header>

<main class="container home-page">
  <?= render_flash() ?>

  <div class="home-layout">
    <!-- Left column: location map above the rules image -->
    <div class="home-left">
      <section class="card location-card">
        <h2>📍 Find us — <?= e($settings['venue_name']) ?></h2>
        <?php if (!empty($settings['venue_address'])): ?>
          <p class="muted" style="margin-top:-4px"><?= e($settings['venue_address']) ?></p>
        <?php endif; ?>
        <div class="map-embed">
          <iframe
            src="https://maps.google.com/maps?q=Games%20n%27%20Bites%20Box%20Cricket%20and%20Party%20Lawn%2C%20Nana%20Chiloda%2C%20Ahmedabad&output=embed"
            title="Map to <?= e($settings['venue_name']) ?>"
            loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
        </div>
        <a class="btn btn-secondary btn-sm" style="margin-top:12px"
           href="https://share.google/ybcskuSpMhYfP1ZhF" target="_blank" rel="noopener">
          Open in Google Maps / Get directions →
        </a>
      </section>

      <section class="home-rules">
        <img src="<?= e($rulesImg) ?>" alt="Games N Bites Box Cricket — Rules &amp; Regulations" class="rules-image">
      </section>
    </div>

    <div class="home-booking">
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

    <?php if ($dateBlocked): ?>
      <div class="empty-state">
        <p><strong>Bookings are closed on <?= e(date('l, F j', strtotime($selectedDate))) ?>.</strong></p>
        <?php if ($blockReason): ?><p class="muted"><?= e($blockReason) ?></p><?php endif; ?>
        <p class="muted">Please pick another date.</p>
      </div>
    <?php elseif (empty($allSlots)): ?>
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
          $slotId   = (int)$slot['id'];
          $isPast   = isset($past[$slotId]);
          $bookedName = $booked[$slotId] ?? null;
          $awaitKind  = ($bookedName === null && isset($awaiting[$slotId])) ? $awaiting[$slotId] : null;
          $isInProcess = $bookedName === null && $awaitKind === null && isset($inProcess[$slotId]);
          $todayRate = (float)$slot['rate_today'];
        ?>
          <?php if ($isPast): ?>
            <div class="slot slot-past" aria-disabled="true">
              <div class="slot-time"><?= e($datePrefix) ?> · <?= e(slot_label($slot)) ?></div>
              <div class="slot-rate"><?= inr($todayRate) ?></div>
              <div class="slot-status">Past</div>
            </div>
          <?php elseif ($bookedName !== null): ?>
            <div class="slot slot-taken" aria-disabled="true">
              <div class="slot-time"><?= e($datePrefix) ?> · <?= e(slot_label($slot)) ?></div>
              <div class="slot-rate"><?= inr($todayRate) ?></div>
              <div class="slot-status">Booked</div>
              <div class="slot-name">🏏 <?= e($bookedName) ?></div>
            </div>
          <?php elseif ($awaitKind !== null): ?>
            <div class="slot slot-awaiting" aria-disabled="true">
              <div class="slot-time"><?= e($datePrefix) ?> · <?= e(slot_label($slot)) ?></div>
              <div class="slot-rate"><?= inr($todayRate) ?></div>
              <div class="slot-status">
                <?= $awaitKind === 'paid' ? 'Payment done · awaiting confirmation' : 'Reserved · awaiting confirmation' ?>
              </div>
            </div>
          <?php elseif ($isInProcess): ?>
            <div class="slot slot-pending" aria-disabled="true">
              <div class="slot-time"><?= e($datePrefix) ?> · <?= e(slot_label($slot)) ?></div>
              <div class="slot-rate"><?= inr($todayRate) ?></div>
              <div class="slot-status">In process · try again in <?= (int)SLOT_HOLD_MINUTES ?> min</div>
            </div>
          <?php else: ?>
            <a class="slot slot-available"
               href="book.php?slot=<?= $slotId ?>&date=<?= e($selectedDate) ?>">
              <div class="slot-time"><?= e($datePrefix) ?> · <?= e(slot_label($slot)) ?></div>
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
    </div><!-- /.home-booking -->
  </div><!-- /.home-layout --><!-- left = map+rules, right = slots -->
</main>

<footer class="footer">
  <small>© <?= date('Y') ?> <?= e($settings['venue_name']) ?></small>
</footer>
</body>
</html>
