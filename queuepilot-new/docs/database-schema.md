# QueuePilot — Database schema

**Status:** Design stage · logical model, 34 tables.
**Engine:** MySQL 8.0 / MariaDB 10.6+, InnoDB, `utf8mb4_unicode_ci`.
SQLite is acceptable for local development only; MySQL is the target.

---

## 0. Principles

These are the rules the schema is built on. Where a decision was arguable, the
rule that decided it is stated.

1. **Every clinic-owned row carries `clinic_id`.** Not "the parent has it" —
   the row itself. A query that forgets to join can still be caught by a
   service-level assertion, and tenant scoping becomes a mechanical check rather
   than an exercise in remembering the join graph.
2. **`clinic_id` is denormalised where the join is hot.** `queue_entries` has
   `clinic_id` even though its queue has one. It costs 4 bytes and removes a
   join from the hottest query in the product.
3. **Appointments and queue entries are different records.** An appointment
   reserves a time; a queue entry is a visit. They are linked by
   `queue_entries.appointment_id`, never merged.
4. **Nothing operational is hard-deleted.** Patients, doctors, rooms and queues
   get `deleted_at` or an active flag. Deleting a doctor must not erase the
   history of the 400 people they saw.
5. **History is append-only.** `queue_events`, `audit_logs` and
   `incident_entries` are never updated. A correction is a new row.
6. **Money is integer cents / integer rupees**, never float.
7. **Time is stored in UTC** (`DATETIME`), rendered in the clinic's timezone.
   "Today" is a *clinic-local* date, so `queues.session_date` is a clinic-local
   `DATE` — a queue belongs to a clinic's day, not a server's day.
8. **Uniqueness that matters is enforced by the database**, not by application
   hope: one token per queue per day, one queue per doctor per day, no double
   booking.

---

## 1. Tenancy & identity

### `organisations`
Parent bodies owning multiple clinics. **Optional** — a single-site clinic has
`NULL`.

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `name` | VARCHAR(160) | |
| `slug` | VARCHAR(80) UNIQUE | |
| `contact_email` | VARCHAR(160) NULL | |
| `status` | ENUM(`active`,`suspended`) | default `active` |
| `created_at`, `updated_at` | DATETIME | |

### `clinics`
The tenant.

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `organisation_id` | FK → organisations, NULL | |
| `parent_clinic_id` | FK → clinics, NULL | set when this is a *branch* |
| `name` | VARCHAR(160) | |
| `slug` | VARCHAR(80) UNIQUE | public booking URL `/book/{slug}` |
| `phone`, `email` | VARCHAR | |
| `address_line`, `city`, `district` | VARCHAR | |
| `timezone` | VARCHAR(64) | default `Asia/Colombo` |
| `currency` | CHAR(3) | default `LKR` |
| `logo_path` | VARCHAR NULL | private storage, never under `/public` |
| `status` | ENUM(`pending`,`active`,`suspended`) | |
| `suspended_reason` | TEXT NULL | |
| `onboarding_step` | VARCHAR(40) NULL | resume point; NULL = complete |
| `deleted_at` | DATETIME NULL | |
| `created_at`, `updated_at` | DATETIME | |

> **Branches are clinics.** A branch is a `clinics` row with
> `parent_clinic_id` set. This avoids a parallel `branches` table that would
> have to duplicate profile, hours, staff and queues — and would inevitably
> drift. Roll-up reporting is `WHERE organisation_id = ? OR id = ?`.

### `users`
Login accounts — **global**, not per-clinic. One human, one account, many
clinics.

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `name` | VARCHAR(120) | |
| `email` | VARCHAR(160) UNIQUE | stored lowercased |
| `password_hash` | VARCHAR(255) | bcrypt/argon2id — never reversible |
| `phone` | VARCHAR(40) NULL | |
| `locale` | VARCHAR(10) | default `en` |
| `timezone` | VARCHAR(64) NULL | overrides clinic when set |
| `is_platform_admin` | TINYINT(1) | default 0 |
| `email_verified_at` | DATETIME NULL | |
| `last_login_at` | DATETIME NULL | |
| `failed_logins` | SMALLINT | lockout counter |
| `locked_until` | DATETIME NULL | |
| `status` | ENUM(`active`,`disabled`) | |
| `created_at`, `updated_at` | DATETIME | |

### `clinic_users`
The membership join. **This is where a role lives** — a user is not globally "a
receptionist", they are a receptionist *at a clinic*.

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `clinic_id` | FK → clinics | |
| `user_id` | FK → users | |
| `role` | ENUM(`owner`,`receptionist`,`doctor`,`manager`) | |
| `permission_overrides` | JSON NULL | explicit grants beyond the role |
| `status` | ENUM(`active`,`disabled`) | |
| `invited_by` | FK → users NULL | |
| `joined_at` | DATETIME NULL | NULL while pending |
| `created_at`, `updated_at` | DATETIME | |

**UNIQUE(`clinic_id`,`user_id`)** — a person is a member of a clinic once.

### `password_resets`, `email_verifications`, `login_attempts`
Standard: token hash, user, expiry, consumed_at. Tokens stored **hashed**, so a
database leak does not hand over live reset links. `login_attempts` keys on
(email, IP) for rate limiting.

---

## 2. People

### `patients`
Clinic-scoped register.

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `clinic_id` | FK → clinics | **tenant key** |
| `patient_code` | VARCHAR(20) | `P-00012` — clinic-scoped, human-readable |
| `full_name` | VARCHAR(160) | |
| `phone` | VARCHAR(40) | |
| `gender` | ENUM(`male`,`female`,`other`,`unspecified`) NULL | |
| `birth_year` | SMALLINT NULL | **year, not full DOB** — collect what is needed |
| `address_line` | VARCHAR NULL | |
| `notes` | TEXT NULL | operational notes, **not** clinical records |
| `created_by` | FK → users NULL | |
| `deleted_at` | DATETIME NULL | |
| `created_at`, `updated_at` | DATETIME | |

**Indexes:** (`clinic_id`,`phone`), (`clinic_id`,`full_name`),
(`clinic_id`,`patient_code`).
**UNIQUE(`clinic_id`,`patient_code`)**.

> Duplicate prevention is *advisory*, not a hard unique on phone: two family
> members may legitimately share a number, and a clinic must be able to record
> that. The service warns and offers to open the existing record.

### `doctors`

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `clinic_id` | FK → clinics | |
| `user_id` | FK → users NULL | set when the doctor logs in |
| `full_name` | VARCHAR(160) | |
| `specialty_id` | FK → specialties NULL | |
| `room_id` | FK → rooms NULL | default room |
| `department_id` | FK → departments NULL | |
| `consultation_fee` | INT NULL | integer rupees |
| `queue_prefix` | VARCHAR(3) | `A`, `B`, `C` |
| `phone`, `email` | VARCHAR NULL | |
| `is_active` | TINYINT(1) | default 1 |
| `deleted_at` | DATETIME NULL | |
| `created_at`, `updated_at` | DATETIME | |

**UNIQUE(`clinic_id`,`queue_prefix`)** — two doctors issuing `A` tokens on the
same day is the exact ambiguity the product exists to prevent.

### `specialties`
(`id`, `clinic_id` NULL, `name`) — `clinic_id` NULL marks a global seed
specialty.

### `rooms`
(`id`, `clinic_id`, `name`, `department_id` NULL, `is_available`,
`deleted_at`). A room assigned to a doctor cannot be removed — enforced in the
service, checked before delete.

### `departments`
(`id`, `clinic_id`, `name`, `code`, `floor`, `description`,
`lead_doctor_id` NULL, `display_key` NULL, `is_active`, `deleted_at`).
Optional: a clinic with no departments never sees them.

### `department_assignments`
(`id`, `clinic_id`, `department_id`, `doctor_id` NULL, `queue_id` NULL,
`assigned_date`) — today's assignments, which drive the per-department display
and the *By department* report.

---

## 3. Schedule

### `doctor_schedules`
Recurring weekly pattern.

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `clinic_id` | FK | |
| `doctor_id` | FK → doctors | |
| `weekday` | TINYINT | 0=Sunday … 6=Saturday |
| `start_time` | TIME | |
| `end_time` | TIME | |
| `is_working` | TINYINT(1) | default 1 |

**UNIQUE(`doctor_id`,`weekday`)**.
Online booking intersects this with clinic hours, holidays, leave and existing
bookings.

### `doctor_leaves`
(`id`, `clinic_id`, `doctor_id`, `start_date`, `end_date`, `reason`,
`created_at`). A doctor on leave is **not offered** on the booking form;
existing appointments are left untouched (the UI says so).

### `clinic_holidays`
(`id`, `clinic_id`, `holiday_date`, `name`, `is_annual` TINYINT).
**UNIQUE(`clinic_id`,`holiday_date`)**.

### `clinic_hours`
(`id`, `clinic_id`, `weekday`, `open_time`, `end_time`, `is_closed`).
**UNIQUE(`clinic_id`,`weekday`)**. Drives "open for the day" and the booking
grid.

### `scheduled_breaks`
Recurring planned breaks: (`id`, `clinic_id`, `doctor_id` NULL, `weekday`,
`start_time`, `end_time`, `label`).

### `queue_breaks`
Actual breaks taken: (`id`, `clinic_id`, `queue_id`, `type`, `reason`,
`started_at`, `ended_at` NULL, `created_by`).
**A live break must stretch the estimated waits** — a queue that ignores a
lunch break lies to patients.

---

## 4. The queue engine

### `sessions`
A doctor's working day.

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `clinic_id` | FK | |
| `doctor_id` | FK → doctors | |
| `session_date` | DATE | **clinic-local** |
| `status` | ENUM(`not_started`,`running`,`paused`,`closed`,`cancelled`) | |
| `started_at`, `ended_at` | DATETIME NULL | |
| `pause_reason`, `cancel_reason` | TEXT NULL | |
| `created_at`, `updated_at` | DATETIME | |

**UNIQUE(`doctor_id`,`session_date`)**.
**The blocking rule:** calling is illegal unless a session for that doctor and
date is `running`. Enforced in `QueueService`, not in the view.

### `queues`
One doctor's queue for one day.

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `clinic_id` | FK | |
| `doctor_id` | FK → doctors | |
| `session_id` | FK → sessions NULL | |
| `session_date` | DATE | clinic-local |
| `token_prefix` | VARCHAR(3) | copied from the doctor, snapshot at creation |
| `last_token_number` | INT | default 0 — the allocation cursor |
| `status` | ENUM(`open`,`paused`,`closed`) | |
| `estimated_minutes_per_patient` | INT NULL | |
| `created_at`, `updated_at` | DATETIME | |

**UNIQUE(`doctor_id`,`session_date`)** — one queue per doctor per day.

### `queue_entries`
A token. One patient's place in one queue on one day.

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `clinic_id` | FK | **denormalised tenant key** |
| `queue_id` | FK → queues | |
| `patient_id` | FK → patients | |
| `appointment_id` | FK → appointments NULL | set when checked in |
| `token_number` | INT | sequence within the queue |
| `token_display` | VARCHAR(12) | `A001` — generated, stored for display & search |
| `status` | ENUM(9 states) | see below |
| `priority` | TINYINT | default 0 |
| `priority_reason` | TEXT NULL | **required when priority is set** |
| `priority_set_by` | FK → users NULL | |
| `hold_reason` | VARCHAR NULL | |
| `transfer_from_queue_id` | FK → queues NULL | preserves origin history |
| `cancel_reason` | TEXT NULL | |
| `called_count` | SMALLINT | default 0 |
| `called_at`, `consultation_started_at`, `completed_at` | DATETIME NULL | |
| `joined_at` | DATETIME | |
| `version` | INT | **optimistic lock** |
| `track_token` | VARCHAR(64) UNIQUE NULL | patient tracking link |
| `track_expires_at` | DATETIME NULL | default +18h (`security.track_token_hours`) |
| `created_by` | FK → users NULL | |
| `created_at`, `updated_at` | DATETIME | |

**UNIQUE(`queue_id`,`token_number`)** — the database, not the application,
guarantees no duplicate tokens.

**The nine states:** `waiting`, `called`, `in_consultation`, `completed`,
`skipped`, `no_show`, `on_hold`, `transferred`, `cancelled`.

**Concurrency.** Every transition is:

```
BEGIN;
SELECT ... WHERE id = ? AND version = ? FOR UPDATE;   -- row lock
-- validate: session running, transition legal, not stale
UPDATE queue_entries SET status = ?, version = version + 1 WHERE id = ? AND version = ?;
INSERT INTO queue_events ...;
COMMIT;
```

A request carrying a stale `version` affects 0 rows and is rejected as stale —
this is what makes a double-click, a retried POST and two receptionists acting
at once all safe, without any client-side trickery.

### `queue_events`
Append-only history. **Never updated, never deleted.**

(`id`, `clinic_id`, `queue_entry_id`, `from_status` NULL, `to_status`,
`action`, `actor_user_id` NULL, `reason` NULL, `metadata` JSON NULL,
`created_at`). Index (`queue_entry_id`,`created_at`).

### `workflow_steps` / `entry_step_done`
Optional multi-step visits (counter → triage → doctor). A step removed from the
workflow keeps its `entry_step_done` records for visits that already passed it.

### `priority_rules`
Configurable automatic priority: (`id`, `clinic_id`, `name`, `type`
(`age`,`pregnancy`,`service`,`wait_time`), `params` JSON, `priority_value`,
`sort_order`, `is_active`). A rule that fires logs the reason — automatic must
still be auditable.

### `priority_rule_history`
(`id`, `clinic_id`, `rule_id`, `changed_by`, `change_type`, `before` JSON,
`after` JSON, `created_at`).

### `offline_desks` / `offline_reservations` / `offline_blocks`
Reserved token blocks for when the internet drops.
`offline_blocks`: (`id`, `clinic_id`, `queue_id`, `start_number`, `end_number`,
`taken_at`, `released_at` NULL).
Reconciliation is **deliberate**: conflicts are listed for a human, never
auto-merged.

---

## 5. Appointments

### `appointments`
A reserved time. **Not** a queue entry.

| Column | Type | Notes |
|---|---|---|
| `id` | PK | |
| `clinic_id` | FK | |
| `patient_id` | FK → patients | |
| `doctor_id` | FK → doctors | |
| `appointment_date` | DATE | |
| `start_time`, `end_time` | TIME | |
| `duration_minutes` | SMALLINT | |
| `status` | ENUM(`booked`,`checked_in`,`served`,`completed`,`cancelled`,`no_show`,`rescheduled`) | |
| `source` | ENUM(`web`,`phone`,`walk_in`,`kiosk`) | |
| `notes` | TEXT NULL | |
| `cancel_reason` | TEXT NULL | |
| `manage_token` | VARCHAR(64) UNIQUE | public `/a/{token}` link |
| `checked_in_at` | DATETIME NULL | |
| `created_by` | FK → users NULL | |
| `created_at`, `updated_at` | DATETIME | |

**No double booking.** Enforced two ways, because optimistic checks alone race:

1. **Partial unique index** on (`doctor_id`,`appointment_date`,`start_time`)
   where status is in the live set (`booked`,`checked_in`) — the database
   refuses the second row.
2. A `SELECT … FOR UPDATE` on the doctor's day inside the booking transaction,
   so the availability check and the insert are one atomic step.

Cancelled and rescheduled rows are excluded from the unique index, so a slot
can legitimately be rebooked.

### `appointment_policies`
(`id`, `clinic_id`, `service_key` NULL, `lead_time_hours`,
`cancel_window_hours`, `lateness_grace_minutes`, `slot_minutes`,
`booking_window_days`, `checkin_window_minutes`).
`service_key` NULL is the clinic default; a row with a key is an override.

### `appointment_slots`
Materialised only if a clinic needs explicit slot granularity beyond generated
availability. **Not created by default** — availability is computed from
schedule ∩ hours − holidays − leave − bookings. Materialising it for everyone
is premature.

---

## 6. Notifications

Kept deliberately independent of the queue engine. **An SMS outage must never
stop a registration or a consultation.**

### `notification_outbox`
(`id`, `clinic_id`, `channel` ENUM(`sms`,`email`,`whatsapp`), `event`,
`recipient`, `body`, `template_id` NULL, `status`
ENUM(`pending`,`sending`,`sent`,`failed`), `attempts`, `next_attempt_at`,
`last_error` NULL, `dedupe_key` NULL, `created_at`, `sent_at` NULL).

The queue transaction **inserts** a row and commits. It never calls a gateway.
A worker drains the outbox. **UNIQUE(`dedupe_key`)** prevents double-sends.

### `notification_logs`
(`id`, `clinic_id`, `outbox_id`, `channel`, `status`, `provider_reference`
NULL, `cost_cents` NULL, `created_at`). Delivery history and usage metering.

### `notification_templates`
(`id`, `clinic_id`, `event`, `channel`, `subject` NULL, `body`,
`is_default` TINYINT, `updated_at`).
**UNIQUE(`clinic_id`,`event`,`channel`)**.

### `notification_settings`
(`id`, `clinic_id`, `key`, `value`) — the three clinic-wide switches, plus
per-clinic channel config.

### `integrations`
(`id`, `clinic_id`, `service`, `is_connected`, `credentials` **encrypted**,
`settings` JSON, `updated_at`).

### `sms_usage`
(`id`, `clinic_id`, `period_month`, `sent_count`, `allowance`) — metered
against the plan.

---

## 7. Billing & platform

### `plans`
(`id`, `name`, `slug`, `price_cents` INT, `currency`, `billing_period`,
`limits` JSON, `features` JSON, `is_visible`, `sort_order`, `created_at`).
**Prices and limits are data.** Nothing about a plan is a constant in code.

### `subscriptions`
(`id`, `clinic_id`, `plan_id`, `status`
ENUM(`trialing`,`active`,`past_due`,`cancelled`,`suspended`),
`current_period_start`, `current_period_end`, `trial_ends_at` NULL,
`cancelled_at` NULL, `created_at`).
**UNIQUE(`clinic_id`)** where status is active — one live subscription.

### `feature_entitlements`
(`id`, `clinic_id`, `feature_key`, `is_enabled`, `source`
ENUM(`plan`,`override`), `updated_by` NULL).
An `override` row beats the plan default. **Enforcement lives in the service
layer** — `FeatureService::enabled($clinicId, $key)` — never in a hidden button.

### `invoices`
(`id`, `clinic_id`, `subscription_id` NULL, `invoice_number` UNIQUE,
`period_start`, `period_end`, `amount_cents`, `tax_cents`, `status`
ENUM(`draft`,`open`,`paid`,`void`,`uncollectible`), `issued_at`,
`due_at`, `paid_at` NULL, `created_at`).

### `payments`
(`id`, `clinic_id`, `invoice_id` NULL, `method`
ENUM(`card`,`bank_transfer`,`cash`), `amount_cents`, `reference` NULL,
`evidence_path` NULL, `status`
ENUM(`pending`,`submitted`,`verified`,`rejected`), `submitted_at` NULL,
`verified_by` NULL, `verified_at` NULL, `rejection_reason` NULL, `created_at`).

---

## 8. Operations

### `audit_logs`
(`id`, `clinic_id` NULL, `actor_user_id` NULL, `actor_role` NULL, `action`,
`entity_type`, `entity_id`, `detail` JSON NULL, `ip_address`, `user_agent`,
`created_at`).
**Append-only.** Index (`clinic_id`,`created_at`), (`entity_type`,`entity_id`).
`clinic_id` is NULL for platform-level actions.

### `support_tickets`
(`id`, `clinic_id`, `user_id`, `subject`, `status`
ENUM(`open`,`pending`,`resolved`,`closed`), `priority`, `created_at`,
`updated_at`).

### `support_replies`
(`id`, `ticket_id`, `user_id` NULL, `body`, `is_internal` TINYINT,
`created_at`). `is_internal` hides platform notes from the clinic.

### `incidents`
(`id`, `clinic_id` NULL, `title`, `severity`
ENUM(`minor`,`major`,`critical`), `summary`, `status`
ENUM(`open`,`acknowledged`,`mitigated`,`resolved`), `opened_by`,
`acknowledged_by` NULL, `resolved_at` NULL, `created_at`).

### `incident_entries`
(`id`, `incident_id`, `entry_type`, `body`, `author_user_id`,
`corrects_entry_id` NULL, `created_at`).
**Append-only.** A correction is a *new entry pointing at the old one*, so a
post-mortem cannot quietly rewrite itself.

### `public_display_keys`
(`id`, `clinic_id`, `department_id` NULL, `display_key` UNIQUE, `label`,
`is_active`, `rotated_at` NULL, `created_at`).
Rotating invalidates the old link — the UI warns that screens using it stop
updating.

### `system_alerts`
(`id`, `scope`, `severity`, `title`, `detail` JSON, `raised_at`,
`resolved_at` NULL) — feeds `/admin/health`.

### `settings`
(`id`, `clinic_id`, `key`, `value` JSON) — the clinic's miscellaneous
configuration. **UNIQUE(`clinic_id`,`key`)**.

---

## 9. Table count & index summary

| Group | Tables |
|---|---|
| Tenancy & identity | organisations, clinics, users, clinic_users, password_resets, email_verifications, login_attempts |
| People | patients, doctors, specialties, rooms, departments, department_assignments |
| Schedule | doctor_schedules, doctor_leaves, clinic_holidays, clinic_hours, scheduled_breaks, queue_breaks |
| Queue engine | sessions, queues, queue_entries, queue_events, workflow_steps, entry_step_done, priority_rules, priority_rule_history, offline_desks, offline_reservations, offline_blocks |
| Appointments | appointments, appointment_policies, *(appointment_slots — deferred)* |
| Notifications | notification_outbox, notification_logs, notification_templates, notification_settings, integrations, sms_usage |
| Billing | plans, subscriptions, feature_entitlements, invoices, payments |
| Operations | audit_logs, support_tickets, support_replies, incidents, incident_entries, public_display_keys, system_alerts, settings |

**34 tables** (35 if `appointment_slots` is materialised later).

### The five indexes that matter

| Index | Serves |
|---|---|
| `queue_entries(clinic_id, status, joined_at)` | the live board — the hottest query |
| `queue_entries UNIQUE(queue_id, token_number)` | no duplicate tokens |
| `appointments UNIQUE(doctor_id, appointment_date, start_time) WHERE live` | no double booking |
| `queue_entries UNIQUE(track_token)` | patient tracking lookup |
| `patients(clinic_id, phone)`, `patients(clinic_id, full_name)` | receptionist search |

---

## 10. Deliberate omissions

Recorded so they are not "discovered" later as gaps:

- **No `branches` table** — a branch is a clinic with `parent_clinic_id`.
- **No `appointment_slots` by default** — availability is computed.
- **No diagnoses, prescriptions, clinical notes or attachments.** QueuePilot is
  explicitly **not** an EMR, and adding those tables would drag in consent,
  retention and clinical-safety obligations the product does not have.
- **No `sessions` separate from `queues` by force** — a queue may exist with a
  `NULL` session (tokens issued before the doctor arrives), which is the
  required behaviour, not an anomaly.
- **No soft-delete on `queue_events`, `audit_logs`, `incident_entries`** — they
  are immutable by design.
