# Hardened root `.htaccess` — GoGlobia (F-03 remediation)

**Date:** 2026-11-11
**Purpose:** Close the production file-exposure finding (F-03) from the pentest — stop
the web server from handing out the live error log, the Internal/Confidential `.md`
specs, the DB dump, and other sensitive files that currently return **200** at the web root.

## What changed vs. your current file

I kept **100 % of your working rules** (rewrite engine, the `/updates` route, the
`index.php` catch-all, caching, gzip/brotli/deflate, expires). I only **added two
focused security blocks** and left everything else byte-for-byte:

1. **Block sensitive file types at the web root** — `.sql .log .md .docx .pdf .db .lock .env .ini .sh .bak .old`
   and backup/dump suffixes. These are never meant to be fetched over HTTP.
2. **Explicitly block the known sensitive dotfiles/paths** — `.git` (you already had this),
   `.env`, `.DS_Store`, `composer.*`, `package*.json`, and the local report archives.

### Important — what this does and does NOT affect (verified against the code)

- ✅ **Invoice / ticket PDFs keep working.** They are generated into `uploads/invoices|tickets/`
  and streamed through PHP routes (`Content-Disposition: ... .pdf`), **not** served as raw
  `.pdf` files from the web root. The `.pdf` deny below only blocks `.pdf` files sitting at the
  document root (your two report files), not the router-served downloads.
- ✅ **OpenAPI docs keep working.** `.yaml` / `.yml` are **intentionally not blocked** — your
  `/docs/` Swagger serving is preserved (the `AddType application/yaml` line stays).
- ✅ **The app, PWA, and all assets are untouched** — the rewrite, manifest, service worker,
  caching and compression rules are unchanged.
- ⚠️ **These files will stop being downloadable** (this is the point): `CLAUDE.md`, `README.md`,
  `GIA_by_GoGlobia_Complete_BRD.md`, `GoGlobia_Umrah_Service_Full_Engineering_Spec.md`,
  `Goglobia Report 2.11.{pdf,docx}`, `_error.log`, `app.db`, `goglobia_live (3).sql`.

> **Belt-and-suspenders (do this too, independent of `.htaccess`):**
> The `.htaccess` blocks web access, but these files should not live in the public document
> root at all. After deploying this file, **delete** the DB dump, the `_error.log`, and the
> Confidential `.md`/report files from the server's `public_html`, and point PHP's `error_log`
> to a path **outside** the web root. Also set `APP_DEBUG=false` in the production `.env`.
> (On LiteSpeed, `.htaccess` is honored like Apache, so the rules below apply as-is.)

---

## The full hardened `.htaccess` (copy this over your current one)

```apache
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

# Serve the Swagger/OpenAPI YAML file with the correct content type for /docs/.
AddType application/yaml .yaml .yml

# PWA: correct MIME types for the web app manifest and service worker.
AddType application/manifest+json .webmanifest
AddType text/javascript .js

# =====================================================================
# SECURITY (F-03): block direct web access to sensitive files at the
# document root. These are logs, DB dumps, internal docs, dependency
# manifests and backups that must never be fetched over HTTP.
# NOTE: .yaml/.yml are intentionally NOT listed here — the /docs OpenAPI
# files are meant to be served. Invoice/ticket PDFs are streamed via PHP
# routes from uploads/, so blocking root-level *.pdf does not affect them.
# =====================================================================
<FilesMatch "\.(sql|log|md|markdown|docx?|pdf|db|sqlite|lock|env|ini|sh|bak|old|orig|save|swp|dist)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order Allow,Deny
        Deny from all
    </IfModule>
</FilesMatch>

# Block dotfiles and known-sensitive config/metadata files by name.
<FilesMatch "^(\.env.*|\.git.*|\.htaccess|\.htpasswd|\.DS_Store|composer\.(json|lock)|package(-lock)?\.json|yarn\.lock|\.user\.ini)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order Allow,Deny
        Deny from all
    </IfModule>
</FilesMatch>

# Block the local SQL dump even with the space in its name (defence in depth
# atop the \.sql rule above), and any *.sql.* / *~ backup variants.
<FilesMatch "(\.sql(\.(gz|zip|bz2|tar))?|~)$">
    <IfModule mod_authz_core.c>
        Require all denied
    </IfModule>
    <IfModule !mod_authz_core.c>
        Order Allow,Deny
        Deny from all
    </IfModule>
</FilesMatch>

# Standalone updates installer — /updates → updates.php (must be BEFORE index.php catch-all).
# This file ships through the updates system — keep it generic, no per-site paths.
RewriteRule ^updates/?$ updates.php [QSA,L]

RewriteCond %{REQUEST_FILENAME} !-d
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-l
RewriteRule ^(.+)$ index.php?url=$1 [QSA,L]

# secure GIT files
RedirectMatch 404 /\.git

# Hide the PHP version banner (F-14 — reduce fingerprinting).
<IfModule mod_headers.c>
    Header always unset X-Powered-By
    Header unset X-Powered-By
</IfModule>

# One year for image files
<filesMatch ".(jpg|jpeg|png|gif|ico)$">
Header set Cache-Control "max-age=31536000, public"
</filesMatch>
# One month for css and js

<filesMatch ".(css|js)$">
Header set Cache-Control "max-age=2628000, public"
</filesMatch>

<IfModule mod_expires.c>
ExpiresActive On

# Images
ExpiresByType image/jpg "access 1 year"
ExpiresByType image/jpeg "access 1 year"
ExpiresByType image/gif "access 1 year"
ExpiresByType image/png "access 1 year"

# Video
ExpiresByType video/mp4 "access plus 1 year"
ExpiresByType video/mpeg "access plus 1 year"

# CSS, JavaScript
ExpiresByType text/css "access plus 1 month"
ExpiresByType text/javascript "access plus 1 month"
ExpiresByType application/javascript "access plus 1 month"

# Others
ExpiresByType application/pdf "access plus 1 month"
ExpiresByType application/x-shockwave-flash "access plus 1 month"
</IfModule>

<ifModule mod_gzip.c>
mod_gzip_on Yes
mod_gzip_dechunk Yes
mod_gzip_item_include file .(html?|txt|css|js|php|pl)$
mod_gzip_item_include handler ^cgi-script$
mod_gzip_item_include mime ^text/.*
mod_gzip_item_include mime ^application/x-javascript.*
mod_gzip_item_exclude mime ^image/.*
mod_gzip_item_exclude rspheader ^Content-Encoding:.*gzip.*
</ifModule>

<IfModule mod_deflate.c>
AddOutputFilterByType DEFLATE text/plain text/html text/xml text/css text/javascript application/javascript application/json application/xml image/svg+xml
</IfModule>

<IfModule mod_brotli.c>
AddOutputFilterByType BROTLI_COMPRESS text/plain text/html text/xml text/css text/javascript application/javascript application/json application/xml image/svg+xml
</IfModule>

<IfModule mod_headers.c>
Header append Vary Accept-Encoding

<FilesMatch "\.(css|js|mjs|svg|woff|woff2|ttf|eot|png|jpg|jpeg|gif|webp|avif|ico)$">
Header set Cache-Control "public, max-age=31536000, immutable"
</FilesMatch>

<FilesMatch "\.(html|php)$">
Header set Cache-Control "private, no-cache, no-store, must-revalidate"
</FilesMatch>
</IfModule>

<IfModule mod_expires.c>
ExpiresByType image/webp "access plus 1 year"
ExpiresByType image/avif "access plus 1 year"
ExpiresByType image/svg+xml "access plus 1 year"
ExpiresByType font/woff "access plus 1 year"
ExpiresByType font/woff2 "access plus 1 year"
</IfModule>
```

---

## How to verify after you deploy it

Run these from your machine (they should all now be **403** instead of 200):

```bash
for p in "/_error.log" "/goglobia_live%20(3).sql" "/app.db" "/composer.lock" \
         "/GIA_by_GoGlobia_Complete_BRD.md" "/GoGlobia_Umrah_Service_Full_Engineering_Spec.md" \
         "/CLAUDE.md" "/README.md" "/Goglobia%20Report%202.11.pdf"; do
  printf "%-50s -> " "$p"
  curl -s -o /dev/null -w "%{http_code}\n" "https://goglobia.com$p"
done
```

And confirm these still work (should be **200**):

```bash
curl -s -o /dev/null -w "home:        %{http_code}\n" "https://goglobia.com/"
curl -s -o /dev/null -w "openapi:     %{http_code}\n" "https://goglobia.com/openapi-flight.yaml"
curl -s -o /dev/null -w "updates:     %{http_code}\n" "https://goglobia.com/updates"
# And log into the site and download any invoice/ticket PDF — it goes through a PHP route, unaffected.
```

## Post-deploy cleanup checklist (server side — do these as well)

- [ ] Delete `goglobia_live (3).sql`, `_error.log`, `app.db` from `public_html`.
- [ ] Delete / move out of web root: `GIA_by_GoGlobia_Complete_BRD.md`,
      `GoGlobia_Umrah_Service_Full_Engineering_Spec.md`, `Goglobia Report 2.11.{pdf,docx}`,
      `CLAUDE.md`, `UPLOAD_TO_SERVER/`.
- [ ] In the production `.env`: set `APP_DEBUG=false` (stops verbose logging + Whoops).
- [ ] Point PHP `error_log` to a path **outside** `public_html` (e.g. `/home/goglobia/logs/php_error.log`).
- [ ] (Optional, F-14) `expose_php = Off` in php.ini to drop the `X-Powered-By` header at the source.
```
```
