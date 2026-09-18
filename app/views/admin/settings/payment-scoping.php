<?php
// Admin: per-module / per-service payment scoping + Pay-Later rules.
// Expects: $moduleTypes[], $suppliersByType[type][], $gateways[], $selModule,
// $selSupplier, $scopeType, $scopeRows[gateway_id=>enabled], $hasScopeRows, $plRule.
@$SECURE or die('Access Denied!');

$e = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$scopeLabel = $selSupplier !== '' ? ($selModule . ' / ' . $selSupplier) : $selModule;
$offsets = $plRule['reminder_offsets_hours'] ?? '48,12';
?>
<div class="container my-4" style="max-width:1000px;">
  <div class="flex items-center justify-between mb-5">
    <div>
      <h1 class="text-xl font-bold text-slate-800">Payment Scoping &amp; Pay-Later</h1>
      <p class="text-sm text-slate-500">Choose which payment methods apply per module and per service, and configure how Pay-Later works for each. Rules resolve <strong>service → module → global</strong>.</p>
    </div>
    <a href="<?= root . admin ?>/settings/gateways" class="btn outline"><span class="material-symbols-outlined">arrow_back</span><span>Gateways</span></a>
  </div>

  <!-- Scope picker -->
  <div class="section mb-4" style="padding:16px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">
    <form method="get" action="<?= root . admin ?>/settings/payment-scoping" id="scopeForm" class="flex flex-wrap items-end gap-3">
      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Module</label>
        <select name="module" id="moduleSel" class="select" onchange="onModuleChange()">
          <?php foreach ($moduleTypes as $mt): ?>
            <option value="<?= $e($mt) ?>" <?= $mt === $selModule ? 'selected' : '' ?>><?= $e(ucfirst($mt)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Service / Supplier</label>
        <select name="supplier" id="supplierSel" class="select">
          <option value="">— Whole module (all suppliers) —</option>
          <?php foreach (($suppliersByType[$selModule] ?? []) as $sup): ?>
            <option value="<?= $e(strtolower($sup)) ?>" <?= strtolower($sup) === $selSupplier ? 'selected' : '' ?>><?= $e($sup) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn primary"><span class="material-symbols-outlined">tune</span><span>Load scope</span></button>
    </form>
    <p class="text-xs text-slate-500 mt-2">Now editing: <strong><?= $e($scopeLabel) ?></strong> (<?= $e($scopeType) ?> scope)</p>
  </div>

  <!-- Gateway allow-list -->
  <div class="section mb-4" style="padding:16px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">
    <h2 class="font-semibold text-slate-800 mb-1">Allowed payment methods</h2>
    <p class="text-xs text-slate-500 mb-3">
      Tick the gateways a customer may use for <strong><?= $e($scopeLabel) ?></strong>.
      Leave “inherit” on to fall back to the broader level (ultimately every enabled gateway).
    </p>

    <label class="flex items-center gap-2 mb-3">
      <input type="checkbox" id="inheritChk" <?= $hasScopeRows ? '' : 'checked' ?> onchange="toggleInherit()">
      <span class="text-sm">Inherit from broader scope (no explicit rule here)</span>
    </label>

    <div id="gwList" class="grid" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;<?= $hasScopeRows ? '' : 'opacity:.45;pointer-events:none;' ?>">
      <?php foreach ($gateways as $gw):
        $gid = (int) $gw['id'];
        $on  = $hasScopeRows ? !empty($scopeRows[$gid]) : true;
        $muted = (string) $gw['status'] !== '1';
      ?>
        <label class="flex items-center gap-2" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:8px;">
          <input type="checkbox" class="gwChk" value="<?= $gid ?>" <?= $on ? 'checked' : '' ?>>
          <span class="text-sm"><?= $e($gw['display_name'] ?: $gw['name']) ?>
            <span class="text-xs text-slate-400">(<?= $e($gw['type']) ?><?= $muted ? ', globally off' : '' ?>)</span>
          </span>
        </label>
      <?php endforeach; ?>
    </div>

    <div class="mt-3">
      <button type="button" class="btn primary" onclick="saveGateways()"><span class="material-symbols-outlined">save</span><span>Save methods</span></button>
      <span id="gwMsg" class="text-sm ml-2"></span>
    </div>
  </div>

  <!-- Pay-Later rule -->
  <div class="section" style="padding:16px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">
    <h2 class="font-semibold text-slate-800 mb-1">Pay-Later rule for <?= $e($scopeLabel) ?></h2>
    <p class="text-xs text-slate-500 mb-3">Reserve-now-pay-later behaviour for this scope. Applies when the customer picks a “Pay Later” gateway.</p>

    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:14px;">
      <label class="flex items-center gap-2">
        <input type="checkbox" id="plEnabled" <?= !empty($plRule['enabled']) ? 'checked' : '' ?>>
        <span class="text-sm font-medium">Enable Pay-Later here</span>
      </label>

      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Payment deadline (hours)</label>
        <input type="number" id="plDeadline" class="input" min="1" max="8760" value="<?= (int) ($plRule['deadline_hours'] ?? 72) ?>">
      </div>

      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Reminder offsets (hours before, CSV)</label>
        <input type="text" id="plOffsets" class="input" value="<?= $e($offsets) ?>" placeholder="48,12">
      </div>

      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">At deadline</label>
        <select id="plPolicy" class="select">
          <option value="flag" <?= ($plRule['deadline_policy'] ?? 'flag') === 'flag' ? 'selected' : '' ?>>Flag for admin (don’t cancel)</option>
          <option value="auto_cancel" <?= ($plRule['deadline_policy'] ?? '') === 'auto_cancel' ? 'selected' : '' ?>>Auto-cancel &amp; release</option>
        </select>
      </div>

      <label class="flex items-center gap-2">
        <input type="checkbox" id="plRelease" <?= (int) ($plRule['release_inventory'] ?? 1) === 1 ? 'checked' : '' ?>>
        <span class="text-sm">Release inventory on auto-cancel</span>
      </label>

      <label class="flex items-center gap-2">
        <input type="checkbox" id="plAgents" <?= !empty($plRule['agents_only']) ? 'checked' : '' ?>>
        <span class="text-sm">Agents only</span>
      </label>

      <div>
        <label class="block text-xs font-semibold text-slate-600 mb-1">Min order amount (optional)</label>
        <input type="number" id="plMin" class="input" min="0" step="0.01" value="<?= $plRule && $plRule['min_amount'] !== null ? $e($plRule['min_amount']) : '' ?>">
      </div>
    </div>

    <div class="mt-3">
      <button type="button" class="btn primary" onclick="savePayLater()"><span class="material-symbols-outlined">save</span><span>Save Pay-Later rule</span></button>
      <span id="plMsg" class="text-sm ml-2"></span>
    </div>
  </div>
</div>

<script>
const SCOPE = { module: <?= json_encode($selModule) ?>, supplier: <?= json_encode($selSupplier) ?> };
const SUPPLIERS = <?= json_encode($suppliersByType, JSON_UNESCAPED_SLASHES) ?>;

// Repopulate the supplier dropdown when the module changes (before submitting).
function onModuleChange() {
  const mt = document.getElementById('moduleSel').value;
  const sup = document.getElementById('supplierSel');
  sup.innerHTML = '<option value="">— Whole module (all suppliers) —</option>';
  (SUPPLIERS[mt] || []).forEach(s => {
    const o = document.createElement('option'); o.value = String(s).toLowerCase(); o.textContent = s; sup.appendChild(o);
  });
}
function toggleInherit() {
  const inherit = document.getElementById('inheritChk').checked;
  const list = document.getElementById('gwList');
  list.style.opacity = inherit ? '.45' : '1';
  list.style.pointerEvents = inherit ? 'none' : 'auto';
}
function post(url, data, msgEl) {
  const body = new URLSearchParams();
  Object.entries(data).forEach(([k, v]) => {
    if (Array.isArray(v)) v.forEach(x => body.append(k + '[]', x)); else body.append(k, v);
  });
  msgEl.textContent = 'Saving…'; msgEl.style.color = '#64748b';
  // app.js interceptor attaches X-CSRF-TOKEN for same-origin POSTs.
  return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, credentials: 'same-origin', body })
    .then(r => r.json())
    .then(d => {
      const ok = d && d.status === 'success';
      msgEl.textContent = (d && d.message) || (ok ? 'Saved.' : 'Failed.');
      msgEl.style.color = ok ? '#16a34a' : '#dc2626';
    })
    .catch(() => { msgEl.textContent = 'Network error.'; msgEl.style.color = '#dc2626'; });
}
function saveGateways() {
  const inherit = document.getElementById('inheritChk').checked;
  const enabled = [...document.querySelectorAll('.gwChk:checked')].map(c => c.value);
  post('<?= root . admin ?>/settings/payment-scoping/gateways',
    { module_type: SCOPE.module, supplier: SCOPE.supplier, inherit: inherit ? '1' : '', enabled_gateways: enabled },
    document.getElementById('gwMsg'));
}
function savePayLater() {
  post('<?= root . admin ?>/settings/payment-scoping/pay-later', {
    module_type: SCOPE.module, supplier: SCOPE.supplier,
    enabled: document.getElementById('plEnabled').checked ? '1' : '',
    deadline_hours: document.getElementById('plDeadline').value,
    reminder_offsets_hours: document.getElementById('plOffsets').value,
    deadline_policy: document.getElementById('plPolicy').value,
    release_inventory: document.getElementById('plRelease').checked ? '1' : '',
    agents_only: document.getElementById('plAgents').checked ? '1' : '',
    min_amount: document.getElementById('plMin').value,
  }, document.getElementById('plMsg'));
}
</script>
