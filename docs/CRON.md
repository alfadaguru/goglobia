# Cron / Scheduler Setup — goglobia.com

Every background job in this app is an **external-pinged HTTP endpoint**. There is
**no internal ticker** — `config.php`'s `register_shutdown_function` is only an error
handler. So nothing runs unless the server scheduler (system `crontab`, cPanel cron,
or an uptime pinger) hits these URLs on a schedule.

> **If these are not scheduled, the features silently do nothing** — no GIA payment
> reminders, no pre/post travel messages, no pay-later chasing, no held-seat expiry,
> no supplier-earning release, no FX updates.

Each endpoint is **idempotent and best-effort**: it tracks what it already did, acks
`200` even on a transient error (so the scheduler doesn't hammer), and never throws.
Safe to call more often than needed.

---

## Install (system crontab)

Run `crontab -e` on the server and paste the block below. Replace `https://goglobia.com`
if the public host differs. (`> /dev/null 2>&1` discards output; keep it so cron doesn't
email you every run.)

```cron
# ====================================================================
# goglobia.com — application cron jobs
# ====================================================================

# ── GIA / customer messaging ────────────────────────────────────────
* * * * *       curl -s "https://goglobia.com/gia_payments_sweep"        > /dev/null 2>&1
*/10 * * * *    curl -s "https://goglobia.com/cron/flight-reminders"     > /dev/null 2>&1
*/20 * * * *    curl -s "https://goglobia.com/cron/service-comms"        > /dev/null 2>&1
*/15 * * * *    curl -s "https://goglobia.com/support_shift_nudge"       > /dev/null 2>&1
0 * * * *       curl -s "https://goglobia.com/gia_nurture_sweep"         > /dev/null 2>&1

# ── Money / booking lifecycle ────────────────────────────────────────
*/5 * * * *     curl -s "https://goglobia.com/cron/campaigns/dispatch"   > /dev/null 2>&1
*/5 * * * *     curl -s "https://goglobia.com/pay_later_sweep"           > /dev/null 2>&1
*/10 * * * *    curl -s "https://goglobia.com/cron/bm-stranded-issue"    > /dev/null 2>&1
*/5 * * * *     curl -s "https://goglobia.com/umrah_expire_holds"        > /dev/null 2>&1
*/15 * * * *    curl -s "https://goglobia.com/supplier_release_earnings" > /dev/null 2>&1
0 * * * *       curl -s "https://goglobia.com/referral_release_sweep"    > /dev/null 2>&1

# ── Daily maintenance ────────────────────────────────────────────────
*/15 * * * *    curl -s "https://goglobia.com/update_currency_rates"     > /dev/null 2>&1
30 2 * * *      curl -s "https://goglobia.com/cron/airalo-sync"          > /dev/null 2>&1
0 9 * * *       curl -s "https://goglobia.com/send_credits_reminders"    > /dev/null 2>&1

# ── OPTIONAL — supplier-PMS automations (admin-toggled; OFF by default).
#    Only schedule these if you enable the supplier notify/workflow engines.
# */10 * * * *  curl -s "https://goglobia.com/crons/notify-tick"         > /dev/null 2>&1
# */10 * * * *  curl -s "https://goglobia.com/crons/workflow-tick"       > /dev/null 2>&1
# */5  * * * *  curl -s "https://goglobia.com/crons/supplier-webhooks"   > /dev/null 2>&1
```

---

## What each job does

| Schedule | Endpoint | Job | Source |
|---|---|---|---|
| every min | `/gia_payments_sweep` | **GIA per-booking payment**: up to 5 WhatsApp pay reminders across the 20-min window, then expire + cancel unpaid bookings (frees seats) + late-refund. **Without this, GIA takes payments but never chases/expires them and inventory stays locked.** | `crons/giaPaymentsRoutes.php` → `gia_bp_sweep()` |
| */10 | `/cron/flight-reminders` | Flight travel reminders: 3 pre-flight (≈24h/4h/1h before) + 1 post-flight thank-you/review/rebook, email + WhatsApp. | `crons/flightRemindersRoutes.php` → `flight_reminders_sweep()` |
| */20 | `/cron/service-comms` | Pre/post comms for **stays, umrah, tours, cars, ferries, rail, bus** (2 pre + 1 post, travel-date anchored) and **visa, esim** (post-only, purchase anchored). Email + WhatsApp. | `crons/serviceCommsRoutes.php` → `service_comms_sweep()` |
| */15 | `/support_shift_nudge` | GIA WhatsApps support staff who haven't clocked in at shift start (then every 15 min, capped at 3). | `crons/supportShiftNudgeRoutes.php` |
| hourly | `/gia_nurture_sweep` | Re-engages quiet, un-converted leads from ACTIVE ad campaigns with ONE warm WhatsApp/email follow-up (idempotent; respects not_ready + ended campaigns). | `crons/giaNurtureRoutes.php` → `gia_nurture_sweep()` |
| */5 | `/cron/campaigns/dispatch` | Sends due scheduled email campaigns (and resumes multi-tick sends). | `crons/campaignsDispatchRoutes.php` |
| */5 | `/pay_later_sweep` | Pay-Later: per-scope reminder emails + deadline (auto-cancel/flag) handling. | `crons/payLaterRoutes.php` |
| */10 | `/cron/bm-stranded-issue` | Auto-recovers BM/agent bookings that were paid but never issued at the supplier. | `crons/bmStrandedIssueRoutes.php` |
| */5 | `/umrah_expire_holds` | Releases expired umrah seat holds so inventory frees. | `crons/umrahHoldsRoutes.php` |
| */15 | `/supplier_release_earnings` | Advances supplier earnings `pending → available` after the clearance window. | `crons/supplierEarningsRoutes.php` |
| hourly | `/referral_release_sweep` | Releases referral rewards after the refund hold window; claws back on refund. | `crons/referralReleaseRoutes.php` |
| */15 | `/update_currency_rates` | Refreshes FX rates from the currency API. | `crons/currencyRatesRoutes.php` |
| daily 02:30 | `/cron/airalo-sync` | Syncs the Airalo eSIM country/package catalogue. | `crons/airaloSyncRoutes.php` |
| daily 09:00 | `/send_credits_reminders` | Reminds agents of credit-line payment due dates. | `crons/creditsRemindersRoutes.php` |

---

## Notes (read before deploying)

- **Currency cadence**: the source file header says "daily", but production should run
  `*/15` so rates stay fresh (and so a bad key surfaces fast). Drop to daily if you
  prefer fewer calls. The currencylayer key must also be set in admin.
- **Duplicate**: `/api/credits/reminders` (API host) is the same job as
  `/send_credits_reminders`. Schedule **one**, not both.
- **Supplier-tick trio** (`notify-tick` / `workflow-tick` / `supplier-webhooks`) is
  **off unless an admin enables** the supplier notify/workflow engines — leave commented
  until you use them.
- **Messaging prerequisites**: the WhatsApp provider (Green API) and the email provider
  must be configured in admin Settings, or the sends log an error and no-op.
- **Comms only fire for PAID, non-cancelled bookings** with a resolvable service date
  (service_comms) / known departure time (flight_reminders). Unpaid/test bookings and
  bookings with no reliable date are skipped by design (never a guessed-date message).
- **HTTPS**: use the `https://` host so Bearer/`Authorization` flows and secure cookies
  behave. Plain-http cron works too but prefer https.

### Verifying a job runs
Hit the URL in a browser / with curl — each returns JSON, e.g.:
```
curl -s "https://goglobia.com/cron/service-comms"
# {"status":true,"scanned":42,"skipped":40,"pre_sent":1,"post_sent":1,"ran_at":"..."}
```
`scanned` > 0 with `pre_sent`/`post_sent` = 0 is normal when no booking is currently
inside a send window.
