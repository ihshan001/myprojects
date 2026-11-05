# Spec audit — what is built, what is not, and why

This file exists because "it's all done" is not a useful answer. Every line of
the Phase 1 / Phase 2 / Phase 3 brief and the "difficult part" table is listed
below with its real status: **built and tested**, **built, not exercised**, or
**not built**.

Last verified: 64 automated checks passing, 90 files lint-clean,
`tools/healthcheck.php` green.

Legend: ● built + tested · ◐ built, verified by hand but no automated test ·
○ not built

---

## Phase 1 — essential for first release

### 1. Clinic and staff management

| Requirement | Status | Where |
| --- | --- | --- |
| Create and configure a clinic | ● | `/register` self-service (creates clinic + owner + room + first queue, auto-login) |
| Add multiple doctors and specialties | ● | `/doctors`, `DoctorService`, `specialties` table |
| Configure consultation rooms and sessions | ◐ | rooms attached to doctors; queue sessions open/pause/close per day (`/queue/session`) |
| Invite receptionists and doctors with different permissions | ● | `/settings/staff`, four roles, matrix in `Auth::matrix()` |
| One clinic with several doctors and departments | ● | Serenity demo: 5 doctors, 5 queues, 5 specialties |

**Gap:** staff are created with a password, not invited by email. There is no
mail transport in this environment, and inventing one would have been untested
code. Creating the account and handing over the credentials is what the form
does today; a tokenised invite is a Phase 2 item.

### 2. Smart queue management

| Requirement | Status | Where |
| --- | --- | --- |
| Sequential tokens per doctor's session | ● | `QueueService::issueToken()`, locked allocation, `UNIQUE (queue_id, token_label)` |
| Register walk-ins | ● | `/queue/new` |
| Check in pre-booked appointments | ● | `/appointments/{id}/checkin` → token with `source=appointment` |
| Call next, recall, hold, skip, no-show, transfer, complete | ◐ | call / recall / skip / no-show / transfer / complete all built. **"Hold" is not a separate state** — see below |
| Pause or resume a queue | ● | `/queue/session`, paused queues refuse new tokens |
| Clear history of queue changes | ● | `queue_events` (append-only) + `audit_logs` |

**"Hold" — deliberate omission.** A hold is ambiguous: it can mean "park this
patient and keep the queue moving" (that is `skip`, reversible via `requeue`) or
"stop the whole queue" (that is `pause`). Both exist. Adding a third
`on_hold` state would have created a status the state machine cannot explain to
a receptionist. If your clinical workflow needs a distinct hold that survives a
shift change, it is a real gap — tell me and I will add it with its own
transition rules.

### 3. Appointment and walk-in coordination

| Requirement | Status | Where |
| --- | --- | --- |
| Online booking where enabled | ● | `/book/{slug}`, off by default, 404 when disabled |
| Reception registers walk-ins | ● | `/queue/new` |
| Check in on physical arrival | ● | appointment check-in; late policy applied here |
| Clinic-defined ordering rules | ● | `clinics.appointment_weight` / `walkin_weight`, read at issue time |
| Prevent double bookings | ● | service check **and** `uq_appointment_slot` index |
| Prevent duplicate active queue entries | ● | service check **and** `uq_active_entry_per_patient` partial index |

The last two were missing in the first build. Both are now enforced by the
database, not only by PHP, because two devices can pass a PHP check at the same
moment. The migration that added them immediately caught a real duplicate
created during testing.

### 4. Patient queue tracking

| Requirement | Status | Where |
| --- | --- | --- |
| Private link to own queue status | ● | `/t/{track_token}`, 24-byte URL-safe token, expires |
| Token, patients ahead, estimated range | ● | `/api/track/{token}` — never a point estimate |
| Notify when their turn approaches | ◐ | `entry_called` / `visit_completed` messages, outbox + retry. "Approaching" (3 ahead) is **not** implemented — see below |
| Reception-assisted registration without a smartphone | ● | a token can be issued with no phone number at all; the QR is printed |
| English + Sinhala + Tamil | ● | three locale files, navigation actually translated (this was dead code until tested) |

**Gap: "your turn is approaching".** The system notifies on *call*, not at "two
patients ahead". Sending an early warning is easy; sending it *accurately* is
not, because the estimate is a range. I would rather not page a patient fifteen
minutes early and have them miss their actual turn. If you want it, the right
design is a threshold on the *low* bound plus an explicit opt-in per clinic.

**Privacy note:** the tracking payload contains no name, no phone number and no
medical note. Verified by inspecting the live JSON.

### 5. Live queue display

| Requirement | Status | Where |
| --- | --- | --- |
| Full-screen browser TV mode | ● | `/display` (staff) and `/board/{key}` (public TV) |
| Currently serving + upcoming tokens | ● | `/api/display/{key}` |
| Doctor name, room, delay notices | ● | delay announcements added; pushed to the TV as a notice line |
| Auto-refresh | ● | 5 s poll, backoff to 30 s, stale banner |
| No names, phones or medical details | ● | payload contains tokens and rooms only |

### 6. Clinic dashboard and reports

| Requirement | Status | Where |
| --- | --- | --- |
| Served / waiting / skipped / no-show | ● | `/reports` |
| Actual average wait and consultation times | ● | `AVG(wait_minutes)`, `AVG(consult_minutes)` from real transitions |
| Doctor delays and performance by session | ● | delays counted from the audit log; per-doctor table |
| Daily, weekly, monthly | ● | date range picker (`from`/`to`), default last 7 days |
| CSV export for authorised staff | ● | `/reports/export`, UTF-8 BOM so Excel opens it correctly |

---

## The difficult part — real queue interruptions

| Situation | Required behaviour | Status |
| --- | --- | --- |
| Doctor arrives late | mark session delayed, recalculate estimates | ● `delay_minutes` shifts every patient range; TV shows it |
| Doctor is absent | pause/cancel session with patient notification | ● pause + close; **no automatic patient broadcast** on pause — see below |
| Patient does not respond | skip or no-show, allow recall | ● both, recall is `called → called` |
| Urgent patient arrives | authorised staff handle it, record override | ● `queue.priority` **clinical roles only**, reason mandatory, audited |
| Patient arrives late | apply configured late-arrival policy | ● `arrivalPolicy()`, uses `late_grace_minutes`, explains the decision |
| Two receptionists click "Next" | no double call | ● version guard in the SQL `WHERE`, loser gets 409 |
| Internet drops | offline warning + documented manual fallback | ● staleness pill, `/api/*` uncached, `docs/CONTINUITY.md` |
| Patient moves to another doctor | safe transfer, no duplicate token | ● atomic: closes original, issues new token in target, one live token |
| Doctor takes a break | pause progression, communicate delay | ● pause + delay notice |

**Gap: notifying patients when a session is paused.** Pausing stops new tokens
and shows on the TV, but no SMS goes out. Bulk-messaging every waiting patient
on a pause is the kind of thing that annoys people when the pause lasts two
minutes. Recommended: a "notify waiting patients" button, not an automatic send.

**Clinical safeguard:** the software never decides who deserves priority. It
provides a controlled override that requires a reason, records who did it, and
is unavailable to reception. No triage scoring exists anywhere in the codebase.

---

## Security and reliability

| Requirement | Status | Evidence |
| --- | --- | --- |
| Server-side tenant isolation on every request | ● | every query takes `clinic_id` from the session; cross-tenant ids return 404, not 403; tested |
| No ID/URL tampering across tenants | ● | tests: clinic 1 cannot act on clinic 2's entry, patient, queue or transfer target |
| Separate platform admin / clinic admin / reception / doctor | ● | `Auth::matrix()`, enforced in `QueueService` not just the UI |
| Strong authentication | ◐ | password hashing, min length, idle timeout, session regeneration. **No 2FA** — see below |
| Rate limiting | ◐ | login attempts table + lockout; public tracking throttled per IP. **No rate limit on the display key** |
| Session protection | ● | HttpOnly, SameSite=Lax, secure when HTTPS, `use_strict_mode`, rotate on login/logout |
| Additional verification for privileged accounts | ○ | not built (see 2FA below) |
| Encrypted backups, off-host, tested restore | ○ | **documented only** — `docs/BACKUPS.md` has the procedure; nothing runs it here |
| Queue integrity via transactions + constraints | ● | single mutation path, `version` in the `WHERE`, partial unique indexes |
| Who changed what, when | ● | `audit_logs` + `queue_events` on every mutation |
| Manual fallback documented | ● | `docs/CONTINUITY.md` |
| Flag stale data, never present it as live | ● | 30 s staleness rule, explicit banner, no API caching |
| Monitoring: availability, failed logins, traffic, DB health, jobs | ◐ | `healthcheck.php`, `Log::warn` on failed logins, notification stats. **No dashboard or alerting** |
| Incident response / recovery plan | ○ | procedures written in `docs/`, but no runbook rehearsed against real infra |

### The two honest gaps that matter most

**1. No two-factor authentication.** The brief asks for "additional
verification for privileged accounts". Platform-admin accounts can suspend every
tenant; a stolen password is currently enough. This is the single highest-value
thing left to build.

**2. No automated backup job.** The procedure is written and correct, but
nothing runs it. A cron entry plus a restore drill is required before a real
clinic is onboarded. A backup that has never been restored is not a plan — the
document says so, and the software does not yet prove it.

---

## Phase 2 — value features

| Feature | Status | Notes |
| --- | --- | --- |
| Self-service registration | ● | `/register`, creates a working clinic immediately |
| Online queue joining if the clinic permits | ● | `/book/{slug}`, opt-in per clinic |
| Automatic SMS / WhatsApp | ◐ | full outbox, dedupe, backoff, swappable gateways; **no provider credentials**, so the default driver logs instead of sending and never reports "sent" |
| Doctor schedule and leave management | ○ | not built |
| Multiple branches under one organisation | ○ | not built; schema has one clinic per row |
| Custom branding and patient pages | ◐ | brand colour, clinic name and phone flow to the tracking page, TV and booking form; no logo upload UI |
| Subscription billing, invoices, plan limits | ● | `/billing`, `/admin/plans`, limit checks before creating doctors/staff/queues |
| Payment verification | ◐ | manual "mark paid" by the clinic admin, audited. No gateway integration by design |
| Hardware add-ons | ○ | no token printer / dedicated device integration; the browser TV mode covers the display case |
| Advanced analytics, configurable policies | ◐ | weights and grace period are configurable; no policy editor for more complex rules |

---

## Phase 3 — beyond clinics

| Requirement | Status |
| --- | --- |
| Reusable queue engine | ● `App\Domain\Queue` has no medical concepts — it deals in queues, entries, tokens and transitions |
| Per-industry workflows, settings, permissions | ○ not built |
| One interface per business type | ○ not built |

The schema is deliberately vertical-neutral (`queues`, `queue_entries`,
`doctors`), so Phase 3 is a vocabulary and configuration problem, not a rewrite.
The one thing that would need modelling first is a *service* entity (a dental
procedure is not a consultation), which is why I would not claim Phase 3 "ready"
without that work.

---

## What I would build next, in order

1. **2FA for privileged accounts** — the largest real security gap.
2. **Automated, verified backups** — the largest real reliability gap.
3. **Rate limit and alert on the display key and failed logins** — cheap, closes
   a brute-force surface.
4. **Doctor leave / schedule** — the most-requested clinic feature that is missing.
5. **"Notify waiting patients" button** for pauses and delays.
6. **Organisation → branches** model, once a clinic actually asks for it.

Everything above is a real gap, not a missed checkbox. The 64 tests cover the
parts that are built; nothing in this document is claimed without a way to
check it.
