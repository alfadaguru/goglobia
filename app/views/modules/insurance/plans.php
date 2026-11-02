<?php @$SECURE or die('Access Denied!'); ?>
<?php
// PAID travel-insurance storefront (own-inventory, customer-flow #12). Lists the
// admin-defined active plans from `insurance_plans` ($__insurancePlans, set by the
// /insurance/plans route) and lets the customer buy one: pick a plan -> fill
// traveller details -> POST /api/insurance/booking/submit (price re-derived
// server-side) -> redirect to /invoice/insurance/{id} to pay. When there are no
// active plans, show an honest "coming soon" + the free claim link.
$plans    = isset($__insurancePlans) && is_array($__insurancePlans) ? $__insurancePlans : [];
$hasPlans = count($plans) > 0;
$csrf     = class_exists('CSRF') ? CSRF::getToken() : '';
$ccyFmt   = function ($amt, $ccy) {
    $n = number_format((float) $amt, 2);
    return htmlspecialchars((string) $ccy) . ' ' . $n;
};
?>
<section class="max-w-5xl mx-auto px-4 py-10"
         x-data='insuranceStore(<?= json_encode([
            "plans" => array_map(function ($p) {
                return [
                    "id"              => (int) $p["id"],
                    "name"            => (string) $p["name"],
                    "provider_label"  => (string) ($p["provider_label"] ?? ""),
                    "currency"        => (string) ($p["currency"] ?? "NGN"),
                    "price"           => (float) $p["price"],
                    "coverage_amount" => $p["coverage_amount"] !== null ? (float) $p["coverage_amount"] : null,
                    "duration_days"   => $p["duration_days"] !== null ? (int) $p["duration_days"] : null,
                    "region"          => (string) ($p["region"] ?? ""),
                ];
            }, $plans),
            "csrf"      => $csrf,
            "submitUrl" => root . "api/insurance/booking/submit",
         ], JSON_UNESCAPED_SLASHES | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>

  <div class="text-center mb-8">
    <span class="material-symbols-outlined text-5xl text-sky-600">health_and_safety</span>
    <h1 class="text-3xl font-bold mt-2">Travel Insurance</h1>
    <p class="text-gray-600 mt-2 max-w-2xl mx-auto">
      Protect your trip — medical cover, trip cancellation, lost baggage and delays.
    </p>
  </div>

  <?php if ($hasPlans): ?>
    <!-- PLAN GRID -->
    <div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
      <?php foreach ($plans as $p): ?>
        <div class="bg-white rounded-xl shadow border border-gray-100 p-5 flex flex-col <?= !empty($p['featured']) ? 'ring-2 ring-sky-500' : '' ?>">
          <?php if (!empty($p['featured'])): ?>
            <span class="self-start text-xs font-semibold text-sky-700 bg-sky-50 rounded-full px-2 py-0.5 mb-2">Most popular</span>
          <?php endif; ?>
          <h3 class="text-lg font-semibold"><?= htmlspecialchars((string) $p['name']) ?></h3>
          <?php if (!empty($p['provider_label'])): ?>
            <p class="text-xs text-gray-500 mt-0.5"><?= htmlspecialchars((string) $p['provider_label']) ?></p>
          <?php endif; ?>
          <?php if (!empty($p['description'])): ?>
            <p class="text-sm text-gray-600 mt-2"><?= nl2br(htmlspecialchars((string) $p['description'])) ?></p>
          <?php endif; ?>
          <ul class="text-sm text-gray-700 mt-3 space-y-1">
            <?php if ($p['coverage_amount'] !== null): ?>
              <li class="flex items-center gap-2"><span class="material-symbols-outlined text-base text-sky-600">verified_user</span>Cover up to <?= $ccyFmt($p['coverage_amount'], $p['currency']) ?></li>
            <?php endif; ?>
            <?php if ($p['duration_days'] !== null): ?>
              <li class="flex items-center gap-2"><span class="material-symbols-outlined text-base text-sky-600">event</span>Up to <?= (int) $p['duration_days'] ?> days</li>
            <?php endif; ?>
            <?php if (!empty($p['region'])): ?>
              <li class="flex items-center gap-2"><span class="material-symbols-outlined text-base text-sky-600">public</span><?= htmlspecialchars((string) $p['region']) ?></li>
            <?php endif; ?>
          </ul>
          <?php if (!empty($p['coverage_details'])): ?>
            <p class="text-xs text-gray-500 mt-3"><?= nl2br(htmlspecialchars((string) $p['coverage_details'])) ?></p>
          <?php endif; ?>
          <div class="mt-auto pt-4">
            <div class="text-2xl font-bold text-gray-900"><?= $ccyFmt($p['price'], $p['currency']) ?><span class="text-sm font-normal text-gray-500"> / traveller</span></div>
            <button type="button" class="btn cyan w-full mt-3" @click="choose(<?= (int) $p['id'] ?>)">Buy now</button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <!-- BUY MODAL -->
    <div x-show="selected" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4" @keydown.escape.window="selected=null">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6" @click.outside="selected=null">
        <div class="flex items-start justify-between">
          <h3 class="text-lg font-semibold" x-text="selected ? ('Buy: ' + selected.name) : ''"></h3>
          <button type="button" class="text-gray-400 hover:text-gray-600" @click="selected=null"><span class="material-symbols-outlined">close</span></button>
        </div>
        <form class="mt-4 space-y-3" @submit.prevent="submit()">
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">First name</label>
              <input type="text" x-model="form.first_name" required class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Last name</label>
              <input type="text" x-model="form.last_name" required class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Email</label>
            <input type="email" x-model="form.email" required class="w-full border rounded-lg px-3 py-2 text-sm">
          </div>
          <div class="grid grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Phone</label>
              <input type="tel" x-model="form.phone" class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Country</label>
              <input type="text" x-model="form.country" class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Travellers</label>
              <input type="number" min="1" max="20" x-model.number="form.travellers" class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
          </div>
          <div class="flex items-center justify-between bg-gray-50 rounded-lg px-3 py-2 text-sm">
            <span class="text-gray-600">Total</span>
            <span class="font-semibold" x-text="totalLabel()"></span>
          </div>
          <label class="flex items-start gap-2 text-xs text-gray-600">
            <input type="checkbox" x-model="form.terms_accepted" required class="mt-0.5">
            <span>I accept the terms and conditions and confirm the traveller details are correct.</span>
          </label>
          <p x-show="error" x-text="error" class="text-sm text-red-600"></p>
          <button type="submit" class="btn cyan w-full" :disabled="busy" x-text="busy ? 'Please wait…' : 'Continue to payment'"></button>
        </form>
      </div>
    </div>
  <?php else: ?>
    <div class="bg-sky-50 border border-sky-200 rounded-xl p-6 text-center">
      <span class="material-symbols-outlined text-3xl text-sky-600">schedule</span>
      <h2 class="text-lg font-semibold mt-2 text-sky-900">Coming soon</h2>
      <p class="text-sky-800 mt-1 max-w-xl mx-auto">
        Buying a travel-insurance policy online isn’t available just yet. In the
        meantime, our team can arrange a policy for you — contact us, or visit a
        branch. If your flight was delayed or cancelled, you can
        <a href="<?= root ?>insurance" class="font-semibold underline">claim compensation here</a>.
      </p>
    </div>
  <?php endif; ?>
</section>

<script>
function insuranceStore(cfg) {
  return {
    plans: cfg.plans || [],
    csrf: cfg.csrf || '',
    submitUrl: cfg.submitUrl,
    selected: null,
    busy: false,
    error: '',
    form: { first_name: '', last_name: '', email: '', phone: '', country: '', travellers: 1, terms_accepted: false },
    choose(id) {
      this.selected = this.plans.find(p => p.id === id) || null;
      this.error = '';
    },
    totalLabel() {
      if (!this.selected) return '';
      const t = Math.max(1, parseInt(this.form.travellers || 1, 10));
      const amt = (this.selected.price * t);
      return this.selected.currency + ' ' + amt.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    async submit() {
      if (this.busy || !this.selected) return;
      this.error = '';
      this.busy = true;
      try {
        const res = await fetch(this.submitUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
          body: JSON.stringify({
            plan_id: this.selected.id,
            first_name: this.form.first_name,
            last_name: this.form.last_name,
            email: this.form.email,
            phone: this.form.phone,
            country: this.form.country,
            travellers: Math.max(1, parseInt(this.form.travellers || 1, 10)),
            terms_accepted: this.form.terms_accepted ? 1 : 0,
            csrf_token: this.csrf
          })
        });
        const j = await res.json().catch(() => ({}));
        if (j && (j.success || j.status) && (j.redirect || j.redirect_url)) {
          window.location.href = j.redirect || j.redirect_url;
          return;
        }
        this.error = (j && j.message) ? j.message : 'Could not start your purchase. Please try again.';
      } catch (e) {
        this.error = 'Network error. Please try again.';
      } finally {
        this.busy = false;
      }
    }
  };
}
</script>
