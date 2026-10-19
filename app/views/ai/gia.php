<?php @$SECURE or die('Access Denied!'); ?>
<?php
// ============================================================================
// GIA chat UI — frontend for POST /api/gia/chat.
// House pattern: rendered between includes/header.php and includes/footer.php by
// GET /gia (giaRoutes.php). Alpine.js component, Tailwind build, CSRF token from
// CSRF::getToken() — same conventions as app/views/ai/trip.php. The JS is inline
// (self-contained; no separate built asset to version).
//
// It renders the agent's text reply plus the structured tool outputs the route
// surfaces: search results, a checkout card with a REAL "Approve" button (which
// re-sends the last turn with approved=true — the only way an approval/payment is
// authorized; the model can never self-approve), a payment redirect, and a
// truthful order-status chip.
// ============================================================================
$giaCfg = json_encode([
    'endpoint'  => root . 'api/gia/chat',
    'csrfToken' => class_exists('CSRF') ? CSRF::getToken() : '',
    'brand'     => '#0058E6',
    'root'      => root,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<style>
  [x-cloak]{display:none!important}
  :root{--gia-brand:#0058E6;--gia-brand-soft:#E8F0FC;--gia-ink:#0f172a}
  .gia-wrap{max-width:860px}
  .gia-scroll{scrollbar-width:thin}
  .gia-scroll::-webkit-scrollbar{width:6px}
  .gia-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px}
  .gia-bubble-user{background:var(--gia-brand);color:#fff;border-radius:18px 18px 4px 18px}
  .gia-bubble-ai{background:#f1f5f9;color:var(--gia-ink);border-radius:18px 18px 18px 4px}
  .gia-typing span{display:inline-block;width:6px;height:6px;margin:0 1px;border-radius:50%;background:#94a3b8;animation:gia-blink 1.2s infinite both}
  .gia-typing span:nth-child(2){animation-delay:.2s}.gia-typing span:nth-child(3){animation-delay:.4s}
  @keyframes gia-blink{0%,80%,100%{opacity:.3}40%{opacity:1}}
  .gia-card{border:1px solid #e2e8f0;border-radius:14px;background:#fff}
  .gia-chip{font-size:.7rem;font-weight:600;letter-spacing:.03em;text-transform:uppercase;padding:3px 10px;border-radius:999px}
</style>

<div class="bg-slate-50 min-h-[70vh] py-6" x-data='giaChat(<?= $giaCfg ?>)' x-init="init()" x-cloak>
  <div class="gia-wrap mx-auto px-4">

    <!-- Header -->
    <div class="flex items-center gap-3 mb-4">
      <div class="w-11 h-11 rounded-full flex items-center justify-center text-white shrink-0" style="background:var(--gia-brand)">
        <span class="material-symbols-outlined">travel_explore</span>
      </div>
      <div>
        <h1 class="text-lg font-bold text-slate-800 leading-tight">GIA — your travel assistant</h1>
        <p class="text-xs text-slate-500">Plan a trip in plain English. GIA searches live options and prepares a checkout — you always approve before anything is paid.</p>
      </div>
    </div>

    <!-- AI disclosure (BRD CON-006) -->
    <div class="flex items-center gap-2 mb-3 text-[11px] text-slate-500">
      <span class="material-symbols-outlined text-[14px]">smart_toy</span>
      You're chatting with an AI assistant. It can search and prepare bookings; it cannot confirm or pay without your explicit approval.
    </div>

    <!-- Conversation -->
    <div class="gia-card p-4 sm:p-5">
      <div class="gia-scroll overflow-y-auto pr-1" style="max-height:52vh" x-ref="scroll">

        <!-- Empty state -->
        <template x-if="messages.length === 0">
          <div class="text-center py-10">
            <p class="text-slate-600 font-medium mb-4">Where would you like to go?</p>
            <div class="flex flex-wrap gap-2 justify-center">
              <template x-for="s in starters" :key="s">
                <button type="button" class="text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-full px-3 py-1.5 transition"
                        @click="send(s)" x-text="s"></button>
              </template>
            </div>
          </div>
        </template>

        <!-- Messages -->
        <template x-for="(m, i) in messages" :key="i">
          <div class="mb-4" :class="m.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
            <div class="max-w-[85%]">
              <div class="px-4 py-2.5 text-sm leading-relaxed whitespace-pre-wrap"
                   :class="m.role === 'user' ? 'gia-bubble-user' : 'gia-bubble-ai'"
                   x-html="m.role === 'user' ? escapeHtml(m.text) : renderMarkdown(m.text)"></div>

              <!-- Search results -->
              <template x-if="m.searches && m.searches.length">
                <div class="mt-2 space-y-2">
                  <template x-for="(sr, si) in m.searches" :key="si">
                    <div>
                      <!-- REAL priced option cards (e.g. umrah packages from our inventory) -->
                      <template x-if="sr.cards && sr.cards.length">
                        <div class="space-y-2">
                          <template x-for="(c, ci) in sr.cards.slice(0, 6)" :key="ci">
                            <div class="gia-card overflow-hidden">
                              <div class="flex gap-3">
                                <!-- thumbnail -->
                                <template x-if="c.image">
                                  <div class="w-20 shrink-0 bg-slate-100">
                                    <img :src="c.image" :alt="c.name || c.title || 'Package'"
                                         class="w-20 h-full object-cover" loading="lazy"
                                         @error="$event.target.style.display='none'">
                                  </div>
                                </template>
                                <div class="flex-1 min-w-0 p-3">
                                  <div class="flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                      <div class="text-sm font-semibold text-slate-800 truncate" x-text="c.name || c.title || 'Option'"></div>
                                      <div class="text-[11px] text-slate-500 truncate" x-text="c.subtitle || c.location || ''"></div>
                                    </div>
                                    <div class="text-right shrink-0">
                                      <div class="text-sm font-bold text-slate-900" x-text="money(c.price != null ? c.price : c.adult_price, c.currency)"></div>
                                      <div class="text-[10px] text-slate-400" x-text="(c.price != null && (c.adults || c.children || c.infants)) ? 'total' : 'from'"></div>
                                    </div>
                                  </div>
                                  <!-- inclusions chips -->
                                  <template x-if="c.inclusions && c.inclusions.length">
                                    <div class="flex flex-wrap gap-1 mt-1.5">
                                      <template x-for="(inc, ii) in c.inclusions.slice(0, 4)" :key="ii">
                                        <span class="text-[10px] bg-slate-100 text-slate-600 rounded-full px-2 py-0.5" x-text="inc.name"></span>
                                      </template>
                                    </div>
                                  </template>
                                  <a :href="cardUrl(sr, c)" target="_blank" rel="noopener"
                                     class="inline-flex items-center gap-1 mt-2 text-xs font-semibold"
                                     style="color:var(--gia-brand)">
                                    View &amp; book
                                    <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                                  </a>
                                </div>
                              </div>
                            </div>
                          </template>
                          <!-- link to all results when there are more -->
                          <template x-if="sr.listing_url && sr.cards.length >= 6">
                            <a :href="absUrl(sr.listing_url)" target="_blank" rel="noopener"
                               class="block text-center text-xs text-slate-500 hover:text-slate-700 py-1" x-text="'See all ' + (sr.module || '') + ' options'"></a>
                          </template>
                        </div>
                      </template>

                      <!-- Flights: a rich "Search flights" deep-link card (live-supplier,
                           so fares load on the results page, not inline) -->
                      <template x-if="!(sr.cards && sr.cards.length) && sr.summary && sr.module === 'flights'">
                        <a :href="sr.listing_url ? absUrl(sr.listing_url) : '#'" target="_blank" rel="noopener"
                           class="gia-card block p-3 hover:bg-slate-50 transition">
                          <div class="flex items-center gap-2 mb-1.5">
                            <span class="material-symbols-outlined text-[18px]" style="color:var(--gia-brand)">flight</span>
                            <span class="text-sm font-semibold text-slate-800" x-text="(sr.summary.origin_label || sr.summary.origin_code) + (sr.summary.trip_type === 'return' ? ' ⇄ ' : ' → ') + (sr.summary.destination_label || sr.summary.destination_code)"></span>
                          </div>
                          <div class="flex flex-wrap gap-x-3 gap-y-1 text-[11px] text-slate-500 mb-2">
                            <span x-text="sr.summary.trip_label"></span>
                            <span x-text="'📅 ' + sr.summary.departure_date + (sr.summary.return_date ? ' → ' + sr.summary.return_date : '')"></span>
                            <span x-text="'👤 ' + sr.summary.travellers"></span>
                            <span x-text="sr.summary.cabin"></span>
                          </div>
                          <span class="inline-flex items-center gap-1 text-xs font-semibold" style="color:var(--gia-brand)">
                            Search live flights
                            <span class="material-symbols-outlined text-[15px]">arrow_forward</span>
                          </span>
                        </a>
                      </template>

                      <!-- Intent-only (other live-supplier modules): a tappable deep-link chip -->
                      <template x-if="!(sr.cards && sr.cards.length) && !(sr.summary && sr.module === 'flights')">
                        <a :href="sr.listing_url ? absUrl(sr.listing_url) : '#'"
                           :target="sr.listing_url ? '_blank' : '_self'" rel="noopener"
                           class="gia-card px-3 py-2 flex items-center gap-2"
                           :class="sr.listing_url ? 'hover:bg-slate-50 transition' : ''">
                          <span class="material-symbols-outlined text-[18px] text-slate-400" x-text="moduleIcon(sr.module || sr.kind)"></span>
                          <span class="text-xs text-slate-700 font-medium" x-text="sr.title || (sr.module || sr.kind || 'result')"></span>
                          <span class="text-xs text-slate-500 truncate flex-1" x-text="sr.subtitle || sr.note || ''"></span>
                          <template x-if="sr.listing_url">
                            <span class="material-symbols-outlined text-[16px] text-slate-400">arrow_forward</span>
                          </template>
                        </a>
                      </template>
                    </div>
                  </template>
                </div>
              </template>

              <!-- Checkout card -> Approve -->
              <template x-if="m.checkout">
                <div class="mt-2 gia-card p-4">
                  <div class="flex items-center justify-between mb-1">
                    <span class="gia-chip bg-amber-50 text-amber-700">Review &amp; approve</span>
                    <span class="text-[11px] text-slate-400" x-text="m.checkout.expires_at ? ('Expires ' + m.checkout.expires_at) : ''"></span>
                  </div>
                  <div class="flex items-baseline gap-1 mb-3">
                    <span class="text-2xl font-bold text-slate-800" x-text="money(m.checkout.amount, m.checkout.currency)"></span>
                    <span class="text-xs text-slate-500">total</span>
                  </div>
                  <p class="text-xs text-slate-500 mb-3">Approve this exact amount to continue. Nothing is charged until you approve and complete payment.</p>
                  <button type="button"
                          class="w-full py-2.5 rounded-lg text-white font-semibold text-sm disabled:opacity-60"
                          style="background:var(--gia-brand)"
                          :disabled="sending"
                          @click="approveCheckout()">
                    <span class="material-symbols-outlined align-middle text-[18px] mr-1">check_circle</span>
                    Approve <span x-text="money(m.checkout.amount, m.checkout.currency)"></span>
                  </button>
                </div>
              </template>

              <!-- Payment redirect -->
              <template x-if="m.payment && m.payment.redirect_url">
                <div class="mt-2 gia-card p-4">
                  <span class="gia-chip bg-green-50 text-green-700 mb-2 inline-block">Approved — pay securely</span>
                  <p class="text-xs text-slate-500 mb-3">Your approval is locked in. Complete the secure payment to confirm your booking.</p>
                  <a :href="m.payment.redirect_url"
                     class="block text-center w-full py-2.5 rounded-lg text-white font-semibold text-sm"
                     style="background:#16a34a">
                    <span class="material-symbols-outlined align-middle text-[18px] mr-1">lock</span>
                    Continue to secure payment
                  </a>
                </div>
              </template>

              <!-- Order status chip -->
              <template x-if="m.order_status">
                <div class="mt-2 gia-card p-3 flex items-center gap-2">
                  <span class="gia-chip" :class="statusClass(m.order_status.state)" x-text="statusLabel(m.order_status.state)"></span>
                  <span class="text-xs text-slate-600" x-show="m.order_status.pnr" x-text="'Ref: ' + m.order_status.pnr"></span>
                  <span class="text-xs text-red-600" x-show="m.order_status.needs_ops">Needs support attention</span>
                </div>
              </template>
            </div>
          </div>
        </template>

        <!-- Typing indicator -->
        <div class="flex justify-start mb-4" x-show="sending">
          <div class="gia-bubble-ai px-4 py-3 gia-typing"><span></span><span></span><span></span></div>
        </div>
      </div>

      <!-- Composer -->
      <div class="mt-3 border-t border-slate-100 pt-3">
        <form @submit.prevent="send()" class="flex items-end gap-2">
          <textarea x-model="draft" x-ref="input" rows="1" maxlength="2000"
                    @keydown.enter.prevent="if(!$event.shiftKey){ send() }"
                    @input="autoGrow($event.target)"
                    placeholder="e.g. 2 adults from Abuja to Dubai, Nov 10-17, 4-star hotel"
                    class="flex-1 resize-none rounded-xl border border-slate-200 px-3 py-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200"></textarea>
          <button type="submit" :disabled="sending || !draft.trim()"
                  class="shrink-0 w-11 h-11 rounded-xl text-white flex items-center justify-center disabled:opacity-50"
                  style="background:var(--gia-brand)">
            <span class="material-symbols-outlined">send</span>
          </button>
        </form>
        <p class="text-[10px] text-slate-400 mt-1.5">GIA can make mistakes. Verify prices and details before you pay.</p>
      </div>
    </div>
  </div>
</div>

<script>
function giaChat(cfg) {
  return {
    endpoint: cfg.endpoint,
    csrfToken: cfg.csrfToken || '',
    root: cfg.root || '',
    messages: [],
    draft: '',
    sending: false,
    lastUserText: '',
    starters: [
      'Umrah packages from Kano next month for 1 traveller',
      'Plan a 5-day trip to Dubai from Abuja under ₦2.5m',
      'Family-friendly hotel in Zanzibar for a week',
    ],

    init() {},

    autoGrow(el) {
      el.style.height = 'auto';
      el.style.height = Math.min(el.scrollHeight, 140) + 'px';
    },

    async send(preset) {
      const text = (preset !== undefined ? preset : this.draft).trim();
      if (!text || this.sending) return;
      this.lastUserText = text;
      this.messages.push({ role: 'user', text });
      this.draft = '';
      this.$nextTick(() => { if (this.$refs.input) this.$refs.input.style.height = 'auto'; this.scrollDown(); });
      await this.post({ message: text });
    },

    // The REAL approval path: re-send the last message with approved=true. This is
    // the only way an approval/payment is authorized — a genuine button press, not
    // model text (the backend reads this flag, not the conversation).
    async approveCheckout() {
      if (this.sending) return;
      await this.post({ message: this.lastUserText || 'I approve this amount.', approved: true });
    },

    async post(body) {
      this.sending = true;
      this.$nextTick(() => this.scrollDown());
      try {
        const res = await fetch(this.endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
          body: JSON.stringify(Object.assign({ csrf_token: this.csrfToken }, body)),
        });
        const data = await res.json().catch(() => ({}));
        this.messages.push({
          role: 'ai',
          text: data.reply || (res.ok ? 'Done.' : 'Sorry, something went wrong. Please try again.'),
          searches: Array.isArray(data.searches) ? data.searches : null,
          checkout: data.checkout || null,
          payment: data.payment || null,
          order_status: data.order_status || null,
        });
      } catch (e) {
        this.messages.push({ role: 'ai', text: 'I could not reach the assistant. Please check your connection and try again.' });
      } finally {
        this.sending = false;
        this.$nextTick(() => this.scrollDown());
      }
    },

    scrollDown() { const s = this.$refs.scroll; if (s) s.scrollTop = s.scrollHeight; },

    money(amt, cur) {
      const n = Number(amt || 0);
      return (cur ? cur + ' ' : '') + n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    // Resolve a (possibly relative) listing/deep-link path against the site root.
    absUrl(u) {
      u = String(u || '');
      if (!u) return '#';
      if (/^https?:\/\//i.test(u)) return u;
      return (this.root || '') + u.replace(/^\/+/, '');
    },
    // Best real link for a result card: an umrah package's own page when we have a
    // slug, else the module's listing/results page. Never an invented URL.
    cardUrl(sr, c) {
      const mod = (c && (c.kind || c.module)) || (sr && sr.module) || '';
      if (mod === 'umrah' && c && c.slug) return this.absUrl('umrah/packages/' + encodeURIComponent(c.slug));
      if (sr && sr.listing_url) return this.absUrl(sr.listing_url);
      return '#';
    },
    moduleIcon(m) {
      return ({ flights:'flight', stays:'hotel', tours:'tour', cars:'directions_car', visa:'badge',
                esim:'sim_card', ferries:'directions_boat', rail:'train', bus:'directions_bus', umrah:'mosque' })[m] || 'place';
    },
    statusLabel(s) {
      return ({ CONFIRMED:'Confirmed', PARTIAL:'Partially booked', PAID_PENDING:'Payment received',
                UNPAID:'Not paid', FAILED:'Booking failed', NOT_FOUND:'Not found' })[s] || s;
    },
    statusClass(s) {
      if (s === 'CONFIRMED') return 'bg-green-50 text-green-700';
      if (s === 'PARTIAL' || s === 'FAILED') return 'bg-red-50 text-red-700';
      if (s === 'PAID_PENDING') return 'bg-amber-50 text-amber-700';
      return 'bg-slate-100 text-slate-600';
    },
    escapeHtml(s) {
      return String(s || '').replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
    },
    // Minimal, SAFE markdown: escape first, then allow **bold**, *italic*, line breaks,
    // and bullet lines. No raw HTML from the model is ever injected.
    renderMarkdown(s) {
      let t = this.escapeHtml(s);
      t = t.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      t = t.replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>');
      t = t.replace(/^\s*[-•]\s+(.*)$/gm, '<span class="block pl-3">• $1</span>');
      return t;
    },
  };
}
</script>
