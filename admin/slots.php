<?php
require __DIR__ . '/_auth.php';

// -------- CSV EXPORT (must come before any output) --------
if (($_GET['action'] ?? '') === 'export') {
    $stmt = $pdo->query("SELECT * FROM slots ORDER BY sort_order, start_time");
    $filename = 'gnb-slots-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'start_time','end_time','sort_order','is_active',
        'mon_rate','tue_rate','wed_rate','thu_rate','fri_rate','sat_rate','sun_rate',
        'mon_enabled','tue_enabled','wed_enabled','thu_enabled','fri_enabled','sat_enabled','sun_enabled',
        'mon_coupon','tue_coupon','wed_coupon','thu_coupon','fri_coupon','sat_coupon','sun_coupon',
    ]);
    while ($r = $stmt->fetch()) {
        fputcsv($out, [
            substr($r['start_time'], 0, 5),
            substr($r['end_time'], 0, 5),
            $r['sort_order'],
            $r['is_active'],
            $r['mon_rate'], $r['tue_rate'], $r['wed_rate'], $r['thu_rate'],
            $r['fri_rate'], $r['sat_rate'], $r['sun_rate'],
            $r['mon_enabled'], $r['tue_enabled'], $r['wed_enabled'], $r['thu_enabled'],
            $r['fri_enabled'], $r['sat_enabled'], $r['sun_enabled'],
            $r['mon_coupon'] ?? 1, $r['tue_coupon'] ?? 1, $r['wed_coupon'] ?? 1, $r['thu_coupon'] ?? 1,
            $r['fri_coupon'] ?? 1, $r['sat_coupon'] ?? 1, $r['sun_coupon'] ?? 1,
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Slots & Rates';

function normalize_time_input(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m)) return null;
    $h = (int)$m[1]; $mn = (int)$m[2]; $s = isset($m[3]) ? (int)$m[3] : 0;
    if ($h > 23 || $mn > 59 || $s > 59) return null;
    return sprintf('%02d:%02d:%02d', $h, $mn, $s);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $start = normalize_time_input($_POST['start_time'] ?? '');
        $end   = normalize_time_input($_POST['end_time'] ?? '');
        $rate  = (float)($_POST['rate'] ?? 0);
        $sortOrder = (int)($_POST['sort_order'] ?? 0);

        if (!$start || !$end) {
            flash_set('error', 'Enter valid start and end times.');
        } elseif ($rate <= 0) {
            flash_set('error', 'Rate must be greater than zero.');
        } else {
            $crosses = (strtotime($end) <= strtotime($start)) ? 1 : 0;
            $stmt = $pdo->prepare("INSERT INTO slots
                (start_time, end_time, crosses_midnight, rate, sort_order,
                 mon_enabled, tue_enabled, wed_enabled, thu_enabled, fri_enabled, sat_enabled, sun_enabled,
                 mon_rate, tue_rate, wed_rate, thu_rate, fri_rate, sat_rate, sun_rate)
                VALUES (?,?,?,?,?, 1,1,1,1,1,1,1, ?,?,?,?,?,?,?)");
            $stmt->execute([
                $start, $end, $crosses, $rate, $sortOrder,
                $rate, $rate, $rate, $rate, $rate, $rate, $rate,
            ]);
            flash_set('success', 'Slot added with the same rate for all 7 days. Click Edit to set different rates per day.');
        }
        redirect('slots.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $count = (int)$pdo->query("SELECT COUNT(*) FROM bookings WHERE slot_id = $id")->fetchColumn();
        if ($count > 0) {
            flash_set('error', "Cannot delete — slot has $count booking(s). Disable it instead.");
        } else {
            $stmt = $pdo->prepare("DELETE FROM slots WHERE id = ?");
            $stmt->execute([$id]);
            flash_set('success', 'Slot deleted.');
        }
        redirect('slots.php');
    }

    if ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE slots SET is_active = 1 - is_active WHERE id = ?");
        $stmt->execute([$id]);
        redirect('slots.php');
    }

    if ($action === 'block_date') {
        $bd = trim($_POST['block_date'] ?? '');
        $reason = trim($_POST['reason'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd) || strtotime($bd) === false) {
            flash_set('error', 'Pick a valid date to block.');
        } elseif ($bd < date('Y-m-d')) {
            flash_set('error', 'You can only block today or a future date.');
        } else {
            $stmt = $pdo->prepare("INSERT INTO blocked_dates (block_date, reason) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE reason = VALUES(reason)");
            $stmt->execute([$bd, $reason !== '' ? mb_substr($reason, 0, 255) : null]);
            flash_set('success', 'Bookings disabled for ' . date('D, M j, Y', strtotime($bd)) . '.');
        }
        redirect('slots.php');
    }

    if ($action === 'unblock_date') {
        $bd = trim($_POST['block_date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $bd)) {
            $stmt = $pdo->prepare("DELETE FROM blocked_dates WHERE block_date = ?");
            $stmt->execute([$bd]);
            flash_set('success', 'Bookings re-enabled for ' . date('D, M j, Y', strtotime($bd)) . '.');
        }
        redirect('slots.php');
    }

    if ($action === 'import') {
        $mode = $_POST['import_mode'] ?? 'append';
        if (!in_array($mode, ['append','replace','update'], true)) $mode = 'append';

        if (empty($_FILES['csv_file']['tmp_name']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            flash_set('error', 'Please choose a CSV file to upload.');
            redirect('slots.php');
        }
        if ($_FILES['csv_file']['size'] > 1 * 1024 * 1024) {
            flash_set('error', 'CSV file must be under 1 MB.');
            redirect('slots.php');
        }

        $fh = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$fh) {
            flash_set('error', 'Could not open uploaded file.');
            redirect('slots.php');
        }

        // Header row required — must match the Export CSV format.
        // Detect the delimiter (Excel may save comma- OR semicolon-separated)
        // and strip a UTF-8 BOM if present, so Excel-edited files import cleanly.
        $firstLine = fgets($fh);
        if ($firstLine === false || trim($firstLine) === '') {
            fclose($fh);
            flash_set('error', 'CSV is empty.');
            redirect('slots.php');
        }
        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine); // strip BOM
        $firstLine = rtrim($firstLine, "\r\n");
        $counts = [
            ','  => substr_count($firstLine, ','),
            ';'  => substr_count($firstLine, ';'),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($counts);
        $delim = array_key_first($counts);
        if ($counts[$delim] === 0) $delim = ',';

        $first = str_getcsv($firstLine, $delim);
        $headerMap = [];
        foreach ($first as $i => $cell) {
            $key = strtolower(trim((string)$cell, " \t\n\r\0\x0B\"\xEF\xBB\xBF"));
            $headerMap[$key] = $i;
        }

        $required = [
            'start_time','end_time','sort_order','is_active',
            'mon_rate','tue_rate','wed_rate','thu_rate','fri_rate','sat_rate','sun_rate',
            'mon_enabled','tue_enabled','wed_enabled','thu_enabled','fri_enabled','sat_enabled','sun_enabled',
        ];
        $missing = array_values(array_filter($required, fn($c) => !isset($headerMap[$c])));
        if ($missing) {
            fclose($fh);
            flash_set('error', 'CSV header is missing required columns: ' . implode(', ', $missing)
                . '. Columns found: ' . (implode(', ', array_keys($headerMap)) ?: '(none)')
                . '. Tip: click Export CSV, edit that file (keep the header row), then re-upload it.');
            redirect('slots.php');
        }

        $rows = []; $errors = []; $lineNo = 1;
        while (($r = fgetcsv($fh, 0, $delim)) !== false) {
            $lineNo++;
            if (count(array_filter($r, fn($c) => trim((string)$c) !== '')) === 0) continue;

            $get = fn(string $name) => $r[$headerMap[$name]] ?? '';

            $start = normalize_time_input((string)$get('start_time'));
            $end   = normalize_time_input((string)$get('end_time'));
            if (!$start) { $errors[] = "Line $lineNo: invalid start_time."; continue; }
            if (!$end)   { $errors[] = "Line $lineNo: invalid end_time."; continue; }
            if ($start === $end) { $errors[] = "Line $lineNo: start_time and end_time can't be the same."; continue; }

            $sort   = (int)$get('sort_order');
            $active = (int)!!$get('is_active');

            $rates = []; $enabled = []; $coupon = [];
            foreach (['mon','tue','wed','thu','fri','sat','sun'] as $d) {
                $rates[$d]   = (float)$get($d.'_rate');
                $enabled[$d] = (int)!!$get($d.'_enabled');
                // Coupon columns are optional — default to allowed (1) if not present.
                $coupon[$d]  = isset($headerMap[$d.'_coupon']) ? (int)!!$get($d.'_coupon') : 1;
            }

            $maxRate = max($rates);
            if ($maxRate <= 0) { $errors[] = "Line $lineNo: all day rates are zero."; continue; }

            $crosses = (strtotime($end) <= strtotime($start)) ? 1 : 0;
            $rows[] = [
                'start' => $start, 'end' => $end, 'crosses' => $crosses,
                'rate' => $maxRate, 'sort' => $sort, 'active' => $active,
                'enabled' => $enabled, 'rates' => $rates, 'coupon' => $coupon,
            ];
        }
        fclose($fh);

        if ($errors) {
            flash_set('error', 'CSV had ' . count($errors) . ' error(s): ' . implode(' | ', array_slice($errors, 0, 5))
                . (count($errors) > 5 ? ' …' : '') . ' Nothing was imported.');
            redirect('slots.php');
        }
        if (!$rows) {
            flash_set('error', 'No valid rows found in the CSV.');
            redirect('slots.php');
        }

        if ($mode === 'replace') {
            $usedCount = (int)$pdo->query("SELECT COUNT(DISTINCT slot_id) FROM bookings")->fetchColumn();
            if ($usedCount > 0) {
                flash_set('error', "Replace blocked: $usedCount existing slot(s) have bookings.");
                redirect('slots.php');
            }
        }

        $updatedCount = 0; $insertedCount = 0;
        try {
            $pdo->beginTransaction();
            if ($mode === 'replace') $pdo->exec("DELETE FROM slots");

            $ins = $pdo->prepare("INSERT INTO slots
                (start_time, end_time, crosses_midnight, rate, sort_order, is_active,
                 mon_enabled, tue_enabled, wed_enabled, thu_enabled, fri_enabled, sat_enabled, sun_enabled,
                 mon_rate, tue_rate, wed_rate, thu_rate, fri_rate, sat_rate, sun_rate,
                 mon_coupon, tue_coupon, wed_coupon, thu_coupon, fri_coupon, sat_coupon, sun_coupon)
                VALUES (?,?,?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?,?,?,?)");

            // For 'update' mode: match an existing slot by its start+end time and
            // update it in place (no deletes — safe even when bookings reference it).
            $find = $pdo->prepare("SELECT id FROM slots WHERE start_time = ? AND end_time = ? LIMIT 1");
            $upd  = $pdo->prepare("UPDATE slots SET
                crosses_midnight=?, rate=?, sort_order=?, is_active=?,
                mon_enabled=?, tue_enabled=?, wed_enabled=?, thu_enabled=?, fri_enabled=?, sat_enabled=?, sun_enabled=?,
                mon_rate=?, tue_rate=?, wed_rate=?, thu_rate=?, fri_rate=?, sat_rate=?, sun_rate=?,
                mon_coupon=?, tue_coupon=?, wed_coupon=?, thu_coupon=?, fri_coupon=?, sat_coupon=?, sun_coupon=?
                WHERE id = ?");

            foreach ($rows as $r) {
                $existingId = null;
                if ($mode === 'update') {
                    $find->execute([$r['start'], $r['end']]);
                    $existingId = $find->fetchColumn() ?: null;
                }

                if ($existingId) {
                    $upd->execute([
                        $r['crosses'], $r['rate'], $r['sort'], $r['active'],
                        $r['enabled']['mon'], $r['enabled']['tue'], $r['enabled']['wed'],
                        $r['enabled']['thu'], $r['enabled']['fri'], $r['enabled']['sat'], $r['enabled']['sun'],
                        $r['rates']['mon'], $r['rates']['tue'], $r['rates']['wed'],
                        $r['rates']['thu'], $r['rates']['fri'], $r['rates']['sat'], $r['rates']['sun'],
                        $r['coupon']['mon'], $r['coupon']['tue'], $r['coupon']['wed'],
                        $r['coupon']['thu'], $r['coupon']['fri'], $r['coupon']['sat'], $r['coupon']['sun'],
                        $existingId,
                    ]);
                    $updatedCount++;
                } else {
                    $ins->execute([
                        $r['start'], $r['end'], $r['crosses'], $r['rate'], $r['sort'], $r['active'],
                        $r['enabled']['mon'], $r['enabled']['tue'], $r['enabled']['wed'],
                        $r['enabled']['thu'], $r['enabled']['fri'], $r['enabled']['sat'], $r['enabled']['sun'],
                        $r['rates']['mon'], $r['rates']['tue'], $r['rates']['wed'],
                        $r['rates']['thu'], $r['rates']['fri'], $r['rates']['sat'], $r['rates']['sun'],
                        $r['coupon']['mon'], $r['coupon']['tue'], $r['coupon']['wed'],
                        $r['coupon']['thu'], $r['coupon']['fri'], $r['coupon']['sat'], $r['coupon']['sun'],
                    ]);
                    $insertedCount++;
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Import failed: ' . $e->getMessage());
            redirect('slots.php');
        }

        if ($mode === 'update') {
            flash_set('success', "Imported — updated $updatedCount existing slot(s)"
                . ($insertedCount ? " and added $insertedCount new slot(s)." : "."));
        } elseif ($mode === 'replace') {
            flash_set('success', "Imported successfully — replaced all slots with $insertedCount slot(s).");
        } else {
            flash_set('success', "Imported successfully — added $insertedCount slot(s).");
        }
        redirect('slots.php');
    }
}

$slots = $pdo->query("SELECT * FROM slots ORDER BY sort_order, start_time")->fetchAll();

// Upcoming blocked dates (today onward)
$blockedDates = $pdo->query("SELECT block_date, reason FROM blocked_dates
    WHERE block_date >= CURDATE() ORDER BY block_date")->fetchAll();

require __DIR__ . '/_layout_top.php';
?>

<div class="admin-header">
  <h1>Slots & Rates</h1>
  <div class="actions">
    <a href="slots.php?action=export" class="btn btn-secondary btn-sm">↓ Export CSV</a>
  </div>
</div>

<div class="card">
  <h2>Add new slot</h2>
  <p class="muted small">Creates a slot with all 7 days enabled at the same rate. After adding, click Edit on the row to set different rates per weekday or disable specific days.</p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;align-items:end">
      <div class="field" style="margin:0">
        <label for="start_time">Start time</label>
        <input type="time" id="start_time" name="start_time" required>
      </div>
      <div class="field" style="margin:0">
        <label for="end_time">End time</label>
        <input type="time" id="end_time" name="end_time" required>
      </div>
      <div class="field" style="margin:0">
        <label for="rate">Rate (₹) — all days</label>
        <input type="number" id="rate" name="rate" required min="0" step="50" value="1000">
      </div>
      <div class="field" style="margin:0">
        <label for="sort_order">Sort order</label>
        <input type="number" id="sort_order" name="sort_order" value="0">
      </div>
      <div>
        <button class="btn btn-primary btn-block">+ Add slot</button>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <h2>Import slots from CSV</h2>
  <p class="muted small">
    💡 Workflow: <a href="slots.php?action=export">Export CSV</a> → edit in Excel/Google Sheets → re-upload here.
    Use <strong>Update existing</strong> to change rates/days/coupons on slots you already have — it matches each
    row to a slot by its start &amp; end time, updates it in place, and adds any new ones (works even when slots have
    bookings). <strong>Append</strong> only adds new rows. <strong>Replace</strong> wipes all slots first (blocked
    if any booking references an existing slot).
  </p>
  <p class="muted small">
    Import accepts the same format Export produces. The header row is required and must contain:
    <code>start_time, end_time, sort_order, is_active, mon_rate … sun_rate, mon_enabled … sun_enabled</code>.
    The <code>mon_coupon … sun_coupon</code> columns (1 = coupons allowed that weekday, 0 = not) are
    <strong>optional</strong> — if omitted, coupons stay allowed on every day.
  </p>
  <details style="margin-bottom:12px">
    <summary style="cursor:pointer;color:var(--turf);font-weight:600">Example CSV</summary>
    <pre style="background:#f4f6f8;padding:12px;border-radius:8px;font-size:12px;overflow:auto;margin-top:8px">start_time,end_time,sort_order,is_active,mon_rate,tue_rate,wed_rate,thu_rate,fri_rate,sat_rate,sun_rate,mon_enabled,tue_enabled,wed_enabled,thu_enabled,fri_enabled,sat_enabled,sun_enabled,mon_coupon,tue_coupon,wed_coupon,thu_coupon,fri_coupon,sat_coupon,sun_coupon
06:00,07:00,1,1,800,800,800,800,800,1000,1000,1,1,1,1,1,1,1,1,1,1,1,1,0,0
21:00,23:00,16,1,1000,1000,1000,1000,1200,1500,1500,1,1,1,1,1,1,1,1,1,1,1,1,1,1</pre>
  </details>
  <form method="post" enctype="multipart/form-data"
        onsubmit="var m=this.import_mode.value; if(m==='replace'){return confirm('Replace ALL existing slots? This cannot be undone.');} return true;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="import">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;align-items:end">
      <div class="field" style="margin:0">
        <label for="csv_file">CSV file</label>
        <input type="file" id="csv_file" name="csv_file" accept=".csv,text/csv" required>
      </div>
      <div class="field" style="margin:0">
        <label for="import_mode">Mode</label>
        <select id="import_mode" name="import_mode">
          <option value="update">Update existing (match by time — safe with bookings)</option>
          <option value="append">Append (add to existing)</option>
          <option value="replace">Replace (delete all, then import)</option>
        </select>
      </div>
      <div>
        <button class="btn btn-primary btn-block">↑ Import CSV</button>
      </div>
    </div>
  </form>
</div>

<div class="card">
  <h2>Block dates (close bookings)</h2>
  <p class="muted small">
    Disable <strong>all</strong> bookings on a specific date — holidays, private events, maintenance.
    Customers see the date as closed and can't book any slot.
  </p>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="block_date">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;align-items:end">
      <div class="field" style="margin:0">
        <label for="block_date">Date to close</label>
        <input type="date" id="block_date" name="block_date" required min="<?= date('Y-m-d') ?>">
      </div>
      <div class="field" style="margin:0">
        <label for="reason">Reason <small class="muted">(optional, shown to customers)</small></label>
        <input type="text" id="reason" name="reason" maxlength="255" placeholder="e.g. Diwali holiday">
      </div>
      <div>
        <button class="btn btn-primary btn-block">Close this date</button>
      </div>
    </div>
  </form>

  <?php if ($blockedDates): ?>
    <table class="data" style="margin-top:16px">
      <thead>
        <tr><th>Closed date</th><th>Reason</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($blockedDates as $bd): ?>
        <tr>
          <td><strong><?= e(date('D, M j, Y', strtotime($bd['block_date']))) ?></strong></td>
          <td><?= $bd['reason'] !== null && $bd['reason'] !== '' ? e($bd['reason']) : '<span class="muted">—</span>' ?></td>
          <td>
            <form method="post" style="display:inline" onsubmit="return confirm('Re-enable bookings for this date?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="unblock_date">
              <input type="hidden" name="block_date" value="<?= e($bd['block_date']) ?>">
              <button class="btn btn-ghost btn-sm">Re-open</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="muted small" style="margin-top:12px">No upcoming dates are blocked.</p>
  <?php endif; ?>
</div>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th>Time</th>
        <th>Days available</th>
        <th>Rate range</th>
        <th>Active</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$slots): ?>
      <tr><td colspan="5" style="text-align:center;padding:32px;color:var(--muted)">
        No slots configured. Add your first slot above.
      </td></tr>
    <?php endif; ?>
    <?php foreach ($slots as $s):
      $rates = [];
      $enabledDays = [];
      $noCouponDays = [];
      foreach (['mon','tue','wed','thu','fri','sat','sun'] as $d) {
        if (!empty($s[$d.'_enabled'])) {
          $rates[] = (float)$s[$d.'_rate'];
          $enabledDays[] = ucfirst($d);
          if (array_key_exists($d.'_coupon', $s) && empty($s[$d.'_coupon'])) {
            $noCouponDays[] = ucfirst($d);
          }
        }
      }
      $rateMin = $rates ? min($rates) : 0;
      $rateMax = $rates ? max($rates) : 0;
    ?>
      <tr>
        <td>
          <strong><?= e(fmt_time($s['start_time'])) ?> – <?= e(fmt_time($s['end_time'])) ?></strong>
          <?php if ($s['crosses_midnight']): ?><br><small class="muted">crosses midnight</small><?php endif; ?>
        </td>
        <td>
          <?php if ($enabledDays && count($enabledDays) === 7): ?>
            <span class="muted small">All week</span>
          <?php elseif (!$enabledDays): ?>
            <span style="color:var(--danger)">No days enabled</span>
          <?php else: ?>
            <span class="small"><?= e(implode(', ', $enabledDays)) ?></span>
          <?php endif; ?>
          <?php if ($noCouponDays): ?>
            <br><small style="color:var(--danger)">No coupons: <?= e(implode(', ', $noCouponDays)) ?></small>
          <?php endif; ?>
        </td>
        <td>
          <?php if (!$rates): ?>
            <span class="muted">—</span>
          <?php elseif ($rateMin === $rateMax): ?>
            <strong><?= inr($rateMin) ?></strong>
          <?php else: ?>
            <strong><?= inr($rateMin) ?>–<?= inr($rateMax) ?></strong>
          <?php endif; ?>
        </td>
        <td>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="toggle_active">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button type="submit" class="pill pill-<?= $s['is_active'] ? 'active' : 'inactive' ?>"
                    style="border:none;cursor:pointer;font:inherit;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em">
              <?= $s['is_active'] ? 'on' : 'off' ?>
            </button>
          </form>
        </td>
        <td>
          <div class="actions">
            <a href="slot.php?id=<?= (int)$s['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
            <form method="post" style="display:inline" onsubmit="return confirm('Delete this slot? Only allowed if no bookings exist.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
              <button class="btn btn-ghost btn-sm">Delete</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
