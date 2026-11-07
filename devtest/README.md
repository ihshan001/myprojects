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
| **8084** | Clinic Owner (2nd) | `admin@lakeview.lk` | `/app` | Lakeview Medical Centre — **one doctor** |
| **8085** | Public homepage | — no sign-in — | `/` | marketing site, pricing, live demo |

8084 is the same role as 8080 in a **different tenant**, so you can prove
isolation: it must never show Serenity's patients. It is also the solo-doctor
fixture: one session, so it exercises the single-doctor board and console.

**8085 is not devtest.** devtest has no landing page, no sign-in and no
registration by design, so the homepage only exists in the reference app. `tools/serve-landing.mjs` runs `../queuepilot`'s own unmodified server on
8085, changing the transport only:

- strips `X-Frame-Options` and `frame-ancestors` so the Arena preview iframe can
  render it (production keeps its clickjacking protection);
- defaults `Content-Type` to `text/html`. queuepilot's `serve.mjs` never sets it —
  PHP's implicit content type does not appear in `headers_list()` — and with
  `X-Content-Type-Options: nosniff` the browser refuses to guess and prints the
  page as escaped source. devtest's `serve-role.mjs` already carried this fix.

The shim itself modifies no file under `queuepilot/`. The reference app's
homepage has been changed repeatedly, each time on explicit request:

- **Privacy fix.** Its demo panel selected `p.full_name` and printed real patient
  names on an unauthenticated marketing page, while the page's own FAQ promised
  "tokens and room numbers only". The patients join is gone entirely, so the name
  is not reachable rather than merely unprinted. A test asserts the join stays out.
- **Currency typo.** The hero read "works on a ₹ phone" — a stray rupee sign where
  a number had been lost. That line no longer exists: the hero was rewritten below.
- **Homepage redesign.** `pages/landing.php` was rebuilt to the supplied mockup:
  sticky header, split hero with a photo and floating product cards, capability
  strip, three-column OPD challenge with an isometric render, five-step flow,
  four pricing cards, a principles carousel beside a live dashboard panel, and a
  patient row with a QR code. Inter and the `.lp-*` component layer are scoped to
  this page, so the console's typography is untouched.

  The mockup's placeholder credibility was **not** copied. There are no hospital
  logos, no named medical director, no "500+ clinics", no "47%"/"92%" figures and
  no USD toggle, because none of it is true of this product. Every number on the
  page is queried from the database or the `plans` table; tiles with no data show
  a dash. Five test groups in `queuepilot/tests/run.php` fail if a fabricated
  brand, statistic, hardcoded price or wrong trial length is ever pasted back in.
  The patient mock-up is filled by `QueueService::nextWaitingPublic()`, which
  returns five whitelisted fields so no capability token reaches the view.

A fourth change was app-wide rather than cosmetic. The redesigned homepage
rendered **unstyled in a browser that had visited before**: `tools/serve.mjs`
sends `Cache-Control: public, max-age=31536000, immutable` for `/assets/build/`,
which is only correct for a content-addressed URL — but queuepilot's `asset()`
returned a fixed path, and `sw.js` served the shell cache-first under a `VERSION`
nobody bumped. New markup arrived with year-old CSS, so the container had no
width, the type fell back to default sizes and the chart SVG had no height and
blew up to full width. Both are now fixed in queuepilot the way devtest fixed them
in `6e69d22`: `asset()` appends a content digest, and the worker is network-first
at `qp-v3` with an offline fallback. Two test groups assert the pairing, and a
browser test reproduces the failure — reverting `asset()` makes a warm-cache
reload miss the rebuild; restoring it makes the rebuild win.

A fifth change gave the homepage an actual identity, and fixed the bug that was
hiding it. `view()` accepted two parameters while nine callers passed a third
`false` meaning "this view is already a whole document". PHP drops extra
arguments silently, so `View::render` wrapped the landing (and the kiosk and
track error pages) in the console layout anyway. The response carried **two
`<html>` tags**; browsers keep the first, so the page's own `html`/`body` classes
were discarded — which is why the brand tokens resolved to nothing and every
border fell back to `currentColor`. The helper now forwards `$asLayout`.

The brand surface itself:

| Decision | What it is |
| --- | --- |
| Idea | **The token.** A clinic runs on a numbered ticket, so the ticket is the identity: a perforated hero card with punched notches, mono numerals, a dotted queue rail. |
| Display | Fraunces, variable, with `SOFT 40` and `WONK 1` — warm, slightly wonky serifs that make the wordmark recognisable. |
| UI / numbers | Inter for text, JetBrains Mono for anything numeric. All self-hosted, licences included. |
| Palette | Warm paper `#FBFAF7` instead of clinical white, deep teal ink `#06322E`, and one gold signal `#E0A63C` used sparingly enough to still mean something. |
| Mark | A token-shaped Q whose tail ends in a gold dot. Inline SVG, no request. |
| Close | A dark footer carrying an oversized wordmark, so the last thing on the page is the brand. |

**Scale.** The page is designed to be read at the proportions of a browser at
90% zoom while the browser stays at 100%. `.lp-root { font-size: 90% }` is the
single knob and every size on the page is in `rem`, so one number retunes it.
Breakpoints stay in `px`, so responsive behaviour is unchanged. A test asserts
no arbitrary `px` size creeps into the view and escapes the knob.

**Motion.** Sections fade and rise on entry, list items stagger, the header
gains a stuck state, a progress bar tracks reading position, and the real
figures count up. All of it is progressive enhancement: the hiding rules are
scoped to `.lp-reveal-ready`, which only the script adds, so a no-JS visitor
sees the whole page. `prefers-reduced-motion` skips the arming entirely. Count-ups
read their target from the server-rendered DOM and land on exactly that value —
they animate a true number, never invent one.

**Section rhythm.** The first brand pass put every section in the same white
rounded card, so six identical boxes read at one volume. Sections now use shared
band primitives — `.lp-band` with `--paper`, `--mist` and `--dark` variants —
and the page alternates: hero wash, open capability strip, open editorial
challenge, tinted flow band, pricing cards, one full-bleed dark band, open
patient section, dark footer. `.lp-panel` is reserved for things that really are
cards (pricing, the dashboard, the hero floats). No `<section>` carries it.

**The flow is the product motif.** The five steps are no longer five boxes; they
are `.lp-flow`, a dotted queue rail with numbered nodes — vertical on phones,
a sticky heading beside it on tablet, horizontal across desktop.

**Reveals repeat.** The observer toggles `is-in` in both directions and is never
disconnected, so a section fades out when it leaves and back in when it returns.
Both states carry a transition, so the exit fades rather than snaps. No-JS and
reduced-motion still show everything; a test asserts the observer never calls
`unobserve`.

**Small screens.** Hero cards span the full gutter instead of being pinned to a
desktop width; the fifth capability spans and centres rather than orphaning;
the phone mock-up stacks above full-width copy instead of squeezing it into a
150px column; the QR sits beside its caption; footer links form a two-column
grid. Tap targets are `3.0556rem`, which is **44 physical px at the 90% root** —
a naive `2.75rem` renders 39.6px and silently misses the floor. Verified at 375,
426 and 768: no horizontal overflow, 44px minimum target.

### Three sections, rethought

The capability chip-row, the five-step process and the quote carousel were the
three weakest patterns on the page. Each was replaced with a different idea
rather than a restyle. The capability strip was then **restored on request** —
the ledger below is kept in the table only to record what it replaced; see the
next section for what shipped.

| Was | Now | Why |
| --- | --- | --- |
| Five icon chips ("Priced in LKR") | ~~Constraint ledger~~ → **capability rail** — `.lp-caprail` | The ledger was tried and reverted on request. The original five items are back, but as one instrument panel instead of five floating chips. |
| Five step boxes | **Swimlane** — `.lp-duet` | The old flow described our internal process. The product's actual value is *simultaneity*, so each row now shows one moment from both sides: "At the desk" (solid) and "In their hand" (dashed), with the token rail down the centre. |
| Quote carousel + three feature rows | **Annotated evidence** — `.lp-mark` / `.lp-decision` | An unattributed quotation reads like a fake testimonial and hid two thirds of itself behind a click. Now the real dashboard carries three numbered markers keyed to the decisions behind them. Nothing is hidden and no JS is needed to read it. |

The carousel's markup, its JS block and the unused `$steps` array were deleted
rather than left dormant.

Every claim on the page is traceable to code: the 30-second staleness rule, the
token-only public surfaces, LKR plan rows, the `en`/`si`/`ta` lang files, and
plan-gated channels. A test extracts the threshold from `app.js`
(`Date.now() - lastOk > 30000`) and fails if the copy quotes a different number —
verified by changing the copy to "45 seconds" and watching it fail. The restored
rail keeps that wording: *"Marks itself stale after 30 seconds."*

### The restored rail, and the dashboard on a phone

**The rail.** Same five items and the same heading as the original strip, but
built as a single bordered frame (`.lp-caprail`) with the cells divided *inside*
it rather than five separate cards. Each icon sits in a 44px gradient medallion
with a ring and a soft shadow; each label now carries a one-line note under it.
The heading is flanked by two short gold rules (`.lp-kicker`).

The divider rules switch direction with the layout, which is the part worth
copying elsewhere:

- **Below `lg`** the cells are two-up and the rule is a full-bleed `bottom` line,
  so adjacent cells join into one continuous row rule. An inset (`left-4 right-4`)
  leaves a visible gap between the columns and reads as two stray underlines.
- **At `lg`** the cells are five-up and the same `::after` becomes a `right` rule
  inset from the top and bottom, so it reads as a hairline between columns.
- The last cell drops its rule in both layouts, and the fifth item spans both
  columns below `lg` so it centres instead of leaving a hole.

**The dashboard card.** It read well on desktop and badly on a phone. Four fixes:

| Problem | Cause | Fix |
| --- | --- | --- |
| Chart floated inset, ~53px of dead space each side | A fixed height on a `260×70` viewBox makes the browser fit-to-height and centre the plot — SVG letterboxing | `.lp-spark { aspect-ratio: 260 / 70 }`, no fixed height. `preserveAspectRatio="none"` was rejected: it distorts strokes and turns the dots into ellipses. |
| First and last dots sliced in half | `x` mapped `0 → width`, so a `r=2.2` dot sat on the viewBox edge | Inset `x` by the dot radius and match the area polygon's bottom corners to it |
| Markers jammed against the tile labels; values tight | No `min-w-0`, uniform padding, marker inline with the text | `min-w-0`, `px-2.5 py-3 sm:px-3`, marker as its own flex item with `gap-1`, value `truncate` |
| "Average wait measured from 40 finished visits" sat under the *visits* chart | Caption was placed after the wrong figure | Moved directly under the stat tiles, next to the number it describes |

The doctor list became `.lp-doclist` — even rows, one hairline *between* rows
(`li + li`), never a trailing rule.

Both charts share `.lp-spark`; a test asserts the ratio is pinned in CSS and that
neither SVG carries a fixed height that would override it.

Captures: `docs/design/landing-page.png` (desktop), `docs/design/landing-mobile.png`
(426px). Both are shot with reduced motion so every section is visible at once.

```bash
PORT=8085 node tools/serve-landing.mjs     # from devtest/
```

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

Rebuilt to `docs/design/waiting-room-reference.png`: a **light** board rather
than the old dark one, because a waiting room is lit and a black wall reads as
switched off. Two layouts, chosen by how many sessions are open.

- **One card per room, not one token per clinic.** The old screen collapsed
  five consulting rooms into a single headline token, so a patient whose room
  was called never saw it. `DisplayService::payload()` returns `rooms[]` (card
  per session, with its own up-next list and waiting count), plus `serving[]`
  and `now_serving` for older consumers.
- A `called` token is promoted over an `in_consultation` one, because a call is
  the thing a patient must act on.
- Tokens are disambiguated by room **and** doctor: every queue restarts its own
  A-series, so three rooms can all be waiting on "A002".
- The card grid is `auto-fit`, not a fixed four: five open sessions get five
  columns instead of four plus an orphan row.
- Voice announcements fire once per room per token, not on every poll.
- First paint is server-rendered from the **same** service the poller reads, so
  the wall is never blank for four seconds and the two cannot drift.

#### Solo practice vs several doctors

Most clinics are one doctor. Treating that as a group practice with the numbers
turned down produced a grid of one card, an "All doctors" control with a single
doctor behind it, and a lookup form asking which doctor you meant. `payload()`
now returns a `solo` flag and the board branches on it:

| | one session | several sessions |
|---|---|---|
| Wall | `waiting-room-solo.png` — the room takes the whole wall: huge token, then a full-width up-next strip | `waiting-room-desktop.png` — every room an equal card |
| Console | one "today's session" label | doctor tabs + "All doctors" picker |
| Position lookup | doctor preselected, not asked | asks which doctor |

- **No room is visually privileged.** The old board made one doctor's token a
  108px hero and everyone else's 44px, so most of the room was reading small
  print. Every card now renders the same `clamp(2.25rem, 3.4vw, 3.25rem)` token;
  a called room gets a coloured ring and a `CALLED` pill, never a bigger size.
- The layout is re-chosen when the room count changes shape: a solo clinic that
  opens a second session (or closes one) reloads into the other layout rather
  than trying to rebuild it in the poller. Verified live in both directions.

#### One token series per room

Every room issues its own letter: Room 1 A, Room 2 B, Room 3 C, and so on. Three
separate session-opening paths (new doctor, bulk open, clinic signup) each used to
derive the letter from the clinic-wide default or the doctor's initial, so five
rooms all issued "A001" and nobody could tell whose A002 was whose. They now share
`QueueService::suggestPrefix()`, which allocates against today's other sessions
and walks forward past any letter already taken.

- The owner can set any one or two letters per session in **Queue settings**
  (`docs/design/token-series-settings.png`), before the first token is issued.
- After the first token the letter is **locked**: re-lettering a queue would make
  the slip in a patient's hand disagree with the wall. The refusal says so, and
  says to set it on tomorrow's session instead.
- A letter another of today's sessions already uses is refused, so the allocator's
  guarantee cannot be undone by hand. Every change is audited.
- The clinic-wide setting is now a *preferred first letter*, honoured only while
  that letter is still free — a default must not reintroduce the collisions.

#### Order reads the way patients expect

`A001` is seen before `A002`, and the up-next list runs upward from whatever is
being served. The rule is **priority, then appointment/walk-in weight, then token
number**. The last part used to be the row `id`, which agrees with token order only
by luck; an offline sync or a transfer can write rows out of order. Five queries
shared that tiebreak and now all use `token_no`: the waiting-room up-next list,
`QueueRepository::board()` and `clinicBoard()` (what reception sees),
`QueueRepository::position()` (what the patient is told) and the notification
queue. A test asserts the board's order and the patient's stated position agree.

Clinical priority still outranks both, so an urgent patient is still called first.

Up-next chips went from 12–13px to 16–18px (22–26px on the solo board): it is the
one list a seated patient actually scans for their own number.

#### "Where do I stand?" — one patient, one queue

`/board/{key}/position` is a read-only lookup for someone who has stepped
outside. They enter the token on their slip and see how many are ahead, the
estimate range, and which token is being seen **in their own queue** — so they
can tell whether it is moving. No other doctor's queue is rendered.

Reachable from a button in the board header, a QR code on the wall, and the
console's *Patient tracking* panel (which used to hand out the whole board while
claiming to show one room's position).

- **A token label is not a credential.** It is printed on paper and shouted
  across a room, so this endpoint deliberately does **not** redirect to
  `/t/{track_token}`: that page carries cancel, reschedule and rate actions, and
  handing it over for a guessable label would let anyone cancel a stranger's
  visit. `myPosition()` returns a whitelisted read-only payload with no name, no
  arrival time and no track token. The page has no POST form.
- Lookup is scoped to the clinic **and**, for a department key, that department;
  the queue must be open today. Token labels repeat across sessions, so the
  queue id is required rather than optional (a solo clinic supplies it itself).
- A waiting or called visit refreshes itself every 30s; a finished one does not
  poll forever.

#### Flash messages were being discarded

176 places call `flash('error'|'success', ...)`, and nothing read the bag — every
confirmation and every refusal was silently dropped, so a guarded action looked
like a dead button. `partials/toast.php` now reads `notice()` and hands it to the
existing toast helper through a data attribute (an inline script would be blocked
by `script-src 'self'`). Errors linger 12s and stay dismissible, because a message
explaining *why* something was refused is useless if it vanishes unread.

#### One board, three routes

`/display`, `/board/{key}` and `/live/{key}` all include
`partials/waiting-board.php`. They used to be three templates: two were
rebuilt while `/live/{key}` kept the old markup **and an inline script that
CSP silently blocked**, so that link opened and then froze. A test now asserts
all three share the partial and none carries an inline script.

#### Two deliberate departures from the mockup

- **No patient names.** The reference lists "A-033 Priya Nair" under each
  doctor. This board is on an unauthenticated URL that anyone in the room can
  photograph, so "up next" is **tokens only**. `DisplayService` never puts a
  name or phone number in the payload at all. Verified across both clinics:
  34 names/phone numbers and all 76 track tokens, zero occurrences in either
  board's HTML, the API JSON, `/live`, or either position page.
- **The doctor's specialty is shown** ("General Medicine", "Cardiology"). It is
  the doctor's own public attribute, not a patient field — but note that next
  to a token it lets someone infer why a neighbour is there. Say the word and
  it comes off; it is one line in the partial.


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
