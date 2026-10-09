# 05 — Other services (bus, visa, eSIM, ferries, rail, insurance)

> **Status:** drafted from schema audit. Code claims cite `file:line`. Read
> [`00-supplier-platform.md`](00-supplier-platform.md) first. **Honesty note:** not
> every service has an own-inventory table today — so not every service is
> "supplier-able" without first building an inventory model. This chapter states, per
> service, what actually exists.

## Audit of own-inventory readiness

| Service | Own-inventory table? | Owner column? | Supplier-able today? |
|---|---|---|---|
| **Bus** | ✅ `bus` (`install/db.sql:1316`) | ✅ `user_id` | **Yes** — genuine own-inventory with owner, like stays/tours/cars |
| **Visa** | ✅ `visa` (`install/db.sql:29318`) | ❌ none | Partially — it's an **admin catalogue** (from_country→to_country, requirements, prices), not owner-scoped |
| **eSIM** | ❌ (integration only, `modules/esim/airalo`) | — | No — sold via integration; server-authoritative pricing from the Airalo feed |
| **Ferries** | ❌ (integration only, `modules/ferries`) | — | No — integration only |
| **Rail** | ❌ (integration only, `modules/rail`) | — | No — integration only |
| **Insurance** | ❌ (integration only, `modules/insurance/airhelp`) | — | No — integration only |

## 1. Bus — a real supplier service

`bus` has `user_id`, `name`, `slug`, `operator`, `bus_type`, `amenity_ids`,
`boarding_points`/`dropping_points` (JSON), `routes`, `discount`, `refundable`,
`cancellation_policy`, gallery, `status` (`install/db.sql:1316`). It's structurally
like cars (route/point-based). A bus operator supplier manages: fleets/coaches,
routes with boarding/dropping points, schedules, **seat/capacity per departure**
(same availability gap — needs real inventory + holds), fares, amenities, policies,
and a reservations inbox. Approval/quota/payout exactly as `00`. **Bus should be
grouped with stays/flights/tours/cars as a first-class supplier service.**

## 2. Visa — admin catalogue, not a supplier marketplace

`visa` is a platform catalogue (country-pair visa products with requirements and
prices), no owner column. If a "visa supplier" (an agency processing visas) is desired,
that's a **new build**: add an owner model + an application-processing workflow
(the app already has visa booking + document upload + a verify flow in
`app/routes/admin/visaRoutes.php`). Treat visa-supplier as a **later, separate design**
— it's a processing/fulfilment role, not a listing role, so it doesn't fit the
stays/bus "list your inventory" pattern cleanly.

## 3. eSIM / ferries / rail / insurance — integration-only

These are sold through supplier *integrations* under `modules/` (e.g. Airalo for eSIM),
with server-authoritative pricing — there is no own-inventory table for a human
supplier to populate. Making them supplier-able would mean **first building an
inventory model** for each (packages, schedules, capacity), which is a large per-service
build with unclear demand. **Recommendation:** keep these integration-only for now;
revisit only if a concrete supplier use-case appears. Do not promise supplier
self-service for them in the UI.

## Summary

- **First-class supplier services** (own inventory + owner, ready to extend): stays,
  flights, tours, cars, **bus**.
- **Special case:** visa (catalogue today; supplier = a separate processing-role build).
- **Integration-only (not supplier-able without new inventory models):** eSIM, ferries,
  rail, insurance.

This is why the signup service-selection (`00` §2) should offer the first-class set by
default, and the platform should not advertise supplier onboarding for the
integration-only services until their inventory models exist.
