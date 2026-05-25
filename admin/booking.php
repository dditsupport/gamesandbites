<?php
require __DIR__ . '/_auth.php';
$pageTitle = 'Booking';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT b.*, s.start_time, s.end_time, s.crosses_midnight
    FROM bookings b JOIN slots s ON s.id = b.slot_id WHERE b.id = ?");
$stmt->execute([$id]);
$b = $stmt->fetch();

if (!$b) {
    flash_set('error', 'Booking not found.');
    redirect('bookings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $note = trim($_POST['admin_note'] ?? '');

    if ($action === 'confirm') {
        $stmt = $pdo->prepare("UPDATE bookings SET status='confirmed', admin_note=? WHERE id=?");
        $stmt->execute([$note ?: null, $id]);
        flash_set('success', 'Booking confirmed.');
    } elseif ($action === 'cancel') {
        $stmt = $pdo->prepare("UPDATE bookings SET status='cancelled', admin_note=? WHERE id=?");
        $stmt->execute([$note ?: null, $id]);
        flash_set('success', 'Booking cancelled.');
    } elseif ($action === 'reopen') {
        $stmt = $pdo->prepare("UPDATE bookings SET status='pending', admin_note=? WHERE id=?");
        $stmt->execute([$note ?: null, $id]);
        flash_set('success', 'Booking moved back to pending.');
    } elseif ($action === 'save_note') {
        $stmt = $pdo->prepare("UPDATE bookings SET admin_note=? WHERE id=?");
        $stmt->execute([$note ?: null, $id]);
        flash_set('success', 'Note saved.');
    } elseif ($action === 'save_pending') {
        $amount  = (float)($_POST['pending_amount'] ?? 0);
        $method  = $_POST['pending_method'] ?? '';
        $remarks = trim($_POST['pending_remarks'] ?? '');

        if ($amount <= 0) {
            flash_set('error', 'Pending amount must be greater than 0.');
        } elseif (!in_array($method, ['cash', 'upi'], true)) {
            flash_set('error', 'Pick a payment method.');
        } else {
            $stmt = $pdo->prepare(
                "UPDATE bookings
                    SET pending_amount=?, pending_method=?, pending_remarks=?, pending_recorded_at=NOW()
                  WHERE id=?"
            );
            $stmt->execute([$amount, $method, $remarks ?: null, $id]);
            flash_set('success', 'Pending payment recorded.');
        }
    } elseif ($action === 'clear_pending') {
        $stmt = $pdo->prepare(
            "UPDATE bookings
                SET pending_amount=NULL, pending_method=NULL,
                    pending_remarks=NULL, pending_recorded_at=NULL
              WHERE id=?"
        );
        $stmt->execute([$id]);
        flash_set('success', 'Pending payment cleared.');
    } elseif ($action === 'save_adjustment') {
        $extra  = (float)($_POST['extra_discount'] ?? 0);
        $reason = trim($_POST['extra_discount_reason'] ?? '');
        $maxAllowed = max(0, (float)$b['slot_rate'] - (float)$b['discount_amount']);

        if ($extra < 0) {
            flash_set('error', 'Extra discount cannot be negative.');
        } elseif ($extra > $maxAllowed) {
            flash_set('error', 'Extra discount (' . inr($extra) . ') cannot exceed total after coupon (' . inr($maxAllowed) . ').');
        } else {
            $stmt = $pdo->prepare(
                "UPDATE bookings SET extra_discount=?, extra_discount_reason=? WHERE id=?"
            );
            $stmt->execute([$extra, $reason ?: null, $id]);
            flash_set('success', 'Total adjusted.');
        }
    } elseif ($action === 'clear_adjustment') {
        $stmt = $pdo->prepare(
            "UPDATE bookings SET extra_discount=0, extra_discount_reason=NULL WHERE id=?"
        );
        $stmt->execute([$id]);
        flash_set('success', 'Adjustment cleared.');
    }
    redirect('booking.php?id=' . $id);
}

$slot = ['start_time'=>$b['start_time'],'end_time'=>$b['end_time'],'crosses_midnight'=>$b['crosses_midnight']];

require __DIR__ . '/_layout_top.php';
?>

<div class="admin-header">
  <h1>
    <a href="bookings.php" style="color:var(--muted);text-decoration:none">←</a>
    <?= e($b['booking_code']) ?>
    <span class="pill pill-<?= e($b['status']) ?>"><?= e($b['status']) ?></span>
  </h1>
</div>

<div class="detail-grid">
  <div class="card">
    <h2>Customer</h2>
    <div class="summary">
      <div class="summary-row"><span>Name</span><strong><?= e($b['customer_name']) ?></strong></div>
      <div class="summary-row"><span>Mobile</span><strong><a href="tel:<?= e($b['customer_mobile']) ?>"><?= e($b['customer_mobile']) ?></a></strong></div>
      <div class="summary-row"><span>Booking placed</span><strong><?= e(date('M j, Y g:i A', strtotime($b['created_at']))) ?></strong></div>
    </div>
  </div>

  <div class="card">
    <h2>Slot</h2>
    <?php $finalTotal = max(0, (float)$b['slot_rate'] - (float)$b['discount_amount'] - (float)$b['extra_discount']); ?>
    <div class="summary">
      <div class="summary-row"><span>Date</span><strong><?= e(date('D, M j, Y', strtotime($b['booking_date']))) ?></strong></div>
      <div class="summary-row"><span>Time</span><strong><?= e(slot_label($slot)) ?></strong></div>
      <div class="summary-row"><span>Slot rate</span><strong><?= inr((float)$b['slot_rate']) ?></strong></div>
      <?php if ((float)$b['discount_amount'] > 0): ?>
        <div class="summary-row" style="color:var(--success)">
          <span>Coupon <?= e($b['coupon_code']) ?></span>
          <strong>− <?= inr((float)$b['discount_amount']) ?></strong>
        </div>
      <?php endif; ?>
      <?php if ((float)$b['extra_discount'] > 0): ?>
        <div class="summary-row" style="color:var(--success)">
          <span>Extra discount<?= !empty($b['extra_discount_reason']) ? ' — ' . e($b['extra_discount_reason']) : '' ?></span>
          <strong>− <?= inr((float)$b['extra_discount']) ?></strong>
        </div>
      <?php endif; ?>
      <div class="summary-row"><span>Total</span><strong><?= inr($finalTotal) ?></strong></div>
      <div class="summary-row highlight"><span>Advance</span><strong><?= inr((float)$b['advance_amount']) ?></strong></div>
      <?php if ($b['pending_amount'] !== null): ?>
        <div class="summary-row" style="color:var(--success)"><span>Pending received (<?= strtoupper(e($b['pending_method'])) ?>)</span><strong>+ <?= inr((float)$b['pending_amount']) ?></strong></div>
      <?php endif; ?>
      <?php $balance = max(0, $finalTotal - (float)$b['advance_amount'] - (float)($b['pending_amount'] ?? 0)); ?>
      <div class="summary-row"><span>Balance at venue</span><strong><?= inr($balance) ?></strong></div>
    </div>
  </div>

  <div class="card">
    <h2>Payment</h2>
    <div class="summary">
      <div class="summary-row"><span>Method</span><strong><?= strtoupper($b['payment_method']) ?></strong></div>
      <?php if ($b['payment_method'] === 'upi'): ?>
        <div class="summary-row"><span>UTR</span><strong><?= e($b['upi_utr'] ?: '—') ?></strong></div>
      <?php endif; ?>
    </div>
    <?php if ($b['payment_method'] === 'upi' && $b['upi_screenshot']): ?>
      <h3 style="margin-top:16px">Screenshot</h3>
      <a href="../<?= e($b['upi_screenshot']) ?>" target="_blank">
        <img src="../<?= e($b['upi_screenshot']) ?>" alt="Payment proof" style="max-width:100%;border-radius:8px;border:1px solid var(--line)">
      </a>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Pending payment</h2>

    <?php if ($b['pending_amount'] !== null): ?>
      <div class="summary">
        <div class="summary-row highlight">
          <span>Amount due</span>
          <strong><?= inr((float)$b['pending_amount']) ?></strong>
        </div>
        <div class="summary-row">
          <span>Method</span>
          <strong><?= strtoupper(e($b['pending_method'])) ?></strong>
        </div>
        <?php if (!empty($b['pending_remarks'])): ?>
          <div class="summary-row">
            <span>Remarks</span>
            <strong style="text-align:right;max-width:60%"><?= nl2br(e($b['pending_remarks'])) ?></strong>
          </div>
        <?php endif; ?>
        <div class="summary-row" style="color:var(--muted)">
          <span>Recorded</span>
          <strong><?= e(date('M j, Y g:i A', strtotime($b['pending_recorded_at']))) ?></strong>
        </div>
      </div>
      <hr style="border:0;border-top:1px dashed var(--line);margin:14px 0">
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <div class="field">
        <label for="pending_amount">Amount (INR)</label>
        <input type="number" step="0.01" min="0" id="pending_amount" name="pending_amount"
               value="<?= e($b['pending_amount'] !== null ? (string)$b['pending_amount'] : '') ?>"
               placeholder="e.g. 1500.00">
      </div>
      <div class="field">
        <label for="pending_method">Method</label>
        <select id="pending_method" name="pending_method">
          <option value="">— Select —</option>
          <option value="cash" <?= ($b['pending_method'] ?? '') === 'cash' ? 'selected' : '' ?>>Cash</option>
          <option value="upi"  <?= ($b['pending_method'] ?? '') === 'upi'  ? 'selected' : '' ?>>UPI</option>
        </select>
      </div>
      <div class="field">
        <label for="pending_remarks">Remarks</label>
        <textarea id="pending_remarks" name="pending_remarks" rows="2"
                  placeholder="e.g. Customer to pay balance on arrival"><?= e($b['pending_remarks'] ?? '') ?></textarea>
      </div>
      <div class="actions">
        <button type="submit" name="action" value="save_pending" class="btn btn-primary">
          <?= $b['pending_amount'] !== null ? 'Update' : 'Record' ?>
        </button>
        <?php if ($b['pending_amount'] !== null): ?>
          <button type="submit" name="action" value="clear_pending" class="btn btn-ghost"
                  onclick="return confirm('Clear the pending payment note?');">Clear</button>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Adjust total</h2>
    <p class="muted small" style="margin-top:0">
      Reduce the total when the customer didn't pay full amount, or you granted an extra discount beyond a coupon.
      Stored as a positive amount and subtracted from the bill.
    </p>

    <?php if ((float)$b['extra_discount'] > 0): ?>
      <div class="summary">
        <div class="summary-row highlight">
          <span>Extra discount</span>
          <strong style="color:var(--success)">− <?= inr((float)$b['extra_discount']) ?></strong>
        </div>
        <?php if (!empty($b['extra_discount_reason'])): ?>
          <div class="summary-row">
            <span>Reason</span>
            <strong style="text-align:right;max-width:60%"><?= e($b['extra_discount_reason']) ?></strong>
          </div>
        <?php endif; ?>
      </div>
      <hr style="border:0;border-top:1px dashed var(--line);margin:14px 0">
    <?php endif; ?>

    <form method="post">
      <?= csrf_field() ?>
      <div class="field">
        <label for="extra_discount">Reduce total by (INR)</label>
        <input type="number" step="0.01" min="0" id="extra_discount" name="extra_discount"
               value="<?= e((float)$b['extra_discount'] > 0 ? (string)$b['extra_discount'] : '') ?>"
               placeholder="e.g. 200.00">
      </div>
      <div class="field">
        <label for="extra_discount_reason">Reason</label>
        <input type="text" id="extra_discount_reason" name="extra_discount_reason"
               maxlength="255"
               value="<?= e($b['extra_discount_reason'] ?? '') ?>"
               placeholder="e.g. Goodwill discount / short-paid by ₹200">
      </div>
      <div class="actions">
        <button type="submit" name="action" value="save_adjustment" class="btn btn-primary">
          <?= (float)$b['extra_discount'] > 0 ? 'Update' : 'Apply' ?>
        </button>
        <?php if ((float)$b['extra_discount'] > 0): ?>
          <button type="submit" name="action" value="clear_adjustment" class="btn btn-ghost"
                  onclick="return confirm('Remove the extra discount?');">Clear</button>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Admin actions</h2>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field">
        <label for="admin_note">Internal note (optional)</label>
        <textarea id="admin_note" name="admin_note" rows="3"><?= e($b['admin_note'] ?? '') ?></textarea>
      </div>

      <div class="actions">
        <?php if ($b['status'] !== 'confirmed'): ?>
          <button type="submit" name="action" value="confirm" class="btn btn-primary">✓ Confirm booking</button>
        <?php endif; ?>
        <?php if ($b['status'] !== 'cancelled'): ?>
          <button type="submit" name="action" value="cancel" class="btn btn-danger"
                  onclick="return confirm('Cancel this booking? Slot will be released.');">Cancel</button>
        <?php endif; ?>
        <?php if ($b['status'] !== 'pending'): ?>
          <button type="submit" name="action" value="reopen" class="btn btn-ghost">Move to pending</button>
        <?php endif; ?>
        <button type="submit" name="action" value="save_note" class="btn btn-secondary">Save note only</button>
      </div>
    </form>

    <?php
      // Build WhatsApp message from the configured templates (confirm / cancel / pending).
      $waMobile = preg_replace('/\D+/', '', $b['customer_mobile']);
      if (strlen($waMobile) === 10) $waMobile = '91' . $waMobile; // assume India if no country code

      $finalTotal  = max(0, (float)$b['slot_rate'] - (float)$b['discount_amount'] - (float)$b['extra_discount']);
      $pendingPaid = (float)($b['pending_amount'] ?? 0);
      $balance     = max(0, $finalTotal - (float)$b['advance_amount'] - $pendingPaid);

      // Slot duration line, e.g. "9:00 PM – 11:00 PM (2 Hours)"
      $startStr = date('g:i A', strtotime($b['start_time']));
      $endStr   = date('g:i A', strtotime($b['end_time']));
      $sStart   = strtotime($b['start_time']);
      $sEnd     = strtotime($b['end_time']);
      $secs     = (!empty($b['crosses_midnight']) || $sEnd <= $sStart)
                ? ((86400 - $sStart) + $sEnd)
                : ($sEnd - $sStart);
      $hours    = (int) round($secs / 3600);
      $slotLine = "{$startStr} – {$endStr} ({$hours} Hour" . ($hours === 1 ? '' : 's') . ")";

      $customerName = $b['customer_name'];
      $bookingCode  = $b['booking_code'];
      $dateLong     = date('l, j F Y', strtotime($b['booking_date'])); // "Friday, 22 May 2026"
      $slotPriceF   = '₹' . number_format((float)$b['slot_rate'], 0);
      $advanceF     = '₹' . number_format((float)$b['advance_amount'], 0);
      $balanceF     = '₹' . number_format($balance, 0);

      $venue        = $settings['venue_name'] ?: 'Games N Bites';
      $venueUC      = mb_strtoupper($venue, 'UTF-8');
      $contactPhone = $settings['contact_phone'] ?: '9898985677';
      $locationLink = 'https://share.google/7xwXhfLwtDJVauC1v';
      $website      = 'www.gamesnbites.com';

      if ($b['status'] === 'confirmed') {
          $waMessage = <<<TXT
✅ BOOKING CONFIRMED ✅
🏏 {$venueUC} 🏏

Hi {$customerName},

🎟️ Booking ID: {$bookingCode}
📅 Date: {$dateLong}
⏰ Slot: {$slotLine}

💰 Slot Price: {$slotPriceF}
✅ Advance Paid: {$advanceF}
💵 Balance Payable at Venue: {$balanceF} at the Time of Game Start

📍 Please arrive 5 minutes early for a smooth start.

━━━━━━━━━━━━━━━

📢 IMPORTANT RULES & TERMS

👥 Maximum 16 Players Allowed
🏏 Box Strictly for Cricket Only
🚭 No Smoking
🍺 No Drinking
⛔ No Food Allowed Inside the Box
🚫 No Entry for Non-Players
⏳ Slot Extension Available Only Subject to Availability
⏰ Fixed Slot Timing — No Extra 5–10 Minutes Allowed
🎯 Please Complete the Game Within Your Slot Time
🚗 Parking at Owner's Risk
⚠️ Management is Not Responsible for Any Injury, Theft, or Loss
🎲 No Gambling Allowed

🙏 All Teams Must Follow the Above Rules

━━━━━━━━━━━━━━━

📍 VENUE LOCATION
{$venue} Box Cricket
Nr Railway Bridge, Opp. Gappa Garden,
Nr. Nana Chiloda Ringroad Circle,
Ranasan, Ahmedabad

📍 Location Link:
{$locationLink}

📞 Contact: {$contactPhone}
🌐 {$website}

🔥 PLAY • ENJOY • REPEAT 🔥
TXT;
          $btnLabel  = 'Send confirmation on WhatsApp';
          $btnHint   = 'Sends the booking-confirmed template to ' . $b['customer_mobile'] . '.';
      } elseif ($b['status'] === 'cancelled') {
          $waMessage = <<<TXT
❌ BOOKING CANCELLED ❌
🏏 {$venueUC} 🏏

Dear {$customerName},

Your booking has been cancelled due to one of the following reasons:

• Advance Payment Not Received
• Cancellation Requested by Party
• Duplicate/Multiple Booking
• Slot Unavailable
• Violation of Booking Terms

If you wish to book again, please contact us with full advance confirmation.

📍 {$venue} Box Cricket
📞 {$contactPhone}
🌐 {$website}

🙏 Thank You for Your Support
🔥 PLAY • ENJOY • REPEAT 🔥
TXT;
          $btnLabel  = 'Send cancellation on WhatsApp';
          $btnHint   = 'Sends the booking-cancelled template to ' . $b['customer_mobile'] . '.';
      } else {
          // Pending — brief acknowledgement until you confirm or cancel
          $waMessage = "🏏 {$venueUC}\n\nHi {$customerName},\n\n"
                     . "We've received your booking {$bookingCode} for {$dateLong}, {$slotLine}.\n"
                     . "We'll confirm shortly after verifying your payment.\n\n"
                     . "📞 {$contactPhone}";
          $btnLabel  = 'Send received-update on WhatsApp';
          $btnHint   = 'Sends a short acknowledgement. Confirm or cancel the booking to send the full template.';
      }

      $waUrl = 'https://wa.me/' . $waMobile . '?text=' . rawurlencode($waMessage);
    ?>

    <div style="margin-top:16px;padding-top:16px;border-top:1px dashed var(--line)">
      <h3 style="margin-bottom:10px;font-size:14px;color:var(--ink-soft)">Notify customer</h3>
      <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener" class="btn btn-whatsapp btn-block">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" style="vertical-align:-3px;margin-right:6px" aria-hidden="true">
          <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.263.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893A11.821 11.821 0 0 0 20.464 3.488"/>
        </svg>
        <?= e($btnLabel) ?>
      </a>
      <p class="muted small" style="margin:8px 0 0"><?= e($btnHint) ?></p>
      <details style="margin-top:10px">
        <summary class="muted small" style="cursor:pointer">Preview / copy message</summary>
        <textarea readonly rows="10"
          style="width:100%;margin-top:8px;padding:8px;border:1px solid var(--line);border-radius:6px;font:13px/1.45 ui-monospace,Menlo,monospace;background:#fafbfc"><?= e($waMessage) ?></textarea>
      </details>
    </div>
  </div>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
