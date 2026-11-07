# devtest — QueuePilot without sign-in

**A separate system for development and role testing. Never deploy this.**

This is QueuePilot rebuilt with **no sign-in, no authentication and no landing
page**. Every role runs on its own port, and the port decides who you are.

The original system in `../queuepilot` is untouched — it still has full
authentication and its landing page.

---

## Run it

```bash
cd devtest
npm install          # or: ln -s ../queuepilot/node_modules node_modules
npm run migrate && npm run seed
npm run fleet        # all five role ports in ONE process (terminal use)
```

> **In Arena / any preview UI, start five separate processes instead of
> `npm run fleet`.** A preview panel is attached to a process, so one fleet
> process surfaces only one preview even though all five ports are serving.
> Run `npm run p8080`, `p8081`, `p8082`, `p8083`, `p8084` as five processes and
> each role gets its own labelled preview.

Open any port at **`/`** — it lands directly on that role's workspace. There is
no login screen to get past.

| Port | Role | Identity | Lands on | Clinic |
|------|------|----------|----------|--------|
| **8080** | Clinic Owner | `admin@serenity.lk` | `/app` | Serenity OPD & Family Clinic |
| **8081** | Receptionist | `reception@serenity.lk` | `/app` | Serenity OPD & Family Clinic |
| **8082** | Doctor | `amara@serenity.lk` | `/app` | Serenity OPD & Family Clinic |
| **8083** | Platform Admin | `owner@queuepilot.app` | `/admin` | — platform wide — |
| **8084** | Clinic Owner (2nd) | `admin@lakeview.lk` | `/app` | Lakeview Medical Centre |

8084 is the same role as 8080 in a **different tenant**, so you can prove
isolation: it must never show Serenity's patients.

One port at a time, or a subset:

```bash
npm run p8082            # just the doctor
npm run fleet 8081 8082  # receptionist + doctor
```

Every page carries an amber strip naming the port, role, identity and clinic,
plus one line describing what that role can see.

### Switching roles

The amber strip has a chip for every port, and the mobile drawer has the same
list under **Switch role**. You do not need to retype URLs.

Those links are built by `DevIdentity::siblingUrl()`, which rewrites the port
in the host the browser actually used:

| You are on | Link to the doctor becomes |
|---|---|
| `8080-<sandbox>.e2b.app` | `https://8082-<sandbox>.e2b.app/app` |
| `localhost:8080` | `http://localhost:8082/app` |

A proxied preview gives each port its own hostname, so a hard-coded
`localhost:8082` would be dead from your browser. Each link also lands on that
role's own `home` path, so the doctor never arrives on `/admin` and gets a 403.

---

## Design

The UI follows the approved ClinicQueue reference boards. Both the reference
images and screenshots of this build are in `docs/design/`.

| Token | Value | Where it came from |
|---|---|---|
| Primary action | `#0D7459` | sampled from the reference's buttons |
| Active nav pill | `#DFF4EE` | sampled from the reference's sidebar |
| Canvas | `#F7F8FA` | area outside the board panel |
| Hairline | `#E8ECF1` | card and table borders |
| Sidebar | `246px` | measured from the reference |
| Board split | `1fr / 352px`, 28px gutter | measured at 1484px wide |
| Type | Inter, self-hosted | `public/assets/fonts/`, no CDN |

**One design, every role.** The shell (`layouts/app.php`) and the primitives in
`resources/css/app.css` are shared, so all ~60 pages and all five ports inherit
the same sidebar, top bar, cards, buttons, tables and mobile behaviour. The
clinic board and the platform console are built from the same parts — greeting,
five stat tiles, a main card and a right rail — with each role's own data.

## Priority rules

The order on the screen **is** the order the engine evaluates.

### Why the engine changed

Previously there was no stored order: rules were sorted `effect DESC` and the
strongest match won. A manager could not express "under-fives first, and only
then the morning lab list", and two rules with the same effect were ordered by
insertion id — a tie-break decided by when somebody clicked Save.

Migration `0018` adds `sort_order`, and `evaluate()` now takes the **first
matching active rule**. The backfill ranks existing rules by exactly the old
ordering, so first-match returns what strongest-match returned before: no
clinic changes its queue order by upgrading. New rules slot in by effect so
saving a +3 does not silently lose to an existing +1, and dragging overrides
that default.

`reorder()` writes the whole list in one transaction and rejects a partial or
foreign list, so a stale tab cannot drop a rule it never saw.

### The screen

- Four counts: rules in action, manual overrides today, the wait comparison,
  and last change (including reorders and toggles, not just creation).
- Tabs for **Active rules** and **Rule history**, the history read from the
  audit log rather than a second table that could drift.
- Search, strength/status filters, and a numbered table with a drag handle.
  **Arrow keys work on the handle** — a drag-only control locks out keyboards.
- Saves debounce and queue: two quick moves used to lose the second one,
  because the in-flight guard returned early and the screen showed an order
  the server never received.
- Below `sm` the table becomes stacked cards so priority and status are never
  hidden off the right edge, and the row controls grow to 32px tap targets.
- The create form is a rail card, **open on arrival** as in the mockup, with a
  chevron that actually flips. `Priority` is coloured by the strength it names,
  so the select reads the same red/amber/grey as the badge it will produce.
- The time window takes **clock times** (`07:30`), not bare hour integers.
  `hourFromInput()` floors a start and ceils an end, so `23:59` becomes the
  exclusive upper bound `24` and a window means what the label says.
  `validate()` checks the *converted* value, not the raw string.
- `Reason` is optional; it falls back to the rule name so the audit trail and
  the patient's recorded reason are never blank.

### Matching the mockup

Rebuilt against `docs/design/priority-rules-reference.png` at its own
1484×1060: bare outline page glyph, 100px stat tiles with 48px circular icons,
sentence-case labels, a borderless `#F7F8FB` explainer with the evaluation
diagram, an uppercase header **outside** a rounded table body, 58px rows,
flat ring-free badges, and a one-line override report.

The last row, **"Default queue order / First come, first served"**, is
*synthetic*. It is what the engine falls back to, so it belongs at the bottom
of the list — but it is not a stored rule (`effect ≠ 0` is a CHECK), so it has
no drag handle and no delete action, it renumbers when the real rules move,
and it hides while a filter is applied rather than reading as a sixth match.

### Two deliberate deviations

- **"Avg. wait improvement 18%"** reads `—` until there is a real comparison.
  Patients a rule flags are clinically different from those it does not, so
  the gap is a comparison of two groups, never proof the rule caused it. The
  averages are spelled out underneath once there are at least five flagged
  visits. The layout matches; the number is measured, not styled in.
- **No "High fever" rule** in the seed. Temperature is not recorded anywhere,
  so such a rule would look authoritative and never match a patient. The five
  seeded rules use only conditions the engine can actually evaluate.

### Shared, not bespoke

Everything new lives in `resources/css/app.css` as generic primitives:
`.page-icon`, `.toolbar`, `.utab`, `.search-mini`, `.explainer`, `.dtable`,
`.dtable-frame`, `.dtable-stack`, `.rule-grip`, `.rule-num`, `.prio-*`,
`.status-on/-off`, `.stat-soft`, `.label-soft`, `.btn-tint`,
`.btn-outline-brand`, `.metric-strip`, `.disclosure-btn`. The icon-led page
head was applied to **33 page heads** across the console and the platform
admin, so the upgrade reads the same everywhere.

## The two patient-facing screens

These are deliberately **not** the console shell. They render as standalone
documents (`view($tpl, $data, false)`) because neither audience should see a
sidebar, and one of them is not even signed in.

| Screen | Route | Who looks at it |
|---|---|---|
| Waiting room | `/display` (staff preview) · `/board/{key}` (the wall) | everyone in the room |
| Patient view | `/t/{token}` | one patient, on their own phone |
| Self check-in | `/kiosk/{key}` | a walk-in at a lobby tablet |

### Waiting room

- **One tile per room, not one token per clinic.** The old screen collapsed
  five consulting rooms into a single headline token, so a patient whose room
  was called never saw it. `DisplayService::payload()` now returns `serving[]`
  and keeps `now_serving` for older consumers.
- Tokens are disambiguated by room **and** doctor: every queue restarts its own
  A-series, so three rooms can all be waiting on "A002".
- `called` gets a filled brand tile and a flash; `in_consultation` is quieter.
  Voice announcements fire once per room per token, not on every poll.
- Inter tabular numerals. The old `font-mono` em-dash at 10rem rendered as a
  solid white bar, which is what the empty state looked like.
- Fits a 1920×1080 wall exactly with no scrolling; reflows to one column on a
  phone. Full-screen button, wake lock, offline banner.
- First paint is server-rendered from the **same** builder the poller uses, so
  the wall is never blank for four seconds and the two cannot drift.
- Staff setup controls (kiosk, rotate key, exit) are hidden on `/board/{key}`.

### Patient view

- Position when waiting ("2 people ahead of you"), the real status otherwise.
  It used to print a bare `—` for every non-waiting state.
- Guidance follows the visit: a called patient is told to go to the room, not
  to "wait until your token is called". The wait estimate reads `Now` /
  `In progress` / `Finished` instead of a stale range.
- `.patient-view` raises stat labels back to 11px. The console's 9px labels suit
  a dense staff tool; a patient reading their own position deserves better.
- Staff can reopen it: **Patient view** and **Copy patient link** in each queue
  row's overflow menu, so a lost slip no longer means a new token.

Privacy on the wall: tokens, rooms and **doctor** names only. Verified by
scraping every patient name from the directory and asserting none appear in
either the HTML or the JSON of the public board.

Verified: 20 patient-surface checks at 360 / 426 / 768 / 1484 / 1920px, zero
overflow, zero JS errors, zero sub-10px type, no console chrome.

### Every page, not just the board

The layout wraps **every** authenticated page in the same `.board-panel`, and
the shared kit in `resources/css/app.css` supplies the parts:

| Class | Used for |
|---|---|
| `.page-head` / `.page-title` / `.page-sub` | page title block (a card on a phone, bare on desktop) |
| `.section-head` / `.section-title` / `.section-link` | card headers — white with a hairline, never the old grey strip |
| `.stat-tile` / `.stat-label` / `.stat-value` / `.stat-hint` | every number, via `components/stat.php` |
| `.filter-bar` | search and date-filter rows |
| `.glance-row` | label/value rail rows |

So Appointments, Patients, Doctors & rooms, Queue settings, Priority rules,
Service workflows, Policies, Incidents, Breaks, Schedules & holidays,
Departments, Reports, Audit log, Subscription, Notifications, Settings,
Support, Getting started and the platform console are all built from the same
objects as the Live board. Verified: 150 page/viewport checks across the five
roles at 360 / 426 / 1484px, zero horizontal scroll, zero JS errors, and every
page carrying the shared panel.

Full-bleed surfaces (waiting-room TV, kiosk, patient tracking, online booking,
error pages) deliberately opt out via `view($tpl, $data, false)`.

### Small-screen details

Found by measuring every page in a real browser, not by eye:

- Grid/flex children get `min-width: 0`. Without it a single long input or
  nowrap label stretches the whole page past the phone. Fixed-size controls
  (buttons, icons, checkboxes) are excluded so they do not get squashed.
- The "All doctors" jump list dropped from 13px/24px to 11px/32px and is capped
  at 7.5rem; the doctor tabs next to it are 11.5px with a 38px hit area.
- Stat tiles: 9px labels (was 7.5px), 10px hints, and a fixed width only inside
  the board's swipe strip — in a grid they stay fluid.
- Sub-10px type elsewhere raised to 10px; bare checkboxes given the shared
  20px `.checkbox`; 14–22px icon buttons raised to 32px.
- Stat rows are two-up on a phone instead of a tall stack; the rail cards go
  single-column below 380px so the QR block fits.

Two pre-existing bugs surfaced while sweeping and are fixed here:

- `view()` ignored its third argument, so full-document views were nested
  inside the console shell whenever a staff session existed (a document
  inside a document). The parameter now exists and 21 call sites use it.
- `QrCode::dataUri()` was never implemented, so **every patient tracking link
  (`/t/{token}`) returned a 500**. Implemented as a colourable SVG data URI.
  NOTE: the same two bugs exist in `queuepilot/`, which is deliberately
  untouched here.

### The board refreshes without a second template

`GET /app/panel` returns the live-queue card as rendered HTML, and the poller
swaps it in every 5s. There is deliberately **no JS row template**: a duplicate
template is how a redesign silently reverts five seconds after load. The older
JSON board endpoint still serves `/queue`, which has its own denser layout.

### Mobile

- Off-canvas drawer with backdrop, scroll lock, Escape, focus trap and restore.
- Bottom tab bar (Live board / Appointments / Patients / More); More opens the drawer.
- Stat tiles become a swipeable strip; the rail cards sit two-up.
- 44px touch targets, safe-area insets, 16px inputs (no iOS zoom-on-focus).
- Every table scrolls inside its own card. Verified no horizontal page scroll
  at 360px, 426px and 1484px across all five roles.

### Where the build differs from the reference, on purpose

- **Waiting rows say "Call", not "Start".** `waiting → call` is the real state
  machine transition; `start` only applies to an already-called token. The
  button is labelled with the action it actually performs.
- **Estimated wait is a range** (`8–19 min`), not a single number. That is the
  domain's existing `QueueService::estimate()`, which refuses false precision.
- **No "Analytics" nav item.** There is no analytics route; Reports covers it.
  A link that 403s or 404s is worse than a shorter sidebar.
- **Names, counts and the tracking URL are live data**, so they differ from the
  mockup's sample values.

---

## What was removed

| Removed | What it was |
|---------|-------------|
| Landing page | `pages/landing.php`, `/`, `/features`, `/pricing` — `/` now redirects to the role's workspace |
| Sign-in | `AuthController`, `/login`, login/register views |
| Registration | `PublicController::register*`, `/register` |
| Two-factor | `TotpService`, `/login/2fa`, `/settings/security` |
| Password reset | `PasswordResetService`, `PasswordResetController`, `/forgot-password`, `/reset-password/{token}` |
| Email verification | `EmailVerificationService`, `EmailVerificationController`, `/verify-email/*`, the onboarding step and the warning banner |
| Staff invitations | `StaffInviteService`, `/invite/{token}`, `/settings/invites` |
| Sign out | `/logout` and both sign-out buttons |

All of these return **404** on every port.

## What was deliberately kept

**The entire authorisation model.** `Auth::matrix()` still defines what each
role may do, and it is still enforced in the domain layer and the router — not
by hiding buttons. Only the question *"who is asking?"* changed: it is answered
by the port instead of a session.

Verified behaviour:

| Check | Result |
|-------|--------|
| Receptionist opens `/queue/new` | 200 — front desk issues tokens |
| Doctor opens `/queue/new` | **403** — doctors do not issue |
| Doctor opens `/patients` | **403** — no access to patient records |
| Receptionist opens `/audit` | **403** — audit is owner-only |
| Owner opens `/audit` | 200 |
| Owner opens `/admin` | **403** — not a platform admin |
| Platform admin opens `/admin/clinics` | 200 |

Also unchanged: the queue state machine, optimistic concurrency, tenant
scoping on every query, priority rules, workflows, notifications, reports,
billing, displays, kiosk, offline writes — the whole operational system.

---

## How identity works

```
tools/serve-role.mjs --port 8081
        └─ putenv("QP_PORT=8081")
              └─ app/Core/DevIdentity::user()
                    └─ dev/roles.json: 8081 → reception@serenity.lk
                          └─ SELECT * FROM users WHERE email = ?
                                └─ Auth::user() returns that row
```

`Auth::user()` is the only method that changed. `clinicId()`, `can()`,
`isSuper()` and the role matrix are untouched, so every downstream permission
check behaves exactly as in the real system.

Identity is resolved **by email, not by user id** — ids differ between seeds,
and a stale id would silently test the wrong role.

Sessions still exist, but only for locale, flash messages and CSRF. Each port
gets its **own cookie name and session directory** (`qp_devtest_<port>`),
because cookies are scoped by host and not by port; sharing one would make the
five ports overwrite each other's state.

To change who a port is, edit `dev/roles.json` and restart that port.

---

## One database, five roles

All ports share `storage/db/queuepilot.sqlite` (WAL + 5s busy timeout), so work
flows across roles exactly as it would in a clinic:

1. Issue a token as the **receptionist on 8081**
2. It appears on the **owner's board on 8080**
3. and on the **doctor's board on 8082** — if it is that doctor's queue
4. but **never on 8084**, which is a different clinic

That was verified end to end. Note that a doctor only sees their own queue:
Dr. Amara (8082) owns queue 2, so a token issued into queue 1 correctly does not
appear on her board.

This database is separate from `../queuepilot`, so `npm run fresh` here cannot
touch the main system.

---

## Commands

```bash
npm run fleet        # all role ports in one process (terminal)
npm run p8080        # one port = one process = one preview (p8080 … p8084)
npm run lint         # PHP parse check
npm test             # 221 checks
npm run health       # schema, plans, CSS, schema parity
npm run css          # rebuild Tailwind after editing views
npm run fresh        # wipe + re-migrate + re-seed this database
npm run worker       # drain the notification outbox once
```

**Run `npm run css` after editing views.** Tailwind scans the PHP views for
class names, so a new utility class does not exist until you rebuild.

### About the test count

The suite is **221 checks**, down from 246. The 25 removed tests covered only
deleted features: TOTP, recovery codes, staff invitations, password reset and
email verification. Every queue, tenant-isolation, authorisation, priority,
notification, reporting and billing test is retained and passing.

Two onboarding tests previously relied on a side effect of the email-verification
test (it issued clinic 85's first token). They now create their own data, so
they no longer depend on test order.

---

## Files that only exist here

```
dev/roles.json                              port → role → identity map
app/Core/DevIdentity.php                    identity from QP_PORT + siblingUrl()
resources/views/components/role-switcher.php  role popover / drawer rows
resources/views/partials/board-panel.php      the live-queue card (also the refresh fragment)
resources/views/components/board/patient-row.php  one waiting patient
docs/design/                                  reference boards + build screenshots
tools/serve-role.mjs                        one port, one role
tools/fleet.mjs                             all ports at once (terminal only)
README.md                                   this file
```

`public/index.php` requires `DevIdentity` and fails with a clear message if the
database is unseeded. `tests/run.php` uses `DevIdentity::actingAs()` to exercise
several roles in one process, which a single port cannot express.

> **This build has no authentication at all.** Anyone who can reach a port is
> signed in as that port's role. Keep it on a trusted machine, and never put it
> on a public host.
