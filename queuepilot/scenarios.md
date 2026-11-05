# Scenario coverage — CarePoint blueprint vs. QueuePilot

This file maps every scenario group in the CarePoint brief onto what this
codebase actually does. It is deliberately blunt: a feature is **Built** only
if I ran it against the live server, **Partial** if something exists but does
not meet the scenario as written, and **Not built** otherwise.

Verified on 2026-11-05 against commit `22e8a05` (119 automated checks, 107
files lint-clean, healthcheck all-good).

> **Read this first.** The blueprint describes a mature product with billing,
> payroll, EMR integration and hardware. QueuePilot is a queue-management
> system with a clinic admin layer. Roughly a third of the blueprint is built,
> a third is partial, and the rest is either deliberately out of scope or
> would be a separate project. The per-scenario tables below say which.

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
| Patient cancels an appointment themselves | ○ | No route. Only a receptionist can act on an appointment, and even they can only check it in |
| Patient reschedules | ○ | No route |
| Appointment reminder is sent automatically | ◐ | A message template exists; nothing schedules or sends it |
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
| Doctor is running late; the board says so | ✅ | Late-arrival delay announcement with a reason; propagates to ETAs |
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
| The screen recovers if the network drops | ✅ | Keeps the last good frame, shows a "reconnecting" pill |
| Per-department displays | ◐ | The display is per clinic. Since there are no departments, it cannot be filtered to one |
| The screen works on a cheap TV stick | ✅ | One static page and a JSON poll; no build step, no WebSocket |
| Announcements / text-to-speech | ○ | Priority and call are visual only. TTS needs an audio device on the display host — out of scope |

## 9. Notifications — SMS and WhatsApp

| Scenario | State | Notes |
|---|:--:|---|
| The patient is told when they are close to being called | ✅ | Threshold-based notification, deduplicated per entry |
| The patient is told when it is their turn | ✅ | On `call` |
| Delivery failure is visible to staff | ◐ | Failures are logged and retried, but **there is no notification-history screen** — staff must read logs |
| Credentials are never logged | ✅ | Numbers are redacted before they reach a log line |
| Templates are per clinic | ✅ | Stored per clinic, editable |
| Appointment reminders go out on a schedule | ○ | Template only; no scheduler is attached to it |

## 10. Accounts, onboarding and access

| Scenario | State | Notes |
|---|:--:|---|
| A clinic signs up and gets a working workspace | ✅ | `/register`, trial plan applied, admin user created |
| Staff are invited rather than given a shared password | ✅ | Single-use invitation links, hashed, expiring |
| Password policy is enforced | ✅ | Minimum length + letter + number, one rule used everywhere |
| Two-factor authentication | ✅ | TOTP, with recovery handled by a reset tool |
| **A locked-out user can recover their password** | ✅ | **Added today.** Hashed single-use tokens, 60-minute expiry, enumeration-proof responses, session eviction on completion |
| Sessions die when the password is reset | ✅ | Verified over curl: a live session went 200 → 302 `/login` |
| Email verification | ○ | No `email_verified` column, no confirmation flow |
| Per-branch staff scoping | ○ | Staff belong to a clinic. One organisation can own several clinics, but a user cannot be scoped to a branch subset |
| Roles are enforced server-side | ✅ | Verified matrix: receptionist 403 on `/audit`, `/organisation`, `/admin`; doctor 403 on `/queue/new`, `/patients`, `/reports` |

## 11. Multi-tenancy and organisations

| Scenario | State | Notes |
|---|:--:|---|
| One clinic can never see another's data | ✅ | Every query is clinic-scoped from `Auth::clinicId()`, never from the URL |
| A forged clinic id in a form is ignored | ✅ | Verified: cross-tenant queue rejected with a sentence, cross-tenant patient rejected |
| An owner sees a roll-up across their clinics | ✅ | `/organisation` dashboard for multi-clinic owners |
| An owner manages organisations from the UI | ○ | The service and the roll-up exist; there is **no admin screen** for creating or editing organisations |
| Platform staff can see all clinics | ✅ | `/admin` for the super admin; `/app` redirects there |
| **Departments** as a concept above queues | ○ | The schema has `specialties`, not departments. There are no department-level queues, no department lead, no department display |
| Holidays and clinic closures | ○ | No holiday table, no closure calendar. A closed day is one queue at a time |

## 12. Doctors and scheduling

| Scenario | State | Notes |
|---|:--:|---|
| Doctors are created with a specialty and a room | ✅ | Validated, consultation time clamped |
| A doctor only sees their own queue | ✅ | Scoped by `doctor_id` |
| Working days and hours per doctor | ◐ | Hours exist **per clinic, per weekday** (`settings` key `hours.<0-6>`, default 08:30–12:30 when unset). A per-doctor roster is still not modelled |
| A clinic edits its opening hours | ✅ | Settings → Opening hours: seven weekday rows plus a booking horizon. Verified end to end — saving Mon–Fri 09:00–13:00 changed the public booking grid to 09:00…12:45 and closing Sunday made the page say so |
| Leave is recorded and blocks booking | ✅ | Leave rows block new appointments for the window |
| Doctor cancels a session | ◐ | The doctor can pause and close a queue, but cannot cancel appointments in bulk |
| Payroll, rosters, commissions | ⛔ | Not a queue problem |

## 13. Reporting

| Scenario | State | Notes |
|---|:--:|---|
| Daily throughput per queue | ✅ | `/reports` with date range |
| Average wait and average consult | ✅ | Computed from entry timestamps, excluding pause windows |
| No-show and cancellation rates | ✅ | Counted by terminal status |
| CSV export for a spreadsheet | ✅ | Streamed, clinic-scoped |
| **Staff activity report** | ○ | The audit log holds every action, but there is no per-user activity view |
| Patient satisfaction | ○ | No survey, no rating |
| Revenue and payments | ◐ | Invoices exist and can be marked paid. **No gateway**, no reconciliation, no receipts |

## 14. Data, privacy and operations

| Scenario | State | Notes |
|---|:--:|---|
| Every sensitive action is audited | ✅ | Audit table with actor, action, entity, meta |
| Audit can be read by an admin | ✅ | `/audit`, filterable, receptionist-forbidden |
| Backups run and are verified | ✅ | `tools/backup.php` plus a restore drill that restores into a scratch DB and checks integrity |
| Health is monitorable from outside | ✅ | `/api/health` with a token: worker backlog, stale sessions, long waits, backup age, schema parity |
| A paper fallback exists | ✅ | `docs/CONTINUITY.md` — the drill is written down, not implied |
| **Data retention and account closure** | ○ | No retention policy, no delete-my-clinic flow, no purge job |
| Right to export a clinic's data | ◐ | CSV per report; no complete export |
| GDPR-style DPA paperwork | ⛔ | Legal, not code |

## 15. Money

| Scenario | State | Notes |
|---|:--:|---|
| Plans, limits and trials | ✅ | Plan editor validates price and limits (this was silently accepting a negative price before I fixed it) |
| Invoices per clinic | ✅ | Generated and markable as paid |
| **Card / wallet payment at the desk or online** | ○ | No gateway. "Marked paid" is a manual act, which is honest but not a payment system |
| Refunds and credit notes | ○ | Would follow from a gateway |
| Tax handling | ○ | No tax model |

## 16. Hardware and physical space

| Scenario | State | Notes |
|---|:--:|---|
| A waiting-room screen | ✅ | Any browser |
| A thermal token printer | ○ | No print spooler, no ESC/POS. The QR and the token are on screen |
| A token kiosk | ○ | The public booking page exists; a locked-down kiosk mode does not |
| Offline operation and reconciliation | ○ | QueuePilot is server-first. If the server is down, the paper fallback in `docs/CONTINUITY.md` applies |
| Barcode / QR scanning at the desk | ○ | Tokens are typed or looked up, not scanned |

---

## 17. Deliberately out of scope

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
| 9 | Display TV loses the network for 5 minutes | Board holds the last frame, shows reconnecting, resumes without a reload | ◐ tested by simulating, not by pulling a cable |
| 10 | SMS gateway returns 500 | The queue action still succeeds; the failure is logged and visible; no duplicate sends | ◐ retry logic tested, real gateway is a stub |
| 11 | Insufficient plan limit | Token issuing is refused with a clear reason, not a silent no-op (a limit rejection once read as "the form is broken") | ✅ verified |
| 12 | Backup restores onto a clean machine | Data intact, audit chain intact, healthcheck green afterwards | ✅ drill in `docs/BACKUPS.md` |
| 13 | Patient books an appointment when the day is full | Every slot is filtered out, the select is disabled, the submit is disabled and the page states why | ✅ verified |
| 16 | A script posts a time the form never offered | 422 with "not available", no appointment created | ✅ verified |
| 14 | Whole clinic runs a day on paper | `docs/CONTINUITY.md` drill completes with no data lost on re-entry | ○ written, never rehearsed |
| 15 | 200 patients in one day | Board stays responsive; wait estimates stay sane; no unbounded query | ◐ load-tested at 200 rows, not with real traffic |

---

## Summary

| Group | ✅ Built | ◐ Partial | ○ Not built |
|---|:--:|:--:|:--:|
| Arrival & tokens | 7 | 0 | 0 |
| Appointments | 3 | 2 | 2 |
| Call & consult | 6 | 1 | 0 |
| Disruption | 6 | 0 | 0 |
| Priority | 4 | 1 | 0 |
| Transfers | 4 | 0 | 1 |
| Tracking | 5 | 0 | 1 |
| Displays | 3 | 1 | 1 |
| Notifications | 4 | 1 | 1 |
| Accounts | 7 | 0 | 3 |
| Tenancy | 4 | 0 | 3 |
| Doctors | 3 | 1 | 1 |
| Reporting | 3 | 0 | 3 |
| Data & ops | 5 | 1 | 1 |
| Money | 2 | 0 | 3 |
| Hardware | 1 | 0 | 4 |

**The honest one-liner:** the queue engine is finished and hardened — issuing,
calling, transferring, pausing, closing, cancelling and auditing all hold up
under adversarial input. Scheduling now reflects real clinic hours rather than
a fixed list, and the booking page no longer leaks other patients' names. What
is missing is around the edges: a payment gateway, notification history,
department structure, a per-doctor roster, and the administrative surfaces for
organisations, opening hours and staff activity. Those are additions, not
repairs — no scenario above describes a UI that promises what the backend
cannot do.
