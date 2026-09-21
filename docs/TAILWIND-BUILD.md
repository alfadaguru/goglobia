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

## What is NOT done (remaining phases)

- **Phase 3** — finish the safelist sweep for string-built classes (seed only so far; one confirmed builder: `app/views/admin/promo-codes/promo-codes.php:77`).
- **Phase 4** — swap the CDN `<script>` in `header.php` for `<link ... tailwind.build.css>`. The theme-driven `:root` CSS-var block in `tailwind.php` stays.
- **Phase 5** — visual-regression pass across the surface (a bad purge shows as *missing styling*, not an error).

## Deployment note

The site deploys by file copy (`/updates.php`). To keep that copy-only,
**`tailwind.build.css` is committed** and must be **rebuilt + recommitted whenever
classes or the component layer change**. Alternative: run `npm run build:css` in a
deploy hook. Committing the artifact is the lower-friction path for this repo.
