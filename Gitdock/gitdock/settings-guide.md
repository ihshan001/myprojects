# GitDock — settings & configuration guide

Everything you can configure, what it actually does, and what to do when it misbehaves.
Two levels: **panel-wide settings** (the Settings dialog, top right) and **per-project settings**
(the tabs that appear once a project is open).

---

## 0. The map

| Where | What lives there |
|---|---|
| **Settings** (header button) | runtimes on this box · default commit identity · **GitHub token** · **Access** (accounts) · **Stored secrets (.env)** |
| **Files** | drag-and-drop upload, editor, bulk delete — each save is one commit |
| **Versions** | commit list, view any version, restore (as a new commit), download a version as zip |
| **Previews** | branch / tag / sha / PR sandboxes with their own ports and a TTL |
| **Setup** | how GitDock thinks the project runs · `gitdock.yaml` editor · **Apply** |
| **Env** | this project's `.env` (stored outside the repo, injected at service start) |
| **Database** | MySQL provision/import/export/query · SQLite upload/browse |
| **GitHub** | remote connect · pull · push · download zip · PR list · **per-project commit identity** |
| **Logs** | live output of every service of this project |

Rule of thumb: **Settings = things that apply to the whole panel. Tabs = things that belong to one project.**

---

## 1. Settings dialog, card by card

### 1.1 Runtimes on this box (read-only)

Shows what GitDock found on the machine: PHP version, MySQL reachability, sqlite3, git, node.

| You see | Meaning | What to do |
|---|---|---|
| `mysql connected` | MariaDB reachable on 127.0.0.1:3306 with the app account | nothing |
| `mysql unavailable` | the database server isn't running | in the sandbox: `bash bootstrap.sh` — it starts MariaDB and re-verifies the login over TCP |
| `sqlite not installed` | CLI missing | `bash bootstrap.sh` |
| PHP shows a version | PHP CLI is present | nothing |

The sandbox forgets installed packages when it restarts (your files, `.env` files and accounts survive).
**After a restart: `bash bootstrap.sh`, then start the panel.** That's the whole cold start.

### 1.2 Default commit identity

Two fields: **name** and **email**. This is the fallback author for commits you make through the panel.

How GitDock decides who authors a commit — first match wins:

1. **This project's identity** (GitHub tab → *save for this project*)
2. **Panel default** (this card)
3. **Your GitHub account**, if a token is connected → `<login>@users.noreply.github.com`
   (keeps your real address private — GitHub links the commit to you anyway)
4. Last resort: `GitDock <gitdock@localhost>`, shown as `(fallback)`

The line under the fields shows what would be used *right now* and which rule produced it.
Give both fields or neither; an invalid email is refused. Saved in `data/settings.json` (state, not secret).

**Why it matters:** a commit with no identity fails with `fatal: empty ident name` — that error used to
break editing a freshly cloned repo. If you ever see it, this card is the fix.

### 1.3 GitHub token

One field, three buttons: **Save**, **Clear**, and the status line ("not connected" / "connected as you").

| | |
|---|---|
| **What to paste** | a **fine-grained** token with *Contents: Read and write* (plus *Pull requests: Read* for the PR list), or a classic token with `repo` scope |
| **What GitDock does with it** | validates it by calling `GET /user` — a bad token is rejected immediately and **not** stored |
| **Where it lives** | **in the panel process's memory only** — never in `data/`, never in the repo, never in `.git/config` |
| **What it unlocks** | cloning **private** repos, `git pull` / `git push`, listing PRs |
| **Without it** | public repos clone fine; push to your own repos will fail with an authentication error |

**The one gotcha: the token is gone when the panel restarts.** That's deliberate — nothing on disk to
leak. Re-paste it after a sandbox restart (or when a private push suddenly fails with
`could not read Username`). Everything else survives restarts; the token doesn't.

It is never logged: GitDock scrubs the token from any output it shows or stores.

### 1.4 Access (who can open this panel)

The panel is on a public URL, so it is locked. There is **no signup screen** anywhere.

| Element | Meaning |
|---|---|
| badge `locked` / `open — no gate` | `open` means someone started the panel with `GITDOCK_AUTH=off`; restart without it to re-lock |
| the name chips | every account that exists. The `×` next to one removes it |
| **Add user** + role | creates an account; a password is generated and shown **once** — copy it and send it to the person |
| `member` | can use everything (files, git, services, databases) but **cannot** manage access |
| `owner` | additionally: add/remove people, "sign out everywhere" |
| **Change my password** | needs your current password; takes effect immediately, old one stops working |
| **Sign out everywhere** | rotates the session secret: every *other* device and teammate must sign in again. The device you press it on is handed a fresh session and stays signed in — otherwise you would be signing yourself out in order to sign yourself out |
| **Sign out** (top bar) | ends **this device's** session only, and revokes that token on the server straight away — the old token stops working even if someone replays it in the address bar. Other devices are untouched |

Accounts live in `data/auth.json`, mode 0600, passwords stored as scrypt hashes (never plaintext).
Sign-in is throttled: 6 failures per 15 minutes per user+IP, then 429.

**Locked out?** In the sandbox:

```bash
node app/lib/auth.js list             # who exists, when they last signed in
node app/lib/auth.js passwd admin     # prints a new password for admin
node app/lib/auth.js add-user nimal   # add someone without the panel
node app/lib/auth.js remove-user bob  # remove someone
node app/lib/auth.js rotate           # sign every device out
```

You cannot remove the last owner, and you cannot remove yourself from the panel — that's on purpose.

### 1.5 Stored secrets (.env)

Shows what the boot sweep did across all projects: how many were checked, how many `.env` files were
seeded from `.env.example`, how many were restored into Laravel app folders, and which projects have
**no** `.env` stored (a warning chip lists them).

**Re-check secrets now** re-runs the sweep immediately and prints what it did (checked, seeded, permission
fixes, Laravel `.env` restores). It only ever *creates* missing files and fixes permissions — it never
overwrites a `.env` you edited.

---

## 2. Per-project tabs

### 2.1 Env — this project's `.env`

| | |
|---|---|
| **Where it is stored** | `data/secrets/<slug>.env`, mode **0600** — outside the repo, so it can't be committed by accident |
| **How it reaches the app** | every service start injects it as environment variables (plus `PORT`, `APP_ENV`, `APP_URL`) |
| **The key list above the editor** | shows every key in the file; values of keys named `*PASSWORD* / *SECRET* / *KEY* / *TOKEN*` are **masked** in that list (the editor itself necessarily shows the real content — it's your panel) |
| **Missing-keys badge** | compares your `.env` with `.env.example` / `.env.sample` / `.env.dist` and names what's absent |
| **Save env** | writes the file. **Restart the service** afterwards — a running process keeps its old environment |
| **Laravel** | if the project is Laravel and `.env` is gitignored, GitDock also writes `.env` into the app folder (so `artisan`, cron and `tinker` see it too). If `.env` is *not* gitignored, it refuses — it will not be the reason a secret gets committed. `APP_KEY` is generated automatically if missing |

Typical Laravel values GitDock writes for you when you provision a MySQL database:

```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gitdock_<slug>
DB_USERNAME=gitdock
DB_PASSWORD=…            # per-project password
```

### 2.2 GitHub — connection and identity

**Connection** table: remote URL, host, branch, whether a token is in memory, and who commits are
authored as (with the rule that decided it).

**Commit identity** (this project only): name + email, *save for this project*, and a one-click
`<login>@users.noreply.github.com` button when a token is connected. Blank = fall back to the panel
default. This is what makes pushed commits land on *your* GitHub profile with the right avatar.

**Repository**: connect a remote, **Pull**, **Push**, *Download zip*. Output of the last git command
appears below. Local repos (a bare repo path) work the same as GitHub URLs. PR features need
`github.com` specifically; other hosts still pull and push.

**Pull requests**: lists open PRs of the connected repo (needs token + github.com). Each one can become
a preview — GitDock fetches `refs/pull/N/head`, exactly like GitHub does, and runs that revision on its
own port without touching your working tree.

### 2.3 Setup — how this project runs

**Detection card**
| Element | Meaning |
|---|---|
| runtime chip + docroot | what GitDock concluded (static / php / laravel / node) |
| `confidence` | high = unambiguous marker found, medium = a reasonable guess, low = almost nothing to go on |
| `why:` | every reason for the guess — never silent magic |
| ⚠ flags | things you must know (e.g. "index.html found in web/ — serving that folder", "Dockerfile ignored") |
| service table | id, runtime, folder (`path`), docroot — this is exactly what will run |

**`gitdock.yaml`** — the file that wins over detection. If present, the panel says
*"the manifest overrides detection"* and the plan comes from the file. **Save manifest (commit)**
commits it like any other edit; **insert example** puts a working skeleton in the editor.

```yaml
version: 1
project:
  name: my-app
  runtime: php@8.3
  docroot: .

install:
  - composer install --no-dev --optimize-autoloader

services:
  - id: website
    path: web            # folder this app runs from
    runtime: static      # static | php | laravel | node
    port: 8000           # optional, a free port is picked otherwise
    public: true
  - id: admin
    path: admin
    runtime: php
    docroot: public

databases:
  - id: main
    engine: mysql        # or sqlite
    import: dump.sql     # optional: imported on apply

preview:
  ttlHours: 72

env:
  defaults:
    APP_ENV: local
```

**Apply** — turns the plan into reality: creates the service definitions, provisions MySQL and writes
the `DB_*` keys into `.env`, applies env defaults, and (if ticked) **run install commands** (composer /
npm) and **start services**. The **Last applied** card keeps the exact report of what it did.

If a service in the manifest points at a folder that doesn't exist, the flag says so by name instead of
starting something broken.

### 2.4 Database

| | |
|---|---|
| **MySQL → database name + reachable dot** | the schema this project owns: `gitdock_<slug>` |
| **provision & inject** | creates the schema if needed, sets the project's DB password, and writes the `DB_*` keys into `.env` |
| **import .sql** | upload a dump (up to 256 MB) — this is the "upload my DB dump" workflow |
| **export .sql** | downloads a `mysqldump` of the schema |
| **table list → peek** | runs `SELECT * FROM … LIMIT 20` and shows the rows |
| **SQLite** | any `.sqlite` / `.db` / `.sqlite3` file in the project is detected and browsable; **upload .sqlite file** adds one. Query box takes plain SQL |

Pointing a Laravel app at a dump: *Database → import .sql*, confirm `DB_*` in **Env**, then restart the
**website** service (Run bar). WordPress: after importing, remember the home/siteurl rows still contain
the old host — GitDock flags this because the site will otherwise redirect away.

### 2.5 Previews

Field: **ref** (branch name, tag or commit sha — with a dropdown of known branches), **ttl (hours)**,
and *run install*. Creating one makes a throwaway git worktree (shares the repo's objects, so it's
instant), starts the project's services on fresh ports inside it, and shows the URLs. When the TTL
passes, the sweeper tears it down: process stopped, port verified closed, worktree removed. "tear down"
does it immediately. Your main working tree is never touched.

### 2.6 Logs

One button per service; shows the captured output of that service (including the `[gitdock] limits: …`
line that proves the resource caps are in force). Refresh to pull again. This is the first place to look
when a service starts but the page is blank.

---

## 3. Recipes

**Clone a private repo**
Settings → GitHub token → paste → Save (it shows "connected as you") → New project → clone → paste the
URL → Create.

**Make commits show up as you on GitHub**
Connect a token, then GitHub tab → *use `<login>@users.noreply.github.com`* → *save for this project*
(or set it once in Settings → Default commit identity for every project).

**Bring a dump and run it**
Open the project → **Database → import .sql** → check **Env** has `DB_*` → Run bar → start the service.

**Run two apps from one repo**
Setup → manifest with two `services:` entries (different `path`) → Apply. Each gets its own port and URL.

**Kick someone out / rotate everything**
Settings → Access → `×` next to their name (their next request is refused), or *Sign out everywhere*
when you want every device re-authenticated.

**I forgot the admin password**
`node app/lib/auth.js passwd admin` in the sandbox — prints a new one.

---

## 4. Gotchas worth remembering

| Gotcha | Why | What to do |
|---|---|---|
| GitHub token disappears after a panel restart | held in memory on purpose, never on disk | re-paste it; expect `could not read Username` if you forget |
| Env changes need a service restart | the process keeps the environment it started with | Run bar → stop → start |
| `mysql unavailable` after a sandbox restart | packages don't survive restarts | `bash bootstrap.sh` |
| Laravel `.env` not written into the app folder | `.env` isn't in `.gitignore` there | add `.env` to `.gitignore`; GitDock restores it on the next service start |
| Private-repo clone fails immediately | no token, token lacks *Contents*, **or** the credential helper lost its executable bit after a sandbox restart | Settings → token, retry. The helper's bit is repaired automatically at boot; if a clone still fails, the toast now quotes git's own last error line |
| Clone says "project not found" *after* a failure | a failed clone deletes its directory, and old builds hid the reason behind that | fixed: the job reports git's real error (`Repository not found`, `cannot exec …`, `Authentication failed`) and stays readable after the directory is gone |
| `failed to asynchronously prepare wasm` on a Node app | an address-space limit is too small for WebAssembly | don't set `limits.memMB` for Node/WASM services; the default has no memory cap, only process/file caps |
| Service shows a port but nothing responds | the process may still be booting | the Run bar now reports a service that **died** as failed, with the reason from its log |
| **Download zip shows a white screen** | the button used to navigate the preview frame at the API, and a navigation carries no session (fixed — an unauthenticated navigation now gets a page, never bare JSON) | click download again |
| Download says "ready" but no file appears | some embedded preview frames are forbidden by the browser from saving files, and a page cannot tell whether a save happened | the dialog that appears gives you a **short-lived link** (5 minutes, no sign-in). Press **Copy link**, paste it into a normal tab, and the file downloads there. Or press **Open panel in its own tab** (button in the header) and download normally from then on |
| The downloaded zip has no `node_modules`, no `db` file, no `.env` | it is `git archive` — a snapshot of the **committed** code, which is what you want for a deploy or a backup | for data, use **Database → export .sql** (MySQL) or download the SQLite file from the SQLite card; env files live in `data/secrets/` and are deliberately not in the zip |
| A cross-origin (iframe) `fetch` fails | the API now answers CORS for opaque origins, authenticating by token header only | make sure you are signed in inside the preview; a preflight is never asked to authenticate |
| "I pressed stop but the site is still serving" | Node services start through `npm run start`, and npm's child kept running (fixed) | stop now kills the whole process group; leftovers from a hard restart are reclaimed at boot by port, and the log says `reclaimed N orphaned service process(es)` |
| A clone's services are missing right after it finishes | the clone job writes service definitions *after* the code lands | wait for the job's toast/`done` note (the UI polls it), then the Run bar is populated |
| PR list empty but PRs exist | not github.com, no token, or remote isn't connected | GitHub tab → connect remote, add token |
| `open — no gate` in Access | panel started with `GITDOCK_AUTH=off` | restart the panel without it |
| Service starts but port shows "not responding" | the app crashed on boot (bad `DB_*`, missing vendor) | Logs tab — the traceback is there |

---

## 5. Where things are stored (so backups make sense)

| Path | Contents | Secret? |
|---|---|---|
| `data/projects/<slug>/` | the actual repo working tree (one repo per project) | no (except any `.env` the app writes, which is gitignored) |
| `data/secrets/<slug>.env` | the environment GitDock injects | **yes**, mode 0600 |
| `data/auth.json` | accounts (scrypt hashes) + session secret | **yes**, mode 0600 |
| `data/settings.json` | default commit identity | no |
| `data/mysql.json` | the app's MySQL account | **yes**, mode 0600 |
| `data/previews.json`, `data/jobs/`, `data/logs/` | preview records, job logs, service logs | may contain secrets in output — treat as sensitive |
| `data/FIRST-LOGIN.txt` | first owner password | **yes** — change the password and you can delete this file |

Backing up `data/` means backing up everything: code, secrets, accounts, databases (MySQL lives outside
`data/` — use the per-project **export .sql**).
