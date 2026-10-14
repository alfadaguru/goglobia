# White-Label Rich Site — Design of Record (Phases 2b–4)

> Build-ready data-model + render design for the remaining white-label phases. Every schema
> claim here was **verified against real source** (install/db.sql + the reading/writing code),
> including correcting two inferred-but-wrong claims from the research pass (noted inline).
> Companion to `WHITE-LABEL-GAP.md` (the gap analysis) and the shipped Phases 0/1/2a.
>
> Shipped so far: P0 approval gate (`0ba7fc3`), P1 tenant chrome (`a3da03d`), P2a host-aware
> clean URLs + grandfather backfill (`10d8bb3`).

---

## Guiding principle (verified)

Most of a rich hotel site is **data that already exists and simply isn't rendered**. The
money path is host-relative and safe on a tenant/custom domain (verified: `paymentReturnUrl`
host-match + payment-gateway.php builds from `root`). So the remaining work is overwhelmingly
**presentation + a few net-new columns + a multi-language read path** — not new infrastructure.

Reuse helpers that already exist; do not reinvent:
- Reviews: `review_list_for_org($db,$stayIds,$status,$limit)`, `review_rollup`, `review_set_status`,
  `review_set_reply` — `app/lib/supplier_reviews.php` (verified signatures, lines 61/161/180/201).
- Packages: `package_list($db,$stayIds,$limit=300)` — `app/lib/supplier_safety.php:39` (verified; the
  default limit is **300**, not 100 as the research draft said), plus `pkg_items_for`/`pkg_components_total`
  in `supplier_packages_ext.php`.
- Rooms: `stays_room_option_ids($db,$roomId,$stayId)` (stable option ids) — `app/lib/stays_inventory.php`.
- Theme: `sitebuilder_decode`/`sitebuilder_normalize` — `app/lib/supplier_sitebuilder.php`.
- Tenant chrome + URLs: `wl_is_tenant`/`wl_tenant`/`wl_home_url`/`wl_page_url` — `supplier_site_host.php` (P1/P2a).

---

## Verified data inventory (what EXISTS vs NET-NEW)

### Rooms — `stays_rooms` (install/db.sql)
EXISTS: `room_name`, `room_images` (JSON `[{url,default,thumb?}]`), `amenities` (JSON id array),
`room_options` (JSON), `status`. `room_options` keys VERIFIED from the writer
(`supplierStaysRoutes.php:1070-1090`): `option_id, max_adults, max_children, price,
discount_percentage, extra_bed_available, extra_bed_charge, breakfast_included,
cancellation_free, available_quantity, status, board_id`.
NET-NEW (confirmed absent): `description` TEXT, `size_sqm` DECIMAL(7,2), `bed_config` JSON,
`view_type` VARCHAR(40), `translations` LONGTEXT.

### Reviews — `stays_reviews` (VERIFIED columns + index)
EXISTS, fully modelled, **not rendered**: `rating` tinyint, `comment` text, `status`
enum(pending/published/hidden), `owner_reply` + `owner_reply_at`, `guest_name`, `created_at`,
`UNIQUE(stay_id,invoice_id)`, `KEY idx_stay_status(stay_id,status)`. Aggregates `stays.rating`
+ `stays.rating_count` maintained by `review_rollup` on status change. NET-NEW: only a theme
toggle `show_reviews` (no schema).

### Offers — `stays_packages` (VERIFIED)
EXISTS: `name`, `description` varchar(500), `inclusions` varchar(1000) (plain text, not JSON),
`price`, `nights`, `active`. NET-NEW (optional): `image_url` (none today); gate via `active` +
a theme toggle `show_packages`.

### Gallery — `stays.img` / `stays_rooms.room_images`
EXISTS JSON shape VERIFIED from the writer (`supplierStaysRoutes.php:222,227`):
`{url, thumb, default}`. **CORRECTION:** there is **no `caption`** key (research draft claimed
one — wrong). So image captions/alt-beyond-name are **net-new** if wanted (prefer adding a
`caption` key to the existing JSON on write; no column needed).

### Map — `stays.location_coords`
EXISTS: `varchar(255)` holding `'lat,lng'` (written at `supplierStaysRoutes.php` from
`latitude`/`longitude`). Enough for a client-side Leaflet/OSM map (no API key). NET-NEW: none;
render-only + a theme toggle `show_map`.

### Multi-language — `stays.translations` (VERIFIED shape, unused on read)
EXISTS: `longtext` JSON, shape VERIFIED from the admin writer
(`admin/staysRoutes.php:174-205`): **`{ lang_code: { field: value } }`** with fields
`name, desc, address, cancellation_policy, privacy_policy`. **Written by admin, never read
anywhere.** NET-NEW: a read path + supplier edit UI + `stays_rooms.translations` (same shape)
for room-level text.

### Theme — `stays_site.theme` via `sitebuilder_decode` (VERIFIED keys)
EXISTS: `accent, tagline, banner, banner_on, about, meta_title, meta_desc, analytics_id, social`.
NET-NEW keys (pure presentation, same validation style — hex regex / url regex / length caps /
whitelist): `favicon_url, hero_image_url, font_family, header_bg/text, footer_bg/text,
show_reviews, show_packages, show_map, default_language, nav_style`.

---

## Net-new DDL (idempotent self-heal + install/db.sql parity)

```sql
ALTER TABLE `stays_rooms`
  ADD COLUMN `description`  TEXT DEFAULT NULL,
  ADD COLUMN `size_sqm`     DECIMAL(7,2) DEFAULT NULL,
  ADD COLUMN `bed_config`   LONGTEXT DEFAULT NULL,   -- JSON {"beds":[{type,qty,size}]}; LONGTEXT (not JSON type) to match this codebase's convention + MariaDB compat
  ADD COLUMN `view_type`    VARCHAR(40) DEFAULT NULL,
  ADD COLUMN `translations` LONGTEXT DEFAULT NULL;   -- {lang:{description,view_label}}
```
`stays.translations` already exists — only a read path + supplier write are needed. No new tables.
`bed_config`/`translations` use `LONGTEXT` (not MySQL `JSON` type) to match every other JSON
column in this schema (`room_options`, `img`, `theme` are all `longtext`) and avoid MariaDB JSON
quirks. Validate/encode in PHP.

`bed_config` shape: `{"beds":[{"type":"queen","qty":1,"size":"160x200"}]}` — `type` ∈
single|double|queen|king|bunk|sofa_bed; `qty`≥1; `size` free text (optional).
`view_type` ∈ street|garden|sea|city|courtyard|pool|mountain|none (open varchar; validate in code).

---

## Phase plan (each: implement → php -l → runtime-verify → commit)

### Phase 2b — render the rich data (biggest visible win; mostly presentation)
1. **Rooms**: add the 4 net-new columns (self-heal + db.sql); supplier room form gets
   description / size / bed-builder / view inputs; branded-preview renders photos (room_images),
   description, specs (size · beds · view), per-room amenities.
2. **Reviews**: a Reviews section on the branded home driven by `review_list_for_org(published)`
   + the `stays.rating`/`rating_count` aggregate; gated by theme `show_reviews`. No schema.
3. **Offers**: a section from `package_list($db,[stayId])` (active only), gated by `show_packages`.
4. **Gallery**: lightbox grid over `stays.img`; optional `caption` added to the JSON on write.
5. **Map**: client-side Leaflet/OSM from `location_coords` (no key), gated by `show_map`.
6. **Theme extensions**: add the net-new keys to `sitebuilder_decode`/`normalize` + the Site
   editor form (favicon/hero/fonts/colors/toggles). Wire favicon + fonts + header/footer colors
   into the tenant chrome from P1.

### Phase 3 — book on-site in tenant chrome
Reuse the existing engine unchanged (hold + server price + payment-gateway, all host-relative —
verified). Only wrap the booking render in tenant chrome when `wl_is_tenant()`. Options:
(a) a tenant checkout route that calls the same hold/price/payment; (b) render the existing
`/stay` flow with tenant header/footer on a tenant host. (a) is cleaner/safer (~200 lines, no
money-path edits). External-domain caveat: session is host-scoped (correct) — document that the
whole guest journey stays on that host.

### Phase 4 — multi-language content
Read path `wl_stay_field($stay,$field,$lang,$fallback='en')` over `stays.translations`
(+ `stays_rooms.translations`); active language from `?lang=` → session → theme
`default_language` → 'en'. Supplier translation editor (admin form already writes the same JSON
shape — reuse it; supplier form must read-merge-write so it doesn't clobber admin translations).
RTL (`dir="rtl"`) for ar/he/fa. A language switcher on the tenant site.

---

## Risks / honesty
- Net-new room columns need the `_supplier_stays_col_exists()` write-guard pattern (un-migrated
  installs must not fatal) + install/db.sql parity + the self-heal ALTER.
- Reviews are **moderation-gated**: only `published` show; suppliers/admins publish. A property
  with zero published reviews shows an empty state, not fake ratings.
- `inclusions` is plain text — render with `nl2br(htmlspecialchars())`, not as structured items.
- Leaflet/OSM: free, no key, MIT — but must be loaded self-hosted or via allowed CDN; respect
  Nominatim usage limits if adding geocoding to the supplier form.
- Multi-language is the only sizable net-new effort; everything else leans on existing data.
- All supplier-authored text stays **escaped on output** (the codebase's manual-escaping rule) —
  no raw HTML from suppliers, ever.
