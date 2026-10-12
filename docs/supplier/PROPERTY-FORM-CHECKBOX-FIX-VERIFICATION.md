# Property form rework + checkbox fix — verification note

**Date:** 2026-10-12
**Commit:** `7006031` (rework) — this note records the post-rework verification, no code change.

---

## What was wrong (root cause)

The supplier **Add/Edit Property** form had three real defects, all found by reading
the source, not guessed:

1. **Amenities (and all supplier-stays checkboxes) were un-selectable.**
   `.checkbox-input { @apply sr-only; }` (`assets/css/tailwind.src.css`) **visually
   hides** the native checkbox. That class is only meant to be used *with* a visible
   sibling `<span class="checkbox-custom">` that draws the box (the login/signup
   pattern). Every supplier-stays checkbox used `.checkbox-input` **bare**, so the box
   was hidden with nothing drawn in its place — the amenity names rendered as
   un-clickable label text. This is why the list looked "just there, not selective."

2. **Thin / possibly-empty amenity list.** The picker hides entirely when the
   `stay_amenity` list is empty (likely the cause of "no amenities showing" on a live
   install), and the shipped list was only 10 items.

3. **Photos underspecified.** One generic "Photos" field — no logo field, no guidance
   on kind/size, and **no server-side resize** anywhere (`secureUploadCheck` only
   validates type/size/content).

---

## The fix (shipped in `7006031`)

- **CSS:** the `sr-only` hide now applies **only** when a `.checkbox-custom` sibling
  exists (`.checkbox-input:has(+ .checkbox-custom)`). A **standalone** `.checkbox-input`
  (`:not(:has(+ .checkbox-custom))`) gets a real visible 16px accent-green box. One
  change fixes every supplier-stays checkbox at once. `tailwind.build.css` recompiled.
- **Amenities:** canonical **~40-item** `stay_amenity` list that **self-seeds** when the
  table is empty (`ensureSupplierStaysSchema`), so the picker is never blank.
- **Photos & logo:** new `app/lib/supplier_image.php` (GD downscale to 1600px long edge
  + 400px thumbnail; format-preserving; GIF animation-safe; never fatal — keeps the
  original on any failure). New `hotel_logo` upload + `stays.logo` column
  (ensure-fn + `install/db.sql`). Gallery uploads now store `{url, thumb}`.
- **Form:** rebuilt as a **5-step wizard** (Basics → Location & contact → Policies &
  house rules → Photos & logo → Amenities) with per-step and on-submit validation that
  jumps to the offending step; amenity step has select-all / clear + a live count.

---

## Verification performed

### Image library — runtime-verified (real GD, no DB needed)
- Oversized **3000×2000 JPEG** → downscaled to **1600×1067** + **400×267 thumbnail**. ✅
- **300×200 PNG** → **not upscaled** (stays 300×200). ✅
- **Corrupt file** → graceful fallback (both URLs = original), **no crash**. ✅

### Checkbox fix across all stays views — compiled-CSS proof (deterministic)
Every checkbox in these views uses **bare `.checkbox-input` with no `.checkbox-custom`
sibling**, so the standalone (visible) rule applies and the `sr-only` hide never does:

| View | Checkboxes | Result |
|---|---|---|
| `form.php` | Refundable, 4× house rules, amenities grid | ✅ visible, selectable |
| `rooms.php` | Rate active, Extra bed, Breakfast, Free cancellation, Refundable, Room status | ✅ visible; Alpine `:checked` bindings unaffected (appearance-only change) |
| `calendar.php` | "Closed" per date (can be `disabled`) | ✅ visible when editable; **disabled state preserved** (native grey-out — my rule doesn't touch `:disabled`) |
| `rate-rules.php` | Closed-to-arrival | ✅ visible |
| `site.php` | Show promotion banner | ✅ visible |

Decisive evidence in `assets/css/tailwind.build.css`:
- The `sr-only` rule is **guarded**: `.checkbox-input:has(+.checkbox-custom){position:absolute;width:1px;…}`
  — only hides a checkbox that has a `.checkbox-custom` sibling.
- **No unguarded `.checkbox-input{…}` rule remains**, so standalone boxes are never hidden.
- The login/signup `.checkbox-custom` pattern is untouched (its `:checked +
  .checkbox-custom` rules still match).

### Static checks
- `php -l` clean on all touched PHP (`supplier_image.php`, `functions.php`, `config.php`,
  `supplierStaysRoutes.php`, `form.php`).
- Tailwind build succeeded; both `:has` rules + all JS-toggled classes present in output.
- No duplicate function definitions (`supplier_image_process`, `_supplier_stays_handle_logo`).

---

## Not yet verified (honest limit)

- **No live-DB run.** MySQL isn't available in the build sandbox, so the amenity
  self-seed, `stays.logo` column creation, and the full add/edit save round-trip are
  **static-verified only**. First real confirmation is opening **Add Property** as a
  supplier on the server: expect the 5 steps, a visible logo upload, and a populated,
  tickable amenities grid.
- **No pixel screenshot** of the rendered checkboxes (no headless browser in the
  sandbox). The checkbox proof is CSS-rule determinism against the compiled stylesheet.
  A manual harness that loads the real compiled CSS + exact markup was used to
  eyeball-confirm; it lives outside the repo (scratchpad).

### `:has()` support note
The fix relies on CSS `:has()` — baseline across current browsers (Chrome/Edge 105+,
Safari 15.4+, Firefox 121+; widely available since Dec 2023). Safe for an internal
admin/supplier tool. On a pre-2024 browser the only effect would be a cosmetic double
box on login/signup (never a functional failure on the supplier forms).
