<?php @$SECURE or die('Access Denied!'); ?>
<?php
// ============================================================================
// GIA floating chat widget — a site-wide chatbox (bottom-right bubble that opens
// a slide-up chat panel). Included from includes/footer.php on every page, gated
// on the AI feature flag. Reuses the SAME /api/gia/chat backend + the same chat
// logic as the full /gia page; this is just the floating-widget shell.
// ============================================================================
$giaWDb = $db ?? ($GLOBALS['db'] ?? null);
// Only show the widget when the AI trip feature is enabled + a provider key is set.
if (!function_exists('aiTripIsEnabled') || $giaWDb === null || !aiTripIsEnabled($giaWDb)) {
    return;
}
$giaWCfg = json_encode([
    'endpoint'  => root . 'api/gia/chat',
    'csrfToken' => class_exists('CSRF') ? CSRF::getToken() : '',
    'root'      => root,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<style>
  .giaw-fab{position:fixed;right:20px;bottom:20px;z-index:9998;width:60px;height:60px;border-radius:50%;
    background:#0058E6;color:#fff;border:none;cursor:pointer;box-shadow:0 8px 24px rgba(0,88,230,.35);
    display:flex;align-items:center;justify-content:center;transition:transform .2s}
  .giaw-fab:hover{transform:scale(1.06)}
  .giaw-fab .material-symbols-outlined{font-size:28px}
  .giaw-panel{position:fixed;right:20px;bottom:20px;z-index:9999;width:380px;max-width:calc(100vw - 32px);
    height:600px;max-height:calc(100vh - 40px);background:#fff;border-radius:18px;
    box-shadow:0 20px 60px rgba(0,0,0,.25);display:flex;flex-direction:column;overflow:hidden}
  .giaw-head{background:#0058E6;color:#fff;padding:14px 16px;display:flex;align-items:center;gap:10px}
  .giaw-head .ttl{font-weight:700;font-size:.95rem;line-height:1.1}
  .giaw-head .sub{font-size:.68rem;opacity:.85}
  .giaw-close{margin-left:auto;background:none;border:0;color:#fff;cursor:pointer;opacity:.9}
  .giaw-body{flex:1;overflow-y:auto;padding:14px;background:#f8fafc;scrollbar-width:thin}
  .giaw-body::-webkit-scrollbar{width:6px}.giaw-body::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px}
  .giaw-u{background:#0058E6;color:#fff;border-radius:16px 16px 4px 16px}
  .giaw-a{background:#eef2f7;color:#0f172a;border-radius:16px 16px 16px 4px}
  .giaw-card{border:1px solid #e2e8f0;border-radius:12px;background:#fff}
  .giaw-chip{font-size:.62rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:2px 8px;border-radius:999px}
  .giaw-typing span{display:inline-block;width:5px;height:5px;margin:0 1px;border-radius:50%;background:#94a3b8;animation:giaw-blink 1.2s infinite both}
  .giaw-typing span:nth-child(2){animation-delay:.2s}.giaw-typing span:nth-child(3){animation-delay:.4s}
  @keyframes giaw-blink{0%,80%,100%{opacity:.3}40%{opacity:1}}
  .giaw-foot{border-top:1px solid #eef2f7;padding:10px 12px;background:#fff}
  @media (max-width:420px){.giaw-panel{right:0;bottom:0;width:100vw;height:100vh;max-height:100vh;border-radius:0}}
  [x-cloak]{display:none!important}
</style>

<div x-data='giaWidget(<?= $giaWCfg ?>)' x-cloak>
  <!-- Floating bubble -->
  <button type="button" class="giaw-fab" x-show="!open" @click="toggle()" aria-label="Ask GIA">
    <span class="material-symbols-outlined">forum</span>
  </button>

  <!-- Chat panel -->
  <div class="giaw-panel" x-show="open" x-transition.opacity x-cloak>
    <div class="giaw-head">
      <span class="material-symbols-outlined">travel_explore</span>
      <div>
        <div class="ttl">Ask GIA</div>
        <div class="sub">AI travel assistant · you approve before any payment</div>
      </div>
      <button class="giaw-close" @click="toggle()" aria-label="Close"><span class="material-symbols-outlined">close</span></button>
    </div>

    <div class="giaw-body" x-ref="scroll">
      <template x-if="messages.length === 0">
        <div class="text-center py-6">
          <p class="text-slate-600 text-sm font-medium mb-3">Where would you like to go?</p>
          <div class="flex flex-wrap gap-1.5 justify-center">
            <template x-for="s in starters" :key="s">
              <button type="button" class="text-[11px] bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-full px-2.5 py-1" @click="send(s)" x-text="s"></button>
            </template>
          </div>
        </div>
      </template>

      <template x-for="(m,i) in messages" :key="i">
        <div class="mb-3" :class="m.role==='user' ? 'flex justify-end' : 'flex justify-start'">
          <div class="max-w-[88%]">
            <div class="px-3 py-2 text-[13px] leading-relaxed whitespace-pre-wrap" :class="m.role==='user' ? 'giaw-u' : 'giaw-a'"
                 x-html="m.role==='user' ? escapeHtml(m.text) : renderMarkdown(m.text)"></div>

            <template x-if="m.searches && m.searches.length">
              <div class="mt-1.5 space-y-1.5">
                <template x-for="(sr,si) in m.searches" :key="si">
                  <div>
                    <!-- REAL priced cards -->
                    <template x-if="sr.cards && sr.cards.length">
                      <div class="space-y-1.5">
                        <template x-for="(c,ci) in sr.cards.slice(0,5)" :key="ci">
                          <a :href="cardUrl(sr,c)" target="_blank" rel="noopener" class="giaw-card block overflow-hidden hover:bg-slate-50 transition">
                            <div class="flex gap-2">
                              <template x-if="c.image">
                                <img :src="c.image" :alt="c.name||c.title||''" class="w-14 h-14 object-cover shrink-0" loading="lazy" @error="$event.target.style.display='none'">
                              </template>
                              <div class="flex-1 min-w-0 p-2">
                                <div class="flex items-start justify-between gap-1.5">
                                  <div class="min-w-0">
                                    <div class="text-[11px] font-semibold text-slate-800 truncate" x-text="c.name||c.title||'Option'"></div>
                                    <div class="text-[10px] text-slate-500 truncate" x-text="c.subtitle||c.location||''"></div>
                                  </div>
                                  <div class="text-[11px] font-bold text-slate-900 shrink-0" x-text="money(c.price!=null?c.price:c.adult_price, c.currency)"></div>
                                </div>
                              </div>
                            </div>
                          </a>
                        </template>
                      </div>
                    </template>
                    <!-- Flights: rich deep-link card -->
                    <template x-if="!(sr.cards && sr.cards.length) && sr.summary && sr.module==='flights'">
                      <a :href="sr.listing_url?absUrl(sr.listing_url):'#'" target="_blank" rel="noopener"
                         class="giaw-card block p-2.5 hover:bg-slate-50 transition">
                        <div class="flex items-center gap-1.5 mb-1">
                          <span class="material-symbols-outlined text-[16px]" style="color:var(--gia-brand)">flight</span>
                          <span class="text-[12px] font-semibold text-slate-800" x-text="(sr.summary.origin_label||sr.summary.origin_code)+(sr.summary.trip_type==='return'?' ⇄ ':' → ')+(sr.summary.destination_label||sr.summary.destination_code)"></span>
                        </div>
                        <div class="text-[10px] text-slate-500 mb-1.5">
                          <span x-text="sr.summary.departure_date+(sr.summary.return_date?' → '+sr.summary.return_date:'')"></span>
                          <span x-text="' · '+sr.summary.travellers+' · '+sr.summary.cabin"></span>
                        </div>
                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold" style="color:var(--gia-brand)">Search live flights
                          <span class="material-symbols-outlined text-[14px]">arrow_forward</span></span>
                      </a>
                    </template>
                    <!-- Intent-only deep-link chip -->
                    <template x-if="!(sr.cards && sr.cards.length) && !(sr.summary && sr.module==='flights')">
                      <a :href="sr.listing_url ? absUrl(sr.listing_url) : '#'" :target="sr.listing_url?'_blank':'_self'" rel="noopener"
                         class="giaw-card px-2.5 py-1.5 flex items-center gap-1.5" :class="sr.listing_url?'hover:bg-slate-50 transition':''">
                        <span class="material-symbols-outlined text-[16px] text-slate-400" x-text="moduleIcon(sr.module||sr.kind)"></span>
                        <span class="text-[11px] font-medium text-slate-700" x-text="sr.title||(sr.module||sr.kind||'result')"></span>
                        <span class="text-[11px] text-slate-500 truncate flex-1" x-text="sr.subtitle||sr.note||''"></span>
                      </a>
                    </template>
                  </div>
                </template>
              </div>
            </template>

            <template x-if="m.checkout">
              <div class="mt-1.5 giaw-card p-3">
                <span class="giaw-chip bg-amber-50 text-amber-700">Review &amp; approve</span>
                <div class="flex items-baseline gap-1 mt-1.5 mb-2">
                  <span class="text-xl font-bold text-slate-800" x-text="money(m.checkout.amount,m.checkout.currency)"></span>
                  <span class="text-[11px] text-slate-500">total</span>
                </div>
                <p class="text-[11px] text-slate-500 mb-2">Nothing is charged until you approve and complete payment.</p>
                <button type="button" class="w-full py-2 rounded-lg text-white font-semibold text-[13px] disabled:opacity-60" style="background:#0058E6" :disabled="sending" @click="approveCheckout()">
                  Approve <span x-text="money(m.checkout.amount,m.checkout.currency)"></span>
                </button>
              </div>
            </template>

            <template x-if="m.payment && m.payment.redirect_url">
              <div class="mt-1.5 giaw-card p-3">
                <span class="giaw-chip bg-green-50 text-green-700">Approved — pay securely</span>
                <a :href="m.payment.redirect_url" class="mt-2 block text-center w-full py-2 rounded-lg text-white font-semibold text-[13px]" style="background:#16a34a">Continue to secure payment</a>
              </div>
            </template>

            <template x-if="m.order_status">
              <div class="mt-1.5 giaw-card p-2 flex items-center gap-1.5">
                <span class="giaw-chip" :class="statusClass(m.order_status.state)" x-text="statusLabel(m.order_status.state)"></span>
                <span class="text-[11px] text-slate-600" x-show="m.order_status.pnr" x-text="'Ref: '+m.order_status.pnr"></span>
              </div>
            </template>
          </div>
        </div>
      </template>

      <div class="flex justify-start mb-3" x-show="sending">
        <div class="giaw-a px-3 py-2.5 giaw-typing"><span></span><span></span><span></span></div>
      </div>
    </div>

    <div class="giaw-foot">
      <form @submit.prevent="send()" class="flex items-end gap-2">
        <textarea x-model="draft" rows="1" maxlength="2000" x-ref="input"
                  @keydown.enter.prevent="if(!$event.shiftKey){ send() }" @input="autoGrow($event.target)"
                  placeholder="Ask about a trip…"
                  class="flex-1 resize-none rounded-lg border border-slate-200 px-2.5 py-2 text-[13px] focus:outline-none focus:ring-2 focus:ring-blue-200"></textarea>
        <button type="submit" :disabled="sending || !draft.trim()" class="shrink-0 w-10 h-10 rounded-lg text-white flex items-center justify-center disabled:opacity-50" style="background:#0058E6">
          <span class="material-symbols-outlined text-[20px]">send</span>
        </button>
      </form>
      <p class="text-[9px] text-slate-400 mt-1">AI assistant — verify prices before you pay.</p>
    </div>
  </div>
</div>

<script>
function giaWidget(cfg){
  return {
    endpoint: cfg.endpoint, csrfToken: cfg.csrfToken || '', root: cfg.root || '',
    open:false, messages:[], draft:'', sending:false, lastUserText:'',
    starters:['Umrah from Kano next month','Trip to Dubai from Abuja','Hotel in Zanzibar'],
    toggle(){ this.open=!this.open; if(this.open) this.$nextTick(()=>{ if(this.$refs.input) this.$refs.input.focus(); this.scrollDown(); }); },
    autoGrow(el){ el.style.height='auto'; el.style.height=Math.min(el.scrollHeight,100)+'px'; },
    async send(preset){
      const text=(preset!==undefined?preset:this.draft).trim();
      if(!text||this.sending) return;
      this.lastUserText=text; this.messages.push({role:'user',text}); this.draft='';
      this.$nextTick(()=>{ if(this.$refs.input)this.$refs.input.style.height='auto'; this.scrollDown(); });
      await this.post({message:text});
    },
    async approveCheckout(){ if(this.sending) return; await this.post({message:this.lastUserText||'I approve this amount.', approved:true}); },
    async post(body){
      this.sending=true; this.$nextTick(()=>this.scrollDown());
      try{
        const res=await fetch(this.endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':this.csrfToken},body:JSON.stringify(Object.assign({csrf_token:this.csrfToken},body))});
        const data=await res.json().catch(()=>({}));
        this.messages.push({role:'ai',text:data.reply||(res.ok?'Done.':'Sorry, something went wrong.'),
          searches:Array.isArray(data.searches)?data.searches:null,checkout:data.checkout||null,payment:data.payment||null,order_status:data.order_status||null});
      }catch(e){ this.messages.push({role:'ai',text:'I could not reach the assistant. Please try again.'}); }
      finally{ this.sending=false; this.$nextTick(()=>this.scrollDown()); }
    },
    scrollDown(){ const s=this.$refs.scroll; if(s) s.scrollTop=s.scrollHeight; },
    money(a,c){ const n=Number(a||0); return (c?c+' ':'')+n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); },
    absUrl(u){ u=String(u||''); if(!u) return '#'; if(/^https?:\/\//i.test(u)) return u; return (this.root||'')+u.replace(/^\/+/,''); },
    cardUrl(sr,c){ const mod=(c&&(c.kind||c.module))||(sr&&sr.module)||''; if(mod==='umrah'&&c&&c.slug) return this.absUrl('umrah/packages/'+encodeURIComponent(c.slug)); if(sr&&sr.listing_url) return this.absUrl(sr.listing_url); return '#'; },
    moduleIcon(m){ return ({flights:'flight',stays:'hotel',tours:'tour',cars:'directions_car',visa:'badge',esim:'sim_card',ferries:'directions_boat',rail:'train',bus:'directions_bus',umrah:'mosque'})[m]||'place'; },
    statusLabel(s){ return ({CONFIRMED:'Confirmed',PARTIAL:'Partially booked',PAID_PENDING:'Payment received',UNPAID:'Not paid',FAILED:'Booking failed',NOT_FOUND:'Not found'})[s]||s; },
    statusClass(s){ if(s==='CONFIRMED')return'bg-green-50 text-green-700'; if(s==='PARTIAL'||s==='FAILED')return'bg-red-50 text-red-700'; if(s==='PAID_PENDING')return'bg-amber-50 text-amber-700'; return'bg-slate-100 text-slate-600'; },
    escapeHtml(s){ return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); },
    renderMarkdown(s){ let t=this.escapeHtml(s); t=t.replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>'); t=t.replace(/(^|[^*])\*([^*]+)\*/g,'$1<em>$2</em>'); t=t.replace(/^\s*[-•]\s+(.*)$/gm,'<span class="block pl-2">• $1</span>'); return t; },
  };
}
</script>
