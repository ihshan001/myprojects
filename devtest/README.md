# devtest — QueuePilot without sign-in

**A separate system for development and role testing. Never deploy this.**

This is QueuePilot rebuilt with **no sign-in, no authentication and no landing
page**. Every role runs on its own port, and the port decides who you are.

The original system in `../queuepilot` is untouched — it still has full
authentication and its landing page.

---

## Run it

```bash
cd devtest
npm install          # or: ln -s ../queuepilot/node_modules node_modules
npm run migrate && npm run seed
npm run fleet        # all five role ports at once
```

Open any port at **`/`** — it lands directly on that role's workspace. There is
no login screen to get past.

| Port | Role | Identity | Lands on | Clinic |
|------|------|----------|----------|--------|
| **8080** | Clinic Owner | `admin@serenity.lk` | `/app` | Serenity OPD & Family Clinic |
| **8081** | Receptionist | `reception@serenity.lk` | `/app` | Serenity OPD & Family Clinic |
| **8082** | Doctor | `amara@serenity.lk` | `/app` | Serenity OPD & Family Clinic |
| **8083** | Platform Admin | `owner@queuepilot.app` | `/admin` | — platform wide — |
| **8084** | Clinic Owner (2nd) | `admin@lakeview.lk` | `/app` | Lakeview Medical Centre |

8084 is the same role as 8080 in a **different tenant**, so you can prove
isolation: it must never show Serenity's patients.

One port at a time, or a subset:

```bash
npm run p8082            # just the doctor
npm run fleet 8081 8082  # receptionist + doctor
```

Every page carries an amber strip naming the port, role, identity and clinic,
plus one line describing what that role can see.

---

## What was removed

| Removed | What it was |
|---------|-------------|
| Landing page | `pages/landing.php`, `/`, `/features`, `/pricing` — `/` now redirects to the role's workspace |
| Sign-in | `AuthController`, `/login`, login/register views |
| Registration | `PublicController::register*`, `/register` |
| Two-factor | `TotpService`, `/login/2fa`, `/settings/security` |
| Password reset | `PasswordResetService`, `PasswordResetController`, `/forgot-password`, `/reset-password/{token}` |
| Email verification | `EmailVerificationService`, `EmailVerificationController`, `/verify-email/*`, the onboarding step and the warning banner |
| Staff invitations | `StaffInviteService`, `/invite/{token}`, `/settings/invites` |
| Sign out | `/logout` and both sign-out buttons |

All of these return **404** on every port.

## What was deliberately kept

**The entire authorisation model.** `Auth::matrix()` still defines what each
role may do, and it is still enforced in the domain layer and the router — not
by hiding buttons. Only the question *"who is asking?"* changed: it is answered
by the port instead of a session.

Verified behaviour:

| Check | Result |
|-------|--------|
| Receptionist opens `/queue/new` | 200 — front desk issues tokens |
| Doctor opens `/queue/new` | **403** — doctors do not issue |
| Doctor opens `/patients` | **403** — no access to patient records |
| Receptionist opens `/audit` | **403** — audit is owner-only |
| Owner opens `/audit` | 200 |
| Owner opens `/admin` | **403** — not a platform admin |
| Platform admin opens `/admin/clinics` | 200 |

Also unchanged: the queue state machine, optimistic concurrency, tenant
scoping on every query, priority rules, workflows, notifications, reports,
billing, displays, kiosk, offline writes — the whole operational system.

---

## How identity works

```
tools/serve-role.mjs --port 8081
        └─ putenv("QP_PORT=8081")
              └─ app/Core/DevIdentity::user()
                    └─ dev/roles.json: 8081 → reception@serenity.lk
                          └─ SELECT * FROM users WHERE email = ?
                                └─ Auth::user() returns that row
```

`Auth::user()` is the only method that changed. `clinicId()`, `can()`,
`isSuper()` and the role matrix are untouched, so every downstream permission
check behaves exactly as in the real system.

Identity is resolved **by email, not by user id** — ids differ between seeds,
and a stale id would silently test the wrong role.

Sessions still exist, but only for locale, flash messages and CSRF. Each port
gets its **own cookie name and session directory** (`qp_devtest_<port>`),
because cookies are scoped by host and not by port; sharing one would make the
five ports overwrite each other's state.

To change who a port is, edit `dev/roles.json` and restart that port.

---

## One database, five roles

All ports share `storage/db/queuepilot.sqlite` (WAL + 5s busy timeout), so work
flows across roles exactly as it would in a clinic:

1. Issue a token as the **receptionist on 8081**
2. It appears on the **owner's board on 8080**
3. and on the **doctor's board on 8082** — if it is that doctor's queue
4. but **never on 8084**, which is a different clinic

That was verified end to end. Note that a doctor only sees their own queue:
Dr. Amara (8082) owns queue 2, so a token issued into queue 1 correctly does not
appear on her board.

This database is separate from `../queuepilot`, so `npm run fresh` here cannot
touch the main system.

---

## Commands

```bash
npm run fleet        # all role ports
npm run p8080        # a single port (p8080 … p8084)
npm run lint         # PHP parse check
npm test             # 221 checks
npm run health       # schema, plans, CSS, schema parity
npm run css          # rebuild Tailwind after editing views
npm run fresh        # wipe + re-migrate + re-seed this database
npm run worker       # drain the notification outbox once
```

**Run `npm run css` after editing views.** Tailwind scans the PHP views for
class names, so a new utility class does not exist until you rebuild.

### About the test count

The suite is **221 checks**, down from 246. The 25 removed tests covered only
deleted features: TOTP, recovery codes, staff invitations, password reset and
email verification. Every queue, tenant-isolation, authorisation, priority,
notification, reporting and billing test is retained and passing.

Two onboarding tests previously relied on a side effect of the email-verification
test (it issued clinic 85's first token). They now create their own data, so
they no longer depend on test order.

---

## Files that only exist here

```
dev/roles.json              port → role → identity map
app/Core/DevIdentity.php    resolves identity from QP_PORT
tools/serve-role.mjs        one port, one role
tools/fleet.mjs             all ports at once
README.md                   this file
```

`public/index.php` requires `DevIdentity` and fails with a clear message if the
database is unseeded. `tests/run.php` uses `DevIdentity::actingAs()` to exercise
several roles in one process, which a single port cannot express.

> **This build has no authentication at all.** Anyone who can reach a port is
> signed in as that port's role. Keep it on a trusted machine, and never put it
> on a public host.
