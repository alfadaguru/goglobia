<?php
// UMRAH v2 — flight-style checkout. Review selection -> per-pilgrim details
// (Adults/Children/Infants) + contact -> one Confirm & Pay that posts to
// /api/v1/umrah/checkout (server quotes+holds+books+writes travellers) and then
// redirects to the invoice/payment page. Server is the sole price authority.
// Expects: $dt, $dep, $tier, $tpl, $priced, $soldOut, $adults, $children,
//          $infants, $maxPax, $plans, $countries, $umrahCsrf, $leadEmail, $leadPhone.
@$SECURE or die('Access Denied!');
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
$unit = (float) ($priced['unit'] ?? 0);
$currency = (string) ($priced['currency'] ?? 'NGN');
$regular = $priced['promo']['regular'] ?? null;
$depLabel = date('d M Y', strtotime($dep['departure_date'])) . ' → ' . date('d M Y', strtotime($dep['return_date']));
$tierLabel = $tier['public_label'] ?: $tier['name'];
$packageName = $tpl['name'] ?? 'Umrah package';
$backUrl = root . 'umrah/packages/' . rawurlencode((string) ($tpl['slug'] ?? '')) . '?departure=' . (int) $dep['id'];
$countryJs = array_map(fn($c) => ['iso' => $c['iso'], 'name' => $c['nicename']], $countries);
$plansJs = array_map(fn($p) => ['code' => $p['code'], 'name' => $p['name']], $plans);
// Build the pax slot descriptors (type + default title) the component starts from.
$slotsJs = [];
for ($i = 0; $i < $adults; $i++)   { $slotsJs[] = ['pax_type' => 'adult']; }
for ($i = 0; $i < $children; $i++) { $slotsJs[] = ['pax_type' => 'child']; }
for ($i = 0; $i < $infants; $i++)  { $slotsJs[] = ['pax_type' => 'infant']; }
?>
<style>[x-cloak]{display:none!important}</style>
<div class="bg-slate-50 min-h-screen" x-data="umrahCheckout(
      <?= htmlspecialchars(json_encode($slotsJs), ENT_QUOTES) ?>,
      <?= htmlspecialchars(json_encode($countryJs), ENT_QUOTES) ?>,
      <?= htmlspecialchars(json_encode($plansJs), ENT_QUOTES) ?>,
      { unit: <?= $unit ?>, dtId: <?= (int) $dt['id'] ?>,
        email: '<?= htmlspecialchars($leadEmail, ENT_QUOTES) ?>', phone: '<?= htmlspecialchars($leadPhone, ENT_QUOTES) ?>' }
    )">
  <div class="container py-6">

    <div class="flex items-center justify-between mb-4">
      <div>
        <h1 class="text-2xl font-bold text-slate-900">Secure your Umrah</h1>
        <p class="text-sm text-slate-500"><?= htmlspecialchars($packageName) ?> · <?= htmlspecialchars($tierLabel) ?></p>
      </div>
      <a href="<?= htmlspecialchars($backUrl) ?>" class="text-sm text-primary inline-flex items-center gap-1">
        <span class="material-symbols-outlined text-[18px]">arrow_back</span> Back to package
      </a>
    </div>

    <?php if ($soldOut): ?>
      <div class="alert-warning"><span class="material-icon material-symbols-outlined">event_busy</span>
        <p>This departure-tier is sold out. <a class="text-primary" href="<?= root ?>umrah">Choose another departure</a>.</p></div>
    <?php else: ?>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

      <!-- LEFT: pilgrim forms + contact -->
      <div class="lg:col-span-2 space-y-4">

        <!-- Pilgrim count adjuster (A/C/I) -->
        <div class="card border">
          <div class="flex items-center justify-between mb-3">
            <h2 class="font-bold text-slate-900">Pilgrims</h2>
            <span class="text-xs text-slate-400" x-text="slots.length + ' of <?= (int) $maxPax ?> max'"></span>
          </div>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <template x-for="row in paxRows" :key="row.key">
              <div class="flex items-center justify-between border rounded-lg px-3 py-2">
                <div>
                  <div class="text-sm font-medium text-slate-700" x-text="row.label"></div>
                  <div class="text-[11px] text-slate-400" x-text="row.hint"></div>
                </div>
                <div class="flex items-center gap-2">
                  <button type="button" class="w-7 h-7 rounded-full border text-slate-600 disabled:opacity-40" @click="dec(row.key)" :disabled="!canDec(row.key)">−</button>
                  <span class="w-5 text-center text-sm" x-text="counts[row.key]"></span>
                  <button type="button" class="w-7 h-7 rounded-full border text-slate-600 disabled:opacity-40" @click="inc(row.key)" :disabled="!canInc()">+</button>
                </div>
              </div>
            </template>
          </div>
          <p class="text-[11px] text-slate-400 mt-2">Every pilgrim pays the same tier price. Children and infants are captured for the visa manifest. Up to <?= (int) $maxPax ?> pilgrims per online booking.</p>
        </div>

        <!-- Per-pilgrim identity -->
        <template x-for="(p, idx) in slots" :key="idx">
          <div class="card border">
            <div class="flex items-center justify-between mb-3">
              <h3 class="font-semibold text-slate-900">
                <span x-text="idx === 0 ? 'Lead pilgrim' : ('Pilgrim ' + (idx+1))"></span>
                <span class="text-[11px] font-normal text-slate-400 ml-1" x-text="'(' + p.pax_type + ')'"></span>
              </h3>
              <span class="badge" x-show="p.scanned" style="background:#dcfce7;color:#166534">Details read from passport</span>
            </div>

            <!-- PASSPORT-FIRST: upload the passport, we read the details, you
                 confirm. Manual fields below remain editable as a fallback. -->
            <div class="rounded-xl border border-dashed border-primary/40 bg-primary/5 p-3 mb-3">
              <div class="flex items-center justify-between flex-wrap gap-2">
                <div class="flex items-center gap-2 min-w-0">
                  <span class="material-symbols-outlined text-primary">document_scanner</span>
                  <div class="min-w-0">
                    <div class="text-sm font-medium text-slate-800">Upload passport (photo page)</div>
                    <div class="text-[11px] text-slate-500" x-text="p.passportName ? p.passportName : 'We read the name, DOB, passport no. & expiry so you just confirm. Stored for your visa.'"></div>
                  </div>
                </div>
                <label class="btn btn-sm cursor-pointer shrink-0">
                  <span class="material-symbols-outlined text-[18px]">upload</span>
                  <span x-text="p.scanning ? 'Reading…' : (p.passportName ? 'Replace' : 'Upload passport')"></span>
                  <input type="file" class="hidden" accept="image/jpeg,image/png,image/webp" @change="scanPassport(idx, $event)">
                </label>
              </div>
              <p class="text-[11px] mt-1" :class="p.scanOk ? 'text-green-600' : 'text-rose-600'" x-text="p.scanMsg"></p>
              <p class="text-[11px] text-slate-400 mt-1" x-show="!p.scanning && !p.passportName">Prefer to type it in? Just fill the fields below.</p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <select class="select" x-model="p.title">
                <option value="">Title</option><option>Mr</option><option>Mrs</option><option>Ms</option><option>Miss</option><option>Mast</option><option>Dr</option>
              </select>
              <input class="input" placeholder="First name (as passport)" x-model="p.first_name">
              <input class="input" placeholder="Last name (as passport)" x-model="p.last_name">
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-3">
              <select class="select" x-model="p.gender">
                <option value="">Gender *</option><option value="male">Male</option><option value="female">Female</option>
              </select>
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Date of birth *</label>
                <input type="date" class="input w-full" x-model="p.dob">
              </div>
              <select class="select" x-model="p.nationality">
                <option value="">Nationality *</option>
                <template x-for="c in countries" :key="c.iso"><option :value="c.iso" x-text="c.name"></option></template>
              </select>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
              <input class="input" placeholder="Passport number *" x-model="p.passport_number">
              <div>
                <label class="block text-[11px] text-slate-500 mb-0.5">Passport expiry *</label>
                <input type="date" class="input w-full" x-model="p.passport_expiry">
              </div>
            </div>
          </div>
        </template>

        <!-- Contact -->
        <div class="card border">
          <h3 class="font-semibold text-slate-900 mb-3">Contact details</h3>
          <p class="text-xs text-slate-500 mb-3">We send your booking confirmation, visa updates and payment receipts here.</p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <input class="input" type="email" placeholder="Email address" x-model="contact.email">
            <input class="input" placeholder="WhatsApp / phone" x-model="contact.phone">
          </div>
        </div>
      </div>

      <!-- RIGHT: sticky summary + Confirm & Pay -->
      <div class="lg:col-span-1">
        <div class="card border lg:sticky lg:top-24">
          <h2 class="text-lg font-bold text-slate-900 mb-3">Order summary</h2>
          <div class="text-sm space-y-1">
            <div class="flex justify-between"><span class="text-slate-500">Package</span><span class="font-medium text-right"><?= htmlspecialchars($packageName) ?></span></div>
            <div class="flex justify-between"><span class="text-slate-500">Tier</span><span class="font-medium"><?= htmlspecialchars($tierLabel) ?></span></div>
            <div class="flex justify-between"><span class="text-slate-500">Departure</span><span class="font-medium text-right"><?= htmlspecialchars($depLabel) ?></span></div>
            <div class="flex justify-between"><span class="text-slate-500">City</span><span class="font-medium"><?= htmlspecialchars($dep['origin_city'] ?: 'Kano') ?></span></div>
            <div class="flex justify-between"><span class="text-slate-500">Room</span><span class="font-medium"><?= htmlspecialchars($tier['room_sharing'] ?? '') ?></span></div>
          </div>

          <div class="mt-3 border-t pt-3 text-sm space-y-1">
            <div class="flex justify-between">
              <span class="text-slate-500"><span x-text="slots.length"></span> × <?= $fmt($unit) ?></span>
              <span class="font-semibold" x-text="money(unit * slots.length)"></span>
            </div>
            <div class="flex justify-between text-base mt-1">
              <span class="font-bold text-slate-900">Total</span>
              <span class="font-extrabold text-slate-900" x-text="money(unit * slots.length)"></span>
            </div>
            <p class="text-[11px] text-slate-400">Price per pilgrim. Currency: <?= htmlspecialchars($currency) ?>.</p>
          </div>

          <label class="block text-sm font-medium text-slate-700 mt-4 mb-1">Payment</label>
          <select class="select w-full" x-model="plan">
            <template x-for="pl in plans" :key="pl.code"><option :value="pl.code" x-text="pl.name"></option></template>
          </select>
          <p class="text-[11px] text-slate-400 mt-1">Pay in full or lock your price with an installment plan — the amount due now adjusts automatically.</p>

          <button class="btn w-full justify-center mt-4" :disabled="busy" @click="confirmAndPay()"
            x-text="busy ? 'Processing…' : 'Confirm & Pay'"></button>
          <p class="text-xs mt-2" :class="msgOk ? 'text-green-600' : 'text-rose-600'" x-text="msg"></p>
          <p class="text-[11px] text-slate-400 mt-2">A seat hold is created when you confirm. Your price locks once the qualifying payment clears. You can upload passports now or after payment.</p>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<script>
function umrahCheckout(initialSlots, countries, plans, opts) {
  const mk = (s) => ({
    pax_type: s.pax_type || 'adult',
    title: '', first_name: '', last_name: '', gender: '',
    dob: '', nationality: '', passport_number: '', passport_expiry: '',
    // passport-first state
    scanning: false, scanOk: false, scanMsg: '', scanned: false,
    passportName: '', passportFile: null
  });
  return {
    unit: opts.unit || 0,
    dtId: opts.dtId || 0,
    maxPax: <?= (int) $maxPax ?>,
    countries: countries || [],
    plans: plans || [],
    plan: (plans && plans[0] ? plans[0].code : 'PP-50-25-25'),
    csrf: '<?= htmlspecialchars($umrahCsrf, ENT_QUOTES) ?>',
    apiBase: '<?= root ?>api/v1/umrah',
    slots: (initialSlots || [{ pax_type: 'adult' }]).map(mk),
    counts: {
      adults: (initialSlots || []).filter(s => (s.pax_type||'adult') === 'adult').length || 1,
      children: (initialSlots || []).filter(s => s.pax_type === 'child').length,
      infants: (initialSlots || []).filter(s => s.pax_type === 'infant').length,
    },
    paxRows: [
      { key: 'adults',   label: 'Adults',   hint: '12+ years' },
      { key: 'children', label: 'Children', hint: '2–11 years' },
      { key: 'infants',  label: 'Infants',  hint: 'under 2' },
    ],
    contact: { email: opts.email || '', phone: opts.phone || '' },
    busy: false, msg: '', msgOk: false,
    money(n) { return '₦' + Number(n||0).toLocaleString('en-NG'); },
    total() { return this.counts.adults + this.counts.children + this.counts.infants; },
    canInc() { return this.total() < this.maxPax; },
    canDec(k) { return k === 'adults' ? this.counts.adults > 1 : this.counts[k] > 0; },
    inc(k) { if (this.canInc()) { this.counts[k]++; this.rebuildSlots(); } },
    dec(k) { if (this.canDec(k)) { this.counts[k]--; this.rebuildSlots(); } },
    rebuildSlots() {
      // Preserve already-entered pilgrims by type order (adults, children, infants).
      const byType = { adult: [], child: [], infant: [] };
      this.slots.forEach(s => { (byType[s.pax_type] || byType.adult).push(s); });
      const out = [];
      const take = (type, n) => {
        for (let i = 0; i < n; i++) { out.push(byType[type][i] || mk({ pax_type: type })); }
      };
      take('adult', this.counts.adults);
      take('child', this.counts.children);
      take('infant', this.counts.infants);
      this.slots = out;
    },
    async scanPassport(idx, ev) {
      const file = ev.target.files && ev.target.files[0];
      if (!file) { return; }
      const p = this.slots[idx];
      p.passportFile = file; p.passportName = file.name;   // keep for upload after booking
      p.scanning = true; p.scanMsg = 'Reading passport…'; p.scanOk = true;
      try {
        const fd = new FormData();
        fd.append('csrf_token', this.csrf);
        fd.append('passport_image', file);
        const r = await fetch(this.apiBase + '/passport/extract', {
          method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf }, body: fd
        });
        const j = await r.json();
        if (j.success && j.fields) {
          const f = j.fields;
          // Prefill; the pilgrim still sees + can correct every field below.
          if (f.first_name) p.first_name = f.first_name;
          if (f.last_name) p.last_name = f.last_name;
          if (f.gender) p.gender = f.gender;
          if (f.dob) p.dob = f.dob;
          if (f.nationality) p.nationality = f.nationality;
          if (f.passport_number) p.passport_number = f.passport_number;
          if (f.passport_expiry) p.passport_expiry = f.passport_expiry;
          p.scanned = true; p.scanOk = true;
          p.scanMsg = 'Details read — please check they match your passport, then continue.';
          if (j.warnings && j.warnings.length) { p.scanMsg += ' (' + j.warnings.join('; ') + ')'; }
        } else {
          // Graceful fallback — keep the file, ask them to fill/confirm manually.
          p.scanOk = false;
          p.scanMsg = (j.message || 'Could not read the passport.') + ' Please enter the details below.';
        }
      } catch (e) {
        p.scanOk = false; p.scanMsg = 'Scan failed — please enter the details below.';
      }
      p.scanning = false; ev.target.value = '';
    },
    validate() {
      const req = ['first_name','last_name','gender','dob','nationality','passport_number','passport_expiry'];
      for (let i = 0; i < this.slots.length; i++) {
        const p = this.slots[i];
        for (const f of req) {
          if (!String(p[f] || '').trim()) return 'Pilgrim ' + (i+1) + ': ' + f.replace(/_/g,' ') + ' is required';
        }
      }
      if (!this.contact.email && !this.contact.phone) return 'Enter a contact email or phone';
      return '';
    },
    async confirmAndPay() {
      const err = this.validate();
      if (err) { this.msg = err; this.msgOk = false; return; }
      this.busy = true; this.msg = 'Reserving your seats…'; this.msgOk = true;
      try {
        const body = {
          csrf_token: this.csrf,
          departure_tier_id: this.dtId,
          adults: this.counts.adults, children: this.counts.children, infants: this.counts.infants,
          payment_plan: this.plan,
          email: this.contact.email, phone: this.contact.phone,
          pilgrims: this.slots.map(p => ({
            pax_type: p.pax_type, title: p.title,
            first_name: p.first_name, last_name: p.last_name, gender: p.gender,
            dob: p.dob, nationality: p.nationality,
            passport_number: p.passport_number, passport_expiry: p.passport_expiry
          }))
        };
        const r = await fetch(this.apiBase + '/checkout', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
          body: JSON.stringify(body)
        });
        const j = await r.json();
        if (j.success && j.pay_url) {
          // Store each pilgrim's passport file against its traveller for the visa
          // (best-effort; a failed upload never blocks payment — it can be
          // re-uploaded from the booking page). traveller_ids is index-aligned.
          const ids = j.traveller_ids || [];
          this.msg = 'Saving passports…';
          await Promise.all(this.slots.map(async (p, i) => {
            const tid = ids[i];
            if (!tid || !p.passportFile) return;
            try {
              const fd = new FormData();
              fd.append('csrf_token', this.csrf);
              fd.append('doc_type', 'passport');
              fd.append('file', p.passportFile);
              await fetch(this.apiBase + '/travellers/' + tid + '/documents', {
                method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf }, body: fd
              });
            } catch (e) { /* non-blocking */ }
          }));
          window.location.href = j.pay_url; return;
        }
        this.msg = j.message || 'Could not complete checkout. Please try again.';
        this.msgOk = false; this.busy = false;
      } catch (e) {
        this.msg = 'Network error. Please try again.'; this.msgOk = false; this.busy = false;
      }
    }
  };
}
</script>
