# GIA Ad-Campaign Runbook — Umrah Dec 16 & 25 + cheap local-flight promos

How to run a paid ad campaign so **GIA is fully aware of it** — recognises the lead
(web + WhatsApp), surfaces the real promo, converts with care, and re-engages quiet
leads. Everything here uses the admin UI (no SQL needed).

> **Prerequisite — cron:** none of the automated pieces (payment chase, pre/post comms,
> quiet-lead nurture) send unless the cron jobs in `docs/CRON.md` are scheduled on the
> server. For this campaign you especially need: `/gia_payments_sweep` (every min),
> `/gia_nurture_sweep` (hourly). Also set your WhatsApp (Green API) + email providers in
> Admin → Settings → Notifications, and verify with the **"Test pre/post comms"** button.

---

## 1. Seed the inventory first (GIA can only sell what's real)

### Umrah — the two December departures
Admin → **Umrah** (v2 departures dashboard). For EACH of 16 Dec and 25 Dec:
1. Create the departure with the real **departure_date** and **return_date** (the return
   date is what drives GIA's post-trip "welcome back" message).
2. Add at least one **tier** (Economy/Standard/VIP…) with a price.
3. To make GIA surface a **saving**, set the tier's **regular price** higher than the
   promo/current price (and set the promo window dates). GIA will then say *"Regular
   ₦X, your price ₦Y — you save Z%"* using ONLY those real figures. No promo set → GIA
   quotes the plain price and says nothing about a discount.
4. **Publish** both departures (GIA only shows published, priced departures).

GIA resolves "16 December" / "25 December" deterministically from the departure dates —
no code change, no hardcoding. Test in chat: *"book umrah for 16 December"*.

### Cheap local flights (Abuja↔Lagos etc.)
Flights are live-supplier, so you don't seed fares. To make a **flight promo** real:
Admin → **Discounts** → Add:
- **scope**: `service` = flights (or `provider` for one supplier), set discount type +
  value, currency NGN, and the start/end dates of the ad.
- GIA runs this SAME engine when a traveller locks a fare and will show *"Regular ₦X,
  your price ₦Y (save Z%)"* only when the rule actually applies to that fare — what GIA
  quotes equals what's charged at checkout. No rule → no discount shown.

---

## 2. Register the campaigns so GIA knows them

Admin → **Support → Ad Campaigns** → Create one row per ad:

| Field | Umrah ad | Flights ad |
|---|---|---|
| Platform | meta (or tiktok/google) | meta |
| Name | `Umrah Dec 16 & 25` | `Cheap Flights ABV↔LOS` |
| Offer intent (GIA reads this) | `Umrah departures on 16 & 25 December — early-bird` | `Cheaper Abuja–Lagos flights with a promo` |
| Offer product | `umrah` | `flights` |
| Match key (web) | `umrah_dec` (= the utm_campaign on the ad link) | `flights_abv_los` |
| **WhatsApp keyword** | `UMRAHDEC` | `CHEAPFLY` |
| utm_source | `facebook` (optional) | `facebook` |
| Active | ✓ | ✓ |
| Start / End | the ad flight dates | the ad flight dates |

The **offer intent** is exactly what GIA is told: *"The customer arrived from our Meta
ad '…' about … (umrah) — acknowledge naturally and prioritise umrah options. Never invent
offer details, prices or discounts beyond what is stated."* So keep it truthful and short.

---

## 3. Build the ad links / calls-to-action

- **Web ads** (drive to the site): append the utm_campaign that matches *Match key*, e.g.
  `https://goglobia.com/umrah?utm_campaign=umrah_dec`. GIA on the widget auto-acknowledges
  the offer on the first reply and attributes the conversation.
- **Click-to-WhatsApp ads** (recommended for reach): two ways, both covered —
  1. Meta sends a **referral payload** with the ad; GIA reads it automatically (no action
     needed beyond the campaign row).
  2. **Keyword fallback** (works on every provider incl. Green API/Telegram): tell people
     in the ad creative to *"Send **UMRAHDEC** on WhatsApp to get the December offer."*
     GIA matches the keyword in their first message and attributes the lead.

---

## 4. What GIA now does with a campaign lead
- **Knows the offer** (web + WhatsApp) and prioritises the right product.
- **Surfaces the real promo** ("save Z%") — never an invented one (containment-guarded).
- **Converts with care**: reads intent (specific date/pax = serious; price-only/"just
  checking" = browsing), guides serious leads to hold→pay, stays warm + low-pressure with
  browsers, never dismisses a "time-waster", and can tag intent for ops (internal only).
- **Recognises returning customers by phone** and greets them with their latest booking.
- **Re-engages quiet leads**: the hourly nurture sweep sends ONE warm, campaign-aware
  WhatsApp/email follow-up to un-converted leads while the campaign is live (respects
  "not_ready" and ended campaigns; never double-sends).

---

## 5. Measure it
`ad_campaign_conversion_report($db, $from, $to)` (surfaced in Admin → Support →
Conversion) gives the funnel per campaign: **chats → held bookings → paid → revenue** +
conversion rate. Use it to see which ad actually drives booked trips.

---

## Honest notes
- Nothing auto-sends without the cron scheduled (see `docs/CRON.md`).
- CTWA referral fields depend on Meta actually sending them — the keyword is the reliable
  fallback, so always put a keyword in the ad creative.
- GIA only ever quotes promos/figures that the real data (umrah tier / discounts engine)
  returns; if you don't configure a promo, GIA won't claim one.
- Seed the two umrah departures + publish them, or GIA truthfully says it has none.
