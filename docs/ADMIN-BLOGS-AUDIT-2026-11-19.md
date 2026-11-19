# Admin Blogs Pages — End-to-End Audit (2026-11-19)

**Scope:** the admin Blogs area —
- `app/routes/admin/blogsRoutes.php` (491): list, add GET/POST, edit GET/POST, delete, categories list/get/add/update/delete.
- Views: `admin/blogs/blogs.php` (list), `admin/blogs/manage-blog.php` (748, editor), `admin/blogs/categories.php` (categories).
- **Public + API render paths** (in scope — blogs are published): `app/routes/blogsRoutes.php` + `app/views/modules/blogs/{detail,listing,featured}.php`, and `app/routes/api/blogsRoutes.php` (mobile).

**Method:** lead read the full admin route file + the public detail/listing views + the blogs API + `cleanBlogHtml()`; one auditor read the three admin views; **lead re-verified every finding at file:line, including running the actual `crud.php::formatRowData` escape against the blog row templates** to settle whether the admin-list sinks are live.

**Headline:** the blogs area is in **good shape** — the post body is already DOM-sanitized on save (`cleanBlogHtml`, both add+edit), the WYSIWYG editor is injection-safe, CSRF is everywhere, and the admin-list `->row()` templates are **neutralized by the shared `crud.php` escape** added in the bookings audit. The one real gap is that **translation bodies are stored unsanitized** and leak raw through the **mobile API**.

---

## 1. Findings (lead-verified)

| ID | Sev | Where | Finding |
|---|---|---|---|
| **B-01** | **Medium** | `blogsRoutes.php:104` (add) & `:277` (edit) store; `api/blogsRoutes.php:43,94,137` emit | **Translation post bodies stored unsanitized → raw via the mobile API.** The main `post_desc` is run through `cleanBlogHtml()` on save (add:49, edit:197) — but the per-language **`desc_translations[lang]`** is stored with only `trim()`. The public **web** detail happens to re-sanitize on render (`detail.php:82` calls `cleanBlogHtml`), so the website is safe — **but the blogs REST API returns the translation `desc` raw** in JSON (and does not sanitize), so any mobile app / JS client that renders a non-English blog body as HTML executes a stored `<script>`/`<img onerror>` planted by a `cms:edit` holder. Fix at the source: `cleanBlogHtml()` the translation descs on save (covers every consumer). |
| **B-02** | Low | `app/views/modules/blogs/listing.php:60` | Public listing excerpt is `strip_tags($post_desc)` with **no `htmlspecialchars`**. `strip_tags` removes tags but does not entity-encode, so a stored-dirty translation body (B-01) rendered here could leave dangling `<`/`"` fragments. Should be `htmlspecialchars(strip_tags(...))`. |
| **B-03** | Low (latent) | `manage-blog.php:364`, `blogs.php:10`, `categories.php:10` | Flash `$_SESSION['message']['text']` echoed raw. Setters in the route file are static `T::` constants / `implode('<br>',$errors)` of T:: strings / `$e->getMessage()` (DB text) — **not attacker-HTML**; latent. Trivial to harden. |
| **B-04** | Low | `blogsRoutes.php:120` | `author_id => $_SESSION['admin_id'] ?? null` — the real admin login never sets `admin_id` (sets `user_id`/`user_role`), so blog authorship is **always null**. Use `$_SESSION['user_id']`. (Same class as the users/transactions-page `created_by` finding.) |
| **B-05** | Low | `blogsRoutes.php:64,133,137,212` | `redirect($_SERVER['HTTP_REFERER'] ?? …)` on add/edit error branches — open-redirect/header-injection class (Referer is attacker-influenceable). Use a fixed in-app target. |

---

## 2. Verified PASS — including the admin-list sinks the auditor flagged

- **Admin-list `->row()` template XSS is NOT live (verified by running the escape).** The auditor flagged `{{post_title}}`/`{{post_slug}}` (`blogs.php:36-41`), `{{cat_name}}`/`{{cat_slug}}` (`categories.php:37-45`), and `{{post_img}}` into `<img src>` (`blogs.php:31-35`) as stored XSS. These are **string templates** (not closures), so they pass through `CRUD::formatRowData()`, which — since the admin-bookings audit — **`htmlspecialchars`-escapes the substituted `{{column}}` value**. I ran the exact escape against hostile inputs: `post_title`→`&lt;script&gt;`, `post_slug`→`&quot;&gt;&lt;img…&gt;`, `cat_name`→`&lt;img…&gt;`, `post_img`→`x&quot; onerror=…` inside `src="…"` — **all entity-encoded, none can execute or break the attribute.** So these are already neutralized (the crud.php fix retroactively covers the blogs list too). Not findings.
- **Post body (`post_desc`) is DOM-sanitized on save for BOTH add and edit** via `cleanBlogHtml()` (a well-built DOMDocument allowlist: strips script/style/iframe/object/form/svg with subtree, allowlist tags+attrs, scheme-checks URLs) — and the public detail re-sanitizes on render. Belt + braces.
- **The WYSIWYG editor is injection-safe.** `post_desc` and every translation `desc` reach CKEditor via an `htmlspecialchars`'d `<textarea>` body (manage-blog.php:432, :645) + CKEditor-from-DOM (`.create(document.querySelector…)`) — no raw `setData('<?= ?>')`/JS-string path. `post_title`/`meta_*`/translation titles are all `htmlspecialchars`'d in their inputs.
- **CSRF is complete:** every state-changing POST (add/edit/delete + 3 category handlers) has `ADMIN_AUTH()` + `admin_require(cms, add/edit/delete)` + `CSRF::guard()`; save forms carry `CSRF::tokenField()`; the CKEditor `simpleUpload` + category fetches send `X-CSRF-TOKEN` (covered by the app.js interceptor).
- **File upload** (featured image, both add+edit) uses `secureUploadCheck` (finfo MIME + ext allowlist, SVG excluded), random filename, `0644`; edit deletes the old file under the project root.
- **No SQLi** (ids `(\d+)`/`intval`; all bound Medoo); per-action RBAC correct; the public listing category name + slug are `htmlspecialchars`'d (the prior public-listing category XSS from `frontend-xss-pass` stays fixed).

## 3. Noted, not a code bug
- **PII/authorship:** minor — blog author is effectively unattributed (B-04).
- The public **detail** view's render-time `cleanBlogHtml` is the robust control; B-01's save-time fix is defense-in-depth that also closes the **API** path (the one consumer that doesn't re-sanitize).

## 4. Fix plan
1. **B-01 (Medium):** in both add (`:101-106`) and edit (`:274-279`) translation loops, run the translation `desc` through `cleanBlogHtml()` before storing (mirror the main-body treatment). Closes the API leak at the source.
2. **B-02 (Low):** `htmlspecialchars()` the listing excerpt (`listing.php:60`).
3. **B-03 (Low):** `htmlspecialchars()` the three admin flash echoes.
4. **B-04 (Low):** `author_id => $_SESSION['user_id'] ?? null`.
5. **B-05 (Low):** redirect the add/edit error branches to a fixed `root.admin.'/blogs…'` target instead of `HTTP_REFERER`.
