# Custom-domain HTTPS — cPanel setup runbook

Your app side is already live. This is the one piece that must run **outside** PHP —
because no web app can issue its own TLS certificate. Follow the path that matches your
hosting.

---

## Read this before you touch anything

**Pure cPanel shared hosting cannot run the TLS edge.** No root, no control of ports
80/443, no long-running process. So you have two real paths — pick one.

| Path | What it is | Verdict |
|---|---|---|
| **A — Recommended** | A tiny VPS running **Caddy** sits in front, terminates HTTPS for every hotel domain, forwards to your cPanel origin. One box, unlimited domains, zero per-domain work. | Phases 1–5. ~30 min. |
| **B — cPanel-only** | No VPS. Use **cPanel AutoSSL** — add each hotel domain as an Alias/Addon; cPanel issues its cert. Works, but manual per domain and doesn't scale. | Phase 6. Per domain, forever. |

Everything through Phase 5 is Path A. Phase 6 is the Path-B fallback if you have no VPS yet.

---

## The four states a domain moves through

Each supplier domain walks this line inside the app. You'll see these labels on the
hotel's **Site & Domain** screen.

| State | Meaning |
|---|---|
| `none` | No custom domain saved yet. |
| `pending` | Domain saved; waiting for DNS records to be added & found. |
| `verified` | DNS proven. Page serves over `http`. Cert not issued yet. |
| `active` | Real HTTPS confirmed. Live on `https`. |

---

## Phase 1 — Stand up a small VPS

Any provider works — a $5/mo box is plenty. This is the host your hotels' DNS will
ultimately point at.

1. Create a VPS (Ubuntu 22.04+). Note its **public IPv4** — call it `EDGE_IP`.
2. Open ports **80** and **443** inbound in its firewall/security group.
3. SSH in as root (or a sudo user).

> **Why not just cPanel's IP?** Your current app IP is `65.181.116.249`. If that's
> shared cPanel, you cannot bind Caddy to its 443. The VPS is a separate, controllable
> front door — your cPanel keeps serving the app behind it.

---

## Phase 2 — Install Caddy & drop in the config

The `Caddyfile` already ships in your repo at `deploy/Caddyfile` — copy it to the VPS,
don't rewrite it.

**On the VPS — install Caddy:**
```bash
# Official Caddy apt repo (Ubuntu/Debian)
sudo apt install -y debian-keyring debian-archive-keyring apt-transport-https curl
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key' \
  | sudo gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
curl -1sLf 'https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt' \
  | sudo tee /etc/apt/sources.list.d/caddy-stable.list
sudo apt update && sudo apt install -y caddy
```

**Copy your repo's Caddyfile onto the VPS (from your machine):**
```bash
scp deploy/Caddyfile root@EDGE_IP:/etc/caddy/Caddyfile
```

That file does on-demand TLS gated by your app's `/tls/authorize` endpoint, and
reverse-proxies with the real `Host` header. You don't edit its logic — only the env
values in Phase 3.

---

## Phase 3 — Point Caddy at your app with three values

The Caddyfile reads three environment variables.

| Variable | What to put |
|---|---|
| `APP_ORIGIN` | Where your PHP app actually serves, e.g. `https://origin.goglobia.com` (a hostname on cPanel's IP that still answers for the app). |
| `APP_PUBLIC` | Your app's public URL the edge calls for the gate: `https://goglobia.com`. |
| `ACME_EMAIL` | Your email for Let's Encrypt, e.g. `ops@goglobia.com`. |

**Put them in Caddy's service environment:**
```bash
# Create an override so systemd loads the vars for Caddy
sudo mkdir -p /etc/systemd/system/caddy.service.d
sudo tee /etc/systemd/system/caddy.service.d/env.conf >/dev/null <<'EOF'
[Service]
Environment=APP_ORIGIN=https://origin.goglobia.com
Environment=APP_PUBLIC=https://goglobia.com
Environment=ACME_EMAIL=ops@goglobia.com
EOF

sudo systemctl daemon-reload
sudo systemctl restart caddy
sudo systemctl enable caddy        # survive reboots
systemctl status caddy             # should read: active (running)
```

> **Need an origin hostname?** `APP_ORIGIN` must reach your app *without* looping through
> the VPS. In cPanel DNS, add an A-record like `origin.goglobia.com → 65.181.116.249`
> (your cPanel IP) and make sure the app answers on it.

---

## Phase 4 — Platform DNS & two admin settings

Do this once. In **cPanel → Zone Editor** for `goglobia.com`, add these records pointing
at the **VPS** (`EDGE_IP`), not cPanel:

| Type | Name | Value | Purpose |
|---|---|---|---|
| A | `sites.goglobia.com` | `EDGE_IP` | The CNAME target hotels aim at |
| A | `*.goglobia.com` | `EDGE_IP` | Auto `<slug>.goglobia.com` subdomains |

Then in the app, **Admin → Settings**:

| Setting | Value |
|---|---|
| `supplier_site_domain` | `goglobia.com` — turns the whole feature on |
| `supplier_site_ip` | `EDGE_IP` — shown to hotels for apex A-records |

> **Changed `supplier_site_ip`:** this is the *edge* IP now, not your cPanel IP. Hotels
> using an apex A-record must point at the VPS, because that's where TLS is terminated.

---

## Phase 5 — Prove it end-to-end with one domain

Walk a single hotel domain through the state machine. If this works, every future domain
is automatic.

1. In a hotel's **Site & Domain** screen, save `book.theirhotel.com`. State → `pending`,
   and the app shows the exact records.
2. At *the hotel's* DNS provider, add what the app shows:

   | Type | Name | Value |
   |---|---|---|
   | CNAME | `book.theirhotel.com` | `sites.goglobia.com` |
   | TXT | `book.theirhotel.com` | `goglobia-verify=<token>` |

3. Click **Verify now**. Once DNS resolves → `verified` (page already serves over http).
4. Open `https://book.theirhotel.com` once in a browser. This first hit triggers Caddy to
   ask your `/tls/authorize` gate, get a yes, and fetch a Let's Encrypt cert.
5. Back in the app, click **Check HTTPS & go live** → `active`. Done.

**Sanity-check the gate yourself (optional):**
```bash
# A verified tenant returns 200; a random host returns 404.
curl -s -o /dev/null -w "%{http_code}\n" \
  "https://goglobia.com/tls/authorize?domain=book.theirhotel.com"   # -> 200
curl -s -o /dev/null -w "%{http_code}\n" \
  "https://goglobia.com/tls/authorize?domain=random-stranger.com"   # -> 404
```

> **That 404 is the point.** It's what stops a stranger pointing any domain at your edge
> and flooding Let's Encrypt. Caddy only requests certs for domains your app vouches for.

---

## Phase 6 — Path B: no VPS? Use cPanel AutoSSL per domain

Honest trade-off: works today with zero extra servers, but it's manual for every single
hotel domain and won't scale past a handful.

1. Have the hotel point their domain straight at cPanel:
   `A  book.theirhotel.com → 65.181.116.249` (your cPanel IP), plus the `TXT` the app shows.
2. In **cPanel → Domains**, add `book.theirhotel.com` as an **Alias** (or Addon) pointing
   at the same docroot as the app.
3. In **cPanel → SSL/TLS Status**, select the new domain and click **Run AutoSSL**.
   cPanel issues the cert within minutes.
4. In the app, click **Verify now** then **Check HTTPS & go live** → `active`.

> **Know the limits before you lean on this.** Every new hotel = you adding an Alias +
> running AutoSSL by hand. AutoSSL also silently caps how many domains a cPanel account
> will cover. For more than a few branded domains, move to Path A.

The app code is identical either way — `/tls/authorize`, DNS verify, and the HTTPS probe
all behave the same. Only *who issues the certificate* differs.

---

## Notes

- **What you don't touch:** the app is already deployed via your own `/updates.php`. No
  code step here — this is pure infra. The `Caddyfile` and operator notes live beside this
  file at `deploy/Caddyfile` and `deploy/README-custom-domains.md`.
- **If a domain won't go live:** almost always DNS propagation (wait, re-run **Verify
  now**) or the first `https://` visit not having happened yet (open the domain in a
  browser, then **Check HTTPS & go live**).
