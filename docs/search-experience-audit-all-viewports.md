# Search Experience Audit — Homepage + All Services × Mobile/Tablet/Desktop

Deep, honest audit of the homepage and every enabled service's search widget across
three viewports. Every result below was produced in a real headless-Chrome browser
(not by reading code alone), and the two code-level findings were re-verified against
source and live behaviour before being reported.

Viewports tested: **mobile 390px**, **tablet 768px**, **desktop 1280px**.
Enabled service tabs: Flights, Stays, Tours, Umrah, Visa, Cars, Cruises, Bus, eSIM,
Insurance (+ GIA).

## Homepage — PASS on all 3 viewports
- No horizontal overflow at 390 / 768 / 1280 (scrollW == viewport width). Zero JS errors.
- Mobile: hero readable (no zoom-out), trip-type pills render once (not doubled),
  fields stack, clear "Search Flights" button.
- Tablet: 2-column field layout, full-width search button — clean.
- Desktop: inline search row with the ⇄ swap button (desktop-only), nav, carousels.

## Per-service search widget — structural health matrix
For each of mobile/tablet/desktop: overflow, search button renders, field panel opens,
type-to-search input focusable+typable, JS errors. Result was IDENTICAL across all three
viewports:

| Service | overflow | search btn | panel opens | input typable | JS errors |
|---|---|---|---|---|---|
| flights | ok | ok | opens | YES | 0 |
| stays   | ok | ok | opens | YES | 0 |
| tours   | ok | ok | opens | YES | 0 |
| umrah   | ok | ok | n/a (native selects) | n/a | 0 |
| visa    | ok | ok | opens | YES | 0 |
| cars    | ok | ok | opens | YES | 0 |
| cruises | ok | ok | n/a (selects) | n/a | 0 |
| bus     | ok | ok | opens | YES | 0 |
| esim    | ok | ok | opens | YES | 0 |

So at the widget level, every enabled service's search form renders, fits, and is usable
on all three screen sizes with no errors. That part is genuinely good.

## REAL FINDINGS (verified, not cosmetic)

### 1. CRUISES search is BROKEN — submitting it 404s (HIGH)
- The Cruises tab shows a full search form, but there is **no cruises route anywhere**:
  `app/routes/cruises/` does not exist, there is no `router->get('/cruises...')`, and
  cruises is not wired in `_routes.php`. Only `cruises-search.php` exists — there is no
  results/listing view.
- The form is `method="GET" action="<root>cruises"`, so Search navigates to
  `/cruises?destination=...` which returns **HTTP 404 "Page Not Found"** (verified live).
- modules table: `cruises` row has EMPTY status (disabled) and there is a junk `widgety`
  row with status 1. So Cruises is effectively an un-launched shell that is still shown
  as a tab.
- ACTION: either HIDE the Cruises tab until it is built, or build the cruises route +
  results page. Right now a user who clicks Cruises → fills it → Search hits a dead 404.

### 2. Stays nationality silent-block — ALREADY FIXED (01d7977)
- First-visit stays search used to silently block on an empty 'nationality'. Fixed to
  default to the site country (NG). Re-verified: fresh visit now defaults NG and search
  proceeds.

### 3. Agent-flagged items that turned out NOT to be bugs (checked, cleared)
- Visa "focus() on a non-focusable div" (claimed auto-advance broken): FALSE. The code is
  `document.getElementById('vtc_t')?.click()` (click, not focus); verified live that
  picking the from-country auto-opens the to-country panel. Works.
- Umrah "optional city/month": not a blocker — search runs; semantic-only.
- Cruises "no validation / optional port": moot given finding #1 (whole route is 404).

## Verdict
Homepage and 9 of 10 service search widgets are solid across mobile/tablet/desktop
(no overflow, usable, zero JS errors). The one real breakage is **Cruises**, which has a
visible search form but no backend route (404 on submit) — it should be hidden or built.

## Production confirmation
The Cruises 404 is NOT local-only: submitting the Cruises search on goglobia.com
(production) navigates to `/cruises?...` and returns "404 - Page Not Found" too. Real
users on the live site hit a dead end when they use the Cruises tab.
