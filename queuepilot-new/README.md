# QueuePilot

**Simple queues. Better clinic experiences.**

A multi-tenant, cloud-based clinic queue, appointment and patient-flow
management SaaS.

> **Status: design stage complete. Implementation not started.**
> This repository currently contains the design and planning deliverables only.
> See [§4 Status](#4-status) and `docs/development-roadmap.md`.

---

## 1. What it is

A clinic has **doctors**. Each doctor working on a given day has a **queue**. A
patient arriving is given a **token** — `A001`, `B014` — which is their place in
one doctor's queue for that day.

| Doctor | Prefix | Tokens |
|---|---|---|
| Dr. Nuwan Perera | A | A001, A002, A003 |
| Dr. Amara Silva | B | B001, B002, B003 |
| Dr. Imran Farook | C | C001, C002, C003 |

Everything else — the live board, the waiting-room screen, the SMS, the
patient's own phone link, the reports — is built on that one idea.

### The governing principle

> **Powerful behind the scenes, extremely simple for everyday users.**

The product competes on **simplicity, speed, reliability and ease of adoption**,
not on feature count. It is not a hospital management system and it is **not an
EMR** — no diagnoses, prescriptions or clinical notes.

### Five roles, five different products

| Role | Sees |
|---|---|
| Receptionist | Overview · Queue · Appointments · Patients — nothing else |
| Doctor | A minimal workspace: own queue, own session, own patients |
| Clinic owner | Configuration, staff, schedules, reports, billing |
| Patient | Booking and token tracking — **no account required** |
| Platform admin | A separate console for the SaaS business |

---

## 2. Documentation

| Document | Contents |
|---|---|
| [`docs/product-requirements.md`](docs/product-requirements.md) | Normative requirements (A–O), acceptance criteria, decisions, non-goals |
| [`docs/screen-inventory.md`](docs/screen-inventory.md) | **106 screens** — every screen, designed: purpose, layout, actions, states, responsive behaviour |
| [`docs/design-system.md`](docs/design-system.md) | Colour, type, spacing, components, breakpoints — derived from the 10 reference screens |
| [`docs/screen-feature-mapping.md`](docs/screen-feature-mapping.md) | Screen → route, permission, backend capability, tables, connections |
| [`docs/database-schema.md`](docs/database-schema.md) | 34 tables, constraints, the five indexes that matter |
| [`docs/architecture.md`](docs/architecture.md) | Modular monolith, queue engine, tenancy, concurrency, testing |
| [`docs/permissions.md`](docs/permissions.md) | The capability matrix and where checks are enforced |
| [`docs/development-roadmap.md`](docs/development-roadmap.md) | Phases 0–9, exit criteria, the six workflow tests |

Design tokens — the machine-readable source of truth for colour, spacing and
type: [`resources/design-system/tokens.css`](resources/design-system/tokens.css).

---

## 3. Layout

```
queuepilot-new/
├── app/
│   ├── Core/                 Plumbing: Database, Router, Request, Response, Session, Auth, Validator
│   ├── Middleware/           Csrf · Auth · Tenant · Subscription · Role
│   ├── Modules/              18 domain modules, each Controllers/Services/Models/Validators
│   ├── Policies/             Pure capability checks
│   ├── Services/             QueueService, TokenService, AppointmentService, NotificationService, …
│   └── Views/                layouts · components · per-role page templates
├── bootstrap/ config/ database/{migrations,seeders,schema}
├── public/                   index.php + assets ONLY — everything else is above the docroot
├── resources/{css,js,design-system}
├── routes/{web,api,platform}.php
├── storage/{logs,cache,exports,private,sessions}    never publicly served
├── workers/                  NotificationWorker, ScheduledTasks
├── tests/{Unit,Integration,Security,Workflows}
└── docs/
```

**`public/` contains only `index.php` and static assets.** `app/`, `config/`,
`.env` and `storage/` sit above the document root — directory structure is the
first security control, not `.htaccess`.

---

## 4. Status

| Phase | Description | State |
|---|---|---|
| 0 | Design | ✅ Complete |
| 1 | Foundation — auth, tenancy, onboarding | ⬜ |
| 2 | Core queue engine | ⬜ |
| **3** | **Everyday interfaces — the daily workflow** | ⬜ |
| 4 | Appointments & patients | ⬜ |
| 5 | Patient experience — tracking, displays, kiosk | ⬜ |
| 6 | Clinic management | ⬜ |
| 7 | SaaS operations — plans, billing, platform console | ⬜ |
| 8 | Notifications, reports, audit, offline | ⬜ |
| 9 | Production readiness | ⬜ |

**Phase 3 is the product.** Everything after it amplifies it; nothing after it
compensates for it. The build order is deliberate: the complete daily workflow
— *receptionist registers → token issued → doctor calls → consultation starts →
consultation completes → queue updates* — must be excellent before billing,
reports or platform administration are built.

---

## 5. The non-negotiable rules

These are enforced in the domain layer, not by hiding buttons:

1. **A token is never duplicated** within a queue on a day — unique constraint
   plus `SELECT … FOR UPDATE`.
2. **Calling is blocked until the session is running** — tokens may be issued
   before, but nobody is called into an empty room.
3. **Completing a consultation never auto-calls the next patient** — the doctor
   decides, every time.
4. **Clinical priority requires an authorised actor and a recorded reason.**
   Reception cannot grant it by default.
5. **A doctor cannot issue tokens** — but can cancel their own day.
6. **One clinic can never see another clinic's data** — tested, not assumed.
7. **A failed notification never stops a registration or a consultation.**
8. **Waiting times are estimates, never guaranteed times.**
9. **Public screens never expose patient names, phones or histories.**
10. **Stale data is labelled stale.** Cached data is never presented as live.

---

## 6. Stack

| Layer | Choice |
|---|---|
| Backend | Native PHP 8.1+ |
| Database | MySQL 8.0 (SQLite for local dev only) |
| Frontend | HTML, CSS, vanilla JavaScript — progressive enhancement |
| Styling | CSS with `resources/design-system/tokens.css` |
| Icons | Lucide, inlined SVG |
| Server | Apache or Nginx |
| Background | PHP CLI workers, supervised |
| Architecture | Modular monolith |

No Laravel, no React, no microservices, no build step. Deliberately — the brief
asks for a maintainable modular monolith, and the interactive surface is small
enough that a framework would ship more JavaScript than the application's own
logic.

The core workflow **works with JavaScript disabled**. JS enhances; it is not
load-bearing.

---

## 7. Getting started *(once implementation begins)*

```bash
cp .env.example .env          # then fill in DB and mail credentials
composer install              # or: no dependencies required
php tools/migrate.php         # build the schema
php tools/seed.php            # demo clinic, doctors, patients
php -S localhost:8080 -t public
php workers/NotificationWorker.php
```

---

## 8. Where the design came from

The visual identity is derived from the **10 approved reference screens**
(`image-9.png` … `image-18.png`) by measurement, not by eye. Every one is
1536×1024 with a **384px** white left rail on a `#f2f5f9` canvas, a **~92px**
top band, near-white `#fefefe` cards, a **`#0555da`** primary blue and a
**`#218e5a`** success green.

Those measured values are the tokens in `resources/design-system/tokens.css`.
`docs/design-system.md` §0 records the full measurement.
