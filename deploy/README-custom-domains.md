# Custom-domain TLS edge — operator setup

This directory ships the edge configuration that gives every supplier custom domain
automatic HTTPS, with zero per-domain work. The application side is already built and
deployed (the `/tls/authorize` endpoint, DNS verification, and verified→active
activation); this is the one piece that must run **outside** PHP because a web app
cannot issue TLS certificates for itself.

## The moving parts

| Piece | Where | Already built? |
|---|---|---|
| `GET /tls/authorize?domain=…` (on-demand-TLS gate) | PHP app | ✅ shipped |
| DNS verify (TXT + CNAME/A) → `verified` | PHP app | ✅ shipped |
| HTTPS probe → `active` | PHP app | ✅ shipped |
| On-demand cert issuance + reverse proxy | **Caddy edge (`Caddyfile`)** | this file |

## One-time operator DNS (platform side)

Point the **CNAME target** suppliers are told to use at the edge server's public IP.
The app tells suppliers to CNAME to `sites.<branded-root>` (e.g. `sites.goglobia.com`),
so create:

```
sites.goglobia.com.   A   65.181.116.249        ; the edge server's public IP
*.goglobia.com.       A   65.181.116.249        ; branded <slug>.goglobia.com subdomains
```

Then set, in **Admin → Settings**:
- `supplier_site_domain` = `goglobia.com`  (turns the whole branded-site feature on)
- `supplier_site_ip` = `65.181.116.249`  (shown to hotels for apex A-records)

## What each supplier does (the app shows them this)

For `book.theirhotel.com`:
```
CNAME  book.theirhotel.com  ->  sites.goglobia.com
TXT    book.theirhotel.com  ->  goglobia-verify=<token>
```
For an apex domain (`theirhotel.com`) they use an ALIAS/ANAME to `sites.goglobia.com`,
or an A-record to `65.181.116.249`, plus the TXT. They click **Verify now**; once DNS
resolves, status → `verified` and the page already serves over http. The first https
visit triggers Caddy to obtain a Let's Encrypt cert (allowed only because
`/tls/authorize` returns 200 for that verified tenant); then **Check HTTPS & go live**
flips it to `active`.

## Running the edge

On a VM/VPS the operator controls with ports 80 + 443 public (this is the host the DNS
above points at):

```bash
APP_ORIGIN=https://origin.goglobia.com \   # where the PHP app actually serves
APP_PUBLIC=https://goglobia.com \          # app URL the edge calls for /tls/authorize
ACME_EMAIL=ops@goglobia.com \
caddy run --config deploy/Caddyfile
```

If the app is on cPanel shared hosting (no controllable edge there), run this Caddy on a
small separate VPS in front of cPanel and set `APP_ORIGIN` to the cPanel origin host.
Caddy forwards the original `Host` header, which is all the app needs to resolve the
tenant.

## Why the `ask` gate matters

Without it, On-Demand TLS is an open cert issuer: anyone who points any domain at the
edge can make it hammer Let's Encrypt, hit rate limits, and exhaust the CA quota. The
`ask` endpoint answers 200 only for hosts that are a real, DNS-verified, approved+live
tenant (`supplier_site_tls_authorize()`), so issuance is scoped to domains we genuinely
serve.
