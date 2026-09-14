<?php
// GENERAL CART PAGE — step 6b. Lists cart lines (tours/visa/esim), server-repriced
// via GET /cart/data, and hands off to POST /cart/checkout (one payment).
// Expects: $cartCsrf. Adapted from app/views/modules/umrah/v2/cart.php.
@$SECURE or die('Access Denied!');
$displayCur = strtoupper((string)($_SESSION['app_currency'] ?? 'NGN')) ?: 'NGN';
?>
<div class="bg-slate-50 min-h-screen" x-data="generalCart()" x-init="load()">
  <div class="container py-8 max-w-3xl">

    <div class="flex items-center justify-between mb-4">
      <h1 class="text-2xl font-bold text-slate-900">Your cart</h1>
      <a href="<?= root ?>" class="text-sm text-primary inline-flex items-center gap-1"><span class="material-symbols-outlined text-[18px]">add</span> Continue shopping</a>
    </div>

    <!-- Loading -->
    <template x-if="loading">
      <div class="card border text-center py-10 text-slate-500">Loading your cart…</div>
    </template>

    <!-- Empty -->
    <template x-if="!loading && cart.items.length === 0 && !done">
      <div class="card border text-center py-10">
        <span class="material-symbols-outlined text-4xl text-slate-300">shopping_cart</span>
        <p class="text-slate-600 mt-2">Your cart is empty.</p>
        <a href="<?= root ?>" class="btn mt-4">Browse tours, visas &amp; eSIMs</a>
      </div>
    </template>

    <!-- Cart lines -->
    <template x-if="!loading && cart.items.length > 0 && !done">
      <div>
        <div x-show="cart.changed" class="alert-warning mb-3 text-sm">
          <span class="material-symbols-outlined">info</span>
          <div>Some prices or availability changed — your cart was updated.</div>
        </div>

        <div class="space-y-3">
          <template x-for="it in cart.items" :key="it.key">
            <div class="card border flex items-center gap-4">
              <template x-if="it.image">
                <img :src="it.image" alt="" class="w-16 h-16 rounded-lg object-cover shrink-0">
              </template>
              <div class="flex-1 min-w-0">
                <div class="font-semibold text-slate-900 truncate" x-text="it.title"></div>
                <div class="text-xs uppercase tracking-wide text-slate-400 mt-0.5" x-text="it.module"></div>
                <div class="text-sm text-slate-500 mt-0.5" x-text="lineMeta(it)"></div>
              </div>
              <div class="text-right shrink-0">
                <div class="font-bold text-slate-900 tabular-nums" x-text="money(it.final_base)"></div>
                <button class="text-xs text-rose-600 hover:underline mt-1" @click="remove(it.key)">Remove</button>
              </div>
            </div>
          </template>
        </div>

        <!-- Coupon -->
        <div class="card border mt-4">
          <label class="block text-sm font-medium text-slate-700 mb-1">Promo code</label>
          <div class="flex gap-2">
            <input class="input flex-1 font-mono uppercase" placeholder="Enter code" x-model="promo.code" :disabled="promo.applied">
            <button class="btn outline" :disabled="promo.busy || promo.applied || !promo.code.trim()" @click="applyPromo()"
              x-text="promo.applied ? 'Applied' : (promo.busy ? 'Checking…' : 'Apply')"></button>
            <button x-show="promo.applied" class="btn ghost text-rose-600" @click="clearPromo()">Remove</button>
          </div>
          <p class="text-xs mt-1" :class="promo.ok ? 'text-green-600' : 'text-rose-600'" x-text="promo.msg"></p>
        </div>

        <!-- Totals -->
        <div class="card border mt-4">
          <div class="flex items-center justify-between text-sm text-slate-600">
            <span>Subtotal</span><span class="tabular-nums" x-text="money(cart.grand_base)"></span>
          </div>
          <div x-show="promo.discount > 0" class="flex items-center justify-between text-sm text-green-600 mt-1">
            <span>Discount (<span x-text="promo.code"></span>)</span>
            <span class="tabular-nums" x-text="'-' + money(promo.discount)"></span>
          </div>
          <div class="flex items-center justify-between mt-2 pt-2 border-t">
            <span class="font-semibold text-slate-900">Total</span>
            <span class="text-xl font-extrabold text-slate-900 tabular-nums" x-text="money(payable())"></span>
          </div>
        </div>

        <!-- Contact + checkout -->
        <div class="card border mt-4">
          <h2 class="font-semibold text-slate-900 mb-3">Your details</h2>
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <input class="input" placeholder="Full name" x-model="lead.name">
            <input class="input" type="email" placeholder="Email" x-model="lead.email">
            <input class="input" placeholder="Phone" x-model="lead.phone">
          </div>
          <button class="btn w-full justify-center mt-4" :disabled="busy" @click="checkout()"
            x-text="busy ? 'Preparing payment…' : ('Pay ' + money(payable()))"></button>
          <p class="text-xs mt-2" :class="msgOk ? 'text-green-600' : 'text-rose-600'" x-text="msg"></p>
          <p class="text-xs text-slate-400 mt-2">You pay once for the whole cart. NGN is processed by Paystack; other currencies by Stripe.</p>
        </div>
      </div>
    </template>
  </div>
</div>

<script>
function generalCart() {
  return {
    csrf: '<?= htmlspecialchars($cartCsrf ?? '', ENT_QUOTES) ?>',
    cart: { items: [], grand_base: 0, grand_total: 0, currency: '<?= $displayCur ?>', count: 0, changed: false },
    lead: { name: '', email: '', phone: '' },
    promo: { code: '', applied: false, busy: false, ok: false, msg: '', discount: 0 },
    loading: true, busy: false, done: false, msg: '', msgOk: false,
    money(n) { return (this.cart.currency || 'NGN') + ' ' + Number(n||0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2}); },
    lineMeta(it) {
      const m = it.meta || {};
      if (it.module === 'tours') return (m.adults||0) + ' adult' + ((m.adults||0)!==1?'s':'') + (m.children ? ', ' + m.children + ' child' + (m.children!==1?'ren':'') : '');
      if (it.module === 'visa') return (m.travelers||0) + ' traveller' + ((m.travelers||0)!==1?'s':'');
      if (it.module === 'esim') return 'Qty ' + (m.qty||1);
      return '';
    },
    payable() { return Math.max(0, (this.cart.grand_base||0) - (this.promo.discount||0)); },
    async postJSON(url, body) {
      const r = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf },
        body: JSON.stringify(Object.assign({ csrf_token: this.csrf }, body)) });
      return r.json();
    },
    async load() {
      try { const r = await fetch('<?= root ?>cart/data'); const j = await r.json(); if (j.success) this.cart = j.cart; } catch (e) {}
      this.loading = false;
      if (this.promo.applied) this.applyPromo(true);
    },
    async remove(key) { const j = await this.postJSON('<?= root ?>cart/remove', { key }); if (j.success) { this.cart = j.cart; if (this.promo.applied) this.applyPromo(true); } },
    async applyPromo(silent) {
      const code = (this.promo.code||'').trim().toUpperCase();
      if (!code) return;
      this.promo.busy = true;
      try {
        // Server recomputes against the whole-cart base total (module='cart').
        const j = await this.postJSON('<?= root ?>api/promo/validate', { code, module: 'cart', order_amount: this.cart.grand_base, currency: this.cart.currency });
        if (j.success && j.data) {
          this.promo.discount = Number(j.data.discount_amount || 0);
          this.promo.applied = true; this.promo.ok = true;
          this.promo.msg = silent ? '' : 'Promo applied.';
        } else { this.promo.discount = 0; this.promo.applied = false; this.promo.ok = false; this.promo.msg = j.message || 'Invalid promo code'; }
      } catch (e) { this.promo.ok = false; this.promo.msg = 'Could not check that code'; }
      this.promo.busy = false;
    },
    clearPromo() { this.promo = { code: '', applied: false, busy: false, ok: false, msg: '', discount: 0 }; },
    async checkout() {
      if (!this.lead.name || (!this.lead.email && !this.lead.phone)) { this.msg = 'Enter your name and email or phone.'; this.msgOk = false; return; }
      this.busy = true; this.msg = '';
      const parts = (this.lead.name||'').trim().split(' ');
      try {
        const j = await this.postJSON('<?= root ?>cart/checkout', {
          first_name: parts[0] || '', last_name: parts.slice(1).join(' ') || '',
          email: this.lead.email, phone: this.lead.phone,
          promo_code: this.promo.applied ? this.promo.code : ''
        });
        if (j.success && j.redirect_url) { window.location.href = j.redirect_url; }
        else { this.msg = j.message || 'Checkout failed'; this.msgOk = false; this.busy = false; }
      } catch (e) { this.msg = 'Network error'; this.msgOk = false; this.busy = false; }
    }
  };
}
</script>
