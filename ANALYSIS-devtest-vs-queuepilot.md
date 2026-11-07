# Full analysis — `queuepilot/` (original) vs `devtest/` (upgrade sandbox)

> Goal as stated: **do all testing and upgrades in `devtest` first; once everything is exactly as wanted, port the changes into `queuepilot`.**
> This document records what both systems are, how they differ, what was verified by running them, the live bugs found, and a port-back plan.

---

## 1. The relationship

| | `queuepilot/` | `devtest/` |
|---|---|---|
| Role | **The original production system** | **The development system** — design + feature upgrades happen here |
| Auth | Full authentication (login, 2FA, password reset, email verification, staff invites, logout) | **None by design** — the port you connect on decides who you are |
| Landing page | Yes — redesigned marketing homepage (`/`, `/features` → `#surfaces`, `/pricing` → `#pricing`) | Removed — `/` redirects straight to the role's workspace |
| Theme | Original teal (`#0F766E`) + its own landing brand (Fraunces, warm paper, gold) | **ClinicQueue reference design** — emerald `#0D7459`, Inter, canvas `#F7F8FA`, 246px sidebar |
| Size | 278 tracked files, 176 routes, 186 PHP files linted | 288 tracked files, 154 routes, 176 PHP files linted |
| Both tracked in git | ✅ both committed (566 files total in repo) | ✅ |

Both share the same architecture: **PHP 8.3 running on a WASM runtime** (`tools/serve*.mjs` + `@php-wasm`), no Composer, Tailwind 3.4 built from PHP views, SQLite for dev / MySQL for prod, one shared layout + component kit, the same domain layer (`QueueService` → `QueueStateMachine` → optimistic `version` SQL), same tenant scoping, same test harness (`tests/run.php` on a throwaway DB).

**Identity model in devtest** (the only change to "who is asking"):

```
serve-role.mjs --port 8081 → putenv QP_PORT → app/Core/DevIdentity::user()
      → dev/roles.json (8081 → reception@serenity.lk) → SELECT … WHERE email = ?
      → Auth::user() returns that row
```

`Auth::clinicId()`, `can()`, `isSuper()` and `Auth::matrix()` are **untouched**, so a doctor on 8082 is genuinely refused `queue.priority` by the domain layer — exactly as in the real system. Per-port session cookie/cookie-dir (`qp_devtest_<port>`) so the five ports don't clobber each other's locale/flash/CSRF.

Port map (`dev/roles.json`): **8080** clinic owner (Serenity) · **8081** reception · **8082** doctor (Amara) · **8083** platform admin · **8084** 2nd clinic owner (Lakeview, solo doctor — tenant-isolation + solo-board fixture).

---

## 2. Verification — everything was run

### Test suites

| Check | queuepilot | devtest |
|---|---|---|
| `npm test` | ✅ **All 262 checks passed** | ✅ **All 234 checks passed** |
| `npm run lint` | ✅ 186 files, **0 parse errors** | ✅ 176 files, **0 parse errors** |
| `npm run migrate && npm run seed` | ✅ 20 migrations, demo tenants seeded | ✅ 20 migrations, demo tenants seeded |
| `npm run health` | ✅ **All good** (18 checks, 4 plans, 3 clinics) | ✅ **All good** (18 checks, 4 plans, 3 clinics) |
| README test count | README says 185 — **stale**, actual 262 | README says 221 — **stale**, actual 234 |

Test-count difference is explainable: queuepilot keeps ~28 auth/landing tests (TOTP, invitations, password reset, email verification, landing credibility/privacy); devtest drops those and adds board/display/position/token-series/panel/toast tests.

### Live servers (both running during this analysis)

| Probe | queuepilot `:8090` | devtest `:8080` |
|---|---|---|
| `/` | 200 landing page | **302 → `/app`** (role workspace) |
| `/login`, `/register` | 200 sign-in / register | **404** (deliberately removed) |
| `/app` unauthenticated | 302 → `/login` | 200 live board (115 KB rendered) |
| `/app` authenticated | 200 (89 KB) — login works end-to-end | — |
| `/t/{token}` patient tracking | **❌ 500** (see §4) | ✅ 200 with QR |
| Logged-in role behaviour | session auth, redirects to login when out | port-pinned identity, no login anywhere |

---

## 3. What devtest changed (the diff: 92 files differ, 14 devtest-only, 17 queuepilot-only)

### A. Removed (devtest-only deletions — **must never port back**)

Landing page · sign-in · registration · 2FA (`TotpService`) · password reset · email verification · staff invitations · sign-out · `Auth::attempt()/completeLogin()/logout()` · the email-verification gate inside `QueueService::issueToken()` (removed because seeded demo owners are unverified and the gate would block every token).
All of these 404 on every devtest port. 25 routes exist only in queuepilot.

### B. Design overhaul (the main payload — **this is what ports back**)

- **Palette**: teal `#0F766E` → emerald `#0D7459`, active-nav mint `#DFF4EE`, canvas `#F7F8FA`, hairline `#E8ECF1` — sampled from the approved ClinicQueue reference boards (`docs/design/`).
- **Type**: Inter first in the stack, self-hosted 400–800 (queuepilot also ships Fraunces + JetBrains Mono for its landing brand only).
- **Tailwind config**: full emerald ramp, new `mint` / `ink` / `canvas` / `hairline` tokens, `card`/`bar`/`pop` shadows. (Comment in config records a real fix: an earlier 4-shade ink ramp silently generated nothing for 300/600/800.)
- **Shell** (`layouts/app.php`, 413 changed lines): regrouped sidebar — `Main / Operations / Insights` + nav **footer** for platform admin (was one long list), role-aware icons, mobile drawer + bottom tab bar, amber dev strip with one-click **role switcher** (rewrites the port in the real host via `DevIdentity::siblingUrl()` so `8081-<sandbox>.e2b.app` links work).
- **Shared kit** in `resources/css/app.css` (+100 lines): `.page-head`, `.section-head`, `.stat-tile`, `.filter-bar`, `.glance-row`, `.checkbox`, `min-width:0` sweep, 44px targets, 16px inputs — so **all ~60 pages** inherit one design; verified by the suite at 360/426/1484 px.
- **58 view files changed** (dashboard 363 diff lines, display/screen 153, …). `app.js` grew 742 → 1109 lines (drawer, role switcher, panel poller, toast/notice handling).
- Patient-facing screens (waiting-room TV, tracking, kiosk, booking, errors) deliberately **opt out** of the console shell via `view(..., false)`.

### C. Waiting-room / display rebuild (functional design — **ports back**)

- **`app/Services/DisplayService.php` (new)** — one payload for first paint *and* the poller so they cannot drift. Returns `rooms[]` (card per session), `serving[]`, `now_serving`, `solo` flag. **Privacy: never a patient name/phone/reason** — tokens, rooms, doctors only.
- **`partials/waiting-board.php` (new)** — `/display`, `/board/{key}` and `/live/{key}` all render this one partial (queuepilot still has separate templates; `/live` there kept old markup + an inline script CSP blocked).
- **Solo vs several doctors** — solo clinic gets a full-wall layout instead of a grid of one card; equal token size everywhere (no 108px hero vs 44px).
- **`GET /board/{key}/position` (new route)** — "where do I stand?" lookup by token label; read-only, no name, **never exchanges a guessable label for the cancel-capable track token**. Backed by new `QueueRepository::entryByLabel()`.
- **Voice announcement once per room per token**, server-rendered first paint.

### D. Correctness fixes that live only in devtest (**port back — these are bugs in queuepilot**)

1. **Token series per room** — new `QueueService::suggestPrefix()` + `POST /queues/{id}/prefix` + settings UI. Three session-opening paths used to derive the letter from the clinic default/doctor's initial, so five rooms all issued "A001". Letter is locked after the first token; collisions refused; every change audited.
2. **Ordering tiebreak `id` → `token_no`** in 5 shared queries (waiting-room up-next, `board()`, `clinicBoard()`, `position()`, notification queue). Row `id` agrees with token order only by luck — an offline sync or transfer can write rows out of order. queuepilot still ties on `id`.
3. **`QrCode::dataUri()` implemented** — see §4, queuepilot is broken here today.
4. **Flash messages now reach the screen** — `partials/toast.php` reads `notice()` and hands it to the toast via a data attribute (CSP-safe). 176 call sites call `flash()`; queuepilot's toast is still an empty `<div>` — **every confirmation and refusal is silently dropped there**.
5. **`/app/panel`** — the live-queue card returned as HTML and swapped every 5 s. Deliberately **no JS row template** (a duplicate template is how a redesign reverts itself 5 s after load).
6. Board queries now select `birth_year`/`gender` for the patient-row component.

### E. Already synced queuepilot ← devtest (no work needed)

The landing-page work pushed these into queuepilot already: `asset()` content digest (immutable-cache fix), `sw.js` network-first at `qp-v3`, `view()` forwarding `$asLayout` (double-`<html>` fix), landing privacy/currency/redesign + its credibility test groups.

### F. Files only in devtest

`dev/roles.json` · `app/Core/DevIdentity.php` · `app/Services/DisplayService.php` · `components/role-switcher.php` · `components/board/patient-row.php` · `partials/board-panel.php` · `partials/waiting-board.php` · `pages/display/position.php` · `docs/design/` (22 reference + build screenshots) · `tools/serve-role.mjs` · `tools/fleet.mjs` · `tools/serve-landing.mjs` · README (645 lines).

### G. Files only in queuepilot (the auth/landing world)

`AuthController` · `EmailVerificationController` · `PasswordResetController` · `TotpService` · `EmailVerificationService` · `PasswordResetService` · `StaffInviteService` · `pages/auth/*` · `pages/landing.php` · `pages/settings/security.php` · `landing.js` · Fraunces/JetBrains Mono fonts · landing images.

---

## 4. Live bugs found in `queuepilot` (confirmed by HTTP during this analysis)

| # | Bug | Evidence | Fixed in devtest? |
|---|---|---|---|
| 1 | **`QrCode::dataUri()` does not exist** — `PublicController::track()` and `pages/queue/token_issued.php` both call it | `GET /t/{token}` → **HTTP 500** `Call to undefined method App\Support\QrCode::dataUri()`; `GET /queue/token-issued` → **HTTP 500** same error. Patient tracking **and** the post-issue screen crash in the original system. | ✅ implemented as colourable SVG data URI |
| 2 | **Flash messages discarded** — 176 `flash()` calls, nothing reads the bag | queuepilot `partials/toast.php` is an empty div | ✅ `notice()` → toast via data attribute |
| 3 | **Fresh seed can't issue tokens** — demo owner has `email_verified_at = NULL`, so `issueToken()` refuses with "Confirm the clinic owner's email address…" | reproduced from `storage/logs/app.log`: `token.issue.failed` ×3 until I hand-verified the owner in the DB. Deliberate security gate, but it makes a fresh `npm run seed` demo unable to issue a token. | ✅ gate removed (devtest has no email verification at all) |
| 4 | **Ordering ties on row `id`** instead of `token_no` | static diff in 5 queries (offline sync / transfer can disorder ids) | ✅ `token_no` tiebreak |
| 5 | **Token-series letter collisions** across rooms | static: three session-opening paths derive letters independently | ✅ `suggestPrefix()` + lock + audit |
| 6 | **Waiting-room: 3 divergent templates**, `/live/{key}` had an inline script CSP blocked (page opens then freezes), one headline token for the whole clinic | static + devtest README's test coverage | ✅ one `waiting-board.php` partial, test asserts no inline script |

Note: bugs 1, 2, 4, 5, 6 also mean **queuepilot's own test suite passes while these are broken** — no queuepilot test exercises `dataUri`, the toast partial, or the live board markup. devtest's 234 checks cover them.

---

## 5. Port-back plan (when you're ready to update `queuepilot`)

**Port in this order — each independently testable:**

1. **Correctness fixes** (small, no design): `QrCode::dataUri` → toast/`notice()` → `token_no` tiebreak → `suggestPrefix` + prefix route → `DisplayService` + `waiting-board` partial + position lookup → `/app/panel` poller. After each: `npm test && npm run lint` in queuepilot must stay green (its 262 checks), plus a manual click-through.
2. **Design shell**: `layouts/app.php`, `resources/css/app.css`, `tailwind.config.js`, the 58 views, `app.js`, then `npm run css` to rebuild. Re-verify all 5 roles × 3 viewports the way devtest's suite does.
3. **Auth surfaces need design attention they never got**: devtest *deleted* login, register, forgot/reset password, 2FA, invites, verify-email and settings/security — so those pages still carry the **old teal design** in queuepilot. They must be restyled to the new kit during the port (or they will look like a different product).
4. **Landing reconciliation**: queuepilot's homepage has its own brand identity (Fraunces / warm paper `#FBFAF7` / gold, `.lp-*` layer) that predates the ClinicQueue console design. Decide whether the landing keeps that identity or is re-skinned to match the new console — right now they are two deliberate design languages.
5. **Do NOT port**: `DevIdentity`, `dev/roles.json`, per-port session names, the amber dev strip / role switcher (or keep it dev-gated), and any of the auth removals. queuepilot keeps full auth; devtest keeps none.

**Definition of done** for the "everything is exactly as I want it" gate: devtest suite green (234), lint 0, health all-good, five-role click-through at 360/426/1484 px, waiting-room + patient position + kiosk + booking checked on real devices — then the port, then queuepilot's own 262 checks + auth flows re-verified.

---

## 6. Current workspace state

- `devtest` server: **running on :8080** (Clinic Owner) — preview attached.
- `queuepilot` server: **running on :8090** (with session login verified working).
- Both databases migrated + seeded; both health checks all-good.
- Housekeeping: `queuepilot/node_modules` is an untracked **symlink** to `devtest/node_modules` (so queuepilot's npm scripts run without a second install); `devtest/package-lock.json` has a 2-line version-metadata churn from `npm install`. Neither is committed.
