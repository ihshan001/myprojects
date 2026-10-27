# GitDock — how to work this system

Companion to `README-poc.md`. Written for the sandbox you're using right now.
Everything below was executed and timed against the running panel while writing it.

---

## 0. "Will it work without any issues?" — the honest answer

**Yes for what it does, in this sandbox, right now.** 31/31 backend checks pass, and a project goes from
nothing to a live URL in **550 ms** (measured). But "no issues" depends on which issues:

| | Status |
|---|---|
| 🟢 **Works, verified** | projects, folder/ZIP bulk upload → one commit, file editing, bulk delete, version history + rollback + per-version zip, `gitdock.yaml` manifest + auto-detection, services on real ports (static/PHP/Laravel/Node), MySQL provisioning/import/export/query, SQLite browsing, `.env` injection with masking, git clone/push/pull, PR list, PR/branch previews with auto-teardown, delete-with-cleanup, orphan reclamation after a crash |
| 🟡 **Expected behaviour to know about** | services stop when the panel restarts (deliberate — no orphans) and get **fresh ports**; the GitHub token is **in memory only**, so re-enter it after a restart; system packages (PHP/MariaDB) don't survive a snapshot and need `bootstrap.sh` again — app code and `data/` do survive |
| 🔴 **Real limitations — do not ignore** | **no login**: anyone with the panel URL can use it *and* read the API, so don't paste production secrets yet. **No container isolation**: project code runs as the panel user, so only run code you trust. **2 vCPU / 2 GB**: one composer install at a time, Laravel is ~72 MB per project. **Deleting a project deletes its `.git` too** — connect a GitHub remote if you want an offsite copy. **Last-write-wins** if two browsers edit the same file |
| ⛔ **Not built yet** | authentication/roles, webhook-triggered previews, WordPress DB URL rewriting, containers/cgroup limits, worker & cron services |

So: it's a **working prototype with a proven backend**, not a production service. Treat it as "I can finally do GitHub + run projects without a terminal", not "host my client's live site on it".

---

## 0a. "The panel went quiet" — a sandbox that forgets its runtimes

This environment is ephemeral: a snapshot restores your files but **not** installed programs, and not the
MariaDB data directory in `/var/lib/mysql`. A fresh session can therefore have no `php`, no `sqlite3`, no
`mysql` client and no Node modules — while `data/` still looks normal.

Recover in one command:

```bash
bash /home/user/gitdock/bootstrap.sh      # reinstalls php/sqlite/mariadb/composer, starts MariaDB,
                                          # reconciles the gitdock MySQL account, then re-checks it
cd /home/user/gitdock && npm install      # only needed for the test suites (puppeteer + Chrome libs)
```

The panel itself needs **no** npm modules. It also no longer dies on a missing runtime: before this was
fixed, a missing `mysql` client took the whole process down mid-request (the Run bar and every page simply
stopped answering). Now a service that cannot start, a dump that cannot be imported or a database that
cannot be provisioned reports **what is missing** and the panel keeps serving. `bootstrap.sh` ends with a
check that really creates, uses and drops a schema, so 0a cannot pass silently.

---

## 0b. Signing in (the panel is locked — you and your team only)

There is no signup page. Accounts exist because you created them.

- **First time:** the password was printed once when the panel first started, and it is saved in
  `data/FIRST-LOGIN.txt` (user `admin`). Sign in with it, then go to **Settings → Access → Change my
  password** straight away.
- **Adding your team:** Settings → Access → *Add user* (name + role). A password is generated and
  shown **once** — copy it and send it to that person. `member` can use everything but cannot change
  who has access; `owner` can.
- **Someone leaves:** Settings → Access → the `×` next to their name. Their session stops working at
  that moment — a removed account cannot keep using a token it already had.
- **Signing out:** the **Sign out** button in the top bar ends *this* device's session and revokes its
  token immediately — it cannot be replayed, not even by pasting the old URL back in. Your other devices,
  and your team, are unaffected. **Sign out everywhere** (Settings) is the bigger hammer: it invalidates
  every *other* session at once and keeps the device you pressed it on signed in.
- **Lost the password / locked out:** in the sandbox run
  `node app/lib/auth.js passwd admin` — it prints a fresh password. `node app/lib/auth.js list` shows
  who exists; `rotate` signs every device out.
- **On a phone or Chromebook:** open the panel URL, sign in, and the session is remembered. Inside the
  Arena preview the session is handed over in the URL once and then stripped from the address bar.
- If you ever see `open — no gate` in Settings, someone started the panel with `GITDOCK_AUTH=off`.
  Restart it without that variable and the gate is back.

## 1. Cold start (after a snapshot or a new session)

You don't run shell commands here — **ask me** and I'll do it. Say:

> "restart the GitDock stack"

I run, in order: `bootstrap.sh` (PHP, MariaDB, sqlite3, Composer — idempotent, ~20 s) → `node app/server.js` → optionally `start all` for your projects. Then the live preview on port 3000 is yours again.

Manually, that's exactly:

```bash
bash /home/user/gitdock/bootstrap.sh
node /home/user/gitdock/app/server.js
```

**What survives a snapshot:** everything in `data/` (your projects, git history, `.env` files, MySQL credentials) and all app code.
**What doesn't:** installed system packages, running processes, and the MySQL server itself — hence the bootstrap.

State right now: panel up, **zero projects** (nothing is seeded), no services running, MySQL ready.

---

## 2. Guided tour — nothing to a live URL in about a minute

The panel looks like a small operating system: a **launcher** on the left (one icon per workspace), your
**projects** beside it, and a window with tabs for the one you have open. The top bar shows the repo and the
branch you are on; the bottom bar is a live status line (branch, ahead/behind, uncommitted files, remote,
signed-in account).

Open the panel preview (**port 3000**). You'll see the empty state and 8 tabs once a project exists.

**Step 1 — create a project.** Click **+ New project**. Three templates plus a clone option:

| Choice | What happens |
|---|---|
| `static` | instant: `index.html` + a git repo |
| `php` | instant: `index.php` showing PHP version and loaded extensions |
| `laravel` | runs `composer create-project` in the background (~1–2 min, streamed log) — migrations run, `APP_KEY` generated |
| `clone` | paste any git URL (GitHub, GitLab, a local path) → GitDock clones it, detects the runtime, and prepares its services |

Type `walkthrough demo`, pick `static`, click **Create**. *(Measured: 48 ms.)*

**Step 2 — look at the Setup tab.** GitDock tells you what it thinks: `runtime: static · docroot . · confidence: high`, with the reason (`index.html present`). Nothing is silent — everything it guesses is shown and overridable. *(29 ms.)*

**Step 3 — apply it and start.** In **Setup**, tick *start services* → **Apply**. Or just use the **Run bar** at the top-right: press **▶ start all**. Whichever service is defined gets its own port. *(443 ms.)*

**Step 4 — open it.** The Run bar shows e.g. `● website :8100  open ↗`. Click **open ↗** — a real URL renders your site. *(First response: 30 ms. Total from an empty panel to a live URL: 550 ms.)*

That's the whole loop: **project → apply → start → link**. Everything after this is depth.

---

## 3. The daily loop

### Upload a whole folder (your original pain point)
**Files tab** → drag a folder onto the drop zone (or *Choose folder* / *Choose .zip*). The browser walks the tree,
uploads every file, then makes **one** commit — `Upload 142 file(s) (8.3 MB)`. Skipped automatically:
`node_modules/`, `vendor/`, `.git/`, `.env`, `*.sql`, `*.zip`, OS junk. Progress bar + per-file counter while it runs.

**Choose .zip…** unpacks the archive **in your browser** and uploads what is inside it — a zip is treated as a
box to unpack, not as a file to store, so the `.zip` itself never reaches the repo (GitHub blocks files over
100 MB anyway). Dropping a single file onto the drop zone works too. `.env` files go through the **Env** tab and
`.sql` dumps through the **Database** tab — those two are secrets/data, not code.

### Edit without a terminal
Click any file in the tree → edit in the pane → **Save & commit**. One file, one commit. Reload the preview to see it.
*(Verified: edit → served live in ~1 s.)*

### When a click does nothing, or a toast says something is "not defined"

That is a wiring bug in the panel's own script — a function called from a button but never written.
Re-running the static audit names it exactly, with the line number:

```bash
cd /home/user/gitdock
node scripts/lint-ui.js      # "calls with no definition" / "selectors with no element"
```

It parses the panel with a real JS parser, so it is not fooled by a function name appearing inside a
string or a comment. The UI suite runs the same audit on every pass, and also presses every dock icon,
tab and quick-action entry, so this class of bug fails the build rather than reaching you as a toast.

### If a page looks cut off

It should not be — the layout is checked at 390×640, 844×390 and 1024×600 by the UI suite — but if you
do see it: the content area scrolls on its own, so scroll **inside the window**, not the browser page.
On a very short window (a phone in landscape, or a small browser window) the panel switches to normal
page scrolling on purpose, so there the whole page scrolls. If a control is genuinely unreachable, that
is a bug worth reporting with the device size.

### The manual is inside the panel

There is a **How to** tab (last tab, and a `? How to` button in the menu bar that works even with no
project open). It has one section per area of GitDock — what that area is for, the steps in order, and
the mistake people usually make there — plus the GitHub token permission list quoted exactly as
GitHub labels it. If you or a teammate do not know what to do next, that page is the answer, and its
filter box takes the word you are stuck on (`token`, `port`, `undo`, `drop`, `log`).

### A private repo that will not clone

The dialog now answers this **before** it creates anything: paste the repository URL and GitDock asks
GitHub whether your token can read it. If it cannot, the dialog stays open with the reason and nothing is
created. The two causes, and their fixes on github.com:

- **Fine-grained token** (starts `github_pat_`): it reaches only the repositories you tick. Open the
  token → *Repository access* → **Selected repositories** → add this repo → *Permissions → Repository
  contents:* **Read** (Read and write if you push). Clone only needs read.
- **Classic token** (starts `ghp_`): needs the **`repo`** scope to see private repositories.

Then paste the token again in **Settings → GitHub** (it lives in server memory only, so it also has to be
pasted again after the panel restarts). A 403 that says *"Write access to repository not granted"* is
GitHub telling you the token is fine but not allowed *for that repository* — the job log says so in those
words so nobody goes hunting for a network problem.

### Download just one folder

Every folder row in the tree has a small **⬇** on the right. Click it and that folder comes down as a zip:
its own name and structure kept, subfolders and all — `dashboard/` gives you `dashboard.zip` containing
`dashboard/pages/home.php`, `dashboard/inc/db.php` and so on. Nothing else in the project is included.

The zip contains **committed** files, which is exactly what you want for code: `node_modules`, `vendor`,
`.env`, `*.sql` and database files are git-ignored, so they are never in a download. If a folder holds
*only* ignored files, GitDock says so instead of handing you an empty zip. Single files still come from the
**⬇ file** button in the editor, and the whole project from **Download zip**.

### Delete in bulk
**Files → select** → tick files/folders → **delete (n)** → confirm. One commit, history intact.

### Go back in time
**Versions** tab → the commit list. Per commit you get:
- **files** — what was in that version (plus the diffstat)
- **download** — that exact version as a `.zip`
- **restore** — makes the working tree match that version and **commits it as a new change** (nothing is destroyed; you can restore your way forward again)

Watch out: rolling code back does **not** roll the database back. Export the DB first if migrations moved on — the confirmation dialog says so.

---

## 3b. Finding your way around the panel

The panel explains itself **on demand** — nothing is narrated at you while you work.

- **ⓘ** — anywhere you see one, the reasoning behind that card/row is one click away (GitHub token scope,
  where the `.env` lives, commit identity, what a branch button will do). Click outside and it goes away.
- **Code / Preview** — clicking a file opens it in **Code**. The **Preview** button beside it renders the same
  file: HTML in a sandboxed frame (the project's own CSS, JS and images are pulled in first, so a page looks the
  way it will look live), markdown as readable markdown with tables, CSV/TSV as a grid, images directly, JSON
  pretty-printed. A file that cannot be edited (binary, too large) shows its preview and offers **⬇ file**.
- **Nested tree** — folders show how many files sit inside them. Click a folder to fold or unfold just that one,
  or use **expand** / **collapse** to do the whole tree at once. The moment you fold one folder by hand, the
  tree follows your clicks instead of the global setting.
- **Scrolls** — long content keeps itself in its own box: env values, MySQL table lists, query results, SQLite
  cards, versions, logs, branch lists. The tab itself stays put, and the tree/editor panes are height-capped.
- **Projects** — the ▦ button in the title bar lists your projects (runtime · services · last commit) and holds
  *+ New project*. Picking one opens it and closes the list; the permanent sidebar that used to eat a quarter of
  the screen is gone.
- **Deleting a project** asks you to type its name first, and tells you exactly what it removes.

---

## 4. Databases

**Database tab** is split in two.

**MySQL (per project):**
- **provision & inject** → creates a schema named `gitdock_<project>` and writes `DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD` into the project's `.env`, then restart the service to pick them up.
- **import .sql** → drop the dump you already have; tables are listed when it finishes.
- **peek** on any table, **export .sql** to get a `mysqldump` back.
- Read-only SQL is allowed through the API (`SELECT…` only) — handy for checking a hunch.

**SQLite:** drop a `.sqlite`/`.db` file anywhere in the project — the tab detects it, lists tables, and lets you browse rows. A bare `php` project plus an SQLite file is the fastest way to run a real dynamic app.

**Env tab:** paste your `.env`; it lives **outside** the repo at `data/secrets/<slug>.env` with mode `600`, is injected when a service starts, is masked in API responses (`PAS••••••`), and GitDock flags any key in `.env.example` you haven't set. Missing `APP_KEY` for Laravel is generated for you.

---

## 5. GitHub

**Settings** (top right) → paste a token with `repo` scope → **Save**. It's verified against the API immediately and held **in process memory only — never written to disk**. It disappears when the panel restarts.

Then **GitHub tab**:
- **connect** a remote (any git URL; PR features need github.com)
- **Pull** — fetches and merges, with real error messages (auth failure vs conflicts vs unrelated histories; a conflicting merge is aborted so your tree stays clean)
- **Push** — no token demanded unless the remote actually asks for credentials
- **Pull requests** — number, title, author, branch, state, link

### If you want to push your files to a repo that already exists on GitHub

There are two ways to start, and they are **not** interchangeable:

| what you do in **+ New project** | what you get |
|---|---|
| tick **clone** → paste `https://github.com/owner/repo.git` | a project that *is* that repo: real history, `origin` already connected, **Push just works** |
| leave the template on *static* and paste the URL into **Project name** | a blank project whose *name* is the URL and whose **remote is empty** — Push has nowhere to go, Pull fails with *unrelated histories* |

Made the second mistake? Either paste the URL into **GitHub tab → Repository → connect** and **Pull** before
**Push**, or — cleaner, since the project is still empty — delete it and re-create it with **clone** ticked.
GitDock cannot create a repository on GitHub: if the repo does not exist yet, make it on github.com first
(empty, no README), then connect and push.

---

## 5b. Branches, merges and pull requests

The panel is laid out like a small desktop: a **launcher** down the left edge (Code, Branches, Pulls,
Versions, Preview, Data, Env, GitHub, Setup, Logs), the project list next to it, and a window with tabs.
The branch you are on is always in two places: the chip in the top bar (click it to switch or create) and
the status bar at the bottom (`⎇ branch · ↑2 ↓1 vs main · clean · remote · account`).

### Work in a branch

1. **Branches** → type a name (`feature/search`) → **Create & switch**. You are on it immediately.
2. Edit files in **Code** — every save is a commit, on that branch.
3. **Branches** shows every branch with how far it is from its upstream *or* from the base branch
   (`↑1 vs main`), which are the commits a pull request would contain.

### Merge it yourself

On the **Branches** tab, any branch that is not the current one has **merge into `<current>`**. GitDock makes
a real merge commit. If the two branches touch the same lines it **aborts the merge**, tells you which files
conflict, and leaves your tree exactly as it was — you then fix the files on the branch you want to keep and
merge again. Merging something already contained says *"already up to date"* instead of pretending.

### Undo a merge

Every merge is listed under **Recent merges** (Branches) and marked `merge` in **Versions**. Press
**undo merge** and GitDock adds a new commit that reverses it (`git revert -m 1`) — exactly what GitHub's
Revert button does. The merge itself stays in history forever, so nothing is rewritten and nothing is lost.
A conflict during the undo changes nothing at all.

### Open a pull request

1. **Pull requests** → the form at the top: **from** your branch, **into** the base branch (`main`),
   a title (prefilled from your last commit) and an optional description.
2. **Push branch & open pull request** — the branch is pushed to GitHub first, then the PR is created on it.
3. The list below shows every PR with state, files changed, and buttons: **open on GitHub**, **merge**
   (choose merge / squash / rebase — GitDock then fetches and fast-forwards your local base branch, so your
   copy matches GitHub), **close**, and **undo merge** once it is merged.

Needs: a `github.com` remote and a token in **Settings** (repo scope). Without them the tab says exactly what
is missing rather than failing silently. GitDock never force-pushes and never rewrites history.

### Quick actions

Press **Ctrl-K** (⌘-K on a Mac) anywhere in the panel: new branch, open/merge a pull request, commit
outstanding changes, push, pull, download the project zip, start all services, toggle the theme.

---

## 6. Previews — branch and PR sandboxes

**Previews tab.** Type a branch/tag/sha (or use the dropdown) and press **Create preview**:

- GitDock makes a **git worktree** — instant, shares the repo's objects, and **your working tree is never touched**.
- Its services start on **their own ports** and links appear per service.
- **ttl (hours)** decides when it self-destructs; a sweeper tears it down automatically (worktree + ports + processes).
- **tear down** does it immediately.

**PR previews:** in the same tab, the PR list has **preview this PR** — it fetches `refs/pull/<n>/head` the way GitHub publishes it, runs the PR revision on its own port, and leaves your `main` untouched. That's the "show me this branch running" workflow, working.

---

## 7. Troubleshooting

| Symptom | What's happening | Fix |
|---|---|---|
| Panel preview won't load | The Node process died with the sandbox/session | Ask me: *"restart the GitDock stack"* |
| `open ↗` shows an error page | That service isn't running (or restarted onto a new port) | Run bar → **▶ start all** |
| Everything 502s after a break | Snapshot wiped PHP/MariaDB | Ask me to run `bootstrap.sh` (idempotent) |
| "set a GitHub token in Settings first" | Token is per-process memory and the panel restarted | Settings → paste token again |
| Clone or composer fails | Outbound network blocked, or private repo without a token | Add the token; check the job log in Setup |
| MySQL import fails | Dump too large for 2 GB, or `DEFINER=` clauses from another server | Split the dump; strip `DEFINER` lines |
| Laravel white screen | Usually `APP_KEY` or `storage/` permissions | Logs tab; `APP_KEY` is auto-generated — press start again |
| Two services fighting for a port | Ports are allocated 8100–8399 at start; a restart reallocates | Restart the panel; pin ports in `gitdock.yaml` if it matters |
| A preview vanished | Its TTL expired (sweeper runs every 5 s in this demo instance; 60 s in production) | Create it with a longer TTL |
| Uploaded 3,000 files feels slow | Browser uploads file-by-file | Use a **.zip** — it's inflated in-browser then uploaded |

---

## 7b. If the panel shows an error bar or "Boot error"

The panel now tells you where it broke (red bar under the header: `UI error in <step>: <message>`).
Press **↻** in the sidebar; if it persists, the two test suites below will pinpoint it in seconds. It never
fails silently any more — a broken step names itself, and one bad project can't take down the whole panel.

---

## 7d. Downloads (zip, single files)

In a normal browser tab: click download, the file saves. Nothing else to know.

Inside the embedded preview: some frames are forbidden by the browser from saving files at all. When
that happens the panel opens a dialog with a **short-lived download link**:

1. press **Copy link** (or select the text and copy it),
2. paste it into a new browser tab and press Enter — the file downloads there,
3. the link is good for 5 minutes, needs no sign-in, and only ever points at that one file or version.

Better still, press **↗ own tab** in the header: the panel opens as a normal website (it carries a
one-time sign-in), and from then on downloads, folder drag-and-drop and uploads all behave normally.
If your browser refuses to open the tab (embedded frames are often not allowed to), the panel does not
just do nothing — it shows the address in a box with a **Copy link** button: paste that into a new tab
and you are signed in. That is the way to work for anything long: the embedded frame is for looking, a
real tab is for doing.

The success message is only shown **after the bytes are in your download folder** — if the saving step
fails, you get a red message naming the reason instead of a cheerful toast.

Two things people mistake for bugs:
- the zip contains the **committed** code only — no `node_modules`, no database file, no `.env`
  (that is what `git archive` does, and it is what you want for a deploy or a backup);
- the toast says the download is *ready*, because the file is assembled in your browser — a very large
  project takes a second or two before the save dialog appears.

## 7c. "It says a project is still there after deleting it"

Deleting is now logged and self-checking. In the panel log you get `deleted <slug>`; if anything
recreates the folder you also get `WARNING: <slug> reappeared … after deletion` three seconds later.
That wording points at a job that was still running (a clone or composer install survives a delete
only long enough to be cancelled — it must not recreate anything).

## 8. Verifying it yourself

```bash
node /home/user/gitdock/scripts/selftest.js    # backend: 67/67 · ~32 s
node /home/user/gitdock/scripts/uitest.js      # UI in headless Chrome: 44/44 · ~35 s
```

Both suites sign in with a throwaway account they create through the real CLI and delete afterwards —
so they work against a locked panel, without weakening it. The backend suite also asserts the panel
process is the same one it started with (a killed job once took the whole panel down mid-run).

The backend suite creates its own project, exercises every API behaviour over HTTP, then verifies the results on
disk, in git, in MySQL and by fetching live ports — and removes every trace. The UI suite drives the real panel in
a browser, clicking the actual buttons, and fails on any JavaScript error or failed request. Ask me to run either
any time.

---

## 9. What to use it for today vs. what to wait for

**Use it now for:** moving code between your Chromebook/phone and GitHub without a terminal; running static/PHP/Laravel projects with a real database and a shareable link; previewing branches and PRs; bulk-uploading folders and CMS files; checking any past version.

**Wait for the next milestone before:** pointing a real domain at it, running code you don't trust, or storing production credentials — auth is in place, but isolated runtimes are not: services currently share this sandbox (capped by rlimits) instead of living in their own containers. That, plus scheduled backups, is the next milestone (plan in `blueprint.md` §6 and `hosting-and-access.md` §4 — and it belongs on a real box: Oracle Always Free / Hetzner ~€4/mo / mini PC — never InfinityFree).

### File actions and branch-aware uploads

- Delete labels are white, including the small tree buttons.
- Right-click a file/folder, or tap its **⋯** button, for Open (files), Download and Delete. Delete asks for confirmation; folders include all descendants.
- **⬆ files**, **⬆ folder**, **⬆ zip** and drag-and-drop all ask for a destination before uploading: an existing local branch (including main when present), or a named new branch with a chosen starting branch. Cancel uploads nothing.
- Code now has **Pull**, **Push** and **Merge** shortcuts. Pull and Push explain the current branch before confirmation. Merge lets you choose **From → Into**, then review before changing branches or merging. Push separately to publish.
- Tracked content can be recovered from Versions. Uncommitted and ignored deleted content cannot.

### File-first workspace and faster uploads

The Files tab is the starting point. **Get updates** pulls from GitHub; **Publish** pushes the current branch. Both explain the branch before confirmation. Branches, Versions, and application tools remain available in the labelled navigation.

Uploads now group small files into batches of at most 100 files / roughly 4 MB, with up to three requests in flight. Larger files use individual requests. This removes much of the per-file latency without flooding the connection. The progress display shows bytes transferred, average transfer rate, and an estimated time remaining. One commit is made after all requests succeed. On failure, in-flight requests settle, no new batches begin, and partial uploads are reported rather than silently rolled back.

Internet speed and the Arena preview proxy still determine the achievable rate. A local 18 MB / 360-file test needed five upload requests instead of 360; that is not an internet-speed benchmark. ZIP extraction still happens in your browser before transfer.
