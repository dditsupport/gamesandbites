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

    if ($action === 'import') {
        $mode = $_POST['import_mode'] ?? 'append';
        if (!in_array($mode, ['append','replace'], true)) $mode = 'append';

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

        // Header row required — must match the Export CSV format exactly.
        $first = fgetcsv($fh);
        if (!$first) {
            fclose($fh);
            flash_set('error', 'CSV is empty.');
            redirect('slots.php');
        }
        $headerMap = [];
        foreach ($first as $i => $cell) $headerMap[strtolower(trim((string)$cell))] = $i;

        $required = [
            'start_time','end_time','sort_order','is_active',
            'mon_rate','tue_rate','wed_rate','thu_rate','fri_rate','sat_rate','sun_rate',
            'mon_enabled','tue_enabled','wed_enabled','thu_enabled','fri_enabled','sat_enabled','sun_enabled',
        ];
        $missing = array_values(array_filter($required, fn($c) => !isset($headerMap[$c])));
        if ($missing) {
            fclose($fh);
            flash_set('error', 'CSV header is missing required columns: ' . implode(', ', $missing)
                . '. Tip: click Export CSV, edit that file, then re-upload it.');
            redirect('slots.php');
        }

        $rows = []; $errors = []; $lineNo = 1;
        while (($r = fgetcsv($fh)) !== false) {
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

            $rates = []; $enabled = [];
            foreach (['mon','tue','wed','thu','fri','sat','sun'] as $d) {
                $rates[$d]   = (float)$get($d.'_rate');
                $enabled[$d] = (int)!!$get($d.'_enabled');
            }

            $maxRate = max($rates);
            if ($maxRate <= 0) { $errors[] = "Line $lineNo: all day rates are zero."; continue; }

            $crosses = (strtotime($end) <= strtotime($start)) ? 1 : 0;
            $rows[] = [
                'start' => $start, 'end' => $end, 'crosses' => $crosses,
                'rate' => $maxRate, 'sort' => $sort, 'active' => $active,
                'enabled' => $enabled, 'rates' => $rates,
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

        try {
            $pdo->beginTransaction();
            if ($mode === 'replace') $pdo->exec("DELETE FROM slots");

            $ins = $pdo->prepare("INSERT INTO slots
                (start_time, end_time, crosses_midnight, rate, sort_order, is_active,
                 mon_enabled, tue_enabled, wed_enabled, thu_enabled, fri_enabled, sat_enabled, sun_enabled,
                 mon_rate, tue_rate, wed_rate, thu_rate, fri_rate, sat_rate, sun_rate)
                VALUES (?,?,?,?,?,?, ?,?,?,?,?,?,?, ?,?,?,?,?,?,?)");

            foreach ($rows as $r) {
                $ins->execute([
                    $r['start'], $r['end'], $r['crosses'], $r['rate'], $r['sort'], $r['active'],
                    $r['enabled']['mon'], $r['enabled']['tue'], $r['enabled']['wed'],
                    $r['enabled']['thu'], $r['enabled']['fri'], $r['enabled']['sat'], $r['enabled']['sun'],
                    $r['rates']['mon'], $r['rates']['tue'], $r['rates']['wed'],
                    $r['rates']['thu'], $r['rates']['fri'], $r['rates']['sat'], $r['rates']['sun'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Import failed: ' . $e->getMessage());
            redirect('slots.php');
        }

        $modeLabel = $mode === 'replace' ? 'replaced all slots with' : 'added';
        flash_set('success', "Imported successfully — $modeLabel " . count($rows) . " slot(s).");
        redirect('slots.php');
    }
}

$slots = $pdo->query("SELECT * FROM slots ORDER BY sort_order, start_time")->fetchAll();

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
    Use <strong>Replace</strong> to overwrite all slots (blocked if any booking references an existing slot)
    or <strong>Append</strong> to add new rows.
  </p>
  <p class="muted small">
    Import accepts the same format Export produces. The header row is required and must contain:
    <code>start_time, end_time, sort_order, is_active, mon_rate … sun_rate, mon_enabled … sun_enabled</code>.
  </p>
  <details style="margin-bottom:12px">
    <summary style="cursor:pointer;color:var(--turf);font-weight:600">Example CSV</summary>
    <pre style="background:#f4f6f8;padding:12px;border-radius:8px;font-size:12px;overflow:auto;margin-top:8px">start_time,end_time,sort_order,is_active,mon_rate,tue_rate,wed_rate,thu_rate,fri_rate,sat_rate,sun_rate,mon_enabled,tue_enabled,wed_enabled,thu_enabled,fri_enabled,sat_enabled,sun_enabled
06:00,07:00,1,1,800,800,800,800,800,1000,1000,1,1,1,1,1,1,1
21:00,23:00,16,1,1000,1000,1000,1000,1200,1500,1500,1,1,1,1,1,1,1</pre>
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
      foreach (['mon','tue','wed','thu','fri','sat','sun'] as $d) {
        if (!empty($s[$d.'_enabled'])) {
          $rates[] = (float)$s[$d.'_rate'];
          $enabledDays[] = ucfirst($d);
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
