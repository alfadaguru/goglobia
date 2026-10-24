<?php
// app/views/admin/support/targets-manage.php — create/edit a support target.
// Posts to admin/support/targets/save. Mirrors campaigns-manage.php.
@$SECURE or die('Access Denied!');

$tid = (int) ($_GET['id'] ?? 0);
$t = $tid > 0 ? $db->get('support_targets', '*', ['id' => $tid]) : null;
if ($tid > 0 && !$t) {
    $_SESSION['message'] = ['type' => 'error', 'text' => 'Target not found.'];
    if (function_exists('redirect')) { redirect(root . admin . '/support/targets'); }
    else { header('Location: ' . root . admin . '/support/targets'); }
    return;
}

$products = function_exists('support_target_products')
    ? support_target_products()
    : ['flights', 'stays', 'umrah', 'tours', 'cars', 'visa', 'esim', 'ferries', 'rail', 'bus'];

// Support staff for the per-agent dropdown (name resolved from users when blank).
$staffRows = $db->select('support_staff', ['id', 'user_id', 'display_name'], ['ORDER' => ['id' => 'ASC']]) ?: [];
$staffOptions = [];
foreach ($staffRows as $s) {
    $nm = (string) ($s['display_name'] ?? '');
    if ($nm === '' && !empty($s['user_id'])) {
        $u = $db->get('users', ['first_name', 'last_name'], ['user_id' => $s['user_id']]);
        if (is_array($u)) { $nm = trim((string) ($u['first_name'] ?? '') . ' ' . (string) ($u['last_name'] ?? '')); }
    }
    $staffOptions[(int) $s['id']] = $nm !== '' ? $nm : ('Staff #' . (int) $s['id']);
}

$csrf = (class_exists('CSRF') && method_exists('CSRF', 'getToken')) ? CSRF::getToken() : '';
$v = function ($k, $d = '') use ($t) { return htmlspecialchars((string) ($t[$k] ?? $d), ENT_QUOTES); };
$curScope   = (string) ($t['scope'] ?? 'staff');
$curMetric  = (string) ($t['metric'] ?? 'held');
$curPeriod  = (string) ($t['period'] ?? 'monthly');
$curStaff   = (int) ($t['staff_id'] ?? 0);
$curProduct = (string) ($t['product'] ?? '');
$dOnly = function ($raw) { $raw = (string) $raw; if ($raw === '' || $raw === '0000-00-00') return ''; $ts = strtotime($raw); return $ts !== false ? date('Y-m-d', $ts) : ''; };
?>
<div class="container my-4" style="max-width:720px">
  <div class="flex items-center gap-2 mb-5">
    <a href="<?= root.admin ?>/support/targets" class="text-gray-400 hover:text-gray-700"><span class="material-symbols-outlined">arrow_back</span></a>
    <h1 class="text-xl font-bold text-gray-900"><?= $tid > 0 ? 'Edit target' : 'New target' ?></h1>
  </div>

  <form method="post" action="<?= root.admin ?>/support/targets/save" class="space-y-5 bg-white border border-gray-100 rounded-xl p-5">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
    <input type="hidden" name="id" value="<?= $tid ?>">

    <div>
      <label class="block text-sm font-semibold text-gray-700 mb-1">Scope</label>
      <select name="scope" id="t-scope" class="w-full rounded-lg border border-gray-200 px-3 py-2" onchange="document.getElementById('t-staff-wrap').style.display = this.value==='staff' ? 'block' : 'none'">
        <option value="staff"<?= $curScope==='staff'?' selected':'' ?>>A specific agent</option>
        <option value="gia"<?= $curScope==='gia'?' selected':'' ?>>GIA (AI, chats it handled alone)</option>
        <option value="team"<?= $curScope==='team'?' selected':'' ?>>Whole team</option>
      </select>
    </div>

    <div id="t-staff-wrap" style="<?= $curScope==='staff'?'':'display:none' ?>">
      <label class="block text-sm font-semibold text-gray-700 mb-1">Agent</label>
      <select name="staff_id" class="w-full rounded-lg border border-gray-200 px-3 py-2">
        <option value="0">— select staff —</option>
        <?php foreach ($staffOptions as $sid => $nm): ?>
          <option value="<?= $sid ?>"<?= $curStaff===$sid?' selected':'' ?>><?= htmlspecialchars($nm) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Metric</label>
        <select name="metric" class="w-full rounded-lg border border-gray-200 px-3 py-2">
          <option value="held"<?= $curMetric==='held'?' selected':'' ?>>Held bookings (chat → held)</option>
          <option value="paid"<?= $curMetric==='paid'?' selected':'' ?>>Paid bookings (converted)</option>
          <option value="revenue"<?= $curMetric==='revenue'?' selected':'' ?>>Revenue</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Product</label>
        <select name="product" class="w-full rounded-lg border border-gray-200 px-3 py-2">
          <option value="any"<?= $curProduct===''?' selected':'' ?>>Any service</option>
          <?php foreach ($products as $p): ?>
            <option value="<?= htmlspecialchars($p) ?>"<?= $curProduct===$p?' selected':'' ?>><?= htmlspecialchars(ucfirst($p)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Period</label>
        <select name="period" class="w-full rounded-lg border border-gray-200 px-3 py-2">
          <option value="daily"<?= $curPeriod==='daily'?' selected':'' ?>>Daily</option>
          <option value="weekly"<?= $curPeriod==='weekly'?' selected':'' ?>>Weekly</option>
          <option value="monthly"<?= $curPeriod==='monthly'?' selected':'' ?>>Monthly</option>
        </select>
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Target value</label>
        <input type="number" name="target_value" min="1" value="<?= $v('target_value', '0') ?>" class="w-full rounded-lg border border-gray-200 px-3 py-2" required>
      </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Starts (optional)</label>
        <input type="date" name="starts_at" value="<?= htmlspecialchars($dOnly($t['starts_at'] ?? '')) ?>" class="w-full rounded-lg border border-gray-200 px-3 py-2">
      </div>
      <div>
        <label class="block text-sm font-semibold text-gray-700 mb-1">Ends (optional)</label>
        <input type="date" name="ends_at" value="<?= htmlspecialchars($dOnly($t['ends_at'] ?? '')) ?>" class="w-full rounded-lg border border-gray-200 px-3 py-2">
      </div>
    </div>
    <p class="text-xs text-gray-400 -mt-2">Leave dates blank to measure the current period automatically.</p>

    <div>
      <label class="block text-sm font-semibold text-gray-700 mb-1">Label (optional)</label>
      <input type="text" name="label" value="<?= $v('label') ?>" maxlength="160" placeholder="e.g. December Umrah push" class="w-full rounded-lg border border-gray-200 px-3 py-2">
    </div>

    <label class="flex items-center gap-2 text-sm text-gray-700">
      <input type="checkbox" name="active" value="1" <?= ($tid === 0 || !empty($t['active'])) ? 'checked' : '' ?>> Active
    </label>

    <div class="flex items-center gap-3 pt-2">
      <button type="submit" class="rounded-lg bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">Save target</button>
      <a href="<?= root.admin ?>/support/targets" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
    </div>
  </form>
</div>
