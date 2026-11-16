<?php @$SECURE or die('Access Denied!'); ?>
<?php
// CRUISE DETAIL (own-inventory — Phase 1). Full detail for one cruise ($cruise, set
// by /cruise/{slug}). Booking/payment is a later phase, so the primary CTA is marked
// "coming soon" rather than linking to a non-existent checkout — honest, no dead end.
$c = isset($cruise) && is_array($cruise) ? $cruise : [];
$fmt = function ($amt, $ccy) {
    return htmlspecialchars((string) $ccy) . ' ' . number_format((float) $amt, 2);
};
$img = trim((string) ($c['img'] ?? ''));
$imgUrl = $img !== '' ? (preg_match('#^https?://#i', $img) ? $img : rtrim((string) root, '/') . '/' . ltrim($img, '/')) : '';
$stars = max(0, min(5, (int) ($c['stars'] ?? 0)));
$list = function ($text) {
    // Split on newlines or semicolons only — NOT commas, since itinerary/inclusion
    // lines legitimately contain commas ("Nassau, Bahamas").
    $text = trim((string) $text);
    if ($text === '') return [];
    $parts = preg_split('/\r\n|\r|\n|;/', $text);
    return array_values(array_filter(array_map('trim', $parts), fn($s) => $s !== ''));
};
?>
<section class="max-w-5xl mx-auto px-4 py-6">

  <nav class="text-sm text-gray-500 mb-4 flex items-center gap-1">
    <a href="<?= root ?>#cruises" class="text-blue-600 hover:underline">Cruises</a>
    <span class="material-symbols-outlined text-[16px]">chevron_right</span>
    <span class="text-gray-700 truncate"><?= htmlspecialchars((string) ($c['name'] ?? 'Cruise')) ?></span>
  </nav>

  <div class="grid gap-6 lg:grid-cols-3">
    <!-- MAIN -->
    <div class="lg:col-span-2 space-y-5">
      <div class="rounded-2xl overflow-hidden bg-blue-50 h-56 sm:h-72">
        <?php if ($imgUrl !== ''): ?>
          <img src="<?= htmlspecialchars($imgUrl) ?>" alt="<?= htmlspecialchars((string) ($c['name'] ?? '')) ?>"
               class="w-full h-full object-cover"
               onerror="this.parentElement.innerHTML='<div class=\'w-full h-full flex items-center justify-center\'><span class=\'material-symbols-outlined text-blue-300\' style=\'font-size:56px\'>directions_boat</span></div>'">
        <?php else: ?>
          <div class="w-full h-full flex items-center justify-center"><span class="material-symbols-outlined text-blue-300" style="font-size:56px">directions_boat</span></div>
        <?php endif; ?>
      </div>

      <div>
        <?php if (!empty($c['cruise_line'])): ?>
          <div class="text-xs font-medium text-blue-600 uppercase tracking-wide"><?= htmlspecialchars((string) $c['cruise_line']) ?><?php if (!empty($c['ship_name'])): ?> · <?= htmlspecialchars((string) $c['ship_name']) ?><?php endif; ?></div>
        <?php endif; ?>
        <h1 class="text-2xl font-bold text-gray-900 mt-1"><?= htmlspecialchars((string) ($c['name'] ?? 'Cruise')) ?></h1>
        <?php if ($stars > 0): ?><div class="text-amber-500 mt-1"><?= str_repeat('★', $stars) . str_repeat('☆', 5 - $stars) ?></div><?php endif; ?>
        <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm text-gray-600 mt-3">
          <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-blue-500">location_on</span><?= htmlspecialchars((string) ($c['destination'] ?? '')) ?></span>
          <?php if (!empty($c['departure_port'])): ?><span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-blue-500">anchor</span>From <?= htmlspecialchars((string) $c['departure_port']) ?></span><?php endif; ?>
          <span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-blue-500">dark_mode</span><?= (int) ($c['nights'] ?? 0) ?> nights</span>
          <?php if (!empty($c['departure_date'])): ?><span class="inline-flex items-center gap-1"><span class="material-symbols-outlined text-[18px] text-blue-500">event</span><?= htmlspecialchars(date('d M Y', strtotime((string) $c['departure_date']))) ?></span><?php endif; ?>
        </div>
      </div>

      <?php if (!empty($c['description'])): ?>
        <div><h2 class="font-semibold text-gray-900 mb-1">About this cruise</h2>
          <p class="text-sm text-gray-700 leading-relaxed"><?= nl2br(htmlspecialchars((string) $c['description'])) ?></p></div>
      <?php endif; ?>

      <?php $itin = $list($c['itinerary'] ?? ''); if ($itin): ?>
        <div><h2 class="font-semibold text-gray-900 mb-2">Itinerary</h2>
          <ol class="space-y-1 text-sm text-gray-700">
            <?php foreach ($itin as $i => $stop): ?>
              <li class="flex gap-2"><span class="shrink-0 w-6 h-6 rounded-full bg-blue-50 text-blue-600 text-xs font-semibold flex items-center justify-center"><?= $i + 1 ?></span><span><?= htmlspecialchars($stop) ?></span></li>
            <?php endforeach; ?>
          </ol></div>
      <?php endif; ?>

      <div class="grid sm:grid-cols-2 gap-5">
        <?php $inc = $list($c['inclusions'] ?? ''); if ($inc): ?>
          <div><h2 class="font-semibold text-gray-900 mb-2">What's included</h2>
            <ul class="space-y-1 text-sm text-gray-700"><?php foreach ($inc as $x): ?><li class="flex items-center gap-2"><span class="material-symbols-outlined text-[18px] text-green-600">check_circle</span><?= htmlspecialchars($x) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php $exc = $list($c['exclusions'] ?? ''); if ($exc): ?>
          <div><h2 class="font-semibold text-gray-900 mb-2">Not included</h2>
            <ul class="space-y-1 text-sm text-gray-700"><?php foreach ($exc as $x): ?><li class="flex items-center gap-2"><span class="material-symbols-outlined text-[18px] text-gray-400">cancel</span><?= htmlspecialchars($x) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
      </div>

      <?php if (!empty($c['cancellation_policy'])): ?>
        <div><h2 class="font-semibold text-gray-900 mb-1">Cancellation policy</h2>
          <p class="text-sm text-gray-600"><?= nl2br(htmlspecialchars((string) $c['cancellation_policy'])) ?></p></div>
      <?php endif; ?>
    </div>

    <!-- BOOKING SIDEBAR -->
    <aside class="lg:col-span-1">
      <?php
        $__ccy      = (string) ($c['display_currency'] ?? $c['currency'] ?? 'NGN');
        $__adultP   = (float) ($c['display_price'] ?? $c['price'] ?? 0);
        $__childRaw = (float) ($c['child_price'] ?? 0);
        // Child price shown in the same display terms: scale by the markup/convert ratio
        // the listing already applied to the adult price (keeps the two consistent).
        $__ratio    = ((float) ($c['price'] ?? 0) > 0) ? ($__adultP / (float) $c['price']) : 1.0;
        $__childP   = round($__childRaw * $__ratio, 2);
        $__maxG     = max(1, (int) ($c['max_guests'] ?? 4));
      ?>
      <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm lg:sticky lg:top-24"
           x-data='cruiseBooking(<?= json_encode([
             "cruiseId"  => (int) ($c["id"] ?? 0),
             "ccy"       => $__ccy,
             "adultP"    => $__adultP,
             "childP"    => $__childP,
             "maxGuests" => $__maxG,
             "csrf"      => class_exists("CSRF") ? CSRF::getToken() : "",
             "submitUrl" => root . "api/cruise/booking/submit",
           ], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>

        <div class="text-xs text-gray-400">from</div>
        <div class="text-3xl font-bold text-gray-900"><?= $fmt($__adultP, $__ccy) ?></div>
        <div class="text-xs text-gray-400 mb-4">per guest<?php if (!empty($c['refundable'])): ?> · <span class="text-green-600 font-medium">refundable</span><?php endif; ?></div>

        <!-- STEP 1: show the "Book" button; STEP 2: reveal the guest form inline. -->
        <template x-if="!open">
          <button type="button" class="btn w-full" @click="open = true">
            <span class="material-symbols-outlined text-[18px]">sailing</span> Book this cruise
          </button>
        </template>

        <template x-if="open">
          <form class="space-y-3" @submit.prevent="submit()">
            <div class="grid grid-cols-2 gap-2">
              <div><label class="block text-xs text-gray-500 mb-1">Guests</label>
                <select x-model.number="adults" class="input w-full text-sm">
                  <template x-for="n in maxGuests" :key="n"><option :value="n" x-text="n + ' adult' + (n>1?'s':'')"></option></template>
                </select>
              </div>
              <div><label class="block text-xs text-gray-500 mb-1">Children</label>
                <select x-model.number="children" class="input w-full text-sm">
                  <template x-for="n in (maxGuests - adults + 1)" :key="n"><option :value="n-1" x-text="(n-1)"></option></template>
                </select>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-2">
              <div><label class="block text-xs text-gray-500 mb-1">First name *</label><input type="text" x-model="form.first_name" required class="input w-full text-sm"></div>
              <div><label class="block text-xs text-gray-500 mb-1">Last name *</label><input type="text" x-model="form.last_name" required class="input w-full text-sm"></div>
            </div>
            <div><label class="block text-xs text-gray-500 mb-1">Email *</label><input type="email" x-model="form.email" required class="input w-full text-sm"></div>
            <div class="grid grid-cols-2 gap-2">
              <div><label class="block text-xs text-gray-500 mb-1">Phone</label><input type="tel" x-model="form.phone" class="input w-full text-sm"></div>
              <div><label class="block text-xs text-gray-500 mb-1">Country</label><input type="text" x-model="form.country" class="input w-full text-sm"></div>
            </div>
            <div class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2 text-sm">
              <span class="text-gray-600">Total</span><span class="font-semibold" x-text="totalLabel()"></span>
            </div>
            <label class="flex items-start gap-2 text-xs text-gray-600">
              <input type="checkbox" x-model="form.terms_accepted" required class="mt-0.5">
              <span>I agree to the <a href="<?= root ?>terms-and-conditions" class="text-blue-600 hover:underline" target="_blank">Terms &amp; Conditions</a>.</span>
            </label>
            <p x-show="error" x-text="error" class="text-xs text-red-600"></p>
            <div class="flex gap-2">
              <button type="button" class="btn ghost text-sm flex-1" @click="open = false" :disabled="busy">Back</button>
              <button type="submit" class="btn text-sm flex-1" :disabled="busy">
                <span x-show="!busy">Continue to pay</span><span x-show="busy">Please wait…</span>
              </button>
            </div>
          </form>
        </template>

        <ul class="mt-5 space-y-2 text-xs text-gray-500">
          <li class="flex items-center gap-2"><span class="material-symbols-outlined text-[16px] text-blue-500">verified</span>Hand-picked sailing</li>
          <li class="flex items-center gap-2"><span class="material-symbols-outlined text-[16px] text-blue-500">support_agent</span>24/7 support</li>
          <li class="flex items-center gap-2"><span class="material-symbols-outlined text-[16px] text-blue-500">payments</span>Transparent pricing</li>
        </ul>
      </div>
    </aside>
  </div>

</section>

<script>
function cruiseBooking(cfg) {
  return {
    open: false, busy: false, error: '',
    cruiseId: cfg.cruiseId, ccy: cfg.ccy, adultP: cfg.adultP, childP: cfg.childP,
    maxGuests: cfg.maxGuests, csrf: cfg.csrf, submitUrl: cfg.submitUrl,
    adults: 1, children: 0,
    form: { first_name: '', last_name: '', email: '', phone: '', country: '', terms_accepted: false },
    total() {
      // Keep children within capacity if adults grows.
      if (this.adults + this.children > this.maxGuests) { this.children = Math.max(0, this.maxGuests - this.adults); }
      return (this.adultP * this.adults) + (this.childP * this.children);
    },
    totalLabel() {
      const n = this.total();
      return this.ccy + ' ' + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    async submit() {
      if (this.busy) return;
      this.error = '';
      if (!this.form.first_name || !this.form.last_name || !this.form.email) { this.error = 'Please fill in your name and email.'; return; }
      if (!this.form.terms_accepted) { this.error = 'Please accept the terms and conditions.'; return; }
      this.busy = true;
      try {
        const res = await fetch(this.submitUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
          body: JSON.stringify({
            cruise_id: this.cruiseId,
            adults: Math.max(1, parseInt(this.adults || 1, 10)),
            children: Math.max(0, parseInt(this.children || 0, 10)),
            first_name: this.form.first_name, last_name: this.form.last_name,
            email: this.form.email, phone: this.form.phone, country: this.form.country,
            terms_accepted: this.form.terms_accepted ? 1 : 0,
            csrf_token: this.csrf
          })
        });
        const j = await res.json().catch(() => ({}));
        if (j && (j.success || j.status) && (j.redirect || j.redirect_url)) {
          window.location.href = j.redirect || j.redirect_url; return;
        }
        this.error = (j && j.message) ? j.message : 'Could not start your booking. Please try again.';
      } catch (e) {
        this.error = 'Network error. Please try again.';
      } finally {
        this.busy = false;
      }
    }
  };
}
</script>
