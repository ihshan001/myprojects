# QueuePilot

**Calm, live patient queues for Sri Lankan clinics.**

A multi-tenant queue-management SaaS. A patient walks in, reception issues a
token, the patient scans a QR code and watches their position from a phone
browser — no app install, no account. A TV in the waiting room shows tokens
only. The clinic sees a live board, wait-time ranges, reports and a full audit
trail.

Built to the "Clinic-First Edition" blueprint: clinic-specific in v1, but the
domain model is vertical-neutral (salons, repair desks, labs and pharmacies use
the same queue engine with different vocabulary).

---

## What actually works

Everything below is exercised by the test suite (`tests/run.php`) or was
verified against a running server.

| Area | Status |
| --- | --- |
| Queue engine (issue → call → start → complete, skip / no-show / recall / transfer / requeue) | working, single mutation path |
| Concurrency | optimistic `version` guard **in the SQL WHERE clause**, plus `FOR UPDATE` on MySQL token allocation |
| Patient tracking PWA | `/t/{token}`, QR code, polling every 10–15 s, range estimates |
| Waiting-room display | `/board/{key}`, key-only access, tokens + rooms only, no names |
| Reception dashboard | live board, per-queue columns, one-click actions, auto-refresh |
| Doctor view | same board scoped to the doctor, no token issuing |
| Appointments & check-in | bookings convert to a token with appointment weighting |
| Notifications | outbox table, dedupe key, retry with attempt cap, SMS/WhatsApp-ready gateways |
| Reports | day summary, arrivals by hour, outcomes, per-doctor, CSV export with UTF-8 BOM |
| Billing | plans, per-clinic subscription, invoices, usage meters, suspension keeps all data |
| Super-admin | tenants, plan limits, MRR, suspend / reactivate |
| Self-service signup | creates clinic + owner + room + first queue, then logs in |
| On-hold state | a patient who steps out keeps their place and their token; distinct from skipped and no-show |
| Doctor leave | date ranges that remove a doctor from booking (form *and* POST) and report clashing appointments without cancelling them |
| Staff invitations | single-use, hashed, expiring links; the admin never sets a colleague's password |
| Two-factor auth | TOTP (RFC 6238) for any account, forced as a second step at login, encrypted secrets, one-use recovery codes |
| Security | CSRF on every mutation, durable rate limits on login / display key / tracking / booking, tenant scoping on every query, audit log |
| Roles | reception / doctor / clinic admin / super admin, enforced in the domain layer, not just the UI |
| Monitoring | `/api/health` with concrete checks (worker backlog, stale sessions, long waits, backup age, schema parity), alert history |
| Backups | `tools/backup.php` snapshots, then restores and verifies; freshness is a monitored condition |
| Multi-branch | organisations group clinics for a counts-only roll-up; each branch stays its own tenant |
| i18n | English, Sinhala, Tamil — navigation is actually translated, not just files on disk |
| PWA | manifest, service worker, offline shell, installable icons |

## Requirements

* PHP **8.2+** with `pdo_mysql` (or `pdo_sqlite`) and `mbstring`
* MySQL **8.0+** for production, SQLite for development
* A web server that can point its document root at `public/`

No Composer, no Node, no build step is required to *run* the application. Node
is only used for the optional Tailwind rebuild and the local dev server.

## Install

```bash
git clone <repo> queuepilot && cd queuepilot

cp .env.example .env
# edit DB_* and APP_KEY

php tools/migrate.php        # creates tables from database/schema/mysql.sql
php tools/seed.php           # optional demo tenant + logins
```

Point nginx at `public/`:

```nginx
root /var/www/queuepilot/public;
location / { try_files $uri $uri/ /index.php?$query_string; }
```

Apache users: `public/.htaccess` already contains the rewrite rule.

## Run it locally without PHP installed

This repository ships a self-contained PHP 8.3 WebAssembly runtime so the app
can be developed and demonstrated on a machine with nothing but Node:

```bash
npm install
npm run dev          # http://localhost:8080
npm run css          # rebuild Tailwind after editing views
npm run test         # 40-check suite on a throw-away SQLite database
npm run health       # environment sanity check
npm run worker       # drain the notification outbox once
```

The dev server (`tools/serve.mjs`) mounts the host filesystem into the WASM
runtime and translates HTTP requests into PHP superglobals, so the same code
runs under nginx/php-fpm unchanged.

## Demo accounts

Seeded by `tools/seed.php`:

| Role | Email | Password |
| --- | --- | --- |
| Platform owner | `owner@queuepilot.app` | `QueuePilot#2026` |
| Clinic admin | `admin@serenity.lk` | `Serenity#2026` |
| Receptionist | `reception@serenity.lk` | `Reception#2026` |
| Doctor | `amara@serenity.lk` | `Doctor#2026` |
| Second clinic (isolation demo) | `admin@lakeview.lk` | `Lakeview#2026` |
| Suspended clinic (billing demo) | `admin@coastal.lk` | `Coastal#2026` |

**Change these before any real deployment.** `tools/seed.php` refuses to run
against a database that already has tenants.

## How the queue stays correct

All status changes go through exactly one method:

```
QueueService::apply()  →  QueueStateMachine::assert()  →  UPDATE ... WHERE version = ?
```

* `QueueStateMachine` is the only place that says which transitions are legal.
* `QueueService::apply()` is the only code that writes `queue_entries.status`.
* The `version` column is part of the `UPDATE` predicate, so two receptionists
  clicking "call" at the same moment cannot both win. The loser gets a `409`
  and the UI refreshes.
* Every accepted action writes a `queue_events` row and an `audit_logs` row.
* Token numbers are allocated inside a transaction with the queue row locked
  (`SELECT ... FOR UPDATE` on MySQL), so no two patients ever get `A014`.

Priority is the one deliberate self-transition (`waiting → waiting`): it changes
ordering without moving the patient through the funnel, is audited with a
reason, and requires the `queue.priority` ability.

## Wait times

The product rule is **never show a point estimate**. `QueueService::estimate()`
returns:

```php
['low' => 21, 'high' => 45, 'label' => '21–45 min', 'confidence' => 'data']
```

Confidence is `data` only when there are enough recent completed consultations
for that doctor; otherwise `estimating`, and the UI says so plainly.

## Notifications

`notifications` is an outbox, not a fire-and-forget call:

* one row per (template, entry) thanks to a `UNIQUE` dedupe key
* `attempts` + `last_error` + exponential backoff
* `SmsGateway` / `WhatsAppGateway` behind a `Gateway` interface — the default
  `log` driver writes the message to the app log instead of sending it
* `tools/queue-worker.php --loop 30` drains the queue

A patient with no phone number is never added to the outbox; they are tracked
via the QR link only. No medical information and no full contact details ever
reach a public tracking page or the waiting-room screen.

## Multi-tenancy

Tenant isolation is enforced server-side and never trusts a browser-supplied
`clinic_id`:

* `Auth::clinicId()` comes from the session, resolved once per request
* every query that touches tenant data takes `clinic_id = ?` as a parameter
* a cross-tenant id returns **404**, not 403 — no existence oracle
* the display key and the patient tracking token are both unguessable and
  revocable, and are scoped to one clinic

## Billing rules

* The plan is a recurring subscription with limits (doctors / staff / queues).
* SMS volume, WhatsApp volume, hardware and onboarding are **separate line
  items** and are never folded into the plan price.
* Suspending a clinic blocks sign-in and redirects to billing with an explicit
  "your data is intact" message. Nothing is deleted, ever.

## Layout

```
app/
  Core/            Env, EnvFile, Db, Session, Csrf, Auth, Router, Request,
                   Response, View, Lang, Str, Log
  Domain/Queue/    QueueStateMachine, QueueService, QueueRepository,
                   InvalidTransition
  Services/        Audit, Notification, Gateway, SmsGateway, WhatsAppGateway,
                   Patient, Appointment, Doctor, Clinic, Report
  Http/Controllers Auth, Dashboard, Queue, Api, Public, Patient, Appointment,
                   Clinic, Report, Billing, Display, Admin
  Support/QrCode.php          self-contained QR encoder (no GD, no library)
config/config.php             every knob, with .env overrides
database/schema/{mysql,sqlite}.sql   identical semantics, hand-written
resources/views/              layouts, partials, components, pages
resources/lang/{en,si,ta}.php English / Sinhala / Tamil
public/                       front controller, assets, manifest, service worker
tools/                        serve, php (WASM), migrate, seed, lint, worker,
                              healthcheck, icon generator
tests/run.php                 the suite
```

## Tests

```bash
node tools/php.mjs tests/run.php     # or: npm test
```

164 checks across the state machine, queue engine, tenant isolation, doctor
scoping, authorisation, priority ordering, range estimates, notifications,
CSRF, password hashing, plan limits, suspension durability, QR generation,
reporting, i18n, holds, doctor leave, staff invitations, TOTP (including the
RFC 6238 reference vectors), durable rate limiting, monitoring, backups and
organisation roll-ups. The suite builds and destroys its own SQLite database,
so it never touches demo data.

```
 State machine          6 ✓
 Queue engine          11 ✓
 Queue integrity        4 ✓
 Tenant isolation       5 ✓
 Authorisation          4 ✓
 Priority ordering      1 ✓
 Wait estimates         2 ✓
 Late arrivals          3 ✓
 Online booking         3 ✓
 Transfer               3 ✓
 Notifications          5 ✓
 Security               4 ✓
 Plan limits            2 ✓
 Suspension             1 ✓
 QR codes               2 ✓
 Reporting              1 ✓
 Localisation           5 ✓
 Holds                  5 ✓
 Doctor leave           3 ✓
 Two-factor             4 ✓
 Invitations            5 ✓
 Rate limiting          4 ✓
 Monitoring             4 ✓
 Backups                3 ✓
 Organisations          4 ✓
```

### Who can do what

| Ability | Reception | Doctor | Clinic admin | Super admin |
| --- | --- | --- | --- | --- |
| Issue a token | ✓ | — | ✓ | ✓ |
| Call / start / complete | ✓ | ✓ | ✓ | ✓ |
| Set clinical priority **(reason required)** | — | ✓ | ✓ | ✓ |
| See other doctors' patients | — | — | ✓ | ✓ |
| Patient records | ✓ | — | ✓ | ✓ |
| Reports | ✓ | own | ✓ | ✓ |
| Staff, billing, audit | — | — | ✓ | ✓ |
| Plan configuration | — | — | — | ✓ |

This is enforced by `QueueService` and the router, not by hiding buttons: a
receptionist with `curl` and a receptionist with a mouse hit the same wall.

## When things go wrong

Two operational documents, written to be followed under pressure rather than
admired:

* **`docs/CONTINUITY.md`** — how to recognise stale data, the paper fallback
  procedure (token pad + manual log), and the reconciliation steps when service
  returns. Staff are told never to clear the screen, because losing the order of
  patients is worse than showing an old one.
* **`docs/BACKUPS.md`** — dump flags that do not block a live queue, encryption
  before the backup leaves the host, an off-host copy so an attacker cannot
  delete it, and a quarterly restore drill that runs `healthcheck.php` against
  the restored copy before anyone points production at it.

The software supports both: every live surface marks itself stale after 30
seconds without data, `/api/*` is never cached, and the audit log reconstructs
exactly what happened while a clinic was on paper.

**`docs/OPERATIONS.md`** is the on-call runbook: what each `/api/health` check
means, the cron lines, what a stalled worker looks like, how to close an
abandoned day, and the two settings (`APP_KEY`, `SESSION_PATH`) that break
sign-in if changed carelessly.

## Honest status

`docs/SPEC-AUDIT.md` maps every requirement in the brief to its real status:
built and tested, built but unexercised, or **not built**. It is deliberately
unflattering in places.

Since the first audit, the two largest gaps were closed:

* **Two-factor authentication** for privileged accounts — TOTP (RFC 6238)
  with encrypted-at-rest secrets, single-use recovery codes and a mandatory
  second step at login. A correct password on its own does not create a
  session. Enrolment lives at `/settings/security`; the on-call notes are in
  `docs/OPERATIONS.md`.
* **An automated backup job** — `tools/backup.php` takes a consistent
  snapshot, then *restores it* into a scratch database and checks integrity,
  foreign keys and the tables that hold irreplaceable data. It records the
  result, and `/api/health` fails when that record is stale. A backup that
  cannot be opened is reported at 02:00, not during an incident.

Still genuinely missing: live payment-gateway settlement (invoices are marked
paid by hand), physical hardware integration (thermal printers, dispensers),
and the Phase 3 non-medical vertical packs. `docs/SPEC-AUDIT.md` says so in
the same words.

## Deliberate non-goals for v1

No AI triage. No WebSockets yet (polling is the reliable default on weak
links; the API is shaped so a socket can replace it). No microservices. No
medical records — QueuePilot stores queue state and contact details, never
diagnoses. No hosting topology is prescribed.

## Roadmap

1. **Foundation** — done: schema, tenant model, auth, RBAC, audit.
2. **Queue engine** — done: state machine, tokens, concurrency, priority.
3. **Reception + patient tracking + display** — done.
4. **Notifications** — done: outbox, dedupe, retry, swappable gateways.
5. **Appointments & reports** — done.
6. **Billing & super-admin** — done.
7. **Hardening** — durable rate limits on every public surface, CSV, i18n,
   PWA, 106 tests, healthcheck: done.
8. **Operational depth** — on-hold state, doctor leave, staff invitations,
   two-factor, automated verified backups, monitoring endpoint, multi-branch
   organisations: done.
   Remaining: load-test the token allocator, add a staging rehearsal script.
9. **Later** — WebSocket transport, payment-gateway settlement, hardware
   integration, non-medical vertical packs.

## Deploying

A single-host Docker Compose file is included — one app container, one MySQL,
one worker. It is the smallest topology that satisfies the blueprint, not a
recommendation:

```bash
cp .env.example .env         # set DB_PASSWORD, DB_ROOT_PASSWORD, APP_KEY
docker compose up -d --build
docker compose exec app php tools/migrate.php
docker compose exec app php tools/seed.php --plans-only   # plans, no demo data
```

`--plans-only` exists so a production database gets its four subscription plans
without a single demo patient. The worker container drains the notification
outbox on a 30-second loop.

Check a deployment at any time:

```bash
docker compose exec app php tools/healthcheck.php
```

It verifies the database driver, every table, that plans are seeded, that the
CSS bundle exists, and — importantly — that the hand-written `mysql.sql` and
`sqlite.sql` schemas still describe **the same database**. That last check has
already caught a column that existed in tests but not in production.
