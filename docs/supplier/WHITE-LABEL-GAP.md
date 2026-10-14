# White-Label Hotel Website — Gap Analysis & Build Plan (design-of-record)

> Grounded in a two-wave, seven-agent code study (2026-10-14), every claim verified
> against real source — `install/db.sql`, the views, the router, the payment layer. Where
> a claim is "data exists / not rendered" it was confirmed by reading both the schema and
> the rendering code. Nothing here is assumed. This is the design of record for turning the
> current branded *page* into a real white-label *website*, gated by admin approval.

---

## 0. The one-line truth

Today's "branded site" is a **single booking card wrapped in the full Goglobia website**.
A real white-label site is **the hotel's own multi-page website, its own identity in the
chrome, booking completed on its own domain, platform invisible.**

The good news the deep study surfaced: **the hard parts are already correct.** The money
path (price-integrity, no-oversell holds, payment callbacks, sessions) is **host-relative
and works on a custom domain with zero changes.** The rich content is **mostly already in
the database, just not rendered.** The real work is **presentation + host-aware routing +
killing the platform chrome** — not new infrastructure.

---

## 1. What's verified correct already (do NOT rebuild)

| Capability | Evidence | Status |
|---|---|---|
| Host→stay resolution (subdomain + verified custom domain) | `stays_site_resolve_host` functions.php:7097 | ✅ works |
| Slug uniqueness + hostname sync on rename | `stays_unique_slug`, `supplier_site_sync_hostname` supplier_site_host.php | ✅ hardened |
| Custom-domain DNS verify + TLS edge (Caddy on-demand) | `supplier_site_verify_domain`, `supplier_site_tls_authorize`, deploy/Caddyfile | ✅ built |
| **Payment return/callback URLs are host-relative** | `paymentReturnUrl` functions.php:192 (host-match); payment-gateway.php:132-175 builds from `root` | ✅ **safe on tenant/custom host** |
| **No-oversell hold engine** | `stays_hold_create` stays_inventory.php (FOR UPDATE per night) | ✅ reused as-is |
| **Session isolated per host** | config.php:204 (no cookie `domain`) | ✅ correct for tenant isolation |
| `is_web_client` set on tenant page loads (so hotel JS can call /api) | index.php:154 | ✅ works |
| Rich data ALREADY stored | see §3 | ✅ mostly present |

**Implication:** booking can happen **on the tenant host end-to-end** (subdomain and the
hotel's own external domain) without a platform bounce. The only reason it "leaves" today
is the **Book link points to `/stay/{slug}` which renders platform chrome** — a presentation
problem, not a money problem (`branded-preview.php:162`).

---

## 2. The gaps, by severity

### BLOCKERS (it isn't a white-label site until these are fixed)

**B1 — Platform chrome on every tenant page (root cause: header/footer have zero host-awareness).**
`_branded_render_stay` wraps the page in `includes/header.php` + `includes/footer.php`
(brandedSiteRoutes.php:44-46), which **unconditionally** emit platform identity. Verified leaks:
- Platform logo `uploads/global/logo.png` (header.php:439, footer.php:51)
- "© {business_name}" + hardcoded **"Built with ♥ by GoGlobia.com"** link (footer.php:283,286)
- Services nav Flights/Tours/Cars/Visa from `$GLOBALS['modules']` (header.php:445-463)
- Platform account/login/"Become a Supplier" menu (header.php:644-747)
- Platform cart (header.php:635), CMS footer menus (footer.php:102-128), platform legal links
  `/page/privacy-policy` (footer.php:291-328), platform contact (footer.php:133-196), platform
  socials (footer.php:198-264)
- Platform favicon + PWA name "Goglobia" (header.php:224,370-377)
- Hardcoded Pusher key fires on the hotel's domain (footer.php:433) — privacy leak
- Header/footer have **no** host/branded branch (grep confirmed).

**B2 — Booking renders in platform chrome.** The public "Book" link → `/stay/{slug}` → the
marketplace flow with platform header/footer (`branded-preview.php:162`; maybe_serve only hooks
the root, supplier_site_host.php:121-122). Money path is fine; chrome is not.

**B3 — Only the root page is a tenant page.** `supplier_site_maybe_serve` serves only `''`/`book`
on a tenant host; `/rooms`, `/about`, `/contact` fall through to platform routing / 404
(supplier_site_host.php:118-122). A website needs multiple pages at clean URLs.

**B4 — Per-page SEO/OG/identity is the platform's.** `meta_title`/`meta_desc` are stored in the
theme but **never wired to `<head>`**; OG image + JSON-LD identify `Organization: Goglobia`
(header.php:297-367). Site-builder `analytics_id`/`social` are dead fields in the head/footer.

### MAJORS (a real hotel site is poor without these — but data mostly exists)

- **M1 Rooms are bare.** `stays_rooms.room_images` stored but **never shown**; cards show only
  "Room #id + min price" (branded-preview.php:130-197). `room_name`/`amenities` exist; **net-new**:
  `description`, `size_sqm`, `bed_config`, `view_type`.
- **M2 No reviews shown.** `stays_reviews` fully modelled — `status` enum (pending/published/hidden),
  `owner_reply`, indexed `(stay_id,status)`; `stays.rating`+`rating_count` aggregates exist. **Zero
  public rendering.** Display is pure presentation + a toggle.
- **M3 No offers/packages.** `stays_packages` stored, not surfaced.
- **M4 No gallery / no map.** `stays.img` gallery + `stays.location_coords` (lat,lng) stored; only a
  single hero + text address rendered. No lightbox, no map embed.
- **M5 Custom pages are plain text** (`sitepages_*` body escaped) — no images/layout; reached by a
  button row, not a real nav.
- **M6 Multi-language content.** `stays.translations` column exists but **unused**; amenity/board
  names translate at `stays_settings` level only. Net-new UI + a translations store for per-stay content.
- **M7 Theme model too thin.** Stores one accent + text; **missing**: favicon, hero choice, fonts,
  header/footer colors, nav/footer structure, feature toggles (show_reviews/packages).
- **M8 Session on a hotel's OWN external domain** is isolated to that host (correct for privacy) —
  but it means booking must *complete* on that host. Verified it does (payment is host-relative). The
  only caveat: a guest's platform account/session does **not** carry across to the external domain
  (expected; the white-label guest is a guest of the hotel site, not the platform).

### MINORS
Lang/currency switchers, cart icon, JSON-LD type, PWA icons, per-request host-lookup perf
(`supplier_site_maybe_serve` runs every request but early-returns on primary host).

---

## 3. Data model: what EXISTS vs what's NET-NEW (verified against install/db.sql)

| Rich-site section | Backing data (verified) | Rendered today? | Net-new needed |
|---|---|---|---|
| Rooms (name, images, options, amenities) | `stays_rooms.room_name,room_images,room_options,amenities` | partial (price only) | `description,size_sqm,bed_config,view_type` |
| Gallery / hero | `stays.img` (JSON), `stays.logo` | hero+logo only | hero-choice, alt text, captions |
| Reviews + ratings | `stays_reviews(rating,comment,status,owner_reply)`, `stays.rating,rating_count` | **no** | display toggle only |
| Offers / packages | `stays_packages`, `stays_loyalty_config` | **no** | show/hide toggle |
| Amenities | `stays.amenity_ids`+`stays_settings`; `stays_rooms.amenities` | property only | per-room render |
| Location / map | `stays.location,address,location_coords (lat,lng)` | text only | map embed (client-side) |
| Policies | `stays.cancellation_policy,privacy_policy,house_rules,checkin/out,fees` | most shown | render house_rules/privacy |
| Multi-language | `stays.translations` (unused); `stays_settings.translations` | no | per-stay translation store+UI |
| Branding/theme | `stays_site.theme` (accent/tagline/banner/about/meta/social) | partial | favicon,hero,fonts,colors,nav,toggles |

**Verdict:** ~70% of a rich site is **data-ready, UI-sparse**. Only rooms (structural fields) and
per-stay translations are genuinely net-new storage.

---

## 4. Approval-gated activation (the new product rule)

White-label is now **request → admin-approve → activated** (not automatic). Reuse the existing
`listing_status` / supplier-service approve machinery verbatim.

- **Storage:** add to `stays_site` (idempotent self-heal + install/db.sql), mirroring `listing_status`:
  `site_status ENUM('none','requested','approved','rejected','suspended') DEFAULT 'none'`,
  `site_reviewed_by`, `site_reviewed_at`, `site_review_comment`.
- **Supplier:** "Request white-label site" button on the Site page → sets `requested`.
- **Admin:** a queue tab mirroring `/admin/supplier-listings` → approve/reject (`SENDEMAIL` notify, same
  pattern as suppliersRoutes.php:238-296).
- **Gates (all 5 serve/link points must require `site_status='approved'`):**
  `_branded_render_stay`, `/book/{slug}`, `/book/{slug}/{page}`, `supplier_site_tls_authorize`,
  `supplier_site_maybe_serve` (+ the new tenant routes).

---

## 5. Reference architecture (fits THIS codebase — no framework)

1. **Tenant detection before routes.** In `index.php` right after `config.php`, resolve
   `$__tenantStayId = stays_site_resolve_host($db, supplier_site_request_host())` (only when a branded
   root is configured). Pass into `_routes.php`.
2. **Conditional tenant route group, registered BEFORE primary routes** (router is first-match +
   insertion-order; verified modules/router.php dispatch loop). New `app/routes/stays/brandedTenantRoutes.php`:
   - `GET /` → branded home (tenant chrome)
   - `GET /rooms`, `/gallery`, `/contact`, `/offers` → tenant sections
   - `GET /{page}` → `sitepages_get` (published), guarded by `sitepages_reserved_slugs()` + the hardcoded
     section names, 404 otherwise
   - `GET /stay/...` and the booking POST → render the real engine **in tenant chrome**
   - every closure re-validates it's still on the tenant host (defense in depth)
3. **Tenant chrome.** A branded-context flag (e.g. `$GLOBALS['__tenant_stay']`) set by the tenant routes;
   header.php/footer.php branch on it to swap logo/name/contact/nav/socials/favicon/SEO and drop platform
   nav/cart/login/legal/Pusher/"powered by". This single flag neutralizes ~12 of the B1 leaks.
4. **Assets:** served by the web server as static on all hosts (`/assets/*`,`/uploads/*`); `root` is already
   host-relative so asset URLs resolve on the tenant host.
5. **Booking on-site:** reuse `stays_hold_create` + server pricing + `payment-gateway.php` unchanged (all
   host-relative). Only the view wrapper changes to tenant chrome. ~200 lines, no money-path edits.
6. **External-domain caveat:** the hotel's own domain gets its own host-scoped session (correct). Document
   that platform login does not cross to it; the white-label guest journey is self-contained.

---

## 6. Phased build (each phase shippable, lint + runtime-verified, committed separately)

- **Phase 0 — Approval gate.** `site_status` + supplier request button + admin approve/reject + email +
  gate all 5 entry points. *Feature becomes request→approve→activate.* (Smallest.)
- **Phase 1 — Kill the chrome leak.** Branded-context flag → tenant header/footer (hotel identity in,
  platform chrome out) + per-hotel SEO/OG/favicon + wire the dead theme fields. *Stops looking like Goglobia.*
- **Phase 2 — Make it a site.** Host-aware clean URLs + `brandedTenantRoutes.php` + real per-hotel nav; render
  the data that already exists: room photos/descriptions, gallery+lightbox, reviews, offers, map, amenities.
  Net-new room columns (`description/size_sqm/bed_config/view_type`) + theme extensions (favicon/hero/fonts/colors/nav/toggles).
- **Phase 3 — Book on-site.** Tenant checkout route rendering the existing hold+price+payment engine in tenant
  chrome; resolve external-domain UX. *Guest books without leaving the hotel's site.*
- **Phase 4 (optional) — Multi-language content.** Per-stay translation store + editor; language switcher on the
  branded site.

**Recommended order:** 0 → 1 → 2 → 3. Phase 0+1 alone deliver the approval rule *and* kill the thing the owner
reacted to (platform chrome). Phases 2-3 turn it into a true rich site, leaning on data that already exists.

---

## 7. Honest risks / what this is NOT

- Rendering lots of existing data ≠ trivial: room galleries, reviews, map, offers each need a real UI section +
  Tailwind classes that are **precompiled** (must verify classes exist, no arbitrary `[..]`).
- A hotel's own external domain still needs the **Caddy TLS edge** (deploy/Caddyfile) — unchanged from the
  current custom-domain work; cPanel AutoSSL covers `*.goglobia.com` subdomains.
- Multi-language content (Phase 4) is the only genuinely large net-new data effort; everything else reuses the
  existing schema.
- No money-path code changes in Phases 0-2; Phase 3 only wraps the existing engine — this keeps the
  price-integrity/no-oversell guarantees intact.
