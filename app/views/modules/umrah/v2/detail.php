<?php
// UMRAH v2 — package detail + booking wizard (tier-aware). Mobile-first.
// Expects: $template, $departures, $departureTiers (dep_id => [tiers]),
//          $selectedDepartureId, $plans.
@$SECURE or die('Access Denied!');
$brand = $GLOBALS['app']['business_name'] ?? 'GoGlobia';
$wa = preg_replace('/[^0-9]/', '', (string) ($GLOBALS['app']['contact_phone'] ?? ''));
$waLink = $wa ? ('https://wa.me/' . $wa) : '#';
$fmt = fn($n) => '₦' . number_format((float) $n, 0);
$madinah = (int) ($template['madinah_nights'] ?? 4);
$makkah  = (int) ($template['makkah_nights'] ?? 10);
$slug = $template['slug'] ?? 'normal-umrah-14-day';
$csrf = $_SESSION['csrf_token'] ?? '';
$apiBase = root . 'api/v1/umrah';
$departureTiers = $departureTiers ?? [];

// Departures for the selector (id → dates/city/label).
$depJs = [];
foreach ($departures as $d) {
    $depJs[] = [
        'departure_id' => $d['departure_id'],
        'city'   => $d['origin_city'] ?? 'Kano',
        'label'  => date('d M', strtotime($d['departure_date'])) . ' → ' . date('d M Y', strtotime($d['return_date'])),
        'month'  => $d['month_bucket'],
    ];
}
$plansJs = array_map(fn($p) => ['code' => $p['code'], 'name' => $p['name']], $plans);
?>
<div class="bg-white" x-data="umrahBooking()"
     x-init="init(
        <?= htmlspecialchars(json_encode($depJs), ENT_QUOTES) ?>,
        <?= htmlspecialchars(json_encode($departureTiers, JSON_FORCE_OBJECT), ENT_QUOTES) ?>,
        <?= (int) $selectedDepartureId ?>,
        <?= htmlspecialchars(json_encode($plansJs), ENT_QUOTES) ?>,
        <?= htmlspecialchars(json_encode($departureMedia ?? [], JSON_FORCE_OBJECT), ENT_QUOTES) ?>)">
  <div class="container py-8 grid grid-cols-1 lg:grid-cols-3 gap-8">

    <!-- MAIN -->
    <div class="lg:col-span-2 min-w-0">
      <a href="<?= root ?>umrah/search" class="text-sm text-primary inline-flex items-center gap-1 mb-3"><span class="material-symbols-outlined text-[18px]">arrow_back</span> All departures</a>
      <h1 class="text-2xl md:text-3xl font-extrabold text-gray-900"><?= htmlspecialchars($template['name']) ?></h1>
      <p class="text-gray-600 mt-1">14-Day Umrah · <?= $madinah ?> nights Madinah + <?= $makkah ?> nights Makkah</p>

      <!-- HERO IMAGE + GALLERY -->
      <div class="mt-4" x-show="hero">
        <div class="rounded-2xl overflow-hidden bg-slate-100 aspect-[16/9]">
          <img :src="activeImage || hero" alt="Umrah package" class="w-full h-full object-cover"
               onerror="this.style.visibility='hidden'">
        </div>
        <div class="flex gap-2 mt-2 overflow-x-auto" x-show="gallery.length > 1">
          <template x-for="(g, i) in gallery" :key="i">
            <button type="button" @click="activeImage = g"
              class="w-20 h-14 rounded-lg overflow-hidden border shrink-0"
              :class="(activeImage||hero) === g ? 'border-primary ring-1 ring-primary/40' : 'border-gray-200'">
              <img :src="g" alt="" class="w-full h-full object-cover" onerror="this.style.display='none'">
            </button>
          </template>
        </div>
      </div>

      <!-- Departure selector -->
      <div class="section mt-6">
        <div class="section-header"><h2>Select your departure</h2></div>
        <div class="flex flex-wrap gap-2">
          <template x-for="d in departures" :key="d.departure_id">
            <button type="button" @click="selectDeparture(d.departure_id)"
              :class="d.departure_id === selectedId ? 'bg-primary text-white border-primary' : 'bg-white text-gray-700 border-gray-200'"
              class="border rounded-lg px-3 py-2 text-sm font-medium transition-colors">
              <span x-text="d.label"></span>
            </button>
          </template>
        </div>
      </div>

      <!-- Tier selector -->
      <div class="section">
        <div class="section-header"><h2>Choose your comfort tier</h2></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <template x-for="t in currentTiers" :key="t.departure_tier_id">
            <button type="button" @click="selectTier(t.departure_tier_id)"
              :disabled="t.availability==='sold_out'"
              :class="[
                t.departure_tier_id === selectedTierId ? 'border-primary ring-1 ring-primary/40' : 'border-gray-200',
                t.availability==='sold_out' ? 'opacity-50 cursor-not-allowed' : 'hover:border-primary'
              ]"
              class="text-left border rounded-xl p-4 transition-colors bg-white">
              <div class="flex items-center justify-between">
                <span class="font-bold text-gray-900" x-text="t.tier_label"></span>
                <span class="badge"
                  :class="t.availability==='sold_out' ? 'badge-error' : (t.availability==='limited' ? 'badge-warning' : 'badge-success')"
                  x-text="t.availability==='sold_out' ? 'Sold out' : (t.availability==='limited' ? 'Limited' : 'Available')"></span>
              </div>
              <div class="text-sm text-gray-500 mt-0.5" x-text="t.room_sharing"></div>
              <div class="mt-2 flex items-baseline gap-2">
                <span class="text-xl font-extrabold text-gray-900" x-text="money(t.unit_price)"></span>
                <template x-if="t.regular && t.regular > t.unit_price"><span class="text-sm text-gray-400 line-through" x-text="money(t.regular)"></span></template>
                <span class="text-xs text-gray-500">/ pilgrim</span>
              </div>
              <!-- per-tier inclusions preview -->
              <div class="mt-2 flex flex-wrap gap-1" x-show="t.inclusions && t.inclusions.length">
                <template x-for="(code, i) in (t.inclusions || []).slice(0, 4)" :key="i">
                  <span class="text-[10px] bg-slate-100 text-slate-600 rounded-full px-2 py-0.5" x-text="inclLabel(code)"></span>
                </template>
                <template x-if="(t.inclusions || []).length > 4">
                  <span class="text-[10px] text-slate-400" x-text="'+' + ((t.inclusions||[]).length - 4) + ' more'"></span>
                </template>
              </div>
            </button>
          </template>
        </div>
        <p class="text-xs text-gray-500 mt-3">Prices are per pilgrim. Need a bespoke plan? <a class="text-primary" href="<?= root ?>umrah/customize?departure=<?= (int) $selectedDepartureId ?>">Customize this Umrah</a>.</p>
      </div>

      <!-- Inclusions -->
      <div class="section">
        <div class="section-header"><h2>Everything included</h2></div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
          <?php
          $labels = ['return_flight'=>'Return economy flight','umrah_visa'=>'Saudi Umrah visa','madinah_stay'=>$madinah.' nights Madinah','makkah_stay'=>$makkah.' nights Makkah','airport_transfers'=>'Airport transfers','madinah_makkah_transfer'=>'Madinah → Makkah transport','makkah_ziyarah'=>'Makkah Ziyarah','madinah_ziyarah'=>'Madinah Ziyarah','zain_sim'=>'Zain SIM','goglobia_esim'=>'GoGlobia eSIM','data_1gb'=>'1GB data','discounted_topups'=>'Discounted top-ups','nusuk_assistance'=>'Nusuk assistance','gift_kit'=>'Gift kit','yahaji_ring'=>'Yahaji 3 Pro Ring','group_coordination'=>'Group coordination','whatsapp_support'=>'24/7 support','orientation'=>'Orientation'];
          foreach (($template['inclusions'] ?: array_keys($labels)) as $c): ?>
            <div class="flex items-center gap-2 text-gray-700 text-sm"><span class="material-symbols-outlined text-primary text-[18px]">check_circle</span><?= htmlspecialchars($labels[$c] ?? ucwords(str_replace('_',' ',$c))) ?></div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Rooming + payment plan info -->
      <div class="section">
        <div class="section-header"><h2>Rooming &amp; payment</h2></div>
        <p class="text-sm text-gray-700"><?= htmlspecialchars($template['rooming_note'] ?? 'Shared economy accommodation, normally 4–5 pilgrims per room.') ?></p>
        <p class="text-sm text-gray-700 mt-2">Pay in full, or secure your price with the installment plan (50% now, 25% at T-45, 25% at T-21; the amount due now adjusts automatically as the departure approaches).</p>
      </div>
    </div>

    <!-- STICKY BOOKING CARD -->
    <div class="lg:col-span-1">
      <div id="book" class="card border lg:sticky lg:top-24">
        <h2 class="text-lg font-bold text-gray-900 mb-3">Book this Umrah</h2>

        <template x-if="currentTier">
          <div>
            <div class="text-sm text-gray-600" x-text="currentDeparture ? currentDeparture.label : ''"></div>
            <div class="text-xs text-primary font-semibold mt-0.5" x-text="currentTier.tier_label"></div>
            <div class="mt-1 flex items-baseline gap-2">
              <span class="text-2xl font-extrabold text-gray-900" x-text="money(currentTier.unit_price)"></span>
              <span class="text-xs text-gray-500">per pilgrim</span>
            </div>

            <label class="block text-sm font-medium text-gray-700 mt-4 mb-1">Travellers</label>
            <input type="number" min="1" max="5" class="input" x-model.number="pax" @input="recalc()">
            <p class="text-xs text-gray-400 mt-1">Up to 5 pilgrims per booking. Larger groups: <a class="text-primary" href="<?= root ?>umrah/customize">contact us</a>.</p>

            <label class="block text-sm font-medium text-gray-700 mt-4 mb-1">Payment</label>
            <select class="select" x-model="plan">
              <template x-for="p in plans" :key="p.code"><option :value="p.code" x-text="p.name"></option></template>
            </select>

            <div class="mt-4 border-t border-gray-100 pt-3 text-sm space-y-1">
              <div class="flex justify-between"><span class="text-gray-600">Total</span><span class="font-semibold" x-text="money(total)"></span></div>
            </div>

            <div class="mt-4 space-y-2" x-show="step==='select'">
              <input class="input" placeholder="Full name" x-model="lead.name">
              <input class="input" placeholder="Email" type="email" x-model="lead.email">
              <input class="input" placeholder="WhatsApp / phone" x-model="lead.phone">
            </div>

            <button class="btn w-full justify-center mt-4" :disabled="busy || currentTier.availability==='sold_out'" @click="startBooking()"
              x-text="busy ? 'Please wait…' : 'Continue to secure your seat'"></button>
            <p class="text-xs text-gray-500 mt-2" x-show="msg" x-text="msg"></p>
            <p class="text-xs text-gray-400 mt-2">A 20-minute seat hold is created when you continue. Your price locks once the qualifying payment clears.</p>
          </div>
        </template>

        <template x-if="!currentTier">
          <p class="text-sm text-gray-600">Select a departure and tier to see pricing and book. Or <a class="text-primary" href="<?= htmlspecialchars($waLink) ?>" target="_blank">chat with us</a>.</p>
        </template>
      </div>
    </div>
  </div>
</div>

<script>
function umrahBooking() {
  return {
    apiBase: '<?= $apiBase ?>',
    csrf: '<?= htmlspecialchars($csrf, ENT_QUOTES) ?>',
    departures: [], tiersByDep: {}, plans: [], media: {},
    selectedId: 0, selectedTierId: 0,
    hero: '', gallery: [], activeImage: '',
    pax: 1, plan: 'PP-50-25-25',
    total: 0, busy: false, msg: '', step: 'select',
    lead: { name: '', email: '', phone: '' },
    quote: null, hold: null,
    inclLabels: {
      return_flight:'Return flight', umrah_visa:'Umrah visa', madinah_stay:'Madinah stay',
      makkah_stay:'Makkah stay', airport_transfers:'Airport transfers',
      madinah_makkah_transfer:'Inter-city transport', makkah_ziyarah:'Makkah Ziyarah',
      madinah_ziyarah:'Madinah Ziyarah', zain_sim:'Zain SIM', goglobia_esim:'eSIM',
      data_1gb:'1GB data', discounted_topups:'Top-ups', nusuk_assistance:'Nusuk help',
      gift_kit:'Gift kit', yahaji_ring:'Yahaji Ring', group_coordination:'Group coord.',
      whatsapp_support:'24/7 support', orientation:'Orientation'
    },
    inclLabel(code) { return this.inclLabels[code] || String(code||'').replace(/_/g,' '); },
    init(deps, tiersByDep, sel, plans, media) {
      this.departures = deps || [];
      this.tiersByDep = tiersByDep || {};
      this.plans = plans || [];
      this.media = media || {};
      this.selectedId = sel || (this.departures[0] ? this.departures[0].departure_id : 0);
      this.applyMedia();
      this.pickFirstTier();
      this.recalc();
    },
    applyMedia() {
      const m = this.media[this.selectedId] || this.media[String(this.selectedId)] || {};
      this.hero = m.hero || '';
      this.gallery = (m.gallery && m.gallery.length) ? m.gallery : (this.hero ? [this.hero] : []);
      this.activeImage = this.hero;
    },
    get currentDeparture() { return this.departures.find(d => d.departure_id === this.selectedId) || null; },
    get currentTiers() { return this.tiersByDep[this.selectedId] || []; },
    get currentTier() { return this.currentTiers.find(t => t.departure_tier_id === this.selectedTierId) || null; },
    money(n) { return '₦' + Number(n||0).toLocaleString('en-NG'); },
    pickFirstTier() {
      const list = this.currentTiers;
      const firstBookable = list.find(t => t.availability !== 'sold_out') || list[0];
      this.selectedTierId = firstBookable ? firstBookable.departure_tier_id : 0;
    },
    selectDeparture(id) { this.selectedId = id; this.quote = null; this.hold = null; this.applyMedia(); this.pickFirstTier(); this.recalc(); },
    selectTier(tierId) { this.selectedTierId = tierId; this.quote = null; this.hold = null; this.recalc(); },
    recalc() {
      if (this.pax < 1) this.pax = 1; if (this.pax > 5) this.pax = 5;
      const t = this.currentTier;
      this.total = t ? t.unit_price * this.pax : 0;
    },
    async post(url, body) {
      // Send the CSRF token (header + body) — the v1 API requires it for
      // cookie/session (browser) requests (audit M1).
      const payload = Object.assign({ csrf_token: this.csrf }, body);
      const r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
        body: JSON.stringify(payload)
      });
      return r.json();
    },
    async startBooking() {
      const t = this.currentTier; if (!t) return;
      if (!this.lead.name || (!this.lead.email && !this.lead.phone)) { this.msg = 'Please enter your name and email or phone.'; return; }
      this.busy = true; this.msg = 'Reserving your seat…';
      try {
        const q = await this.post(this.apiBase + '/quotes', { departure_tier_id: t.departure_tier_id, pax: this.pax });
        if (!q.success) { this.msg = q.message || 'Could not create quote'; this.busy = false; return; }
        this.quote = q.quote;
        const h = await this.post(this.apiBase + '/holds', { quote_ref: q.quote.quote_ref });
        if (!h.success) { this.msg = h.message || 'Seats not available'; this.busy = false; return; }
        this.hold = h.hold;
        const parts = (this.lead.name || '').trim().split(' ');
        const b = await this.post(this.apiBase + '/bookings', {
          quote_ref: q.quote.quote_ref, hold_id: h.hold.hold_id, payment_plan: this.plan,
          first_name: parts[0] || '', last_name: parts.slice(1).join(' ') || '',
          email: this.lead.email, phone: this.lead.phone
        });
        if (!b.success) { this.msg = b.message || 'Booking failed'; this.busy = false; return; }
        window.location.href = '<?= root ?>umrah/booking/' + b.booking.booking_ref;
      } catch (e) { this.msg = 'Something went wrong. Please try again.'; this.busy = false; }
    }
  };
}
</script>
