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
// Agent viewing? Show the "Create a group" entry (Phase C).
$isAgentViewer = function_exists('umrah_is_agent') && umrah_is_agent();

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
        <?= htmlspecialchars(json_encode((object) $departureTiers), ENT_QUOTES) ?>,
        <?= (int) $selectedDepartureId ?>,
        <?= htmlspecialchars(json_encode($plansJs), ENT_QUOTES) ?>,
        <?= htmlspecialchars(json_encode((object) ($departureMedia ?? [])), ENT_QUOTES) ?>)">
  <div class="container py-8 grid grid-cols-1 lg:grid-cols-3 gap-8">

    <!-- MAIN -->
    <div class="lg:col-span-2 min-w-0">
      <a href="<?= root ?>umrah/search" class="text-sm text-primary inline-flex items-center gap-1 mb-3"><span class="material-symbols-outlined text-[18px]">arrow_back</span> All departures</a>
      <h1 class="text-2xl md:text-3xl font-extrabold text-gray-900"><?= htmlspecialchars($template['name']) ?></h1>
      <p class="text-gray-600 mt-1">14-Day Umrah · <?= $madinah ?> nights Madinah + <?= $makkah ?> nights Makkah</p>

      <!-- HERO IMAGE + GALLERY (branded placeholder when no image yet) -->
      <div class="mt-4">
        <div class="rounded-2xl overflow-hidden aspect-[16/9] relative"
             style="background:linear-gradient(135deg,#0f766e 0%,#065f46 55%,#064e3b 100%)">
          <template x-if="hero">
            <img :src="activeImage || hero" alt="Umrah package" class="w-full h-full object-cover"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
          </template>
          <div class="absolute inset-0 flex-col items-center justify-center text-white/90 gap-2"
               :class="hero ? 'hidden' : 'flex'" :style="hero ? 'display:none' : ''">
            <span class="material-symbols-outlined text-6xl leading-none">mosque</span>
            <span class="text-sm font-medium tracking-wide"><?= htmlspecialchars($template['name']) ?></span>
          </div>
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

      <!-- Departure: shows the CHOSEN date up front (no re-prompt). "Change date"
           reveals the full list only if the traveller wants a different date. -->
      <div class="section mt-6">
        <div class="section-header">
          <h2 x-text="currentDeparture ? 'Your departure' : 'Select your departure'"></h2>
        </div>

        <!-- Selected date summary -->
        <template x-if="currentDeparture">
          <div class="flex items-center justify-between flex-wrap gap-3 rounded-xl border border-primary/40 bg-primary/5 px-4 py-3">
            <div class="flex items-center gap-2">
              <span class="material-symbols-outlined text-primary">event_available</span>
              <div>
                <div class="font-bold text-gray-900" x-text="currentDeparture.label"></div>
                <div class="text-xs text-gray-500" x-text="currentDeparture.city + ' · ' + currentDeparture.month"></div>
              </div>
            </div>
            <button type="button" class="text-sm text-primary font-medium inline-flex items-center gap-1"
              @click="showDates = !showDates" x-show="departures.length > 1">
              <span class="material-symbols-outlined text-[18px]">edit_calendar</span>
              <span x-text="showDates ? 'Close' : 'Change date'"></span>
            </button>
          </div>
        </template>

        <!-- Full date list: hidden by default when a date is already chosen;
             always shown if nothing is selected yet. -->
        <div class="flex flex-wrap gap-2 mt-3" x-show="showDates || !currentDeparture">
          <template x-for="d in departures" :key="d.departure_id">
            <button type="button" @click="selectDeparture(d.departure_id); showDates = false"
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

            <label class="block text-sm font-medium text-gray-700 mt-4 mb-1">Pilgrims</label>
            <div class="space-y-2">
              <template x-for="row in paxRows" :key="row.key">
                <div class="flex items-center justify-between border rounded-lg px-3 py-2">
                  <div>
                    <div class="text-sm font-medium text-gray-700" x-text="row.label"></div>
                    <div class="text-[11px] text-gray-400" x-text="row.hint"></div>
                  </div>
                  <div class="flex items-center gap-2">
                    <button type="button" class="w-7 h-7 rounded-full border text-gray-600 disabled:opacity-40" @click="dec(row.key)" :disabled="!canDec(row.key)">−</button>
                    <span class="w-5 text-center text-sm" x-text="counts[row.key]"></span>
                    <button type="button" class="w-7 h-7 rounded-full border text-gray-600 disabled:opacity-40" @click="inc(row.key)" :disabled="!canInc()">+</button>
                  </div>
                </div>
              </template>
            </div>
            <p class="text-xs text-gray-400 mt-1">Up to <?= (int) ($GLOBALS['__umrah_customer_max_pax'] ?? 5) ?> pilgrims per booking. Larger groups: <a class="text-primary" href="<?= root ?>umrah/customize">contact us</a>.</p>

            <div class="mt-4 border-t border-gray-100 pt-3 text-sm space-y-1">
              <div class="flex justify-between"><span class="text-gray-600"><span x-text="pax"></span> × <span x-text="money(currentTier.unit_price)"></span></span><span class="font-semibold" x-text="money(total)"></span></div>
            </div>

            <button class="btn w-full justify-center mt-4" :disabled="currentTier.availability==='sold_out'" @click="goToCheckout()">Continue to secure your seat</button>
            <!-- Multi-departure cart: add this departure and keep browsing. -->
            <button type="button" class="btn outline w-full justify-center mt-2" :disabled="cartBusy || currentTier.availability==='sold_out'" @click="addToCart()"
              x-text="cartBusy ? 'Adding…' : 'Add to cart & book more'"></button>
            <?php if ($isAgentViewer): ?>
            <!-- AGENT: create a group from this tier (Phase C). -->
            <button type="button" class="btn secondary w-full justify-center mt-2" :disabled="groupBusy" @click="createGroup()"
              x-text="groupBusy ? 'Creating group…' : 'Create a group (agents)'"></button>
            <?php endif; ?>
            <p class="text-xs text-gray-500 mt-2" x-show="msg" x-text="msg"></p>
            <p class="text-xs text-gray-400 mt-2">A 20-minute seat hold is created when you continue. Your price locks once the qualifying payment clears. Booking for several people/dates? Add each to the cart and pay together.</p>
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
    selectedId: 0, selectedTierId: 0, showDates: false,
    hero: '', gallery: [], activeImage: '',
    maxPax: <?= (int) ($GLOBALS['__umrah_customer_max_pax'] ?? 5) ?>,
    counts: { adults: 1, children: 0, infants: 0 },
    paxRows: [
      { key: 'adults',   label: 'Adults',   hint: '12+ years' },
      { key: 'children', label: 'Children', hint: '2–11 years' },
      { key: 'infants',  label: 'Infants',  hint: 'under 2' },
    ],
    total: 0, busy: false, cartBusy: false, groupBusy: false, msg: '', step: 'select',
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
      // Prefill pilgrim counts from the URL (?adults=&children=&infants=), as set
      // by the search/results page, clamped to the online cap.
      const qs = new URLSearchParams(window.location.search);
      const a = Math.max(1, parseInt(qs.get('adults')   || '1', 10));
      const c = Math.max(0, parseInt(qs.get('children') || '0', 10));
      const inf = Math.max(0, parseInt(qs.get('infants') || '0', 10));
      this.counts = { adults: a, children: c, infants: inf };
      while (this.pax > this.maxPax && this.counts.infants > 0)  { this.counts.infants--; }
      while (this.pax > this.maxPax && this.counts.children > 0) { this.counts.children--; }
      this.applyMedia();
      this.pickFirstTier();
      this.recalc();
    },
    get pax() { return this.counts.adults + this.counts.children + this.counts.infants; },
    canInc() { return this.pax < this.maxPax; },
    canDec(k) { return k === 'adults' ? this.counts.adults > 1 : this.counts[k] > 0; },
    inc(k) { if (this.canInc()) { this.counts[k]++; this.recalc(); } },
    dec(k) { if (this.canDec(k)) { this.counts[k]--; this.recalc(); } },
    goToCheckout() {
      const t = this.currentTier; if (!t) return;
      const q = new URLSearchParams();
      q.set('departure_tier_id', t.departure_tier_id);
      q.set('adults', this.counts.adults);
      q.set('children', this.counts.children);
      q.set('infants', this.counts.infants);
      window.location.href = '<?= root ?>umrah/checkout?' + q.toString();
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
    selectDeparture(id) { this.selectedId = id; this.applyMedia(); this.pickFirstTier(); this.recalc(); },
    selectTier(tierId) { this.selectedTierId = tierId; this.recalc(); },
    recalc() {
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
    async addToCart() {
      const t = this.currentTier; if (!t) return;
      this.cartBusy = true; this.msg = '';
      try {
        const j = await this.post(this.apiBase + '/cart/add', { departure_tier_id: t.departure_tier_id, pax: this.pax });
        if (j.success) { window.location.href = '<?= root ?>umrah/cart'; }
        else { this.msg = j.message || 'Could not add to cart'; this.cartBusy = false; }
      } catch (e) { this.msg = 'Something went wrong.'; this.cartBusy = false; }
    },
    async createGroup() {
      const t = this.currentTier; if (!t) return;
      this.groupBusy = true; this.msg = '';
      try {
        const j = await this.post('<?= root ?>api/v1/umrah/groups', { departure_tier_id: t.departure_tier_id });
        if (j.success) { window.location.href = '<?= root ?>umrah/groups/' + j.group_id; }
        else { this.msg = j.message || 'Could not create group'; this.groupBusy = false; }
      } catch (e) { this.msg = 'Something went wrong.'; this.groupBusy = false; }
    }
  };
}
</script>
