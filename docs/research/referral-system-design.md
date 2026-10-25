# Referral System — Design & Build Plan

**Date:** 2026-10-24 · Owner-requested. Adds a customer **referral program** to the
admin **Marketing** section, alongside the existing coupons and discount engine.
Grounded in (a) deep research on referral-program best practice + fraud, and (b) a
full audit of our existing infrastructure so we **reuse, not reinvent**.

---

## 1. The one rule that governs the whole design

> **Reward on the referred friend's first PAID booking — never on signup or link-click.**

This single decision prevents the large majority of referral fraud (fake-account
farming, click abuse). It is the universal recommendation across every source.

## 2. Owner decisions (locked)

- **Double-sided.** The referrer earns wallet credit **when their friend completes a
  first paid booking**; the friend **also** gets a discount on that first booking.
  (Double-sided converts best — the friend needs a reason to use the code.)
- **Referrer reward = a fixed NGN wallet credit** (admin-set, e.g. ₦2,000),
  credited to their wallet (spendable on their next booking — no cash payout risk).
- **Referee reward = a fixed NGN discount** on their first booking (admin-set).
- **Fraud = Standard:** reward only on first paid booking; block self-referral;
  one reward per referred user; hold the reward through the refund window, then
  release; auto-clawback if that booking is later refunded.
- Everything is **admin-controlled under Marketing**: enable/disable + all amounts,
  hold window, and caps.

## 3. The referral lifecycle (state machine)

```
 (friend signs up with a code)      (friend's 1st paid booking)     (refund window passes)
         PENDING  ───────────────►  QUALIFIED  ───────────────►  HOLD  ──────────────►  REWARDED
            │                                                      │
            ▼ (self-referral / fraud signal)                       ▼ (friend refunds in window)
         INVALID                                                REVERSED  (wallet clawback)
```

- **PENDING** — a new user signed up carrying a referrer's code. No reward yet.
- **QUALIFIED** — that user completes their **first paid booking**. Reward is
  calculated and the referee discount was applied at their checkout.
- **HOLD** — reward held through the refund window (protects against refund abuse).
- **REWARDED** — hold passed → referrer's wallet is credited; both parties notified.
- **INVALID** — self-referral or fraud → no reward.
- **REVERSED** — the qualifying booking was refunded during hold → clawback.

## 4. What we REUSE (audited — no reinvention)

| Need | Reuse | File:line |
|------|-------|-----------|
| Admin Marketing menu | add a "Referral Program" item | `app/views/admin/sidebar.php:377` |
| Admin CRUD pattern | mirror promo-codes / discounts | `promoCodesRoutes.php`, `discountsRoutes.php` |
| **Credit the reward** | `wallet_apply($db,$user,$amt,'credit',…)` | `app/lib/wallet.php:241` |
| **Grant once (idempotency)** | copy `recordPromoUsage` + UNIQUE constraint | `functions.php:5646`, `:5058` |
| Reward hook point | where coupon usage is recorded on paid booking | booking-paid flow |
| Referee discount | the discount/promo apply path | `app/lib/discounts.php:332` |
| Notify both parties | `sendNotification()` / `SENDEMAIL()` | `functions.php:2872` |
| Admin toggle + config | `settings` row id=1 JSON (like loyalty_*) | `wallet.php:436` |
| Mint code at signup | a hook is **already scaffolded** (commented) | `app/webhooks/users/signup.php:238` |

Nothing comparable exists today for *customer* referral (the existing
`supplier_loyalty_ext` referral is supplier/stays-org specific — not reusable).

## 5. New pieces to build

- **`app/lib/referrals.php`** — `ensureReferralSchema()`, `referral_config()` (reads
  settings), `getOrMintReferralCode($userId)`, `validateReferralCode()`,
  `recordReferralReward()` (idempotent), release + clawback helpers.
- **Tables** (self-healing ensure-fn + `install/db.sql`):
  - `referral_codes` — user_id, code (UNIQUE), active, created_at
  - `referrals` — referrer_id, referee_id, code_used, status, qualifying_invoice_id,
    qualifying_at, created_at
  - `referral_rewards` — referral_id (1:1), referrer_id, referee_id, invoice_id,
    reward_amount, currency, status (HOLD/REWARDED/REVERSED), hold_expires_at,
    rewarded_at, UNIQUE(referral_id) + UNIQUE(invoice_id) for idempotency
- **`app/routes/admin/referralsRoutes.php`** + **views** — config screen (enable,
  referrer credit, referee discount, hold days, caps) + a list/audit of referrals &
  rewards. Mirrors the promo-codes admin.
- **Signup capture** — read `?ref=CODE` (and a short-lived cookie) at signup, set
  `referred_by`, create the `referrals` row PENDING, **block self-referral**.
- **Reward hook** — on a paid booking: if the buyer has a `referred_by` and this is
  their **first** paid booking, apply the referee discount + create the reward in
  HOLD. On refund of that booking → REVERSED + wallet clawback.
- **Release cron** — HOLD → REWARDED after the hold window → `wallet_apply` credit +
  notify. (Reuses the existing cron pattern.)
- **Customer "My Referrals"** area — their code/link, share buttons, history
  (invited / signed up / rewarded), pending vs spendable balance.

## 6. Build order (each phase = its own verified PR)

1. **P1** schema + `referrals.php` lib + config + ensure-call.
2. **P2** mint code per user + capture `referred_by` at signup + self-referral block.
3. **P3** reward hook at booking-paid (HOLD) + referee discount + refund clawback.
4. **P4** release cron (HOLD→REWARDED, credit + notify).
5. **P5** admin Marketing UI (config + list/audit).
6. **P6** customer "My Referrals" area.

Defaults are safe and **off** until the admin enables it, exactly like the loyalty /
fare-timer toggles.

## 7. Fraud controls in this build (Standard)

- Reward only on first **paid** booking (not signup/click).
- **Self-referral blocked** — can't use your own code; referrer ≠ referee account.
- **One reward per referred user** (UNIQUE on referral_id; first-paid-booking only).
- **Hold + clawback** — reward held through refund window; refunded booking reverses
  the reward (wallet debit).
- Admin can disable instantly and audit every referral + reward (immutable ledger).
