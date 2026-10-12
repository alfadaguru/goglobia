# Public marketplace surface audit — approved-only coverage across every entry point

**Date:** 2026-10-12
**Question:** does the `status=1 AND listing_status='approved'` gate hold on EVERY public
read of own-inventory stays — search, detail, home, rooms/pricing, branded site, AI,
autocomplete, sitemap — so no draft/submitted/queried/rejected listing leaks or sells?

Marketplace model: own-inventory hotels (module `stays`/`hotels`, in the `stays` table,
moderated by `listing_status`) co-list with API suppliers (hotelbeds/ratehawk/agoda/…,
whose inventory comes from their APIs, not the `stays` table — they have no
`listing_status` and are correctly not gated by it).

---

## Surface coverage (every public own-inventory read verified)

| Entry point | File | Approved filter |
|---|---|---|
| Search results | `modules/stays/hotels/search.php:413,415` | ✓ |
| Detail (module) | `modules/stays/hotels/details.php:116` | ✓ |
| Rooms + pricing (sell source) | `modules/stays/hotels/rooms.php:73` | ✓ |
| API home / featured / locations | `app/routes/api/stays/homeRoutes.php:27,164,172` | ✓ |
| API listing/search | `app/routes/api/stays/listingRoutes.php:96` | ✓ |
| **API detail `GET /api/stay/{id}`** | `app/routes/api/stays/detailRoutes.php:18` | **✓ FIXED (was status-only)** |
| Branded site (host + /book/slug) | `app/routes/stays/brandedSiteRoutes.php:17,62` | ✓ |
| AI revalidate-before-book | `app/routes/ai/tripRevalidateHelper.php` | ✓ |
| Booking submit (web + API) | `bookingRoutes.php` (both) | ✓ gated (prior commit) |
| Autocomplete / destination | `destinationSuggestionRoutes.php` | N/A — reads `locations`, not `stays` |
| Sitemap | `generateSitemap` (functions.php) | N/A — emits home + module landings + CMS + blog only; no per-hotel URLs |

## Defect found + fixed

**MED — `GET /api/stay/{id}` leaked unapproved listings.** This PUBLIC detail endpoint
(auth bypassed for the web client via `is_web_client`) fetched a stay with **only
`status=1`** — no approval filter. It returns the full rooms array + `room_options`
pricing + `starting_price`. So a draft/submitted/queried/rejected own-inventory property
with its on/off switch left at 1 leaked its bookable detail + prices to the public.
(The booking submit is already gated, so you couldn't *complete* a purchase of it — but
the content/pricing leak is a real moderation hole.) **Fix:** added
`listing_status => 'approved'` to the fetch, matching every other public read. The
supplier's own preview uses the separate owner-gated
`/supplier/stays/site/{id}/preview` route and is unaffected.

## Verified clean

- All other public own-inventory reads already gate `approved` (table above).
- API-supplier modules query `stays` only for their content-cache (not for public
  sell-listing); `listing_status` correctly doesn't apply to them — no regression.
- Autocomplete reads the `locations` table, not `stays` — no hotel-name leak.
- Sitemap lists no individual hotel URLs — nothing to leak to crawlers.

## Verified (how)

- `php -l` clean on the changed file.
- Coverage tally: every public own-inventory entry point now contains the approved
  filter (grep-confirmed count per file).

## Not verified (honest limit)

No live DB. The fix is a filter addition proven by code read + lint, not a live request.
First real confirmation: `GET /api/stay/{id}` for a draft own-inventory property returns
"Stay not found" (was leaking), while an approved one still returns rooms + pricing; an
API-supplier search/detail is unaffected.
