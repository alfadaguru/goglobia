<?php
// UMRAH v2 — multi-departure cart + combined checkout (Phase B3).
// Expects: $plans, $umrahCsrf.
@$SECURE or die('Access Denied!');
$plansJs = array_map(fn($p) => ['code' => $p['code'], 'name' => $p['name']], $plans ?? []);
?>
<div class="bg-slate-50 min-h-screen" x-data="umrahCart()" x-init="load()">
  <div class="container py-8 max-w-3xl">

    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold text-slate-900">Your Umrah cart</h1>
      <a href="<?= root ?>umrah/search" class="text-sm text-primary inline-flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">add</span> Add another departure</a>
    </div>

    <!-- Empty -->
    <template x-if="!loading && cart.items.length === 0 && !done">
      <div class="card border text-center py-10">
        <span class="material-symbols-outlined text-4xl text-slate-300">shopping_cart</span>
        <p class="text-slate-600 mt-2">Your cart is empty.</p>
        <a href="<?= root ?>umrah/search" class="btn mt-4">Browse Umrah packages</a>
      </div>
    </template>

    <!-- Cart lines -->
    <template x-if="cart.items.length > 0 && !done">
      <div>
        <div class="space-y-3">
          <template x-for="it in cart.items" :key="it.key">
            <div class="card border flex items-center gap-4">
              <div class="flex-1 min-w-0">
                <div class="font-semibold text-slate-900" x-text="it.label"></div>
                <div class="text-sm text-slate-600" x-text="it.city + ' · ' + it.tier_label + ' · ' + it.pax + ' pilgrim' + (it.pax>1?'s':'')"></div>
                <div class="text-sm text-slate-500 mt-0.5" x-text="money(it.unit_price) + ' × ' + it.pax"></div>
              </div>
              <div class="text-right shrink-0">
                <div class="font-bold text-slate-900" x-text="money(it.line_total)"></div>
                <button class="text-xs text-rose-600 hover:underline mt-1" @click="remove(it.key)">Remove</button>
              </div>
            </div>
          </template>
        </div>

        <!-- Grand total -->
        <div class="card border mt-4 flex items-center justify-between">
          <span class="font-semibold text-slate-900">Grand total</span>
          <span class="text-xl font-extrabold text-slate-900" x-text="money(cart.grand_total)"></span>
        </div>

        <!-- Lead + plan -->
        <div class="card border mt-4">
          <h2 class="font-semibold text-slate-900 mb-3">Your details</h2>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <input class="input" placeholder="Full name" x-model="lead.name">
            <input class="input" type="email" placeholder="Email" x-model="lead.email">
            <input class="input" placeholder="WhatsApp / phone" x-model="lead.phone">
          </div>
          <label class="block text-sm font-medium text-slate-700 mt-3 mb-1">Payment plan</label>
          <select class="select" x-model="plan">
            <template x-for="p in plans" :key="p.code"><option :value="p.code" x-text="p.name"></option></template>
          </select>
          <button class="btn w-full justify-center mt-4" :disabled="busy" @click="checkout()"
            x-text="busy ? 'Creating your bookings…' : ('Book ' + cart.items.length + ' departure' + (cart.items.length>1?'s':''))"></button>
          <p class="text-xs mt-2" :class="msgOk ? 'text-green-600' : 'text-rose-600'" x-text="msg"></p>
          <p class="text-xs text-slate-400 mt-2">Each departure becomes its own booking. Pay the deposit for each below to confirm and lock the price.</p>
        </div>
      </div>
    </template>

    <!-- Post-checkout: created bookings, pay each -->
    <template x-if="done">
      <div>
        <div class="card border">
          <div class="flex items-center gap-3">
            <span class="material-symbols-outlined text-3xl text-green-600">check_circle</span>
            <div>
              <h2 class="text-lg font-bold text-slate-900">Bookings created</h2>
              <p class="text-sm text-slate-600" x-text="created.length + ' Umrah booking' + (created.length>1?'s':'') + ' created. Pay each deposit to confirm.'"></p>
            </div>
          </div>
        </div>
        <div class="space-y-3 mt-3">
          <template x-for="b in created" :key="b.booking_ref">
            <div class="card border flex items-center justify-between gap-3">
              <div>
                <div class="font-semibold text-slate-900" x-text="b.label"></div>
                <div class="text-xs text-slate-500 font-mono" x-text="b.booking_ref"></div>
              </div>
              <div class="flex items-center gap-2">
                <a :href="'<?= root ?>umrah/booking/' + b.booking_ref" class="btn outline btn-sm">Details</a>
                <a :href="'<?= root ?>invoice/umrah/' + b.invoice_id" class="btn btn-sm">Pay</a>
              </div>
            </div>
          </template>
        </div>
        <div class="alert-warning mt-3" x-show="errors.length">
          <span class="material-icon material-symbols-outlined">warning</span>
          <div><p>Some items could not be booked:</p><ul class="list-disc ml-5 text-sm"><template x-for="e in errors" :key="e"><li x-text="e"></li></template></ul></div>
        </div>
      </div>
    </template>

  </div>
</div>

<script>
function umrahCart() {
  return {
    apiBase: '<?= root ?>api/v1/umrah',
    csrf: '<?= htmlspecialchars($umrahCsrf ?? '', ENT_QUOTES) ?>',
    plans: <?= json_encode($plansJs, JSON_UNESCAPED_SLASHES) ?>,
    cart: { items: [], grand_total: 0, currency: 'NGN', count: 0 },
    lead: { name: '', email: '', phone: '' },
    plan: 'PP-50-25-25',
    loading: true, busy: false, done: false, msg: '', msgOk: false,
    created: [], errors: [],
    money(n) { return '₦' + Number(n||0).toLocaleString('en-NG'); },
    async post(url, body) {
      const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
        body: JSON.stringify(Object.assign({ csrf_token: this.csrf }, body)) });
      return r.json();
    },
    async load() {
      try { const r = await fetch(this.apiBase + '/cart'); const j = await r.json(); if (j.success) this.cart = j.cart; }
      catch (e) {}
      this.loading = false;
    },
    async remove(key) { const j = await this.post(this.apiBase + '/cart/remove', { key }); if (j.success) this.cart = j.cart; },
    async checkout() {
      if (!this.lead.name || (!this.lead.email && !this.lead.phone)) { this.msg = 'Enter your name and email or phone.'; this.msgOk = false; return; }
      this.busy = true; this.msg = '';
      const parts = (this.lead.name||'').trim().split(' ');
      try {
        const j = await this.post(this.apiBase + '/cart/checkout', {
          first_name: parts[0] || '', last_name: parts.slice(1).join(' ') || '',
          email: this.lead.email, phone: this.lead.phone, payment_plan: this.plan
        });
        if (j.success) { this.created = j.bookings || []; this.errors = j.errors || []; this.done = true; }
        else { this.msg = j.message || 'Checkout failed'; this.msgOk = false; }
      } catch (e) { this.msg = 'Network error'; this.msgOk = false; }
      this.busy = false;
    }
  };
}
</script>
