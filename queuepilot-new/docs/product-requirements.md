# QueuePilot — Product requirements

**Status:** Design stage.
**Source:** `Queuepilot.pdf` (44pp, read in full) + the build brief.
**Scope:** This document is the normative requirements list. Where the PDF and
the brief overlap they agree; where a decision was needed, it is marked
**[decision]** with the reasoning.

---

## 1. Product identity

| | |
|---|---|
| **Name** | QueuePilot |
| **Positioning** | *Simple queues. Better clinic experiences.* |
| **Type** | Multi-tenant, cloud-based clinic queue, appointment and patient-flow management SaaS |
| **Not** | A hospital management system. Not an EMR. |

**The product in one sentence:** QueuePilot helps clinics register patients,
organise doctor queues, manage appointments, guide patients through their
visits and understand daily clinic activity — without complicated software.

### The central concept

A clinic has **doctors**. Each doctor working on a particular day has a
**queue**. A patient receives a **token** belonging to that queue.

| Doctor | Prefix | Tokens |
|---|---|---|
| Dr. Nuwan Perera | A | A001, A002, A003 |
| Dr. Amara Silva | B | B001, B002, B003 |
| Dr. Imran Farook | C | C001, C002, C003 |

Everything else — the board, the waiting-room screen, the SMS, the patient's
phone link, the reports — is built on that one idea.

### The governing principle

> **Powerful behind the scenes, extremely simple for everyday users.**

Do not sacrifice usability to add features. Every screen, button, workflow and
setting must justify its existence. The product competes on **simplicity,
speed, reliability and ease of adoption**, not on feature count.

> "Don't measure success by how many features QueuePilot has. Measure it by how
> little effort a clinic needs to run its day." — PDF, p.44

---

## 2. SaaS model

- One application serves many independent clinic tenants.
- Each tenant has isolated users, doctors, patients, queues, settings, reports,
  subscriptions and audit records.
- **Tenant isolation is mandatory.** Cross-tenant access by ID manipulation must
  be impossible, and must be tested.
- Two organisational levels: an **independent clinic**, and an **organisation**
  containing multiple clinics/branches. A single-site clinic must **never** be
  forced to create an organisation or configure branches.

### Subscription

Plans are **data, never code**. Prices, limits and entitlements are editable
from platform administration.

Seed plans (LKR/month):

| Plan | Price |
|---|---|
| Starter | 3,500 |
| Professional | 7,900 |
| Clinic Plus | 14,900 |
| Enterprise | Contact sales |

Plan-controllable capabilities: doctors, staff, branches, monthly patient
volume, appointment features, notification allowances, reporting, data export,
advanced integrations, public displays, offline desk, organisation features.

**Feature entitlements are enforced server-side.** Hiding a button is not
enforcement. A user must not reach a paid feature by manipulating a request.

---

## 3. Roles

Five distinct experiences. **They must not all see the same dashboard.**

| Role | Owns |
|---|---|
| Receptionist | Patient arrivals, registration, appointments, daily queues |
| Doctor | Their own queue and consultations |
| Clinic owner / manager | Clinic configuration, staff, schedules, reports, billing |
| Patient | Booking, tokens, tracking — **no account required** |
| Platform owner | The SaaS business: clinics, plans, payments, support, health |

Detailed capability matrix: `docs/permissions.md`.

---

## 4. Functional requirements

### 4.1 Daily clinic operations — A

| # | Requirement |
|---|---|
| A1 | Live clinic overview showing today's activity |
| A2 | Doctor queue overview showing every doctor's current queue |
| A3 | Walk-in patient registration |
| A4 | Unique token generation per queue |
| A5 | Waiting-patient management |
| A6 | Appointment check-in converting an eligible appointment into a queue visit |
| A7 | Queue pause and resume |
| A8 | Session management — start, pause, close |
| A9 | Visit details showing one patient's queue journey |
| A10 | Queue history of completed and past visits |

### 4.2 Patient management — B

| # | Requirement |
|---|---|
| B1 | Patient registration |
| B2 | Existing patient search |
| B3 | Duplicate prevention for **active** visits |
| B4 | Patient profile and contact details |
| B5 | Clinic-scoped patient ID (`P-00012`) |
| B6 | Visit history |
| B7 | Patient record updates |
| B8 | Patient-specific tracking link |
| B9 | **Not an EMR** — no prescriptions, diagnoses, complex clinical notes or hospital-level records |

### 4.3 Queue engine — C

Nine internal states, friendly labels:

| Internal | User-facing |
|---|---|
| `waiting` | Waiting |
| `called` | Called — please proceed |
| `in_consultation` | With doctor |
| `completed` | Completed |
| `skipped` | Skipped |
| `no_show` | Not present |
| `on_hold` | On hold |
| `transferred` | Moved to another queue |
| `cancelled` | Cancelled |

Allowed transitions:

| From | Actions |
|---|---|
| **waiting** | Call · Skip · Mark absent · Cancel · Priority (authorised) · Transfer · Hold |
| **called** | Start consultation · Call again · Skip · Mark absent · Cancel · Transfer · Hold |
| **in_consultation** | Complete · Transfer (authorised) · Cancel (permitted) |
| **completed** | — |
| **skipped** | Re-queue · Cancel |
| **no_show** | Re-queue · Cancel |
| **on_hold** | Resume · Cancel |
| **transferred** | Re-queue where permitted |
| **cancelled** | — |

**Business rules (all mandatory, all enforced in the domain layer):**

| # | Rule |
|---|---|
| C1 | Each doctor working on a given day has one queue |
| C2 | Tokens belong to a queue and date; never duplicated within it |
| C3 | A token may be issued **before** the session starts |
| C4 | **Calling is blocked until the session is running** |
| C5 | Only valid transitions allowed — enforced in backend, not by hidden buttons |
| C6 | A waiting patient cannot be completed directly |
| C7 | A repeated or stale request must not call the same patient twice |
| C8 | Priority requires an authorised actor **and a recorded reason** |
| C9 | Transfers preserve the original queue history |
| C10 | A completed visit cannot be called again |
| C11 | Cancelling a session requires confirmation and a reason |
| C12 | Every important queue action is recorded |
| C13 | Concurrent staff must not create duplicate or conflicting actions — transactions + locking |

### 4.4 Appointments — D

| # | Requirement |
|---|---|
| D1 | Public clinic booking page |
| D2 | Receptionist-created appointments |
| D3 | Doctor selection |
| D4 | Real available slots only |
| D5 | Respect doctor schedules, clinic hours, holidays, doctor leave |
| D6 | Respect existing bookings and the booking window |
| D7 | Confirmation, cancellation, rescheduling |
| D8 | Appointment check-in |
| D9 | Secure booking management links |
| D10 | Reminders when enabled |
| D11 | **Appointment ≠ queue token.** An appointment reserves a time; check-in creates or links the visit |
| D12 | **No double booking** — validated server-side at submit, not just at render |
| D13 | Check-in preserves the appointment's slot priority |

### 4.5 Doctors, rooms, departments — E

Doctor profiles, specialties, rooms, optional departments, doctor↔room and
doctor↔department assignment, daily queue creation, queue prefixes, working
schedules, breaks, holidays, leave, session management, appointment
availability.

**A one-doctor clinic must work with no department configuration at all.**

### 4.6 Waiting room & public displays — F

Main TV display, department display, public queue board, patient position
lookup, kiosk check-in, token slip with QR, automatic refresh.

**These screens must never expose private patient details.** A position lookup
returns a number, never a name or phone.

### 4.7 Notifications — G

Events: appointment confirmation, appointment reminder, token confirmation,
patient turn approaching, cancellation, rescheduling.

Channels: SMS, email, WhatsApp where configured.

Requirements: templates · delivery history · delivery status · retry · usage
tracking · duplicate prevention.

| # | Rule |
|---|---|
| G1 | **Notification failure must never stop a registration or a consultation** |
| G2 | Use an outbox and background processing — the queue transaction commits, a worker delivers |
| G3 | Integrations stay independent from the core queue engine |

### 4.8 Clinic management — H

Clinic profile · working hours · holidays · staff · roles & permissions ·
doctors · rooms · departments · queue preferences · appointment preferences ·
reports · audit activity · billing · branches.

**Advanced configuration stays out of the receptionist and doctor workspace.**

### 4.9 Advanced — optional, I

Clinical priority rules with reason and audit · transfers · hold/resume ·
custom workflows · offline token reservation and reconciliation · advanced
reports · integrations · organisation management · multiple branches · incident
management.

**Do not force small clinics to configure any of these.**

### 4.10 Platform administration — J

Clinic onboarding · tenancy · organisations · plans · billing · payment
verification · feature entitlements · support requests · incident management ·
platform health · usage monitoring · platform audit.

Separate console, separate permissions. **Show operational status only when
backed by actual monitoring data.**

---

## 5. Reporting — K

| Report | Contents |
|---|---|
| Daily | Total registered · Completed · Waiting · Skipped · No-shows · Cancelled |
| Queue | Average wait · Longest wait · Patients by doctor · Activity by hour · Completion |
| Doctor | Patients handled · Session activity · Completion · Avg processing time |
| Waiting time | **Percentiles (p50/p90), not just the mean** |
| Appointment | Total · Completed · Cancelled · No-shows |
| Satisfaction | Answers, average — from post-visit feedback |

| # | Rule |
|---|---|
| K1 | Waiting-time figures are **estimates, never guaranteed times** |
| K2 | Do not overwhelm small clinics with analytics |
| K3 | Export where appropriate; patient exports are owner-only and audited |

---

## 6. Patient experience — L

| # | Requirement |
|---|---|
| L1 | Public booking page, doctor selection, genuinely available times |
| L2 | Request appointment, receive confirmation |
| L3 | Manage a booking through a secure link |
| L4 | Check in, receive a token, scan the slip's QR |
| L5 | Track own queue position **with no account and no login** |
| L6 | See: token · doctor and room · status · patients ahead (a number) · estimated range · last updated · plain instructions |
| L7 | Cancel or reschedule where permitted |
| L8 | Rate the visit afterwards, where the clinic enables feedback |
| L9 | **Never** see another patient's name, phone, personal details or history |
| L10 | Tracking links are unpredictable, purpose-limited, revocable where supported, and expire — **18-hour default** (`security.track_token_hours`) |

---

## 7. Non-functional requirements

### 7.1 Security — M

| # | Requirement |
|---|---|
| M1 | Secure password hashing (bcrypt/argon2id) |
| M2 | CSRF protection on every state-changing request |
| M3 | Session security — regeneration on login, secure/httponly cookies |
| M4 | Login rate limiting and lockout |
| M5 | Password reset protection — hashed tokens, single use, short expiry |
| M6 | Email verification where required |
| M7 | Server-side authorisation, role **and** resource level |
| M8 | Tenant isolation, tested by an automated suite |
| M9 | Secure, unpredictable public tokens |
| M10 | Input validation and output escaping everywhere |
| M11 | Safe file uploads — private storage, never under `/public` |
| M12 | Environment-based secrets; nothing sensitive in the repo |
| M13 | Audit logs for important actions |
| M14 | Automated backups and **tested** restoration |
| M15 | **Never trust a clinic/patient/doctor ID from the browser without checking access** |

### 7.2 Performance & reliability — N

| # | Requirement |
|---|---|
| N1 | Efficient queries, appropriate indexes |
| N2 | Pagination on unbounded lists |
| N3 | Duplicate-submission protection |
| N4 | Queue concurrency protection — transactions, row locks, optimistic versioning |
| N5 | Transactional token generation |
| N6 | Background notification processing |
| N7 | Error logging |
| N8 | Sensible caching |
| N9 | Clear connection and loading states |
| N10 | Graceful slow-connectivity handling |
| N11 | **Never present cached data as live** — stale is labelled stale |
| N12 | Offline operations never silently create conflicting tokens; reconciliation is deliberate and human-reviewed |

### 7.3 Usability — O

| # | Requirement |
|---|---|
| O1 | Responsive desktop, tablet, mobile |
| O2 | Keyboard-friendly registration |
| O3 | Touch-friendly doctor controls (44px minimum) |
| O4 | Readable typography, clear contrast |
| O5 | No excessive animations |
| O6 | No unnecessary dashboard charts |
| O7 | No technical jargon in user-facing copy |
| O8 | One primary action per screen |
| O9 | Progressive disclosure — do not show all nine states and all actions at once |
| O10 | Sensible defaults — today's date, currently working doctors first, existing patient record when found |
| O11 | Explicit feedback for every important action |
| O12 | Safe mistakes — confirm destructive actions, inline validation, never discard unsaved changes |

---

## 8. Acceptance criteria

The system is not ready merely because its pages load.

### A small clinic must be able to

1. Create its account
2. Configure its clinic
3. Add one doctor
4. Invite one receptionist
5. Set basic working hours
6. Open the clinic for the day
7. Register walk-in patients
8. Issue tokens
9. Start the doctor's session
10. Call patients
11. Complete consultations
12. Manage appointments
13. Let patients track tokens without an account
14. Review a simple daily report

### And

- A receptionist understands the main interface after a short introduction.
- A doctor manages a queue without extensive software training.
- A patient tracks a token without logging in.
- **A clinic can never access another clinic's data.**
- Queue operations stay correct when multiple authorised staff act at once.
- The application remains usable when notifications fail.
- All important actions are validated on the server.

---

## 9. Decisions taken

| # | Decision | Reasoning |
|---|---|---|
| 1 | **Branches are `clinics` rows with `parent_clinic_id`** | A parallel `branches` table duplicates profile, hours, staff and queues and inevitably drifts. Roll-up reporting is one `WHERE`. |
| 2 | **Body text is 13px, page titles max 22px** | A dense operational tool; the receptionist must see a whole waiting list without scrolling. Matches the measured reference screens. |
| 3 | **Optimistic `version` column + `SELECT … FOR UPDATE`** | Handles double-clicks, retried POSTs and two receptionists acting at once with one mechanism, no client-side token plumbing. |
| 4 | **Availability is computed, not materialised** | No `appointment_slots` table by default. Materialising for every clinic is premature. |
| 5 | **Patient phone has no hard unique constraint** | Family members legitimately share a number. Duplicate detection is advisory — warn and offer to open the existing record. |
| 6 | **Birth year, not full date of birth** | Collect only what clinic operations need. |
| 7 | **Session-blocked-calling is a domain rule, not a permission** | It produces a friendly board message ("start the session first"), not a 403. Permissions and preconditions are different failure modes. |
| 8 | **Tracking tokens are the authority** | Never "log the patient in" to show a position — that converts a narrow expiring capability into a full account. |
| 9 | **No native `confirm()`/`prompt()`/`alert()`** | Unstyleable, silently blocked in embedded contexts, and they break keyboard flow. |
| 10 | **System font stack, no webfont** | Fast on clinic broadband and fully functional offline from the service worker. |

---

## 10. Explicit non-goals

Recorded so they are not re-litigated mid-build:

- Diagnosis, prescription, medication, lab results, imaging or clinical notes.
  **QueuePilot is not an EMR.**
- In-patient / bed / ward management.
- Insurance claim processing.
- Accounting beyond per-visit fee totals and invoices.
- Telemedicine / video consultations.
- A native mobile app (the PWA covers it).
- Multi-language content beyond the interface strings at launch.
