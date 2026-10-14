# White-Label Templates — Architecture (5 standalone themes + configurator)

> The redesign that makes a hotel's site look like THEIR site, not a themed platform page.
> Owner brief: "a five-star hotel can use our system and make it their website without
> anyone knowing they are using us." Mixed set of 5 templates, rich configurator, local
> Playwright proof. Built in verified increments — not rushed.

## The core change: a STANDALONE layout (no platform header/footer)

Today the branded page is `includes/header.php` + body + `includes/footer.php` — the platform
shell. That is the root reason it reads as "a platform page in a costume." The template system
replaces that entirely with a **self-contained document** owned by the template:

```
app/views/supplier/stays/site/
  _layout.php         # <!doctype> … <head> (assets, SEO, favicon, theme CSS vars, fonts) …
                      # renders the chosen template's header + sections + footer. NO platform include.
  templates/
    grand.php         # 1 — classic luxury (serif display, full-bleed hero)
    minimal.php       # 2 — quiet luxury (whitespace, restraint)
    resort.php        # 3 — warm/coastal resort
    modern.php        # 4 — conversion-forward (hero search widget, price cards)
    urban.php         # 5 — sleek city/business hotel
  sections/           # shared section partials the templates compose (each reads the same data)
    nav.php hero.php rooms.php gallery.php offers.php reviews.php about.php location.php
    contact.php footer.php search.php lang.php
```

Each template file defines its **own header markup, hero, section order, and footer** and pulls
shared section partials where useful. The layout provides the document skeleton + the theme CSS
variables so all templates are driven by the same config.

### Rendering contract
`_branded_render_stay()` (and the tenant/custom-page routes) stop including the platform
header/footer for white-label and instead `require _layout.php`, passing the already-prepared
data ($stay, $rooms, $roomTypeNames, $roomAmenityNames, $site, $sitePages, $theme, $wlLang).
`_layout.php` picks `templates/{theme.template}.php`. Assets reused: `assets/css/tailwind.build.css`
(compiled — all classes must exist there), Material Symbols, Alpine (same sources the platform uses).

## Config model (extends stays_site.theme JSON — no new tables)

New theme keys (validated in sitebuilder_decode/normalize, same style as existing):
- `template` enum: grand|minimal|resort|modern|urban (default 'grand')
- `font_display`, `font_body`: a safe allow-list of font stacks (system + a few Google families)
- `color_primary`, `color_accent` (hex) — drive CSS vars `--wl-primary/--wl-accent` etc.
- `hero_headline`, `hero_subtext`, `hero_cta` (text) — hero copy override
- `sections`: ordered list of enabled section keys (reorder + toggle), e.g.
  ["hero","search","rooms","offers","gallery","reviews","about","location","contact"]
- `nav_labels`: optional per-section label overrides
- (already have: accent, logo via stays.logo, favicon_url, hero_url, show_* toggles, social,
  meta_*, default_language — these fold into the new model)

`stays_rooms`/`stays.translations` unchanged (Phase 2b/4 already added what's needed).

## Configurator (supplier Site editor)

A **template picker** (5 preview thumbnails) + a config panel:
branding (logo/favicon) · colors · fonts · hero (image+headline+subtext+CTA) ·
sections (drag-reorder + show/hide) · nav labels · pages · social · languages.
Posts to the existing `/supplier/stays/site-theme` (extended) — all server-validated.
A "Preview" opens the live template with the saved config.

## Playwright proof (local, owner-run)

`tools/wl-screenshots/` — a tiny harness:
- `render.php` (CLI): renders each template to a static HTML file using sample hotel data
  (so no DB/login needed) into `tools/wl-screenshots/out/`.
- `shots.mjs`: Playwright opens each HTML at 390px / 834px / 1440px and writes PNGs.
- `npm run wl:screenshots` → `tools/wl-screenshots/out/*.png` for the owner to review.
This gives real visual evidence without me claiming a live test I can't run here.

## Build increments (each: implement → php -l → runtime-verify → Playwright shots → commit)

1. **Layout skeleton + theme CSS vars** — `_layout.php` (standalone <head>/doctype, loads assets,
   emits `--wl-*` vars from config) + extend sitebuilder (template/fonts/colors/hero/sections).
   Wire `_branded_render_stay` to use it. One first template (grand) so it renders end-to-end.
2. **Shared section partials** — nav/hero/rooms/gallery/offers/reviews/about/location/contact/
   footer/search/lang, each reading the prepared data, escaped.
3. **Remaining 4 templates** — minimal, resort, modern, urban (distinct headers/heroes/order).
4. **Playwright harness** — render.php + shots.mjs + npm script; produce screenshots.
5. **Configurator UI** — template picker + full config panel in the Site editor.
6. **Polish pass** — responsive/RTL/a11y/contrast review against the screenshots; fix.

## Non-negotiables
- No platform chrome leaks on a template (no `includes/header.php`/`footer.php`).
- Every class used must exist in the precompiled Tailwind build (verify; no arbitrary `[..]`).
- All supplier text escaped on output.
- The approval gate + host routing + on-site booking (Phases 0–4) stay intact — templates are a
  presentation layer over the same data + engine.
- Honest verification: Playwright is the evidence; I won't claim a live render I didn't capture.
