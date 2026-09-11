<?php
// UMRAH v2 admin — agent groups list + status/visa control + Nusuk export.
// Expects: $groups, $counts.
@$SECURE or die('Access Denied!');
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
$csrf = $_SESSION['csrf_token'] ?? (class_exists('CSRF') ? CSRF::getToken() : '');
$cur = $_GET['status'] ?? '';
$statusBadge = ['draft'=>'badge-gray','pending'=>'badge-warning','paid'=>'badge-success','submitted'=>'badge-success','processing'=>'badge-warning','confirmed'=>'badge-success','cancelled'=>'badge-error'];
?>
<div class="container py-6" x-data="umrahGroupsAdmin()">
  <div class="flex items-center justify-between mb-6">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">Agent Groups</h1>
      <p class="text-sm text-slate-600 mt-1">Manage group lifecycle, visa status, and export the Nusuk manifest.</p>
    </div>
    <a href="<?= root ?>admin/umrah-manager" class="btn outline"><span class="material-symbols-outlined">arrow_back</span><span>Manager</span></a>
  </div>

  <div class="flex flex-wrap gap-2 mb-4">
    <a href="?" class="badge <?= $cur==='' ? 'badge-primary' : 'badge-gray' ?>">All</a>
    <?php foreach (['draft','pending','paid','submitted','processing','confirmed','cancelled'] as $s): ?>
      <a href="?status=<?= $s ?>" class="badge <?= $cur===$s ? 'badge-primary' : 'badge-gray' ?>"><?= ucfirst($s) ?> (<?= (int)($counts[$s] ?? 0) ?>)</a>
    <?php endforeach; ?>
  </div>

  <div class="section">
    <div class="table-container">
      <table class="table">
        <thead><tr><th>Ref</th><th>Agent</th><th>Departure</th><th>Tier</th><th>Pax</th><th>Total</th><th>Status</th><th>Visa</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($groups)): ?>
            <tr><td colspan="9" class="text-slate-500">No groups<?= $cur ? " with status “".htmlspecialchars($cur)."”" : '' ?> yet.</td></tr>
          <?php else: foreach ($groups as $g): ?>
            <tr>
              <td class="font-mono text-xs"><?= htmlspecialchars($g['group_ref']) ?></td>
              <td class="text-sm"><?= htmlspecialchars($g['agent_name']) ?></td>
              <td class="text-xs text-slate-600"><?= htmlspecialchars($g['dep_label']) ?></td>
              <td class="uppercase text-xs"><?= htmlspecialchars((string)$g['tier_code']) ?></td>
              <td><?= (int)$g['pax_count'] ?></td>
              <td><?= $fmt($g['total_price']) ?></td>
              <td><span class="badge <?= $statusBadge[$g['status']] ?? 'badge-gray' ?>"><?= ucfirst(htmlspecialchars($g['status'])) ?></span></td>
              <td><span class="badge badge-gray"><?= htmlspecialchars($g['visa_status']) ?></span></td>
              <td>
                <button type="button" class="btn outline btn-sm"
                  @click='open(<?= htmlspecialchars(json_encode([
                    'id'=>(int)$g['id'],'ref'=>$g['group_ref'],'status'=>$g['status'],'visa'=>$g['visa_status'],
                    'booking'=>(int)($g['umrah_booking_id'] ?? 0),
                  ], JSON_UNESCAPED_SLASHES), ENT_QUOTES) ?>)'>Manage</button>
                <a class="btn btn-sm" href="<?= root . admin ?>/umrah-manager/manifest?group=<?= (int)$g['id'] ?>" title="Nusuk manifest CSV">Manifest</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
    <p class="text-xs text-slate-500 mt-2">Nusuk manifest columns are configurable in Settings (umrah_nusuk_columns). Default: passport, names, gender, DOB, nationality, passport dates, mobile, email.</p>
  </div>

  <!-- Manage drawer -->
  <div x-show="sel" x-cloak class="fixed inset-0 z-50 flex" @keydown.escape.window="sel=null">
    <div class="absolute inset-0 bg-black/40" @click="sel=null"></div>
    <div class="relative ml-auto w-full max-w-md bg-white h-full overflow-y-auto shadow-xl p-6" x-show="sel">
      <template x-if="sel">
        <div>
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-slate-900">Group <span x-text="sel.ref"></span></h2>
            <button class="btn ghost btn-sm" @click="sel=null"><span class="material-symbols-outlined">close</span></button>
          </div>
          <form @submit.prevent="save()">
            <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
            <select class="select w-full mb-3" x-model="form.status">
              <option value="">— keep —</option>
              <option>draft</option><option>pending</option><option>paid</option><option>submitted</option><option>processing</option><option>confirmed</option><option>cancelled</option>
            </select>
            <label class="block text-sm font-medium text-slate-700 mb-1">Visa status</label>
            <select class="select w-full mb-3" x-model="form.visa_status">
              <option value="">— keep —</option>
              <option value="none">none</option><option value="partial">partial (some issued)</option><option value="all">all issued</option><option value="rejected">rejected</option>
            </select>
            <div class="flex gap-2 items-center">
              <button type="submit" class="btn" :disabled="busy" x-text="busy?'Saving…':'Save'"></button>
              <a class="btn outline" :href="'<?= root . admin ?>/umrah-manager/manifest?group=' + sel.id">Export manifest</a>
              <span class="text-sm" :class="okMsg?'text-green-600':'text-rose-600'" x-text="okMsg || errMsg"></span>
            </div>
          </form>
        </div>
      </template>
    </div>
  </div>
</div>

<script>
function umrahGroupsAdmin() {
  return {
    sel: null, busy: false, okMsg: '', errMsg: '',
    csrf: '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>',
    form: { status: '', visa_status: '' },
    open(g) { this.sel = g; this.okMsg=''; this.errMsg=''; this.form = { status:'', visa_status:'' }; },
    async save() {
      this.busy = true; this.okMsg=''; this.errMsg='';
      const body = new URLSearchParams({ csrf_token:this.csrf, group_id:this.sel.id, status:this.form.status, visa_status:this.form.visa_status });
      try {
        const r = await fetch('<?= root . admin ?>/umrah-manager/groups/status', { method:'POST', body });
        const j = await r.json();
        if (j.success) { this.okMsg='Saved'; setTimeout(()=>location.reload(), 600); } else { this.errMsg = j.message||'Failed'; }
      } catch(e){ this.errMsg='Network error'; }
      this.busy = false;
    }
  };
}
</script>
