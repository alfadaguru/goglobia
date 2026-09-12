<?php
// UMRAH v2 admin — departures manager (docs Step 8). Expects: $rows, $templates,
// $tiers, $dueSoon, $overdue. Uses fetch to the admin JSON actions.
@$SECURE or die('Access Denied!');
$csrf = $_SESSION['csrf_token'] ?? '';
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
$base = root . 'admin/umrah-manager';
$stdTierId = 0; foreach ($tiers as $t) { if ($t['code'] === 'standard') { $stdTierId = (int) $t['id']; } }
?>
<div class="container py-6" x-data="umrahMgr()">
  <div class="flex items-center justify-between flex-wrap gap-3 mb-6">
    <div>
      <h1 class="text-2xl font-semibold text-slate-900">Umrah Manager</h1>
      <p class="text-sm text-slate-600 mt-1">Create, price and publish departures without a code release.</p>
    </div>
    <div class="flex gap-2">
      <a href="<?= root ?>admin/umrah-manager/bookings" class="btn outline"><span class="material-symbols-outlined">list_alt</span><span>Bookings</span></a>
      <a href="<?= root ?>admin/umrah-manager/quote-requests" class="btn outline"><span class="material-symbols-outlined">contact_support</span><span>Quote requests</span></a>
      <a href="<?= root ?>admin/umrah-manager/groups" class="btn outline"><span class="material-symbols-outlined">groups</span><span>Agent groups</span></a>
      <button class="btn" @click="showCreate=!showCreate"><span class="material-symbols-outlined">add</span><span>New departure</span></button>
      <button class="btn outline" @click="showBulk=!showBulk"><span class="material-symbols-outlined">event_repeat</span><span>Bulk 12th/28th</span></button>
    </div>
  </div>

  <!-- KPI cards -->
  <div class="cards-grid-4 mb-6">
    <div class="card border"><div class="card-stat-label">Departures</div><div class="card-stat-value"><?= count($rows) ?></div></div>
    <div class="card border"><div class="card-stat-label">Published</div><div class="card-stat-value"><?= count(array_filter($rows, fn($r)=>$r['d']['status']==='published')) ?></div></div>
    <div class="card border"><div class="card-stat-label">Due in 7 days</div><div class="card-stat-value"><?= $fmt($dueSoon) ?></div></div>
    <div class="card border"><div class="card-stat-label">Overdue</div><div class="card-stat-value text-red-600"><?= $fmt($overdue) ?></div></div>
  </div>

  <!-- Create form -->
  <div x-show="showCreate" x-cloak class="section mb-6">
    <div class="section-header"><h2>New departure</h2></div>
    <div class="form-grid">
      <div class="form-control"><label>Template</label>
        <select class="select" x-model="c.template_id">
          <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="form-control"><label>Tier</label>
        <select class="select" x-model="c.tier_id">
          <?php foreach ($tiers as $t): ?><option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['public_label']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="form-control"><label>Departure date</label><input type="date" class="input" x-model="c.departure_date"></div>
      <div class="form-control"><label>Return date (blank = +14d)</label><input type="date" class="input" x-model="c.return_date"></div>
      <div class="form-control"><label>Capacity</label><input type="number" class="input" x-model.number="c.capacity"></div>
      <div class="form-control"><label>Regular price</label><input type="number" class="input" x-model.number="c.regular_price"></div>
      <div class="form-control"><label>Promo price</label><input type="number" class="input" x-model.number="c.promo_price"></div>
    </div>
    <div class="mt-4"><button class="btn" :disabled="busy" @click="create()">Create departure</button> <span class="text-sm text-slate-500" x-text="msg"></span></div>
  </div>

  <!-- Bulk form -->
  <div x-show="showBulk" x-cloak class="section mb-6">
    <div class="section-header"><h2>Bulk create (12th &amp; 28th)</h2><p>Generates two departures per month.</p></div>
    <div class="form-grid">
      <div class="form-control"><label>Template</label>
        <select class="select" x-model="bk.template_id">
          <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"><?= htmlspecialchars($t['name']) ?></option><?php endforeach; ?>
        </select></div>
      <div class="form-control"><label>Months (comma YYYY-MM)</label><input class="input" x-model="bk.months" placeholder="2026-10,2026-11,2026-12"></div>
      <div class="form-control"><label>Capacity</label><input type="number" class="input" x-model.number="bk.capacity"></div>
      <div class="form-control"><label>Regular price</label><input type="number" class="input" x-model.number="bk.regular_price"></div>
      <div class="form-control"><label>Promo price</label><input type="number" class="input" x-model.number="bk.promo_price"></div>
    </div>
    <div class="mt-4"><button class="btn" :disabled="busy" @click="bulk()">Generate departures</button> <span class="text-sm text-slate-500" x-text="msg"></span></div>
  </div>

  <!-- Departures table -->
  <div class="section">
    <div class="section-header"><h2>Departures</h2></div>
    <div class="table-container">
      <table class="table">
        <thead><tr><th>Date</th><th>Code</th><th>Promo</th><th>Cap</th><th>Confirmed</th><th>Held</th><th>Avail</th><th>Collected</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
          <?php if (empty($rows)): ?>
            <tr><td colspan="10" class="text-slate-500">No departures yet.</td></tr>
          <?php else: foreach ($rows as $r): $d=$r['d']; $dt=$r['dt']; $cap=$r['cap']; ?>
            <tr>
              <td class="whitespace-nowrap"><?= htmlspecialchars(date('d M Y', strtotime($d['departure_date']))) ?></td>
              <td class="text-xs font-mono"><?= htmlspecialchars($d['code']) ?></td>
              <td><?= $dt && $dt['promo_price'] ? $fmt($dt['promo_price']) : '—' ?></td>
              <td><?= (int)$cap['capacity'] ?></td>
              <td><?= (int)$r['confirmed_pax'] ?></td>
              <td><?= (int)($cap['active_holds'] ?? 0) ?></td>
              <td><?= (int)$cap['remaining'] ?></td>
              <td class="text-xs"><?= $fmt($r['collected']) ?></td>
              <td><span class="badge <?= $d['status']==='published'?'badge-success':($d['status']==='closed'?'badge-error':'badge-gray') ?>"><?= htmlspecialchars($d['status']) ?></span></td>
              <td class="whitespace-nowrap">
                <?php if ($d['status']!=='published'): ?>
                  <button class="btn btn-sm" @click="setStatus(<?= (int)$d['id'] ?>,'published')">Publish</button>
                <?php else: ?>
                  <button class="btn btn-sm outline" @click="setStatus(<?= (int)$d['id'] ?>,'closed')">Close</button>
                <?php endif; ?>
                <button class="btn btn-sm outline" @click="clone(<?= (int)$d['id'] ?>)">Clone</button>
                <button class="btn btn-sm outline" @click='openImages(<?= (int)$d['id'] ?>, <?= htmlspecialchars(json_encode((string)($d['hero_image'] ?? '')), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode(json_decode((string)($d['gallery'] ?? ''), true) ?: []), ENT_QUOTES) ?>)'>Images</button>
                <a class="btn btn-sm outline" href="<?= root ?>admin/umrah-manager/operations/<?= (int)$d['id'] ?>">Ops</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- IMAGE MANAGER MODAL (per departure) -->
  <div x-show="img.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background:rgba(0,0,0,.5)" @click.self="closeImages()">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[90vh] overflow-y-auto p-5">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-bold text-slate-900">Departure images</h2>
        <button class="text-slate-400 hover:text-slate-700" @click="closeImages()"><span class="material-symbols-outlined">close</span></button>
      </div>

      <!-- Hero -->
      <div class="mb-5">
        <label class="block text-sm font-medium text-slate-700 mb-2">Hero image <span class="text-slate-400 font-normal">(main card + detail banner)</span></label>
        <div class="flex items-start gap-3">
          <div class="w-40 h-24 rounded-lg bg-slate-100 overflow-hidden shrink-0 flex items-center justify-center">
            <template x-if="img.hero"><img :src="img.hero" class="w-full h-full object-cover"></template>
            <template x-if="!img.hero"><span class="material-symbols-outlined text-slate-300 text-3xl">mosque</span></template>
          </div>
          <div class="flex-1">
            <label class="btn btn-sm cursor-pointer">
              <span class="material-symbols-outlined text-[18px]">upload</span>
              <span x-text="img.busy==='hero' ? 'Uploading…' : 'Upload hero'"></span>
              <input type="file" class="hidden" accept="image/png,image/jpeg,image/webp" @change="uploadImage('hero',$event)">
            </label>
            <button class="btn btn-sm outline ml-2" x-show="img.hero" @click="deleteImage('hero', img.hero)">Remove</button>
            <p class="text-[11px] text-slate-400 mt-1">JPG/PNG/WebP, up to 6MB. Auto-converted to PNG.</p>
          </div>
        </div>
      </div>

      <!-- Gallery -->
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-2">Gallery <span class="text-slate-400 font-normal">(shown on the detail page)</span></label>
        <div class="grid grid-cols-3 sm:grid-cols-4 gap-2 mb-3">
          <template x-for="g in img.gallery" :key="g">
            <div class="relative group">
              <img :src="g" class="w-full h-20 object-cover rounded-lg border">
              <button class="absolute top-1 right-1 bg-white/90 rounded-full w-6 h-6 flex items-center justify-center text-rose-600 shadow"
                      @click="deleteImage('gallery', g)"><span class="material-symbols-outlined text-[16px]">delete</span></button>
            </div>
          </template>
          <template x-if="!img.gallery.length">
            <div class="col-span-full text-sm text-slate-400 py-3">No gallery images yet.</div>
          </template>
        </div>
        <label class="btn btn-sm outline cursor-pointer">
          <span class="material-symbols-outlined text-[18px]">add_photo_alternate</span>
          <span x-text="img.busy==='gallery' ? 'Uploading…' : 'Add gallery image'"></span>
          <input type="file" class="hidden" accept="image/png,image/jpeg,image/webp" @change="uploadImage('gallery',$event)">
        </label>
      </div>

      <p class="text-xs mt-3" :class="img.msgOk ? 'text-green-600' : 'text-rose-600'" x-text="img.msg"></p>
      <div class="mt-4 text-right">
        <button class="btn outline" @click="closeImages()">Done</button>
      </div>
    </div>
  </div>
</div>

<style>[x-cloak]{display:none!important}</style>
<script>
function umrahMgr() {
  return {
    csrf: '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>', base: '<?= $base ?>',
    busy:false, msg:'', showCreate:false, showBulk:false,
    c:{ template_id:'<?= $templates[0]['id']??'' ?>', tier_id:'<?= $stdTierId ?>', departure_date:'', return_date:'', capacity:50, regular_price:2800000, promo_price:2490000 },
    bk:{ template_id:'<?= $templates[0]['id']??'' ?>', months:'', capacity:50, regular_price:2800000, promo_price:2490000 },
    form(o){ const f=new FormData(); f.append('csrf_token',this.csrf); for(const k in o) f.append(k,o[k]); return f; },
    async post(url,o){ const r=await fetch(url,{method:'POST',body:this.form(o)}); return r.json(); },
    async create(){ this.busy=true; this.msg='Creating…'; const j=await this.post(this.base+'/departures/create',this.c); this.busy=false; this.msg=j.message||''; if(j.success) location.reload(); },
    async bulk(){ this.busy=true; this.msg='Generating…'; const j=await this.post(this.base+'/departures/bulk',this.bk); this.busy=false; this.msg=(j.created||0)+' created'; if(j.success) setTimeout(()=>location.reload(),700); },
    async setStatus(id,s){ if(s==='closed'&&!confirm('Close bookings for this departure?'))return; const j=await this.post(this.base+'/departures/status',{departure_id:id,status:s}); if(j.success) location.reload(); else alert(j.message); },
    async clone(id){ const dt=prompt('New departure date (YYYY-MM-DD):'); if(!dt)return; const j=await this.post(this.base+'/departures/clone',{departure_id:id,departure_date:dt}); if(j.success) location.reload(); else alert(j.message); },

    // ---- Per-departure image manager ----
    img:{ open:false, depId:0, hero:'', gallery:[], busy:'', msg:'', msgOk:false },
    openImages(id, hero, gallery){ this.img={ open:true, depId:id, hero:hero||'', gallery:Array.isArray(gallery)?gallery:[], busy:'', msg:'', msgOk:false }; },
    closeImages(){ this.img.open=false; },
    async uploadImage(slot, ev){
      const file = ev.target.files && ev.target.files[0];
      if(!file){ return; }
      this.img.busy=slot; this.img.msg='';
      const f=new FormData(); f.append('csrf_token',this.csrf); f.append('departure_id',this.img.depId); f.append('slot',slot); f.append('image',file);
      try{
        const r=await fetch(this.base+'/departures/images',{method:'POST',body:f});
        const j=await r.json();
        if(j.success){ if(slot==='hero'){ this.img.hero=j.url; } else { this.img.gallery.push(j.url); } this.img.msg='Uploaded'; this.img.msgOk=true; }
        else { this.img.msg=j.message||'Upload failed'; this.img.msgOk=false; }
      }catch(e){ this.img.msg='Network error'; this.img.msgOk=false; }
      this.img.busy=''; ev.target.value='';
    },
    async deleteImage(slot, url){
      if(!confirm('Remove this image?')) return;
      const j=await this.post(this.base+'/departures/images/delete',{departure_id:this.img.depId,slot:slot,url:url});
      if(j.success){ if(slot==='hero'){ this.img.hero=''; } else { this.img.gallery=this.img.gallery.filter(g=>g!==url); } this.img.msg='Removed'; this.img.msgOk=true; }
      else { this.img.msg=j.message||'Delete failed'; this.img.msgOk=false; }
    }
  };
}
</script>
