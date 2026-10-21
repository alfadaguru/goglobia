<?php @$SECURE or die('Access Denied!'); ?>
<?php
// ============================================================================
// GIA chat UI — frontend for POST /api/gia/chat.
// House pattern: rendered between includes/header.php and includes/footer.php by
// GET /gia (giaRoutes.php). Alpine.js component, Tailwind build, CSRF token from
// CSRF::getToken() — standard GIA conventions. The JS is inline
// (self-contained; no separate built asset to version).
//
// It renders the agent's text reply plus the structured tool outputs the route
// surfaces: search results, a checkout card with a REAL "Approve" button (which
// re-sends the last turn with approved=true — the only way an approval/payment is
// authorized; the model can never self-approve), a payment redirect, and a
// truthful order-status chip.
// ============================================================================
$giaPagePusher = [];
$giaPageRt = dirname(__DIR__, 2) . '/lib/ai/gia_realtime.php';
if (is_file($giaPageRt) && isset($db)) {
    require_once $giaPageRt;
    if (function_exists('App\\lib\\ai\\gia_pusher_public_config')) {
        $giaPagePusher = \App\lib\ai\gia_pusher_public_config($db);
    }
}
// Optional initial query (e.g. the homepage AI box fallback: /gia?q=...). Bounded.
$giaInitialQ = trim((string) ($_GET['q'] ?? ''));
if (function_exists('mb_substr')) { $giaInitialQ = mb_substr($giaInitialQ, 0, 500); }

// BRAND COLOR: drive the page off settings.brand_color (parity with the widget),
// with derived light/dark/soft shades for gradients + washes. Falls back to the
// long-standing blue. Fixes the prior hardcoded #0058E6 (not brand-aware).
$giaBrand = trim((string) ($GLOBALS['app']['brand_color'] ?? ''));
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $giaBrand)) { $giaBrand = '#0058E6'; }
$giaHexAdjust = static function (string $hex, float $factor): string {
    $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2));
    $mix = static fn($c) => max(0, min(255, (int) round($factor >= 1 ? $c + (255 - $c) * ($factor - 1) : $c * $factor)));
    return sprintf('#%02x%02x%02x', $mix($r), $mix($g), $mix($b));
};
$giaBrandLight = $giaHexAdjust($giaBrand, 1.35);
$giaBrandDark  = $giaHexAdjust($giaBrand, 0.82);
$giaBrandSoft  = $giaHexAdjust($giaBrand, 1.88);
$e = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

$giaCfg = json_encode([
    'endpoint'  => root . 'api/gia/chat',
    'csrfToken' => class_exists('CSRF') ? CSRF::getToken() : '',
    'brand'     => $giaBrand,
    'root'      => root,
    'pusher'    => $giaPagePusher,
    'initialQuery' => $giaInitialQ,
], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);
?>
<style>
  [x-cloak]{display:none!important}
  :root{
    --gia-brand:<?= $e($giaBrand) ?>;
    --gia-brand-light:<?= $e($giaBrandLight) ?>;
    --gia-brand-dark:<?= $e($giaBrandDark) ?>;
    --gia-brand-soft:<?= $e($giaBrandSoft) ?>;
    --gia-ink:#0f172a;
  }
  .gia-wrap{max-width:860px}
  /* Side drawer: open a result/listing page in an iframe without leaving the chat */
  .gia-drawer{position:fixed;top:0;right:0;z-index:10000;height:100vh;width:min(640px,100vw);
    background:#fff;box-shadow:-16px 0 48px rgba(0,0,0,.22);display:flex;flex-direction:column;
    animation:gia-slide-in .28s ease both}
  @keyframes gia-slide-in{from{transform:translateX(100%)}to{transform:none}}
  .gia-drawer-head{display:flex;align-items:center;gap:8px;padding:12px 16px;border-bottom:1px solid #eef2f7;background:#f8fafc}
  .gia-drawer-ttl{font-weight:700;font-size:.9rem;color:#0f172a;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .gia-drawer-btn{border:0;background:#eef2f7;color:#334155;border-radius:8px;width:32px;height:32px;cursor:pointer;display:flex;align-items:center;justify-content:center}
  .gia-drawer-btn:hover{background:#e2e8f0}
  .gia-drawer-body{flex:1;border:0;width:100%}
  .gia-scrim{position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.35)}
  @media (max-width:680px){.gia-drawer{width:100vw}}
  @media (prefers-reduced-motion:reduce){.gia-drawer{animation:none}}
  .gia-scroll{scrollbar-width:thin}
  .gia-scroll::-webkit-scrollbar{width:6px}
  .gia-scroll::-webkit-scrollbar-thumb{background:#cbd5e1;border-radius:3px}
  .gia-bubble-user{background:linear-gradient(135deg,var(--gia-brand),var(--gia-brand-dark));color:#fff;border-radius:18px 18px 5px 18px;box-shadow:0 2px 8px color-mix(in srgb,var(--gia-brand) 22%,transparent)}
  .gia-bubble-ai{background:#fff;color:var(--gia-ink);border-radius:18px 18px 18px 5px;box-shadow:0 1px 3px rgba(15,23,42,.08);border:1px solid rgba(15,23,42,.05)}
  /* Message entrance — gentle rise+fade (parity with the widget). */
  .gia-msg-in{animation:gia-msg .32s cubic-bezier(.2,.8,.2,1) both}
  @keyframes gia-msg{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:none}}
  @media (prefers-reduced-motion:reduce){.gia-msg-in{animation:none}}
  .gia-typing span{display:inline-block;width:6px;height:6px;margin:0 1px;border-radius:50%;background:#94a3b8;animation:gia-blink 1.2s infinite both}
  .gia-typing span:nth-child(2){animation-delay:.2s}.gia-typing span:nth-child(3){animation-delay:.4s}
  @keyframes gia-blink{0%,80%,100%{opacity:.3}40%{opacity:1}}
  .gia-card{border:1px solid #e2e8f0;border-radius:14px;background:#fff;box-shadow:0 1px 3px rgba(15,23,42,.06)}
  /* ===== PREMIUM TRAVEL CARD SYSTEM (parity with the floating widget) ===== */
  .gia-opt{display:block;border:1px solid #e8edf3;border-radius:16px;background:#fff;overflow:hidden;
    box-shadow:0 1px 2px rgba(15,23,42,.05);transition:transform .16s ease, box-shadow .16s ease, border-color .16s;
    animation:gia-card-in .36s cubic-bezier(.16,1,.3,1) both}
  .gia-opt:hover{transform:translateY(-2px);box-shadow:0 14px 32px rgba(15,23,42,.13);border-color:color-mix(in srgb,var(--gia-brand) 35%,#e8edf3)}
  @keyframes gia-card-in{from{opacity:0;transform:translateY(12px) scale(.98)}to{opacity:1;transform:none}}
  /* Rich-banner: photo when present, else a generated brand-gradient banner
     with the module motif; title/location overlaid. (parity with the widget) */
  .gia-opt-ban{position:relative;height:132px;overflow:hidden;
    background:linear-gradient(135deg,var(--gia-brand),var(--gia-brand-dark))}
  .gia-opt-ban img{width:100%;height:100%;object-fit:cover;display:block}
  .gia-opt-scrim{position:absolute;inset:0;background:linear-gradient(180deg,rgba(2,8,23,0) 34%,rgba(2,8,23,.58) 100%)}
  .gia-opt-motif{position:absolute;right:-6px;bottom:-28px;color:#fff;opacity:.16;pointer-events:none;line-height:1}
  .gia-opt-motif .material-symbols-outlined{font-size:160px}
  .gia-opt-ban-ttl{position:absolute;left:15px;right:15px;bottom:12px;color:#fff;z-index:1}
  .gia-opt-live{position:absolute;top:11px;left:12px;z-index:1;display:inline-flex;align-items:center;gap:5px;
    background:rgba(255,255,255,.95);color:#15803d;font-size:10px;font-weight:800;letter-spacing:.04em;
    text-transform:uppercase;padding:4px 9px;border-radius:999px;box-shadow:0 1px 3px rgba(15,23,42,.18)}
  .gia-opt-live i{width:6px;height:6px;border-radius:50%;background:#22c55e;display:inline-block;animation:gia-blink 1.4s infinite}
  .gia-opt-body{padding:13px 15px}
  /* base title/sub (dark, used on the summary card); banner variants -> white */
  .gia-opt-ttl{font-size:15px;font-weight:800;color:#0f172a;line-height:1.25;letter-spacing:-.01em}
  .gia-opt-sub{font-size:12px;color:#64748b;margin-top:2px}
  .gia-opt-ban-ttl .gia-opt-ttl{font-size:17px;color:#fff;text-shadow:0 1px 6px rgba(2,8,23,.35);
    display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .gia-opt-ban-ttl .gia-opt-sub{font-weight:600;color:rgba(255,255,255,.92);text-shadow:0 1px 4px rgba(2,8,23,.35);
    white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .gia-opt-badges{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}
  .gia-opt-badge{display:inline-flex;align-items:center;gap:4px;font-size:11px;font-weight:700;color:#334155;
    background:#f1f5f9;border-radius:999px;padding:4px 10px;line-height:1.4}
  .gia-opt-badge .material-symbols-outlined{font-size:14px;color:var(--gia-brand)}
  .gia-opt-price{font-size:19px;font-weight:850;color:#0b1220;line-height:1}
  .gia-opt-price .cur{font-size:12px;font-weight:700;color:#64748b;margin-right:2px}
  .gia-opt-from{font-size:10px;color:#94a3b8;text-transform:uppercase;letter-spacing:.04em;margin-bottom:2px}
  .gia-opt-cta{display:inline-flex;align-items:center;gap:5px;color:#fff;flex:none;
    background:linear-gradient(135deg,var(--gia-brand),var(--gia-brand-dark));
    font-size:13px;font-weight:750;padding:9px 16px;border-radius:11px;white-space:nowrap;cursor:pointer;
    box-shadow:0 3px 10px color-mix(in srgb,var(--gia-brand) 30%,transparent);transition:filter .14s}
  .gia-opt:hover .gia-opt-cta{filter:brightness(1.07)}
  .gia-opt-cta .material-symbols-outlined{font-size:17px}
  .gia-pay{border:1px solid #e8edf3;border-radius:16px;background:#fff;overflow:hidden;box-shadow:0 2px 12px rgba(15,23,42,.07);
    animation:gia-card-in .36s cubic-bezier(.16,1,.3,1) both}
  .gia-pay-top{padding:15px 16px 13px;background:linear-gradient(180deg,color-mix(in srgb,var(--gia-brand) 5%,#fff),#fff)}
  .gia-pay-amt{font-size:30px;font-weight:850;color:#0b1220;line-height:1;margin-top:9px;letter-spacing:-.02em}
  .gia-pay-amt .cur{font-size:15px;font-weight:700;color:#64748b;margin-right:2px}
  .gia-pay-btn{width:100%;border:0;cursor:pointer;color:#fff;font-size:14px;font-weight:750;
    padding:13px;display:flex;align-items:center;justify-content:center;gap:7px;
    background:linear-gradient(135deg,var(--gia-brand),var(--gia-brand-dark));transition:filter .14s}
  .gia-pay-btn:hover{filter:brightness(1.06)}
  .gia-pay-btn:disabled{opacity:.6;cursor:default}
  .gia-pay-btn .material-symbols-outlined{font-size:19px}
  .gia-pay-btn--go{background:linear-gradient(135deg,#16a34a,#15803d)}
  .gia-summary{display:flex;align-items:center;gap:12px;border:1px solid #e8edf3;border-radius:16px;background:#fff;
    padding:13px 15px;box-shadow:0 1px 2px rgba(15,23,42,.05);
    transition:transform .16s ease, box-shadow .16s ease, border-color .16s;
    animation:gia-card-in .36s cubic-bezier(.16,1,.3,1) both}
  .gia-summary:hover{transform:translateY(-2px);box-shadow:0 14px 32px rgba(15,23,42,.13);border-color:color-mix(in srgb,var(--gia-brand) 35%,#e8edf3)}
  .gia-summary-ico{width:44px;height:44px;flex:none;border-radius:12px;background:var(--gia-brand-soft);color:var(--gia-brand);
    display:flex;align-items:center;justify-content:center}
  .gia-summary-ico .material-symbols-outlined{font-size:23px}
  /* Tactile press feedback (parity with the widget). */
  .gia-opt:active,.gia-summary:active{transform:translateY(0) scale(.99)}
  .gia-opt-cta:active,.gia-pay-btn:active:not(:disabled),.gia-qr:active{transform:scale(.97)}
  @media (prefers-reduced-motion:reduce){.gia-opt,.gia-opt:hover,.gia-pay,.gia-summary,.gia-summary:hover,.gia-opt:active,.gia-summary:active,.gia-opt-cta:active,.gia-pay-btn:active,.gia-qr:active{animation:none;transform:none}.gia-opt-live i{animation:none}}
  .gia-chip{font-size:.7rem;font-weight:600;letter-spacing:.03em;text-transform:uppercase;padding:3px 10px;border-radius:999px}
  /* Starter/quick-reply chips lift on hover (parity). */
  .gia-qr{transition:transform .14s ease, box-shadow .14s ease, background .14s}
  .gia-qr:hover{transform:translateY(-1px);box-shadow:0 3px 10px color-mix(in srgb,var(--gia-brand) 18%,transparent)}
  @media (prefers-reduced-motion:reduce){.gia-qr:hover{transform:none}}
  /* 16px composer prevents iOS zoom-on-focus (parity). */
  .gia-input{font-size:16px}
  /* Live presence dot in the header. */
  .gia-presence{position:absolute;right:-1px;bottom:-1px;width:12px;height:12px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 2.5px #fff}
</style>

<div class="bg-slate-50 min-h-[70vh] py-6" x-data='giaChat(<?= $giaCfg ?>)' x-init="init()" x-cloak>
  <div class="gia-wrap mx-auto px-4">

    <!-- Header -->
    <div class="flex items-center gap-3 mb-4">
      <div class="relative w-11 h-11 rounded-full flex items-center justify-center text-white shrink-0"
           style="background:linear-gradient(135deg,var(--gia-brand),var(--gia-brand-light));box-shadow:0 4px 14px color-mix(in srgb,var(--gia-brand) 30%,transparent)">
        <span class="material-symbols-outlined">travel_explore</span>
        <span class="gia-presence" aria-hidden="true"></span>
      </div>
      <div class="flex-1">
        <h1 class="text-lg font-bold text-slate-800 leading-tight">GIA — your travel assistant</h1>
        <p class="text-xs text-slate-500 flex items-center gap-1.5">
          <span class="inline-block w-1.5 h-1.5 rounded-full" style="background:#22c55e"></span>
          Online now · searches live options and prepares a checkout — you always approve before anything is paid.
        </p>
      </div>
      <button type="button" @click="newChat()" x-show="messages.length > 0"
              class="shrink-0 inline-flex items-center gap-1 text-xs font-semibold px-3 py-1.5 rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">
        <span class="material-symbols-outlined text-[16px]">add_comment</span> New chat
      </button>
    </div>

    <!-- AI disclosure (BRD CON-006) -->
    <div class="flex items-center gap-2 mb-3 text-[11px] text-slate-500">
      <span class="material-symbols-outlined text-[14px]">smart_toy</span>
      You're chatting with an AI assistant. It can search and prepare bookings; it cannot confirm or pay without your explicit approval.
    </div>

    <!-- Conversation -->
    <div class="gia-card p-4 sm:p-5">
      <div class="mb-3 px-3 py-1.5 text-xs rounded-lg bg-emerald-50 text-emerald-700 border border-emerald-100 flex items-center gap-1.5" x-show="staffActive" x-cloak>
        <span class="material-symbols-outlined text-[15px]">support_agent</span> A team member is helping you on this chat.
      </div>
      <div class="gia-scroll overflow-y-auto pr-1" style="max-height:52vh" x-ref="scroll"
           role="log" aria-live="polite" aria-relevant="additions text" aria-label="Conversation with GIA">

        <!-- Empty state -->
        <template x-if="messages.length === 0">
          <div class="text-center py-10">
            <p class="text-slate-600 font-medium mb-4">Where would you like to go?</p>
            <div class="flex flex-wrap gap-2 justify-center">
              <template x-for="s in starters" :key="s">
                <button type="button" class="gia-qr text-xs rounded-full px-3 py-1.5"
                        style="color:var(--gia-brand);background:var(--gia-brand-soft);border:1px solid color-mix(in srgb,var(--gia-brand) 22%,#fff)"
                        @click="send(s)" x-text="s"></button>
              </template>
            </div>
          </div>
        </template>

        <!-- Messages -->
        <template x-for="(m, i) in messages" :key="i">
          <div class="mb-4 gia-msg-in" :class="m.role === 'user' ? 'flex justify-end' : 'flex justify-start'">
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
                            <a :href="sr.live ? '#' : cardUrl(sr, c)"
                               @click.prevent="sr.live ? pickOffer(sr, c) : openDrawer(cardUrl(sr, c), c.name || c.title || 'Details')"
                               class="gia-opt cursor-pointer" :style="'animation-delay:' + (ci * 60) + 'ms'">
                              <!-- Banner: real photo if present, else a generated
                                   brand-gradient banner with the module motif. Title +
                                   location sit overlaid; live-fare badge when applicable. -->
                              <div class="gia-opt-ban" :class="c.image ? 'gia-opt-ban--photo' : ''">
                                <template x-if="c.image">
                                  <img :src="c.image" :alt="c.name || c.title || 'Package'" loading="lazy"
                                       @error="const b=$event.target.closest('.gia-opt-ban'); b.classList.remove('gia-opt-ban--photo'); b.querySelector('.gia-opt-motif').style.display='block'; $event.target.remove()">
                                </template>
                                <span class="gia-opt-motif" :style="c.image ? 'display:none' : ''">
                                  <span class="material-symbols-outlined" x-text="moduleIcon(sr.module || sr.kind)"></span>
                                </span>
                                <div class="gia-opt-scrim"></div>
                                <template x-if="sr.live">
                                  <span class="gia-opt-live"><i></i> Live fare</span>
                                </template>
                                <div class="gia-opt-ban-ttl">
                                  <div class="gia-opt-ttl" x-text="c.name || c.title || 'Option'"></div>
                                  <div class="gia-opt-sub" x-show="c.subtitle || c.location" x-text="c.subtitle || c.location || ''"></div>
                                </div>
                              </div>
                              <div class="gia-opt-body">
                                <!-- honest detail badges (real fields only) -->
                                <div class="gia-opt-badges" x-show="giaCardBadges(c).length">
                                  <template x-for="(b, bi) in giaCardBadges(c)" :key="bi">
                                    <span class="gia-opt-badge"><span class="material-symbols-outlined" x-text="b.icon"></span><span x-text="b.label"></span></span>
                                  </template>
                                </div>
                                <!-- inclusions chips (tours/umrah supply these) -->
                                <template x-if="c.inclusions && c.inclusions.length">
                                  <div class="flex flex-wrap gap-1 mt-2">
                                    <template x-for="(inc, ii) in c.inclusions.slice(0, 4)" :key="ii">
                                      <span class="text-[10px] bg-slate-100 text-slate-600 rounded-full px-2 py-0.5" x-text="inc.name"></span>
                                    </template>
                                  </div>
                                </template>
                                <div class="flex items-end justify-between gap-3 mt-3">
                                  <div>
                                    <div class="gia-opt-from" x-text="(c.price != null && (c.adults || c.children || c.infants)) ? 'Total' : 'From'"></div>
                                    <div class="gia-opt-price" x-html="moneyHtml(c.price != null ? c.price : c.adult_price, c.currency)"></div>
                                  </div>
                                  <span class="gia-opt-cta">
                                    <span x-text="sr.live ? 'Select' : 'View & book'"></span>
                                    <span class="material-symbols-outlined">arrow_forward</span>
                                  </span>
                                </div>
                              </div>
                            </a>
                          </template>
                          <!-- link to all results when there are more -->
                          <template x-if="sr.listing_url && sr.cards.length >= 6">
                            <a :href="absUrl(sr.listing_url)" @click.prevent="openDrawer(absUrl(sr.listing_url), 'All ' + (sr.module || '') + ' options')"
                               class="block text-center text-xs text-slate-500 hover:text-slate-700 py-1 cursor-pointer" x-text="'See all ' + (sr.module || '') + ' options'"></a>
                          </template>
                        </div>
                      </template>

                      <!-- Live-supplier deep-link card (flights/cars/esim/rail/ferries):
                           a rich "See live options" card — real fares load on the
                           results page (external API too slow to fetch inline). -->
                      <template x-if="!(sr.cards && sr.cards.length) && hasSummary(sr)">
                        <a href="#" @click.prevent="isLiveModule(sr.module || sr.kind) ? pickLive(sr) : (sr.listing_url && openDrawer(absUrl(sr.listing_url), summaryHeadline(sr)))"
                           class="gia-summary cursor-pointer">
                          <span class="gia-summary-ico"><span class="material-symbols-outlined" x-text="moduleIcon(sr.module)"></span></span>
                          <div class="min-w-0 flex-1">
                            <div class="gia-opt-ttl" x-text="summaryHeadline(sr)"></div>
                            <div class="flex flex-wrap gap-x-3 gap-y-0.5 gia-opt-sub">
                              <template x-for="(ln, li) in summaryLines(sr)" :key="li"><span x-text="ln"></span></template>
                            </div>
                          </div>
                          <span class="gia-opt-cta"><span x-text="summaryCta(sr)"></span><span class="material-symbols-outlined">arrow_forward</span></span>
                        </a>
                      </template>

                      <!-- Intent-only (no summary at all): a tappable deep-link chip -->
                      <template x-if="!(sr.cards && sr.cards.length) && !hasSummary(sr)">
                        <a :href="sr.listing_url ? absUrl(sr.listing_url) : '#'"
                           @click.prevent="sr.listing_url && openDrawer(absUrl(sr.listing_url), sr.title || sr.module || 'Details')"
                           class="gia-card px-3 py-2 flex items-center gap-2"
                           :class="sr.listing_url ? 'hover:bg-slate-50 transition cursor-pointer' : ''">
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

              <!-- Soft lead-capture card (once, after GIA has shown value) -->
              <template x-if="m.lead && !leadDone">
                <div class="mt-2 gia-card p-4">
                  <div class="flex items-center gap-2 mb-2">
                    <span class="material-symbols-outlined text-[18px]" style="color:var(--gia-brand)">bookmark_add</span>
                    <span class="text-sm font-semibold text-slate-700">Save your trip</span>
                    <button type="button" class="ml-auto text-slate-400 hover:text-slate-600" @click="dismissLead()" aria-label="No thanks">
                      <span class="material-symbols-outlined text-[18px]">close</span>
                    </button>
                  </div>
                  <input x-model="leadName" type="text" placeholder="Name (optional)"
                         class="w-full mb-2 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200">
                  <input x-model="leadEmail" type="email" placeholder="Email"
                         class="w-full mb-2 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200">
                  <input x-model="leadPhone" type="tel" placeholder="Phone (optional)"
                         class="w-full mb-2 rounded-lg border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-blue-200">
                  <p class="text-xs text-red-600 mb-1" x-show="leadMsg" x-text="leadMsg"></p>
                  <div class="flex items-center gap-2">
                    <button type="button" class="flex-1 py-2.5 rounded-lg text-white font-semibold text-sm disabled:opacity-60" style="background:var(--gia-brand)" :disabled="sending" @click="saveLead()">Save &amp; continue</button>
                    <button type="button" class="text-xs text-slate-500 hover:text-slate-700 px-3" @click="dismissLead()">No thanks</button>
                  </div>
                </div>
              </template>

              <!-- Checkout card -> Approve -->
              <template x-if="m.checkout">
                <div class="mt-2 gia-pay">
                  <div class="gia-pay-top">
                    <div class="flex items-center justify-between">
                      <span class="gia-chip bg-amber-50 text-amber-700">Review &amp; approve</span>
                      <span class="text-[11px] text-slate-400" x-text="m.checkout.expires_at ? ('Expires ' + m.checkout.expires_at) : ''"></span>
                    </div>
                    <div class="gia-pay-amt" x-html="moneyHtml(m.checkout.amount, m.checkout.currency)"></div>
                    <p class="text-xs text-slate-500 mt-1.5">Total · nothing is charged until you approve and complete payment.</p>
                  </div>
                  <button type="button" class="gia-pay-btn" :disabled="sending" @click="approveCheckout()">
                    <span class="material-symbols-outlined">lock</span>
                    Approve <span x-text="money(m.checkout.amount, m.checkout.currency)"></span>
                  </button>
                </div>
              </template>

              <!-- Payment redirect -->
              <template x-if="m.payment && m.payment.redirect_url">
                <div class="mt-2 gia-pay">
                  <div class="gia-pay-top">
                    <span class="gia-chip bg-green-50 text-green-700 inline-block">Approved — pay securely</span>
                    <p class="text-xs text-slate-500 mt-2">Your approval is locked in. Complete the secure payment right here to confirm your booking.</p>
                  </div>
                  <!-- Pay IN-CHAT: secure payment page loads in the side drawer. -->
                  <button type="button" @click="openPayment(m.payment.redirect_url)" class="gia-pay-btn gia-pay-btn--go">
                    <span class="material-symbols-outlined">verified_user</span> Pay securely now
                  </button>
                  <a :href="absUrl(m.payment.redirect_url)" target="_blank" rel="noopener"
                     class="block text-center text-[11px] text-slate-400 mt-1.5 hover:text-slate-600">or open in a new tab</a>
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
                    aria-label="Message GIA"
                    placeholder="e.g. 2 adults from Abuja to Dubai, Nov 10-17, 4-star hotel"
                    class="gia-input flex-1 resize-none rounded-xl border border-slate-200 px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-blue-200"></textarea>
          <button type="submit" :disabled="sending || !draft.trim()" aria-label="Send message"
                  class="shrink-0 w-11 h-11 rounded-xl text-white flex items-center justify-center disabled:opacity-50"
                  style="background:linear-gradient(135deg,var(--gia-brand),var(--gia-brand-dark))">
            <span class="material-symbols-outlined">send</span>
          </button>
        </form>
        <p class="text-[10px] text-slate-400 mt-1.5">GIA can make mistakes. Verify prices and details before you pay.</p>
      </div>
    </div>
  </div>

  <!-- Side drawer: explore + book a result in an iframe without leaving the chat -->
  <template x-if="drawer">
    <div>
      <div class="gia-scrim" @click="closeDrawer()"></div>
      <div class="gia-drawer" @keydown.escape.window="closeDrawer()">
        <div class="gia-drawer-head">
          <span class="material-symbols-outlined" style="color:var(--gia-brand);font-size:20px">travel_explore</span>
          <span class="gia-drawer-ttl" x-text="drawerTitle"></span>
          <a :href="drawerUrl" target="_blank" rel="noopener" class="gia-drawer-btn" title="Open in new tab">
            <span class="material-symbols-outlined" style="font-size:18px">open_in_new</span>
          </a>
          <button type="button" class="gia-drawer-btn" @click="closeDrawer()" title="Close">
            <span class="material-symbols-outlined" style="font-size:18px">close</span>
          </button>
        </div>
        <iframe class="gia-drawer-body" :src="drawerUrl" title="Trip details"
                referrerpolicy="same-origin" loading="lazy"></iframe>
      </div>
    </div>
  </template>
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
      'Flights from Abuja to Dubai next month',
      'Family-friendly hotel in Zanzibar for a week',
      'Plan a 5-day trip to Dubai under ₦2.5m',
    ],

    leadShown: false, leadDone: false, leadName: '', leadEmail: '', leadPhone: '', leadMsg: '',
    drawer: false, drawerUrl: '', drawerTitle: '',
    staffActive: false, pollTimer: null,
    pusher: (cfg.pusher || {}), convKey: '', rtChannel: null, rtClient: null,

    init() {
      this.hydrate();
      // If arrived with an initial query (homepage AI box fallback), send it once.
      const q = (cfg.initialQuery || '').trim();
      if (q) { this.$nextTick(() => this.send(q)); }
    },

    // Realtime (Pusher) delivery of staff replies; polling is the fallback.
    subscribeRealtime() {
      if (this.rtChannel || !this.convKey) return;
      var key = this.pusher && this.pusher.key;
      if (!key) return;
      var self = this;
      var start = function () {
        try {
          if (!self.rtClient) { self.rtClient = new Pusher(key, { cluster: (self.pusher.cluster || 'us2'), forceTLS: true }); }
          self.rtChannel = self.rtClient.subscribe('gia-conv-' + self.convKey);
          self.rtChannel.bind('gia-staff-reply', function (data) {
            if (!data || !data.text) return;
            self.staffActive = true;
            self.messages.push({ role: 'ai', text: String(data.text) });
            self.$nextTick(() => self.scrollDown());
          });
        } catch (e) {}
      };
      if (typeof Pusher !== 'undefined') { start(); return; }
      var s = document.createElement('script'); s.src = 'https://js.pusher.com/8.0.1/pusher.min.js';
      s.onload = start; document.head.appendChild(s);
    },
    startPolling() { if (this.pollTimer) return; this.pollTimer = setInterval(() => this.syncStaff(), 8000); },
    stopPolling() {
      if (this.pollTimer) { clearInterval(this.pollTimer); this.pollTimer = null; }
      try { if (this.rtChannel && this.rtClient) { this.rtClient.unsubscribe('gia-conv-' + this.convKey); } } catch (e) {}
      this.rtChannel = null;
    },
    async syncStaff() {
      try {
        const res = await fetch(this.root + 'api/gia/history', { headers: { 'X-CSRF-TOKEN': this.csrfToken } });
        const d = await res.json().catch(() => ({}));
        if (d && Array.isArray(d.messages) && d.messages.length > this.messages.length) {
          this.messages = d.messages.map(m => ({ role: m.role === 'user' ? 'user' : 'ai', text: m.text || '' }));
          this.$nextTick(() => this.scrollDown());
        }
        if (d) { this.staffActive = !!d.staff_active; if (!d.staff_active) this.stopPolling(); }
      } catch (e) {}
    },

    async newChat() {
      if (this.sending) return;
      try { await fetch(this.root + 'api/gia/reset', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken }, body: JSON.stringify({ csrf_token: this.csrfToken }) }); } catch (e) {}
      this.stopPolling(); this.convKey = '';
      this.messages = []; this.leadShown = false; this.leadDone = false; this.staffActive = false;
      this.$nextTick(() => { if (this.$refs.input) this.$refs.input.focus(); this.scrollDown(); });
    },

    // Open a result/listing page in a side drawer (iframe) — stay in the chat.
    openDrawer(url, title) { if (!url || url === '#') return; this.drawerUrl = url; this.drawerTitle = title || 'Details'; this.drawer = true; },
    closeDrawer() { this.drawer = false; this.drawerUrl = ''; },
    // Picking a LIVE offer -> ask GIA to select_option(module, ref).
    pickOffer(sr, c) {
      if (this.sending) return;
      const mod = (c && (c.module || c.kind)) || (sr && sr.module) || '';
      const ref = (c && (c.ref || c.id)) || '';
      const name = (c && (c.name || c.title)) || 'this option';
      if (!mod || !ref) { this.send('I\'d like to book ' + name); return; }
      this.send('Please select_option module=' + mod + ' id=' + ref + ' — I want to book "' + name + '".');
    },
    isLiveModule(mod) { return ['flights','cars','rail','ferries','esim'].indexOf(String(mod || '').toLowerCase()) !== -1; },
    // Live-module intent card tapped: run the LIVE supplier search in the chat
    // (GIA calls fetch_live_offers) rather than opening the web listing drawer.
    pickLive(sr) {
      if (this.sending) return;
      const mod = (sr && (sr.module || sr.kind)) || '';
      if (!mod) return;
      this.send('Show me the live ' + mod + ' options now so I can pick one.');
    },
    // Same-origin payment pages load in the drawer; an external gateway URL opens
    // in a new tab (it can't be framed).
    openPayment(url) {
      if (!url) return;
      const u = this.absUrl(url);
      let sameOrigin = false;
      try { sameOrigin = new URL(u, window.location.href).origin === window.location.origin; } catch (e) {}
      if (sameOrigin) { this.openDrawer(u, 'Secure payment'); } else { window.open(u, '_blank', 'noopener'); }
    },

    async hydrate() {
      try {
        const res = await fetch(this.root + 'api/gia/history', { headers: { 'X-CSRF-TOKEN': this.csrfToken } });
        const d = await res.json().catch(() => ({}));
        if (d && Array.isArray(d.messages) && d.messages.length) {
          this.messages = d.messages.map(m => ({ role: m.role === 'user' ? 'user' : 'ai', text: m.text || '' }));
          this.$nextTick(() => this.scrollDown());
        }
        if (d && d.conv_key) { this.convKey = d.conv_key; }
        if (d && d.staff_active) { this.staffActive = true; this.subscribeRealtime(); this.startPolling(); }
      } catch (e) { /* fail-soft: fresh start */ }
    },
    maybeOfferLead() {
      if (this.leadShown || this.leadDone) return;
      this.leadShown = true;
      this.$nextTick(() => {
        this.messages.push({ role: 'ai', lead: true, text: "Want me to save these and send you the details? Drop your email or phone and I'll keep your trip handy — and tell you if prices change." });
        this.scrollDown();
      });
    },
    async saveLead() {
      if (this.sending) return;
      const email = (this.leadEmail || '').trim(), phone = (this.leadPhone || '').trim();
      if (!email && !phone) { this.leadMsg = 'Please add an email or phone.'; return; }
      this.sending = true; this.leadMsg = '';
      try {
        const res = await fetch(this.root + 'api/gia/lead', {
          method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfToken },
          body: JSON.stringify({ csrf_token: this.csrfToken, surface: 'page', name: this.leadName, email, phone }),
        });
        const d = await res.json().catch(() => ({}));
        if (d && d.status) { this.leadDone = true; this.messages.push({ role: 'ai', text: d.message || "Saved! I'll keep your trip handy." }); }
        else { this.leadMsg = (d && d.message) || 'Could not save — check your details.'; }
      } catch (e) { this.leadMsg = 'Could not save right now.'; }
      finally { this.sending = false; this.$nextTick(() => this.scrollDown()); }
    },
    dismissLead() { this.leadDone = true; },

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
        const showedValue = (Array.isArray(data.searches) && data.searches.length) || !!data.checkout;
        this.messages.push({
          role: 'ai',
          text: data.reply || (res.ok ? 'Done.' : 'Sorry, something went wrong. Please try again.'),
          searches: Array.isArray(data.searches) ? data.searches : null,
          checkout: data.checkout || null,
          payment: data.payment || null,
          order_status: data.order_status || null,
        });
        if (showedValue) this.maybeOfferLead();
        if (data.conv_key) { this.convKey = data.conv_key; }
        if (data.handoff || data.staff_active) { this.staffActive = true; this.subscribeRealtime(); this.startPolling(); }
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
    // HTML variant: currency code in a small .cur span. Currency is supplier-
    // supplied, so sanitize it (rendered via x-html).
    moneyHtml(amt, cur) {
      const n = Number(amt || 0);
      const a = n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
      const c = String(cur || '').replace(/[^A-Za-z0-9$€£₦.]/g, '').slice(0, 6);
      return (c ? '<span class="cur">' + c + '</span>' : '') + a;
    },
    // --- Deep-link summary helpers (flights use a rich route/date/pax/cabin shape;
    //     cars/esim/rail/ferries use a generic headline/line1/line2/cta shape). ---
    hasSummary(sr) {
      const s = sr && sr.summary;
      if (!s || typeof s !== 'object') return false;
      return !!(s.origin_label || s.origin_code || s.headline);
    },
    summaryHeadline(sr) {
      const s = sr.summary || {};
      if (s.headline) return s.headline;
      // flight shape
      const o = s.origin_label || s.origin_code || '';
      const d = s.destination_label || s.destination_code || '';
      const sep = s.trip_type === 'return' ? ' ⇄ ' : ' → ';
      return (o && d) ? (o + sep + d) : (sr.title || 'Options');
    },
    summaryLines(sr) {
      const s = sr.summary || {};
      if (s.headline !== undefined) {
        // generic shape
        return [s.line1, s.line2].filter(x => x && String(x).trim() !== '');
      }
      // flight shape
      const out = [];
      if (s.trip_label) out.push(s.trip_label);
      if (s.departure_date) out.push('📅 ' + s.departure_date + (s.return_date ? ' → ' + s.return_date : ''));
      if (s.travellers) out.push('👤 ' + s.travellers);
      if (s.cabin) out.push(s.cabin);
      return out;
    },
    summaryCta(sr) {
      const s = sr.summary || {};
      if (s.cta) return s.cta;
      return (sr.module === 'flights') ? 'Search live flights' : 'See live options';
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
    // Detail badges for a result card — only from fields the card actually carries
    // (attachLocalCards builders). Never invents claims. Max 3.
    giaCardBadges(c) {
      const out = []; if (!c || typeof c !== 'object') return out;
      const days = Number(c.days || 0), nights = Number(c.nights || 0), stars = Number(c.stars || 0), rating = Number(c.rating || 0);
      if (days > 0) out.push({ icon:'schedule', label: days + ' day' + (days > 1 ? 's' : '') });
      else if (nights > 0) out.push({ icon:'bedtime', label: nights + ' night' + (nights > 1 ? 's' : '') });
      if (stars > 0) out.push({ icon:'star', label: stars + '-star' });
      if (rating > 0 && rating <= 5) out.push({ icon:'thumb_up', label: rating.toFixed(1) + ' rating' });
      return out.slice(0, 3);
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
    // Safety net: the model is told not to use markdown tables, but if one slips
    // through, degrade "| a | b |" rows to "a · b" and drop |---| separators so a
    // table never renders as raw pipes.
    stripTables(s) {
      return String(s || '').split('\n').map(line => {
        const m = line.match(/^\s*\|(.+)\|\s*$/);
        if (!m) return line;
        const cells = m[1].split('|').map(c => c.trim());
        if (cells.every(c => /^:?-{2,}:?$/.test(c) || c === '')) return '';
        return cells.filter(c => c !== '').join(' · ');
      }).filter((l, i, a) => !(l === '' && a[i - 1] === '')).join('\n');
    },
    // Minimal, SAFE markdown: escape first, then allow **bold**, *italic*, line breaks,
    // and bullet lines. No raw HTML from the model is ever injected.
    renderMarkdown(s) {
      let t = this.escapeHtml(this.stripTables(s));
      t = t.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
      t = t.replace(/(^|[^*])\*([^*]+)\*/g, '$1<em>$2</em>');
      t = t.replace(/^\s*[-•]\s+(.*)$/gm, '<span class="block pl-3">• $1</span>');
      return t;
    },
  };
}
</script>
