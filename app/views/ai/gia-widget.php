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

// --- Page context: detect which service the visitor is looking at, so GIA's
//     opening line + idle nudge are tailored (e.g. on /umrah it talks umrah).
//     Pure read of the request path; no DB, no side effects. ---
$giaWPath = strtolower(trim((string)($_GET['url'] ?? ''), '/'));
$giaWFirst = $giaWPath === '' ? '' : explode('/', $giaWPath)[0];
$giaWModuleAliases = [
    'flights' => 'flights', 'flight' => 'flights',
    'stays' => 'stays', 'hotels' => 'stays', 'hotel' => 'stays',
    'tours' => 'tours', 'tour' => 'tours',
    'cars' => 'cars', 'car' => 'cars',
    'visa' => 'visa', 'visas' => 'visa',
    'esim' => 'esim',
    'ferries' => 'ferries', 'ferry' => 'ferries',
    'rail' => 'rail', 'train' => 'rail', 'trains' => 'rail',
    'bus' => 'bus',
    'umrah' => 'umrah',
];
$giaWContext = $giaWModuleAliases[$giaWFirst] ?? '';

// Public Pusher bits (key + cluster only) for realtime human-handoff delivery.
// Empty when Pusher isn't configured -> the widget just polls. Never the secret.
$giaWPusher = [];
$giaWRt = dirname(__DIR__, 2) . '/lib/ai/gia_realtime.php';
if (is_file($giaWRt)) {
    require_once $giaWRt;
    if (function_exists('App\\lib\\ai\\gia_pusher_public_config')) {
        $giaWPusher = \App\lib\ai\gia_pusher_public_config($giaWDb);
    }
}

// Personalization: first name for a warm, logged-in greeting (never the full PII).
$giaWFirstName = '';
if (!empty($_SESSION['user_name'])) {
    $giaWFirstName = trim((string) explode(' ', trim((string) $_SESSION['user_name']))[0]);
}

$giaWCfg = json_encode([
    'endpoint'  => root . 'api/gia/chat',
    'csrfToken' => class_exists('CSRF') ? CSRF::getToken() : '',
    'root'      => root,
    'context'   => $giaWContext,
    'pusher'    => $giaWPusher, // {key,cluster} or {}
    'firstName' => $giaWFirstName,
    'loggedIn'  => !empty($_SESSION['user_id']),
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<style>
  .giaw-fab{position:fixed;right:20px;bottom:20px;z-index:9998;width:60px;height:60px;border-radius:50%;
    background:linear-gradient(135deg,#0058E6,#3b82f6);color:#fff;border:none;cursor:pointer;
    box-shadow:0 8px 24px rgba(0,88,230,.35);
    display:flex;align-items:center;justify-content:center;transition:transform .2s}
  .giaw-fab:hover{transform:scale(1.08) rotate(-4deg)}
  .giaw-fab .material-symbols-outlined{font-size:28px}
  /* Attention bounce when the site opens / a proactive ping arrives */
  .giaw-fab.giaw-bounce{animation:giaw-bounce 1s ease 2}
  @keyframes giaw-bounce{0%,100%{transform:translateY(0)}25%{transform:translateY(-10px) scale(1.05)}50%{transform:translateY(0)}75%{transform:translateY(-5px)}}
  /* Unread red dot on the bubble */
  .giaw-unread{position:absolute;top:-2px;right:-2px;min-width:18px;height:18px;padding:0 5px;border-radius:9px;
    background:#ef4444;color:#fff;font-size:11px;font-weight:700;line-height:18px;text-align:center;
    box-shadow:0 0 0 2px #fff;animation:giaw-pop .3s ease}
  @keyframes giaw-pop{from{transform:scale(0)}to{transform:scale(1)}}
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
  /* Message bubbles animate in (slide + fade) so the chat feels alive */
  .giaw-msg-in{animation:giaw-msg .32s cubic-bezier(.2,.8,.2,1) both}
  @keyframes giaw-msg{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}
  .giaw-card{border:1px solid #e2e8f0;border-radius:12px;background:#fff}
  .giaw-chip{font-size:.62rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:2px 8px;border-radius:999px}
  .giaw-typing span{display:inline-block;width:5px;height:5px;margin:0 1px;border-radius:50%;background:#94a3b8;animation:giaw-blink 1.2s infinite both}
  .giaw-typing span:nth-child(2){animation-delay:.2s}.giaw-typing span:nth-child(3){animation-delay:.4s}
  @keyframes giaw-blink{0%,80%,100%{opacity:.3}40%{opacity:1}}
  .giaw-foot{border-top:1px solid #eef2f7;padding:10px 12px;background:#fff}
  @media (max-width:420px){.giaw-panel{right:0;bottom:0;width:100vw;height:100vh;max-height:100vh;border-radius:0}}
  @media (prefers-reduced-motion:reduce){
    .giaw-fab.giaw-bounce,.giaw-msg-in,.giaw-unread{animation:none!important}
    .giaw-fab:hover{transform:none}
  }
  [x-cloak]{display:none!important}
  /* Proactive teaser bubble above the FAB */
  .giaw-teaser{position:fixed;right:20px;bottom:92px;z-index:9998;width:260px;max-width:calc(100vw - 40px);
    background:#fff;border-radius:16px 16px 16px 4px;padding:12px 14px;cursor:pointer;
    box-shadow:0 12px 32px rgba(0,0,0,.18);border:1px solid #eef2f7;animation:giaw-rise .35s ease both}
  .giaw-teaser:hover{box-shadow:0 14px 38px rgba(0,88,230,.22)}
  .giaw-teaser-av{color:#0058E6;font-size:20px;flex:none;margin-top:1px}
  .giaw-teaser-txt{font-size:.8rem;line-height:1.35;color:#0f172a;margin:0}
  .giaw-teaser-x{position:absolute;top:6px;right:6px;width:20px;height:20px;border:0;background:#f1f5f9;
    border-radius:50%;color:#64748b;cursor:pointer;display:flex;align-items:center;justify-content:center}
  .giaw-teaser-x:hover{background:#e2e8f0}
  @keyframes giaw-rise{from{opacity:0;transform:translateY(10px) scale(.96)}to{opacity:1;transform:none}}
  /* Gentle attention pulse on the bubble */
  .giaw-pulse{animation:giaw-fab-pulse 2.2s ease-in-out infinite}
  @keyframes giaw-fab-pulse{0%,100%{box-shadow:0 8px 24px rgba(0,88,230,.35)}50%{box-shadow:0 8px 24px rgba(0,88,230,.35),0 0 0 10px rgba(0,88,230,.12)}}
  @media (prefers-reduced-motion:reduce){.giaw-pulse,.giaw-teaser{animation:none}}
  /* Quick-reply chips under GIA messages */
  .giaw-qr{font-size:.72rem;font-weight:600;color:#0058E6;background:#eaf1fe;border:1px solid #d7e5fd;
    border-radius:999px;padding:4px 10px;cursor:pointer;transition:background .15s}
  /* Side drawer: opens a result/listing page in an iframe WITHOUT leaving the chat */
  .giaw-drawer{position:fixed;top:0;right:0;z-index:10000;height:100vh;width:min(560px,100vw);
    background:#fff;box-shadow:-16px 0 48px rgba(0,0,0,.22);display:flex;flex-direction:column;
    animation:giaw-slide-in .28s ease both}
  @keyframes giaw-slide-in{from{transform:translateX(100%)}to{transform:none}}
  .giaw-drawer-head{display:flex;align-items:center;gap:8px;padding:12px 14px;border-bottom:1px solid #eef2f7;background:#f8fafc}
  .giaw-drawer-ttl{font-weight:700;font-size:.85rem;color:#0f172a;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .giaw-drawer-btn{border:0;background:#eef2f7;color:#334155;border-radius:8px;width:30px;height:30px;cursor:pointer;display:flex;align-items:center;justify-content:center}
  .giaw-drawer-btn:hover{background:#e2e8f0}
  .giaw-drawer-body{flex:1;border:0;width:100%}
  .giaw-scrim{position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.35)}
  @media (max-width:600px){.giaw-drawer{width:100vw}}
  @media (prefers-reduced-motion:reduce){.giaw-drawer{animation:none}}
  .giaw-qr:hover{background:#dbe8fe}
</style>

<div x-data='giaWidget(<?= $giaWCfg ?>)' x-init="init()" x-cloak>
  <!-- Proactive teaser: a little speech bubble GIA "says" above the FAB on first
       visit / when idle. Tapping it opens the chat with that message prefilled. -->
  <div class="giaw-teaser" x-show="teaser && !open" x-transition
       @click="openFromTeaser()" x-cloak>
    <button type="button" class="giaw-teaser-x" @click.stop="dismissTeaser()" aria-label="Dismiss">
      <span class="material-symbols-outlined" style="font-size:15px">close</span>
    </button>
    <div class="flex items-start gap-2">
      <span class="giaw-teaser-av material-symbols-outlined">travel_explore</span>
      <p class="giaw-teaser-txt" x-text="teaser"></p>
    </div>
  </div>

  <!-- Floating bubble -->
  <button type="button" class="giaw-fab" x-show="!open" @click="toggle()" aria-label="Ask GIA" style="position:fixed"
          :class="(pulse && !open ? 'giaw-pulse ' : '') + (bounce ? 'giaw-bounce' : '')">
    <span class="material-symbols-outlined">forum</span>
    <span class="giaw-unread" x-show="unread > 0" x-text="unread" x-cloak></span>
  </button>

  <!-- Chat panel -->
  <div class="giaw-panel" x-show="open" x-transition.opacity x-cloak>
    <div class="giaw-head">
      <span class="material-symbols-outlined">travel_explore</span>
      <div>
        <div class="ttl">Ask GIA</div>
        <div class="sub">AI travel assistant · you approve before any payment</div>
      </div>
      <button class="giaw-close" @click="newChat()" aria-label="New chat" title="Start a new chat"
              x-show="messages.length > 0"><span class="material-symbols-outlined">add_comment</span></button>
      <button class="giaw-close" @click="toggle()" aria-label="Close"><span class="material-symbols-outlined">close</span></button>
    </div>

    <div class="px-3 py-1.5 text-[11px] bg-emerald-50 text-emerald-700 border-b border-emerald-100 flex items-center gap-1.5" x-show="staffActive" x-cloak>
      <span class="material-symbols-outlined text-[14px]">support_agent</span> A team member is helping you on this chat.
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
        <div class="mb-3 giaw-msg-in" :class="m.role==='user' ? 'flex justify-end' : 'flex justify-start'">
          <div class="max-w-[88%]">
            <div class="px-3 py-2 text-[13px] leading-relaxed whitespace-pre-wrap" :class="m.role==='user' ? 'giaw-u' : 'giaw-a'"
                 x-html="m.role==='user' ? escapeHtml(m.text) : renderMarkdown(m.text)"></div>

            <!-- Quick-reply chips (one-tap starters under a GIA message) -->
            <template x-if="m.role!=='user' && m.quick && m.quick.length && i===messages.length-1 && !sending">
              <div class="mt-1.5 flex flex-wrap gap-1.5">
                <template x-for="(q,qi) in m.quick" :key="qi">
                  <button type="button" class="giaw-qr" @click="send(q)" x-text="q"></button>
                </template>
              </div>
            </template>

            <!-- Soft lead-capture card (shown once, after GIA has shown value) -->
            <template x-if="m.lead && !leadDone">
              <div class="mt-1.5 giaw-card p-2.5">
                <div class="flex items-center gap-1.5 mb-1.5">
                  <span class="material-symbols-outlined text-[16px]" style="color:var(--gia-brand)">bookmark_add</span>
                  <span class="text-[11px] font-semibold text-slate-700">Save your trip</span>
                  <button type="button" class="ml-auto text-slate-400 hover:text-slate-600" @click="dismissLead()" aria-label="No thanks">
                    <span class="material-symbols-outlined" style="font-size:15px">close</span>
                  </button>
                </div>
                <input x-model="leadName" type="text" placeholder="Name (optional)"
                       class="w-full mb-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-[12px] focus:outline-none focus:ring-2 focus:ring-blue-200">
                <input x-model="leadEmail" type="email" placeholder="Email"
                       class="w-full mb-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-[12px] focus:outline-none focus:ring-2 focus:ring-blue-200">
                <input x-model="leadPhone" type="tel" placeholder="Phone (optional)"
                       class="w-full mb-1.5 rounded-lg border border-slate-200 px-2.5 py-1.5 text-[12px] focus:outline-none focus:ring-2 focus:ring-blue-200">
                <p class="text-[10px] text-red-600 mb-1" x-show="leadMsg" x-text="leadMsg"></p>
                <div class="flex items-center gap-2">
                  <button type="button" class="flex-1 py-1.5 rounded-lg text-white font-semibold text-[12px] disabled:opacity-60" style="background:#0058E6" :disabled="sending" @click="saveLead()">Save &amp; continue</button>
                  <button type="button" class="text-[11px] text-slate-500 hover:text-slate-700 px-2" @click="dismissLead()">No thanks</button>
                </div>
              </div>
            </template>

            <template x-if="m.searches && m.searches.length">
              <div class="mt-1.5 space-y-1.5">
                <template x-for="(sr,si) in m.searches" :key="si">
                  <div>
                    <!-- REAL priced cards -->
                    <template x-if="sr.cards && sr.cards.length">
                      <div class="space-y-1.5">
                        <template x-for="(c,ci) in sr.cards.slice(0,5)" :key="ci">
                          <a :href="sr.live ? '#' : cardUrl(sr,c)"
                             @click.prevent="sr.live ? pickOffer(sr,c) : openDrawer(cardUrl(sr,c), c.name||c.title||'Details')"
                             class="giaw-card block overflow-hidden hover:bg-slate-50 transition cursor-pointer">
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
                                <div class="mt-1 flex items-center gap-1 text-[10px] font-semibold" style="color:var(--gia-brand)">
                                  <span x-text="sr.live ? 'Select this' : 'View & book'"></span>
                                  <span class="material-symbols-outlined text-[13px]">arrow_forward</span>
                                </div>
                              </div>
                            </div>
                          </a>
                        </template>
                        <template x-if="sr.live && sr.expires">
                          <p class="text-[9px] text-amber-600 px-1">Live fares — prices confirmed when you pick one.</p>
                        </template>
                      </div>
                    </template>
                    <!-- Live-supplier rich deep-link card (flights/cars/esim/rail/ferries) -->
                    <template x-if="!(sr.cards && sr.cards.length) && hasSummary(sr)">
                      <a :href="sr.listing_url?absUrl(sr.listing_url):'#'" @click.prevent="sr.listing_url && openDrawer(absUrl(sr.listing_url), summaryHeadline(sr))"
                         class="giaw-card block p-2.5 hover:bg-slate-50 transition cursor-pointer">
                        <div class="flex items-center gap-1.5 mb-1">
                          <span class="material-symbols-outlined text-[16px]" style="color:var(--gia-brand)" x-text="moduleIcon(sr.module)"></span>
                          <span class="text-[12px] font-semibold text-slate-800" x-text="summaryHeadline(sr)"></span>
                        </div>
                        <div class="text-[10px] text-slate-500 mb-1.5" x-text="summaryLines(sr).join(' · ')"></div>
                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold" style="color:var(--gia-brand)"><span x-text="summaryCta(sr)"></span>
                          <span class="material-symbols-outlined text-[14px]">arrow_forward</span></span>
                      </a>
                    </template>
                    <!-- Intent-only deep-link chip -->
                    <template x-if="!(sr.cards && sr.cards.length) && !hasSummary(sr)">
                      <a :href="sr.listing_url ? absUrl(sr.listing_url) : '#'" @click.prevent="sr.listing_url && openDrawer(absUrl(sr.listing_url), sr.title||sr.module||'Details')"
                         class="giaw-card px-2.5 py-1.5 flex items-center gap-1.5" :class="sr.listing_url?'hover:bg-slate-50 transition cursor-pointer':''">
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
                <p class="text-[11px] text-slate-500 mt-1.5 mb-2">Complete the secure payment right here to confirm your booking.</p>
                <!-- Pay IN-CHAT: the secure payment page loads in the side drawer so the
                     traveller never leaves the conversation. -->
                <button type="button" @click="openPayment(m.payment.redirect_url)"
                        class="block w-full py-2 rounded-lg text-white font-semibold text-[13px]" style="background:#16a34a">
                  Pay securely now
                </button>
                <a :href="absUrl(m.payment.redirect_url)" target="_blank" rel="noopener"
                   class="block text-center text-[10px] text-slate-400 mt-1 hover:text-slate-600">or open in a new tab</a>
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

  <!-- Side drawer: opens the selected result/listing page in an iframe so the
       visitor explores + books WITHOUT leaving the conversation. -->
  <template x-if="drawer">
    <div>
      <div class="giaw-scrim" @click="closeDrawer()"></div>
      <div class="giaw-drawer" @keydown.escape.window="closeDrawer()">
        <div class="giaw-drawer-head">
          <span class="material-symbols-outlined" style="color:#0058E6;font-size:20px">travel_explore</span>
          <span class="giaw-drawer-ttl" x-text="drawerTitle"></span>
          <a :href="drawerUrl" target="_blank" rel="noopener" class="giaw-drawer-btn" title="Open in new tab">
            <span class="material-symbols-outlined" style="font-size:18px">open_in_new</span>
          </a>
          <button type="button" class="giaw-drawer-btn" @click="closeDrawer()" title="Close">
            <span class="material-symbols-outlined" style="font-size:18px">close</span>
          </button>
        </div>
        <iframe class="giaw-drawer-body" :src="drawerUrl" title="Trip details"
                referrerpolicy="same-origin" loading="lazy"></iframe>
      </div>
    </div>
  </template>
</div>

<script>
function giaWidget(cfg){
  return {
    endpoint: cfg.endpoint, csrfToken: cfg.csrfToken || '', root: cfg.root || '', context: cfg.context || '',
    open:false, messages:[], draft:'', sending:false, lastUserText:'',
    teaser:'', pulse:false, greeted:false, idleTimer:null, nudged:false,
    leadShown:false, leadDone:false, leadOpen:false, leadName:'', leadEmail:'', leadPhone:'', leadMsg:'',
    drawer:false, drawerUrl:'', drawerTitle:'',
    staffActive:false, pollTimer:null, pusher:(cfg.pusher||{}), convKey:'', rtChannel:null, rtClient:null,
    firstName:(cfg.firstName||''), loggedIn:!!cfg.loggedIn,
    bounce:false, unread:0,
    starters:['Flights to Dubai','Hotels in Lagos','Plan a trip to Zanzibar'],
    // Per-context copy so GIA opens relevantly (e.g. on /umrah it talks umrah).
    ctxCopy:{
      umrah:  {teaser:"Planning Umrah? 🕋 I can show you our packages in seconds — want to see them?", starters:['Show me Umrah packages','Umrah for 2 adults','Cheapest Umrah option']},
      flights:{teaser:"Looking for flights? Tell me where from and to — I'll find live fares.", starters:['Lagos to London','Abuja to Dubai next month','Cheapest flight out']},
      stays:  {teaser:"Need a place to stay? Tell me the city and I'll pull up great hotels.", starters:['Hotels in Dubai','Family hotel in Zanzibar','Budget stay this weekend']},
      tours:  {teaser:"Want something fun to do? I can find tours and experiences for you.", starters:['Tours in Dubai','City tour this week','Adventure day trip']},
      bus:    {teaser:"Travelling by road? Tell me your route and date — I'll find buses.", starters:['Bus Lahore to Islamabad','Cheapest coach tomorrow']},
      '':     {teaser:"Hi, I'm GIA 👋 Where would you like to go, or what can I help you book?", starters:['Flights to Dubai','Hotels in Lagos','Plan a trip to Zanzibar']}
    },
    init(){
      const c = this.ctxCopy[this.context] || this.ctxCopy[''];
      this.starters = c.starters;
      // Resume a persistent conversation (cross-tab / refresh / another device):
      // pull this browser's prior transcript from the server. Fail-soft.
      this.hydrate();
      // First-visit greeting teaser: appear shortly after load (warm, not instant),
      // once per browser session. A gentle pulse draws the eye.
      // Personalize the teaser when we know the visitor (name), else context/default.
      const teaserText = (this.firstName && !this.context)
        ? ("Welcome back, "+this.firstName+" 👋 Need a hand planning something?")
        : c.teaser;
      const showPing = ()=>{ if(this.open) return;
        this.teaser=teaserText; this.pulse=true; this.unread=1;   // "we sent you a message"
        this.bounce=true; setTimeout(()=>{ this.bounce=false; }, 2100);
      };
      try{
        if(!sessionStorage.getItem('gia_greeted')){
          setTimeout(()=>{ showPing(); sessionStorage.setItem('gia_greeted','1'); }, 1800);
        } else { this.pulse=false; }
      }catch(e){ setTimeout(showPing, 1800); }
      this.armIdle();
      ['click','keydown','scroll','mousemove','touchstart'].forEach(ev=>
        window.addEventListener(ev, ()=>this.armIdle(), {passive:true}));
      // External open hook: anything on the page (e.g. the homepage AI box) can
      // open GIA and optionally send a first message via a window event:
      //   window.dispatchEvent(new CustomEvent('gia:open', {detail:{message:'...'}}))
      window.addEventListener('gia:open', (e)=>{
        const msg = (e && e.detail && e.detail.message) ? String(e.detail.message) : '';
        this.teaser=''; this.pulse=false;
        if(!this.open){ this.open=true; this.$nextTick(()=>{ if(this.$refs.input) this.$refs.input.focus(); this.scrollDown(); }); }
        if(msg.trim()){ this.send(msg.trim()); }
      });
    },
    async hydrate(){
      try{
        const res=await fetch(this.root+'api/gia/history',{headers:{'X-CSRF-TOKEN':this.csrfToken}});
        const d=await res.json().catch(()=>({}));
        if(d && Array.isArray(d.messages) && d.messages.length){
          // Map stored {role:user|assistant} to the widget's bubble roles.
          this.messages = d.messages.map(m=>({role: m.role==='user'?'user':'ai', text:m.text||''}));
          this.greeted = true;          // already has a conversation; skip canned greeting
          this.$nextTick(()=>this.scrollDown());
        }
        if(d && d.conv_key){ this.convKey=d.conv_key; }
        // Resume handoff (realtime + polling) if a human was mid-conversation.
        if(d && d.staff_active){ this.staffActive=true; this.subscribeRealtime(); this.startPolling(); }
      }catch(e){ /* fail-soft: start fresh */ }
    },
    armIdle(){
      if(this.nudged) return;
      clearTimeout(this.idleTimer);
      this.idleTimer=setTimeout(()=>{
        if(this.open||this.nudged) return;
        const c=this.ctxCopy[this.context]||this.ctxCopy[''];
        this.teaser = this.context==='umrah'
          ? "Still thinking about Umrah? I've got packages ready whenever you are 🙂"
          : "Need a hand? I can find and book your trip in a couple of minutes.";
        this.pulse=true; this.nudged=true;
      }, 38000);
    },
    openFromTeaser(){ const t=this.teaser; this.teaser=''; this.pulse=false; this.unread=0; this.open=true;
      this.$nextTick(()=>{ if(this.$refs.input) this.$refs.input.focus(); if(this.messages.length===0) this.showGreeting(); this.scrollDown(); }); },
    dismissTeaser(){ this.teaser=''; this.pulse=false; },
    // A warm, varied, personalized opener — not one static line. Uses the first
    // name when logged in, and rotates wording so repeat visits feel alive.
    greetLine(){
      const c=this.ctxCopy[this.context]||this.ctxCopy[''];
      const name=this.firstName;
      if(this.context==='umrah'){
        return name ? ("Assalamu alaikum, "+name+" 🕋 Ready to look at Umrah packages? I can show you in seconds.")
                    : "Assalamu alaikum 🕋 Ask me about Umrah and I'll show you our packages right away — no long forms.";
      }
      if(this.context){ return (name? (name+", ") : "") + c.teaser; }
      // Generic openers — pick one so it isn't the same every time.
      const loggedIn=[
        "Welcome back, "+name+" 👋 Where are we headed next?",
        "Hey "+name+"! ✈️ What trip can I pull together for you today?",
        "Good to see you, "+name+" 😊 Tell me where you'd like to go and I'll handle the rest."
      ];
      const guest=[
        "Hi, I'm GIA 👋 Tell me where you'd like to go and I'll find real options — and book it with you.",
        "Hey there! ✈️ I'm GIA, your travel buddy. What are you dreaming up?",
        "Hi! 😊 I'm GIA. Give me a trip idea and I'll turn it into real, bookable options."
      ];
      const pool = (name? loggedIn : guest);
      // Rotate by a per-session counter so it varies without randomness APIs.
      let n=0; try{ n=parseInt(sessionStorage.getItem('gia_greet_n')||'0',10)||0; sessionStorage.setItem('gia_greet_n', String(n+1)); }catch(e){}
      return pool[n % pool.length];
    },
    showGreeting(){ if(this.greeted) return; this.greeted=true;
      const c=this.ctxCopy[this.context]||this.ctxCopy[''];
      this.messages.push({role:'ai', text:this.greetLine(), quick:c.starters});
    },
    toggle(){ this.open=!this.open; this.teaser=''; this.pulse=false; this.unread=0;
      if(this.open) this.$nextTick(()=>{ if(this.$refs.input) this.$refs.input.focus(); if(this.messages.length===0) this.showGreeting(); this.scrollDown(); }); },
    autoGrow(el){ el.style.height='auto'; el.style.height=Math.min(el.scrollHeight,100)+'px'; },
    async send(preset){
      const text=(preset!==undefined?preset:this.draft).trim();
      if(!text||this.sending) return;
      // Engaging = stop all proactive nudging for this session.
      this.teaser=''; this.pulse=false; this.nudged=true; clearTimeout(this.idleTimer);
      if(!this.open){ this.open=true; this.$nextTick(()=>this.scrollDown()); }
      this.lastUserText=text; this.messages.push({role:'user',text}); this.draft='';
      this.$nextTick(()=>{ if(this.$refs.input)this.$refs.input.style.height='auto'; this.scrollDown(); });
      await this.post({message:text});
    },
    async approveCheckout(){ if(this.sending) return; await this.post({message:this.lastUserText||'I approve this amount.', approved:true}); },
    async post(body){
      this.sending=true; this.$nextTick(()=>this.scrollDown());
      try{
        const res=await fetch(this.endpoint,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':this.csrfToken},body:JSON.stringify(Object.assign({csrf_token:this.csrfToken,context:this.context,surface:'widget'},body))});
        const data=await res.json().catch(()=>({}));
        const showedValue = (Array.isArray(data.searches) && data.searches.length) || !!data.checkout;
        this.messages.push({role:'ai',text:data.reply||(res.ok?'Done.':'Sorry, something went wrong.'),
          searches:Array.isArray(data.searches)?data.searches:null,checkout:data.checkout||null,payment:data.payment||null,order_status:data.order_status||null});
        if(showedValue) this.maybeOfferLead();
        if(data.conv_key){ this.convKey=data.conv_key; }
        // Human handoff: a staff member took over — subscribe for instant replies
        // (Pusher) AND poll as a fallback; show the banner.
        if(data.handoff || data.staff_active){ this.staffActive=true; this.subscribeRealtime(); this.startPolling(); }
      }catch(e){ this.messages.push({role:'ai',text:'I could not reach the assistant. Please try again.'}); }
      finally{ this.sending=false; this.$nextTick(()=>this.scrollDown()); }
    },
    // Realtime (Pusher) delivery of staff replies — instant, with polling as the
    // safety net. No-op (polling only) when Pusher isn't configured.
    subscribeRealtime(){
      if(this.rtChannel || !this.convKey) return;
      var key=this.pusher && this.pusher.key;
      if(!key){ return; } // not configured -> polling covers it
      var self=this;
      var start=function(){
        try{
          if(!self.rtClient){ self.rtClient=new Pusher(key, { cluster:(self.pusher.cluster||'us2'), forceTLS:true }); }
          self.rtChannel=self.rtClient.subscribe('gia-conv-'+self.convKey);
          self.rtChannel.bind('gia-staff-reply', function(data){
            if(!data || !data.text) return;
            self.staffActive=true;
            self.messages.push({role:'ai', text:String(data.text)});
            self.$nextTick(()=>self.scrollDown());
          });
        }catch(e){ /* fall back to polling */ }
      };
      if(typeof Pusher!=='undefined'){ start(); return; }
      // Lazy-load the Pusher client script once.
      var s=document.createElement('script'); s.src='https://js.pusher.com/8.0.1/pusher.min.js';
      s.onload=start; s.onerror=function(){ /* polling fallback */ };
      document.head.appendChild(s);
    },
    // While a human is handling the chat, poll history for new staff replies
    // (fallback + reconciliation even when realtime is active).
    startPolling(){ if(this.pollTimer) return; this.pollTimer=setInterval(()=>this.syncStaff(), 8000); },
    stopPolling(){ if(this.pollTimer){ clearInterval(this.pollTimer); this.pollTimer=null; }
      // Also tear down the realtime subscription once handoff ends.
      try{ if(this.rtChannel && this.rtClient){ this.rtClient.unsubscribe('gia-conv-'+this.convKey); } }catch(e){}
      this.rtChannel=null; },
    async syncStaff(){
      try{
        const res=await fetch(this.root+'api/gia/history',{headers:{'X-CSRF-TOKEN':this.csrfToken}});
        const d=await res.json().catch(()=>({}));
        if(d && Array.isArray(d.messages)){
          // Rehydrate from the server transcript (includes staff replies) only if it grew.
          if(d.messages.length > this.messages.length){
            this.messages = d.messages.map(m=>({role: m.role==='user'?'user':'ai', text:m.text||''}));
            this.$nextTick(()=>this.scrollDown());
          }
          this.staffActive = !!d.staff_active;
          if(!d.staff_active) this.stopPolling(); // handed back to GIA
        }
      }catch(e){ /* keep polling */ }
    },
    // Soft, optional lead offer AFTER GIA has shown real value. Once per session,
    // dismissible, never blocks the chat. (Warm 'save your trip' value exchange.)
    maybeOfferLead(){
      if(this.leadShown||this.leadDone) return;
      this.leadShown=true;
      this.$nextTick(()=>{
        this.messages.push({role:'ai', text:"Want me to save these and send you the details? Pop in your email or phone and I'll keep your trip handy (and let you know if prices change).", lead:true});
        this.scrollDown();
      });
    },
    async saveLead(){
      if(this.sending) return;
      const email=(this.leadEmail||'').trim(), phone=(this.leadPhone||'').trim();
      if(!email && !phone){ this.leadMsg='Please add an email or phone.'; return; }
      this.sending=true; this.leadMsg='';
      try{
        const res=await fetch(this.root+'api/gia/lead',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':this.csrfToken},
          body:JSON.stringify({csrf_token:this.csrfToken,context:this.context,surface:'widget',name:this.leadName,email:email,phone:phone})});
        const d=await res.json().catch(()=>({}));
        if(d && d.status){ this.leadDone=true; this.leadOpen=false;
          this.messages.push({role:'ai', text:d.message||"Saved! I'll keep your trip details handy."});
        } else { this.leadMsg=(d&&d.message)||'Could not save — check your details.'; }
      }catch(e){ this.leadMsg='Could not save right now.'; }
      finally{ this.sending=false; this.$nextTick(()=>this.scrollDown()); }
    },
    dismissLead(){ this.leadDone=true; this.leadOpen=false; },
    // Start a fresh conversation: retire the server-side one, wipe the UI, re-greet.
    async newChat(){
      if(this.sending) return;
      try{ await fetch(this.root+'api/gia/reset',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':this.csrfToken},body:JSON.stringify({csrf_token:this.csrfToken})}); }catch(e){}
      this.stopPolling(); this.convKey='';
      this.messages=[]; this.greeted=false; this.leadShown=false; this.leadDone=false; this.staffActive=false;
      this.showGreeting(); this.$nextTick(()=>{ if(this.$refs.input) this.$refs.input.focus(); this.scrollDown(); });
    },
    // Picking a LIVE offer: send a message GIA turns into select_option(module,ref).
    // The ref is the stable id fetch_live_offers returned + cached server-side.
    pickOffer(sr, c){ if(this.sending) return;
      const mod=(c&&(c.module||c.kind))||(sr&&sr.module)||'';
      const ref=(c&&(c.ref||c.id))||'';
      const name=(c&&(c.name||c.title))||'this option';
      if(!mod||!ref){ this.send('I\'d like to book '+name); return; }
      // Explicit, unambiguous instruction for the model to call select_option.
      this.send('Please select_option module='+mod+' id='+ref+' — I want to book "'+name+'".'); },
    // Open a result/listing page INSIDE a side drawer (iframe) so the visitor
    // never leaves the chat. Makes sure the panel is open so the drawer has a home.
    openDrawer(url, title){ if(!url||url==='#') return; this.open=true; this.drawerUrl=url; this.drawerTitle=title||'Details'; this.drawer=true; },
    closeDrawer(){ this.drawer=false; this.drawerUrl=''; },
    // Payment: same-origin pages load in the drawer (in-chat); an EXTERNAL gateway
    // URL (Paystack/Stripe-hosted) can't be framed, so open it in a new tab instead
    // of flashing a blank drawer.
    openPayment(url){ if(!url) return; const u=this.absUrl(url); let sameOrigin=false;
      try{ sameOrigin = new URL(u, window.location.href).origin === window.location.origin; }catch(e){}
      if(sameOrigin){ this.openDrawer(u, 'Secure payment'); } else { window.open(u, '_blank', 'noopener'); } },
    scrollDown(){ const s=this.$refs.scroll; if(s) s.scrollTop=s.scrollHeight; },
    money(a,c){ const n=Number(a||0); return (c?c+' ':'')+n.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); },
    hasSummary(sr){ const s=sr&&sr.summary; if(!s||typeof s!=='object') return false; return !!(s.origin_label||s.origin_code||s.headline); },
    summaryHeadline(sr){ const s=sr.summary||{}; if(s.headline) return s.headline; const o=s.origin_label||s.origin_code||'', d=s.destination_label||s.destination_code||'', sep=s.trip_type==='return'?' ⇄ ':' → '; return (o&&d)?(o+sep+d):(sr.title||'Options'); },
    summaryLines(sr){ const s=sr.summary||{}; if(s.headline!==undefined){ return [s.line1,s.line2].filter(x=>x&&String(x).trim()!==''); } const out=[]; if(s.trip_label)out.push(s.trip_label); if(s.departure_date)out.push('📅 '+s.departure_date+(s.return_date?' → '+s.return_date:'')); if(s.travellers)out.push('👤 '+s.travellers); if(s.cabin)out.push(s.cabin); return out; },
    summaryCta(sr){ const s=sr.summary||{}; if(s.cta) return s.cta; return (sr.module==='flights')?'Search live flights':'See live options'; },
    absUrl(u){ u=String(u||''); if(!u) return '#'; if(/^https?:\/\//i.test(u)) return u; return (this.root||'')+u.replace(/^\/+/,''); },
    cardUrl(sr,c){ const mod=(c&&(c.kind||c.module))||(sr&&sr.module)||''; if(mod==='umrah'&&c&&c.slug) return this.absUrl('umrah/packages/'+encodeURIComponent(c.slug)); if(sr&&sr.listing_url) return this.absUrl(sr.listing_url); return '#'; },
    moduleIcon(m){ return ({flights:'flight',stays:'hotel',tours:'tour',cars:'directions_car',visa:'badge',esim:'sim_card',ferries:'directions_boat',rail:'train',bus:'directions_bus',umrah:'mosque'})[m]||'place'; },
    statusLabel(s){ return ({CONFIRMED:'Confirmed',PARTIAL:'Partially booked',PAID_PENDING:'Payment received',UNPAID:'Not paid',FAILED:'Booking failed',NOT_FOUND:'Not found'})[s]||s; },
    statusClass(s){ if(s==='CONFIRMED')return'bg-green-50 text-green-700'; if(s==='PARTIAL'||s==='FAILED')return'bg-red-50 text-red-700'; if(s==='PAID_PENDING')return'bg-amber-50 text-amber-700'; return'bg-slate-100 text-slate-600'; },
    escapeHtml(s){ return String(s||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); },
    stripTables(s){
      // Safety net: the model is told not to use markdown tables, but if one slips
      // through, degrade it to readable lines instead of raw pipes. Drop separator
      // rows (|---|---|) and turn "| a | b | c |" into "a · b · c".
      return String(s||'').split('\n').map(line=>{
        const m=line.match(/^\s*\|(.+)\|\s*$/);
        if(!m) return line;
        const cells=m[1].split('|').map(c=>c.trim());
        if(cells.every(c=>/^:?-{2,}:?$/.test(c)||c==='')) return ''; // separator row
        return cells.filter(c=>c!=='').join(' · ');
      }).filter((l,i,a)=>!(l===''&&a[i-1]==='')).join('\n');
    },
    renderMarkdown(s){ let t=this.escapeHtml(this.stripTables(s)); t=t.replace(/\*\*([^*]+)\*\*/g,'<strong>$1</strong>'); t=t.replace(/(^|[^*])\*([^*]+)\*/g,'$1<em>$2</em>'); t=t.replace(/^\s*[-•]\s+(.*)$/gm,'<span class="block pl-2">• $1</span>'); return t; },
  };
}
</script>
