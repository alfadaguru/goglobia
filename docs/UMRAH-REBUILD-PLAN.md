# Umrah Rebuild — Flight-Style Experience + Agent Groups (Build Plan)

**Status:** DRAFT for owner approval. No code is written until this is approved.
Date: 2026-09-11. Grounded in the verified flight pattern, the current Umrah
code, and Nusuk group-booking research. Owner decisions are locked (see §0).

---

## 0. Locked decisions (from the owner)

1. **A "package" = one departure date.** The 5 real Kano departures are 5
   packages. Each has its own dedicated detail page. The 5 **tiers**
   (Standard Economy → VVVVIP Luxury) are *options within* a package — exactly
   like a flight's cabin class (economy/business/first).
2. **Search = exactly like flights**: a working homepage tab widget + a
   dedicated results page with a **filter sidebar** + result cards → a
   dedicated per-package detail page.
3. **Two filter dimensions** on results: (a) city / month / date / price /
   availability, and (b) a **Tier filter** (Standard/VIP/VVIP/VVVIP/VVVVIP) —
   not generalized.
4. **Inclusions are per-package details**, shown nicely **with images**
   (Flight, Visa, 4 Madinah + 10 Makkah, Transport, Ziyarah, SIM + eSIM,
   Gift kit + Ring, 24/7 support).
5. **Prices & images:** keep the current placeholder tier prices; use generic
   Makkah/Madinah **stock imagery** so pages look complete now; owner supplies
   real prices + photos later via admin. Add the image + price admin fields.
6. **Customer booking:** seamless book **+ upload documents** in one flow.
   **gender + date of birth + passport** are mandatory per traveller. One
   customer may buy multiple Umrahs at once but is **capped at 5 pilgrims per
   booking**.
7. **Agent groups (Nusuk-style):** no 5-cap. Agent picks a package and creates
   a **group** (min **3 male OR 3 female** on Standard Economy). **Agent pays
   for the whole group** via wallet/deposit, then can **add/drop** members and
   **upload documents**; group can be saved **pending**, **submitted**, and
   still **edited while processing**.
8. **Approach:** plan → approve → build in phases with tests each phase.

---

## 1. What already exists (so we build on it, not around it)

- **Data & pricing (solid, keep):** `umrah_departures` (5 published Kano dates)
  × `umrah_departure_tiers` (5 active tiers each, priced). `umrah_price_resolve`
  (agent-aware B2B), `umrah_price_quote`, atomic `umrah_hold_create` (no
  oversell, departure-level cap), `umrah_booking_create`, deposit-aware
  `umrah_settle_payment` + `payment_amount_due`. All the payment-security fixes
  are merged.
- **Booking data model (mostly there):** `umrah_bookings`,
  `umrah_installments`, `umrah_booking_travellers` (already has gender, dob,
  passport_number, passport_expiry, is_lead, room/family group, statuses),
  `umrah_documents` (secure upload + admin serve route + verify).
- **APIs (there, will extend):** `/api/v1/umrah/{quotes,holds,bookings,
  bookings/{ref}/travellers,travellers/{id}/documents}` (CSRF-guarded,
  owner-gated).
- **Gaps to fill:** NO image columns anywhere in v2; the current landing/detail
  are NOT flight-style (thin search, generic inclusions, no per-package pages,
  no tier filter, no images); NO per-booking pilgrim cap; NO agent group model;
  the homepage Umrah tab loads a legacy partial, not a working v2 search.

---

## 2. Target UX (mirrors flights, verified pattern)

**Homepage** → Umrah tab widget (City + Month + Pilgrims) → submit builds a
clean URL → **Results page**.

**Results page** (`/umrah/search/...`) — like `flights/listing`:
- Filter sidebar (Alpine client-side, small fixed inventory): **City**, **Month**,
  **Price range** (slider), **Tier** (Standard→VVVVIP chips), **Availability**.
- Result **cards** = one per departure (package): dates, city, "14-day",
  from-price + strike promo, availability badge, "X tiers", a package **image**,
  and **View / Book** CTAs → the dedicated package page.

**Package detail page** (`/umrah/packages/{code}` or `/umrah/departure/{id}`):
- Hero **image** + gallery; the departure's dates/city.
- **Tier selector** (Standard→VVVVIP) — each tier a card with its price, room
  sharing, and **its own inclusions** shown nicely with icons/images.
- Full **inclusions detail** section (Flight / Visa / 4 Madinah + 10 Makkah /
  Transport / Ziyarah / SIM+eSIM / Gift kit + Ring / 24-7) — designed, not a
  bare checklist.
- Sticky **booking card**: pick tier + pilgrims (≤5 for customers) + plan →
  seamless booking → traveller details + **document upload** in one flow.
- "Customize / Personalize" entry stays (quote request), unchanged in logic.

**Agent view** (agent session): the same package page also exposes **"Create a
group"** → group builder (below).

---

## 3. Data model changes (schema — additive, idempotent, mirrored in db.sql)

**Images (decision 5):**
- `umrah_departures.hero_image` varchar(255) NULL, `gallery` JSON NULL.
- `umrah_package_templates.hero_image` varchar(255) NULL (fallback).
- `umrah_tiers.image` varchar(255) NULL (optional per-tier photo).
- Seed: point to a few bundled stock Makkah/Madinah images under
  `uploads/umrah/stock/` (committed) so pages render complete.

**Per-tier inclusions (decision 4 — real detail, not one global list):**
- `umrah_departure_tiers.inclusions` JSON NULL (overrides template default when
  set) — so VVVVIP can list different inclusions than Standard. Falls back to
  `umrah_package_templates.inclusions`.

**Customer pilgrim cap (decision 6):** enforced in code (no schema needed) —
`umrah_booking_create` / v1 bookings rejects pax > 5 for non-agents.

**Agent groups (decision 7) — new tables:**
- `umrah_groups`: id, group_ref (GGG-…), agent_user_id, departure_id,
  departure_tier_id, name, status enum(draft, pending, submitted, processing,
  confirmed, cancelled), pax_count, total_price, currency, paid (bool),
  invoice_id (nullable), notes, created_at, updated_at.
- `umrah_group_members`: id, group_id, (reuse `umrah_booking_travellers` shape
  OR link) — chosen approach: a group, on payment, materializes one
  `umrah_bookings` row (owned by the agent) whose travellers are the members, so
  documents/rooming/ops reuse the existing traveller+document machinery. Members
  are added/dropped pre-submission via `umrah_group_members` then synced to
  `umrah_booking_travellers` at submit. (Final linkage decided in Phase C.)

All schema via `ensureUmrahSchema` (idempotent) + `install/db.sql`.

---

## 4. Phased build (each phase: build → php -l → live-DB test → commit)

### Phase A — Flight-style search + dedicated package pages (no booking changes)
- A1. Schema: add image + per-tier inclusions columns (ensure + db.sql); commit
  a few stock images under `uploads/umrah/stock/`; seed hero/gallery.
- A2. Homepage Umrah tab: make `/partials/search/umrah` render a real widget
  (City + Month + Pilgrims), submitting to the results URL — mirror the flights
  search partial.
- A3. Results page `/umrah/search/{city}/{month}/{pax}` (+ a no-arg `/umrah`
  that shows all): filter sidebar (city/month/price/tier/availability) + result
  cards with images — mirror `flights/listing` structure & CSS.
- A4. Dedicated package page: hero+gallery, tier selector with per-tier price +
  inclusions, designed inclusions section. (Booking card present but still uses
  the existing quote→hold→book JS.)
- **Acceptance:** search from homepage → results filter by city/month/tier/price
  → open a package → see images + all 5 tiers + detailed inclusions.

### Phase B — Seamless customer booking + documents, 5-pilgrim cap
- B1. Booking flow reworked to flight-style two-column checkout: pick tier +
  pilgrims (≤5) + plan; per-pilgrim fields **gender + DOB + passport** (mandatory)
  in the same flow; document upload step wired to the existing secure upload.
- B2. Enforce the **5-pilgrim cap** for non-agent bookings (server-side in
  `umrah_booking_create` + the v1 API; friendly client guard too).
- B3. Multi-Umrah in one go: allow a customer to add more than one departure to
  a single checkout/cart (like multiple flight tickets) — each its own booking,
  paid together. (Cart scope confirmed at start of Phase B.)
- **Acceptance:** a customer books a package, enters gender/DOB/passport for each
  pilgrim (≤5), uploads passports, pays the deposit — end-to-end, tested.

### Phase C — Agent groups (Nusuk-style)
- C1. Schema + services for `umrah_groups` / members; `umrah_group_create`,
  add/drop member, validate **min 3 same-gender on Standard**, price = Σ(members
  × tier).
- C2. Agent group builder UI on the package page (agent session only): create
  group → add pilgrims (gender/DOB/passport) → save **pending** (add/drop) →
  **pay whole group** from wallet/deposit → **submit** → **processing** (still
  editable: add/drop, upload/replace docs).
- C3. On payment, materialize the agent-owned `umrah_bookings` + travellers so
  documents/rooming/ops reuse existing machinery; agent dashboard lists groups
  with status + document readiness.
- C4. Admin: groups appear in the umrah manager (list, status, members, docs).
- **Acceptance:** agent creates a Standard group of ≥3 same-gender, pays from
  wallet, adds/drops a member while pending, submits, uploads member documents
  while processing — end-to-end, tested.

---

## 5. Guardrails (apply throughout)
- Reuse the existing pricing/settlement/security — do NOT reintroduce the
  audited holes (server-authoritative price, CSRF on mutations, IDOR gates,
  amount-verified payments, deposit-aware charging).
- All keys/config from the DB; no hardcoding.
- Automatic & seamless; no permission prompts to the user mid-flow.
- Each phase committed separately with php -l + a live-DB test; nothing merged
  to main until the owner says so.

---

## 6. Owner answers (2026-09-11) — now locked into the phases above

1. **Multi-Umrah cart (B3) = YES, a true cart.** A customer can add multiple
   Umrah departures and pay for them together, exactly like buying tickets for
   ≤5 passengers. Each customer booking is still capped at **5 pilgrims per
   departure**; the cart can hold several departures.

2. **Agent groups are STAGED (counts first, wallet debited only on submit).**
   The agent does NOT enter full pilgrim data up front — he declares **counts**
   (e.g. "5 males, 3 females"). The wallet is **debited only when the group is
   submitted**, not at draft/pending. Full gender/DOB/passport per member is
   completed before/at submit and can still be edited while processing.

3. **Group lifecycle (authoritative):**
   `draft → pending → paid → submitted → processing → {all visa issued |
   partial visa issued | visa rejected}` and, in parallel, **ticket states**
   per member (reserved/ticketed/etc.). Admin can drive/override every
   transition (full flexibility). The wallet debit happens at the
   `pending → paid`/submit boundary (paid then submitted).

4. **Tier group rules (I decide, grounded):**
   - **Standard / Economy:** min **3 same-gender** (3 males OR 3 females) — the
     shared-room rule.
   - **VIP (quad):** min **2 same-gender** (quad rooms, pairs allowed).
   - **VVIP (triple) / VVVIP (double):** min **2 same-gender**; a lone pilgrim
     needs a mahram/family link or pays single-occupancy.
   - **VVVVIP (private single/double):** **no minimum** (private rooms) — 1+.
   These are configurable per tier in admin so you can change them.

5. **Nusuk export = YES.** A standard **pilgrim manifest export** (CSV/Excel)
   per group/departure with the fields Nusuk needs: passport number, full name
   (as in passport), gender, DOB, nationality, passport issue/expiry, mobile,
   email, tier, room group. Column mapping is **admin-configurable** so it can
   match whatever Nusuk's current upload template requires (exact Nusuk schema
   is partner-login only, so we make it configurable rather than hardcode).

6. **Admin flexibility (throughout):** admin can edit any group/booking, move
   any status (visa: none/partial/all issued/rejected; ticket states), add/drop
   members, re-price, and export manifests — for both customer bookings and
   agent groups.
