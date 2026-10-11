<?php @$SECURE or die('Access Denied!'); ?>
<?php
  // Shared by two routes via $view ∈ {'list','detail'}.
  $view    = $view ?? 'list';
  $canEdit = !empty($canEdit);
  $propMap = (isset($propMap) && is_array($propMap)) ? $propMap : [];
  $live    = (isset($live) && is_array($live)) ? $live : ['connected' => false, 'message' => ''];
  $devTypes = function_exists('iot_device_types') ? iot_device_types() : [];
  $metrics  = function_exists('iot_metrics') ? iot_metrics() : [];
  $ops      = function_exists('iot_rule_operators') ? iot_rule_operators() : [];
  $actions  = function_exists('iot_rule_actions') ? iot_rule_actions() : [];
  $iotBase  = root . 'supplier/iot';
  $stateColor = ['online'=>'bg-green-50 text-green-700','provisioned'=>'bg-blue-50 text-blue-700','offline'=>'bg-amber-50 text-amber-700','not_connected'=>'bg-gray-100 text-gray-500'];
  $stateLabel = ['online'=>'Online','provisioned'=>'Provisioned','offline'=>'Offline','not_connected'=>'Not connected'];
  $unitFor = function($m){ return function_exists('iot_metric_unit') && $m ? iot_metric_unit($m) : ''; };
?>
<div class="max-w-6xl mx-auto px-4 py-8 space-y-6">

  <?php if (!empty($_SESSION['message'])): ?>
    <?php $__m = $_SESSION['message']; $__t = is_array($__m) ? ($__m['type'] ?? 'info') : 'info'; $__x = is_array($__m) ? ($__m['text'] ?? '') : (string) $__m; ?>
    <div class="<?= $__t === 'error' ? 'alert-error' : 'alert-success' ?>"><span class="material-symbols-outlined"><?= $__t === 'error' ? 'error' : 'check_circle' ?></span><p class="text-sm"><?= htmlspecialchars($__x) ?></p></div>
    <?php unset($_SESSION['message']); ?>
  <?php endif; ?>

  <!-- HONEST STUB banner: never imply live telemetry -->
  <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 flex items-start gap-2">
    <span class="material-symbols-outlined text-amber-600 text-base">sensors_off</span>
    <div class="text-sm text-amber-800">
      <span class="font-medium">Live telemetry not connected.</span>
      <?= htmlspecialchars((string) ($live['message'] ?? 'Devices are registry records; readings are entered manually or imported.')) ?>
    </div>
  </div>

<?php if ($view === 'detail'): ?>
<?php
  $device   = (isset($device) && is_array($device)) ? $device : [];
  $readings = (isset($readings) && is_array($readings)) ? $readings : [];
  $rules    = (isset($rules) && is_array($rules)) ? $rules : [];
  $runs     = (isset($runs) && is_array($runs)) ? $runs : [];
  $did  = (int) ($device['id'] ?? 0);
  $dsid = (int) ($device['stay_id'] ?? 0);
  $dMetric = (string) ($device['metric'] ?? '');
  $cs = (string) ($device['conn_state'] ?? 'not_connected');
?>
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900"><?= htmlspecialchars((string) ($device['name'] ?? 'Device')) ?></h1>
      <p class="text-sm text-gray-600">
        <?= htmlspecialchars($propMap[$dsid] ?? ('#' . $dsid)) ?>
        · <?= htmlspecialchars($devTypes[(string) ($device['device_type'] ?? '')] ?? (string) ($device['device_type'] ?? '')) ?>
        <?php if (!empty($device['location'])): ?> · <?= htmlspecialchars((string) $device['location']) ?><?php endif; ?>
      </p>
    </div>
    <a href="<?= $iotBase ?>" class="text-sm text-violet-600 hover:underline">Back to devices</a>
  </div>

  <div class="card p-5 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
    <div><div class="text-xs text-gray-500">Connection</div><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stateColor[$cs] ?? '' ?>"><?= htmlspecialchars($stateLabel[$cs] ?? $cs) ?></span></div>
    <div><div class="text-xs text-gray-500">Metric</div><div class="text-gray-900"><?= htmlspecialchars($dMetric ?: '—') ?></div></div>
    <div><div class="text-xs text-gray-500">Last reading</div><div class="text-gray-900 tabular-nums"><?= $device['last_value'] !== null ? htmlspecialchars(rtrim(rtrim(number_format((float) $device['last_value'], 3, '.', ''), '0'), '.')) . ' ' . htmlspecialchars((string) ($device['last_unit'] ?? '')) : '—' ?></div></div>
    <div><div class="text-xs text-gray-500">Last reading at</div><div class="text-gray-900"><?= htmlspecialchars((string) ($device['last_reading_at'] ?? '') ?: '—') ?></div></div>
    <div><div class="text-xs text-gray-500">Vendor / model</div><div class="text-gray-900"><?= htmlspecialchars(trim(((string) ($device['vendor'] ?? '')) . ' ' . ((string) ($device['model'] ?? ''))) ?: '—') ?></div></div>
    <div><div class="text-xs text-gray-500">External ID</div><div class="text-gray-900 break-all"><?= htmlspecialchars((string) ($device['external_id'] ?? '') ?: '—') ?></div></div>
    <div><div class="text-xs text-gray-500">Status</div><div class="text-gray-900"><?= !empty($device['active']) ? 'Active' : 'Disabled' ?></div></div>
  </div>

  <?php if ($canEdit): ?>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <!-- Log reading -->
    <form action="<?= $iotBase ?>/reading" method="POST" class="card p-5 space-y-3">
      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $dsid ?>"><input type="hidden" name="device_id" value="<?= $did ?>">
      <div class="font-medium text-gray-900 text-sm">Record a reading</div>
      <p class="text-xs text-gray-500">Manual / imported entry. No live poll — logging a reading also evaluates this device's rules.</p>
      <div class="grid grid-cols-2 gap-2">
        <input type="number" step="any" name="value" class="input text-sm" placeholder="Value *" required>
        <select name="metric" class="input text-sm">
          <option value="<?= htmlspecialchars($dMetric) ?>"><?= htmlspecialchars($dMetric ?: 'Metric') ?></option>
          <?php foreach ($metrics as $mk => $mu): if ($mk === $dMetric) continue; ?><option value="<?= htmlspecialchars($mk) ?>"><?= htmlspecialchars($mk) ?> (<?= htmlspecialchars($mu) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <input type="text" name="unit" class="input text-sm" placeholder="Unit" value="<?= htmlspecialchars((string) $unitFor($dMetric)) ?>">
        <input type="datetime-local" name="reading_at" class="input text-sm">
      </div>
      <button type="submit" class="btn emerald text-sm">Log reading</button>
    </form>

    <!-- Add rule -->
    <form action="<?= $iotBase ?>/rule" method="POST" class="card p-5 space-y-3">
      <?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $dsid ?>"><input type="hidden" name="device_id" value="<?= $did ?>">
      <div class="font-medium text-gray-900 text-sm">Automation rule</div>
      <p class="text-xs text-gray-500">IF the recorded metric crosses a threshold THEN act — internally. Actions never push to hardware.</p>
      <input type="text" name="name" class="input text-sm w-full" placeholder="Rule name *" required>
      <div class="grid grid-cols-3 gap-2">
        <select name="metric" class="input text-sm">
          <option value="<?= htmlspecialchars($dMetric) ?>"><?= htmlspecialchars($dMetric ?: 'Metric') ?></option>
          <?php foreach ($metrics as $mk => $mu): if ($mk === $dMetric) continue; ?><option value="<?= htmlspecialchars($mk) ?>"><?= htmlspecialchars($mk) ?></option><?php endforeach; ?>
        </select>
        <select name="operator" class="input text-sm"><?php foreach ($ops as $ok => $ol): ?><option value="<?= htmlspecialchars($ok) ?>"><?= htmlspecialchars($ok) ?></option><?php endforeach; ?></select>
        <input type="number" step="any" name="threshold" class="input text-sm" placeholder="Threshold *" required>
      </div>
      <div class="grid grid-cols-2 gap-2">
        <select name="action" class="input text-sm"><?php foreach ($actions as $ak => $al): ?><option value="<?= htmlspecialchars($ak) ?>"><?= htmlspecialchars($al) ?></option><?php endforeach; ?></select>
        <select name="wo_priority" class="input text-sm"><option value="low">Low</option><option value="normal" selected>Normal</option><option value="high">High</option><option value="urgent">Urgent</option></select>
      </div>
      <input type="text" name="action_note" class="input text-sm w-full" placeholder="Action note (optional)">
      <button type="submit" class="btn emerald text-sm">Save rule</button>
    </form>
  </div>
  <?php endif; ?>

  <!-- Rules on this device -->
  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900 text-sm">Automation rules</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Rule</th><th class="px-4 py-2 font-medium">Condition</th><th class="px-4 py-2 font-medium">Action</th><th class="px-4 py-2 font-medium">Last</th><th class="px-4 py-2 font-medium">Status</th><?php if ($canEdit): ?><th class="px-4 py-2 font-medium text-right"></th><?php endif; ?></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($rules)): ?><tr><td colspan="<?= $canEdit ? 6 : 5 ?>" class="px-4 py-6 text-center text-gray-400">No rules.</td></tr>
        <?php else: foreach ($rules as $r): ?>
          <tr>
            <td class="px-4 py-2 text-gray-900"><?= htmlspecialchars((string) $r['name']) ?></td>
            <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) ($r['metric'] ?? '')) ?> <?= htmlspecialchars((string) $r['operator']) ?> <?= htmlspecialchars(rtrim(rtrim(number_format((float) $r['threshold'], 3, '.', ''), '0'), '.')) ?></td>
            <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($actions[(string) $r['action']] ?? (string) $r['action']) ?></td>
            <td class="px-4 py-2"><?php if (!empty($r['last_evaluated_at'])): ?><span class="px-2 py-0.5 rounded text-xs font-medium <?= $r['last_matched'] ? 'bg-red-50 text-red-700' : 'bg-gray-100 text-gray-500' ?>"><?= $r['last_matched'] ? 'Matched' : 'No match' ?></span><?php else: ?><span class="text-gray-400">—</span><?php endif; ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $r['active'] ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500' ?>"><?= $r['active'] ? 'Active' : 'Off' ?></span></td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2 text-right">
              <form action="<?= $iotBase ?>/rule/toggle" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $dsid ?>"><input type="hidden" name="device_id" value="<?= $did ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="text-xs text-violet-600 hover:underline"><?= $r['active'] ? 'Disable' : 'Enable' ?></button></form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Readings -->
  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900 text-sm">Readings</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Time</th><th class="px-4 py-2 font-medium">Metric</th><th class="px-4 py-2 font-medium text-right">Value</th><th class="px-4 py-2 font-medium">Source</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($readings)): ?><tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No readings yet.</td></tr>
        <?php else: foreach ($readings as $rd): ?>
          <tr><td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) $rd['reading_at']) ?></td><td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) ($rd['metric'] ?? '')) ?></td><td class="px-4 py-2 text-right tabular-nums"><?= htmlspecialchars(rtrim(rtrim(number_format((float) $rd['value'], 3, '.', ''), '0'), '.')) ?> <?= htmlspecialchars((string) ($rd['unit'] ?? '')) ?></td><td class="px-4 py-2 text-gray-500"><?= htmlspecialchars((string) $rd['source']) ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Rule-run log -->
  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900 text-sm">Rule evaluations</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Time</th><th class="px-4 py-2 font-medium text-right">Observed</th><th class="px-4 py-2 font-medium">Matched</th><th class="px-4 py-2 font-medium">Action</th><th class="px-4 py-2 font-medium">Detail</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($runs)): ?><tr><td colspan="5" class="px-4 py-6 text-center text-gray-400">No evaluations yet.</td></tr>
        <?php else: foreach ($runs as $rn): ?>
          <tr><td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) $rn['created_at']) ?></td><td class="px-4 py-2 text-right tabular-nums"><?= $rn['observed_value'] !== null ? htmlspecialchars(rtrim(rtrim(number_format((float) $rn['observed_value'], 3, '.', ''), '0'), '.')) : '—' ?></td><td class="px-4 py-2"><?= !empty($rn['matched']) ? '<span class="text-red-700">yes</span>' : '<span class="text-gray-400">no</span>' ?></td><td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) ($rn['action_taken'] ?? '') ?: '—') ?></td><td class="px-4 py-2 text-gray-500"><?= htmlspecialchars((string) ($rn['detail'] ?? '')) ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

<?php else: ?>
<?php
  // LIST
  $devices   = (isset($devices) && is_array($devices)) ? $devices : [];
  $rules     = (isset($rules) && is_array($rules)) ? $rules : [];
  $physRooms = (isset($physRooms) && is_array($physRooms)) ? $physRooms : [];
  $dash      = (isset($dash) && is_array($dash)) ? $dash : [];
  $stayFilter = (int) ($_GET['stay_id'] ?? 0);
  // Single-property forms need a target stay id. Prefer the filter; else first owned.
  $formStay = $stayFilter > 0 ? $stayFilter : (int) (array_key_first($propMap) ?? 0);
  $roomOpts = $physRooms[$formStay] ?? [];
?>
  <div class="flex items-center justify-between">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Smart building &amp; IoT</h1>
      <p class="text-sm text-gray-600">Device registry, meter readings and internal automation rules. Records only — no live device link.</p>
    </div>
    <?php if (count($propMap) > 1): ?>
    <form method="GET" action="<?= $iotBase ?>" class="flex items-center gap-2">
      <select name="stay_id" class="input text-sm" onchange="this.form.submit()">
        <option value="0">All properties</option>
        <?php foreach ($propMap as $pid => $pname): ?><option value="<?= (int) $pid ?>" <?= $stayFilter === (int) $pid ? 'selected' : '' ?>><?= htmlspecialchars((string) $pname) ?></option><?php endforeach; ?>
      </select>
    </form>
    <?php endif; ?>
  </div>

  <div class="grid grid-cols-2 md:grid-cols-5 gap-3">
    <div class="card p-4"><div class="text-xs text-gray-500">Devices</div><div class="text-2xl font-bold text-gray-900"><?= (int) ($dash['devices_total'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Active</div><div class="text-2xl font-bold text-green-700"><?= (int) ($dash['devices_active'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Active rules</div><div class="text-2xl font-bold text-violet-700"><?= (int) ($dash['rules_active'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Readings (7d)</div><div class="text-2xl font-bold text-gray-900"><?= (int) ($dash['readings_7d'] ?? 0) ?></div></div>
    <div class="card p-4"><div class="text-xs text-gray-500">Live link</div><div class="text-sm font-semibold text-gray-500 mt-1">Not connected</div></div>
  </div>

  <?php if ($canEdit): ?>
  <form action="<?= $iotBase ?>/device" method="POST" class="card p-5 space-y-3">
    <?= CSRF::tokenField() ?>
    <div class="font-medium text-gray-900 text-sm">Register a device</div>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
      <select name="stay_id" class="input text-sm" required>
        <?php if (empty($propMap)): ?><option value="0">No property</option><?php endif; ?>
        <?php foreach ($propMap as $pid => $pname): ?><option value="<?= (int) $pid ?>" <?= $formStay === (int) $pid ? 'selected' : '' ?>><?= htmlspecialchars((string) $pname) ?></option><?php endforeach; ?>
      </select>
      <input type="text" name="name" class="input text-sm" placeholder="Device name *" required>
      <select name="device_type" class="input text-sm"><?php foreach ($devTypes as $tk => $tl): ?><option value="<?= htmlspecialchars($tk) ?>"><?= htmlspecialchars($tl) ?></option><?php endforeach; ?></select>
      <select name="metric" class="input text-sm"><option value="">Metric (optional)</option><?php foreach ($metrics as $mk => $mu): ?><option value="<?= htmlspecialchars($mk) ?>"><?= htmlspecialchars($mk) ?> (<?= htmlspecialchars($mu) ?>)</option><?php endforeach; ?></select>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-4 gap-2">
      <input type="text" name="vendor" class="input text-sm" placeholder="Vendor">
      <input type="text" name="model" class="input text-sm" placeholder="Model">
      <input type="text" name="external_id" class="input text-sm" placeholder="External / device ID">
      <input type="text" name="location" class="input text-sm" placeholder="Location (e.g. Lobby)">
    </div>
    <button type="submit" class="btn emerald text-sm">Register device</button>
  </form>
  <?php endif; ?>

  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900 text-sm">Devices</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Name</th><th class="px-4 py-2 font-medium">Property</th><th class="px-4 py-2 font-medium">Type</th><th class="px-4 py-2 font-medium">Metric</th><th class="px-4 py-2 font-medium text-right">Last reading</th><th class="px-4 py-2 font-medium">Connection</th><?php if ($canEdit): ?><th class="px-4 py-2 font-medium text-right"></th><?php endif; ?></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php if (empty($devices)): ?><tr><td colspan="<?= $canEdit ? 7 : 6 ?>" class="px-4 py-6 text-center text-gray-400">No devices registered.</td></tr>
        <?php else: foreach ($devices as $d): $dsid = (int) $d['stay_id']; ?>
          <tr class="<?= empty($d['active']) ? 'opacity-50' : '' ?>">
            <td class="px-4 py-2 text-gray-900"><a class="text-violet-600 hover:underline" href="<?= $iotBase ?>/device/<?= (int) $d['id'] ?>"><?= htmlspecialchars((string) $d['name']) ?></a></td>
            <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($propMap[$dsid] ?? ('#' . $dsid)) ?></td>
            <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars($devTypes[(string) $d['device_type']] ?? (string) $d['device_type']) ?></td>
            <td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) ($d['metric'] ?? '') ?: '—') ?></td>
            <td class="px-4 py-2 text-right tabular-nums"><?= $d['last_value'] !== null ? htmlspecialchars(rtrim(rtrim(number_format((float) $d['last_value'], 3, '.', ''), '0'), '.')) . ' ' . htmlspecialchars((string) ($d['last_unit'] ?? '')) : '—' ?></td>
            <td class="px-4 py-2"><span class="px-2 py-0.5 rounded text-xs font-medium <?= $stateColor[(string) $d['conn_state']] ?? '' ?>"><?= htmlspecialchars($stateLabel[(string) $d['conn_state']] ?? (string) $d['conn_state']) ?></span></td>
            <?php if ($canEdit): ?>
            <td class="px-4 py-2 text-right">
              <form action="<?= $iotBase ?>/device/toggle" method="POST" class="inline"><?= CSRF::tokenField() ?><input type="hidden" name="stay_id" value="<?= $dsid ?>"><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><button type="submit" class="text-xs text-violet-600 hover:underline"><?= !empty($d['active']) ? 'Disable' : 'Enable' ?></button></form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="card overflow-x-auto">
    <div class="px-4 py-3 border-b border-gray-100 font-medium text-gray-900 text-sm">Recent rule evaluations</div>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-gray-500 border-b border-gray-100"><th class="px-4 py-2 font-medium">Time</th><th class="px-4 py-2 font-medium">Matched</th><th class="px-4 py-2 font-medium">Action</th><th class="px-4 py-2 font-medium">Detail</th></tr></thead>
      <tbody class="divide-y divide-gray-50">
        <?php $recentRuns = $dash['recent_runs'] ?? []; if (empty($recentRuns)): ?><tr><td colspan="4" class="px-4 py-6 text-center text-gray-400">No evaluations yet.</td></tr>
        <?php else: foreach ($recentRuns as $rn): ?>
          <tr><td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) $rn['created_at']) ?></td><td class="px-4 py-2"><?= !empty($rn['matched']) ? '<span class="text-red-700">yes</span>' : '<span class="text-gray-400">no</span>' ?></td><td class="px-4 py-2 text-gray-600"><?= htmlspecialchars((string) ($rn['action_taken'] ?? '') ?: '—') ?></td><td class="px-4 py-2 text-gray-500"><?= htmlspecialchars((string) ($rn['detail'] ?? '')) ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>
</div>
