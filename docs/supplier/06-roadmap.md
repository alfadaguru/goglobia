# 06 — Roadmap, dependencies & open decisions

> **Status:** synthesis of chapters 00–05. This proposes a build order but — per the
> owner's directive ("spec everything; choose phase order after") — does **not** assume
> it. The real purpose of this chapter is the **decision list** (§4): the choices only
> the owner can make, each with the tradeoff spelled out.

---

## 1. The shape of the whole thing

Two things are being built at once, and it's worth naming them separately:

- **A supplier marketplace** (cross-service): suppliers onboard, list inventory, get
  approved, and get paid. Applies to stays, flights, tours, cars, bus.
- **A hotel ERP** (stays only, but huge): the property runs its entire operation —
  front desk, housekeeping, maintenance, F&B, its own branded site, future door keys.

The marketplace is the foundation; the ERP is a deep vertical on top of the stays
supplier. They share one spine: the supplier identity, approval, inventory, and payout
systems from `00`.

---

## 2. Dependency graph (what must precede what)

```
[already shipped: supplier role + signup + account approval + read-only dashboard]
        │
        ▼
P0  Supplier profile + service-selection + per-service approval      (00 §1,§2)
        │
        ├────────────────────────────┬───────────────────────────────┐
        ▼                            ▼                                ▼
P1  Real inventory + holds      P1' Per-listing approval         P1'' Owner-scoped
    (stays first; then           + quotas (00 §4,§5)                /supplier/* write
    flights/tours/cars/bus)                                         routes + ownership
        │                                                            enforcement
        └──────────────┬─────────────────────────────────────────────┘
                       ▼
P2  Reservations inbox + earnings ACCRUAL (pending→available, read-only)   (00 §6.4,§6.7-A)
                       │
        ┌──────────────┼───────────────────────────┐
        ▼              ▼                            ▼
P3 PMS core        P4 F&B / POS                P5 Direct-booking sites
   (stays:          (needs PMS folio for          + custom domains
   front desk,       charge-to-room)              (needs edge/TLS infra decision)
   housekeeping,
   maintenance,
   night audit)
        │
        ▼
P6  Payouts to bank — Paystack transfers        (00 §6; highest risk; needs P2 accrual
    (manual admin → supplier-initiated →         + a "completed" signal: PMS checkout
    scheduled)                                    for stays, travel date for others)
        │
        ▼
P7  External OTA channel manager (two-way)       (needs pooled inventory from P1)
        │
        ▼
P8  Keys SDK (smart-lock credentials)            (needs PMS physical-rooms + reservations)
```

**Critical-path insight:** everything depends on **P1 real inventory + holds**. Today
availability is fictional across every service (counts never decrement; no holds). That
is both the biggest risk in the current system *and* the unlock for the marketplace, the
PMS, and the channel manager. **It should be built first regardless of which vertical
goes first**, using the one proven precedent in the codebase (`umrah_inventory_holds`
with `SELECT … FOR UPDATE`).

---

## 3. Recommended phase order (a proposal, not a decision)

Optimizing for: lowest risk first, each phase shippable, revenue sooner, reuse maximized.

1. **P0 + P1 (stays) + P1' + P1''** — a hotel can self-register, be approved, create a
   property with **real, non-oversellable** rooms/rates/availability, and sell it on the
   marketplace with per-listing review. *This is the smallest end-to-end supplier slice
   and the natural MVP.* Then replicate P1 to bus/flights/tours/cars.
2. **P2** — reservations inbox + earnings accrual (suppliers see what they've earned;
   no money leaves yet — safe).
3. **P6 manual payouts** — pay suppliers via Paystack transfer under admin approval,
   with all 7 outbound-money guards (`00` §6.6). Do this *before* the ERP depth so
   suppliers actually get paid early.
4. **P3 PMS core** — turn stays into the hotel's operating system (walk-ins join the
   pooled inventory; checkout becomes the earnings "completed" signal).
5. **P4 F&B/POS**, **P5 direct-booking sites + custom domains**.
6. **P7 channel manager**, **P8 keys SDK** — the big future bets.

> The owner may legitimately reorder — e.g. prioritize the **direct-booking branded
> sites (P5)** early as the "more appealing than Booking.com" differentiator, accepting
> the edge/TLS infra work sooner. That's a strategy call (§4).

---

## 4. OPEN DECISIONS (owner's call — each with the tradeoff)

These are the forks I deliberately did **not** assume. Each needs a decision before the
corresponding phase is built.

### D1 — Which vertical goes first?
Options: (a) **stays MVP** (recommended — biggest prize, but largest surface); (b) a
**simpler service first** (bus/tours) to prove the marketplace pattern cheaply, then
stays; (c) **direct-booking sites first** as the differentiator.
*Tradeoff:* stays-first is highest value but slowest to first ship; a simpler service
first de-risks the marketplace mechanics before the ERP investment.

### D2 — Commission model (`00` §6.3)
(a) **Merchant model via the wallet spine** (recommended): platform collects, credits
supplier wallet net of commission, pays out later. Keeps refunds/holds/adjustments
sane. (b) **Paystack multi-split/subaccounts**: money reaches the supplier's bank at
settlement automatically, but bypasses the spine and only works for Paystack-settled
charges. *Tradeoff:* control & flexibility (a) vs less payout plumbing (b).

### D3 — Payout rollout (`00` §6.7)
Manual-admin → supplier-initiated → scheduled/auto. *Decision:* how far to go in v1.
Recommend stopping at **manual admin payouts** until proven, given it's the highest-risk
code in the app.

### D4 — Hold/clearance policy (`00` §6.4)
When does a supplier's earning become withdrawable? Recommend **after stay
checkout/travel date + a clearance window** (industry norm) to cover
cancellations/refunds/chargebacks. *Decision:* the window length and whether any
services pay earlier.

### D5 — Custom-domain edge/TLS infrastructure (`01` §7.2)
The app can hold the tenant↔domain mapping + verification state, but automatic TLS at
the edge isn't native to the current XAMPP/Apache setup. Options: (a) **Cloudflare for
SaaS** (managed custom hostnames + auto cert — least ops); (b) a **Caddy/Traefik edge**
with on-demand TLS; (c) defer custom domains, ship subdomains (`hotelname.goglobia.com`)
first. *Tradeoff:* (a) fastest/managed but a vendor dependency; (c) ships the branded-site
value without the domain complexity.

### D6 — Rate/inventory model migration (`01` §2)
Normalize rates/availability into new tables with a compatibility bridge (recommended),
vs. extend the existing `room_options` JSON in place. *Tradeoff:* the normalized model
is required for the PMS/channel-manager and is the clean long-term base; the JSON
in-place route is faster but caps how far the ERP can go.

### D7 — Keys SDK provider (`01` §8)
FLEXIPASS (multi-brand coverage: Vingcard/dormakaba/SALTO) vs TTLock (low-cost indie) vs
Tuya (cheap hardware breadth). *Decision deferred* to when P8 is scheduled; design the
reservation/physical-room model now so the access-grant hook is clean.

### D8 — Supplier staff/sub-users (`01` §9)
Does v1 need multi-user hotel teams (front-desk, housekeeping logins) or is a single
supplier login enough initially? *Tradeoff:* the PMS is much more useful with staff
roles, but it's extra surface; could start single-login and add `supplier_staff` at P3.

### D9 — Visa & integration-only services (`05`)
Confirm the recommendation: supplier onboarding is offered only for the first-class
services (stays, flights, tours, cars, bus); visa stays an admin catalogue (or a
separate processing-role build later); eSIM/ferries/rail/insurance stay integration-only.
*Decision:* accept, or prioritize building an inventory model for one of them.

---

## 5. Cross-cutting risks (carry into every phase)

1. **Availability correctness** — the no-oversell guarantee (atomic holds) is the
   product's integrity; get P1 right or nothing above it is trustworthy.
2. **Outbound money** — payouts are irreversible; the 7 guards in `00` §6.6 are
   non-negotiable.
3. **Ownership enforcement** — every `/supplier/*` action must be scoped to the logged-in
   supplier's `user_id` (and, for staff, the parent property). The admin CRUD has no
   such scoping today — it's net-new and security-critical.
4. **Approval can't be a bottleneck** — per-listing review on *structural* changes only;
   day-to-day rate/availability edits must be instant or hotels won't use it.
5. **Don't break the live booking path** — keep the `room_options` compatibility bridge
   until the public consumer is migrated (`01` §2).
6. **Infra reality** — custom domains/TLS and (later) external OTA connectivity are
   ops/infra workstreams, not just app code; flag them early.

---

## 6. What's already done vs net-new (grounding recap)

**Reusable as-is:** supplier role + `/supplier-signup` + admin approve/reject queue +
`SUPPLIER_AUTH()` + read-only dashboard (shipped, commit `75f98a4`); owner `user_id`
column already consumed by live owner notifications; the admin stays/flights/tours/cars
CRUD as the *template* to copy into owner-scoped routes; the `MARKUP()`/`calculateTax()`
pricing path; the wallet spine's inbound idempotency/locking discipline; the
`umrah_inventory_holds` + `umrah_group_review` patterns as models for holds and
per-listing approval; `CRUD::custom_button()` for admin approve actions;
`users.credit_limits` as the quota precedent; Paystack `paystack_dva_http()` helper for
future transfers.

**Net-new (the real build):** real inventory + holds for every service; normalized
rates; owner-scoped supplier write routes + ownership enforcement; per-listing approval
state; reservations inbox for owners; supplier earnings accrual; the entire Paystack
**Transfer/payout** stack + payout lifecycle; the full stays **PMS** (front desk,
housekeeping, maintenance, night audit); **F&B/POS**; **direct-booking sites + custom
domains**; **channel manager**; **keys SDK**; supplier staff sub-users.

---

*End of suite. Start at [`README.md`](README.md). The owner's next step is the
decision list in §4 — answer those and the phases become concrete build tickets.*
