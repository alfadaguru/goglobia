# Nigerian domestic airline code→name mapping (Adivaha)

**Date:** 2026-11-11. **Method:** 16 live Adivaha NG-domestic searches (local, real API, NGN)
across 13 routes + per-code research against authoritative sources (IATA, Flightradar24,
airportia, Airhex, CAPA, Wikipedia). Every row is backed by evidence — no guessing.

## The bug (proven)

Adivaha returns a carrier `code` (`img`) + an `airline` NAME, but for several Nigerian
carriers **the name Adivaha sends is a DEFUNCT/foreign airline** that historically held that
IATA code. Our `flights_airlines` seed is also stale/wrong for these codes, and the lookup
route (`globalRoutes.php:149`) queries only the `code` column. Net: NG domestic results show
wrong airline names (e.g. "Transaero", "Cimber Air") or raw codes ("VK").

Across 16 searches, EXACTLY FOUR carrier codes appear in NG domestic on Adivaha today:

| Code | Adivaha's NAME (wrong) | seen | flight-no prefix | VERIFIED correct airline | ICAO | source |
|------|------------------------|------|------------------|--------------------------|------|--------|
| `P4` | Air Peace              | 307x | `P47xxx`         | **Air Peace** (correct)  | APK  | IATA; iata-codes.com |
| `UN` | Transaero (defunct RU) | 242x | `UN5xx`          | **United Nigeria Airlines** | NUA | FR24 (UN505/UN501=NUA); IATA members |
| `QI` | Cimber Air (defunct DK)| 57x  | `QI5xx`          | **Ibom Air**             | IAN  | airportia QI521/523; Airhex |
| `VK` | VK (raw code)          | 45x  | `W135xx`         | **ValueJet**             | FVJ  | Airhex; CAPA; Wikipedia |

Note `VK`: the W1 "World Ticket" ticketing-shell is already corrected to operating carrier
`VK` by the existing shell rule — `VK` IS ValueJet's real IATA code (Airhex/CAPA). It only
needs a NAME. Flight numbers `W135xx` are ValueJet's operating designator.

## Full current NG carrier reference (for a complete override, beyond the 4 seen today)

| Code | Airline | ICAO | Status (2026) | source |
|------|---------|------|---------------|--------|
| `P4` | Air Peace | APK | operating | IATA |
| `UN` | United Nigeria Airlines | NUA | operating | IATA members list |
| `QI` | Ibom Air | IAN | operating | Airhex |
| `VK` | ValueJet | FVJ | operating | Airhex/CAPA |
| `W3` | Arik Air | ARA | operating | Airhex/CAPA |
| `N2` | Aero Contractors | NIG | operating | Airhex |
| `OF` | Overland Airways | OLA | operating | WebSearch (routesonline) |
| `Q9` | Green Africa Airways | GWG | operating | Wikipedia |
| `R4` | Rano Air | RAN | operating | Airhex |
| `VM` | Max Air | NGL | grounded | WebSearch |
| `9J` | Dana Air | DAN | suspended 2024 | Wikipedia |
| `ZU` | Azman Air | AZM | suspended 2024 | Wikipedia |

## THE "A.P.G" / "APG Airline" CASE — fully traced (2nd sweep, raw log evidence)

A 2nd sweep (6 dates × smaller-city routes: PHC->ABV, LOS->BNI, ABV->KAN, KAN->ABV)
surfaced the shell the 1st sweep missed. RAW Adivaha offer meta (from error_log):

    "meta":{"code":"A1","airline":"APG Airline","flight_number":"A16516",
            "origin":"ABV","destination":"KAN", ... "legs":[],
            "segments":[{ ... "operated_by":"UN" ... }]}

**What A1 / "APG Airline" actually is (sourced):** `A1` is NOT an airline. It is **APG Airlines**
(Air Promotion Group) — a GDS interline/BSP ticketing shell ("A1-to-Z1" / GP-275). Nigerian
carriers not on GDS are DISPLAYED under A1 flight numbers and ticketed under APG's code.
CAPA: "APG Network welcomes **United Nigeria** as a new codeshare partner"; "**Ibom Air** was
brought into APG IET." So an `A1` offer's REAL operator is whatever `operated_by` says
(verified live: `operated_by:"UN"` = United Nigeria). The `A16xxx` flight numbers are APG's
GDS display numbers, not the operator's.

**Why our correction misses it (code bug, not missing data):** `search.php` reads the operating
carrier from `$meta['segments'][].operated_by`, but builds its leg list from `legs` which on
these offers is `[]` (empty). Adivaha DOES provide `operated_by:"UN"` on the SEGMENT — we just
don't reliably read it when `legs` is empty. So "APG Airline" leaks to the customer instead of
"United Nigeria". The shell-rule DB row (`flight_shell_carriers` adivaha/A1/Nigeria) is correct
in intent but the operating-carrier extraction needs to use `segments[].operated_by` even when
`legs` is empty.

Live NG-domestic carrier set is therefore FIVE shells/codes, not four:
P4 (Air Peace, ok), UN (->United Nigeria), QI (->Ibom Air), VK (->ValueJet),
**A1 (APG shell -> resolve via operated_by, falls to United Nigeria/Ibom on NG)**.

## RAW PROBE — standing re-verification tool (run it anytime, 5x/day)

Built `scratchpad/ng_air_probe.php` — a STANDALONE CLI probe (does NOT boot config.php;
loads only Medoo + .env + adivaha/creds.php) that hits Adivaha's RAW /flights/search +
/flights/search-poll and appends one JSONL row per offer to `scratchpad/ng_air_evidence.jsonl`
with the UNTRUNCATED `operated_by` (which the normalizer's shell-correction otherwise hides and
the error_log truncates at 2000 chars). Requires local MySQL up.

    # one route/date:
    php scratchpad/ng_air_probe.php ABV LOS 2026-12-05
    # then aggregate:
    scratchpad/ng_air_summary.sh      # -> code | adivaha-name | operated_by  (+counts)

## DEFINITIVE CORPUS — 842 offers, 19 route×date combos over 2+ months (2026-11-11)

Saved to `docs/research/ng-air-evidence-842.jsonl`. Aggregated result — the WHOLE NG-domestic
carrier picture, certain, no truncation:

| Adivaha code | name Adivaha shows (WRONG) | operated_by (TRUTH) | offers | real airline |
|------|----------------------------|---------------------|--------|--------------|
| `P4` | Air Peace (correct)        | P4 | 278 | Air Peace |
| `UN` | **Transaero** (defunct RU) | UN | 375 | **United Nigeria Airlines** |
| `QI` | **Cimber Air** (defunct DK)| QI | 145 | **Ibom Air** |
| `W1` | **W1** (raw shell code)    | VK | 38  | **ValueJet** |
| `A1` | **APG Airline** (shell)    | UN | 6   | **United Nigeria** (this corpus) |

FOUR facts that make the fix safe + certain:
1. `operated_by` is NEVER empty (0/842) — Adivaha ALWAYS gives the true operating carrier.
2. The real operating carriers are EXACTLY 4 codes: P4, UN, QI, VK. That is the whole set.
3. A1 (APG shell) resolved to UN in all 6 samples — but it IS a shell; APG also carries Ibom
   per CAPA, so the fix MUST read operated_by dynamically, NEVER hardcode A1=UN.
4. W1 (World-Ticket shell) → operated_by VK. Same rule: resolve to operated_by, don't show the shell.

=> The correct carrier for display/ticketing is ALWAYS `operated_by`, mapped by this 4-row table:
   P4=Air Peace, UN=United Nigeria Airlines, QI=Ibom Air, VK=ValueJet  (Nigeria-scoped).

## Fix direction (decided, not yet implemented)
The wrong NAME comes from Adivaha itself for UN/QI and from the stale `flights_airlines` seed.
So the correct fix is a **Nigeria-aware authoritative override** keyed on IATA code, applied where
the name is resolved, that wins over both Adivaha's name and the stale DB row — scoped so it can't
mislabel a non-NG carrier that shares a code. Seed the correct rows and ensure lookup matches the
code that is actually sent (normalize dots/case; match code OR iata).

Sources: IATA members list (United Nigeria); Flightradar24 UN505/UN501 (NUA); airportia/flightmapper
QI521/QI523 (Ibom Air, IAN); Airhex + CAPA + Wikipedia (ValueJet VK/FVJ, Arik W3/ARA, Rano R4/RAN,
Aero N2/NIG); Wikipedia List of airlines of Nigeria.
