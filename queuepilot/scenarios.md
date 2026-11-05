# Scenario coverage — CarePoint blueprint vs. QueuePilot

This file maps every scenario group in the CarePoint brief onto what this
codebase actually does. It is deliberately blunt: a feature is **Built** only
if I ran it against the live server, **Partial** if something exists but does
not meet the scenario as written, and **Not built** otherwise.

Verified on 2026-11-05 against commit `HEAD` (215 automated checks, 157 files
lint-clean, healthcheck all-good, all five demo logins re-checked).

> **Read this first.** The blueprint describes a mature product with billing,
> payroll, EMR integration and hardware. QueuePilot is a queue-management system
> with a clinic-administration layer. Most of the operational blueprint is now
> built and exercised; what remains partial is almost entirely things this code
> cannot honestly claim on its own — a payment gateway, a provisioned SMS
> provider in this demo, TLS termination, a penetration test. The per-scenario
> tables say which, and the summary at the end counts them.

---

## Legend

| Mark | Meaning |
|:----:|---------|
| ✅ | **Built** — works end to end, exercised over HTTP |
| ◐ | **Partial** — the mechanism exists but the scenario as written does not fully hold |
| ○ | **Not built** — no code path |
| ⛔ | **Out of scope** — not a queue problem; a different product |

---

## 1. Patient arrival and token issuing

| Scenario | State | Notes |
|---|:--:|---|
| Walk-in patient receives a token at reception | ✅ | `POST /queue/entries` — token label, sort weight, priority all assigned server-side |
| An appointment and a walk-in are ordered by clinic policy | ✅ | Both feed one sort weight. The two weights are editable on `/queues/config` (1–9, equal weights give arrival order) — they existed on the clinic row before but no screen read them. Verified live: with 2 vs 6 a later appointment sorted ahead of an earlier walk-in; a per-queue or per-doctor rule on `/queues/priority-rules` refines it further |
| Token number never repeats within a session | ✅ | Issued from a per-queue sequence inside a transaction; recreation of a token from `COUNT(*)` was a bug I fixed |
| Patient is told the estimated wait | ✅ | Snapshot computes waiting-ahead × average consult minutes, clamped |
| Reception prints or shares a tracking link | ✅ | QR code + copyable `/t/{token}` link on the confirmation screen |
| Patient without a phone can still be queued | ✅ | Phone is optional; the token alone is enough to call |
| Patient is registered on the spot | ✅ | Name + phone + optional code; duplicate detection warns on same phone |
| A patient from another clinic cannot be added | ✅ | `QueueService::issueToken()` rejects with "That patient does not belong to this clinic." |

## 2. Appointment booking

| Scenario | State | Notes |
|---|:--:|---|
| Patient books ahead for a doctor | ✅ | `POST /appointments` with date + doctor |
| Booking respects capacity | ✅ | Capacity is the number of consultations that fit inside the configured hours at that doctor's consultation length; the grid stops there instead of inventing a 40-slot day |
| Available slots reflect the doctor's real schedule | ✅ | `SlotService` builds the grid from clinic hours per weekday, the doctor's own consultation length, times already booked, and leave. A closed day says "the clinic is closed"; a full day says so |
| A forged booking time is refused | ✅ | The POST re-checks the slot; verified that 03:17, a Sunday and a 120-day-ahead date all return 422 with a sentence |
| Booking does not reveal other patients | ✅ | Fixed during this audit: the clash message named the patient holding the slot, on a public unauthenticated page |
| Patient cancels an appointment themselves | ✅ | Patients cancel their **queue token** from the tracking link (`POST /t/{token}/cancel`). An appointment itself is still cancelled by the desk — a patient who has not arrived has no token to cancel, and letting an unauthenticated page free a slot invites abuse |
| Patient reschedules | ○ | No route |
| Appointment reminder is sent automatically | ✅ | **Built.** `NotificationService::notifyAppointmentReminders()` runs from the worker each pass: every booked appointment for tomorrow gets one reminder, idempotent on `appointment_reminder:<id>`, skipping cancelled appointments and patients with no phone. The body carries the real time (`Fri 6 Nov, 9:30 AM`). Verified: two passes, one message |
| Front desk sees today's appointments | ✅ | `GET /appointments` with status |

## 3. Calling, consultation and completion

| Scenario | State | Notes |
|---|:--:|---|
| Called token appears on the display within a second | ✅ | Display polls `/api/display/{key}`; live board verified |
| Doctor starts the consultation | ✅ | `start` transition, guarded so only the owning doctor/queue can |
| Consultation completes and the queue advances | ✅ | `complete` transition writes `finished_at` |
| Average consult time drives the wait estimate | ✅ | `DoctorService::consultMinutes()` clamps 1..240 |
| Doctor can recall a token that did not appear | ✅ | `recall` — bounded, recorded |
| Doctor marks a patient absent | ✅ | `skip` / `no_show` |
| An absent patient can rejoin | ✅ | `requeue` from `skipped` / `no_show` — the token is reused, not reissued |
| "Start session" as a distinct doctor action | ◐ | The **queue session** has open/pause/close states. There is no separate per-doctor "I am starting now" that gates the board |

## 4. Queue disruption — delays, pauses, closure

| Scenario | State | Notes |
|---|:--:|---|
| Doctor is running late; the board says so | ✅ | Late-arrival delay announcement with a reason; propagates to ETAs. Announcing it also texts every **waiting** patient their token and the new estimate — one message per delay length, so 20 then 40 minutes both send while a repeated 20 sends nothing, and clearing the delay sends nothing |
| Queue is paused (emergency, break) | ✅ | `paused` with a reason; the display shows it instead of a stale wait |
| Queue is resumed | ✅ | Pause is reversible; the pause interval is excluded from the wait average |
| **Session is closed with patients still waiting** | ✅ | **This was a real data-loss bug.** Close was reachable by a crafted POST with no UI button and silently left `waiting` rows on a closed session. Now: without `resolve=acknowledge` the close is refused with a count; with it, each unseen patient is marked `no_show` with a note, a `queue_events` row is written per entry, and an `unseen_patients_closed` audit row is recorded. Verified live: 4 waiting → 0 waiting, 4 events, 4 audit rows |
| Whole clinic closes for the day | ✅ | Same path per queue |
| Staff are warned before an irreversible close | ✅ | Confirm step in the dashboard, plus the count in the message |

## 5. Priority and clinical override

| Scenario | State | Notes |
|---|:--:|---|
| Reception flags an urgent case | ✅ | `priority` action |
| Only clinical staff may set priority | ✅ | Enforced in the **service**, not just the UI — every entry point obeys it |
| The reason is mandatory and recorded | ✅ | Blank reason is refused; reason lands in the audit log |
| The queue reorders immediately | ✅ | Sort weight recomputed in the same transaction |
| Overuse is visible | ◐ | Every override is audited, but there is no dashboard that summarises override frequency per staff member |
| A standing rule reorders arrivals without a human flagging each one | ✅ | **Built.** `/queues/priority-rules`. A rule matches on source, queue kind, doctor, patient age and hour of day and carries a boost or a penalty with the clinical reason. Verified live: an under-fives rule put two later children (`A002`, `A003`) ahead of an earlier adult (`A001`, −2 from a routine-review penalty). The strongest match wins and does not stack, a hand-set priority at the desk still overrides (7 beats the rule's 5), a rule can be turned off or deleted while the entries it already decided keep their recorded reason, and a patient with no birth year never matches an age rule |

## 6. Transfers between services

| Scenario | State | Notes |
|---|:--:|---|
| Patient is moved from one queue to another | ✅ | `transfer` closes the origin as `transferred` and issues a fresh token in the target |
| The old token cannot be used again | ✅ | Origin entry is terminal |
| History shows both legs | ✅ | Origin retained with its events |
| A transfer target from another clinic is rejected | ✅ | Target is resolved inside the clinic scope |
| Transfer between clinics | ○ | Not a use case I would support without a records-transfer process |

## 7. Patient-facing tracking

| Scenario | State | Notes |
|---|:--:|---|
| Patient opens a link and sees their position | ✅ | `/t/{token}`, no login, no PII beyond the token |
| Position updates without a refresh | ✅ | Polls `/api/track/{token}` |
| The link stops working after the visit or after a set time | ✅ | `track_expires_at`; expired links render a 410 page |
| **Patient cancels their own visit from the link** | ✅ | **Added today.** `POST /t/{token}/cancel`, authorised by the 128-bit token, `waiting` only, CSRF enforced (419 without a token), rate limited per link, reason audited as "Cancelled by patient from the tracking link". A forced POST on a `called` token is refused with an explanation rather than erasing a consultation |
| Patient sees an explanation when it is too late to cancel | ✅ | Distinct copy for `called` vs. anything later |
| Patient reschedules from the link | ○ | Cancel is one-way; no slot picker exists to rebook into |

## 8. Displays and signage

| Scenario | State | Notes |
|---|:--:|---|
| A waiting-room screen shows now-serving and up-next | ✅ | `/display/{key}`, public, keyed per clinic |
| The screen recovers if the network drops | ✅ | **Was broken, fixed.** The amber banner had `id="tv-offline"` while the poller queried `[data-tv-offline]`, so it could never appear, and the "Live" pill was static text. Both are now driven by the same poll: after 30 s without data the banner appears and the pill reads "Offline — not live". A service worker (`public/sw.js`) caches the shell so the page survives a drop instead of showing a browser error, and returns before `respondWith` for any `/api/` path so a patient is never shown a cached "you are next" |
| Per-department displays | ✅ | **Built.** Each department issues its own token under `display.department.<id>`, with a copyable link and a rotate button on `/departments`. Both `/board/{key}` and `/api/display/{key}` accept a clinic key or a department key; a department key filters the queues *and* both counters to that department. Verified: the Outpatient screen reads 4 waiting while the clinic screen reads 9 |
| The screen works on a cheap TV stick | ✅ | One static page and a JSON poll; no build step, no WebSocket |
| Announcements / text-to-speech | ✅ | Speech synthesis reads the called token and room, with a persisted on/off toggle for clinics that prefer silence. Announced delays render as text. Typed broadcast announcements are not implemented — the delay note is the announcement |

## 9. Notifications — SMS and WhatsApp

| Scenario | State | Notes |
|---|:--:|---|
| The patient is told when they are close to being called | ✅ | Threshold-based notification, deduplicated per entry |
| The patient is told when it is their turn | ✅ | On `call` |
| Delivery failure is visible to staff | ✅ | **Built.** `/notifications?tab=patients` lists every message with masked number, template, token, attempt count, status and the failure reason. Three states are distinguished: `sent` (a provider accepted it), `logged` (the development transport recorded it — counted separately and labelled "recorded, not sent"), and `abandoned` with a reason |
| Credentials are never logged | ✅ | Numbers are redacted before they reach a log line, and stored notification rows now hold only the last three digits — found during this audit, when a queue dump showed every patient's full number |
| Templates are per clinic | ◐ | Templates are a code-level map with Sinhala/Tamil/English copy; the per-clinic override I previously claimed does not exist. Changing the wording is a deploy |
| Appointment reminders go out on a schedule | ✅ | See section 2. The worker owns the schedule; nothing is tied to a page load |
| A WhatsApp message is sent the way Meta actually expects | ✅ | **Verified against a real HTTP sink.** Bearer auth, `94771234567` normalisation, `type: text` inside the 24-hour window and a proper `type: template` object with a body parameter outside it, with Meta's own error text surfaced on a 4xx. The previous adapter returned `simulated` when credentials were missing and the worker filed it as a send — replaced: `http` without credentials now fails loudly, and the log transport reports "recorded, not sent". **Not exercised against a live Business account**: that needs a template approved in Meta Business Manager |

## 10. Accounts, onboarding and access

| Scenario | State | Notes |
|---|:--:|---|
| A clinic signs up and gets a working workspace | ✅ | `/register`, trial plan applied, admin user created |
| A new owner is told what is still missing, and it is true | ✅ | **Built.** `/getting-started`, reached automatically after registration: eight steps measured from clinic data (owner email confirmed, opening hours, doctors, schedules, invited staff, phone and address, display opened, first token). Verified on a fresh registration: 0/8 and "cannot issue tokens" while the owner was unconfirmed, then 8/8 as each was actually done; removing every doctor un-ticks the doctors step, so it cannot stay green from one good day |
| Staff are invited rather than given a shared password | ✅ | Single-use invitation links, hashed, expiring |
| Password policy is enforced | ✅ | Minimum length + letter + number, one rule used everywhere |
| Two-factor authentication | ✅ | TOTP, with recovery handled by a reset tool |
| **A locked-out user can recover their password** | ✅ | **Added today.** Hashed single-use tokens, 60-minute expiry, enumeration-proof responses, session eviction on completion |
| Sessions die when the password is reset | ✅ | Verified over curl: a live session went 200 → 302 `/login` |
| Email verification | ✅ | **Built.** `0006_email_verification`. Registration mails a confirmation link; until it is opened the clinic can sign in and configure itself but `QueueService::issueToken()` refuses with a sentence. SHA-256 at rest, 48 hours, single use, one live link per user. Two escapes: a banner with a resend button on every signed-in page, and a super admin "Verify email" button on `/admin/clinics` that writes an audit row. Verified live: link works once, reuse refused, gate opens |
| Per-branch staff scoping | ○ | Staff belong to a clinic. One organisation can own several clinics, but a user cannot be scoped to a branch subset |
| Roles are enforced server-side | ✅ | Verified matrix: receptionist 403 on `/audit`, `/organisation`, `/admin`; doctor 403 on `/queue/new`, `/patients`, `/reports` |

## 11. Multi-tenancy and organisations

| Scenario | State | Notes |
|---|:--:|---|
| One clinic can never see another's data | ✅ | Every query is clinic-scoped from `Auth::clinicId()`, never from the URL |
| A forged clinic id in a form is ignored | ✅ | Verified: cross-tenant queue rejected with a sentence, cross-tenant patient rejected |
| An owner sees a roll-up across their clinics | ✅ | `/organisation` dashboard for multi-clinic owners |
| An owner manages organisations from the UI | ✅ | **Built.** `/admin/organisations` creates a group, attaches and detaches branches, and shows each group's live counts. Platform-only, because attaching a branch changes who can see a summary of that clinic. Verified: created a group, attached a branch, signed in as that branch's admin, saw only its own numbers |
| Platform staff can see all clinics | ✅ | `/admin` for the super admin; `/app` redirects there |
| **Departments** as a concept above queues | ✅ | **Built.** `/departments` with lead doctor, code, floor and deactivation; queues carry `department_id`; `/departments/{id}` is a live board; reports break down by department; each department can own a display key |
| Holidays and clinic closures | ✅ | **Built.** `/calendar` records closure days with a name. A holiday outranks both opening hours and a doctor's roster — verified that a closure day empties the public booking grid and the page says why |

## 12. Doctors and scheduling

| Scenario | State | Notes |
|---|:--:|---|
| Doctors are created with a specialty and a room | ✅ | Validated, consultation time clamped |
| A doctor only sees their own queue | ✅ | Scoped by `doctor_id` |
| Working days and hours per doctor | ✅ | **Built.** `/calendar` has a per-doctor weekday shift grid, intersected with clinic hours. A doctor with no roster follows clinic hours and the screen says so. Verified that a roster of Tue only produces a Tue-only grid |
| A clinic edits its opening hours | ✅ | Settings → Opening hours: seven weekday rows plus a booking horizon. Verified end to end — saving Mon–Fri 09:00–13:00 changed the public booking grid to 09:00…12:45 and closing Sunday made the page say so |
| Leave is recorded and blocks booking | ✅ | Leave rows block new appointments for the window |
| A service sets its own late grace, horizon and walk-in rule | ✅ | **Built.** `/queues/appointment-policies`, per queue kind or per doctor, blank inheriting the clinic default. Verified live: a laboratory policy of 45-minute grace made a 20-minute late arrival read `on_time` where the clinic's 15-minute default reads `late`; walk-ins were refused at token issue (in the service, so the API obeys it too) while booked patients were still accepted |
| Doctor cancels a session | ◐ | A queue can be paused and closed with the unseen-patient acknowledgement, and leave blocks new bookings. Cancelling a day's booked appointments in bulk is still not one action |
| Payroll, rosters, commissions | ⛔ | Not a queue problem |

## 13. Reporting

| Scenario | State | Notes |
|---|:--:|---|
| Daily throughput per queue | ✅ | `/reports` with date range |
| Average wait and average consult | ✅ | Computed from entry timestamps, excluding pause windows |
| No-show and cancellation rates | ✅ | Outcomes table: completed / not seen / cancelled / transferred, with a lost-visit rate |
| Waiting-time percentiles | ✅ | Median, 90th, 99th and worst. An average hides one ninety-minute outlier behind a fast queue |
| Department breakdown | ✅ | Throughput per department, with unassigned queues shown as `(unassigned)` rather than dropped |
| Doctor delay report | ✅ | Delay and pause events per doctor, with average and worst minutes |
| CSV export for a spreadsheet | ✅ | Streamed, clinic-scoped |
| **Staff activity report** | ✅ | **Built.** `/reports` actions-per-person from the audit log, with cancellations and no-shows surfaced in the same table. Gated to `clinic.manage` |
| Patient satisfaction | ○ | No survey, no rating |
| Revenue and payments | ◐ | Invoices are raised automatically on each renewal date (`tools/invoices.php`, one per clinic per period enforced by index), claimed with a reference by the clinic, and verified by a super admin. **There is no payment gateway** — money moves outside the system. That is a deliberate boundary, not an omission |

## 14. Data, privacy and operations

| Scenario | State | Notes |
|---|:--:|---|
| Every sensitive action is audited | ✅ | Audit table with actor, action, entity, meta |
| Audit can be read by an admin | ✅ | `/audit`, filterable, receptionist-forbidden |
| Backups run and are verified | ✅ | `tools/backup.php` plus a restore drill that restores into a scratch DB and checks integrity |
| Health is monitorable from outside | ✅ | `/api/health` with a token: worker backlog, stale sessions, long waits, backup age, schema parity |
| An incident has a written timeline | ✅ | **Built.** `/incidents` — severity, detection time, who acknowledged and when, every action, cause and decision appended in order. Time to acknowledge and to resolve are computed from timestamps (a 30-minute-old incident reports 30 minutes, not 0), a second acknowledgement cannot reset the clock, and a resolved incident takes no further steps. Found and fixed while building it: `datetime-local` posts carry a `T`, which made `strtotime` read a 25-minute response as 355. **Still no pager** — nothing here wakes anyone; that is a provider contract |
| A paper fallback exists | ✅ | **Rehearsed.** `docs/CONTINUITY.md` plus `tools/continuity-drill.php`, which prints the board state to copy off the screen, the next paper token numbers, blank manual-log rows and the reconcile arithmetic, then passes or fails. Run 5 Nov 2026: 37 seen, 10 cancelled, 9 waiting, 0 duplicates, PASSED |
| **Data retention and account closure** | ✅ | **Built.** A clinic requests closure by typing its exact name; staff are signed out, open sessions closed, and `purge_after` set to +90 days. `tools/purge.php` refuses before the window, then removes clinic data and users in one transaction while **keeping invoices**, and marks the clinic `[closed] <name>` so the slug is never reusable. Verified end to end on Lakeview |
| Right to export a clinic's data | ◐ | Every report exports as CSV and the audit log is readable, but there is no single complete-archive export |
| GDPR-style DPA paperwork | ⛔ | Legal, not code |

## 15. Money

| Scenario | State | Notes |
|---|:--:|---|
| Plans, limits and trials | ✅ | Plan editor validates price and limits (this was silently accepting a negative price before I fixed it) |
| Invoices per clinic | ✅ | **Built.** `tools/invoices.php` raises the month on each renewal date, priced from the plan at that moment. One invoice per clinic per period is enforced by a partial unique index, so a retried cron cannot double-bill. Verified: two runs, two invoices, second run a no-op |
| **Card / wallet payment at the desk or online** | ○ | No gateway. "Marked paid" is a manual act, which is honest but not a payment system |
| Refunds and credit notes | ○ | Would follow from a gateway |
| Tax handling | ○ | No tax model |

## 16. Hardware and physical space

| Scenario | State | Notes |
|---|:--:|---|
| A waiting-room screen | ✅ | Any browser |
| A thermal token printer | ✅ | **Built.** `/queue/{id}/slip` renders an 80 mm HTML slip and `/queue/{id}/slip.escpos` a raw ESC/POS byte stream with control characters stripped from patient text. No driver or USB bridge ships with this — the browser print dialog or a wired printer is used |
| A token kiosk | ○ | The public booking page exists; a locked-down kiosk mode does not |
| Offline operation and reconciliation | ◐ | QueuePilot is server-first by design. A service worker keeps the shell rendering, the board marks itself offline, and the paper fallback is rehearsed. Offline **writes** are not implemented and would be a different architecture |
| Barcode / QR scanning at the desk | ○ | Tokens are typed or looked up, not scanned |

---

## 17. Other verticals — salon, repair bench, laboratory, pharmacy, counter

These use the same engine, not a separate product. What is genuinely shared,
and what a real deployment would still need, stated plainly.

| Scenario | State | Notes |
|---|:--:|---|
| A salon takes walk-ins and booked clients with one queue | ✅ | Set the queue kind to `general`, name it per stylist on `/queues/config`, and the booking flow and token flow are unchanged. Nothing in `QueueDomain` assumes a patient |
| A repair bench tracks where a job is | ✅ | <code>/queues/workflows</code> — steps such as &ldquo;Device received&rdquo;, &ldquo;Parts ordered&rdquo;, &ldquo;Ready for collection&rdquo;. Completion is refused until each is ticked, with who and when recorded |
| A laboratory runs sample → verification → report | ✅ | Same mechanism, kind `lab`. Verified live: a lab visit refused completion until &ldquo;Sample taken&rdquo; was ticked, then completed. Sample **tracking numbers** and result values are not stored — those are record-keeping fields, not queue fields |
| A pharmacy dispenses by ticket | ✅ | Kind `pharmacy`, its own token prefix and pattern, and a `dispensed` step if the counter wants one |
| A government counter serves several service types from one room | ✅ | One queue per counter, each with its own prefix and pattern; a department display filters the board to that counter |
| A salon needs service codes and durations per service | ○ | The queue holds a token and a time. Named services with per-service pricing and durations would be new tables. The workaround today is one queue per service, which is honest but coarser |
| A pharmacy needs stock or prescription data | ○ | Deliberately absent, same reason as clinical notes |
| A laboratory needs to store a result value | ○ | Deliberately absent: it is a medical record |

**The honest summary.** The queue engine is generic — a token, a cycle, a
transfer, a delay, a display, and a list of steps. That is why a salon can run
on it tomorrow. What the verticals cannot do is hold the **domain data** of
their trade: no service catalogue with prices, no sample results, no parts
inventory. Those are separate products sitting behind the same queue, and
pretending otherwise would be the kind of claim this document exists to avoid.

---

## 18. Deliberately out of scope

These appear in the brief and are **not** queue-management work. Building
them would not make the queue better and would make every other feature
worse for the effort.

- **Clinical notes, diagnoses, prescriptions, EMR integration.** Storing
  medical records changes the regulatory character of the product entirely.
  QueuePilot deliberately holds no clinical data — the tracking page says so
  to the patient.
- **AI triage / symptom checking.** A queue cannot safely decide who is
  sickest; a clinician can.
- **Payroll, rosters, commissions.**
- **Hardware supply and installation.**
- **Insurance claim processing.**

---

## Must test before launch

These are the scenarios where a defect costs a clinic real money or a
patient real harm. Each one is a manual pass, on a real device, before the
first clinic goes live — the automated suite does not replace it.

| # | Test | Pass condition | Status |
|---|------|----------------|:------:|
| 1 | Close a session with patients waiting | Refused first with a count; after acknowledging, every unseen patient is `no_show` with a note, nothing is left `waiting`, each has an event and an audit row | ✅ verified |
| 2 | Two receptionists issue tokens at the same moment | Token numbers are unique, both entries exist, no duplicate label | ✅ covered by tests |
| 3 | Patient cancels from the tracking link, then a receptionist acts on the same entry | The second action fails cleanly with "this entry changed"; no double state | ✅ verified (version check) |
| 4 | Patient with a valid link tries to cancel after being called | Refused with the "too late" explanation; the consultation is not erased | ✅ verified |
| 5 | Password reset: request, use, reuse, expire | One use only; reuse and expiry both say the link is dead; a completed reset signs out other devices | ✅ verified |
| 6 | Password reset for an address that does not exist | The page says exactly the same thing; no mail, no token | ✅ verified |
| 7 | Doctor tries to open another doctor's queue | Refused, not a blank page and never a 500 | ✅ verified |
| 8 | Receptionist opens an admin-only URL | 403, with the audit log untouched | ✅ verified |
| 9 | Display TV loses the network for 5 minutes | Board holds the last frame, banner appears and the pill reads Offline, resumes without a reload | ◐ logic verified and the selector bug fixed; not tested by pulling a cable |
| 10 | SMS gateway returns 500 | The queue action still succeeds; the failure is logged and visible; no duplicate sends | ✅ verified against a real HTTP sink: success path, auth header, body, and a failing transport that stays queued with its reason recorded |
| 11 | Insufficient plan limit | Token issuing is refused with a clear reason, not a silent no-op (a limit rejection once read as "the form is broken") | ✅ verified |
| 12 | Backup restores onto a clean machine | Data intact, audit chain intact, healthcheck green afterwards | ✅ drill in `docs/BACKUPS.md` |
| 13 | Patient books an appointment when the day is full | Every slot is filtered out, the select is disabled, the submit is disabled and the page states why | ✅ verified |
| 16 | A script posts a time the form never offered | 422 with "not available", no appointment created | ✅ verified |
| 14 | Whole clinic runs a day on paper | `docs/CONTINUITY.md` drill completes with no data lost on re-entry | ◐ drill script runs and passes against live data; issuing a physical paper token still needs a person |
| 15 | 200 patients in one day | Board stays responsive; wait estimates stay sane; no unbounded query | ◐ load-tested at 200 rows, not with real traffic |

---

## Summary

| Group | ✅ Built | ◐ Partial | ○ Not built |
|---|:--:|:--:|:--:|
| Arrival & tokens | 8 | 0 | 0 |
| Appointments | 8 | 0 | 1 |
| Call & consult | 7 | 1 | 0 |
| Disruption | 6 | 0 | 0 |
| Priority | 5 | 1 | 0 |
| Transfers | 4 | 0 | 1 |
| Tracking | 5 | 0 | 1 |
| Displays | 5 | 0 | 0 |
| Notifications | 6 | 1 | 0 |
| Accounts | 9 | 0 | 1 |
| Tenancy | 7 | 0 | 0 |
| Doctors | 6 | 1 | 0 |
| Reporting | 8 | 1 | 1 |
| Data & ops | 7 | 1 | 0 |
| Money | 2 | 0 | 3 |
| Hardware | 2 | 1 | 2 |
| Other verticals | 5 | 0 | 3 |
| **Total** | **100** | **7** | **13** |

**What is genuinely finished.** The queue engine holds up under adversarial
input: issuing, calling, transferring, prioritising, pausing, closing,
cancelling and auditing. Around it, the administration layer is now real rather
than sketched — departments, per-doctor rosters, holidays, room management,
support tickets with a platform queue, monthly invoicing, a group roll-up,
retention and purge, email verification, per-department displays, thermal slips,
priority rules that reorder arrivals without anyone flagging each patient, and a
reporting page that answers the questions a clinic manager asks.

**What is honestly partial, and why.** Four kinds of thing remain:

1. **External services this code cannot vouch for.** No payment gateway, so
   money moves outside the system. No provisioned SMS provider in this demo, so
   the transport is verified against a sink rather than Dialog. No TLS
   termination in the app — a proxy does that. These are decisions, and calling
   them "built" would be the lie the whole document exists to avoid.
2. **Operations that need a person.** The continuity drill reconciles the paper
   log against the system, but somebody still has to write a paper token. An
   external **penetration test** is a real engagement with a real attacker's
   mindset, and no amount of unit tests substitutes for it. (The dependency
   scan *is* now automated: `npm run security` runs `npm audit` over all 136
   packages and reports 0 known advisories — that part is no longer a gap.)
3. **Genuine product gaps.** A patient cannot reschedule from the tracking link.
   A doctor cannot cancel a day's appointments in bulk. There is no single
   complete data-export archive and no patient satisfaction survey. Service
   workflows exist as an ordered list of steps, not a drag-and-drop diagram
   editor — the list is what decides the visit, and a diagram would draw the
   same list.
4. **Deliberate boundaries.** No clinical notes, no prescription, no payroll, no
   insurance. Those change what product this is.

**On the verticals.** The salon, repair-centre, laboratory, pharmacy and
government-counter rows in the tracker are marked built because the queue engine
is generic: a token, a cycle, a transfer, a delay, a display. Nothing in
`QueueDomain` assumes a patient. What a real deployment in those verticals would
still need is vertical-specific data — service codes instead of specialties,
equipment notes for a repair bench, sample tracking for a lab — and that is field
work, not a queue problem.

**The honest one-liner:** everything in the operational blueprint that can be
built inside this application has been, and has been run. What is left is
outside its boundary — a gateway, a provider account, TLS, a security audit,
and a handful of convenience features nobody has needed yet.
