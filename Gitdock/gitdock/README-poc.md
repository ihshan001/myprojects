# GitDock — backend prototype (running in this sandbox)

**Status: empty panel, no mockup data, backend verified by a 31-check self-test that is ALL GREEN.**

The panel starts with **zero projects**, zero databases, zero services. Nothing is seeded or faked.
Every project you create is a real git repository, every service is a real process on a real port, and
every database is a real MySQL schema or SQLite file.

```
gitdock/
├── app/
│   ├── server.js                  control plane: HTTP + git + services + databases + previews + GitHub
│   ├── lib/manifest.js            gitdock.yaml parser + the project detection ladder
│   ├── lib/static-server.js       static-site runtime
│   ├── lib/askpass.sh             git credential helper (token never touches disk)
│   └── public/index.html          the UI (single file, 8 tabs)
├── scripts/
│   ├── selftest.js                backend acceptance test — creates and destroys its own project
│   ├── uitest.js                  headless-browser test — drives the real panel and fails on any JS error
│   └── optional/demo-fixtures.js  optional demo content (not part of the system; run it only if you want examples)
├── data/                          projects/ previews/ logs/ jobs/ secrets/ mysql.json  ← empty by design
├── bootstrap.sh                   reinstalls PHP/MariaDB/sqlite3/Composer + starts the DB (idempotent)
└── README-poc.md                  this file
```

---

## 1. Proof the backend works (not a frontend shell)

```bash
node scripts/selftest.js        # ~12 s, exits 0 when everything passes
```

Last run: **67/67 checks passed, ALL GREEN.** (plus 46/46 in the UI suite)

### 1a. Fixed after the "Boot error: Cannot read properties of undefined (reading 'meta')" report

That error was real and it broke **every** panel load. My API suite passed while the UI was dead — the gap that
mattered, now closed by `uitest.js` below.

| Bug | Cause | Fix |
|---|---|---|
| `project.meta` undefined at boot | the API returned `{ slug, meta, identity, services }` but the client destructured `{ project, services }` and read `project.meta.name` | the API now also returns a canonical `project` object, **and** the client normalizes the payload in one function (`normalizeProject`) so the two shapes cannot drift apart silently again |
| silent death | a UI exception left a half-dead panel with one transient toast | a red **error bar** naming the failing step, plus the exact stack in the console; one bad project can no longer break boot or a whole tab |
| `Node is detached from document` | starting/stopping a service re-rendered the entire tab, so clicks hit stale DOM | start/stop now refresh **only the run bar** — file tree, editor and scroll position survive |
| the branch list showed one absurd entry like `main%x1fcc257c4%x1f3 minutes ago%x1f…` | `git for-each-ref --format` does **not** expand `%x1f` the way `git log --pretty=format` does (git 2.47 prints the four characters literally), so the fields never split | the separator is a real `0x1f` byte in the format string, with a comment so nobody "tidies" it back |
| "delete branch" on a branch with unique commits looked like a crash | the refusal answered `200 {ok:false, needsForce:true}` — and both the panel's and the test suite's API helper treat `ok:false` as a failure, so the reason never reached the user | refusals are real HTTP codes: `409` + `needsForce`, which the UI turns into a "those commits only exist here — sure?" dialog |
| a branch three commits *behind* main was labelled `= main` | the badge only looked at `ahead`, so "nothing to push" quietly meant "you are behind" | both directions are shown (`↑n ↓n vs main`), because a badge that hides being behind is how people lose work |
| **Sign out**, and every button on the Settings **Access** card, did nothing at all | the OS-shell rewrite rendered the buttons but wired none of them, and the tests never clicked them — a button that is merely *there* looks identical to a button that works | every one is wired (add user, remove user, change password, sign out everywhere, re-check secrets) **and** the logout route now truly revokes the session: it used to clear a cookie and leave the token valid, so a signed-out token still worked if replayed from a URL. Three new UI checks drive all of it, including that a removed teammate and a signed-out token are both refused |
| the error bar rendered unstyled, in the middle of the page instead of stuck to the top | the CSS selector was `#errbar`, the element's id was `errBar` — CSS is case-sensitive, the JS was right and the stylesheet was wrong | selector fixed, so a failure now actually appears where it can be seen |
| after opening a project the menubar chip and status bar still said `⎇ —` | `openProject()` is the boot path and does not go through `refreshAll()`, where the chrome refresh had been attached | the chrome refresh is called from `openProject()` too, and a UI check asserts chip/status bar agree with git |
| **"Download zip" reported success but no file arrived** (13.5 MB archive) | Chromium will not hand the body of a `Content-Disposition: attachment` response to `fetch()`: the request returns **200 with correct headers**, then reading the body dies with `net::ERR_FAILED` / `TypeError: Failed to fetch`. `curl` and Node `fetch` read the same URL fine, and small zips squeaked through — so the toast said "ready" while nothing was saved | downloads read their bytes with **XMLHttpRequest** instead of `fetch()`. XHR delivers the same response in full (13,568,267 bytes verified on disk, byte-for-byte with `content-length`) | 
| the download test passed on a file that was never the project | the check clicked the *first* `[data-dl]` button on the Versions tab and only asserted "≥ 500 bytes"; while the tab was re-fetching, that button still belonged to the previous render (an initial commit, 524 bytes) | the test now uploads an incompressible **12 MB** file, waits for the history row to show *that* commit's sha, and rejects any zip under 11 MB — plus tab switches blank the panel first, so stale buttons cannot be clicked |
| stray `404 /favicon.ico` | no favicon | inline SVG data-URI (no request at all) |
| deleting a project mid-job left a **zombie directory** | a running clone/composer job kept writing after the project was deleted | jobs now carry their project, are **cancelled on delete**, and abort before every write (`jobAborted`) — new self-test check `deleting a project mid-job leaves no zombie directory` |
| `Cannot set properties of null (setting 'onclick')` | handler wiring assumed every element is on the page; a project being deleted (or a tab not rendered yet) leaves those nodes missing | every handler now goes through **`on(sel, fn)`**, which skips and says which selector was missing — the error class is gone, not just this instance |
| **the panel process died mid-suite** (the real cause of the "leftover project" mystery) | a job killed by `cancelJobsFor` still emitted output after its log stream was closed (`ERR_STREAM_WRITE_AFTER_END`); an unhandled stream `error` event kills Node | job and service logs go through guarded writers, and every stream has an `error` handler. A dead panel mid-cleanup was exactly why deletes "left projects behind" — the suite was deleting against a corpse. New check: *the panel process survived the whole suite* |
| **"I clicked Download zip and got a white screen"** (reported) | the button was a plain `<a href>`, so the browser *navigated* the preview iframe at the API. A navigation carries no session header and third-party cookies are blocked, so the gate answered 401 — and the iframe rendered that bare JSON, wiping the whole panel | downloads are now fetched with the session and saved as a blob; if the sandboxed frame blocks that, the panel opens the URL in a new tab with `?t=<token>` (accepted from the URL, stripped afterwards), and if even that fails it says so instead of pretending. An unauthenticated **link navigation** now returns an HTML page ("Please sign in again"), never bare JSON. New checks: preflight/CORS, URL-token download, HTML-not-JSON, and a UI check that clicks the real button and asserts a zip lands on disk |
| **"Download ready … if nothing arrived, open the panel in its own tab"** — a disclaimer, not a fix | a sandboxed preview frame may block downloads entirely (no `allow-downloads`), block `localStorage` and block popups. Nothing in that frame can save a file, and we had no way to tell whether the save happened | downloads are now honest: outside a frame they save via blob and say so; inside a frame the panel shows a **short-lived link** (`/api/dl/<key>`, no session needed, 60–3600 s TTL, scoped to one project+ref, minted only with a session) with Copy / Open-in-a-new-tab buttons and the panel's own URL. Pasting that link into a normal tab always works, and no session token ever sits in a URL. UI check drives a real `sandbox="allow-scripts"` frame: no storage, no cookies, no popups — panel authenticates, offers the link, and the link is fetched from Node with no session and yields a real zip |
| the session vanished in a frame where `localStorage` throws | the token lived only in storage or the URL, and the panel strips the URL token after reading it — so the next request would be unauthenticated and bounce to the sign-in page mid-use | the token is now held in memory as well (`sessionToken()` keeps it in a variable), so it survives both a stripped URL and unavailable storage |
| CORS: a sandboxed iframe's `fetch()` never reached the API | an opaque-origin frame makes every call cross-origin; without `Access-Control-Allow-Origin` the browser blocks the response before the session check | the API answers CORS for opaque/header origins and authenticates by **token header only** (no cookie is cross-origin), so a preflight needs no session and a random page still gets 401. The preflight must call `res.end()` — my first version hung the browser until timeout |
| the editor's `⬇ zip` button downloaded the whole project | it pointed at the archive route | a real `GET /api/projects/:slug/raw?path=…` (attachment, traversal-safe) and the button is now `⬇ file` + a separate `⬇ project zip` |
| **"I stopped the service and the site is still up"** | a Node service is started via `npm run start`, and npm spawns the real server as its own child — killing npm left `node server.js` running and holding the port. Hard restarts left orphans the reclaimer could not identify (its command line is just `node server.js`) | services now run in their **own process group** and are killed as a group (SIGTERM → SIGKILL → whatever still holds the port); the boot reclaimer finds leftovers by **port + working directory**, not by command line. New check: *stopping a service really stops it — even when it is started through npm* |
| **private clone failed with `cannot exec '…/askpass.sh': Permission denied`** (seen on a real repo) | the workspace snapshot does not preserve the executable bit, so git could not run GitDock's credential helper — and it then reported *"could not read Username"*, which looks like a token problem but isn't | the bit is re-asserted before **every** git call and at boot (`ensureAskpass()`), and the failure is now logged where it happens. Self-test check: *the git credential helper is executable* |
| **a failed clone answered "project not found"** (the same report) | the job-status route sat behind the "project exists" guard, and a failed clone deletes the directory before the UI's next poll — so every poll got 404 and the real git error stayed invisible | job status now lives **above** that guard, is readable straight from `data/jobs/*.log` even after the project is gone, and the job note carries **git's own last error line** with a tailored hint instead of a guess |
| a clone onto the wrong branch failed the whole clone | `main` typed by hand where the repo uses `master` anyway | if the branch does not exist, the clone retries on the repo's default branch and says so in the note |
| `failed to asynchronously prepare wasm: RangeError` on a Node project | the resource cap set `--as` (address space), and Node's WebAssembly runtime reserves gigabytes of *virtual* memory — my own cap broke a working app | the address-space cap is now **opt-in** (`limits: { memMB: n }`); process and open-file caps still apply. Resident-memory limiting belongs to the cgroup slice, not an rlimit |
| a service that died on boot showed a green dot + a port | starting a process was treated as success even when it exited immediately | a service that has already exited is reported as **failed**, with the last error line from its log, and is not listed as running (new check) |
| "cleanup left a project behind" (repeatedly) | partly a test bug (slug re-derived from a name with a trailing space), partly the crash above | the clone test takes the slug **from the API response**, cleanup reports strays loudly, and deletes are now logged (`deleted <slug>`) and watched for 3s for a reappearance |

### 1b. Proof the UI works (the layer the API test cannot see)

The suite now also drives the branch workflow end to end in a real browser: it checks the shell (launcher,
branch chip, status bar) against git itself, creates a branch by typing in the UI, merges by clicking the
button, then **undoes the merge from the Versions tab** — and verifies in git that the merge commit still
exists and the file it brought in is gone again. It then tests access control the same way: adds a
teammate through the settings dialog, **signs in as them over HTTP** with the one-time password, removes
them and proves their session dies; refuses a password change with the wrong current password; and signs
out — then proves the old token is dead, even when replayed in a URL. (Those two deliberate 403/401
responses happen on a separate page, so the panel page's "no console errors" check stays absolute.)

```bash
node scripts/uitest.js          # ~28 s, needs: npm install puppeteer
```

**46/46 checks, ALL GREEN.** It launches headless Chrome, loads the real panel, and **clicks the real
buttons** — creates a project through the dialog, opens and edits a file, saves (asserting a commit sha appears),
checks the Versions list, the Setup detection output, the Database/Env/GitHub tabs, then presses **start** on a
service and fetches that port **from Node** to confirm the edited file is really being served. It fails on any
console error, page error or 5xx, and deletes the project through the UI's own confirm dialog at the end — after
typing the project's name, which the dialog refuses to proceed without.

It also audits the panel's own script, because a bug the user hit lived entirely in the client: the
dock's Setup icon and the ⌘ palette's Settings entry both called a function that was **never defined**
— a click threw *"openSettings is not defined"*, with no syntax error and no server test anywhere near
it. `scripts/lint-ui.js` now parses the script with a real JS parser and fails on any call with no
definition, or any `#id` selector with no element. On top of that, the browser suite presses every dock
icon, every tab and every quick-action entry and insists each one does something real.

It also loads the panel at three device sizes — 390×640 phone, 844×390 phone landscape, 1024×600
Chromebook — and for each one checks both directions: nothing off-screen horizontally (menu bar, window
bars, Settings, Sign out, ⌘ actions), and every tab scrolled to its end shows its last element inside
the viewport. That check exists because the earlier phone test only looked sideways, and missed that
every tab was cut off at the bottom with no way to reach the rest.

It also drives the manual: the **How to** tab must cover every area by name (Code, Database, GitHub,
Pull requests, Previews, Services, Versions, Branches, Env, Setup, Settings) with at least three steps
each, must carry the exact token permissions, and its filter must narrow and admit when nothing
matches. And it loads the whole panel at **390 px** to prove the phone case is real: no sideways
scrolling, and Settings / How to / Sign out on screen.

It also holds the clone path to its promises: the dialog refuses a repository the token cannot reach
**without creating a project**, and a project deleted from under an open panel collapses the window and
shows "is not there" rather than a ghost shell. The backend suite proves a failed clone leaves no
directory, no pending metadata and no entry in the project list.

It also holds folder downloads to their promise: it clicks the ⬇ on a folder, waits for the network
call, then opens the bytes that call produced and asserts the zip contains the nested file and **not** the
project root — and that the click did not fold the folder.

It also holds the interface itself to the organisation rules: a folder in the tree folds and unfolds and shows
how many files are inside it; a file opens in **Code** with **Preview** one click away (an HTML preview lands in a
sandboxed frame carrying the project's own CSS/JS/images, markdown renders as real markdown including tables); the
long explanations live behind an **ⓘ** that stays shut until clicked; and the Database/Env/Files panes keep their
own scroll boxes instead of stretching the tab.

It is **state-independent**: it creates its own project, selects it by name in the sidebar, and verifies it is gone
at the end — so it works whether the panel is empty or full. It talks to the running panel over HTTP and then verifies
the results **on the filesystem, in git, in MySQL and by fetching live ports** — it does not trust the API's word.

| Section | Checks |
| access control | no session → 401 on the API and the shell leaks no project names; wrong password refused; token **and** cookie sessions work, a forged cookie does not; an owner adds a teammate who can sign in, a member **cannot** manage access, a removed user cannot sign in; a password change takes effect immediately |
|---|---|
| panel + environment | health endpoint; PHP/sqlite3/git/MariaDB actually present; project list real |
| project creation | directory created, `.git` initialised, initial commit exists |
| bulk upload → **one** commit | 12 files uploaded → exactly 12 files in **one** commit; GitDock metadata never committed |
| safety | `node_modules` skipped, **path traversal rejected** |
| editing | write → read round-trip, one commit per save, bulk delete = one commit |
| versions | rollback removes later files, **keeps them in history**, adds a new commit; any version downloads as a real zip (verified PK header) |
| manifest | `gitdock.yaml` parsed + committed; apply creates services, **provisions a real MySQL schema**, writes env defaults and injects `DB_*` |
| detection | runtime + docroot + confidence + the reasons for the guess |
| services | two services started on real ports, HTTP 200, **PHP rendered with env injected (DB name visible)**; logs captured |
| database | `.sql` import (tables verified), SQL query through the API, `mysqldump` export contains DDL, SQLite upload → tables → rows |
| env/secrets | file mode is `0600`, passwords **masked** in API responses, missing keys reported |
| git remote | push verified in the bare repo, pull moves HEAD to the remote commit, **PR ref (`refs/pull/7/head`) fetched and served** |
| previews | branch preview serves the branch while main is unaffected and the **working tree is untouched**; teardown closes the port and removes the worktree; **TTL sweeper auto-destroys an expired preview** |
| multi-app | a `gitdock.yaml` declaring two services in two folders starts both on **different** ports and each serves its own folder; a repo whose site lives in a subfolder is detected without a manifest; a service can be re-pointed with `PUT` (missing folder refused) |
| isolation | services really do run under the resource caps (`limits:` line in the service log) — process and open-file caps by default, address-space only when asked for |
| failure paths | the credential helper is executable; a failed clone still explains itself after its directory is deleted; a service that dies on boot is reported as failed instead of a green dot; stopping an npm-started service really stops the app |
| tidiness | `gitdock.yaml` is honoured over detection; `.gitdock/` is excluded through `.git/info/exclude` so `git status` stays clean without touching the repo |
| downloads | CORS preflight answered without a session; downloads work by session **or** `?t=` token; a navigation that needs a session gets an HTML page; single-file downloads; short-lived public download links for sandboxed frames (and they expire, cannot be used as sessions, and cannot escape the project) |
| cleanup | deleting a project stops services (port verified closed), drops its MySQL schema, removes its secrets and directory; **deleting mid-job leaves no zombie directory**; and the panel process is still the same one at the end of the run |

**Bugs the self-test caught and that are now fixed** (all were real, not cosmetic):

1. `.gitdock/` bookkeeping was being committed into your projects → excluded from staging and gitignored.
2. `GET /db/...` and `GET /services/...` swallowed their own sub-routes (status endpoints shadowed query/export/logs) → guards added.
3. `git push` demanded a token even for remotes that need no credentials → now it attempts the push and only asks for a token when the remote actually refuses anonymous access.
4. `git pull` returned HTTP 200 with `{ok:false}` and no message → now returns real status codes with an actionable explanation (auth vs unrelated histories vs merge conflict, and it aborts cleanly).
5. Stopping a service returned before the process had exited → `stopService` now waits for exit **and** for the port to be free, so "deleted" means deleted.
6. A panel crash left orphaned project servers running → at boot GitDock now finds and reclaims processes it spawned (verified by killing the panel with `SIGKILL`: the new panel logged `reclaimed 1 orphaned service process(es)` and the port was free).

---

## 2. What's implemented

| Capability | Notes |
|---|---|
| Clone an existing repo | `New project → clone` with any git URL (GitHub/GitLab/local path); detects the runtime, writes service definitions, leaves your repo's history alone |
| New project from template | static · PHP · Laravel (composer `create-project`, migrations, `APP_KEY`) |
| Folder / ZIP drag-and-drop | browser walks the tree (or inflates a zip in-browser) → chunked upload → **one** commit |
| File tree, editor, bulk select/delete | nested tree — folders fold, each shows its file count, *expand*/*collapse* for the whole tree; every mutation is one commit; hidden-path filtering; traversal-safe |
| No dead clicks | every dock icon, tab and quick-action entry is exercised by the UI suite, and a static audit (`scripts/lint-ui.js`) fails the build when a call has no definition or a selector has no element — the class of bug that otherwise only shows up as a toast in your browser |
| How to, in the panel | a **How to** tab (and a `? How to` button in the menu bar, reachable with no project open) that explains every area of the panel — what it is for, the steps, and the mistake people make. Filterable: type `token`, `port`, `undo` and only the matching sections stay. Includes the exact GitHub token permission list |
| Works on small screens | the menu bar and window bars wrap whenever they need to (no width threshold, so no dead zone where a long account name pushes **Sign out** off the edge) and the content column shrinks to the space left, so **every tab scrolls to its own end** at 390×640, 844×390 and 1024×600. Below that the app-shell gives up and the page scrolls instead — a shell that cannot fit is worse than no shell |
| Clone preflight | the New-project dialog asks GitHub — through the panel's token — whether it can read the repository **before** creating anything, and keeps the dialog open with the reason if it cannot (missing token, unselected fine-grained repo, bad URL). Nothing half-made is ever left behind |
| Failing jobs explain themselves | a failed clone names the real cause (403 "Write access to repository not granted" → the token is not allowed to read *that* repo, with the exact fix), and the panel collapses to a "not there" card instead of leaving the window, tabs and branch chip around a project that no longer exists |
| Download one folder | the **⬇** on any folder row zips that folder — its own name and structure preserved, everything nested inside included — and never the rest of the project. Refused clearly (never silently) when the folder holds only git-ignored files, and folder paths are validated like every other path |
| Preview next to the editor | every file opens in **Code**, with a **Preview** tab beside it: HTML in a sandboxed frame (project CSS/JS/images inlined), markdown with tables and code fences, CSV/TSV as a grid, images, pretty-printed JSON; downloads offered for the rest |
| ⓘ instead of walls of text | the long "why this works this way" notes are collapsed into an ⓘ in the heading or row they belong to, and open as a card on click — the panel stays scannable |
| Scroll where it matters | env keys, MySQL table lists, query results, SQLite cards, versions, logs and branch lists each scroll inside their own box (the tree and editor panes are capped too) |
| Project delete is gated | the dialog spells out what goes (files, stored `.env`, database) and the button stays dead until the project's own name is typed into it |
| Versions | commit list, files at any version, restore (as a new commit), download any version as `.zip` |
| GitHub | connect remote, pull, push, PR list; token held in memory only |
| **Branches** | create (from any branch/commit), switch, delete (with the "commits exist nowhere else" guard), list local + on GitHub with ahead/behind against the upstream *or* the base branch |
| **Merge** | merge any branch into the current one as a real merge commit; a conflict is detected, listed file-by-file, and **aborted** so the tree is never left half-merged |
| **Undo a merge** | `git revert -m 1` on any merge commit — history is never rewritten, and the merge stays visible; offered on the row in Versions and on merged PRs |
| **Pull requests** | open one from the UI (branch is pushed first, then the PR is created on GitHub), merge it (merge/squash/rebase — then the local base branch is fast-forwarded), close it, and undo a merge |
| OS shell | launcher dock, a ▦ projects switcher (the old permanent sidebar is gone), window chrome with a live status bar (branch · ahead/behind · dirty · ports · remote), branch chip menu, light/dark theme, ⌘/Ctrl-K quick actions — all inline SVG and CSS, nothing external |
| `gitdock.yaml` manifest | services (including **each service's `path`** — that is how one repo becomes website + admin + api), databases, env defaults/injection, install commands, preview TTL; auto-detection as fallback, with reasons shown |
| Multi-app repos | every service has its own folder, port and URL; `PUT /api/projects/:slug/services/:id` re-points one at another folder (and restarts it), missing folders are refused with a clear message |
| Static sites in a subfolder | a zipped folder with no root `index.html` is detected anyway (`dist`/`build`/`public`/`web`/`site`/`www`/… or any subfolder holding an `index.html`) |
| Access control | `data/auth.json` accounts (owner/member), scrypt hashes, sign-in page, session cookie **or** `X-GitDock-Session` header, login throttling, "sign out everywhere"; no public signup |
| Resource caps | every service runs under `prlimit`: address space, process count and open files — first slice of isolation (cgroup slice next) |
| Services | static · PHP · Laravel · Node, one port each; start/stop/start-all, health checks, log capture |
| Databases | MySQL schema + credentials per project (auto-injected into `.env`), `.sql` import, dump export, table peek; SQLite detection, browsing, querying |
| Env | `.env` stored at `data/secrets/<slug>.env` (0600), injected at service start, masked in the API, missing keys vs `.env.example` flagged; a **boot sweep** re-seeds missing files from `.env.example`, fixes modes, and restores `.env` into Laravel app folders (only when `.env` is gitignored) |
| Previews | branch/tag/sha **and PR refs** in throwaway git worktrees, own ports, TTL auto-teardown, global preview list |

---

## 2b. Who can get in (access control)

The panel is reachable from the internet through the sandbox preview URL, so it is **locked by
default** — no signup screen, no strangers.

- Accounts live in `data/auth.json` (mode 0600): name + scrypt hash + role (`owner` or `member`).
- The first boot mints an owner account and prints the password **once** (also written to
  `data/FIRST-LOGIN.txt`, mode 0600). Change it in **Settings → Access**.
- Owners add teammates there (a password is generated and shown once), remove people, and can
  *sign out everywhere* (rotates the session secret).
- Sessions travel two ways: an `HttpOnly; SameSite=Lax` cookie when you browse the panel directly,
  and an `X-GitDock-Session` header when the panel is embedded in a third-party iframe (the Arena
  preview), where cookies from other sites are blocked. After signing in, the header token is taken
  from the URL once and immediately stripped from the address bar.
- The panel *shell* (HTML/CSS/JS) is public on purpose — an iframe needs the app to load before it
  can send its token. Everything underneath it (projects, files, env, git, databases) returns **401**
  without a session; a self-test check asserts no existing project name appears in the public shell.
- CLI, for when you are locked out:
  `node app/lib/auth.js list | passwd <name> | add-user <name> | remove-user <name> | rotate`.
- `GITDOCK_AUTH=off` disables the gate — it logs a warning at boot. Only for a private network.

## 3. Using it (from empty)

1. **+ New project → clone** with your repo URL (add a GitHub token in *Settings* first for private repos), or pick a template.
2. **Setup tab** → *Apply* to create the services/database/env the manifest or detection describes. Long installs stream into the log panel.
3. **Run bar** → *start all* → each service shows its port and an **open ↗** link.
4. **Files tab** → drop a folder to bulk upload; **Versions** → restore or download any past state.
5. **Previews tab** → preview a branch or a PR; it gets its own ports and disappears on its own at the TTL.

---

## 4. Restarting after a snapshot

System packages don't persist; app code and `data/` do.

```bash
bash /home/user/gitdock/bootstrap.sh          # PHP, MariaDB, sqlite3, Composer, DB user (idempotent)
node /home/user/gitdock/app/server.js         # panel on :3000
node /home/user/gitdock/scripts/selftest.js   # optional: prove the backend still works
```
Services are never auto-started (deliberate) — press **start all**. Ports are dynamic: a restart gives fresh ports, and orphans from a hard-killed panel are reclaimed automatically at boot.

> The panel currently runs with `GITDOCK_SWEEP_MS=5000` so preview TTLs expire quickly in this demo. Production default is 60 s.

---

## 5. What is deliberately not here

1. **No isolation.** Services run as the panel's own user — trusted code only. Containers + cgroup limits are the next milestone.
2. **No authentication.** The panel URL is open to whoever has it; the design (GitHub OAuth + org allowlist, Cloudflare) is in `hosting-and-access.md` §4.
3. **No webhook receiver** — PR previews are created on demand, not automatically on PR push.
4. **No WordPress DB URL rewriting** (detected and flagged, not rewritten).
5. **No `worker`/`cron` runtimes** — the manifest accepts them; the runner reports them as skipped.
6. **Not multi-user**: no roles, quotas or fairness limits (2 vCPU / 2 GB sandbox).
7. **GitHub token is per-process, in memory** — re-enter it after a panel restart.

## Latest workspace update

- File-first visual redesign: labelled navigation, flatter surfaces, readable light/dark semantic colours, unified dialogs, and guided Upload → destination → Publish flow.
- Uploads: batches of up to 100 small files (approximately 4 MB payload), at most three concurrent transfers, byte/rate/ETA progress, and one commit after success. Large files retain the individual-upload path.
- `POST /api/projects/:slug/upload-batch`: authenticated binary request, 4-byte big-endian manifest length, JSON array of `{path,size}`, then concatenated file bytes. The complete manifest, sizes and safe paths are checked before writing. Request limit 8 MB; manifest limit 256 KB.
- Local browser benchmark: 18 MB across 360 files in five upload requests. This does not measure client-to-preview internet throughput.
- Regression checks: 53 UI checks and 67 backend checks. The backend suite needs MariaDB and a short preview sweep interval (`GITDOCK_SWEEP_MS=5000`) to finish its TTL test within its deadline.
- User-requested cleanup: local projects removed; generated screenshots/test logs removed after verification. Accounts and configuration retained; remote GitHub repositories unchanged.
