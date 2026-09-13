<?php
// UMRAH v2 — agent group builder (Phase C). Expects: $group, $members,
// $walletBalance, $dep, $countries, $umrahCsrf.
@$SECURE or die('Access Denied!');
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
$editable = in_array($group['status'], ['draft', 'pending'], true);
$countryJs = array_map(fn($c) => ['iso' => $c['iso'], 'name' => $c['nicename']], $countries ?? []);
$membersJs = array_map(fn($m) => [
    'id' => (int) $m['id'], 'title' => $m['title'] ?? '', 'first_name' => $m['first_name'] ?? '',
    'last_name' => $m['last_name'] ?? '', 'gender' => $m['gender'] ?? '', 'dob' => $m['dob'] ?? '',
    'nationality' => $m['nationality'] ?? '', 'passport_number' => $m['passport_number'] ?? '',
    'passport_expiry' => $m['passport_expiry'] ?? '', 'doc_status' => $m['doc_status'] ?? 'not_started',
    'passport' => !empty($m['passport_doc']),
], $members ?? []);
$depLabel = $dep ? (($dep['origin_city'] ?? 'Kano') . ' · ' . date('d M', strtotime($dep['departure_date'])) . ' → ' . date('d M Y', strtotime($dep['return_date']))) : '';
?>
<div class="bg-slate-50 min-h-screen"
     x-data="umrahGroup(
        <?= (int) $group['id'] ?>,
        <?= htmlspecialchars(json_encode([
            'status' => $group['status'], 'tier_code' => $group['tier_code'], 'currency' => $group['currency'],
            'declared_male' => (int) $group['declared_male'], 'declared_female' => (int) $group['declared_female'],
            'pax_count' => (int) $group['pax_count'], 'unit_net' => (float) $group['unit_net'],
            'total_price' => (float) $group['total_price'], 'visa_status' => $group['visa_status'],
            'booking_ref' => null, 'invoice_id' => $group['invoice_id'],
            'review_comment' => $group['review_comment'] ?? null,
            'refunded_total' => (float) ($group['refunded_total'] ?? 0),
        ]), ENT_QUOTES) ?>,
        <?= htmlspecialchars(json_encode($membersJs), ENT_QUOTES) ?>,
        <?= htmlspecialchars(json_encode($countryJs), ENT_QUOTES) ?>,
        <?= (float) ($walletBalance ?? 0) ?>
     )">
  <div class="container py-8 max-w-4xl">

    <a href="<?= root ?>umrah/groups" class="text-sm text-primary inline-flex items-center gap-1 mb-3"><span class="material-symbols-outlined text-[18px]">arrow_back</span> My groups</a>

    <!-- Header -->
    <div class="card border">
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-xl font-bold text-slate-900"><?= htmlspecialchars($group['name'] ?: ('Group ' . $group['group_ref'])) ?></h1>
          <p class="text-sm text-slate-500 font-mono"><?= htmlspecialchars($group['group_ref']) ?></p>
          <p class="text-sm text-slate-600 mt-1"><?= htmlspecialchars($depLabel) ?> · <span class="uppercase font-semibold"><?= htmlspecialchars((string) $group['tier_code']) ?></span></p>
        </div>
        <div class="text-right">
          <span class="badge" :class="badge(group.status)" x-text="group.status"></span>
          <div class="text-xs text-slate-500 mt-1">Wallet: <?= $fmt($walletBalance ?? 0) ?></div>
        </div>
      </div>
    </div>

    <!-- Staged counts (editable pre-submit) -->
    <div class="card border mt-4" x-show="editable">
      <h2 class="font-semibold text-slate-900 mb-1">Group size</h2>
      <p class="text-sm text-slate-500 mb-3">Declare how many males and females to size the group. Before you can submit &amp; pay, add every pilgrim below with their details and passport upload.</p>
      <div class="grid grid-cols-2 gap-3 max-w-sm">
        <div><label class="block text-sm text-slate-700 mb-1">Males</label><input type="number" min="0" class="input w-full" x-model.number="group.declared_male" @change="saveCounts()"></div>
        <div><label class="block text-sm text-slate-700 mb-1">Females</label><input type="number" min="0" class="input w-full" x-model.number="group.declared_female" @change="saveCounts()"></div>
      </div>
      <p class="text-sm mt-2" :class="ruleOk ? 'text-green-600' : 'text-amber-600'" x-text="ruleHint"></p>
    </div>

    <!-- Members -->
    <div class="card border mt-4">
      <div class="flex items-center justify-between mb-3">
        <h2 class="font-semibold text-slate-900">Pilgrims (<span x-text="members.length"></span>)</h2>
        <button class="btn btn-sm" x-show="editable" @click="addBlank()"><span class="material-symbols-outlined text-[18px]">person_add</span> Add pilgrim</button>
      </div>

      <template x-if="members.length === 0">
        <p class="text-sm text-slate-500">No pilgrims added yet. Add each pilgrim, save, then upload their passport — all required before you can submit.</p>
      </template>

      <div class="space-y-3">
        <template x-for="(m, idx) in members" :key="idx">
          <div class="border border-slate-200 rounded-xl p-3">
            <div class="grid grid-cols-1 sm:grid-cols-4 gap-2">
              <input class="input" placeholder="First name" x-model="m.first_name">
              <input class="input" placeholder="Last name" x-model="m.last_name">
              <select class="select" x-model="m.gender"><option value="">Gender</option><option value="male">Male</option><option value="female">Female</option></select>
              <input type="date" class="input" x-model="m.dob" title="Date of birth">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 mt-2">
              <select class="select" x-model="m.nationality"><option value="">Nationality</option><template x-for="c in countries" :key="c.iso"><option :value="c.iso" x-text="c.name"></option></template></select>
              <input class="input" placeholder="Passport number" x-model="m.passport_number">
              <input type="date" class="input" x-model="m.passport_expiry" title="Passport expiry">
            </div>
            <div class="flex items-center gap-3 mt-2 flex-wrap">
              <button class="btn btn-sm" @click="saveMember(idx)" :disabled="m.saving" x-text="m.saving ? 'Saving…' : (m.id ? 'Update' : 'Save pilgrim')"></button>
              <!-- Passport upload (REQUIRED). Save the pilgrim first so it has an id. -->
              <label class="btn btn-sm outline cursor-pointer" :class="!m.id ? 'opacity-50 pointer-events-none' : (m.passport ? '' : 'text-rose-600 border-rose-300')">
                <span class="material-symbols-outlined text-[18px]">document_scanner</span>
                <span x-text="m.uploading ? 'Reading…' : (m.passport ? 'Replace passport' : 'Upload passport *')"></span>
                <input type="file" class="hidden" accept="image/jpeg,image/png,image/webp,application/pdf" @change="uploadPassport(idx, $event)">
              </label>
              <button class="text-xs text-rose-600 hover:underline" x-show="m.id || editable" @click="dropMember(idx)">Remove</button>
              <span class="badge" :class="m.passport ? 'badge-success' : 'badge-gray'" x-text="m.passport ? 'passport ✓' : 'passport required'"></span>
              <span class="text-xs" :class="m.msgOk?'text-green-600':'text-rose-600'" x-text="m.msg"></span>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Totals + submit -->
    <div class="card border mt-4">
      <div class="flex items-center justify-between">
        <div>
          <div class="text-sm text-slate-500">Group total (<span x-text="group.pax_count"></span> pilgrims × <span x-text="money(group.unit_net)"></span>)</div>
          <div class="text-2xl font-extrabold text-slate-900" x-text="money(group.total_price)"></div>
        </div>
        <div class="text-right">
          <div class="text-xs text-slate-500">Wallet balance</div>
          <div class="font-semibold" :class="wallet >= group.total_price ? 'text-green-600' : 'text-rose-600'" x-text="money(wallet)"></div>
        </div>
      </div>

      <!-- Operator's note (query/reject/accept comment). -->
      <template x-if="group.review_comment">
        <div class="mt-4 alert-warning">
          <span class="material-icon material-symbols-outlined">chat</span>
          <div><strong>Note from our team:</strong> <span x-text="group.review_comment"></span></div>
        </div>
      </template>

      <!-- DRAFT / QUERIED: agent submits for review (NO payment yet). -->
      <template x-if="['draft','queried'].includes(group.status)">
        <div class="mt-4">
          <button class="btn w-full justify-center" :disabled="busy || !ruleOk || !allHavePassport" @click="submitForReview()"
            x-text="busy ? 'Submitting…' : (group.status==='queried' ? 'Re-submit for review' : 'Submit for review')"></button>
          <p class="text-sm mt-2 text-rose-600" x-show="!allHavePassport"
             x-text="members.length === 0 ? 'Add each pilgrim and upload their passport before you can submit.' : (missingPassportCount + ' pilgrim(s) still need a passport upload before you can submit.')"></p>
          <p class="text-xs text-slate-500 mt-2">No payment yet — our team reviews your group first. Your wallet is only debited when you confirm an accepted group.</p>
          <p class="text-sm mt-2" :class="msgOk?'text-green-600':'text-rose-600'" x-text="msg"></p>
        </div>
      </template>

      <!-- SUBMITTED: waiting for the operator. -->
      <template x-if="group.status==='submitted'">
        <div class="mt-4 alert-info"><span class="material-icon material-symbols-outlined">hourglass_top</span>
          <p><strong>Submitted for review.</strong> Our team will accept, ask a question, or advise. You'll be able to confirm &amp; pay once it's accepted.</p></div>
      </template>

      <!-- ACCEPTED: agent confirms -> wallet debited -> processing. -->
      <template x-if="group.status==='accepted'">
        <div class="mt-4">
          <div class="alert-success mb-3"><span class="material-icon material-symbols-outlined">verified</span>
            <p><strong>Accepted.</strong> Confirm to pay <span x-text="money(group.total_price)"></span> from your wallet and start visa processing.</p></div>
          <button class="btn w-full justify-center" :disabled="busy || wallet < group.total_price" @click="confirmAndPay()"
            x-text="busy ? 'Processing…' : ('Confirm & pay ' + money(group.total_price) + ' from wallet')"></button>
          <p class="text-sm mt-2 text-rose-600" x-show="wallet < group.total_price">Top up your wallet — balance is below the group total.</p>
          <p class="text-sm mt-2" :class="msgOk?'text-green-600':'text-rose-600'" x-text="msg"></p>
        </div>
      </template>

      <!-- REJECTED. -->
      <template x-if="group.status==='rejected'">
        <div class="mt-4 alert-error"><span class="material-icon material-symbols-outlined">block</span>
          <p><strong>Not accepted.</strong> Please see the note above or contact us.</p></div>
      </template>

      <!-- PROCESSING / APPROVED / PARTIALLY_APPROVED / COMPLETED: paid, in progress. -->
      <template x-if="['processing','approved','partially_approved','completed'].includes(group.status)">
        <div class="mt-4 alert-success">
          <span class="material-icon material-symbols-outlined">check_circle</span>
          <div>
            <p><strong>Paid &amp; processing.</strong> We're handling the visas. As each pilgrim's visa, ticket and hotel are ready, the documents appear on their card above.</p>
            <p class="text-sm mt-1" x-show="group.refunded_total > 0">Refunded to your wallet for pilgrims not approved: <strong x-text="money(group.refunded_total)"></strong>.</p>
          </div>
        </div>
      </template>
    </div>

    <!-- Cancel (only before it's confirmed/paid). -->
    <div class="mt-3" x-show="['draft','queried','submitted'].includes(group.status)">
      <button class="text-xs text-rose-600 hover:underline" @click="cancelGroup()">Cancel this group</button>
    </div>

  </div>
</div>

<script>
function umrahGroup(gid, g, members, countries, wallet) {
  return {
    gid: gid,
    group: g,
    members: (members||[]).map(m => ({ ...m, saving:false, uploading:false, passport: !!m.passport, msg:'', msgOk:false })),
    countries: countries || [],
    wallet: wallet || 0,
    csrf: '<?= htmlspecialchars($umrahCsrf ?? '', ENT_QUOTES) ?>',
    api: '<?= root ?>api/v1/umrah/groups/' + gid,
    busy: false, msg: '', msgOk: false,
    minSameGender: { standard:3, vip:2, vvip:2, vvvip:2, vvvvip:0 },
    money(n){ return '₦' + Number(n||0).toLocaleString('en-NG'); },
    badge(s){ return ({draft:'badge-gray',pending:'badge-warning',paid:'badge-success',submitted:'badge-success',processing:'badge-warning',confirmed:'badge-success',cancelled:'badge-error'})[s]||'badge-gray'; },
    get editable(){ return ['draft','pending','queried'].includes(this.group.status); },
    get submitted(){ return ['processing','confirmed','approved','partially_approved','completed'].includes(this.group.status); },
    get counts(){
      // Prefer actual member genders when members exist, else declared.
      if (this.members.length) {
        const m = this.members.filter(x=>x.gender==='male').length;
        const f = this.members.filter(x=>x.gender==='female').length;
        return { m, f };
      }
      return { m: this.group.declared_male||0, f: this.group.declared_female||0 };
    },
    get ruleMin(){ return this.minSameGender[this.group.tier_code] ?? 0; },
    get ruleOk(){ const {m,f}=this.counts; return this.ruleMin<=0 || m>=this.ruleMin || f>=this.ruleMin; },
    get ruleHint(){
      if (this.ruleMin<=0) return 'This tier has private rooms — no minimum group size.';
      const {m,f}=this.counts;
      return this.ruleOk
        ? '✓ Meets the tier rule (at least '+this.ruleMin+' of one gender).'
        : 'This tier needs at least '+this.ruleMin+' of the same gender (e.g. '+this.ruleMin+' males or '+this.ruleMin+' females). Currently '+m+' male / '+f+' female.';
    },
    async post(url, body){
      const r = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':this.csrf}, body: JSON.stringify(Object.assign({csrf_token:this.csrf}, body)) });
      return r.json();
    },
    async saveCounts(){
      const j = await this.post(this.api + '/counts', { declared_male:this.group.declared_male, declared_female:this.group.declared_female });
      if (j.success && j.group){ this.group.pax_count=j.group.pax; this.group.unit_net=j.group.unit; this.group.total_price=j.group.total; }
    },
    addBlank(){ this.members.push({ id:0, first_name:'', last_name:'', gender:'', dob:'', nationality:'', passport_number:'', passport_expiry:'', doc_status:'not_started', passport:false, saving:false, uploading:false, msg:'', msgOk:false }); },
    // Every saved member must have a stored passport before submit is allowed.
    get allHavePassport(){ return this.members.length > 0 && this.members.every(m => m.id && m.passport); },
    get missingPassportCount(){ return this.members.filter(m => !m.passport).length; },
    async uploadPassport(idx, ev){
      const file = ev.target.files && ev.target.files[0];
      if (!file) return;
      const m = this.members[idx];
      if (!m.id){ m.msg='Save the pilgrim first, then upload the passport'; m.msgOk=false; ev.target.value=''; return; }
      m.uploading=true; m.msg='Reading passport…'; m.msgOk=true;
      try {
        const fd = new FormData();
        fd.append('csrf_token', this.csrf);
        fd.append('passport_image', file);
        const r = await fetch(this.api + '/members/' + m.id + '/passport', { method:'POST', headers:{'X-CSRF-TOKEN':this.csrf}, body: fd });
        const j = await r.json();
        if (j.success){
          m.passport=true;
          if (j.fields){ // OCR prefilled — apply then save so the record matches
            const f=j.fields;
            if(f.first_name) m.first_name=f.first_name; if(f.last_name) m.last_name=f.last_name;
            if(f.gender) m.gender=f.gender; if(f.dob) m.dob=f.dob; if(f.nationality) m.nationality=f.nationality;
            if(f.passport_number) m.passport_number=f.passport_number; if(f.passport_expiry) m.passport_expiry=f.passport_expiry;
            await this.saveMember(idx);
            m.msg='Passport read & saved — please check the details match.'; m.msgOk=true;
          } else {
            m.msg='Passport uploaded. Please confirm the details below are correct.'; m.msgOk=true;
          }
        } else { m.msg=j.message||'Upload failed'; m.msgOk=false; }
      } catch(e){ m.msg='Upload error'; m.msgOk=false; }
      m.uploading=false; ev.target.value='';
    },
    async saveMember(idx){
      const m = this.members[idx];
      m.saving=true; m.msg='';
      const j = await this.post(this.api + '/members', {
        id:m.id||0, title:m.title||'', first_name:m.first_name, last_name:m.last_name, gender:m.gender,
        dob:m.dob, nationality:m.nationality, passport_number:m.passport_number, passport_expiry:m.passport_expiry
      });
      if (j.success){ m.id=j.member_id; m.msg='Saved'; m.msgOk=true; await this.refreshTotals(); }
      else { m.msg=j.message||'Failed'; m.msgOk=false; }
      m.saving=false;
    },
    async dropMember(idx){
      const m = this.members[idx];
      if (m.id){ const j = await this.post(this.api + '/members/' + m.id + '/drop', {}); if(!j.success){ m.msg=j.message||'Failed'; return; } }
      this.members.splice(idx,1); await this.refreshTotals();
    },
    async refreshTotals(){
      try { const r = await fetch(this.api); const j = await r.json(); if (j.success && j.group){ this.group.pax_count=j.group.pax_count; this.group.unit_net=j.group.unit_net; this.group.total_price=j.group.total_price; } } catch(e){}
    },
    async submitForReview(){
      this.busy=true; this.msg='';
      const j = await this.post(this.api + '/submit', {});
      if (j.success){ this.group.status=j.status||'submitted'; this.group.review_comment=null; this.msg='Submitted for review.'; this.msgOk=true; setTimeout(()=>location.reload(),700); }
      else { this.msg=j.message||'Submit failed'; this.msgOk=false; }
      this.busy=false;
    },
    async confirmAndPay(){
      if (!confirm('Confirm this group and pay '+this.money(this.group.total_price)+' from your wallet?')) return;
      this.busy=true; this.msg='';
      const j = await this.post(this.api + '/confirm', {});
      if (j.success){ this.group.status='processing'; this.group.booking_ref=j.booking_ref; this.group.invoice_id=j.invoice_id; this.msg='Confirmed & paid.'; this.msgOk=true; setTimeout(()=>location.reload(),700); }
      else { this.msg=(j.message||'Confirm failed') + (j.required?(' (need '+this.money(j.required)+')'):''); this.msgOk=false; }
      this.busy=false;
    },
    async cancelGroup(){
      if (!confirm('Cancel this group?')) return;
      const j = await this.post(this.api + '/cancel', {});
      if (j.success) window.location.href = '<?= root ?>umrah/groups';
      else this.msg = j.message || 'Could not cancel';
    }
  };
}
</script>
