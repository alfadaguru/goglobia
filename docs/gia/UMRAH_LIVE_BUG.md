# Umrah live-chat "0 cards" bug — investigation (2026-10-22)

## PROVEN FACTS (from the LIVE server, not assumptions)
- Live /api/gia/chat response for "umrah": search runs, module=umrah,
  destination=any, duration=any, subtitle "All package locations (Kano)·22-10-2026",
  BUT "cards":[]  (EMPTY). Captured via Playwright network intercept.
- Website /umrah shows 2 Kano departures, ₦2,450,000, bookable.
- Website /umrah renders from umrahV2PublishedDepartures($db) DIRECTLY
  (umrahV2Routes.php:63 + results.php:22 uses $d['price_min']). => that function
  RETURNS the 2 departures with real prices. No separate price path.
- GIA path = aiSearchService.buildUmrahSearch -> attachLocalCards('umrah') ->
  umrahListingCards (app/lib/umrah/listing.php) -> umrahV2PublishedDepartures.
- Both call sites call the IDENTICAL umrahV2PublishedDepartures($db) with the main $db.

## KEY REALIZATION (my earlier error)
"Website works" NEVER proved GIA's umrahListingCards works — they are DIFFERENT
functions. The website uses umrahV2PublishedDepartures directly; GIA uses
umrahListingCards (which wraps it). I conflated them for days. That was the mistake.

## VERIFIED IN REPO (HEAD b096a3c / listing.php at 756e330+dc52fb0)
- HEAD listing.php is v2: 5 umrahV2PublishedDepartures refs, 0 select('umrah') dead-table reads.
- umrah_price_resolve/umrah_b2c_unit_price/umrah_capacity_for all in services.php, loaded unconditionally config.php:171.
- umrahListingCards logic + card build verified correct via local mock test (returns 2 cards for the live params).
- umrahV2PublishedDepartures has NO session/request/global dep; pure DB reads.

## CONCLUSION: code-reading exhausted. With identical code + same $db, cards:[] in GIA
but data on website is IMPOSSIBLE unless:
  (A) DEPLOYED app/lib/umrah/listing.php is OLDER than dc52fb0 (still v1, reads the now-EMPTY
      dead `umrah` table -> []). A per-file DEPLOY GAP. <-- most likely.
  (B) A live DB/runtime condition (e.g. umrah_price_resolve unit<=0 dropping tiers at line 358)
      that the website tolerates differently. UNLIKELY given website uses the SAME fn.

## DIAGNOSTIC ADDED (uncommitted) in listing.php:
  Logs to error_log: "[umrahListingCards] DIAG: ..." — distinguishes threw / empty /
  raw published_departures & active_tiers counts. On next "book umrah", the server
  error_log names the exact cause.

## NEEDED TO FINISH (owner-only, I cannot see):
  1. Confirm deployed commit of app/lib/umrah/listing.php (is it >= dc52fb0?). If not -> redeploy that file.
  2. OR the "[umrahListingCards] DIAG:" error_log line after a live "book umrah".
  3. OR start local MySQL so I query umrah_departures/tiers + run umrahListingCards on real data.
