# QueuePilot — Architecture

**Status:** Design stage.

---

## 1. The shape

A **modular monolith**. One deployable, one database, organised by module with
enforced boundaries. Not microservices — the brief is explicit, and splitting a
clinic queue system into network calls would buy operational pain and sell no
user value.

```
Browser ──► public/index.php ──► Router ──► Middleware stack ──► Controller
                                                                    │
                                            ┌───────────────────────┘
                                            ▼
                                    Service (domain rules)
                                            │
                          ┌─────────────────┼──────────────────┐
                          ▼                 ▼                  ▼
                        Policy           Models            Outbox
                     (permission)       (persistence)     (side effects)
                          │                 │                  │
                          ▼                 ▼                  ▼
                        Audit            MySQL            Worker process
                                                               │
                                                               ▼
                                                        SMS / Email / WA
```

**The rule that keeps this maintainable:** controllers never touch the database,
views never contain business rules, and services never emit HTML.

---

## 2. Request lifecycle

```
1. public/index.php
     ├─ load env, bootstrap/app.php
     ├─ Session::start()
     └─ Router::dispatch()

2. Middleware stack (order matters)
     ├─ CsrfMiddleware         — validate token on POST/PUT/DELETE
     ├─ AuthMiddleware         — resolve the authenticated user
     ├─ TenantMiddleware       — resolve the active clinic from the session
     ├─ SubscriptionMiddleware — is the clinic entitled to this route?
     └─ RoleMiddleware         — route-level role gate

3. Controller
     ├─ read + validate input (Validator)
     ├─ call ONE service method
     ├─ handle domain exceptions → friendly messages
     └─ return a View or a JSON Response

4. Service
     ├─ Policy::can(...)            ← permission, first
     ├─ precondition checks          ← domain rules, second
     ├─ transaction: mutate + event + audit
     └─ queue side effects (notifications) — never inline
```

**Never call a gateway, send an email or write a file inside a queue
transaction.** The transaction commits; a row in `notification_outbox` records
the intent; a worker does the slow, failure-prone part. This is how an SMS
outage cannot stop a registration.

---

## 3. Directory layout

```
queuepilot/
├── app/
│   ├── Core/                    Framework-agnostic plumbing
│   │   ├── Database.php         PDO wrapper, transactions, query log
│   │   ├── Router.php           Route table + dispatch
│   │   ├── Request.php          Typed input access
│   │   ├── Response.php         HTML / JSON / redirect
│   │   ├── Session.php
│   │   ├── Auth.php             Current user, login, logout
│   │   ├── Validator.php
│   │   └── Helpers.php          e(), csrf_field(), money(), time_ago()
│   │
│   ├── Middleware/              The five above
│   ├── Policies/                Pure capability checks (see permissions.md)
│   │
│   ├── Modules/                 One directory per domain module
│   │   └── Queues/
│   │       ├── Controllers/     HTTP only
│   │       ├── Services/        Business rules
│   │       ├── Models/          Persistence
│   │       └── Validators/      Input shapes
│   │
│   ├── Services/                Cross-module domain services
│   │   ├── QueueService.php     THE queue engine
│   │   ├── TokenService.php     Allocation & guarantees
│   │   ├── AppointmentService.php
│   │   ├── NotificationService.php
│   │   ├── SessionService.php
│   │   ├── FeatureService.php   Entitlement enforcement
│   │   └── AuditService.php
│   │
│   └── Views/                   Templates, no business logic
│       ├── layouts/{app,receptionist,doctor,owner,platform,public}.php
│       ├── components/          Reusable partials
│       └── {auth,onboarding,receptionist,doctor,clinic,patient,platform}/
│
├── bootstrap/{app.php,providers.php}
├── config/{app,database,mail,notifications,permissions}.php
├── database/{migrations,seeders,schema}
├── public/index.php             The ONLY publicly reachable PHP file
├── resources/{css,js,images,design-system}
├── routes/{web,api,platform}.php
├── storage/{logs,cache,exports,private,sessions}   ← outside public/
├── workers/{NotificationWorker,ScheduledTasks}.php
├── tests/{Unit,Integration,Security,Workflows}
└── docs/
```

### Why `public/` contains only `index.php` and assets

Everything else — `app/`, `config/`, `.env`, `storage/` — sits **above** the
document root. A misconfigured web server that stops interpreting PHP will serve
`app/Core/Database.php` as a text file containing credentials. Directory
structure is the first security control, not `.htaccess`.

`storage/private/` holds uploads (logos, payment evidence). Nothing user-supplied
is written under `public/`.

---

## 4. The queue engine

The heart of the product. Everything about it is designed around one fact: **two
people will act on the same queue at the same time, and both must be right.**

### 4.1 Token allocation

```
BEGIN;
SELECT last_token_number FROM queues WHERE id = ? FOR UPDATE;   -- serialise
UPDATE queues SET last_token_number = last_token_number + 1 WHERE id = ?;
INSERT INTO queue_entries (queue_id, token_number, token_display, status, ...)
       VALUES (?, ?, CONCAT(prefix, LPAD(n,3,'0')), 'waiting', ...);
COMMIT;
```

- The `FOR UPDATE` on the queue row serialises allocation — no gap, no duplicate.
- `UNIQUE(queue_id, token_number)` is the backstop. If application logic is ever
  wrong, the **database** refuses the duplicate. Never rely on the application
  alone for the one guarantee the product is named after.
- The sequence never reuses a number, even if an entry is cancelled. A gap in
  the sequence is acceptable; reissuing `A007` to two different people is not.

### 4.2 Transitions

Every state change is one transaction with an optimistic version check:

```php
public function transition(int $entryId, string $action, User $actor, array $payload = []): void
{
    DB::transaction(function () use ($entryId, $action, $actor, $payload) {
        $entry = QueueEntry::findForUpdate($entryId);          // SELECT … FOR UPDATE

        if (!Policy::can($actor, "queue.$action", $entry)) {    // permission first
            throw new Forbidden("queue.$action");
        }
        if (!$this->machine->can($entry->status, $action)) {    // legality second
            throw new DomainException($this->explain($entry->status, $action));
        }
        if ($action === 'call' && !$this->sessionRunning($entry)) {
            throw new DomainException('Start the session before calling patients.');
        }

        $from = $entry->status;
        $entry->apply($action, $payload, $actor);               // sets status, timestamps
        $entry->saveWithVersion($payload['version'] ?? null);   // 0 rows ⇒ stale ⇒ reject

        QueueEvent::record($entry, $from, $action, $actor, $payload['reason'] ?? null);
        Audit::record($actor, "queue.$action", $entry);
    });

    Notification::queue(...);   // AFTER commit — never inside
}
```

Three independent protections, each catching a different failure:

| Protection | Catches |
|---|---|
| `SELECT … FOR UPDATE` | Two staff acting simultaneously |
| `version` check | A stale page submitting an old action |
| Domain state machine | An illegal transition from any source |

### 4.3 Stale actions

Every form and every action button carries the entry's current `version`. A
submission with an old version affects 0 rows and returns a board-level message
in the language of the reception desk:

> *"This patient has already been called by Ishara. Reloading the queue…"*

Never a `DomainException`, never a stack trace, never "Conflict 409". The board
then refreshes itself. **The user is never told they were wrong; they are told
the state moved.**

### 4.4 `Complete & call next`

Two transitions, one transaction, both-or-neither. Completing alone and calling
alone are each legal; the composite exists because it is the doctor's most
common action and must not cost two taps. **Auto-calling on complete is
forbidden** — the doctor decides, every time.

---

## 5. Tenancy

`TenantContext` resolves the active clinic once per request, from the session
(never from a request parameter).

```php
final class TenantContext {
    public function id(): int;                 // the active clinic
    public function switchTo(int $clinicId): void;  // branch switching
}
```

**Rules:**

1. `clinic_id` is never read from the query string or body.
2. Every clinic-owned model exposes `forClinic($id)` which appends the scope.
3. Every clinic-owned record carries `clinic_id` directly (denormalised on hot
   tables), so a forgotten join is still caught.
4. Resource IDs from the browser are loaded **through** the scope:

```php
// CORRECT — a cross-tenant ID yields null, so the route 404s
$patient = Patient::forClinic(TenantContext::id())->find($id);

// WRONG — leaks existence and possibly data
$patient = Patient::find($id);
```

5. A cross-tenant request returns **404, not 403** — a 403 confirms the record
   exists.

Branch switching changes `TenantContext` for the session. It is a context
switch, not a re-login.

---

## 6. Feature entitlements

```php
FeatureService::enabled(TenantContext::id(), 'online_booking'): bool
```

Resolution order: explicit `feature_entitlements` override → plan default →
`false`.

Checked in **three** places, because two of them can be bypassed:

| Where | Purpose |
|---|---|
| Service layer | **Authoritative.** Throws if not entitled. |
| Middleware | Clean 402-style "upgrade" page instead of a raw error. |
| View | Hides the nav entry and the button. |

Hiding alone is explicitly not enforcement — a crafted POST must still be
refused.

---

## 7. Notifications

```
Queue transaction ──commits──► notification_outbox (status: pending)
                                      │
                       NotificationWorker (CLI, supervised)
                                      │
                     ┌────────────────┼────────────────┐
                     ▼                ▼                ▼
                    SMS             Email          WhatsApp
                     │                │                │
                     └────────► notification_logs ◄────┘
                                  (status, cost, provider ref)
```

| Rule | Why |
|---|---|
| Outbox rows are written **in** the transaction | If the visit commits, the message will be sent |
| Delivery happens **after** commit, in a worker | A gateway outage cannot roll back a registration |
| Exponential backoff, max attempts, then `failed` | A dead number must not be retried forever |
| `UNIQUE(dedupe_key)` | A retried webhook cannot double-text a patient |
| Failures surface in the notification centre with **Retry** | Silent failure is worse than a visible one |

---

## 8. Views and components

- **Five layouts**, one per experience: `receptionist`, `doctor`, `owner`,
  `platform`, `public`. A receptionist never loads the owner layout.
- Reusable partials in `app/Views/components/`: `card`, `stat-tile`,
  `status-chip`, `token-chip`, `data-table`, `empty-state`, `confirm-dialog`,
  `field`, `queue-row`, `doctor-card`.
- **No business logic in templates.** A template may ask "is this state
  called?" — it may not decide what is legal.
- Escape on output, always, through `e()`. There is no "trusted" variable.
- Templates are PHP. Introducing a template engine adds a compile step and a
  second language for no gain at this scale.

---

## 9. Frontend

**Progressive enhancement, not a JavaScript application.**

| Concern | Approach |
|---|---|
| Styling | CSS with the token file (`resources/design-system/tokens.css`) as the only source of colour/spacing |
| Icons | Lucide, inlined as SVG — no icon font, no request |
| Interactivity | Small vanilla modules; no framework, no build step required |
| Live updates | Polling (5s) against `/app/panel` |
| Offline | Service worker + manifest (PWA) |

**Why polling, not WebSockets:** a queue board needs 5-second freshness, not
millisecond freshness. Polling is stateless, survives a server restart, works
through any proxy, needs no supervision, and degrades to "stale" honestly when
the network drops — which the brief explicitly requires. WebSockets would add a
stateful server, a reconnect protocol and a new failure mode to deliver a
difference no receptionist would notice.

**Why no framework:** the interactive surface is a handful of behaviours —
live refresh, patient search, dropdowns, dialogs, scan-box autofocus. A
framework would ship more JavaScript than the entire application's own logic.

**Critical no-JS behaviour:** the core workflow must survive with JavaScript
disabled. Patient search results are real links, forms are real forms, and the
queue board renders server-side. JavaScript enhances; it is not load-bearing.

---

## 10. Background work

| Worker | Cadence | Job |
|---|---|---|
| `NotificationWorker` | Continuous, supervised | Drain the outbox, retry, log |
| `ScheduledTasks` | Cron, every minute | Close stale sessions, expire tracking tokens, generate daily summaries, check backups, run health checks |

Workers are plain PHP CLI scripts supervised by systemd (or cron for the
scheduled tasks). No queue broker — `notification_outbox` **is** the queue.

---

## 11. Configuration & secrets

- `config/*.php` returns arrays and reads `$_ENV`. **No secrets in `config/`.**
- `.env` holds secrets, is git-ignored, and is never in the document root.
- `.env.example` documents every key with a safe placeholder and is committed.
- **The app must fail loudly** on a missing required environment key — never
  fall back to a default that quietly disables a security control.

Required keys: `APP_ENV`, `APP_URL`, `APP_KEY`, `DB_*`, `MAIL_*`,
`SMS_*`, `TRACK_TOKEN_HOURS`, `CSRF_SECRET`.

---

## 12. Error handling & logging

| Layer | Behaviour |
|---|---|
| Domain exceptions | Caught in the controller, rendered as a friendly inline message near the cause |
| Permission failures | The permission-denied page explaining who *can* |
| Validation failures | Beside the field, focus moved to the first error |
| Unexpected exceptions | Logged with a correlation ID; user sees a reference, never a stack trace |
| Production | `display_errors = off`, errors to `storage/logs/` |

**Input is never discarded on error.** A failed form re-renders with what the
user typed. Losing a patient registration to one bad field is unforgivable in
this product.

---

## 13. Testing strategy

| Suite | Covers |
|---|---|
| `tests/Unit` | Policies, state machine, validators, formatters — pure, fast |
| `tests/Integration` | Services against a real database, including concurrency |
| `tests/Security` | Tenant isolation, role matrix, resource ownership, public tokens |
| `tests/Workflows` | The six end-to-end journeys in `development-roadmap.md` §7 |

**Concurrency is tested, not assumed.** A test spawns N processes that all
attempt to issue a token into one queue simultaneously, then asserts exactly N
distinct token numbers and zero duplicates. If this test does not exist, the
central guarantee of the product is unverified.

**Mutation discipline:** removing any policy check, tenant scope or state
transition guard must fail a test. A check that can be deleted without a test
failing is decoration.

---

## 14. Deployment

- Apache or Nginx, PHP 8.1+, MySQL 8.0.
- Document root → `public/`.
- `storage/` writable by PHP, **not** served.
- Migrations run explicitly (`php tools/migrate.php`), never on request.
- Health endpoint `/api/health` checks database, worker liveness, schema parity
  and backup freshness — this is what `/admin/health` reports, so a green tick
  always means something.
- Backups: automated nightly dump, encrypted, off-host, with a **quarterly
  tested restore**. An untested backup is a hypothesis.
