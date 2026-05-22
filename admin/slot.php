<?php
require __DIR__ . '/_auth.php';
$pageTitle = 'Edit slot';

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM slots WHERE id = ?");
$stmt->execute([$id]);
$slot = $stmt->fetch();

if (!$slot) {
    flash_set('error', 'Slot not found.');
    redirect('slots.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $isActive  = isset($_POST['is_active']) ? 1 : 0;

    $enabled = [];
    $rates = [];
    $coupon = [];
    $anyEnabled = false;
    foreach (['mon','tue','wed','thu','fri','sat','sun'] as $d) {
        $enabled[$d] = isset($_POST[$d . '_enabled']) ? 1 : 0;
        $rates[$d]   = max(0, (float)($_POST[$d . '_rate'] ?? 0));
        $coupon[$d]  = isset($_POST[$d . '_coupon']) ? 1 : 0;
        if ($enabled[$d]) $anyEnabled = true;
    }

    $errors = [];
    if (!$anyEnabled) {
        $errors[] = 'At least one day must be enabled. Otherwise this slot won\'t appear anywhere.';
    }
    foreach ($enabled as $d => $on) {
        if ($on && $rates[$d] <= 0) {
            $errors[] = strtoupper($d) . ': enabled days must have a rate greater than zero.';
        }
    }

    if ($errors) {
        flash_set('error', implode(' ', $errors));
        redirect('slot.php?id=' . $id);
    }

    // Update — also keep legacy `rate` synced to the max for any downstream code that still reads it
    $maxRate = max($rates);
    $stmt = $pdo->prepare("UPDATE slots SET
        sort_order = ?,
        is_active  = ?,
        rate       = ?,
        mon_enabled = ?, tue_enabled = ?, wed_enabled = ?, thu_enabled = ?,
        fri_enabled = ?, sat_enabled = ?, sun_enabled = ?,
        mon_rate = ?, tue_rate = ?, wed_rate = ?, thu_rate = ?,
        fri_rate = ?, sat_rate = ?, sun_rate = ?,
        mon_coupon = ?, tue_coupon = ?, wed_coupon = ?, thu_coupon = ?,
        fri_coupon = ?, sat_coupon = ?, sun_coupon = ?
        WHERE id = ?");
    $stmt->execute([
        $sortOrder, $isActive, $maxRate,
        $enabled['mon'], $enabled['tue'], $enabled['wed'], $enabled['thu'],
        $enabled['fri'], $enabled['sat'], $enabled['sun'],
        $rates['mon'], $rates['tue'], $rates['wed'], $rates['thu'],
        $rates['fri'], $rates['sat'], $rates['sun'],
        $coupon['mon'], $coupon['tue'], $coupon['wed'], $coupon['thu'],
        $coupon['fri'], $coupon['sat'], $coupon['sun'],
        $id,
    ]);

    flash_set('success', 'Slot updated.');
    redirect('slot.php?id=' . $id);
}

require __DIR__ . '/_layout_top.php';

$days = [
    'mon' => 'Monday',
    'tue' => 'Tuesday',
    'wed' => 'Wednesday',
    'thu' => 'Thursday',
    'fri' => 'Friday',
    'sat' => 'Saturday',
    'sun' => 'Sunday',
];
?>

<div class="admin-header">
  <h1>
    <a href="slots.php" style="color:var(--muted);text-decoration:none">←</a>
    Edit slot · <?= e(fmt_time($slot['start_time'])) ?> – <?= e(fmt_time($slot['end_time'])) ?>
  </h1>
</div>

<form method="post">
  <?= csrf_field() ?>

  <div class="card">
    <h2>Slot info</h2>
    <p class="muted small">Time can't be changed once a slot exists. To change times, delete this slot (if no bookings) and create a new one.</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px">
      <div class="field">
        <label>Start time</label>
        <input type="text" value="<?= e(fmt_time($slot['start_time'])) ?>" disabled
               style="background:#f4f6f8;color:var(--muted)">
      </div>
      <div class="field">
        <label>End time</label>
        <input type="text" value="<?= e(fmt_time($slot['end_time'])) ?>" disabled
               style="background:#f4f6f8;color:var(--muted)">
      </div>
      <div class="field">
        <label>Sort order</label>
        <input type="number" name="sort_order" value="<?= (int)$slot['sort_order'] ?>">
      </div>
      <div class="field">
        <label style="display:flex;align-items:center;gap:8px;margin-top:24px">
          <input type="checkbox" name="is_active" <?= $slot['is_active'] ? 'checked' : '' ?>>
          Slot is active
        </label>
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Days & rates</h2>
    <p class="muted small">Tick the days this slot is available. Set the rate for each enabled day.</p>

    <div style="margin-bottom:16px;padding:12px;background:var(--turf-light);border-radius:8px;display:flex;flex-wrap:wrap;gap:8px;align-items:center">
      <span style="font-weight:600;color:var(--turf)">Bulk set rate:</span>
      <input type="number" id="bulk_rate" min="0" step="50" placeholder="₹ amount"
             style="width:120px;padding:6px 10px;border:1px solid var(--line);border-radius:6px">
      <button type="button" onclick="bulkSet('all')"     class="btn btn-secondary btn-sm">All 7 days</button>
      <button type="button" onclick="bulkSet('weekday')" class="btn btn-secondary btn-sm">Mon–Fri</button>
      <button type="button" onclick="bulkSet('weekend')" class="btn btn-secondary btn-sm">Sat–Sun</button>
    </div>

    <div class="day-grid">
      <?php foreach ($days as $key => $label): ?>
        <div class="day-row">
          <label class="day-toggle">
            <input type="checkbox" name="<?= $key ?>_enabled"
                   <?= !empty($slot[$key.'_enabled']) ? 'checked' : '' ?>>
            <span><?= e($label) ?></span>
          </label>
          <div class="day-rate">
            <span class="muted small">₹</span>
            <input type="number" name="<?= $key ?>_rate"
                   class="rate-input rate-<?= $key ?>"
                   min="0" step="50"
                   value="<?= e(number_format((float)$slot[$key.'_rate'], 2, '.', '')) ?>">
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <h2>Coupons</h2>
    <p class="muted small">Tick the weekdays on which customers may apply a coupon to this slot. Unticked = no coupons accepted for this slot that day.</p>
    <div class="day-grid">
      <?php foreach ($days as $key => $label): ?>
        <label class="day-row" style="cursor:pointer">
          <input type="checkbox" name="<?= $key ?>_coupon"
                 style="width:18px;height:18px;accent-color:var(--turf)"
                 <?= !array_key_exists($key.'_coupon', $slot) || !empty($slot[$key.'_coupon']) ? 'checked' : '' ?>>
          <span style="font-weight:600"><?= e($label) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <button type="submit" class="btn btn-primary btn-block">Save changes</button>
    <a href="slots.php" class="btn btn-ghost btn-block" style="margin-top:8px">Cancel</a>
  </div>
</form>

<script>
function bulkSet(scope) {
  var v = document.getElementById('bulk_rate').value;
  if (!v) { alert('Enter a rate first'); return; }
  var keys;
  if (scope === 'all')     keys = ['mon','tue','wed','thu','fri','sat','sun'];
  if (scope === 'weekday') keys = ['mon','tue','wed','thu','fri'];
  if (scope === 'weekend') keys = ['sat','sun'];
  keys.forEach(function(k) {
    var el = document.querySelector('.rate-' + k);
    if (el) el.value = v;
  });
}
</script>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
