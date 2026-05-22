<?php
require __DIR__ . '/includes/bootstrap.php';

$settings = get_settings($pdo);
$advance  = (float)$settings['advance_amount'];
$windowDays = max(1, (int)($settings['booking_window_days'] ?? 7));

// Which payment methods are enabled by admin?
$cashEnabled = !empty($settings['cash_enabled']);
$upiEnabled  = !empty($settings['upi_enabled']);
$enabledMethods = [];
if ($upiEnabled)  $enabledMethods[] = 'upi';
if ($cashEnabled) $enabledMethods[] = 'cash';
if (!$enabledMethods) {
    flash_set('error', 'Online booking is temporarily unavailable. Please contact the venue.');
    redirect('index.php');
}

$slotId = (int)($_GET['slot'] ?? $_POST['slot_id'] ?? 0);
$date   = $_GET['date'] ?? $_POST['booking_date'] ?? '';

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date < date('Y-m-d')) {
    flash_set('error', 'Please pick a valid date.');
    redirect('index.php');
}

// Enforce booking window
$maxDate = date('Y-m-d', strtotime('+' . ($windowDays - 1) . ' days'));
if ($date > $maxDate) {
    flash_set('error', 'Bookings are only open for the next ' . $windowDays . ' day' . ($windowDays > 1 ? 's' : '') . '.');
    redirect('index.php');
}

// Admin may have disabled bookings entirely for this date
if (is_date_blocked($pdo, $date)) {
    $reason = blocked_date_reason($pdo, $date);
    flash_set('error', 'Bookings are closed on ' . date('D, M j', strtotime($date))
        . ($reason ? ' — ' . $reason : '') . '.');
    redirect('index.php?date=' . urlencode($date));
}

$slot = null;
if ($slotId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM slots WHERE id = ? AND is_active = 1");
    $stmt->execute([$slotId]);
    $slot = $stmt->fetch();
}
if (!$slot) {
    flash_set('error', 'Slot not found or no longer available.');
    redirect('index.php?date=' . urlencode($date));
}

// Check slot is enabled on the chosen weekday
if (!slot_enabled_on($slot, $date)) {
    flash_set('error', 'This slot is not available on ' . date('l', strtotime($date)) . 's.');
    redirect('index.php?date=' . urlencode($date));
}

// Block booking of past slots on today
if ($date === date('Y-m-d')) {
    $slotStartTs = strtotime($date . ' ' . $slot['start_time']);
    if ($slotStartTs !== false && $slotStartTs <= time()) {
        flash_set('error', 'This slot has already started or passed.');
        redirect('index.php?date=' . urlencode($date));
    }
}

if (is_slot_taken($pdo, $slotId, $date)) {
    flash_set('error', 'Sorry, that slot was just booked. Please pick another.');
    redirect('index.php?date=' . urlencode($date));
}

// Use the rate for THIS date's weekday (not the legacy single rate)
$slotRate = slot_rate_for($slot, $date);
if ($slotRate <= 0) {
    flash_set('error', 'Pricing for this slot is not configured. Please contact the venue.');
    redirect('index.php?date=' . urlencode($date));
}

// Whether coupons may be applied to this slot on this weekday (admin-controlled)
$couponsAllowed = slot_allows_coupon($slot, $date);

$errors = [];
$old = ['name'=>'', 'mobile'=>'', 'payment_method'=>$enabledMethods[0], 'coupon_code'=>''];
$couponMsg = null;
$appliedCoupon = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $old['name']           = trim($_POST['name'] ?? '');
    $old['mobile']         = trim($_POST['mobile'] ?? '');
    $old['payment_method'] = $_POST['payment_method'] ?? $enabledMethods[0];
    $old['coupon_code']    = strtoupper(trim($_POST['coupon_code'] ?? ''));

    $action = $_POST['action'] ?? 'place';

    if ($action === 'apply_coupon') {
        if (!$couponsAllowed) {
            $couponMsg = ['type'=>'error', 'text'=>'Coupons can\'t be applied to this slot on ' . date('l', strtotime($date)) . 's.'];
        } elseif ($old['coupon_code'] === '') {
            $couponMsg = ['type'=>'error', 'text'=>'Enter a coupon code.'];
        } else {
            $res = validate_coupon($pdo, $old['coupon_code'], $slotRate);
            if ($res['ok']) {
                $appliedCoupon = $res['coupon'];
                $couponMsg = ['type'=>'success',
                    'text'=>'Coupon applied: ' . inr((float)$appliedCoupon['discount_amount']) . ' off'];
            } else {
                $couponMsg = ['type'=>'error', 'text'=>$res['error']];
            }
        }
    } elseif ($action === 'remove_coupon') {
        $old['coupon_code'] = '';
        $couponMsg = null;
        $appliedCoupon = null;
    } else {
        // Place booking
        if ($old['coupon_code'] !== '') {
            if (!$couponsAllowed) {
                $errors['coupon_code'] = 'Coupons can\'t be applied to this slot on ' . date('l', strtotime($date)) . 's.';
            } else {
                $res = validate_coupon($pdo, $old['coupon_code'], $slotRate);
                if ($res['ok']) {
                    $appliedCoupon = $res['coupon'];
                } else {
                    $errors['coupon_code'] = $res['error'];
                }
            }
        }

        if ($old['name'] === '' || mb_strlen($old['name']) < 2) {
            $errors['name'] = 'Please enter your name.';
        } elseif (!preg_match('/^[A-Za-z ]+$/', $old['name'])) {
            $errors['name'] = 'Name can contain letters and spaces only.';
        }
        $mobile = normalize_mobile($old['mobile']);
        if ($mobile === null) {
            $errors['mobile'] = 'Enter a valid 10-digit mobile number.';
        }
        if (!in_array($old['payment_method'], $enabledMethods, true)) {
            $errors['payment_method'] = 'Choose a valid payment method.';
        }

        if (!$errors && is_slot_taken($pdo, $slotId, $date)) {
            $errors['_'] = 'That slot was just booked by someone else. Please choose another.';
        }

        if (!$errors) {
            $discount = $appliedCoupon ? (float)$appliedCoupon['discount_amount'] : 0;
            $couponId = $appliedCoupon ? (int)$appliedCoupon['id'] : null;
            $couponCode = $appliedCoupon ? $appliedCoupon['code'] : null;

            $pdo->beginTransaction();
            $bookingId = 0;
            try {
                $code = generate_booking_code();
                for ($i = 0; $i < 3; $i++) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO bookings
                            (booking_code, slot_id, booking_date, customer_name, customer_mobile,
                             slot_rate, coupon_id, coupon_code, discount_amount,
                             advance_amount, payment_method, status)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
                        $stmt->execute([
                            $code, $slotId, $date, $old['name'], $mobile,
                            $slotRate, $couponId, $couponCode, $discount,
                            $advance, $old['payment_method'],
                        ]);
                        break;
                    } catch (PDOException $e) {
                        if ($i === 2) throw $e;
                        $code = generate_booking_code();
                    }
                }
                $bookingId = (int)$pdo->lastInsertId();

                if ($couponId !== null) {
                    $stmt = $pdo->prepare("UPDATE coupons
                        SET used_count = used_count + 1
                        WHERE id = ?
                          AND is_active = 1
                          AND (usage_limit IS NULL OR used_count < usage_limit)");
                    $stmt->execute([$couponId]);
                    if ($stmt->rowCount() === 0) {
                        throw new RuntimeException('Coupon was just used up. Please try again.');
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors['_'] = $e instanceof RuntimeException
                    ? $e->getMessage()
                    : 'Could not place booking. Please try again.';
                $bookingId = 0;
            }

            if ($bookingId > 0 && empty($errors['_'])) {
                $clickUrl = ($settings['base_url'] ?: '') . '/admin/booking.php?id=' . $bookingId;
                $discountLine = ($appliedCoupon ? "\nCoupon: {$couponCode} (-" . inr((float)$discount) . ")" : '');
                ntfy_notify($pdo,
                    'New booking: ' . $code,
                    sprintf(
                        "%s\n%s · %s\n%s · %s advance%s\nMobile: %s",
                        $old['name'],
                        date('D, M j', strtotime($date)),
                        slot_label($slot),
                        strtoupper($old['payment_method']),
                        inr($advance),
                        $discountLine,
                        $mobile
                    ),
                    ['priority' => 'high', 'tags' => 'cricket,bell', 'click' => $clickUrl]
                );
                redirect('confirm.php?id=' . $bookingId);
            }
        }
    }
}

$discountAmount = $appliedCoupon ? (float)$appliedCoupon['discount_amount'] : 0;
$totalAfterDiscount = max(0, $slotRate - $discountAmount);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Confirm booking · <?= e($settings['venue_name']) ?></title>
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="customer">
<header class="topbar">
  <div class="brand">
    <a href="index.php?date=<?= e($date) ?>" class="back">←</a>
    <div>
      <div class="brand-name">Confirm booking</div>
      <div class="brand-sub"><?= e($settings['venue_name']) ?></div>
    </div>
  </div>
</header>

<main class="container">
  <?php if (!empty($errors['_'])): ?>
    <div class="flash flash-error"><?= e($errors['_']) ?></div>
  <?php endif; ?>

  <section class="card">
    <div class="summary">
      <div class="summary-row"><span>Date</span><strong><?= e(date('D, M j, Y', strtotime($date))) ?></strong></div>
      <div class="summary-row"><span>Slot</span><strong><?= e(slot_label($slot)) ?></strong></div>
      <div class="summary-row"><span>Slot price</span><strong><?= inr($slotRate) ?></strong></div>
      <?php if ($discountAmount > 0): ?>
        <div class="summary-row" style="color: var(--success)">
          <span>Coupon <?= e($appliedCoupon['code']) ?></span>
          <strong>− <?= inr($discountAmount) ?></strong>
        </div>
      <?php endif; ?>
      <div class="summary-row"><span>Total amount</span><strong><?= inr($totalAfterDiscount) ?></strong></div>
      <div class="summary-row highlight"><span>Pay now (advance)</span><strong><?= inr($advance) ?></strong></div>
      <div class="summary-row"><span>Balance at venue</span><strong><?= inr(max(0, $totalAfterDiscount - $advance)) ?></strong></div>
    </div>
  </section>

  <?php if ($couponsAllowed): ?>
  <section class="card">
    <h2>Have a coupon?</h2>
    <form method="post" class="coupon-form">
      <?= csrf_field() ?>
      <input type="hidden" name="slot_id" value="<?= (int)$slotId ?>">
      <input type="hidden" name="booking_date" value="<?= e($date) ?>">
      <input type="hidden" name="name" value="<?= e($old['name']) ?>">
      <input type="hidden" name="mobile" value="<?= e($old['mobile']) ?>">
      <input type="hidden" name="payment_method" value="<?= e($old['payment_method']) ?>">

      <?php if ($appliedCoupon): ?>
        <div class="coupon-applied">
          <div>
            <strong><?= e($appliedCoupon['code']) ?></strong>
            <small class="muted"> · <?= inr((float)$appliedCoupon['discount_amount']) ?> off</small>
          </div>
          <button type="submit" name="action" value="remove_coupon" class="btn btn-ghost btn-sm">Remove</button>
        </div>
        <input type="hidden" name="coupon_code" value="<?= e($appliedCoupon['code']) ?>">
      <?php else: ?>
        <div class="coupon-input-row">
          <input type="text" name="coupon_code"
                 value="<?= e($old['coupon_code']) ?>"
                 placeholder="Enter code e.g. GNB100"
                 maxlength="30"
                 style="text-transform:uppercase">
          <button type="submit" name="action" value="apply_coupon" class="btn btn-secondary">Apply</button>
        </div>
        <?php if ($couponMsg): ?>
          <small style="<?= $couponMsg['type']==='success' ? 'color:var(--success)' : 'color:var(--danger)' ?>;display:block;margin-top:6px">
            <?= e($couponMsg['text']) ?>
          </small>
        <?php endif; ?>
        <?php if (!empty($errors['coupon_code'])): ?>
          <small class="err"><?= e($errors['coupon_code']) ?></small>
        <?php endif; ?>
      <?php endif; ?>
    </form>
  </section>
  <?php endif; ?>

  <section class="card">
    <h2>Your details</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="place">
      <input type="hidden" name="slot_id" value="<?= (int)$slotId ?>">
      <input type="hidden" name="booking_date" value="<?= e($date) ?>">
      <?php if ($appliedCoupon): ?>
        <input type="hidden" name="coupon_code" value="<?= e($appliedCoupon['code']) ?>">
      <?php endif; ?>

      <div class="field">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" required maxlength="100"
               value="<?= e($old['name']) ?>"
               placeholder="e.g. Mohit Motwani"
               pattern="[A-Za-z ]+" title="Letters and spaces only"
               oninput="this.value=this.value.replace(/[^A-Za-z ]/g,'')">
        <?php if (!empty($errors['name'])): ?>
          <small class="err"><?= e($errors['name']) ?></small>
        <?php endif; ?>
      </div>

      <div class="field">
        <label for="mobile">Mobile number</label>
        <input type="tel" id="mobile" name="mobile" required
               value="<?= e($old['mobile']) ?>"
               placeholder="10-digit number" inputmode="numeric"
               maxlength="10" pattern="[0-9]{10}" title="Exactly 10 digits, numbers only"
               oninput="this.value=this.value.replace(/\D/g,'').slice(0,10)">
        <?php if (!empty($errors['mobile'])): ?>
          <small class="err"><?= e($errors['mobile']) ?></small>
        <?php endif; ?>
      </div>

      <div class="field">
        <label>Payment method for advance</label>
        <?php if (count($enabledMethods) === 1): ?>
          <?php $only = $enabledMethods[0]; ?>
          <input type="hidden" name="payment_method" value="<?= e($only) ?>">
          <div class="payment-single">
            <?php if ($only === 'upi'): ?>
              <strong>UPI</strong>
              <small>GPay, PhonePe, Paytm — pay online</small>
            <?php else: ?>
              <strong>Cash</strong>
              <small>Pay <?= inr($advance) ?> at venue to confirm</small>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="payment-options">
            <?php if ($upiEnabled): ?>
              <label class="payment-option">
                <input type="radio" name="payment_method" value="upi"
                       <?= $old['payment_method'] === 'upi' ? 'checked' : '' ?>>
                <span class="payment-card">
                  <strong>UPI</strong>
                  <small>GPay, PhonePe, Paytm — pay online</small>
                </span>
              </label>
            <?php endif; ?>
            <?php if ($cashEnabled): ?>
              <label class="payment-option">
                <input type="radio" name="payment_method" value="cash"
                       <?= $old['payment_method'] === 'cash' ? 'checked' : '' ?>>
                <span class="payment-card">
                  <strong>Cash</strong>
                  <small>Pay <?= inr($advance) ?> at venue to confirm</small>
                </span>
              </label>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>

      <button type="submit" class="btn btn-primary btn-block">
        Place booking · <?= inr($advance) ?>
      </button>
      <p class="muted small">By placing a booking you agree the advance is non-refundable.</p>
    </form>
  </section>
</main>

<footer class="footer">
  <small>© <?= date('Y') ?> <?= e($settings['venue_name']) ?></small>
</footer>
</body>
</html>
