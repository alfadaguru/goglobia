<?php
// ============================================================================
// ADMIN — AGENT API ACCESS (Phase 3 / close-out). Manage an agent's API keys +
// per-service enablement & fees. Calls the JSON endpoints in
// app/routes/admin/agentApiRoutes.php via fetch. Admin-only (route ADMIN_AUTH).
// ============================================================================
@$SECURE or die('Access Denied!');
$csrf = $_SESSION['csrf_token'] ?? '';
$uid  = htmlspecialchars((string) $user_id, ENT_QUOTES);
$isAgent = $agent && ($agent['role'] ?? '') === 'agent';
$services = ['flights','stays','cars','tours','visa','umrah','esim','bus','ferries','rail'];
?>
<div class="container py-6" x-data="agentApiAdmin()" x-init="init()">
  <div class="flex items-center justify-between mb-6 flex-wrap gap-3">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">Agent API Access</h1>
      <p class="text-sm text-slate-600 mt-1">
        <?php if ($agent): ?>
          <?= htmlspecialchars(trim(($agent['first_name'] ?? '').' '.($agent['last_name'] ?? ''))) ?>
          (<?= htmlspecialchars($agent['email'] ?? '') ?>) — <code><?= $uid ?></code>
        <?php else: ?>User <code><?= $uid ?></code><?php endif; ?>
      </p>
    </div>
    <a href="<?=root.admin?>/users/edit/<?= $uid ?>" class="btn outline"><span class="material-symbols-outlined">arrow_back</span><span>Back to user</span></a>
  </div>

  <?php if (!$isAgent): ?>
    <div class="alert-warning mb-6"><span class="material-icon material-symbols-outlined">warning</span>
      <p>This user is not an agent. API access applies to agent accounts only.</p></div>
  <?php endif; ?>

  <!-- SERVICE MATRIX -->
  <div class="section mb-8">
    <div class="section-header"><h2>Services &amp; fees</h2>
      <p>Enable the services this agent's API keys may call, and set a per-booking fee (percentage or flat).</p></div>
    <div class="table-container">
      <table class="table">
        <thead><tr><th>Service</th><th>Enabled</th><th>Fee type</th><th>Fee value</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($services as $svc): $r = $svcMap[$svc] ?? null; ?>
            <tr>
              <td class="capitalize font-medium"><?= htmlspecialchars($svc) ?></td>
              <td>
                <label class="switch-container switch-md">
                  <input type="checkbox" class="switch-input" x-model="svc['<?= $svc ?>'].enabled">
                  <span class="switch-track"><span class="switch-thumb"></span></span>
                </label>
              </td>
              <td>
                <select class="select" style="height:36px" x-model="svc['<?= $svc ?>'].fee_type">
                  <option value="percentage">Percentage (%)</option>
                  <option value="flat">Flat amount</option>
                </select>
              </td>
              <td><input type="number" step="0.01" min="0" class="input" style="height:36px;max-width:140px" x-model="svc['<?= $svc ?>'].fee_value"></td>
              <td><button class="btn btn-sm" @click="saveService('<?= $svc ?>')" :disabled="saving">Save</button></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-slate-500 mt-2" x-text="svcMsg"></p>
  </div>

  <!-- KEYS -->
  <div class="section">
    <div class="section-header flex items-center justify-between">
      <div><h2>API keys</h2><p>Generate a key (shown once) or revoke an existing one.</p></div>
      <button class="btn" @click="showGen = !showGen"><span class="material-symbols-outlined">add</span><span>New key</span></button>
    </div>

    <div x-show="showGen" x-cloak class="mb-6 p-4 rounded-xl border border-slate-200 bg-slate-50">
      <div class="form-grid">
        <div class="form-control"><label>Label</label><input class="input" type="text" x-model="newLabel" placeholder="e.g. Agent production"></div>
        <div class="form-control"><label>IP allow-list (optional)</label><input class="input" type="text" x-model="newIps" placeholder="Comma-separated IPs, or blank"></div>
      </div>
      <div class="mt-4"><button class="btn" @click="generate()" :disabled="saving">Generate</button></div>
    </div>

    <div x-show="freshKey" x-cloak class="alert-warning mb-6">
      <div class="flex-1"><p class="font-semibold mb-1">New key (copy now — shown once):</p>
        <div class="flex items-center gap-2">
          <code class="bg-white/70 px-3 py-2 rounded-lg text-sm break-all flex-1" x-text="freshKey"></code>
          <button class="btn btn-sm" @click="navigator.clipboard.writeText(freshKey)">Copy</button>
        </div></div>
    </div>

    <div class="table-container">
      <table class="table">
        <thead><tr><th>Key</th><th>Label</th><th>Status</th><th>Last used</th><th>Created</th><th></th></tr></thead>
        <tbody>
          <template x-for="k in keys" :key="k.id">
            <tr>
              <td><code class="text-xs" x-text="k.key_prefix + '…'"></code></td>
              <td x-text="k.label || '—'"></td>
              <td><span class="badge" :class="k.status==='active' ? 'badge-success' : 'badge-error'" x-text="k.status"></span></td>
              <td class="text-xs text-slate-500" x-text="k.last_used_at || 'Never'"></td>
              <td class="text-xs text-slate-500" x-text="k.created_at"></td>
              <td><button class="btn btn-sm rose" x-show="k.status==='active'" @click="revoke(k.id)">Revoke</button></td>
            </tr>
          </template>
          <tr x-show="keys.length===0"><td colspan="6" class="text-slate-500">No keys yet.</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
function agentApiAdmin() {
  return {
    csrf: '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>',
    uid: '<?= $uid ?>',
    base: '<?=root.admin?>/users',
    keys: [], svc: {}, saving: false, showGen: false, freshKey: '', newLabel: '', newIps: '', svcMsg: '',
    init() {
      <?php foreach ($services as $svc): $r = $svcMap[$svc] ?? null; ?>
      this.svc['<?= $svc ?>'] = {
        enabled: <?= $r && (int)$r['enabled']===1 ? 'true' : 'false' ?>,
        fee_type: '<?= $r ? htmlspecialchars($r['fee_type'], ENT_QUOTES) : 'percentage' ?>',
        fee_value: <?= $r ? (float)$r['fee_value'] : 0 ?>
      };
      <?php endforeach; ?>
      this.refresh();
    },
    form(obj) { const f = new FormData(); f.append('csrf_token', this.csrf); f.append('user_id', this.uid); for (const k in obj) f.append(k, obj[k]); return f; },
    async refresh() {
      const r = await fetch(this.base + '/api-keys/list', { method:'POST', body:this.form({}) });
      const j = await r.json(); if (j.success) this.keys = j.keys || [];
    },
    async generate() {
      this.saving = true;
      const r = await fetch(this.base + '/api-keys/generate', { method:'POST', body:this.form({label:this.newLabel, ip_allowlist:this.newIps}) });
      const j = await r.json(); this.saving = false;
      if (j.success) { this.freshKey = j.api_key; this.showGen = false; this.newLabel=''; this.newIps=''; this.refresh(); }
      else alert(j.message || 'Failed');
    },
    async revoke(id) {
      if (!confirm('Revoke this key? Apps using it stop working immediately.')) return;
      const r = await fetch(this.base + '/api-keys/revoke', { method:'POST', body:this.form({key_id:id}) });
      const j = await r.json(); if (j.success) this.refresh(); else alert(j.message || 'Failed');
    },
    async saveService(svc) {
      this.saving = true; const s = this.svc[svc];
      const r = await fetch(this.base + '/api-services/set', { method:'POST', body:this.form({service:svc, enabled:s.enabled?1:0, fee_type:s.fee_type, fee_value:s.fee_value}) });
      const j = await r.json(); this.saving = false;
      this.svcMsg = j.success ? (svc + ' saved.') : (j.message || 'Failed');
    }
  };
}
</script>
