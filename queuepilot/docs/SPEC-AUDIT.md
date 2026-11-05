# Spec audit — what is built, what is not, and why

This file exists because "it's all done" is not a useful answer. Every line of
the Phase 1 / Phase 2 / Phase 3 brief and the "difficult part" table is listed
below with its real status: **built and tested**, **built, not exercised**, or
**not built**.

Last verified: **106 automated checks passing**, 102 files lint-clean,
`tools/healthcheck.php` green, and `/api/health` reporting `ok` on the demo
data.

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
| Invite receptionists and doctors with different permissions | ● | `/settings/invites` — single-use, hashed, 72-hour token; the admin never sets a colleague's password |
| One clinic with several doctors and departments | ● | Serenity demo: 5 doctors, 5 queues, 5 specialties |

Staff are now **invited**, not created with a password the administrator
invents. The invite token is stored only as a SHA-256 hash, is single-use, and
expires in 72 hours; the invitee sets their own password on `/invite/{token}`.
Tested: token-hash storage, single use, weak passwords refused, cross-tenant
acceptance impossible, expired invites rejected.

Delivery is honest about its limits: `MailService` supports `none`, `log` and
`smtp`. With `none` (the default here) the invitation is **not** emailed and
the link is shown once to the administrator to pass on — the system says so in
the flash message rather than claiming a send that did not happen.

### 2. Smart queue management

| Requirement | Status | Where |
| --- | --- | --- |
| Sequential tokens per doctor's session | ● | `QueueService::issueToken()`, locked allocation, `UNIQUE (queue_id, token_label)` |
| Register walk-ins | ● | `/queue/new` |
| Check in pre-booked appointments | ● | `/appointments/{id}/checkin` → token with `source=appointment` |
| Call next, recall, hold, skip, no-show, transfer, complete | ● | all eight, including a real `on_hold` state (see below) |
| Pause or resume a queue | ● | `/queue/session`, paused queues refuse new tokens |
| Clear history of queue changes | ● | `queue_events` (append-only) + `audit_logs` |

**`on_hold` is now a first-class state.** The earlier reasoning ("skip covers
it") was wrong in one specific case: a patient who is *here* but temporarily
unavailable — sent for an X-ray, stepped outside for air, fetching cash. Skipping
them loses their place; marking no-show is a false record.

So `waiting ⇄ on_hold` and `called → on_hold` are real transitions with these
properties, each one tested:

* The token is kept and the patient keeps their standing — `sort_weight` is not
  reset, so coming back does not push them to the end.
* `on_hold` counts as **live** for the duplicate-token guard: they cannot be
  handed a second token by walking back to reception (the partial unique index
  was extended, and the service-level message says *why*).
* They are **not** counted as "patients ahead of you" in the wait estimate —
  `waiting_count` excludes holds, and they are reported separately.
* Coming off hold returns them to `waiting`, never straight to `called`:
  a hold does not let a patient jump the queue.
* A reason and `hold_since` are recorded, shown on the row, and audited.

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
| Notify when their turn approaches | ● | `entry_approaching`, sent once per entry when the patient enters the next *N* (clinic chooses 1–10). Opt-in per clinic |
| Reception-assisted registration without a smartphone | ● | a token can be issued with no phone number at all; the QR is printed |
| English + Sinhala + Tamil | ● | three locale files, navigation actually translated (this was dead code until tested) |

**"Your turn is approaching" is built, with the design the first audit
proposed:** a threshold on position in the line plus an explicit opt-in per
clinic. Specifically —

* The clinic switches it on in Settings and chooses how many patients ahead to
  message (default 2). Off is the default because every message costs money.
* Position is computed in **board order** (priority, then original standing),
  not by row id, so a priority queue messages the right people.
* It fires **at most once per entry**. The claim is an atomic
  `UPDATE … WHERE approach_notified_at IS NULL` whose affected-row count gates
  the send, so two workers polling at the same moment cannot both announce the
  same patient.
* It is a different message from `entry_called` — "almost your turn" and "go in
  now" are different instructions.

It is generated by the worker, not in the request cycle, so a receptionist
clicking *Call* never waits on an SMS.

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

**Still not built: a "notify everyone waiting" button for a pause.** Pausing
stops new tokens and shows on the TV, but no SMS goes out to the line. The
reason given in the first audit still holds — an automatic bulk send on every
two-minute pause is worse than silence. It needs to be an explicit button, and
it is not built yet. This is the one item from the "difficult part" table that
remains a real gap.

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
| Strong authentication | ● | password hashing, min length, idle timeout, session regeneration, **plus TOTP second factor** |
| Rate limiting | ● | durable counters in `rate_limits`: login (existing table), display key per-key **and** per-IP, tracking per IP, online booking per IP |
| Session protection | ● | HttpOnly, SameSite=Lax, secure when HTTPS, `use_strict_mode`, rotate on login/logout |
| Additional verification for privileged accounts | ● | TOTP, RFC 6238. A correct password alone does **not** create a session |
| Encrypted backups, off-host, tested restore | ◐ | `tools/backup.php` snapshots **and restores** every run (integrity, foreign keys, required tables, row counts) and records the result. Encryption/off-host is a deployment step in `docs/BACKUPS.md`, not code |
| Queue integrity via transactions + constraints | ● | single mutation path, `version` in the `WHERE`, partial unique indexes |
| Who changed what, when | ● | `audit_logs` + `queue_events` on every mutation |
| Manual fallback documented | ● | `docs/CONTINUITY.md` |
| Flag stale data, never present it as live | ● | 30 s staleness rule, explicit banner, no API caching |
| Monitoring: availability, failed logins, traffic, DB health, jobs | ● | `/api/health` runs 7 concrete checks and returns 503 on critical; alerts persist in `system_alerts` and clear on recovery |
| Incident response / recovery plan | ◐ | `docs/OPERATIONS.md` is a real runbook (what each check means, cron lines, stale-day procedure, the two settings that break sign-in). Rehearsing it against a failing host is still to do |

### The two gaps from the first audit are now closed

**1. Two-factor authentication — built.** The first audit called this "the
single highest-value thing left to build". It now exists, and the details are
what make it worth having rather than a checkbox:

| Property | Implementation |
| --- | --- |
| Algorithm | TOTP, RFC 6238. The suite checks all five reference vectors from the RFC, not just "a code is six digits" |
| Secret at rest | AES-256-GCM, key derived with HKDF from `APP_KEY`, versioned `v1:` envelope. A plaintext secret in a dump is a permanent bypass, so the column never holds one |
| Half-authenticated state | the pending marker is `2fa_pending_user_id`, **not** `user_id`, so every existing `Auth::check()` keeps failing until the second factor arrives. Expires after 10 minutes |
| Guessing | 5 attempts per 5 minutes per account, in the same durable limiter used elsewhere |
| Replay | a code is accepted ±30 s for clock drift; recovery codes are single-use |
| Lockout recovery | 8 hashed recovery codes, shown once, consumed on use |
| Turning it off | requires the account password — a stolen session is not enough |

**2. Automated, verified backups — built.** `tools/backup.php` takes a
consistent snapshot (`VACUUM INTO` on SQLite; `mysqldump --single-transaction`
is the documented MySQL path) and then **restores it into a scratch database**
on every run, checking:

* `PRAGMA integrity_check` returns `ok`
* `PRAGMA foreign_key_check` returns no violations
* the tables that hold irreplaceable data exist (`queue_entries`, `patients`,
  `audit_logs`, `clinics`, `users`)
* the file is not implausibly small — a 0-byte dump is the most common silent
  failure and the one discovered during an incident
* row counts are recorded so a sudden drop is visible

The result is written to `storage/backups/last-backup.json`, and `/api/health`
turns **critical** when that record is missing or older than
`BACKUP_MAX_AGE_HOURS` (48 h). A backup nobody verified surfaces as a warning
long before it is needed. `--verify` re-runs the drill at any time.

**What is still deployment work, not code:** GPG encryption before the archive
leaves the host, and the off-host copy. Those are documented steps in
`docs/BACKUPS.md` because they depend on your key custody and storage, not on
this application.

---

## Phase 2 — value features

| Feature | Status | Notes |
| --- | --- | --- |
| Self-service registration | ● | `/register`, creates a working clinic immediately |
| Online queue joining if the clinic permits | ● | `/book/{slug}`, opt-in per clinic |
| Automatic SMS / WhatsApp | ◐ | full outbox, dedupe, backoff, swappable gateways; **no provider credentials**, so the default driver logs instead of sending and never reports "sent" |
| Doctor schedule and leave management | ● | `/doctors` — inclusive date ranges; a doctor on leave is flagged, unselectable on the booking form, and **rejected by the POST**; clashing appointments are reported, never auto-cancelled |
| Multiple branches under one organisation | ● | `/organisation` — counts-only roll-up across branches; each clinic remains its own tenant, and no patient identifier crosses the boundary |
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

The previous list has been worked through. Items 1–4 and 6 are now built and
tested; item 5 is the one that remains.

1. **"Notify everyone waiting" button** for a pause or an abandoned session.
   The only remaining item from the "difficult part" table. It should be a
   deliberate button, not an automatic send.
2. **Rehearse the runbook on a failing host.** `docs/OPERATIONS.md` describes
   what to do; it has not been walked through against a host that is actually
   broken.
3. **Load-test the token allocator.** The correctness is tested (locked
   allocation, unique index); the behaviour at hundreds of tokens per minute on
   MySQL has not been measured.
4. **Payment-gateway settlement.** Invoices are marked paid by hand today,
   which is a deliberate choice, not an oversight.
5. **Hardware integration** (thermal token printer, room-side call display).
   The browser TV mode covers the display case; a printer does not.
6. **Phase 3 vertical packs.** The queue engine is already vertical-neutral;
   this needs a *service* entity (a dental procedure is not a consultation)
   before the vocabulary can be generalised.

### Things this audit deliberately does not claim

* **No AI triage, and no priority scoring.** The system never decides who
  deserves to be seen first. It offers authorised clinical staff a recorded
  override with a mandatory reason. That is a safety property, not a missing
  feature.
* **No SMS provider is configured.** The outbox, dedupe, backoff and gateway
  interface are real; without credentials every message is marked
  `abandoned` with an explicit reason rather than falsely `sent`. Usage meters
  count only real sends.
* **No WebSocket transport.** Polling with backoff is the reliable default on
  the links this is built for. The API shape allows a socket later.

The 106 tests cover the parts that are built; nothing in this document is
claimed without a way to check it.
