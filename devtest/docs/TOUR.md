# QueuePilot — the complete tour

Every screen, every control, and what it is for. Read top to bottom and you
have seen the whole product.

Written from the code and the running build, not from a spec. Where a number
is quoted it is the demo data in `devtest` (Serenity OPD & Family Clinic,
Colombo) so figures look like the real thing.

---

## The shape of the thing

A **clinic** has **doctors**, and each doctor working today has a **queue**.
A person arriving is given a **token** — `A001`, `B014` — which is their place
in one doctor's queue for that day. Everything else is built on that: the
board, the waiting-room screen, the text message, the patient's own phone
link, the reports.

Some terms, because they are used precisely:

- **Token** — a number for one queue on one day. `A` belongs to Dr Perera's
  queue; Dr Silva's queue issues `B`. The prefix is set per queue.
- **Entry / visit** — one token's journey: waiting → called → in consultation
  → completed, or off to one side as skipped, no-show, cancelled, transferred
  or on hold.
- **Session** — a doctor's day. A queue can be *open* while the session is
  *not started*. Tokens can be issued before the doctor arrives; **calling is
  blocked until the session starts**, so nobody is called into an empty room.
- **Queue** and **doctor** are near-interchangeable in the UI, because a queue
  is one doctor seeing patients today.

### The nine token states

| State | Meaning | Actions available |
|---|---|---|
| `waiting` | In the queue, not yet called | Call, Skip, No show, Cancel, Priority, Transfer, Hold |
| `called` | Name called, not yet in the room | Start, Call again, Skip, No show, Cancel, Transfer, Hold |
| `in_consultation` | With the doctor | Complete, Transfer, Cancel |
| `completed` | Seen | — |
| `skipped` | Passed over | Re-queue, Cancel |
| `no_show` | Called, did not answer | Re-queue, Cancel |
| `on_hold` | Stepped away (X-ray, bloods) | Resume, Cancel |
| `transferred` | Moved to another queue | Re-queue |
| `cancelled` | Taken out | — |

The transitions are enforced in the domain layer, not just hidden in the UI —
you cannot jump a waiting patient straight to completed, and a replayed Call
is rejected as stale rather than calling twice.

### Who can do what

| | Clinic owner | Reception | Doctor | Platform admin |
|---|---|---|---|---|
| Issue tokens | ✓ | ✓ | — | ✓ |
| Call / start / complete | ✓ | ✓ | ✓ | ✓ |
| Clinical priority | ✓ | — | ✓ | ✓ |
| Patients, appointments | ✓ | ✓ | own only | ✓ |
| Reports | ✓ | ✓ (basic) | own only | ✓ |
| Settings, staff, billing | ✓ | — | — | ✓ |
| Manage clinics & plans | — | — | — | ✓ |

Two deliberate asymmetries: **reception cannot set clinical priority** (that
decision needs a clinician's judgement and a recorded reason), and a **doctor
cannot issue tokens** (their board hides the button and the route refuses
them). A doctor *can* cancel their own day — that is clinical authority over
their own list.

---

## 1. Live board — `/app`

The clinic's home. It answers "how are we doing right now?" without a click.

### Greeting strip

- **"Good morning, Ishara 👋"** — the greeting follows the clock (morning
  before 12, afternoon before 5, evening after), and uses the first two words
  of your name.
- **Date and time** — `Thursday, 6 November 2026 · 3:25 PM`.
- **Live pill** — turns amber and says "stale" if the 5-second refresh has
  stopped landing, so you never trust a frozen number by accident.
- **"Auto-updates every 5s"** — stated, because a board that silently
  refreshes makes people wonder whether the numbers are real.
- **Waiting room screen** button (top right) — opens the TV display in the
  same tab, not a popup, because popups are silently blocked inside an
  embedded preview and the button would appear dead.

### Five stat tiles

A swipeable strip on a phone, a 3-up grid on tablet, 5-up on a laptop.

| Tile | Shows | Hint under it |
|---|---|---|
| **Waiting now** | Patients still in a queue | *patients* |
| **In consultation** | With a doctor right now | *with doctor* |
| **Completed today** | Finished visits | *patients* |
| **Average wait** | Mean wait today | *today* |
| **Longest wait** | Worst wait, formatted `1h 20m` past the hour | *today* |

Each has a coloured icon: brand green (waiting), sky (in consultation),
emerald (completed), violet (average), amber (longest).

### Left column — Live queue

The heart of the screen.

**Header:** "Live queue" with an **Open / Paused / Closed** badge, a list-view
toggle, a link out to the full queue page, and a **pause/reopen** button.

**Doctor tabs.** Up to three doctors as real links (the board works without
JavaScript), then an "All doctors" select for the rest. Changing doctor
re-renders the panel. Underneath: a dot and **"Token auto-call is ON/OFF"**.

**In consultation** card — blue-bordered. Status label (`In consultation`),
the token in a large chip, the patient's name, age and gender, minutes elapsed
since they went in, the time they started, and the actions available from
`in_consultation` (Completed, Transfer, Cancel).

**Next in line** card — amber-bordered. Status label, token, name, age and
gender, then to the right: **estimated wait**, the time they joined, and a
prominent **Call next** button, with the remaining verbs (Skip, Cancel,
Priority, Transfer, Hold) beneath it.

**Waiting in queue** list — headed `Waiting in queue (4)`, at most five rows,
each with:

- position number
- **token chip**, plus a red *Priority* badge and an amber *Waiting* badge
- patient name and patient code (`P-00012`)
- "joined 3:25 PM", and `· called 2×` when they have been called more than once
- actions — the first two verbs inline, the rest behind a **⋯** overflow menu
  (Cancel, Transfer, Hold live there on a phone)
- a chevron through to the visit

Below the list, **"View all in queue (4)"** when there are more than five.

The panel **polls every 5 seconds** against `/app/panel`.

### Right rail

**Patient tracking.** A QR code and the public tracking URL, with a **Copy
link** button and the room and specialty under it. The explanation says what
it is for: *patients scan this, enter the token on their slip, and see their
own position — only for this doctor, not the whole waiting room.* A **Issue a
token** button sits below. If no screen key exists yet, it says so and offers
a link to set one up.

**Today at a glance.** Tokens issued · Completed · No shows · Skipped ·
Clinical priority · Longest wait. Plus **Open full reports** for anyone who
may see reports.

**System status.** "All systems operational", the timestamp of the last
refresh to the second, and a **Refresh now** button.

### Mobile

The same blocks as separate cards: stat tiles in a swipeable strip, rail cards
two-up from 380px, and a bottom tab bar with Live board, Appointments and
Patients.

---

## 2. Queue board — `/queue`

One doctor's queue, in full. Reached from the Live queue panel or a doctor tab.

**Header:** back arrow, queue name (`OPD — Nuwan Perera`), an open/paused
badge, and beneath it *doctor · specialty · room · session date*.

**Controls:**

- **Queue switcher** — a select of every queue open today with its waiting
  count, e.g. `OPD — Amara Silva (1)`. Submits on change.
- **Scan box** — "Scan a slip or type A012". A barcode reader is just a
  keyboard: it types the code and presses Enter, so this is a real form with
  autofocus, and the field is wide enough to hold a whole tracking URL, which
  is what the slip's QR actually contains.
- **New patient** — through to the token desk, pre-set to this queue.

**Session banner** — colour-coded and worded for the state:

- *not started* (amber): "The doctor has not started this session yet. Tokens
  are still being issued and queued. Press start when the doctor is actually
  ready — **calling is blocked until then**, so nobody is called into an empty
  room." With a **Start session** button.
- *running* (emerald): "Session running — patients can be called. Started
  3:25 PM. 4 waiting."
- *paused / closed* (grey): "Patients keep their place. Resume the session to
  continue calling."

**Three stat cards:** Now serving (token, and who is in the room), Waiting
(count in this queue), Tokens issued (with the prefix).

**Live queue** card — the full list, ordered **priority first, then arrival
order**. Every row has position, token chip, priority badge, status badge,
name, patient code, joined time, call count, and its action buttons — on a
phone the actions drop to their own line so the name is never squeezed out.
Rows are colour-flagged: sky for called, a red left border for priority.

---

## 3. New patient (the token desk) — `/queue/new`

Two steps on one screen.

**Step 1 · Who is it?** A live search box. Type two or more characters and
matching patients appear with name, phone, patient code and age — scoped to
this clinic only. Each result shows whether they are **already in a queue
today** ("In OPD now · A001"), so you cannot issue a duplicate. Clicking a
result fills the form and hides the walk-in fields. If nobody matches you get
**Register "Zzz Nobody" as a new walk-in →**, which prefills the name and
puts the cursor in the phone field.

There is also an **Or check in a booking** list — today's appointments, each
with a **Check in** button, because a booked patient checking in keeps their
slot priority.

The whole step works with JavaScript disabled: results are real links
(`?patient=12`) and there is a plain search form in a `<noscript>` block.

**Step 2 · Issue the token.** Pick the queue (defaulting to the one you came
from), and the button tells you exactly what will happen — *"Issuing to Anura
Dissanayake…"*. Fields for a new walk-in: name, phone, gender, birth year.

On success you land on **Token issued**, showing the token, a **Print token**
button, and a **Copy link** for the patient's own tracking page.

---

## 4. A visit — `/queue/entry?id=…`

Everything about one token.

- **Visit** — token, patient, status, who they are waiting for, timings.
- **Actions** — the verbs legal from the current state. Actions that need a
  reason ask for one in a proper dialog: **priority** requires one (it is
  written to the audit log), **hold** defaults to "Stepped away" because a
  hold without a reason is just a mystery row on the board, **cancel** is
  optional, **transfer** asks for a destination from a list of today's open
  queues — not "type 1, 2 or 3" — and then a reason.
- **Service steps** — if the queue has a workflow, the steps for this visit
  with **Tick** and **Undo**.
- **History** — every event on this token with from/to states and timestamps.

---

## 5. Appointments — `/appointments`

Appointments made ahead of time. **A booked patient who checks in keeps their
slot priority** — that is the whole point of the page.

**Filter bar:** date picker, doctor select, **Show**, **Today**.

**The day's list** — each row: time in monospace, duration, patient name,
doctor, phone, and the **source** (where the booking came from). A booked
appointment has a **Check in** button; otherwise a status badge
(`checked_in`, `served`, …).

**Book an appointment** (right column, sticky on desktop): patient name (a
walk-in or a known patient), phone, doctor, date and time, duration, notes.
Disabled with an explanation if there are no doctors.

**Cancel a day** — at the bottom, guarded by a confirmation: cancel every live
booking for one doctor on one day, with a reason ("Doctor unwell,
rescheduled theatre list…"). Each patient will need a call, and the page says
so before you confirm.

---

## 6. Patients — `/patients`

The clinic's own register. *"12 records in this clinic · private to you."*

**Search** by name, phone or patient code, with **Clear**.

**Table:** avatar with initials, name with gender and age underneath, phone,
patient code (`P-00012`), visit count, last seen as a relative time
("3 days ago"), and **Open**.

### A patient record — `/patients/{id}`

- **Edit record** — name, phone, gender, birth year, address, notes. Save
  changes.
- **Visit history** — every visit: token, when, doctor, status, and how long
  they waited.

---

## 7. Doctors & rooms — `/doctors`

**Doctors** — one card each, with specialty, room, consultation fee, and an
**On leave today** badge when relevant. Each card has:

- **Add leave** — start date, end date, reason. A doctor on leave is **not
  offered on the booking form**; existing appointments are left alone (the
  page says so).
- **Edit** — name, specialty, room, fee.

**Add a doctor** — name, specialty, room, fee, and **Add doctor & open
queue** creates the doctor and starts today's queue for them in one go.

**Upcoming leave** — who is away, when, and why.

**Rooms** — the room list with add/edit/remove. A room still assigned to a
doctor cannot be removed.

**Specialties** — the specialty list.

---

## 8. Departments — `/departments`

Optional grouping for larger clinics. A department has a name, code
(`OPD`), floor, description, a lead doctor, and an active flag.

- **Add / edit / deactivate** — deactivating keeps its queues and history.
- **Display link** — each department can be given its own screen key, so a
  screen can show just that department. Issuing a new one warns that screens
  using the old link stop updating.
- **Today's assignments** — assign a queue or a doctor to a department. This
  is what makes the per-department display and the *By department* report
  work.

---

## 9. Schedules & holidays — `/calendar`

Two things: each doctor's weekly pattern, and the days the clinic is shut.

**Doctor weekly schedule** — pick a doctor, set the times they work each day,
save. **Clear schedule** (confirm first) puts them back on the clinic's
default hours. Used by the online booking form to offer only real slots.

**Clinic closure days** — public holidays and closures. **Close a date** adds
one; **Reopen** (confirm first) puts a date back.

Upcoming holidays are listed here and summarised on the Settings page.

---

## 10. Breaks — `/breaks`

**Today's queues** — start a break or end one per queue, with a type and a
reason. A break is shown on the board and the waiting-room screen, and the
estimated waits stretch to cover it.

**Plan a weekly break** — a recurring slot (e.g. lunch at 1pm), which can be
removed from the weekly plan.

**Break history** — Started, Queue, Type, Length, Reason.

---

## 11. Queue settings — `/queues/*`

Four pages that decide how tokens behave.

### Queue settings — `/queues/config`

- **Defaults** — clinic-wide queue defaults.
- **Open today** — each queue with **Rename** and **Set prefix** (so Dr
  Perera's queue issues `A`, Dr Silva's `B`).

### Priority rules — `/queues/priority-rules`

Rules that raise a token's priority automatically — by age, by pregnancy, by
service, by how long they have waited. Rules are **ordered** (drag or **Save
order**), each can be toggled on and off, and each records **who is
overriding priority** and why.

Two panels worth knowing: **What each rule does today** (a read-only preview
of what the rules currently produce) and **Who is overriding priority**
(manual priority set by staff, which outranks the rules). Every change is
kept in **Rule history**, and every priority a rule applies is logged with
its reason.

### Service workflows — `/queues/workflows`

Multi-step visits: a patient goes to the counter, then triage, then the
doctor. Each **step** has a name and an order, and shows up on the visit page
with **Tick** and **Undo**. Removing a step keeps the record for visits that
already passed it.

### Appointment policies — `/queues/appointment-policies`

What happens when a booked patient is late, or cancels close to their slot —
per service, with a clinic default and per-service **overrides**. Two panels:
**What each service currently does** and **Configured overrides**.

---

## 12. Reports — `/reports`

Pick a date (for the day view) and a from/to range (for everything else).
**Print** and **Export this day** (CSV) are in the header.

- **Arrivals by hour** — when patients actually come in.
- **Outcomes** — completed / skipped / no-show / cancelled over the range.
- **By doctor** — Seen, Completed, Avg wait, Avg consult, Priority.
- **By department** — Patients, Avg wait, Events.
- **Waiting time** — percentiles, not just the mean, because the average
  hides the person who waited two hours.
- **Consultation revenue** — Visits, Fee, Billed.
- **Patient satisfaction** — Answers, Average (from the rating link patients
  get after a visit).
- **Doctor delays** — Worst delay per doctor.
- **Staff activity** — per staff member, role and action count (clinic owners
  only).

---

## 13. Audit log — `/audit`

Every state-changing action, newest first: **When · Actor · Action · Entity ·
Detail · IP**. Filterable by action. This is the record you produce when
someone asks why a token was cancelled.

---

## 14. Subscription — `/billing`

- **Current plan** and what it includes.
- **Usage against your plan** — patients, staff, tokens.
- **SMS & WhatsApp usage** — messages sent against the allowance.
- **Change plan** — switch between Starter, Professional, Clinic Plus and
  Enterprise (confirm first).
- **Invoices** — Invoice, Period, Amount, Status, with **I have paid** for
  offline bank transfer ("entered it wrong?" withdraws the claim).

---

## 15. Settings — `/settings`

Five tabs.

### Clinic settings

- **Clinic profile** — name, phone, email, address, city, district. **Edit
  profile** opens a full-width inline panel.
- **Operating hours** — a read-only weekly summary (Monday 08:30 AM – 12:30
  PM, Sunday Closed) and **Upcoming holidays**. **Edit hours** opens the
  editor: seven rows, each with a Closed checkbox and open/close times
  (checking Closed disables that row's time fields), plus the **booking
  window** in days. Saves to the clinic's real hours, which drive the online
  booking grid.
- **Staff & access** — every account: name, email, role, status, last active.
  Invite new staff; pending invitations show in the same table.
- **System & data** — **Export clinic data** and **Audit log** (both owner
  only; hidden rather than 403 for everyone else).
- **Danger zone** — **Clear data**, behind a confirmation that makes you type
  the clinic name. Deletes every patient, token and appointment; staff
  accounts are kept.
- **Settings guide** — short explainers.

### Team

**Staff accounts** — name, email, role, and status (Active / Disabled).
**Pending invitations** — email, role, invited date, expiry. **Invite a team
member** by email and role; when no mail transport is configured the
invitation link is shown once to whoever created it, since that is the only
way it will reach the person.

Branches live on their own page (`/settings/branches`) — who can reach which
branch, with **Grant** and **Remove**.

### Notifications

**Alert preferences** — three clinic-wide switches, each with a one-line
explanation:

- **Queue alerts** — long waits and stalled queues
- **System updates** — announcements about the product itself
- **Daily summary** — a summary of the day's queue at close

"Unticking a box turns that alert off — nothing is sent for a switch that is
not ticked." Only a clinic owner can change them.

Plus **Message wording** (`/settings/templates`) — edit the actual text that
goes out, with **Insert:** chips for the merge placeholders (`{token}`,
`{patient}`…) and **Use the default** to revert one message.

### Integrations

Five services, each connectable and disconnectable, with a connected count:

- **SMS gateway** — sender ID, endpoint, credentials
- **Email delivery**
- **Accounting**
- **Patient app**
- **Webhooks** — an endpoint URL for your own systems

Each takes its own settings (a sender ID and endpoint URL, an organisation
reference) rather than one shared blob.

### Billing

Plan, invoices, payment claims.

### Also under Settings

- **Language & region** — interface language and timezone.
- **Offline desks** (`/settings/offline`) — for when the internet drops.
  **Set aside numbers** reserves a block of tokens; **Issue from the block**
  hands them out offline; **Sync now** reconciles when the connection
  returns. The table shows Queue, Next number, Set aside, Taken, Used, Left,
  State.
- **Export clinic data** (`/settings/export`) — pick what to include, build
  and download a single archive. The preview lists each file, what it holds
  and how many rows.

---

## 16. Notifications — `/notifications`

Every message the system sent: **When · To · Template · Token · Status ·
Detail**. **Mark read** on one, **Mark all read** for the lot.

---

## 17. Support — `/support`

**Open a ticket** — subject and message. **Your tickets** — the thread, with
replies from both sides. Reachable by every role, because the person who
notices the problem is rarely the owner.

Platform staff answer from the **Support queue** in the admin console.

---

## 18. Incidents — `/incidents`

When something breaks, this is where it is written down.

**Open an incident** — title, severity (minor / major / critical), summary.
Open incidents sort first, then newest. Each incident has its own page with a
**timeline** — *append-only*, so a correction is a new entry saying it
corrects one, and a post-mortem cannot quietly rewrite itself. Progress it
with **I am on it** (acknowledge), **Add** (a timeline entry), **Mark
mitigated**, **Resolve**.

---

## 19. Group / branches — `/organisation`

Only appears when the clinic belongs to a group. A **summary** across
branches — branch, city, seen today, waiting, average wait, staff, status —
with no patient names and no tokens: the difference between a useful roll-up
and a privacy incident.

**Branches** — which staff reach which branch, and the owner's grant screen.
Switch the branch you are working in from the header (or the drawer on a
phone).

---

## 20. Getting started — `/getting-started`

Seven steps, each ticked automatically when the data behind it exists, with
an explanation of *why* it matters and a **fix** button that takes you to the
page that satisfies it:

1. Set the clinic's opening hours
2. Add the doctors who will actually see patients
3. Give each doctor a weekly schedule
4. Invite the staff who will use the desk
5. Add the clinic's phone and address
6. Put the waiting-room screen up
7. Issue a token and watch the board

Blocking steps are marked, so a half-configured clinic is obvious. There is
also a **display setup** sub-page for the TV.

---

## 21. Screens

### Waiting room TV — `/display`

The screen the waiting room looks at. Big tokens, big names, and — depending
on how many doctors are working — either equal cards per doctor or one
solo panel with an up-next list. A called room is ringed rather than enlarged.

Controls (staff preview only): **Full screen**, **Voice announcements on/off**,
**Rotate key** (confirm first — every screen using the old link stops
updating), and the **self check-in kiosk** setup with its own URL and
**Switch off**. An amber banner appears across the top when the screen loses
its connection.

### Self check-in kiosk — `/kiosk/{key}`

A tablet on a stand with a short form — full name, phone, and which queue —
and a **Get my number** button. It issues a real token into the same queue,
not a placeholder, and shows the result as a printable slip.

### Public board — `/board/{key}` and `/live/{key}`

A read-only board at a URL that needs no sign-in. **Where do I stand?**
(`/board/{key}/position`) lets someone type a token and see their position —
no name, no phone, nothing that lets one patient identify another.

### Per-department board

Same screens, filtered to one department, using that department's display
key.

---

## 22. Patient-facing

### Track link — `/t/{token}`

The link printed on the slip and encoded in its QR. A patient sees their own
position, the estimated wait, and the doctor they are waiting for — **only
for their own queue**. They can:

- **Move my appointment to this time** (reschedule)
- **Cancel my visit** (confirm first — it gives up their place)
- **Rate the visit** afterwards, which feeds the satisfaction report

The link is a long unguessable token and expires after 18 hours
(`security.track_token_hours`) — afterwards it renders as an "expired"
page rather than continuing to show the queue.

### Online booking — `/book/{slug}`

The clinic's public booking page. Pick a service and a doctor, see only the
slots that are real (doctor's schedule, clinic hours, holidays, existing
bookings, and the booking window from Settings), and **Request appointment**.
Doctors on leave are not offered.

### Manage a booking — `/a/{token}`

The link in a booking confirmation. **Show times**, **Move my appointment**,
or **I cannot come — cancel it**.

---

## 23. Platform console (super admin)

Sign in as the platform operator and the console is a different product.

- **Overview** — tenants at a glance: clinic, plan, status, staff, tokens
  today; MRR; **Needs attention**; **Today at a glance**; support queue;
  system status.
- **Clinics** — every tenant. Edit, **Suspend** (no tokens until reinstated)
  and **Reinstate**.
- **Groups** — create an organisation and attach/detach clinics.
- **Feature gates** — entitlements per clinic per feature (SMS, WhatsApp,
  display screens, online booking, reports, departments). An override beats
  the plan default; enforcement is in the service layer, not just the UI.
- **Plans & billing** — the plans themselves (Starter 3,500 / Professional
  7,900 / Clinic Plus 14,900 / Enterprise) and recent invoices.
- **Payment verification** — clinics claim they have paid by bank transfer;
  verify or reject each claim.
- **Platform health** — the checks the external monitor runs, as a page:
  database, notification worker, backups, schema parity, stale queues, each
  with its severity and detail, alongside recent incidents.
- **Support queue** — every ticket, with reply and status.

---

## 24. Offline and installed

The app is a **PWA** — installable, with a manifest and a service worker.
When the connection drops the board says so rather than pretending, and the
**offline desk** keeps issuing numbers from a reserved block so the queue
keeps moving. It reconciles on reconnect.

---

## 25. The API, for anyone extending it

`GET /api/queue/board`, `POST /api/queue/action`, `GET /api/patient/search`,
`GET /api/summary`, `GET /api/display/{key}`, `GET /api/track/{token}`,
`GET /api/health` (bearer token or super admin), and the offline pair
`POST /api/queue/offline/block` / `offline/sync`.

---

## 26. Running it

```bash
npm install
npm run migrate && npm run seed     # demo data
npm run fleet                       # every role on its own port
```

| Port | Role | Identity |
|---|---|---|
| 8080 | Clinic owner | admin@serenity.lk |
| 8081 | Reception | reception@serenity.lk |
| 8082 | Doctor | amara@serenity.lk |
| 8083 | Platform admin | owner@queuepilot.app |
| 8084 | Clinic owner (2nd clinic) | admin@lakeview.lk |

`npm run lint`, `npm test` (273 checks), `npm run build`.

---

*Everything above is enforced somewhere real: the state machine refuses
transitions the UI will not offer, the routes re-check the ability the button
was hidden behind, and the audit log records every mutation.*
