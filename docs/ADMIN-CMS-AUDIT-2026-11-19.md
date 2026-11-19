# Admin CMS Pages — End-to-End Audit (2026-11-19)

**Scope:** the admin CMS area —
- `app/routes/admin/cmsRoutes.php` (471): pages list/add/edit GET, **manage POST** (writes page_name/slug/content), **upload-image POST**, translate GET/POST.
- `app/routes/admin/menusRoutes.php` (10 routes): the header/footer MENU builder (add/move/remove/create/update/delete/restore).
- Views: `cms/pages.php` (list), `cms/pages-manage.php` (787, WYSIWYG editor), `cms/translate.php` (162), `cms/menus.php` (997).
- **Public render path** (in scope because CMS content is published): `app/routes/cms/pageRoutes.php` → `app/views/modules/cms/page.php`.

**Method:** lead read both route files + the public render view + the CSP + checked for an existing HTML sanitizer; two auditors read the four admin views. **Every finding re-verified by the lead at the cited file:line.** The thing I most suspected — the WYSIWYG `content` round-trip in the admin editor — was **verified SAFE**; the real issue is on the **public** render.

---

## 1. Findings (lead-verified)

| ID | Sev | Where | Finding |
|---|---|---|---|
| **C-01** | **High** | `app/views/modules/cms/page.php:80` (fed by `pageRoutes.php:27/42`, written at `cmsRoutes.php:94`) | **Public stored XSS — CMS page content rendered raw to every visitor.** The published page body does `echo $processedContent;` (line 80) where `$processedContent` is the admin-authored `content` HTML after only cosmetic `str_replace` on a few tags — **no HTML sanitization**. `content` is stored verbatim from `$_POST['content']` (manage handler, line 94). So a `cms:edit` holder — **including a lower-privileged employee**, not just a full admin — who stores `<script>` / `<img src=x onerror=…>` / `<svg onload=…>` in a page body gets it executed in **every public visitor's browser** on `/page/<slug>`, and in any admin who views the page (session/credential theft, privileged actions). The same sink renders `content_translations[lang]` (via `pageRoutes.php:37`). The site CSP (`config.php:398`) uses `script-src 'unsafe-inline'`, so it does **not** mitigate this. `content` is HTML-by-design, so the fix is an **allowlist HTML sanitizer** (strip `<script>/<style>/<iframe>`, `on*=` handlers, `javascript:`/`data:` URLs), not `htmlspecialchars` (which would break legitimate WYSIWYG markup). **No sanitizer currently exists in the codebase.** |
| **C-02** | Low | `cmsRoutes.php:93` (render: `menus.php:72,251`; CRUD preview href in `pages.php:38`) | **`slug_url` is not sanitized server-side** — the manage handler only `trim()`s it (the client `sanitizeSlug` JS is bypassable via a direct POST). A malformed slug (`"><…`, `javascript:`) then flows into the admin menus `<option value="<?= $page['slug_url'] ?>">` (unescaped attribute — the adjacent `data-slug` IS escaped) and the CRUD list preview `href` placeholder (unescaped). Admin-area only; low real impact, but a genuine unvalidated-write + unescaped-attribute pair. |
| **C-03** | Low (latent) | `pages-manage.php:356`, `pages.php:7`, `menus.php:12` | Flash `$_SESSION['message']['text']` echoed raw. All setters are static `T::` constants or `$e->getMessage()` (DB error text, not attacker-HTML) — **not currently reachable**; latent. Trivial to harden. |
| **C-04** | Low | `menusRoutes.php:87` (`move-menu-item`) | **Missing CSRF guard.** This state-changing POST has `ADMIN_AUTH()` + `admin_require(cms,edit)` but — unlike all **9** sibling menu handlers — **no `CSRF::guard()`**. An attacker page could drive an authenticated admin's browser to move a CMS page into a menu. Low impact (menu placement), but a real inconsistency/CSRF gap. |
| **C-05** | Low | `menus.php:525,607` (source `:466`) | **DOM re-introduction of XSS.** A menu label is escaped on server render (line 314, safe), but the parent-select JS reads it back with jQuery `.text()` (line 466) and re-inserts it as `<option>` innerHTML via a template string (`${item.title}`, lines 525/607) — unescaped. A stored label `<img src=x onerror=…>` fires inside the admin edit modal. Self/stored-XSS-to-admin (a lower-priv staffer who creates menu items → fires when a full admin edits). Build the option with `textContent`/`createTextNode`. |

---

## 2. Verified PASS — including the candidate I most suspected

- **Admin WYSIWYG `content` round-trip is SAFE.** The editor echoes `content` into a `<textarea>` body via `htmlspecialchars` (`pages-manage.php:512-515`) and CKEditor initializes **from that DOM node** (`.create(document.querySelector('#content'))`, line 755) — not from a raw JS string / `setData('<?= … ?>')`. So `</textarea><script>` cannot break out of the admin editor. **`translate.php` is the same** (content into `<textarea>` via `htmlspecialchars`, line 132; page_name into `value=""` escaped, line 118). This was the highest-suspicion sink — it's clean. The XSS is purely on the **public** render (C-01).
- **CSRF: the 9 other menu handlers + all cmsRoutes POSTs are guarded** (`ADMIN_AUTH` + `admin_require(cms,...)` + `CSRF::guard()`/`CSRF::verifyRequest()`), and the `app.js` global `fetch`/`XHR` interceptor attaches `X-CSRF-TOKEN` (jQuery `$.ajax` rides the XHR wrapper). Only `move-menu-item` is missing the guard (C-04).
- **`upload-image`** — `secureUploadCheck` (finfo MIME + ext allowlist, **SVG intentionally excluded**), random filename, `0644`. Solid.
- **Save-form CSRF tokens present** (`CSRF::tokenField()` in pages-manage.php:386 and translate.php:38).
- **Menu labels escaped on render** (`htmlspecialchars($translatedName)`, menus.php:314); menu URLs are never emitted into an `href` on the admin screen.
- **Per-action RBAC** is correct (view/add/edit/delete mapped to the right `cms:*` permission across both route files). **No SQLi** (ids `(\d+)`-constrained or `(int)`-cast; all bound Medoo where).
- List view: `->col()` does not include `content`; the `slug_url` row closure escapes.

## 3. Noted, not a code bug
- **Dead duplicate-slug check** (`cmsRoutes.php:102-110,130`): `$existing` is computed but never acted on, so two pages can share a slug (the first wins at `/page/<slug>`). Data-integrity nit, not security. (Relatedly, C-02's slug validation would be the place to also enforce uniqueness.)
- **CSP is permissive** (`'unsafe-inline' 'unsafe-eval'`, config.php:398) by design (the app uses inline scripts). It means stored-HTML XSS is unmitigated — a reason C-01 is High, and a candidate for a longer-term tightening pass (out of scope here).

## 4. Fix plan
1. **C-01 (High):** add an allowlist **HTML sanitizer** and apply it when rendering CMS content to the public (`modules/cms/page.php`, both `content` and `content_translations`) — and ideally also sanitize on **save** (`cmsRoutes.php` manage + translate) as defense-in-depth. Strip `<script>/<style>/<iframe>/<object>/<embed>`, all `on*=` attributes, and `javascript:`/`data:` (non-image) URLs, while keeping the formatting tags the WYSIWYG emits (p/h1-6/ul/ol/li/a/strong/em/img/figure/table/…). No vendored sanitizer exists — add a small self-contained one (or vendor one) rather than a fragile regex.
2. **C-02 (Low):** sanitize `slug_url` server-side on save (`preg_replace` to `[a-z0-9\-]`, lowercased) and escape the `menus.php:72/251` `value=""`.
3. **C-03 (Low):** `htmlspecialchars()` the three flash echoes.
4. **C-04 (Low):** add `CSRF::guard()` to `move-menu-item`.
5. **C-05 (Low):** build the parent-select `<option>` with `textContent` instead of an innerHTML template.
