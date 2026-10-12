# Per-listing approval flow — audit + fixes

**Date:** 2026-10-12
**Scope:** the `stays.listing_status` state machine (draft → submitted → approved /
queried / rejected), who moves it, and whether the public sell path actually keeps
unapproved listings from selling. Own-inventory only; marketplace API suppliers
(hotelbeds/ratehawk/…) have no `listing_status` gate (they aren't moderated by us).

---

## Verdict: the state machine + moderation are sound

- **Supplier submit** (`POST /supplier/stays/submit/{id}`): auth + CSRF + `supplier_can`
  + owner-scoped; sets `submitted`; emits `property.submitted` event.
- **Admin review** (`POST admin/supplier-listings/review/{id}`): `ADMIN_AUTH` + CSRF;
  decision map approve→approved / query→queried / reject→rejected; **approve flips
  status=1 (live)**, query/reject flip status=0 (offline); records reviewer/comment and
  emails the owner.
- **Public readers filter `status=1 AND listing_status='approved'`** on every surface
  checked: search (`api/stays/listingRoutes`), home/featured/locations
  (`api/stays/homeRoutes`), rooms/pricing (`modules/stays/hotels/rooms.php`), branded
  site (`brandedSiteRoutes`), and AI revalidate-before-book (`tripRevalidateHelper`).

## Defects found (verified) + fixes

1. **Booking submit had no approval gate (moderation + oversell bypass).** The public
   search/detail/rooms surfaces all filter `approved`, but the booking SUBMIT endpoints
   (web `/api/stay/booking/submit` and mobile API) did not re-check it — and
   `stays_recompute_net` fetches the stay by `id` only (no status/listing_status filter,
   correctly, since it is shared with the owner/front-desk walk-in path). So a
   hand-crafted POST with a draft/queried/rejected hotel's id + a valid room/option could
   price AND place a real hold AND create a payable booking against an unapproved listing.
   **Fix:** both public submit paths now require `status=1 AND listing_status='approved'`
   for own inventory before pricing/holding (web throws; API 409s). The owner/front-desk
   `direct_booking` path is deliberately NOT gated (it legitimately books pre-live
   properties and is owner-authorised) — the gate lives only in the public submit.

2. **Empty/unsellable listing was submittable.** `submit/{id}` flipped any state →
   submitted with no content check, so a property with zero rooms/rates could reach
   admin review and be approved as unsellable. **Fix:** a sellability guard requires at
   least one ACTIVE room with an ACTIVE rate priced > 0 before submission; otherwise the
   supplier is sent back to the rooms screen with a clear message.

### Noted, not changed (by design / out of scope)
- `submit/{id}` still allows `approved → submitted` (re-review). It doesn't flip
  status=0, so an approved listing re-submitted stays status=1 but drops out of search
  (which filters `listing_status='approved'`). Low impact; a dedicated "unpublish"
  action would be the clean model — deferred, not silently assumed away.

---

## Verified

- `php -l` clean on all three touched route files.
- Sellability guard logic-tested (6 cases: active+priced sellable; status 0 / price 0 /
  no rooms / no options blocked; one-of-two sellable).
- The new submit gate sits INSIDE the own-inventory branch — API/marketplace suppliers
  are not affected (no listing_status moderation for them).

## Not verified (honest limit)

No live DB, so the end-to-end gate (crafted POST of a draft hotel id → refused) and the
sellability block are static + logic-verified only. First real confirmation needs a DB:
submit an empty draft (blocked), approve a listing (goes live + sellable), and attempt a
direct booking-submit POST against a non-approved id (refused on both web + API) while an
API-supplier booking still succeeds.
