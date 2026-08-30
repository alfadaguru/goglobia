# Self-Hosting the Update System on Your Own GitHub

**Goal:** push your own updates to your own GitHub repo, then install them from
the existing `/updates` GUI — exactly the way the vendor (phptravels.com) does.

Everything in this guide was verified against the actual source. File paths and
line numbers are given so you can confirm each step yourself.

> ⚠️ Read §1 first. The GitHub repo + token are **not** stored in this codebase.
> They are fetched over the network from a hardcoded URL. You cannot "just paste
> your repo somewhere" — you must either (A) host your own credentials JSON and
> repoint the URL, or (B) hardcode your repo/token directly. Both are covered.

---

## 1. How the update system actually resolves credentials (ground truth)

There are **two** front-ends and they read credentials differently:

### The file installer — `/updates.php` (the GUI you use)
- URL `/updates` → `updates.php` via root `.htaccess`
  (`RewriteRule ^updates/?$ updates.php`). **Public, no login.**
- It has its **own** hardcoded credentials URL:
  - `updates.php:21` → `define('UPD_CREDENTIALS_URL', 'https://phptravels.com/updates.json');`
  - `upd_credentials()` (`updates.php:48`) cURL-GETs that URL and expects JSON
    with **`github_repo`** and **`github_token`** (both required, or it returns
    `[]` and the GUI shows "Update service unavailable").
- It then calls the GitHub REST API with that token:
  - `upd_github()` (`updates.php:72`) → `https://api.github.com/repos/{repo}/...`
    with header `Authorization: token {github_token}`.
  - Lists commits `GET /repos/{repo}/commits?per_page=100`
    (`upd_commit_list`, `updates.php:210`), filtered to commits on/after
    `updates_start_date`, sorted **oldest-first**.
  - Downloads each changed file at the commit via
    `GET /repos/{repo}/contents/{path}?ref={sha}` (`updates.php:464`).

### The admin config / DB tool — `app/views/admin/updates-config.php`
- Separate file, also hardcodes the SAME URL:
  - `updates-config.php:32` → `$credentialsUrl = 'https://phptravels.com/updates.json';`
  - `v10_updates_credentials()` (`updates-config.php:40`) fetches it the same way.
  - The final `return [...]` array exposes them at
    `updates-config.php:96` (`github_repo`) and `:97` (`github_token`).
- ⚠️ **The file installer does NOT read `updates-config.php` for credentials.**
  The installer only reads `updates-config.php` to extract `updates_start_date`
  as *text* via a regex (`upd_start_date()`, `updates.php:200`). So the URL that
  actually controls what the GUI installs is the one in **`updates.php:21`**.

### What the remote JSON must look like
`upd_credentials()` accepts either a flat object or a `{ "credentials": {...} }`
wrapper (`updates.php:66-68`). Minimum required fields:

```json
{
  "github_repo": "your-github-username/your-repo",
  "github_token": "ghp_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX",
  "default_branch": "main",
  "allowed_repos": ["your-github-username/your-repo"]
}
```

- `github_repo` — `owner/repo` (NOT a URL).
- `github_token` — a GitHub Personal Access Token (see §3).
- `default_branch` / `allowed_repos` — optional; used by the admin config only.

### Guardrails already in the installer (so you know the rules)
- SHA must be 40 hex chars (`updates.php:378`).
- Only the **oldest pending commit** may be installed; out-of-order is rejected
  (`updates.php:403`) — installs go one at a time, oldest → newest.
- Only commits **on/after `updates_start_date`** appear
  (`updates-config.php:updates_start_date`, currently `2026-08-19`; installer
  fallback `2026-08-15`).
- Files matching `$UPD_EXCLUDED` are never overwritten (`updates.php:29`):
  `.env`, `uploads/`, `cache/`, `backups/`, `app/updates.json`,
  `app/views/admin/updates-config.php`.
- Files whose GitHub status is `removed` are **deleted** locally
  (`updates.php:443`) — so a commit can delete files on installs too.

---

## 1b. Full update flow (diagram)

Two moments matter: **(1) you push a commit to GitHub**, and **(2) someone opens
`/updates` and clicks Install**. Everything the app does is a reaction to your
commits. Boxes show the file + function that runs.

```
┌──────────────────────────────────────────────────────────────────────────┐
│  YOU (developer)                                                           │
│    edit files in the working copy → git add → git commit → git push        │
│    each COMMIT = one installable "update" (commit message = its title)     │
└───────────────────────────────┬──────────────────────────────────────────┘
                                 │  pushes to
                                 ▼
                    ┌─────────────────────────────┐
                    │  GitHub repo  owner/repo    │  (your repo, private rec.)
                    │  commits, file contents     │
                    └──────────────┬──────────────┘
                                   ▲  reads with Authorization: token <PAT>
                                   │
        ┌──────────────────────────┴───────────────────────────┐
        │  CREDENTIALS: where the token/repo come from          │
        │  updates.php:21  UPD_CREDENTIALS_URL ────────► HTTPS GET
        │  updates.php:48  upd_credentials()   ◄──────── your updates.json
        │        returns { github_repo, github_token }          │
        └───────────────────────────────────────────────────────┘

────────────────────────  RUNTIME (a visitor hits /updates)  ────────────────

  Browser: GET /updates
     │   .htaccess:9   RewriteRule ^updates/?$ updates.php
     ▼
  updates.php  (PAGE render section, ~L615)
     │  1. upd_credentials()            L48   → get repo+token from your JSON
     │  2. upd_selfheal_htaccess()      L590  → refresh .htaccess if stale
     │  3. upd_commit_list(repo,token)  L210  → GitHub GET /repos/{repo}/commits
     │        · keep commits on/after updates_start_date (config L121 = 2026-08-19)
     │        · sort OLDEST-FIRST
     │  4. upd_history_load()           L175  → read app/updates.json (installed SHAs)
     │  5. compute pending = commits not yet installed
     ▼
  HTML GUI (Tailwind, L667–1160): New / Installed tabs, one row per commit,
  "Install", "Install All", and a "Details" button.

        ┌───────────────── user clicks "Details" ─────────────────┐
        │  JS showUpdateDetails() L1218 → fetchCommitFiles() L1234 │
        │     GET updates.php?action=files&sha=<sha>               │
        │  PHP files endpoint  L339  → upd_github()                │
        │     GitHub GET /repos/{repo}/commits/{sha}               │
        │     returns changed-file list (added/modified/removed)   │
        └──────────────────────────────────────────────────────────┘

        ┌───────────────── user clicks "Install" ─────────────────┐
        │  JS startInstallation() L1317  (or Install All L1558)   │
        │     POST updates.php   body: action=install&sha=<sha>   │
        │                         │                                │
        │                         ▼                                │
        │  PHP install endpoint  updates.php:375                   │
        │   ├─ sha must be 40 hex ...................... L378      │
        │   ├─ upd_credentials() → repo, token ......... L380      │
        │   ├─ already installed? → done ............... L387      │
        │   ├─ upd_commit_list() + upd_autoskip_internal L391/395  │
        │   ├─ ENFORCE ORDER: sha must == oldest pending L403      │
        │   ├─ GitHub GET /repos/{repo}/commits/{sha} .. L405      │
        │   └─ for each changed file:                             │
        │        ├─ excluded ($UPD_EXCLUDED L29)? skip . L427      │
        │        ├─ status 'removed'? @unlink() ........ L443      │
        │        ├─ else GET /contents/{path}?ref={sha}  L464      │
        │        │        (base64 decode, or raw fallback)         │
        │        └─ upd_replace_file() atomic write .... L118/513  │
        │   ├─ if commit touched app/database/db.sql:             │
        │   │      upd_db() + run SQL ................... L530      │
        │   │      (⚠ real schema is install/db.sql — see §5)      │
        │   ├─ record result in app/updates.json ....... L540/560  │
        │   └─ return JSON {success, files_updated,...}  L562      │
        │  JS: log to terminal, then window.location.reload() L1490│
        └──────────────────────────────────────────────────────────┘

  "Install All" (JS autoInstallNext L1558) just repeats the POST for the next
  oldest pending sha, reloading between each, until none remain.

────────────────────  SEPARATE: DB schema tool (admin only)  ─────────────────

  Admin → GET admin/updates/database        app/routes/admin/updatesRoutes.php:20
     ADMIN_AUTH(); renders app/views/admin/updates-database.php
  Admin clicks Apply → POST admin/updates/database/apply           :37
     ADMIN_AUTH(); getDatabaseSchemaDiff($db)  app/lib/functions.php:4913
     → diffs LIVE DB against install/db.sql, applies pending ALTERs.
  (All old admin FILE-update URLs admin/updates(.*) just redirect to /updates.)
```

**One-line summary of the trust chain:** `your updates.json (HTTPS)` →
`github_token` → `GitHub repo commits/files` → written onto the site by the
public `/updates` installer. Whoever controls the JSON URL and the repo controls
what gets installed.

---

## 2. Two ways to switch to your own GitHub

Pick ONE.

### Option A — Host your own credentials JSON (recommended, matches the design)
You keep the exact vendor architecture: a small JSON file on a server you
control, so you can rotate the repo/token later without editing PHP.

1. Create your GitHub repo (see §4) and token (see §3).
2. Create a file `updates.json` with the content from §1 ("What the remote JSON
   must look like").
3. Host it at an **HTTPS** URL you control, e.g.
   `https://updates.yoursite.com/updates.json`. It must be served with a valid
   TLS cert — the fetcher uses `CURLOPT_SSL_VERIFYPEER => true`
   (`updates.php:57`). Protect it (see §6) — it contains a token.
4. Change the URL in **both** files:
   - `updates.php:21` →
     `define('UPD_CREDENTIALS_URL', 'https://updates.yoursite.com/updates.json');`
   - `app/views/admin/updates-config.php:32` →
     `$credentialsUrl = 'https://updates.yoursite.com/updates.json';`
5. Done. Visit `/updates` — the GUI now lists commits from *your* repo.

**Pros:** rotate token/repo centrally; no token committed into the app tree.
**Cons:** you must host one extra JSON file over HTTPS.

### Option B — Hardcode your repo/token directly (simplest, less clean)
Skip the remote JSON entirely by making `upd_credentials()` return your values.

In `updates.php`, replace the body of `upd_credentials()` (`updates.php:48-70`)
so it returns your creds directly:

```php
function upd_credentials() {
    // SELF-HOSTED: return credentials directly instead of fetching a remote URL.
    return [
        'github_repo'  => 'your-github-username/your-repo',
        'github_token' => 'ghp_XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX',
        'default_branch' => 'main',
    ];
}
```

Do the same in `app/views/admin/updates-config.php` by making
`v10_updates_credentials()` (`updates-config.php:40`) return the same array, OR
just hardcode the returned `github_repo` / `github_token` keys in that file's
`return [...]` block (`updates-config.php:96-97`).

**Pros:** no external hosting.
**Cons:** ⚠️ your GitHub **token is now in a PHP file**. `updates.php` is at the
web root and is world-reachable — a token leak here = write access to your repo.
Use a **fine-grained token scoped to only this one repo, contents:read** (§3),
and never commit `updates.php` with a real token to a public repo.

> Whichever option you choose, remember the installer is **public and
> unauthenticated** (anyone who can reach `/updates` can trigger installs). See
> §6 to lock it down.

---

## 3. Create a GitHub token (least privilege)

You want a token that can only **read repository contents** of your one repo.

1. GitHub → **Settings → Developer settings → Personal access tokens →
   Fine-grained tokens → Generate new token**.
2. **Resource owner:** your account/org. **Repository access:** *Only select
   repositories* → pick your update repo.
3. **Permissions → Repository permissions → Contents: Read-only.** (That is all
   the installer needs — it only GETs commits and file contents.)
4. Set an expiry you're comfortable with, generate, copy the `github_pat_...` /
   `ghp_...` value.
5. Put it in your `updates.json` (Option A) or in `upd_credentials()`
   (Option B).

> Classic tokens work too (scope `repo` for private repos, or none for public),
> but fine-grained + contents:read is the safest.

---

## 4. Create and push your update repo

The app's own `.gitignore` is already set up for this — it excludes secrets and
runtime data. Git has been initialized in this working copy (`git init -b main`
was run; identity set to name `aliyumohammedlawal`, email
`alfadaguru@gmail.com` — change with `git config user.name/‑.email`).

### 4a. What is and isn't tracked (verified from `.gitignore`)
Already ignored (safe): `.env`, `/updates.json`, `app/updates.json`,
`backups/**`, `cache/**`, `app/cache/*`, `_error.log`, most of `uploads/**`,
and **`vendor/`** (line 113).

- Because `vendor/` is ignored, anyone deploying from a fresh clone must run
  `composer install`. That's fine for a source repo. **But the live update flow
  copies changed files onto an already-deployed site**, so vendor being absent
  from the repo is OK — you just don't ship vendor changes through updates.
- ⚠️ `.idea/` (PhpStorm project files) is **not** ignored. Add it if you don't
  want it in the repo:
  ```
  echo ".idea/" >> .gitignore
  ```

### 4b. First commit & push
```bash
cd /Applications/XAMPP/xamppfiles/htdocs/goglobia

# (git already initialized on branch main; if not: git init -b main)
git add -A

# SANITY CHECK — these must print nothing:
git diff --cached --name-only | grep -E '(^|/)\.env$'
git diff --cached --name-only | grep -E '^updates\.json$'
git diff --cached --name-only | grep -E '^app/updates\.json$'

git commit -m "Initial import of goglobia application"

# create an EMPTY repo on GitHub first (no README), then:
git remote add origin https://github.com/your-username/your-repo.git
git push -u origin main
```

> If `git push` asks for a password, use a PAT as the password (GitHub removed
> password auth), or set up SSH and use the `git@github.com:...` remote.

### 4c. Shipping an update afterwards
Each **commit** is one installable update. Workflow:
```bash
# edit files in this working copy...
git add app/routes/whatever.php app/views/whatever.php
git commit -m "Fix X / add Y"     # commit MESSAGE shows in the GUI
git push
```
Then open `/updates` on the live site → the new commit appears under **New** →
**Install** (or **Install All**). Remember: installs are **oldest-first**, so if
you have several pending commits they install in chronological order.

### 4d. Making the installer respect your history
- `updates_start_date` (`updates-config.php`, currently `2026-08-19`) hides
  commits older than that date. Set it to the date of your FIRST update commit
  so your initial "import everything" commit doesn't try to install itself over
  the running site. Edit:
  `app/views/admin/updates-config.php` → `'updates_start_date' => 'YYYY-MM-DD'`.
- The installer's own fallback if it can't read that file is `2026-08-15`
  (`updates.php:206`) — keep `updates-config.php` present so your real date wins.
- `app/updates.json` tracks which SHAs are already installed on THIS site (it's
  gitignored, per-install). A fresh site starts with none installed.

---

## 5. Deletions & DB migrations through updates (know the seam)

- **Deletions:** commit that deletes a file → GitHub marks it `removed` →
  installer deletes it locally (`updates.php:443`). So you CAN remove files via
  updates.
- **DB migrations — IMPORTANT MISMATCH (verified):** the standalone installer
  only auto-runs SQL when a commit changes the path **`app/database/db.sql`**
  (`updates.php:530-532`). That file does **not** exist in this project — the real
  schema is **`install/db.sql`**, and the admin "Database Update" tool
  (`admin/updates/database`, `app/routes/admin/updatesRoutes.php:20`) is what
  diffs live DB vs `install/db.sql` via `getDatabaseSchemaDiff()`
  (`app/lib/functions.php:4913`).
  - **Consequence:** if you ship schema changes as `install/db.sql`, the `/updates`
    GUI copies the file but does NOT run the migration. You (or the site admin)
    must then open **Admin → Updates → Database Update** and apply it.
  - If you want the file installer itself to auto-run migrations, ship them as a
    file literally named `app/database/db.sql` (matching `updates.php:530`), OR
    change that path in `updates.php` to `install/db.sql`.

---

## 6. Security — do this before going live

The `/updates` installer is **public and unauthenticated** (`updates.php:375`
has no login/admin/CSRF). With your own repo wired in, anyone who can reach the
URL can trigger installs of your pending commits. Options to lock it down:

1. **HTTP auth / IP allow-list** on `/updates.php` via `.htaccess`, e.g.:
   ```apache
   <Files "updates.php">
     Require ip 1.2.3.4          # your admin IP(s)
   </Files>
   ```
2. **Protect your credentials JSON** (Option A): put it behind auth or an
   unguessable path, or serve it only to your server's IP. It contains a token.
3. **Least-privilege token** (§3): contents:read on one repo only, with expiry.
4. **Never expose the token in a public repo.** If you use Option B, keep this
   application repo **private**, or keep the token out of committed `updates.php`.
5. Keep `verify_ssl`/`CURLOPT_SSL_VERIFYPEER` on (default) so the creds/commits
   fetch can't be trivially MITM'd.

---

## 7. Quick checklist

- [ ] GitHub repo created (private recommended).
- [ ] Fine-grained PAT, contents:read, this repo only.
- [ ] Option A: `updates.json` hosted over HTTPS + URL changed in `updates.php:21`
      **and** `updates-config.php:32`. — OR — Option B: `upd_credentials()`
      hardcoded in both files.
- [ ] `updates_start_date` set to your first update date.
- [ ] `.idea/` added to `.gitignore` if unwanted.
- [ ] Sanity check: `.env`, `/updates.json`, `app/updates.json` are NOT staged.
- [ ] First push done; `/updates` lists your commits.
- [ ] `/updates.php` locked down (IP allow-list / auth).
- [ ] Decided how DB migrations flow (admin DB tool vs `app/database/db.sql`).

---

## 8. Exact files you touch

| Purpose | File | Line |
|---|---|---|
| File installer credentials URL (**the one that matters**) | `updates.php` | 21 |
| File installer credentials fetch logic | `updates.php` | 48 (`upd_credentials`) |
| GitHub API caller | `updates.php` | 72 (`upd_github`) |
| Commit list + start-date filter | `updates.php` | 210 (`upd_commit_list`) |
| Install endpoint (guardrails) | `updates.php` | 375 |
| Admin config credentials URL | `app/views/admin/updates-config.php` | 32 |
| Admin config credentials fetch | `app/views/admin/updates-config.php` | 40 (`v10_updates_credentials`) |
| Admin config returned repo/token keys | `app/views/admin/updates-config.php` | 96 / 97 |
| `updates_start_date` (`2026-08-19`), excluded files, options | `app/views/admin/updates-config.php` | 121 |
| Admin DB-migration route | `app/routes/admin/updatesRoutes.php` | 20 / 37 |
| Ignore rules (already secret-safe) | `.gitignore` | — |

> Note: `app/views/admin/updates.php` (~50 KB) is an OLD admin update GUI and is
> **dead code** — no route includes it. You do not need to touch it.
