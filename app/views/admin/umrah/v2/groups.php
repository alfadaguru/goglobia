<?php
// UMRAH v2 admin — agent groups list + status/visa control + Nusuk export.
// Expects: $groups, $counts.
@$SECURE or die('Access Denied!');
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
$csrf = $_SESSION['csrf_token'] ?? (class_exists('CSRF') ? CSRF::getToken() : '');
$cur = $_GET['status'] ?? '';
$statusBadge = ['draft'=>'badge-gray','pending'=>'badge-warning','submitted'=>'badge-warning','queried'=>'badge-warning','accepted'=>'badge-success','rejected'=>'badge-error','paid'=>'badge-success','processing'=>'badge-warning','confirmed'=>'badge-success','approved'=>'badge-success','partially_approved'=>'badge-warning','completed'=>'badge-success','cancelled'=>'badge-error'];
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
    <?php foreach (['draft','submitted','queried','accepted','processing','partially_approved','approved','completed','rejected','cancelled'] as $s): ?>
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
          <div class="mb-3 text-sm text-slate-600">Status: <span class="badge" x-text="sel.status"></span></div>

          <!-- REVIEW: only when submitted (accept / query / reject + comment). -->
          <template x-if="sel.status==='submitted'">
            <div class="mb-4">
              <label class="block text-sm font-medium text-slate-700 mb-1">Comment to the agent (optional for accept, recommended for query/reject)</label>
              <textarea class="input w-full mb-2" rows="3" x-model="reviewComment" placeholder="e.g. Please re-upload pilgrim 2's passport — the photo page is cut off."></textarea>
              <div class="flex gap-2">
                <button class="btn" :disabled="busy" @click="review('accept')">Accept</button>
                <button class="btn outline" :disabled="busy" @click="review('query')">Query</button>
                <button class="btn outline text-rose-600" :disabled="busy" @click="review('reject')">Reject</button>
              </div>
              <p class="text-xs text-slate-500 mt-1">Accepting lets the agent confirm &amp; pay from their wallet. No money moves here.</p>
            </div>
          </template>

          <template x-if="['accepted','queried','rejected'].includes(sel.status)">
            <div class="alert-info mb-4"><span class="material-icon material-symbols-outlined">info</span>
              <p x-text="sel.status==='accepted' ? 'Accepted — waiting for the agent to confirm & pay.' : (sel.status==='queried' ? 'Queried — sent back to the agent to amend.' : 'Rejected.')"></p></div>
          </template>

          <!-- VISA outcomes + per-member docs: once paid/processing. -->
          <template x-if="['processing','partially_approved','approved','completed'].includes(sel.status)">
            <div>
              <h3 class="font-semibold text-slate-800 mb-2">Pilgrim visa outcomes</h3>
              <p class="text-xs text-slate-500 mb-2">Mark each pilgrim approved or rejected, then finalise. Rejected pilgrims are auto-refunded (unit price + fee share) to the agent's wallet.</p>
              <div class="space-y-2 mb-3">
                <template x-for="m in members" :key="m.id">
                  <div class="border rounded-lg p-2">
                    <div class="flex items-center justify-between">
                      <div class="text-sm font-medium" x-text="(m.first_name||'') + ' ' + (m.last_name||'')"></div>
                      <span class="badge" :class="m.visa_status==='approved'?'badge-success':(m.visa_status==='refunded'?'badge-error':(m.visa_status==='rejected'?'badge-error':'badge-gray'))" x-text="m.visa_status"></span>
                    </div>
                    <div class="flex gap-3 mt-1 text-sm" x-show="m.visa_status!=='refunded'">
                      <label class="inline-flex items-center gap-1"><input type="radio" :name="'v'+m.id" value="approved" x-model="visa[m.id]"> Approve</label>
                      <label class="inline-flex items-center gap-1"><input type="radio" :name="'v'+m.id" value="rejected" x-model="visa[m.id]"> Reject (refund)</label>
                    </div>
                    <!-- per-pilgrim fulfilment uploads -->
                    <div class="flex flex-wrap gap-2 mt-2" x-show="m.visa_status==='approved'">
                      <template x-for="kind in ['visa','ticket','hotel']" :key="kind">
                        <label class="btn btn-sm outline cursor-pointer">
                          <span class="material-symbols-outlined text-[16px]">upload</span>
                          <span x-text="(m[kind+'_doc']?'Replace ':'Upload ')+kind"></span>
                          <input type="file" class="hidden" accept="image/*,application/pdf" @change="uploadDoc(m, kind, $event)">
                        </label>
                      </template>
                    </div>
                  </div>
                </template>
              </div>
              <button class="btn" :disabled="busy" @click="saveVisa()" x-text="busy?'Saving…':'Finalise visa outcomes'"></button>
            </div>
          </template>

          <div class="mt-4 flex gap-2 items-center">
            <a class="btn outline btn-sm" :href="'<?= root . admin ?>/umrah-manager/manifest?group=' + sel.id">Export manifest</a>
            <span class="text-sm" :class="okMsg?'text-green-600':'text-rose-600'" x-text="okMsg || errMsg"></span>
          </div>
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
    base: '<?= root . admin ?>/umrah-manager/groups',
    reviewComment: '', members: [], visa: {},
    async open(g) {
      this.sel = g; this.okMsg=''; this.errMsg=''; this.reviewComment=''; this.members=[]; this.visa={};
      if (['processing','partially_approved','approved','completed'].includes(g.status)) {
        try { const r = await fetch(this.base + '/' + g.id + '/members'); const j = await r.json(); if (j.success) this.members = j.members || []; } catch(e){}
      }
    },
    form(o){ const b=new URLSearchParams(); b.append('csrf_token',this.csrf); for(const k in o) b.append(k,o[k]); return b; },
    async review(decision) {
      this.busy=true; this.okMsg=''; this.errMsg='';
      try {
        const r = await fetch(this.base + '/review', { method:'POST', body:this.form({ group_id:this.sel.id, decision, comment:this.reviewComment }) });
        const j = await r.json();
        if (j.success) { this.okMsg='Done'; setTimeout(()=>location.reload(), 500); } else { this.errMsg=j.message||'Failed'; }
      } catch(e){ this.errMsg='Network error'; }
      this.busy=false;
    },
    async saveVisa() {
      this.busy=true; this.okMsg=''; this.errMsg='';
      try {
        const r = await fetch(this.base + '/visa', { method:'POST', body:this.form({ group_id:this.sel.id, outcomes:JSON.stringify(this.visa) }) });
        const j = await r.json();
        if (j.success) { this.okMsg='Approved '+j.approved+', rejected '+j.rejected+(j.refunded?(' — refunded ₦'+Number(j.refunded).toLocaleString('en-NG')):''); setTimeout(()=>location.reload(), 900); } else { this.errMsg=j.message||'Failed'; }
      } catch(e){ this.errMsg='Network error'; }
      this.busy=false;
    },
    async uploadDoc(m, kind, ev) {
      const file = ev.target.files && ev.target.files[0]; if(!file) return;
      const fd = new FormData(); fd.append('csrf_token',this.csrf); fd.append('group_id',this.sel.id); fd.append('member_id',m.id); fd.append('kind',kind); fd.append('doc',file);
      this.okMsg='Uploading '+kind+'…'; this.errMsg='';
      try {
        const r = await fetch(this.base + '/member-doc', { method:'POST', body:fd });
        const j = await r.json();
        if (j.success) { m[kind+'_doc']=j.url; this.okMsg=kind+' uploaded'; } else { this.errMsg=j.message||'Upload failed'; }
      } catch(e){ this.errMsg='Upload error'; }
      ev.target.value='';
    }
  };
}
</script>
