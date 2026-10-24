# GIA Identity, Personalization, Phone-Auth & WhatsApp — DEEP Audit + Task List

**Date:** 2026-10-24 · **Status:** audit complete (8 explorers, 2 waves), nothing built yet.
**Method:** every claim backed by a file:line that was read. Conflicts between agents were
re-verified directly and corrected inline. No SQL run; schema read from `install/db.sql`.

**Citation audit (2026-10-24):** every file:line in this doc was re-checked against the live
source. Result: **no false claims** — all functions, tables, enums, behaviors, and
architecture statements are accurate. Subagent reports had ~a dozen stale line numbers (drift
of 1–45 lines); all were corrected to the verified lines. Three subagent errors were caught and
fixed earlier (single-vs-two booking tables; "buttons are dead code"; "admin channels view
missing"). Specifically re-confirmed by direct read: the markdown fix must cover 3 call sites
(`gia_channel_send.php:138/146/202`) + the bare-text branch, not just `gia_render_text_fallback`.

---

## 0. YOUR REQUEST → COVERAGE MATRIX (nothing skipped)

| # | Your words | Covered by | Verdict |
|---|-----------|-----------|---------|
| R1 | "audit how people are users on our platform" | §1 user model | ✅ done |
| R2 | "add phone number to sign up" | §1, Task P1 | gap G1 |
| R3 | "enable login with email **or** phone number" | §1, Task P3 | gap G2 |
| R4 | "verification via OTP WhatsApp … **using GIA to send the OTP**" | §2, §4, Task P2 | gap G3/G4 |
| R5 | "GIA personalize user account on chatting" | §3, Task P5 | partial exists |
| R6 | "GIA **register an account**" | §3, §6, Task P5-T11 | gap G5 |
| R7 | "**update the account** with names, email etc" | §3, §6, Task P5-T12 | gap G5 |
| R8 | "**context aware on the number** chat GIA" | §3 identity | partial exists |
| R9 | "serious personalization" | §3, Task P5 | gap G7 |
| R10 | "GIA on WhatsApp **have buttons**" | §4 | ⚠️ constrained — see note |
| R11 | "**simplify & properly format the text** of GIA on WhatsApp" | §4, Task P4 | gap G8 |
| R12 | "GIA access to **all bookings** / **fully aware** of the user" | §3, §5, Task P5-T13/T14 | gap G5/G7 |

---

## 1. USER MODEL (R1, R2, R3)

### Signup
- Web `POST /signup` (`signupRoutes.php:55-267`): CSRF (`:95`), CAPTCHA (`:113`), registration
  toggle (`:56`), duplicate-email via `$db->has` (`:134`), password ≥6 (`:144`), insert (`:200`),
  `sendNotification($userId,'signup_welcome',…)` (`:238`), redirect to `/login`.
- **Phone columns exist but the web form never collects them** — handler accepts `phone` +
  `phone_country_code` (`:69-70`) and writes them, but with no input they arrive empty and
  **`phone_country_code` defaults to `'92'` Pakistan** via `getPhoneCode` (`:184`).
- `users.email` is **UNIQUE** (`install/db.sql:32207`). `users.phone` has **NO index, NOT
  unique** (`:31630`). `email_verified` gates `status=active`; **no `phone_verified` column**
  (NOT FOUND).
- Mobile `POST /api/signup` already takes phone; mints a 6-digit **signup OTP** into
  `users.otp`, 24h (`api/users/signupRoutes.php:151-173`).
- `generateUserId()` (`functions.php:54`) mints the custom `user_id` (13 hex + unix ts). There
  is **no `createUser()` helper** — routes call `$db->insert('users',…)` directly.

### Login — 4 entry points, MUST stay consistent
| Entry | File:line | Sets |
|---|---|---|
| Web `POST /login` | `loginRoutes.php:123-345` | session: `user_id,user_email,user_name,user_role,login_time` (+`admin_logged_in`) |
| Quick-login | `loginRoutes.php:415-504` | **identical** session keys |
| Remember-me cookie | `loginRoutes.php:32-83` | same session keys |
| API `POST /api/login` | `api/users/loginRoutes.php:8-168` | **no session — JWT** (15-min access + 15-day refresh) |

- **All lookups are email-only** (`['email'=>$email]`). Password = bcrypt `password_verify`.
- Lockout: 5 fails → 30-min (`login_attempts` + `locked_until`), on every path.
- **`_lic_check($db)` blocks ALL logins** before credentials (`loginRoutes.php:131-139`) — any
  new login path MUST include it.
- Admin/supplier/agent all flow through the same `/login` with role-based redirect.

### Roles
`role` ∈ `customer|agent|supplier|admin`. Customer vs agent decided at signup by `user_type`;
supplier/admin elsewhere. Supplier needs admin approval; `status` enum widened by
`ensureSupplierSchema`.

---

## 2. OTP + DELIVERY INFRA (R4)

- **One shared `users.otp` + `users.otp_expires`** column pair, reused by signup (24h) and
  password-reset (5min). Verify at `POST /api/verify-otp` with **burn-after-5-wrong**
  (`passwordResetRoutes.php:229-267`) + 60s resend cooldown (`:51-58`).
- 🔴 **OTP COLLISION (CRITICAL):** a new phone/login OTP would be a **third** writer of the
  same single column — reset overwrites signup, burn clears all. **Must add `otp_type`
  (or dedicated columns) before building phone-OTP.** Verdict confirmed by audit.
- **Delivery:** `SENDWHATSAPP($toPhone,$toName,$message,$mediaUrl=null)` (`functions.php:3747`)
  → provider from `settings.whatsapp_provider` (default **greenapi**). `sendNotification` fans
  out email/WA/SMS but is event-based (booking), not an OTP sender. **No `sendOTP()` exists.**
- **Phone normalization is ready:** `buildE164PhoneNumber` (`:2345`), `isValidE164Phone`
  (`:2368`), `getPhoneCode` (`:2278`), `formatPhoneNumber` (`:2300`).
- **RateLimiter** (`modules/RateLimiter.php`) reusable to throttle OTP-send (WhatsApp-bombing).
- Schema-add template: `ensureCoreFixSchema` (`functions.php:856-942`) — `SHOW COLUMNS` then
  `ALTER`, fail-soft.

### "GIA sends the OTP" — honest interpretation
The OTP is a **deterministic system message** sent over the same WhatsApp number GIA runs on
(Green API/Meta) via `SENDWHATSAPP` — NOT the LLM composing it. That is correct and safer. GIA
the conversational agent can *prompt* "I've sent a code to your WhatsApp", but the code itself
is generated+sent by code, never by the model.

---

## 3. GIA PERSONALIZATION, IDENTITY, CONTEXT (R5, R6, R7, R8, R9, R12)

### What GIA knows today (thin)
- `setUserProfile()` injects ONLY `first_name`, `full_name`, `currency`, `role`
  (`giaAgent.php:100`; source `gia_user_profile` `gia_crm.php:495`). No bookings, no wallet,
  no email/phone, no history summary.
- Web: reads `$_SESSION['user_id']` and personalizes **only if logged in**
  (`giaRoutes.php:276-278`) — ignores the conversation's own linked `user_id`/`lead_id` when
  session is empty (gap G6).

### Identity on channels (the "context aware on the number" piece — partly built)
`gia_identity.php`:
- `gia_identity_resolve()` (`:247`) → `['user_id','verified','match']`, called per turn
  (`gia_channel.php:178`).
- **Verified link** (`gia_channel_links` table): 6-digit code, SHA256-hashed, 10-min, max-5
  attempts, delivered **out-of-band via account email** by `verifyIdentityTool` (channels-only,
  `verifyIdentityTool.php:61` guards `AI_NO_CHANNEL`; `SENDEMAIL` at `:96/:102`).
- **Passive phone match** (`gia_identity.php:88`): **WhatsApp-only**; `users.phone` exact →
  last-10-digits; greeting-only today. ⚠️ **returns FIRST match only** → shared-phone collision.
- Context handed to tools (`gia_channel.php:330` sets `identity_verified`): `account_id` (any
  match), `identity_verified` (bool), `user_approved=false` always on channels.
- **Today:** on channels, `verifyIdentityTool` is registered (`gia_channel.php:364`) and
  `getOrderStatusTool` **only if verified**; unverified users get a system note telling GIA to
  run `verify_identity` first.

### Conversation memory (good)
- Persisted in `gia_messages` (per `conv_id`); last **20 turns** replayed
  (`giaRoutes.php:176`, `gia_crm.php` history fn).
- Conversation keyed per channel identity: `gia_conversations` UNIQUE `(channel,channel_user_id)`
  — a returning WhatsApp number resumes the same thread (`gia_conv_get_or_create_channel`
  `gia_crm.php:202`).
- `gia_lead_capture()` exists (`gia_crm.php:384`) — matches email/phone→users, links convo —
  but **is never called by any tool** (dead until wired).

### GIA tools — the account gap
- Tool contract: `giaToolInterface` (`giaToolInterface.php` — `name:19 description:22
  inputSchema:30 riskClass:36 run:46`) = `name/description/inputSchema/riskClass/
  run($input,$context)`. `$context` is route-trusted.
- **Register in TWO places:** web `giaRoutes.php` (`registerTool` block from `:250`) + channel
  `gia_channel.php` (`verifyIdentityTool` registered `:364`, verified-gated `getOrderStatusTool`).
- 21 tools exist (umrah/flights/stays/generic booking chain + `get_order_status` +
  `verify_identity` + `request_human`). Risk ceiling R0–R2; R3 pay only with `user_approved`;
  R4+ blocked.
- 🔴 **NO account tools:** cannot register a user, update a profile, or **list all bookings**.
  `get_order_status` reads **ONE** booking by `invoice_id` only (`getOrderStatusTool.php:45-80`).

---

## 4. WHATSAPP: BUTTONS + FORMATTING (R10, R11)

### How a reply is built/sent (verified pipeline)
1. `giaAgent::run()` (`giaAgent.php:129`) returns **plain-text `reply`** + `tool_runs`.
2. `gia_channel_build_rich($reply, tool_runs, …)` (`gia_channel.php:587-882`) **deterministically
   builds buttons/cards from TOOL OUTPUT data** (flights/stays/umrah/offers results, payment
   buttons). The model never emits buttons — the channel layer attaches them.
3. `gia_channel_send_whatsapp()` (`gia_channel_send.php`):
   - **Meta mode** (`:111`): `gia_render_whatsapp_interactive()` (`gia_channel_render.php:149`)
     → native buttons (≤3) / list (>3) with ≤72-char descriptions; text fallback at `:138`.
   - **Green API mode (your choice)** (`:143` BETA comment): numbered text via
     `gia_render_text_fallback()` for choices (`:146`).

### Buttons verdict (R10) — must be honest
- **Buttons/cards DO exist and work in Meta Cloud API mode.** (I previously under-stated this.)
- **Green API = buttons IMPOSSIBLE.** Provider `send()` only POSTs `{chatId, message}`; no
  interactive endpoint exists anywhere (`greenapi.php:46-74`; explicit BETA comment
  `gia_channel_send.php:143-146`). So on Green API, "buttons" = **numbered-list choices** that
  the user replies to by number. The model is already taught this via the **PICK BUTTONS**
  system note (`gia_channel.php:475-496`) — tapping/typing `1` or `pick|module|ref` is handled.
- ⇒ **Decision locked = stay Green API** ⇒ deliver the best numbered-choice UX + formatting;
  native tappable buttons require switching the channel to Meta Cloud API (future, infra +
  Meta WABA onboarding). The Meta renderer stays in place, untouched, for that future switch.

### Formatting verdict (R11) — real gap, clear fix
- System prompt only forbids markdown **tables** (`giaAgent.php:749`, the "FORMATTING — PLAIN,
  SHORT, SCANNABLE" line); still permits `**bold**`/`- bullets`.
- **Web widget renders markdown** (`gia.php` `renderMarkdown`), so the model is encouraged to
  emit markdown — which then **leaks raw to WhatsApp** (`**bold**` shows literally).
- **Fix hook = `gia_render_text_fallback()` (`gia_channel_render.php:32`)** — but VERIFIED that
  function is used in THREE call sites in `gia_channel_send.php` (`:138` Meta fallback, `:146`
  Green API choices, `:202` a third path) AND the Green API **no-choice** branch is a bare
  `(string)$norm['text']` at `:146` that BYPASSES it. ⇒ to cover EVERY WhatsApp text the
  converter must be applied so all three sites + the bare-text branch pass through it — not
  only `gia_render_text_fallback`. Web widget stays untouched. (Confirmed by direct read.)

### Admin control (exists)
`GET /admin/gia/channels` (`admin/giaRoutes.php:304`) → `require app/views/admin/gia/channels.php`
(`:317`): per-channel enable, credentials, one-click webhook register (`greenapi-webhook:414`),
test (`:354`). *(An agent wrongly said this view was missing; re-verified — the file exists and
is rendered.)*

---

## 5. BOOKINGS / WALLET / LOYALTY DATA MODEL (R12) — the "fully aware" substrate

- 🔴 **TWO booking tables, not one** (I verified this directly — corrects an agent claim):
  1. **`bookings`** (`install/db.sql:1212`) — the 9 services via `module_type`
     (flights/stays/hotels/tours/cars/bus/rail/visa/esim/ferries/insurance), supplier in
     `module`. Status split: `booking_status` (confirmed/pending/cancelled) +
     `payment_status` (paid/unpaid/refunded/partially_paid/awaiting_verification). Ref =
     `invoice_id`; `pnr`; `price_markup`+`currency_markup`; `paid_at`; `created_at`; rich
     `booking_data` JSON.
  2. **`umrah_bookings`** (`install/db.sql:32939`) — umrah v2, **different enums**
     (`booking_status: held/confirmed/cancelled/completed`; `payment_status: unpaid/
     deposit_paid/partially_paid/fully_paid/overdue/refund_pending/refunded`), + installment
     schedule tables. Has `user_id`, `invoice_id`, `total_price`, `amount_paid`, `currency`.
  ⇒ The "all bookings" tool must read **both** and normalize the two status vocabularies.
- **Link is `*.user_id` = `users.user_id` (custom VARCHAR), never numeric `id`** — explicit code
  warning at `flights/bookingRoutes.php:920`. Recipe:
  `$db->select('bookings','*',['user_id'=>$uid,'ORDER'=>['id'=>'DESC']])` +
  `$db->select('umrah_bookings',…)`.
- **Booking truth ≠ raw columns.** Use `gia_commit_order_state()` (`gia_commit.php:134`):
  CONFIRMED/PARTIAL/PAID_PENDING/UNPAID/FAILED — the tool must reuse this.
- **Wallet:** `wallet_balance($db,$uid,$currency)` (`wallet.php:123`) reads `wallets` per
  (user,currency). `users.balance` is DEAD (seed-once). Ledger = `wallet_ledger` / audit =
  `money_transactions`.
- **Loyalty:** latest `loyalty_ledger.balance_after`; cached in `users.loyalty_points`.
- **Profile columns** for "knowing the user": first/last name, email, phone, phone_country_code,
  country/city/state/address, currency, language, timezone, role, status, created_at,
  last_login, loyalty_points, agent_tier_id.

---

## 6. USER CREATE/UPDATE REUSE MAP (R6, R7)
- Create: mirror `api/users/signupRoutes.php` (insert at `:185`, id via `$db->id()` `:186`) —
  `generateUserId()` (`functions.php:54`), build insert, `$db->insert('users',$a)`. For GIA
  passwordless: omit password, `role=customer`, `status` active-after-OTP, phone from channel,
  `phone_verified=1` once OTP passes.
- Update: mirror `admin/usersRoutes.php:185` (`$db->update('users',$data,['user_id'=>$uid])`).
  GIA allow-list: name, currency, language, prefs; email change ⇒ re-verify; NEVER
  role/status/password/balance.
- No helper wrappers exist — safe to add `gia_user_create()/gia_user_update()` thin helpers in
  `lib/ai/` that enforce the allow-list, so both the tool and route share one guarded path.

---

## 7. OWNER DECISIONS (locked 2026-10-24)
1. Phone at signup = **OPTIONAL** (email-only stays).
2. WhatsApp provider = **GREEN API** → numbered-choice UX + markdown→WA formatting; no native
   buttons (Meta renderer kept for future).
3. GIA-created accounts = **PASSWORDLESS phone-OTP** login.
4. Passive phone-match = **FULL CONTEXT, NO CONFIRMATION** (owner decision 2026-10-24:
   "fully trust the match"). GIA will show bookings/wallet to any WhatsApp number that matches
   `users.phone`. ⚠️ ACCEPTED RISK: a spoofed/shared number could read another person's account
   data. Keeping exact + last-10-digit match as-is (first-match wins); no YES-gate. (T14 drops
   the confirmation step; passive-match stays as the existing `gia_identity_phone_match`.)
5. GIA = **FULLY user-aware** — all bookings (both tables), wallet, loyalty, upcoming/past,
   answered from real data, never hallucinated. Gated to the identified user.
- Defaults I'll apply unless told otherwise: OTP = WhatsApp-first, email fallback;
  profile-update allow-list = name/currency/language/prefs.

---

## 8. GAP LIST (verified)
| # | Gap | Evidence | Size |
|---|-----|----------|------|
| G1 | Signup collects no phone (defaults `92`) | `signup.php` no input; `signupRoutes.php:184` | S |
| G2 | Login email-only (4 entry points) | all `['email'=>…]` | M |
| G3 | No phone-verify OTP; **single `otp` column collision** | `passwordResetRoutes.php`; one column | M |
| G4 | No OTP-over-WhatsApp sender | no `sendOTP`; `SENDWHATSAPP` text-only | S |
| G5 | GIA has no account tools (register/update/list-bookings) | tool registry; `get_order_status` is single-booking | L |
| G6 | Web personalization ignores convo's linked user | `giaRoutes.php:276-278` | S |
| G7 | GIA context = 4 fields only; no awareness of trips/wallet | `gia_user_profile:495` | M |
| G8 | Markdown leaks raw to WhatsApp | no transform; `gia.php:220` renders it on web | S |
| G9 | Native buttons need Meta; Green API = numbered text only | `greenapi.php:46-74` | infra |
| G10 | Passive phone-match = first-match-only, unverified | `gia_identity.php:102` | M |
| G11 | `otp_type` / `phone_verified` columns missing | NOT FOUND | S |
| G12 | `gia_lead_capture()` built but never called | `gia_crm.php:384` | S |

---

## 9. TASK LIST (phased; each = its own PR, verified, ships via /updates.php)

> **BUILD ORDER (owner-locked 2026-10-24): STRICT 0 → 1 → 2 → 3 → 4 → 5 → 6.**
> Auth foundation lands before GIA smarts. Phase 0 first (mandatory — fixes OTP collision).

### Phase 0 — Schema groundwork  [G3, G11]
- T0. `ensureGiaAuthSchema` (new ensure-fn + `install/db.sql`): add `users.phone_verified`
  TINYINT, `users.otp_type` VARCHAR(20) (or `otp_purpose`); add a non-unique index on `phone`.
  Resolve OTP collision by scoping every OTP to a purpose.

### Phase 1 — Phone on signup (OPTIONAL) + E.164  [G1]
- T1. Add optional phone + country-code selector to `signup.php`; store true E.164 via
  `buildE164PhoneNumber` (kill the `92` default); validate; mirror on `/api/signup`.

### Phase 2 — OTP engine + WhatsApp delivery  [G3, G4]
- T2. `lib/otp.php`: `otp_issue($db,$uid,$purpose)` / `otp_verify(...)` — purpose-scoped (uses
  `otp_type`), 5-min, 60s cooldown, burn-after-5 (copy proven guard), RateLimiter on send.
- T3. `sendOtpWhatsApp($db,$phone,$code)` via `SENDWHATSAPP` (+ email/SMS fallback).
- T4. Wire signup phone-verify → set `phone_verified`.

### Phase 3 — Login with email OR phone  [G2, G10]
- T5. Generalize the lookup across ALL 4 entry points (identical session keys + `_lic_check` +
  lockout): phone-shaped identifier → normalized `phone` lookup, else email. Handle
  shared-phone (require email disambiguation or match verified account).
- T6. Passwordless **phone-OTP login** path (reuses `lib/otp.php`, purpose=`login`).
  **SPLIT INTO ITS OWN PR (Phase 3b).** Reason: the password `/login` success path
  (session keys + remember-me + deferred webhook + role-based safe-redirect, ~70 lines) must
  NOT be duplicated. 3b will first extract a shared `establish_customer_session()` helper and
  refactor BOTH the password path and the new OTP path onto it — done carefully to avoid
  regressing the working login. Phase 3a (email-OR-phone *password* login) shipped first.

### Phase 4 — WhatsApp formatting  [G8]
- T7. `gia_md_to_whatsapp($text)` (`**b**`→`*b*`, `_i_`, `- `/`* `→`• `, strip `#`/tables,
  collapse blanks). Apply at `gia_channel_render.php:35` **and** the plain-text branch
  `gia_channel_send.php:146` so all WhatsApp text is covered; web widget untouched.
- T8. Tighten the WhatsApp system note: "use WhatsApp-native emphasis, short lines, no markdown".

### Phase 5 — GIA account tools + FULL awareness  [G5, G7, G12] (your priority)
- T9. `userAccountTool` (R0 read): for the identified `account_id`, read **both** `bookings`
  (+`gia_commit_order_state`) **and** `umrah_bookings`, normalize to one list (service, ref,
  status, dates, amount, paid?), split upcoming/past; + wallet balance + loyalty + profile.
  Compact summary to model; never other users' data. Register web + channel.
- T10. Inject a one-line awareness summary at conversation start ("you have N upcoming trips,
  next is X on {date}") for the identified user.
- T11. `registerAccountTool` (R2): create a `users` row from phone+name **after OTP-verified
  phone** (Phase 2), passwordless; block if phone exists (offer link). Wire `gia_lead_capture`.
- T12. `updateProfileTool` (R2): allow-list (name/currency/language/prefs); email re-verifies;
  via a guarded `gia_user_update()` helper.
- T13. Ensure the identified `user_id` flows into booking tools' `account_id` so GIA acts as
  the user (already the pattern).
- T14. Passive-match safety (decision #4): exact/last-10 match + one-time YES confirm before
  reciting sensitive data.

### Phase 6 — Personalization correctness  [G6]
- T15. In `giaRoutes.php`, fall back to the conversation's own `user_id`/lead when
  `$_SESSION['user_id']` is empty, before treating as anonymous.

---

## 10. RISKS / NON-NEGOTIABLES
- **OTP collision (G3/G11) is a prerequisite** — Phase 0 MUST land before any new OTP, or
  signup/reset/login codes clobber each other.
- **Two booking tables** — the awareness tool is wrong if it reads only `bookings`.
- **Full-context-on-passive-match** is the one real privacy exposure; the YES-confirm (T14) is
  my mitigation — awaiting your sign-off.
- **Model IDs** (`claude-sonnet-4-6` etc.) may be stale — load `claude-api` skill before editing
  `app/lib/ai/`.
- **No SQL / no deploy by me.** Schema via `install/db.sql` + ensure-fns; ships through
  `/updates.php` + admin DB tool.
