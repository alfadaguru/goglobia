# White-Label Gap Analysis #2 — Supplier Config + Visitor Booking (verified)

> A deep, two-sided gap analysis from a 4-agent research pass (supplier configuration + visitor
> booking + manage-booking + template-preview), with EVERY load-bearing claim re-verified by me
> against real source (file:line). Companion to WHITE-LABEL-TEMPLATES.md (the shipped template
> system). This is the design-of-record for the NEXT phase. No fixes applied yet.

## The honest headline

The template system looks 5-star (shipped + screenshot-verified). Two real problems remain, and
the research pinned them precisely:

1. **The booking MECHANICS work end-to-end for a local hotel — but booking happens in PLATFORM
   chrome, and the confirmation email is platform-branded.** So the guest leaves the beautiful
   template and the white-label illusion breaks at the two highest-stakes moments (checkout + email).
2. **The supplier can't SEE what they're choosing or fully set it up** — template picker is a colour
   gradient, and there's no hero/favicon uploader.

Booking is **not** "unwired" — it's **un-branded** at the edges.

---

## PART A — Visitor booking journey (VERIFIED end-to-end)

Deep-link from the template search widget:
`/stay/{slug}/{id}/hotels/_/{ci}/{co}/{nat}/1/{adults-children}` (supplier='hotels' = local inventory).

| Step | Verdict | Evidence |
|---|---|---|
| URL parse + nationality | ✅ works | detailRoutes.php:11-60 (nationality modal enforces a valid ISO) |
| `localStorage last_hotel` dependency | ✅ cosmetic only | stay.php:908-926 — address/amenities fallbacks; real data from DB |
| Rooms/rates fetch | ✅ works | modules/stays/hotels/details.php:206 + rooms.php (queries stays_rooms + room_options; requires listing_status='approved') |
| Select room → draft | ✅ works | bookingRoutes.php:80 save-draft → hash |
| **No-oversell hold** | ✅ **VERIFIED wired** | bookingRoutes.php:775,792 — `/api/stay/booking/submit` calls `stays_hold_create` for `$isOwnInventoryStay` BEFORE payment (I verified this; the research had marked it "unverified") |
| Payment → consume hold | ✅ works | payment-gateway.php:477 `stays_on_paid` (held→consumed); Adyen:378 |
| Issue → confirm | ✅ works | modules/stays/hotels/actions/issue.php — price-trust guard, PNR, booking_status='confirmed' |
| Invoice | ✅ works | invoiceRoutes.php; token-based access (enforceInvoiceAccess) |
| On tenant host | ✅ works | session + payment callbacks are host-relative (paymentReturnUrl host-match) |

### The REAL gaps on the visitor side
- **G1 (Major) — Booking pages render PLATFORM chrome.** `/stay/{slug}/...` detail + `/stays/booking/{hash}` + `/invoice/stays/...` use the platform header/footer, not the hotel's template. The guest clicks "Check availability" on a gorgeous Grand/Resort site → lands on the Goglobia stay page. White-label illusion breaks at checkout. (The P3 `detailRoutes` tenant-chrome hook sets `wl_set_tenant_context`, but the stay/booking/invoice VIEWS still render platform `includes/header.php`, not a tenant shell.)
- **G2 (Major) — `last_hotel` cosmetic gap.** On the tenant host the stay-detail header shows empty address/amenities (the guest never hit platform search to populate localStorage). Booking still works; it just looks half-filled. Fix: have `modules/stays/hotels/details.php` return address/amenities/stars from `stays` (it already has them) so the page doesn't depend on localStorage.

---

## PART B — Manage booking + guest account (VERIFIED)

**Guest CAN (token-based, no login):** view invoice, download PDF, resend email, request cancellation,
complete online check-in (`/stay-checkin/{invoiceId}`), pay if unpaid.
**Guest CANNOT:** modify dates/rooms, self-cancel+refund (request only; admin/supplier approves), reach
"My Bookings" (needs login + a password that's never delivered).
**Supplier CAN (PMS inbox, supplierReservationsRoutes.php):** view, check-in/out, no-show, cancel
(releases holds + voids earnings), full folio. Solid.

### The REAL gaps on the manage side
- **G3 (Major) — Confirmation email is PLATFORM-branded.** VERIFIED: notify.php:267 `companyName =
  $settings['business_name']` (platform), `supportEmail` defaults `support@phptravels.com`,
  emails/header.php:13,50 hardcodes `uploads/global/logo.png` (platform logo), footer.php:27 "©
  {platform}". A white-label guest's booking email shows **Goglobia's logo/name/support** — a direct
  white-label break. Fix: make the stays email use the hotel's name/logo/contact (per-booking).
- **G4 (Minor) — Auto-created guest password never sent** (bookingRoutes.php:~720). Guest can't reach
  "My Bookings". Low impact (token-invoice covers management) but should either deliver creds or drop
  the auto-account.
- **G5 (Minor) — Online check-in not linked** from the invoice/email, only via direct URL.

---

## PART C — Supplier configuration (VERIFIED)

Core content is well-covered (property form wizard: name/desc/location/stars/contact/photos/logo/
amenities/policy; rooms.php for rooms+rates; the configurator for template/colours/fonts/hero-copy/
sections/social/SEO/languages/pages).

### The REAL gaps on the supplier side
- **G6 (Critical, owner's complaint) — Template picker = colour gradient only** (site.php:~94). A
  supplier can't tell what the template looks like. Fix: real screenshot thumbnails per template
  (I already have them at docs/supplier/wl-templates-shots/*-fold.png) + a per-template "Live preview"
  button.
- **G7 (Critical) — No hero-image uploader and no favicon uploader** (VERIFIED: 0 such inputs in
  site.php). `hero_url`/`favicon_url` exist in the theme but the supplier has no UI to set them — they
  can only fall back to the gallery "main" photo / logo. Fix: add upload fields (reuse the hardened
  `_supplier_stays_process_gallery`/secureUploadCheck path).
- **G8 (Major) — No live preview of UNSAVED changes.** Preview shows only the saved state → confusing.
  Fix: either a `?template=X`+draft preview, or clearly label "preview shows your last saved version".
- **G9 (Major) — Empty Reviews section renders a bare "What our guests say"** when a new hotel has 0
  reviews (reviews.php). Offers already hide when empty — make Reviews consistent (hide when 0).
- **G10 (Minor) — Website setup not in the property-hub checklist** → a supplier may never discover the
  site builder. No map-picker for coords (manual lat/lng). About has two sources (theme.about vs a
  custom "About" page) — precedence unclear.

### Template-preview constraint (VERIFIED)
`X-Frame-Options: DENY` + CSP `frame-ancestors 'none'` (config.php:282,315) **block any iframe preview**
— even same-origin — without weakening the whole platform. So: **static screenshot thumbnails in the
cards + a "Live preview" link opening `/preview?template=X` in a NEW TAB** (dodges the frame block).
`/assets/*` is served statically (.htaccess `!-f`), so thumbnails placed there load directly. The
preview route currently ignores `?template=` (confirmed) — a ~6-line override adds no-save preview.

---

## NEXT PHASE — proposed build (priority order, each verified-small)

1. **Tenant chrome on the booking flow (G1)** — the biggest immersion fix. Render `/stay`,
   `/stays/booking`, `/invoice/stays` in the hotel's shell when on a tenant host / white-label stay
   (reuse the standalone layout's header/footer or a slim tenant wrapper; money path unchanged).
2. **Per-hotel confirmation email (G3)** — stays booking email uses the hotel's name/logo/contact
   instead of the platform's; add the check-in link (G5).
3. **Template picker thumbnails + live preview (G6, G8)** — ship the screenshots as card images +
   `/preview?template=X` new-tab button + `?template=` no-save override.
4. **Hero + favicon uploaders (G7)** — add the two upload fields to the Design card (secure upload).
5. **Fix empty Reviews + last_hotel cosmetic (G9, G2)** — hide empty Reviews; return address/amenities
   from the details endpoint so the detail header isn't blank.
6. **Discoverability polish (G10)** — website step in the hub checklist; map picker; About precedence.

### Non-negotiables
- The booking engine (price-integrity + no-oversell holds) is PROVEN wired — do NOT touch it; only
  re-skin the views. Every change verified by Playwright render + screenshot. No platform-wide header
  changes (CSP/X-Frame stay). All supplier text escaped.
