# QueuePilot — Development roadmap

**Status:** Design stage complete. Implementation not started.
**Ordering principle:** the daily clinic workflow works end to end before any
advanced capability is built.

> "The first thing I would actually implement is not the billing system, reports
> or platform administration. It is the complete daily workflow: receptionist
> registers → token is issued → doctor calls → consultation starts →
> consultation completes → queue updates." — PDF, p.43

---

## Phase 0 — Design *(this document's stage)*

| # | Deliverable | Status |
|---|---|---|
| 0.1 | Read the PDF in full (44pp) | ✅ |
| 0.2 | Analyse all 10 reference screens (measured, not eyeballed) | ✅ |
| 0.3 | Complete screen inventory — 106 screens | ✅ `screen-inventory.md` |
| 0.4 | Design system + tokens | ✅ `design-system.md` + `tokens.css` |
| 0.5 | Screen → feature mapping | ✅ `screen-feature-mapping.md` |
| 0.6 | Database schema — 34 tables | ✅ `database-schema.md` |
| 0.7 | Architecture | ✅ `architecture.md` |
| 0.8 | Permissions model | ✅ `permissions.md` |
| 0.9 | Product requirements | ✅ `product-requirements.md` |
| 0.10 | Project folder structure | ✅ |

**Gate:** no implementation begins until 0.1–0.10 exist and are agreed.

---

## Phase 1 — Foundation

*Goal: a user can sign in, belong to exactly one clinic, and nothing leaks across
tenants.*

| # | Task | Done when |
|---|---|---|
| 1.1 | Project skeleton, bootstrap, env loading | `public/index.php` serves a route |
| 1.2 | `Core`: Database, Router, Request, Response, Session | A route can read a request and write a response |
| 1.3 | Migrations runner + `schema/` snapshot | `php tools/migrate.php` builds from zero |
| 1.4 | Auth: login, logout, password hashing, session regeneration | Login works; sessions regenerate |
| 1.5 | CSRF middleware | Every POST carries and validates a token |
| 1.6 | Rate limiting + lockout | Lockout after N failures with a countdown |
| 1.7 | Password reset + email verification | Hashed, single-use, expiring tokens |
| 1.8 | `clinics`, `users`, `clinic_users` + seeder | A tenant and a membership exist |
| 1.9 | **TenantMiddleware + TenantContext** | Active clinic resolved from session only |
| 1.10 | Role middleware | Route-level role gating works |
| 1.11 | Clinic registration + onboarding wizard (screens 2.1–2.7) | A new clinic reaches the dashboard |
| 1.12 | **TenantIsolationTest** | Cross-tenant IDs are rejected on every clinic-owned route |

**Exit criteria:** Two clinics exist. A user from clinic A is refused every
clinic B resource by an automated test, not by inspection.

---

## Phase 2 — Core queue engine

*Goal: tokens exist, are unique, and move only along legal paths. No UI beyond
the minimum needed to prove it.*

| # | Task | Done when |
|---|---|---|
| 2.1 | `doctors`, `rooms`, `specialties` + doctor CRUD | A doctor can be added |
| 2.2 | `sessions` + SessionService | Start, pause, close, cancel-with-reason |
| 2.3 | `queues` — one per doctor per day | Opening a doctor for the day creates a queue |
| 2.4 | **TokenService: transactional allocation** | `FOR UPDATE` + unique constraint |
| 2.5 | `queue_entries` + the nine states | Entries persist with status |
| 2.6 | **QueueStateMachine** — all legal transitions | Illegal transitions throw |
| 2.7 | **QueueService::transition()** — permission → legality → version | One method, one transaction |
| 2.8 | `queue_events` append-only history | Every action is recorded |
| 2.9 | Session-gating: **calling blocked until running** | Enforced in the service, not the view |
| 2.10 | Priority: reason + actor required, always audited | No silent priority path |
| 2.11 | Transfer preserving origin history | Origin queue retained |
| 2.12 | Hold / resume / skip / no-show / re-queue / cancel | All nine states reachable and correct |
| 2.13 | `AuditService` | Every mutation audited |

**Exit criteria:** A concurrency test issues 100 tokens from 20 parallel
processes into one queue and gets **exactly 100 distinct numbers, 0 duplicates**.
A transition test asserts every legal path succeeds and every illegal path
throws.

---

## Phase 3 — Everyday interfaces ⭐

*Goal: the daily workflow works end to end. This is the phase the product is
judged on.*

| # | Task | Done when |
|---|---|---|
| 3.1 | App layouts: receptionist, doctor, owner | Role-appropriate shell each |
| 3.2 | Design system → CSS (tokens + components) | Cards, buttons, inputs, chips, tables, dialogs |
| 3.3 | Reusable view components | No duplicated header/button/form markup |
| 3.4 | **Receptionist overview (3.1)** greeting, stat tiles, live queue, rail | The board renders and polls |
| 3.5 | **Patient search (3.3)** — live, with JS-disabled fallback | Sub-second search |
| 3.6 | **Issue token (3.4a)** — two steps, one page | Search → select doctor → issue |
| 3.7 | Token issued result: print slip + QR + copy link | Slip prints; QR scans to tracking |
| 3.8 | **Queue board (3.8)** — session banner, scan box, full list | Full queue management |
| 3.9 | Visit details (3.7) with reason dialogs | Hold/priority/cancel/transfer dialogs |
| 3.10 | **Doctor workspace (4.1–4.7)** — minimal, touch-friendly | Doctor runs their queue |
| 3.11 | **Complete & call next** (4.7) | Completing never auto-calls |
| 3.12 | Stale-action handling with friendly recovery | Double action → "already called" + refresh |
| 3.13 | Toast feedback for every important action | Nothing happens silently |
| 3.14 | **Workflow test: the 14-step daily journey** | Green |

**Exit criteria:** The complete workflow runs on real data with no mocks:

```
receptionist registers → token issued → doctor starts session → doctor calls
→ patient enters → consultation starts → consultation completes → board and
doctor console agree → doctor chooses whether to call next
```

**Do not proceed until this is excellent.** Everything after this is
amplification; this is the product.

---

## Phase 4 — Appointments & patients

*Goal: booked patients keep their priority; the register is usable.*

| # | Task | Done when |
|---|---|---|
| 4.1 | `patients` + clinic-scoped `patient_code` | Registration creates `P-00012` |
| 4.2 | Patient directory, search, details, visit history (3.2–3.6) | Register usable |
| 4.3 | Duplicate detection (advisory) | Warns, offers to open existing |
| 4.4 | `appointments` + availability engine | Real slots only |
| 4.5 | **Double-booking prevention** — partial unique index + `FOR UPDATE` | A slot cannot be taken twice |
| 4.6 | Receptionist booking (3.11) | Book from the desk |
| 4.7 | **Appointment check-in preserving slot priority** (3.13) | The point of an appointment |
| 4.8 | Reschedule / cancel with history | Preserved, not overwritten |
| 4.9 | `appointment_policies` — lead time, cancellation window, lateness | Enforced at booking |
| 4.10 | Doctor's appointments (4.9) | Doctor sees own list |

---

## Phase 5 — Patient experience

*Goal: a patient tracks their token without an account, and nothing leaks.*

| # | Task | Done when |
|---|---|---|
| 5.1 | Tracking tokens: generate, store, 18h expiry | Unguessable, expiring |
| 5.2 | **Patient tracking page (5.6/5.7)** — mobile-first | Position, wait range, instruction |
| 5.3 | QR on the printed slip | Scan → tracking page |
| 5.4 | Public booking page (5.1–5.3) | Real slots, no account |
| 5.5 | Booking confirmation + manage link (5.4/5.5) | Reschedule / cancel by link |
| 5.6 | **Waiting-room TV display (D1)** | Readable from 5 metres |
| 5.7 | Department display (D2) | Filtered by department key |
| 5.8 | Public board + "Where do I stand?" (D3) | Position only, no identity |
| 5.9 | Kiosk check-in (D4) | Issues a real token |
| 5.10 | Post-visit rating feeding the satisfaction report | Ratings recorded |
| 5.11 | **PublicTokenTest** — unguessable, expires, leaks nothing | Green |

---

## Phase 6 — Clinic management

*Goal: an owner configures the clinic without technical knowledge.*

| # | Task | Done when |
|---|---|---|
| 6.1 | Clinic profile + hours (6.1, 6.8) | Hours drive booking |
| 6.2 | Doctors, rooms, specialties (6.2–6.4) | Full CRUD with safe deletes |
| 6.3 | Departments — optional, with display keys (6.5) | A one-doctor clinic ignores them |
| 6.4 | Doctor schedules (6.6) | Drives availability |
| 6.5 | Breaks — live and recurring (6.7) | **Waits stretch to cover a break** |
| 6.6 | Holidays (6.8) | Remove dates from availability |
| 6.7 | Staff + invitations (6.9) | Invite, revoke, resend |
| 6.8 | Permissions screen (6.10) | Overrides visible and audited |
| 6.9 | Queue config + prefixes (6.12) | `A`, `B`, `C` per doctor |
| 6.10 | Priority rules + rule history | Automatic priority is auditable |
| 6.11 | Service workflows | Multi-step visits |
| 6.12 | Getting-started checklist | A new clinic is guided |

---

## Phase 7 — SaaS operations

*Goal: the platform can sell and administer itself.*

| # | Task | Done when |
|---|---|---|
| 7.1 | `plans`, `subscriptions`, `invoices`, `payments` | Data, not constants |
| 7.2 | **FeatureService** + `feature_entitlements` | Enforced in the service layer |
| 7.3 | Clinic billing screens (9.1–9.7) | Plan, compare, change, invoices |
| 7.4 | Payment submission + admin verification (11.7) | Bank transfer flow |
| 7.5 | **Platform console** (11.1–11.12) — separate layout | Separate product |
| 7.6 | Clinic suspend / reinstate | Suspending stops tokens |
| 7.7 | Organisations + branches (10.1–10.5) | Roll-up with **no patient names** |
| 7.8 | Entitlement test: a crafted POST to a paid feature is refused | Green |

---

## Phase 8 — Notifications, reports, operations

*Goal: the clinic can see and say what happened.*

| # | Task | Done when |
|---|---|---|
| 8.1 | `notification_outbox` + worker | Delivery never blocks the queue |
| 8.2 | Templates + merge fields (7.2) | Editable, with defaults |
| 8.3 | Integrations: SMS, email, WhatsApp (7.4–7.6) | Connect/disconnect, test message |
| 8.4 | Retry, backoff, dedupe, usage metering | Failures visible and retryable |
| 8.5 | Notification centre + delivery history (7.1, 7.7) | Status and cost visible |
| 8.6 | Reports (8.1–8.7) — including **percentile** wait times | Estimates labelled as estimates |
| 8.7 | CSV export + full data export (8.7, 12.2) | Owner-only, audited |
| 8.8 | Audit logs (12.1) | Filterable, complete |
| 8.9 | Support centre (12.3) | Every role can raise a ticket |
| 8.10 | Incidents with append-only timeline (11.12) | Corrections are new entries |
| 8.11 | Offline desk: reserve, issue, reconcile (12.4) | Conflicts listed, never auto-merged |
| 8.12 | PWA: manifest + service worker | Installable |

---

## Phase 9 — Production readiness

*Goal: it survives a busy Monday.*

| # | Task | Done when |
|---|---|---|
| 9.1 | Security testing — OWASP top 10, XSS, CSRF, SQLi | Clean |
| 9.2 | **Tenant isolation testing** — every route, parameterised | Clean |
| 9.3 | **Queue concurrency testing** — parallel issue + transition | Zero duplicates, zero lost updates |
| 9.4 | Performance testing — 200 concurrent tokens/hour | p95 under target |
| 9.5 | Backup + **tested restore** | Restore rehearsed successfully |
| 9.6 | Mobile responsiveness — every screen at 320/768/1280 | No overflow, no clipping |
| 9.7 | Error handling + empty/loading/permission states (12.5–12.9) | No dead ends |
| 9.8 | Accessibility — contrast, focus, labels | WCAG 2.1 AA |
| 9.9 | UAT with a real receptionist | **The 14-step journey completed unassisted** |

---

## 7. The six workflow tests

These are the acceptance tests. If they pass, the product works; if any fails,
no amount of additional features compensates.

| # | Journey | Steps | Phase |
|---|---|---:|---|
| W1 | **Daily clinic** | Register patient → issue token → start session → call → start consultation → complete → board + doctor console agree → doctor chooses whether to call next | 3 |
| W2 | **Appointment** | Book online → receive confirmation → arrive → check in → **keeps slot priority** → seen | 4 |
| W3 | **Patient tracking** | Receive slip → scan QR → see position → see "called" → no other patient's data visible | 5 |
| W4 | **Concurrency** | Two receptionists issue tokens simultaneously → distinct numbers, no duplicates, both boards agree | 2 |
| W5 | **Tenant isolation** | Clinic A user attempts every clinic B resource by ID → all refused | 1 |
| W6 | **Notification failure** | Gateway down → registration still succeeds → consultation still completes → message retried later | 8 |

---

## Sequencing rules

1. **Phase 3 is the product.** Phases 4–8 amplify it. If phase 3 is weak,
   everything after it is decoration on a weak product.
2. **No phase starts until the previous one's exit criteria are met and tested.**
   Broken dependencies left behind compound.
3. **Every phase ends with a working, demoable system** — not half a feature
   across five modules.
4. **Security is not a phase.** Tenant isolation (1.12), concurrency (2.4) and
   server-side authorisation are built in as they arise.
5. **Concurrency and tenant isolation are built in from the start,** not
   retrofitted. Retrofitting either is a rewrite.

---

## What is deliberately deferred

| Deferred | Why |
|---|---|
| `appointment_slots` materialisation | Computed availability is sufficient until a clinic needs explicit granularity |
| WhatsApp integration | Depends on provider approval of message templates |
| Multi-language content | Interface strings only at launch |
| Native mobile app | The PWA covers the patient and kiosk cases |
| Telemedicine | Explicit non-goal |
| Redis / query cache | Add only when measured, not in anticipation |
