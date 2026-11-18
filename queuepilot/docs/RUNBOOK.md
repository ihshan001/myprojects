# Runbook

The things you actually have to do, in the order you have to do them, written for
someone who has not touched this codebase in three months. `OPERATIONS.md` covers
routine jobs and `BACKUPS.md` covers restore drills; this file covers the
operational duties that have a person on the other end of them.

---

## 1. Health monitoring

The app exposes one endpoint that answers a machine, `GET /api/health`. It is
closed by default: with no `MONITOR_TOKEN` set it returns **404 to everyone**
except a signed-in super admin. That is deliberate — an open health page that
lists database driver, backup filenames and queue counts is a reconnaissance
gift, and "it's only health data" is how those get shipped.

### Set the token

```bash
# on the server, in the app's .env
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # generate
# add to .env:
MONITOR_TOKEN=<that value>
```

Then verify from outside the network:

```bash
curl -sS -H "Authorization: Bearer $MONITOR_TOKEN" https://queue.lk/api/health
```

Expected: `{"ok":true,"status":"ok"|"warning"|"critical", ...}`.

| HTTP | `status`   | Meaning                                                        |
| ---- | ---------- | -------------------------------------------------------------- |
| 200  | `ok`       | Everything fine.                                               |
| 200  | `warning`  | Working, but something needs a human soon (stale backup, etc.). |
| 503  | `critical` | Something is broken now — database unreachable, schema drift.   |

The distinction matters: **only `critical` returns a non-2xx code.** A monitor
that alarms on any non-200 will therefore only wake you for real outages, and
warnings arrive by email instead. If you would rather be woken for warnings too,
have your monitor parse the `status` field.

### What it checks

| Key                   | Fails when                                            |
| --------------------- | ----------------------------------------------------- |
| `database`            | Cannot read; or takes more than a second.             |
| `notification_worker` | Outbox has entries older than the drain window.       |
| `backups`             | Newest backup is older than `BACKUP_MAX_AGE_HOURS` (48). |
| `schema_parity`       | Migrations recorded but columns missing, or vice versa. |
| `disk`                | Less than the configured free space.                  |

### Pointing a monitor at it

Any of these work; pick one in five minutes, do not build your own.

**UptimeRobot (free tier).** Add monitor → *HTTP(s)* → URL above. Paste the
header under *Advanced → Custom HTTP headers* as:

```
Authorization: Bearer <token>
```

Keyword monitoring: set *Monitor type* to `Keyword`, keyword `"status":"critical"`,
alert when it **exists**. Interval 5 minutes is the free-tier limit, which is
fine — a queue system that is down for five minutes costs a clinic one morning's
tokens, not a legal incident.

**Healthchecks.io.** Useful *in addition* because it catches the opposite
failure: cron jobs that stopped running. Have the daily dunning job ping a check
URL when it finishes; if the ping does not arrive, you get the alarm. Database
down and cron silently dead look identical from the outside — this is how you
tell them apart.

**A shell loop**, if you refuse to use a service:

```bash
# crontab: */5 * * * *
curl -fsS -H "Authorization: Bearer $MONITOR_TOKEN" https://queue.lk/api/health \
  || echo "queuepilot health critical" | mail -s "ALERT" you@example.com
```

`-f` makes curl exit non-zero on 503, which is the whole point.

### What to do when it goes critical

1. `php tools/healthcheck.php` on the server — same checks, human output.
2. `php tools/migrate.php` if it says schema drift. It is idempotent.
3. Check disk: a full `/var` breaks SQLite writes and session files silently.
4. If the database is unreachable, follow `BACKUPS.md`. Do not restart MySQL
   before taking a copy of whatever is on disk.

---

## 2. Email

Mail leaves through a socket SMTP client — no Composer, no library — so it works
on shared hosting where you cannot install anything.

### The Google free tier

Gmail's free *"less secure apps"* door is gone. What works today:

1. Turn on 2-Step Verification on the Google account.
2. Google Account → Security → **App passwords** → create one for "Mail".
3. You get a 16-character password. Put it in `.env`:

```ini
MAIL_TRANSPORT=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=you@gmail.com
MAIL_PASSWORD=abcd efgh ijkl mnop     # the app password, spaces are fine
MAIL_FROM=you@gmail.com
MAIL_FROM_NAME=QueuePilot
```

Port 587 with STARTTLS is what the client does. Do not use 465 — that is implicit
TLS and this client speaks STARTTLS.

### The limits, which are the reason this is not your long-term answer

- **~500 recipients/day** on a free Gmail account (2,000 on Workspace).
- **~100 recipients per message**, so a broadcast to all clinics must be looped
  in batches.
- The `From:` must be the Gmail address itself. Sending as `noreply@queue.lk`
  through Gmail fails SPF/DMARC and lands in spam, which for a password link is
  the same as not sending it.
- Google can rate-limit or suspend an account that behaves like a bulk sender.
  One bug in a notification loop and the account is gone.

**Use Gmail to get started and to prove the flows. Move to a real transactional
provider (Brevo 300/day free, Mailgun, Postmark, Amazon SES) before you have
paying clinics.** They all speak the same SMTP, so it is a `.env` change and
nothing else. See `docs/OPERATIONS.md` for the switch.

### Verifying mail without spamming anyone

```bash
php tools/mail-test.php you@example.com   # sends one message, prints the SMTP conversation
```

`MAIL_TRANSPORT` has three values, and the difference matters:

- `none` (the default when unset) — nothing is written anywhere. The approval
  flow says *"Mail is not configured, so no welcome email was sent — copy the
  link from the clinic page"* rather than pretending to succeed.
- `log` — the whole message goes to `storage/logs/`, so you can read exactly
  what a clinic would have received without sending it. Use this to check the
  welcome link looks right before wiring SMTP.
- `smtp` — delivered.

### Shared hosting gotcha

cPanel and CyberPanel both allow outbound SMTP on 587 but some hosts block it by
default to stop spam. If `mail-test.php` times out rather than being refused,
ask the host to unblock outbound 587, or use port 2525 (Brevo and Mailgun both
listen there).

---

## 2b. Checking a new host before it goes live

Two drills, both safe to run on production:

```bash
php tools/host-drill.php      # 24 checks, ~2 seconds, writes nothing
php tools/loadtest.php --count 500 --clinic 1
php tools/loadtest.php --clinic 1 --cleanup
```

**`host-drill.php`** is the one to run first on a new host, and again after any
server change. It checks the six things that break silently on shared hosting:

| Check | What it catches |
| --- | --- |
| `APP_KEY` present, long, not the repository fallback | 2FA secrets encrypted with a publicly-known key |
| Session directory writable, not world-readable, outside `public/` | Every request arriving signed out; downloadable sessions |
| Database answers, quickly | The obvious outage, and a subtly slow one |
| Every column the code reads exists | Migrations not run — the app looks fine and throws on first use |
| Storage writable (actual write, not `is_writable`) | Disk full or quota exceeded while `is_writable` still says yes |
| Debug off, 2FA required, monitor token set | Stack traces to the public; a platform with no second factor |

It writes nothing and changes nothing. A failure prints the fix next to it.

**`tools/dialect-check.php`** compares every migration pair for
copy-paste drift between SQLite and MySQL. This one earns its place: it found
`0015_session_start.mysql.sql` calling SQLite's `datetime('now')`, which MySQL
does not have. Nothing else in the repository would have caught it — the test
suite only ever executes the SQLite half — and the first execution against MySQL
is on a clinic's host.

```bash
php tools/dialect-check.php
```

It checks spelling, not behaviour. It cannot tell you a migration will *run*;
only a MySQL host does that.

**`loadtest.php`** measures the token allocator on *this* host with *this*
database. On SQLite it manages roughly 260–680 tokens/second with zero
duplicates; InnoDB on a shared host will be different, which is the reason to
run it rather than trust the number. It creates a throwaway queue and deletes
it with `--cleanup`. Never run it against a live queue id.

## 2c. The path a clinic takes from stranger to paying customer

This is the whole lifecycle, written down because it is the one flow where a
mistake is invisible: every step succeeds, and the customer still ends up
without what they paid for.

```
1  VISITOR      /  →  pricing  →  clicks a plan
                 the CTA carries ?plan=<code>; register preselects it.
                 A bogus ?plan= falls back to professional, never to a
                 plan code with no price row.

2  APPLY        POST /register
                 clinic created with billing_status = 'pending'.
                 No trial. A trial is a platform decision, not a signup
                 bonus, and the copy says so.
                 → the platform is alerted (email + bell). An application
                   nobody is told about is an application nobody reviews.

3  REVIEW       Platform admin opens /admin
                 "N things waiting on you" at the top, and the decision
                 queue at /admin/clinics. Approve, grant trial, or decline
                 with a reason the clinic can read.

4a TRIAL        Grant trial (14 days by default, 1–90)
                 No invoice — a trial that invoices is not a trial.
                 Both addresses are provisioned, welcome email sent.

4b PAID         Approve (paid)
                 billing_status = 'active', renews_on = one cycle out.
                 The FIRST invoice is raised when that period closes.

5  WELCOME      One email, three facts the owner does not have:
                 · the board URL a patient opens   /board/<key>
                 · the clinic's public page        (when it differs)
                 · the staff sign-in URL           (ops host)
                 plus a single-use link to set a password. No password is
                 ever emailed — a credential in an inbox is a credential
                 someone else can read.

6  RUN          Owner sets hours, doctors, and issues a token.
                 NOT blocked on payment during a trial; blocked on the
                 owner confirming their address, which is deliberate.

7  INVOICE      Arrears. When renews_on passes, one invoice is raised for
                 the period that just ended — clamped to the day the clinic
                 went live, so a clinic approved on the 19th is never
                 billed for the 19th of the previous month.
                 → emailed with the amount, the period, the due date, the
                   stop date, and the bank details.

8  PAY          Bank transfer. The owner records the reference at /billing.
                 → the platform is alerted (email + bell).

9  VERIFY       Admin matches it against the bank and verifies.
                 Invoice → paid, subscription extended, receipt emailed.

10 LAPSE        If unpaid: 5 days after the due date the service stops.
                 Law: the due date, not when the cron ran. The warning email
                 is sent when grace starts and names the date. Nothing is
                 deleted, ever, on a timer.
```

### What to check if a step does not happen

| Symptom | Cause |
| --- | --- |
| No alert email on signup | `MAIL_TRANSPORT=none` (default), or `COMPANY_ALERTS_EMAIL` and the super-admin address are both empty. The bell row always appears — check /admin |
| Welcome email says the same URL twice | The clinic has no `site_host` yet, which is correct until `APP_DOMAIN` is set. The board URL is still correct and is printed first |
| No invoice after activating a paid plan | Correct. Activation sets `renews_on` one cycle out; the invoice is arrears and appears when that date passes. Run `php tools/cron.php` if you expected it today |
| A clinic on a trial gets an invoice | It should not — `generateDue()` skips `billing_status = 'trial'` explicitly |
| Invoice covers time before signup | Should be impossible now; the period start is clamped to `approved_at`/`created_at`. If you see it, the clamp was removed |
| Payment claimed but no alert | Same mail cause as above; the bell row is in /admin |

## 3. A clinic that has stopped using the system

This is the flow the owner asked for explicitly, and it is **not** a timer.

Nothing is deleted automatically. Ever. `tools/purge.php` defaults to a dry run.

### When a clinic has been closed for 90 days

`php tools/purge.php` lists it under **awaiting contact**, with the owner's email
and phone number. That is all it does — it does not authorise anything.

1. **Email them.** Ask what happened. A clinic that closed its doors for
   renovation, or changed hands, or is simply between receptionists, is not a
   clinic to delete. Most of these resolve here.
2. **If no answer in a week, phone them.** The number is in the output. People
   answer a phone when they ignore email, and a two-minute call tells you
   whether this is a dead clinic or a disorganised one.
3. **Wait for an answer.** If they say they are coming back, note it; the list
   will keep showing them, which is correct.
4. **Only if they confirm they are done**, and only after you have told them what
   deletion means (queues, tokens, patients, invoices — gone), record the
   decision:

```php
// tools/approve-purge.php
php tools/approve-purge.php --clinic=42 --note="Spoke to Dr Perera 2026-11-06; clinic closed permanently, data no longer needed" --yes
```

The note must be at least 10 characters and it is stored with your name. This is
the audit trail for a decision that destroys a patient's medical queue history.

5. **Then** `php tools/purge.php --yes` does the deletion.

### Changing your mind

```php
php tools/approve-purge.php --clinic=42 --revoke --note="Owner called back: reopening in January"
```

Revocation works right up until the purge runs. After that the data is gone, and
the only copy is the backup — see `BACKUPS.md`, and note that restoring a backup
to recover one clinic's data rolls back every other clinic too. This is why step
4 exists.

### Why it is built this way

The previous design deleted a clinic automatically once its retention window
passed. The owner reversed that decision: **a human decides.** The service layer
enforces it — `purgeable()` reports who is *due*, `approvedForPurge()` reports who
is *authorised*, and they are separate queries. A scheduled job cannot delete
anything by accident, because it has no way to become authorised on its own.

---

## 4. Two-factor authentication

Mandatory for super admins, optional for everyone else. Enforced in
`public/index.php` before routing, so it covers every current and future admin
screen.

A super admin who has not enrolled is redirected to `/settings/security` and can
go nowhere else except sign-out. There is no grace period on purpose: the account
that can read every clinic's patient records is the one worth stealing.

### Break-glass

`SECURITY_REQUIRE_2FA_SUPER=false` in `.env` disables enforcement. Use it only
to recover from a lost authenticator, then set it back. The health check reports
when it is off, so an install left in that state does not go unnoticed.

Recovery for a locked-out admin, in order of preference:

1. Another super admin resets their 2FA from `/admin/staff`.
2. An operator clears `users.totp_secret` and `users.totp_enabled_at` in the
   database, then the admin re-enrols on next login.
3. Break-glass above, re-enrol, unset.

---

## 5. Monthly checklist

- [ ] Health endpoint responding, and the monitor has not been silently broken.
- [ ] Backups exist and are younger than 48 hours; restore drill done this quarter.
- [ ] `php tools/purge.php` reviewed — anyone due for contact? Anyone waiting on a decision?
- [ ] `php tools/dunning.php` has run every day (grep the log).
- [ ] Invoices match payments received; unreconciled transfers chased.
- [ ] Trial clinics converted or closed out.
- [ ] Error log reviewed for anything repeating.
