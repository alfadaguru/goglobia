# GIA — Live End‑to‑End Test Checklist

This is the owner's checklist to **prove GIA works against real suppliers and a
real payment gateway**. Everything in GIA has been verified at the logic level
(unit/mocked tests), but the build environment has no browser, no MySQL, and no
live supplier/gateway keys — so the live end‑to‑end run is done here, on the
server, by you.

Run it on **test/sandbox keys first**. Capture what you see at each step; when a
step misbehaves, copy the **GIA admin debug** line (admins see a `_admin.debug`
block in the chat response with the real HTTP code + provider error) and the
browser console, and send them back for a fix.

---

## 0. Prerequisites (once)

1. Deploy the current `main` (via `/updates.php`), then run the admin **Database
   Update** tool so the new `gia_*` columns/tables land (`closed`, `needs_human`,
   `staff_active`, `flagged`, `gia_messages.is_staff`).
2. Admin → **GIA Assistant** (new top‑level menu) → confirm the dashboard loads.
3. Admin → GIA Assistant → **GIA is ON** toggle shows green (or click to enable).
   Also confirm an AI provider + key is set (AI settings) — GIA is dead without it.
4. Set each supplier you want to test to **sandbox/test credentials** in the
   modules admin. Keep a test card handy for the gateway.

> ⚠️ Until you're confident, test with amounts/keys you can safely refund.

---

## 1. Smoke test (no money)

- [ ] Open any page → the floating GIA bubble greets within ~2s (first visit).
- [ ] On `/umrah` the greeting/starters are umrah‑flavoured; on the homepage they're generic (flights/hotels), **not** umrah‑led.
- [ ] Open a second browser tab → the **same** conversation resumes (cross‑tab).
- [ ] Click **New chat** → history clears and a fresh greeting appears; the old
      conversation still shows in the admin dashboard (not deleted).

---

## 2. Per‑service booking — run ONE real booking for each

For every service: search → pick an option → GIA reprices → review the exact
amount → **Approve** (tap the button) → **Pay** → confirmation. Tick each stage.

Legend: *Local‑DB* = card appears directly in chat. *Live* = GIA calls
`fetch_live_offers` and shows pickable offers after you ask to see options.

### Local‑DB services (real priced cards inline)
- [ ] **Umrah** — "umrah packages next month" → cards → pick → reprice → approve → pay → `get_order_status` shows CONFIRMED + a reference.
- [ ] **Stays** — "hotels in <city>" → cards → pick → reprice → approve → pay → confirmed.
- [ ] **Tours** — "tours in <city>" → pick → reprice → approve → pay → confirmed.
- [ ] **Bus** — "bus <A> to <B> on <date>" → pick → reprice → approve → pay → confirmed.
- [ ] **Visa** — "visa from <X> to <Y>" → priced card (or inquiry) → pick → reprice → approve → pay → confirmed.

### Live‑supplier services (offers fetched on demand)
- [ ] **eSIM** — "eSIM for <country>" → "show me options" → Airalo packages appear → pick → reprice → approve → pay → confirmed.
- [ ] **Rail** — "train <A> to <B> on <date>" → options → pick → reprice → approve → pay → confirmed.
- [ ] **Ferries** — "ferry <portA> to <portB> on <date>" → sailings → pick → reprice → approve → pay → confirmed.
- [ ] **Cars** — "car in <city> <dates>" → offers → pick → reprice → approve → pay → confirmed.
- [ ] **Flights** — "flights <A> to <B> on <date>" → "show me fares" → offers → pick →
      **reprice immediately** → approve quickly → pay → confirmed.
      - [ ] Also test the **expiry path**: pick a fare, wait several minutes, then try to approve → GIA should say the fare is no longer valid and offer to pull fresh options (it must **not** charge a stale price).

### What to check at each booking
- [ ] The amount GIA shows before Approve **matches** the amount on the payment page.
- [ ] GIA never says "confirmed/booked" until `get_order_status` returns a real PNR/reference.
- [ ] Same‑origin payment page opens **in the side drawer**; an external gateway
      (Paystack/Stripe‑hosted) opens in a **new tab** (not a blank drawer).

---

## 3. Safety checks (must hold)

- [ ] You cannot complete a payment without tapping **Approve** (typing "ok, pay"
      in chat must NOT trigger payment).
- [ ] GIA never invents a price — the number always comes from the reprice step.
- [ ] A search that returns nothing is handled warmly (offers an alternative), no crash.

---

## 4. Lead capture + admin oversight

- [ ] After GIA shows options, the soft "save your trip" card appears **once**,
      is dismissible, and never blocks the chat.
- [ ] Submit a test email → Admin → GIA Assistant → **Leads** shows it. If the
      email matches an existing user, status = `linked`.
- [ ] **Export leads (CSV)** downloads a valid file.
- [ ] Open a conversation → **flag** it (⚑), then **unflag**; **delete** a throwaway
      conversation and confirm it's gone from the list.
- [ ] Toggle **GIA OFF** → the widget stops offering chat; toggle back **ON**.

---

## 5. Human handoff

- [ ] As a visitor, start a chat. As admin, open that conversation → **Take over**
      → type a reply → **Send**.
- [ ] Back in the visitor's widget (within ~6s) the staff reply appears and a
      green "A team member is helping you" banner shows; GIA stops auto‑replying.
- [ ] Visitor sends another message → it's recorded (GIA stays quiet).
- [ ] Admin → **Hand back to GIA** → GIA resumes on the next visitor message; the
      banner clears.

---

## When something breaks

Capture and send back:
1. The exact step + what you typed.
2. The GIA reply shown (and, as admin, the `_admin.debug` line in the response).
3. Browser console errors (F12 → Console).
4. For payment issues: the gateway response/redirect URL and whether it was the
   drawer or new‑tab path.

These pinpoint the fix fast — most live failures are supplier credential/shape
differences the mocked tests can't see.
