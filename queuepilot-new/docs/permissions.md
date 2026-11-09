# QueuePilot — Permissions model

**Status:** Design stage · the single source of truth for who may do what.

---

## 0. Where permission checks live

There is exactly **one** place a capability is answered:

```php
Policy::can($user, 'queue.call', $queue);   // true | false
```

Three layers, all of which must pass:

| Layer | Question | Failure mode |
|---|---|---|
| **Route guard** | Is this role allowed at this URL at all? | 403 / redirect |
| **Policy** | May *this user* do *this action* to *this resource*? | 403 page explaining who can |
| **Service precondition** | Is the action legal for this object *right now*? | Domain exception → friendly message |

**Hiding a button is presentation, never enforcement.** Every controller calls
the policy again even though the view already hid the control. This is not
paranoia: the button and the route are written at different times by different
parts of the codebase, and only one of them is trusted.

**Tenant scoping is a fourth, orthogonal check.** It is not a permission — it is
an invariant. Every query that touches clinic data is scoped through
`TenantContext`, and resource IDs from the browser are always loaded *through*
that scope before any policy runs. A receptionist at Clinic A asking for
`/patients/912` where 912 belongs to Clinic B gets a 404, not a 403 — a 403
would confirm the record exists.

---

## 1. Roles

Five roles. A role is a **named bundle of capabilities**, and it lives on
`clinic_users.role` — not on `users`. One human may be a receptionist at one
clinic and an owner at another; that is normal and must work.

| Role | Where it applies |
|---|---|
| `owner` | Clinic owner / manager |
| `receptionist` | Front desk |
| `doctor` | Clinician |
| `manager` | Optional delegate with a subset of owner capabilities |
| `platform_admin` | `users.is_platform_admin` — **separate system, separate console** |

`platform_admin` is deliberately *not* a clinic role. A platform administrator
has no `clinic_users` row for a clinic they are inspecting; they have an
explicitly audited, time-boxed administrative route into it.

---

## 2. The capability matrix

Legend: **✓** allowed · **—** denied · **own** allowed for own resources only ·
**(o)** allowed only via explicit override.

### 2.1 Patients

| Capability | Owner | Receptionist | Doctor | Platform |
|---|---|---|---|---|
| `patient.view` | ✓ | ✓ | own | — |
| `patient.create` | ✓ | ✓ | — | — |
| `patient.update` | ✓ | ✓ | — | — |
| `patient.export` | ✓ | — | — | — |
| `patient.delete` | ✓ | — | — | — |
| `patient.view_history` | ✓ | ✓ | own | — |

### 2.2 Queue & tokens

| Capability | Owner | Receptionist | Doctor | Platform |
|---|---|---|---|---|
| `token.issue` | ✓ | ✓ | **—** | — |
| `queue.view` | ✓ | ✓ | own | — |
| `queue.call` | ✓ | ✓ (o) | **own** | — |
| `queue.recall` | ✓ | ✓ (o) | own | — |
| `queue.start_consultation` | ✓ | ✓ (o) | own | — |
| `queue.complete` | ✓ | ✓ (o) | own | — |
| `queue.complete_and_call_next` | ✓ | — | **own** | — |
| `queue.skip` | ✓ | ✓ | own | — |
| `queue.mark_no_show` | ✓ | ✓ | own | — |
| `queue.cancel` | ✓ | ✓ | own | — |
| `queue.requeue` | ✓ | ✓ | own | — |
| `queue.hold` / `resume` | ✓ | ✓ (o) | own | — |
| `queue.transfer` | ✓ | ✓ (o) | own (o) | — |
| `queue.set_priority` | ✓ | **—** | ✓ | — |

**The two deliberate asymmetries**, both from the brief:

- **Reception cannot set clinical priority.** It is a clinician's judgement and
  it is always recorded with a reason and an actor. Granting it to reception is
  possible but must be an explicit, deliberate, auditable override — never a
  default.
- **A doctor cannot issue tokens.** The doctor's board hides the button *and*
  `QueueService::issueToken()` refuses them. But a doctor **can** cancel their
  own day's session — that is clinical authority over their own list, and
  denying it would force them to ask reception to lie for them.

### 2.3 Sessions

| Capability | Owner | Receptionist | Doctor | Platform |
|---|---|---|---|---|
| `session.start` | ✓ | ✓ (o) | own | — |
| `session.pause` / `resume` | ✓ | ✓ (o) | own | — |
| `session.close` | ✓ | ✓ (o) | own | — |
| `session.cancel_day` | ✓ | (o) | **own** | — |

### 2.4 Appointments

| Capability | Owner | Receptionist | Doctor | Platform |
|---|---|---|---|---|
| `appointment.view` | ✓ | ✓ | own | — |
| `appointment.create` | ✓ | ✓ | — | — |
| `appointment.update` | ✓ | ✓ | own | — |
| `appointment.check_in` | ✓ | ✓ | — | — |
| `appointment.cancel` | ✓ | ✓ | own | — |
| `appointment.reschedule` | ✓ | ✓ | own | — |

### 2.5 Clinic configuration

| Capability | Owner | Receptionist | Doctor | Platform |
|---|---|---|---|---|
| `clinic.update` | ✓ | — | — | — |
| `doctor.manage` | ✓ | — | — | — |
| `room.manage` | ✓ | — | — | — |
| `department.manage` | ✓ | — | — | — |
| `schedule.manage` | ✓ | — | own (o) | — |
| `holiday.manage` | ✓ | — | — | — |
| `queue.configure` | ✓ | — | — | — |
| `appointment.policy.manage` | ✓ | — | — | — |

### 2.6 Team, reports, data

| Capability | Owner | Receptionist | Doctor | Platform |
|---|---|---|---|---|
| `staff.invite` / `staff.manage` | ✓ | — | — | — |
| `permissions.manage` | ✓ | — | — | — |
| `report.view.full` | ✓ | — | own | ✓ |
| `report.view.basic` | ✓ | ✓ | own | ✓ |
| `audit.view` | ✓ | — | — | ✓ |
| `data.export` | ✓ | — | — | — |
| `data.purge` | ✓ | — | — | — |
| `notification.configure` | ✓ | — | — | — |
| `integration.configure` | ✓ | — | — | — |

### 2.7 Billing & platform

| Capability | Owner | Receptionist | Doctor | Platform |
|---|---|---|---|---|
| `subscription.manage` | ✓ | — | — | ✓ |
| `invoice.view` | ✓ | — | — | ✓ |
| `payment.submit` | ✓ | — | — | — |
| `payment.verify` | — | — | — | ✓ |
| `clinic.suspend` / `reinstate` | — | — | — | ✓ |
| `plan.manage` | — | — | — | ✓ |
| `feature.entitle` | — | — | — | ✓ |
| `support.manage` | — | — | — | ✓ |
| `incident.manage` | ✓ | — | — | ✓ |

---

## 3. Cross-cutting rules

### 3.1 Resource-level, not just role-level

`queue.call` is not enough on its own. The check is:

```
can(user,'queue.call')  AND  queue.doctor_id is the user's own doctor  OR  user is owner/reception
```

A doctor may only act on **their own** queue. Row-level ownership is checked
inside the policy, not left to the query.

### 3.2 State preconditions are not permissions

"Calling is blocked until the session starts" is **not** a permission — it is a
domain rule, and it is enforced in `QueueService`. The distinction matters: a
permission denied is a 403; a precondition failed is a friendly board-level
message ("Start the session before calling patients"). Mixing them produces
either an unhelpful error or, worse, a permission that quietly becomes
configurable.

### 3.3 Priority always needs a reason and an actor

Any path that raises priority writes `priority_reason` and `priority_set_by`,
and appends an audit row. There is no code path that sets priority silently.

### 3.4 Overrides are explicit and visible

`clinic_users.permission_overrides` is JSON. The rule:

- An override may **grant** a capability the role lacks.
- An override may **not** grant `payment.verify`, `clinic.suspend`,
  `plan.manage`, `feature.entitle` or `support.manage` — those are platform-only
  and structurally unreachable from clinic code.
- Every grant is written to the audit log with the granter.
- The permissions screen shows which capabilities are currently overridden, so
  an inherited surprise is visible.

### 3.5 Public and patient access is capability-free by design

Tracking links (`/t/{token}`) and booking links (`/a/{token}`) carry **their own
authority**. There is no user, no session, and no policy — the token *is* the
permission. Therefore:

- Tokens are long, random, and single-purpose.
- They expire (18h default for tracking).
- They reveal **only** the one visit.
- They are never guessable from a token number or patient code.

Never "log the patient in" to show them their queue position. That converts a
narrow, expiring, purpose-limited capability into a full account.

### 3.6 Platform admin access to clinic data

Administrators do not get a silent back door. Accessing a clinic's data:

1. Requires `is_platform_admin`.
2. Renders a read-only view — administrative viewing never mutates clinic data.
3. Writes an audit row naming the administrator, the clinic and the reason
   prompt shown to them.

---

## 4. Implementation shape

```
app/Policies/
  QueuePolicy.php       token.issue, queue.call/complete/skip/transfer/priority…
  PatientPolicy.php     patient.view/create/update/export
  AppointmentPolicy.php appointment.*
  ClinicPolicy.php      clinic.update, doctor.manage, staff.invite…
  ReportPolicy.php      report.view.*
  PlatformPolicy.php    clinic.suspend, plan.manage, payment.verify…
```

Each policy is a small class with one method per capability, returning `bool`
and taking the resource. Policies are **pure** — no I/O beyond the models
already loaded, so they can be unit-tested exhaustively.

The service layer calls policies itself rather than trusting the controller:

```php
// QueueService::complete()
if (!Policy::can($actor, 'queue.complete', $entry)) {
    throw new Forbidden('queue.complete');
}
if ($entry->status !== 'in_consultation') {
    throw new DomainException('Only a patient with the doctor can be completed.');
}
```

Order matters: **permission first, then precondition.** Otherwise a user without
permission learns something about the state of a record they cannot see.

---

## 5. Test requirements

Permissions are only real if they are tested. Required suites:

| Suite | What it proves |
|---|---|
| `tests/Security/TenantIsolationTest` | Every clinic-scoped route rejects a cross-tenant ID. Parameterised over **all** clinic-owned entities. |
| `tests/Security/RoleMatrixTest` | Every capability × every role, asserted against the matrix above. A table-driven test — the matrix is the fixture. |
| `tests/Security/ResourceOwnershipTest` | A doctor cannot act on another doctor's queue even with a valid capability. |
| `tests/Security/PublicTokenTest` | Tracking tokens are unguessable, expire, and leak no other patient's data. |
| `tests/Unit/Policies/*` | Each policy in isolation. |
| `tests/Workflows/PriorityAuditTest` | No code path sets priority without a reason and an actor. |

**Mutation discipline:** deleting any single policy check must fail at least one
test. If a check can be removed without a test failing, the check is not
covered and does not count.
