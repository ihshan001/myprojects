# QueuePilot — Screen → feature mapping

**Status:** Design stage · all 106 screens.
**Purpose:** ties every designed screen to the route, permission, backend
capability, database tables and neighbouring screens it depends on, so design
and implementation cannot drift apart.

**Columns**

- **Role** `R` receptionist · `D` doctor · `O` owner · `P` public/patient · `A` platform admin · `all`
- **Perm** the capability gate (see `permissions.md`)
- **Tables** the database tables the screen reads or writes
- **→** connected screens, referenced by their `screen-inventory.md` number

---

## 1. Authentication

| Screen | Role | Route | Actions → backend | Perm | Tables | → |
|---|---|---|---|---|---|---|
| 1.1 Login | all | `GET/POST /login` | authenticate, rate-limit, lockout, session regenerate | public | `users`, `login_attempts` | 1.2, 2.1 |
| 1.2 Forgot password | all | `GET/POST /forgot-password` | issue hashed reset token, mail or one-time display | public | `password_resets` | 1.1, 1.3 |
| 1.3 Password reset | all | `GET/POST /reset-password` | validate token, hash new password, consume token | token | `password_resets`, `users` | 1.1 |
| 1.4 Email verification | all | `GET /verify-email` | consume token, stamp `email_verified_at` | token | `email_verifications`, `users` | 1.1, 2.1 |
| 1.5 User profile | all | `GET/POST /profile` | update own account, change password, list sessions | authenticated | `users` | everywhere |

---

## 2. Clinic onboarding

| Screen | Role | Route | Actions → backend | Perm | Tables | → |
|---|---|---|---|---|---|---|
| 2.1 Welcome | O | `GET /onboarding` | create draft tenant | authenticated | `clinics` | 2.2 |
| 2.2 Clinic registration | O | `GET/POST /onboarding/register` | create clinic + owner membership | public | `clinics`, `users`, `clinic_users` | 2.3 |
| 2.3 Clinic details | O | `GET/POST /onboarding/clinic` | save profile, timezone, currency | `clinic.update` | `clinics` | 2.4 |
| 2.4 Add first doctor | O | `GET/POST /onboarding/doctor` | create doctor, allocate prefix, optionally open queue | `doctor.manage` | `doctors`, `queues`, `sessions` | 2.5 |
| 2.5 Working hours | O | `GET/POST /onboarding/hours` | save seven-day hours and booking window | `clinic.update` | `clinic_hours` | 2.6 |
| 2.6 Invite receptionist | O | `GET/POST /onboarding/team` | create invitation, mail or show link once | `staff.invite` | `clinic_users`, `staff_invites` | 2.7 |
| 2.7 Completion | O | `GET /onboarding/done` | clear `onboarding_step`, seed defaults | `clinic.update` | `clinics`, `settings` | 3.1, D1 |

---

## 3. Receptionist

| Screen | Role | Route | Actions → backend | Perm | Tables | → |
|---|---|---|---|---|---|---|
| 3.1 Overview | R, O | `GET /app` | today's stats, live queue, tracking QR; poll `GET /app/panel` | `queue.view` | `queues`, `queue_entries`, `sessions`, `doctors`, `public_display_keys` | 3.4a, 3.8, 3.10, D1 |
| 3.2 Patient registration | R, O | `GET/POST /patients/new` | create patient, duplicate check, allocate `patient_code` | `patient.create` | `patients`, `settings` | 3.4a, 3.1 |
| 3.3 Patient search | R, O, D | `GET /api/patient/search` | live name/phone/code search, in-queue-today flag | `patient.view` | `patients`, `queue_entries` | 3.4, 3.4a |
| 3.4 Patient details | R, O | `GET /patients/{id}` | load profile, visit & appointment history | `patient.view` | `patients`, `queue_entries`, `appointments` | 3.4a, 3.10, 5.6 |
| 3.4a **Issue token** | R, O | `GET/POST /queue/new` | create entry, allocate token, generate tracking token, queue notification | `token.issue` | `queue_entries`, `queues`, `patients`, `notification_outbox` | 3.1, 3.8, 5.6 |
| 3.5 Visit history | R, O, D | panel on 3.4 | paginated past visits with wait/consult times | `patient.view_history` | `queue_entries`, `queue_events`, `doctors` | 3.7 |
| 3.6 Patient edit | R, O | `POST /patients/{id}` | update demographics, re-run duplicate check | `patient.update` | `patients`, `audit_logs` | 3.4 |
| 3.7 Token / visit details | R, O, D | `GET /queue/entry?id=` | full journey, legal actions, workflow steps, event history | `queue.view` + per-action | `queue_entries`, `queue_events`, `workflow_steps`, `entry_step_done` | 3.8, 3.1 |
| 3.8 Queue board | R, O, D | `GET /queue` | full list (priority then arrival), scan/lookup, session banner | `queue.view` | `queues`, `queue_entries`, `sessions`, `queue_breaks` | 3.4a, 3.7, 3.9 |
| 3.9 Pause / resume | R, O, D | `POST /queue/pause` | pause or resume with optional reason | `session.pause` | `queues`, `sessions`, `audit_logs` | 3.8 |
| 3.10 Appointment list | R, O | `GET /appointments` | day list filtered by date/doctor/status | `appointment.view` | `appointments`, `patients`, `doctors` | 3.11, 3.12, 3.13 |
| 3.11 Appointment booking | R, O | `GET/POST /appointments/new` | compute real availability, create booking | `appointment.create` | `appointments`, `doctor_schedules`, `clinic_hours`, `clinic_holidays`, `doctor_leaves`, `appointment_policies` | 3.10 |
| 3.12 Appointment details | R, O, D | `GET /appointments/{id}` | load booking, status history, linked entry | `appointment.view` | `appointments`, `queue_entries` | 3.13, 3.4 |
| 3.13 **Check-in** | R, O | `POST /appointments/{id}/check-in` | create queue entry from booking, **preserve slot priority** | `appointment.check_in` | `appointments`, `queue_entries`, `queues`, `notification_outbox` | 3.8, 3.1 |

---

## 4. Doctor workspace

| Screen | Role | Route | Actions → backend | Perm | Tables | → |
|---|---|---|---|---|---|---|
| 4.1 Doctor dashboard | D | `GET /doctor` | own session state, own queue summary, own day | `queue.view` (own) | `sessions`, `queues`, `queue_entries` | 4.4, 4.9 |
| 4.2 Session not started | D | state on 4.1 | start session | `session.start` (own) | `sessions` | 4.3 |
| 4.3 Active session | D | state on 4.1 | pause, end, cancel day with reason | `session.pause` / `session.cancel_day` (own) | `sessions`, `audit_logs` | 4.4 |
| 4.4 Doctor's queue | D | `GET /doctor/queue` | own waiting list, all state actions | `queue.*` (own) | `queue_entries`, `queue_events` | 4.5, 4.6, 3.7 |
| 4.5 Patient called | D, R | state on 4.4 | start consultation, recall, skip, no-show, hold | `queue.start_consultation` (own) | `queue_entries`, `queue_events` | 4.6 |
| 4.6 Active consultation | D | state on 4.4 | complete, transfer, cancel | `queue.complete` (own) | `queue_entries`, `queue_events` | 4.7 |
| 4.7 **Complete & call next** | D | `POST /doctor/complete` | complete ± call next — **never auto-calls** | `queue.complete_and_call_next` (own) | `queue_entries`, `queue_events`, `audit_logs` | 4.4 |
| 4.8 Patient history | D | `GET /doctor/patients/{id}` | own-visit history for a patient | `patient.view_history` (own) | `queue_entries`, `queue_events` | 4.4 |
| 4.9 Doctor's appointments | D | `GET /doctor/appointments` | own booked list, own leave days | `appointment.view` (own) | `appointments`, `doctor_leaves` | 3.12 |

---

## 5. Patient experience

| Screen | Role | Route | Actions → backend | Perm | Tables | → |
|---|---|---|---|---|---|---|
| 5.1 Public clinic page | P | `GET /book/{slug}` | clinic profile, doctors, next availability | public | `clinics`, `doctors`, `specialties` | 5.2, 5.3 |
| 5.2 Doctor selection | P | panel on 5.1 | doctors with next free slot, leave-aware | public | `doctors`, `doctor_leaves`, `doctor_schedules` | 5.3 |
| 5.3 Appointment booking | P | `GET/POST /book/{slug}/{doctor}` | compute availability, create booking, send confirmation | public + rate limit | `appointments`, `patients`, `appointment_policies`, `notification_outbox` | 5.4 |
| 5.4 Booking confirmation | P | `GET /a/{token}` | load booking by manage token | token | `appointments` | 5.5 |
| 5.5 Manage appointment | P | `POST /a/{token}` | reschedule, cancel, within policy | token | `appointments`, `appointment_policies` | 5.1 |
| 5.6 **Patient token details** | P | `GET /t/{track-token}` | own position, wait range, instruction; reschedule, cancel, rate | token (expiring) | `queue_entries`, `queues`, `doctors`, `rooms` | 5.7 |
| 5.7 Live queue tracking | P | state on 5.6 | poll position; stale/offline labelling | token | `queue_entries`, `queue_events` | 5.6 |
| 5.8 QR token access | P | encoded on slip | resolve tracking token from QR | token | `queue_entries` | 5.6 |

---

## 6. Clinic management

| Screen | Role | Route | Actions → backend | Perm | Tables | → |
|---|---|---|---|---|---|---|
| 6.1 Clinic profile | O | `GET/POST /settings/clinic` | update profile, timezone, currency, logo | `clinic.update` | `clinics` | 6.2 |
| 6.2 Doctors | O | `GET /doctors` | list, add (with open-queue option), deactivate, leave | `doctor.manage` | `doctors`, `doctor_leaves`, `queues` | 6.3, 6.4 |
| 6.3 Doctor details | O | `GET/POST /doctors/{id}` | profile, schedule, leave, queue history | `doctor.manage` | `doctors`, `doctor_schedules`, `doctor_leaves` | 6.6, 6.7 |
| 6.4 Rooms | O | `POST /settings/rooms` | add, edit, assign, remove (blocked while assigned) | `room.manage` | `rooms`, `doctors`, `departments` | 6.2, 6.5 |
| 6.5 Departments | O | `GET/POST /departments` | CRUD, display key + rotation, today's assignments | `department.manage` | `departments`, `department_assignments`, `public_display_keys` | D2, 8.4 |
| 6.6 Working schedules | O | `GET/POST /calendar` | per-doctor weekly pattern, clear to default | `schedule.manage` | `doctor_schedules`, `clinic_hours` | 5.3 |
| 6.7 Break schedules | O | `GET/POST /breaks` | start/end live break, plan recurring, history | `schedule.manage` | `queue_breaks`, `scheduled_breaks`, `queues` | 3.8, D1 |
| 6.8 Holidays | O | `POST /calendar/holidays` | close a date, reopen, annual repeat | `holiday.manage` | `clinic_holidays` | 5.3 |
| 6.9 Staff management | O | `GET/POST /settings/team` | invite, change role, disable, revoke, resend | `staff.invite` | `clinic_users`, `staff_invites`, `users` | 6.10 |
| 6.10 User permissions | O | `GET/POST /settings/permissions` | role matrix + explicit overrides, audited | `permissions.manage` | `clinic_users`, `audit_logs` | 6.9 |
| 6.11 Clinic settings | O | `GET /settings` | five tabs; export; danger zone (purge, name-confirmed) | `clinic.update` / `data.purge` | `settings`, `patients`, `queue_entries` | 7.3, 7.4 |
| 6.12 Queue settings | O | `GET/POST /queues/config` | clinic defaults, per-queue rename and prefix | `queue.configure` | `queues`, `doctors`, `settings` | 3.8 |
| 6.13 Appointment settings | O | `GET/POST /queues/appointment-policies` | lead time, cancel window, grace, slot length, overrides | `appointment.policy.manage` | `appointment_policies` | 5.3, 3.11 |

---

## 7. Notifications

| Screen | Role | Route | Actions → backend | Perm | Tables | → |
|---|---|---|---|---|---|---|
| 7.1 Notification centre | R, O, D | `GET /notifications` | list sends, mark read, retry failed | `notification.configure` / own | `notification_outbox`, `notification_logs` | 7.7 |
| 7.2 Templates | O | `GET/POST /settings/templates` | edit copy, merge fields, revert to default | `notification.configure` | `notification_templates` | 7.3 |
| 7.3 Notification settings | O | `POST /settings/notifications` | three clinic-wide switches | `notification.configure` | `notification_settings` | 7.1 |
| 7.4 SMS integration | O | `POST /settings/integrations` | connect/disconnect, credentials, test send, usage | `integration.configure` | `integrations`, `sms_usage` | 7.7 |
| 7.5 Email integration | O | `POST /settings/integrations` | transport, from address, test send | `integration.configure` | `integrations` | 7.7 |
| 7.6 WhatsApp integration | O | `POST /settings/integrations` | provider, template namespace, test send | `integration.configure` | `integrations` | 7.7 |
| 7.7 Delivery history | O | `GET /notifications/history` | all sends, status, attempts, cost, retry | `notification.configure` | `notification_logs`, `notification_outbox` | 7.1 |

---

## 8. Reports

| Screen | Role | Route | Actions → backend | Perm | Tables | → |
|---|---|---|---|---|---|---|
| 8.1 Daily clinic report | O, R | `GET /reports` | daily totals, arrivals by hour, outcomes; print/CSV | `report.view.basic` | `queue_entries`, `queue_events`, `patients` | 8.7 |
| 8.2 Patient statistics | O | `GET /reports/patients` | new vs returning, age bands, by doctor/department | `report.view.full` | `patients`, `queue_entries` | 8.7 |
| 8.3 Doctor activity | O | `GET /reports/doctors` | seen, avg wait, avg consult, priority count | `report.view.full` | `queue_entries`, `sessions`, `doctors` | 8.7 |
| 8.4 Queue performance | O | `GET /reports/queues` | activity by hour, completion/transfer/hold/no-show rates | `report.view.full` | `queue_entries`, `queue_events` | 8.7 |
| 8.5 Waiting-time report | O | `GET /reports/waiting` | **p50/p90 percentiles**, longest waits — labelled estimates | `report.view.full` | `queue_entries`, `queue_breaks` | 8.7 |
| 8.6 Appointment reports | O | `GET /reports/appointments` | totals, outcomes, lead-time distribution, source | `report.view.full` | `appointments` | 8.7 |
| 8.7 Export reports | O | `GET/POST /settings/export` | select scope, build archive, preview rows, download | `data.export` | all clinic tables | 12.2 |

---

## 9. Subscription & billing

| Screen | Role | Route | Actions → backend | Perm | Tables | → |
|---|---|---|---|---|---|---|
| 9.1 Current subscription | O | `GET /billing` | plan, inclusions, renewal, usage vs limits | `subscription.manage` | `subscriptions`, `plans`, `feature_entitlements` | 9.2, 9.4 |
| 9.2 Available plans | O | `GET /billing/plans` | list plans with limits and features | `subscription.manage` | `plans` | 9.3, 9.4 |
| 9.3 Plan comparison | O | `GET /billing/compare` | capability × plan matrix | `subscription.manage` | `plans` | 9.2 |
| 9.4 Subscription management | O | `POST /billing/subscription` | change plan (confirm, warn on downgrade), cancel | `subscription.manage` | `subscriptions`, `audit_logs` | 9.5 |
| 9.5 Invoices | O | `GET /billing/invoices` | list invoices, submit "I have paid", withdraw claim | `invoice.view` / `payment.submit` | `invoices`, `payments` | 9.7 |
| 9.6 Payment history | O | `GET /billing/payments` | payments with verification state | `invoice.view` | `payments` | 9.7 |
| 9.7 Payment details | O | `GET /billing/payments/{id}` | one payment, evidence, rejection reason | `invoice.view` | `payments`, `invoices` | 9.5 |

---

## 10. Organisation & branches

| Screen | Role | Route | Actions → backend | Perm | Tables | → |
|---|---|---|---|---|---|---|
| 10.1 Organisation dashboard | O | `GET /organisation` | roll-up across branches — **no patient names** | `clinic.update` | `clinics`, `queue_entries`, `clinic_users` | 10.2, 10.5 |
| 10.2 Branch list | O | `GET /settings/branches` | list and add branches | `clinic.update` | `clinics` | 10.3 |
| 10.3 Branch details | O | `GET/POST /settings/branches/{id}` | profile, hours, staff, status | `clinic.update` | `clinics`, `clinic_hours` | 10.4 |
| 10.4 Branch management | O | `POST /settings/branches/{id}/access` | grant / remove branch access; switch active context | `clinic.update` | `branch_grants`, `clinic_users` | 10.2 |
| 10.5 Branch-level reporting | O | `GET /reports/branches` | daily report sliced and compared by branch | `report.view.full` | `clinics`, `queue_entries` | 10.1 |

---

## 11. Platform administration

| Screen | Role | Route | Actions → backend | Perm | Tables | → |
|---|---|---|---|---|---|---|
| 11.1 Platform dashboard | A | `GET /admin` | tenant counts, MRR, needs attention, health, support | platform admin | `clinics`, `subscriptions`, `plans`, `payments` | 11.2, 11.11 |
| 11.2 Clinic management | A | `GET /admin/clinics` | list, search, add, suspend, reinstate | `clinic.suspend` | `clinics`, `subscriptions` | 11.3 |
| 11.3 Clinic details | A | `GET /admin/clinics/{id}` | read-only profile, subscription, usage, staff, audit | platform admin | `clinics`, `clinic_users`, `audit_logs` | 11.2 |
| 11.4 Organisation management | A | `GET/POST /admin/organisations` | create org, attach/detach clinics | platform admin | `organisations`, `clinics` | 11.2 |
| 11.5 Subscription plans | A | `GET/POST /admin/plans` | create/edit plans — price, limits, features as data | `plan.manage` | `plans` | 11.6 |
| 11.6 Feature management | A | `GET/POST /admin/features` | per-clinic entitlements; override beats plan | `feature.entitle` | `feature_entitlements`, `plans` | 11.5 |
| 11.7 Payments | A | `GET/POST /admin/payments` | verify / reject submitted bank transfers | `payment.verify` | `payments`, `invoices` | 11.8 |
| 11.8 Invoices | A | `GET /admin/invoices` | all invoices across clinics | `plan.manage` | `invoices` | 11.7 |
| 11.9 Support tickets | A | `GET/POST /admin/support` | reply, internal notes, status | `support.manage` | `support_tickets`, `support_replies` | — |
| 11.10 Platform activity | A | `GET /admin/activity` | platform-wide audit trail | platform admin | `audit_logs` | 11.3 |
| 11.11 System health | A | `GET /admin/health` | real checks: DB, worker, backups, schema parity, stale queues | platform admin | `system_alerts`, `schema_migrations` | 11.12 |
| 11.12 Incident management | A, O | `GET/POST /incidents` | open, acknowledge, append-only timeline, mitigate, resolve | `incident.manage` | `incidents`, `incident_entries` | 11.11 |

---

## 12. System screens & states

| Screen | Role | Route | Actions → backend | Perm | Tables | → |
|---|---|---|---|---|---|---|
| 12.1 Audit logs | O, A | `GET /audit` | filtered, newest-first audit trail | `audit.view` | `audit_logs`, `users` | — |
| 12.2 Data export | O | `GET/POST /settings/export` | build archive, preview, download; audited | `data.export` | all clinic tables | 8.7 |
| 12.3 Support centre | R, O, D | `GET/POST /support` | open ticket, thread with replies | authenticated | `support_tickets`, `support_replies` | — |
| 12.4 Offline status & desk | R, O | `GET/POST /settings/offline` | reserve block, issue offline, reconcile with conflict list | `queue.configure` | `offline_blocks`, `offline_reservations`, `queue_entries` | 3.4a |
| 12.5 Error states | all | — | render inline, keep input, offer retry | — | — | — |
| 12.6 Empty states | all | — | icon, title, explanation, **the action that fills it** | — | — | — |
| 12.7 Loading states | all | — | skeleton matching real layout; stale not blank | — | — | — |
| 12.8 Confirmation dialogs | all | — | confirm destructive; type clinic name for purge; Escape cancels | per action | — | — |
| 12.9 Permission-denied | all | — | explain who *can*, never a bare 403 | — | — | — |
| 12.10 Responsive layouts | all | — | phone / tablet / desktop per screen | — | — | — |

---

## Public displays & kiosk

| Screen | Role | Route | Actions → backend | Perm | Tables | → |
|---|---|---|---|---|---|---|
| D1 Waiting-room TV | public | `GET /display/{key}` | board by display key, 5s refresh, connection banner | display key | `queues`, `queue_entries`, `doctors`, `rooms`, `public_display_keys` | D2, D4 |
| D2 Department display | public | `GET /display/{key}?department=` | same, filtered by department | display key | + `departments`, `department_assignments` | D1 |
| D3 Public queue board | public | `GET /board/{key}`, `/live/{key}` | read-only board; `/position` returns **only** a number | display key | `queues`, `queue_entries` | — |
| D4 Kiosk check-in | public | `GET/POST /kiosk/{key}` | short form → issues a **real** token + printable slip | display key | `patients`, `queue_entries`, `queues` | 5.6 |

---

## Cross-cutting backend capabilities

Every screen above depends on these. They are built once.

| Capability | Used by | Notes |
|---|---|---|
| `TenantContext` | every clinic screen | Resolved from session, never from a request parameter |
| `Policy::can()` | every action | Permission before precondition |
| `QueueService::transition()` | 3.7–3.9, 4.4–4.7 | Permission → legality → version, one transaction |
| `TokenService::allocate()` | 3.4a, D4 | `FOR UPDATE` + unique constraint |
| `NotificationService::queue()` | 3.4a, 3.11, 4.7, 5.3 | Outbox insert **after** commit |
| `FeatureService::enabled()` | gated screens | Service-layer enforcement |
| `AuditService::record()` | every mutation | Append-only |
| `e()` output escaping | every template | No trusted variables |
| CSRF validation | every POST | Middleware |
| Rate limiting | 1.1, 5.3, D4, `/position` | Public surfaces especially |
