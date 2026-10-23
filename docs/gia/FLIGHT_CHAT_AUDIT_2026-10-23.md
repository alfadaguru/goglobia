# GIA Flight Chat — Deep Audit (2026-10-23)

5-agent read-only audit. Every claim is file:line-verified. Scope: flight variety,
result overflow, chat beautification, expiry timer. GIA = chat (web widget +
WhatsApp/Telegram/Messenger), NOT the website.

## KEY INSIGHT
Adivaha ALREADY returns rich data; GIA discards it. `modules/flights/adivaha/search.php`
builds per-leg `meta.legs[]` with duration/stops/baggage/refundable/arrival/seats/cabin/
fare_name, but `gia_flights.php:268` reads ONLY `segments[0][0]`. Fixes = extract+display,
not new integrations.

## FINDINGS (ranked)

### A. Chat beautification — WhatsApp triple-render (ROOT of owner complaint)
- Green API can't do native buttons -> `gia_channel_render.php:24-49` prints model prose
  list + card list + numbered menu = same flights up to 3x.
- Web widget is fine (cards+Select button, prompt forbids re-list): `gia-widget.php:403-451`,
  `giaAgent.php:724-737`. Meta Cloud API renders native buttons; Green API (ours) does not.

### B. Round-trip return leg invisible
- Card shows outbound only; price is round-trip. `gia_flights.php:268` reads segments[0][0].
  Return leg is in `segments[1]`/`meta.legs[1]` (`search.php:411-444`).

### C. Stops / duration / baggage / cabin / refundable / seats — all ignored
- `search.php:334-384` builds them; `gia_flights.php:274-298` drops them. A 1-stop looks
  nonstop. No journey duration, no baggage, no refund policy on the card.

### D. Cabin class silently defaults economy, never honored
- `gia_flights.php:201` `?: 'economy'`; prompt never asks/uses it. "Business" -> economy results.

### E. Result overflow = silent truncation (violates no-silent-caps rule)
- 6 fares cap (`gia_flights.php:206,344`), model sees 4 (`giaAgent.php:458`), WhatsApp slices
  10 with no disclosure (`gia_channel.php:875`). No "N more", no refine (cheapest/morning/
  nonstop/airline), no pagination.

### F. Expiry timer inconsistent
- WhatsApp shows "expires in ~N min" on pay (`gia_channel.php:278-297`). GOOD.
- Web widget RECEIVES expires_at but renders NO countdown (`giaRoutes.php:764,784`;
  `gia_web_pay_options:543-554` drops it). Fare-hold ("price held N min") shown nowhere
  (`flightsTools.php:112` captured, excluded from model).

## DECISIONS
- Multi-city: DEFERRED (owner, 2026-10-23) — heaviest lift, rare; its own follow-up.
- "No stress to customer": keep origin/dest/date hard-required + 1-adult default; add smart
  defaults + at most ONE optional nudge (honor "business" if said; infer round-trip from
  return date). No interrogation.

## BUILD PLAN (this pass)
1. gia_flights.php: extract full offer — read meta.legs/duration/stops/baggage/refundable/
   arrival/seats/cabin/fare_name; richer card subtitle; carry return-leg summary.
2. Cabin: honor requested class; default economy but don't downgrade a stated business/first.
3. Overflow: raise cap sensibly + DISCLOSE "showing N cheapest of M" + let user refine
   (cheapest/earliest/nonstop/airline) via a re-search hint; stop silent WhatsApp slice.
4. WhatsApp de-clutter: when native cards/buttons render, DON'T also prose-list; tighten
   gia_render_text_fallback so prose + cards don't duplicate.
5. Expiry: surface fare-hold at select + payment countdown in the web widget (parity w/ WA).
