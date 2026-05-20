<?php
require __DIR__ . '/_auth.php';
$pageTitle = 'Coupons';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $code = preg_replace('/[^A-Z0-9]/', '', $code ?? '');
        $discount = (float)($_POST['discount_amount'] ?? 0);
        $limit = trim($_POST['usage_limit'] ?? '');
        $limit = ($limit === '' ? null : max(1, (int)$limit));
        $expires = trim($_POST['expires_on'] ?? '');
        $expires = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) ? $expires : null;

        if ($code === '' || strlen($code) < 3) {
            flash_set('error', 'Coupon code must be at least 3 characters (letters/numbers only).');
        } elseif ($discount <= 0) {
            flash_set('error', 'Discount amount must be greater than zero.');
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO coupons (code, discount_amount, usage_limit, expires_on, is_active)
                    VALUES (?, ?, ?, ?, 1)");
                $stmt->execute([$code, $discount, $limit, $expires]);
                flash_set('success', "Coupon $code created.");
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    flash_set('error', "A coupon with code $code already exists.");
                } else {
                    flash_set('error', 'Could not create coupon.');
                }
            }
        }
        redirect('coupons.php');
    }

    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $discount = (float)($_POST['discount_amount'] ?? 0);
        $limit = trim($_POST['usage_limit'] ?? '');
        $limit = ($limit === '' ? null : max(1, (int)$limit));
        $expires = trim($_POST['expires_on'] ?? '');
        $expires = preg_match('/^\d{4}-\d{2}-\d{2}$/', $expires) ? $expires : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($id && $discount > 0) {
            $stmt = $pdo->prepare("UPDATE coupons SET discount_amount=?, usage_limit=?, expires_on=?, is_active=? WHERE id=?");
            $stmt->execute([$discount, $limit, $expires, $isActive, $id]);
            flash_set('success', 'Coupon updated.');
        }
        redirect('coupons.php');
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $used = (int)$pdo->query("SELECT used_count FROM coupons WHERE id = $id")->fetchColumn();
        if ($used > 0) {
            flash_set('error', "Cannot delete — coupon has been used $used time(s). Disable it instead.");
        } else {
            $stmt = $pdo->prepare("DELETE FROM coupons WHERE id = ?");
            $stmt->execute([$id]);
            flash_set('success', 'Coupon deleted.');
        }
        redirect('coupons.php');
    }
}

$coupons = $pdo->query("SELECT * FROM coupons ORDER BY is_active DESC, created_at DESC")->fetchAll();

require __DIR__ . '/_layout_top.php';
?>

<div class="admin-header">
  <h1>Coupons</h1>
</div>

<div class="card">
  <h2>Create new coupon</h2>
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;align-items:end">
      <div class="field" style="margin:0">
        <label>Code</label>
        <input type="text" name="code" required maxlength="30" placeholder="GNB100"
               style="text-transform:uppercase">
      </div>
      <div class="field" style="margin:0">
        <label>Flat discount (₹)</label>
        <input type="number" name="discount_amount" required min="1" step="1" value="100">
      </div>
      <div class="field" style="margin:0">
        <label>Usage limit <small class="muted">(blank = unlimited)</small></label>
        <input type="number" name="usage_limit" min="1" placeholder="50">
      </div>
      <div class="field" style="margin:0">
        <label>Expires on <small class="muted">(optional)</small></label>
        <input type="date" name="expires_on" min="<?= date('Y-m-d') ?>">
      </div>
      <div>
        <button class="btn btn-primary btn-block">+ Add coupon</button>
      </div>
    </div>
    <p class="muted small" style="margin-top:8px">
      Common picks: <code>GNB100</code> ₹100 off, <code>GNB200</code> ₹200 off, <code>GNB300</code> ₹300 off.
    </p>
  </form>
</div>

<div class="table-wrap">
  <table class="data">
    <thead>
      <tr>
        <th>Code</th>
        <th>Discount</th>
        <th>Usage</th>
        <th>Expires</th>
        <th>Active</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$coupons): ?>
      <tr><td colspan="6" style="text-align:center;padding:32px;color:var(--muted)">
        No coupons yet. Create one above.
      </td></tr>
    <?php endif; ?>
    <?php foreach ($coupons as $c):
      $limitDisplay = $c['usage_limit'] === null ? '∞' : (int)$c['usage_limit'];
      $exhausted = $c['usage_limit'] !== null && (int)$c['used_count'] >= (int)$c['usage_limit'];
      $expired = $c['expires_on'] && $c['expires_on'] < date('Y-m-d');
    ?>
      <tr>
        <td>
          <form method="post" id="cf-<?= (int)$c['id'] ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          </form>
          <strong style="font-family:ui-monospace,Menlo,monospace"><?= e($c['code']) ?></strong>
          <?php if ($expired): ?><br><small style="color:var(--danger)">expired</small><?php endif; ?>
          <?php if ($exhausted): ?><br><small style="color:var(--danger)">limit reached</small><?php endif; ?>
        </td>
        <td>
          <input form="cf-<?= (int)$c['id'] ?>" type="number" name="discount_amount"
                 value="<?= e($c['discount_amount']) ?>" min="1" step="1"
                 style="width:90px;padding:6px;border:1px solid var(--line);border-radius:6px">
        </td>
        <td>
          <strong><?= (int)$c['used_count'] ?></strong> / 
          <input form="cf-<?= (int)$c['id'] ?>" type="number" name="usage_limit"
                 value="<?= $c['usage_limit'] === null ? '' : (int)$c['usage_limit'] ?>"
                 placeholder="∞" min="1"
                 style="width:70px;padding:6px;border:1px solid var(--line);border-radius:6px">
        </td>
        <td>
          <input form="cf-<?= (int)$c['id'] ?>" type="date" name="expires_on"
                 value="<?= e($c['expires_on'] ?? '') ?>"
                 style="padding:6px;border:1px solid var(--line);border-radius:6px">
        </td>
        <td>
          <label style="display:inline-flex;align-items:center;gap:6px">
            <input form="cf-<?= (int)$c['id'] ?>" type="checkbox" name="is_active" <?= $c['is_active'] ? 'checked' : '' ?>>
            <span class="pill pill-<?= $c['is_active'] ? 'active' : 'inactive' ?>"><?= $c['is_active'] ? 'on' : 'off' ?></span>
          </label>
        </td>
        <td>
          <div class="actions">
            <button form="cf-<?= (int)$c['id'] ?>" class="btn btn-primary btn-sm">Save</button>
            <form method="post" style="display:inline"
                  onsubmit="return confirm('Delete this coupon? Only allowed if never used.');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
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
