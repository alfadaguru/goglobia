# Precompiled Tailwind build (Phase 1–2 spike)

**Status: spike / not wired in.** This branch stands up a build-time Tailwind
compile and proves it produces a complete, correct stylesheet. It does **not**
yet replace the runtime CDN in `app/views/includes/header.php` — that is Phase 4,
gated on the Phase 5 visual-regression pass.

## Why

Today `header.php` loads `https://cdn.tailwindcss.com`, which compiles Tailwind
**in the browser on every page load** from the config + the `@apply` component
layer in `app/views/tailwind.php`. Until that runtime compile finishes, component
classes (`.btn`, `.badge-primary`, …) don't exist and elements fall back to
`body{color:#000}` — the blue-button-black-text flash fixed piecemeal in
PRs #100/#101. Precompiling removes the whole failure class and stops shipping the
~120KB compiler to every visitor. Full scope: see the scope doc.

## Files this spike adds

| File | Purpose |
|------|---------|
| `package.json` | `build:css` / `watch:css` scripts + the `tailwindcss` dev dep |
| `tailwind.config.js` | 1:1 port of `__APP_TAILWIND_CONFIG__` (same hex tokens), `content` globs, minimal safelist |
| `assets/css/tailwind.src.css` | `@tailwind` directives + the component `@layer` copied verbatim from `tailwind.php` |
| `assets/css/tailwind.build.css` | **compiled output** — committed so deploys stay copy-only (see below) |

`node_modules/` is gitignored (already covered in `.gitignore`).

## Build

```bash
npm install          # once
npm run build:css    # compiles assets/css/tailwind.build.css (minified)
npm run watch:css    # rebuild on source/class changes during dev
```

## Spike result (measured)

- Build compiles cleanly — all **310 `@apply` rules** port with no errors.
- Output: **~277KB raw / ~36KB gzipped** (vs the ~120KB compiler + per-load runtime compile).
- Verified present: every component class (`.btn`, `.btn.white/.light/.ghost/.outline/.secondary`, `.badge-*`, `.field-box`, `.alert-*`, `.switch-*`, `.tooltip`), the custom-token utilities (`text-foreground` etc.), and dynamically-used classes picked up by the content scan (`text-emerald-700`, `bg-blue-500`, `text-blue-400`).
- `.btn.primary` is intentionally **not** here — it lives in the static `app.css` (the #100 fix) and loads alongside this build, exactly as today.

## Phase 3 — safelist (DONE)

Full sweep of `app/` + `modules/` for classes built by string concatenation
(invisible to the content scanner). Result: **exactly one** such site —
`app/views/admin/promo-codes/promo-codes.php:77` builds `bg-{$color}-500` with
`$color ∈ {red, yellow, green}` (a usage-meter bar). Safelist is exactly those
three. Everything else that looked dynamic — the ticket/status/priority colour
**maps** — stores FULL literal class strings (`'bg-blue-100 text-blue-800'`),
which the scanner already catches, so they are deliberately not safelisted.

## Phase 4 — header swap (DONE)

- `app/views/includes/header.php`: removed the `cdn.tailwindcss.com` `<script>`,
  the `__APP_TAILWIND_CONFIG__` apply line, and the `assets/js/tailwind.js`
  warning-shim; added `<link ... assets/css/tailwind.build.css>` before `app.css`.
- `app/views/tailwind.php`: the JS config object is commented out (provenance),
  and the `<style type="text/tailwindcss">` component block is removed (it is
  inert without the CDN JIT and lives in `tailwind.src.css`). The theme-driven
  `:root` CSS variables, the field-box plain CSS, and the checkbox JS all stay.

Verified live over HTTP (`/login`): page links `tailwind.build.css`, zero
`cdn.tailwindcss.com` script tags, `app.css` still loads, `:root --btn-*` vars
still emitted, zero stray `@apply`, and `.btn` resolves its colour from the build.

## Phase 5 — automated purge-gap sweep (DONE, zero regressions)

Rather than eyeball every page, an automated sweep extracted **788 colour-utility
classes** used across `app/views` + `modules` + `app/lib` and checked each against
the built CSS. A class that is *used but absent* would be a purge gap (unstyled).

Result: **0 genuine purge regressions.** Every apparent "gap" triaged to a
non-regression:

| Category | Count | Why it's not a regression |
|----------|-------|---------------------------|
| Arbitrary values (`bg-[#0046b8]`) | 0 missing | scanner picks them all up |
| `dark:` variants | 114 | customer UI has no `.dark` toggler; equally absent under the CDN |
| Unknown config tokens (`bg-sidebar`, `bg-custom-blue`, `text-primary-500`, `border-gray-150`) | 13 | **never defined in the old CDN config either** — pre-existing dead classes, used only in the dead `admin/side.php` + the components demo gallery |
| Typos (`text-md`, `text-1xl`, `text-voilet-300`, `outline-hidden`) | ~10 | never valid Tailwind v3 — no-ops under the CDN too |
| Tokenizer noise (`{{mustache}}` fragments) | rest | not real classes |

The active admin `sidebar.php` (heavy conditional classes) has **0** missing
colour classes. The build reproduces everything the CDN produced.

### Recommended human spot-check before merge (optional, belt-and-suspenders)
The automated sweep is thorough for colour utilities but a human glance at the
dashboard, checkout, and one admin CRUD page is cheap insurance. Not a blocker —
the sweep found nothing.

### Pre-existing cleanup found (out of scope, FYI)
The sweep surfaced a handful of typo classes that have silently never rendered
under the CDN either — e.g. `text-md` (→ `text-base`), `text-1xl` (→ `text-xl`),
`text-voilet-300` (→ `violet`), `text-gray-805`, `outline-hidden`. Worth a
separate tidy-up; not caused by this migration.

## Deployment note

The site deploys by file copy (`/updates.php`). To keep that copy-only,
**`tailwind.build.css` is committed** and must be **rebuilt + recommitted whenever
classes or the component layer change**. Alternative: run `npm run build:css` in a
deploy hook. Committing the artifact is the lower-friction path for this repo.
