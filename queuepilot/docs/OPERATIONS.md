# Running QueuePilot

This is the on-call document. It assumes you are half-awake at 02:00 and need
to know whether the system is working and what to do if it is not.

## The two checks you will actually run

```bash
php tools/healthcheck.php          # deploy sanity: schema, plans, CSS, schema parity
curl -H "Authorization: Bearer $MONITOR_TOKEN" https://queues.example.lk/api/health
node tools/security-check.mjs      # before every release that changes dependencies
```

`tools/security-check.mjs` is the standing posture check: dependency
advisories from `npm audit`, PHP settings that turn a bug into a breach,
whether `.env` is in the repository, every route reachable without signing in,
and whether a finished notification still holds a patient's phone number. It
prints warnings for things this demo environment cannot change (php-wasm cannot
toggle `expose_php`) and failures for things that are actually wrong. `npm run
security` runs it; `npm run security:strict` exits non-zero for CI.

It is **not** a penetration test. It catches the mistakes that are cheap to
catch mechanically, and it found a real leak the first time it ran — 18
finished notifications still carried a deliverable phone number in their
payload.

`healthcheck.php` is a pre-flight for a deploy: it proves the files are there
and the two schema dialects still agree. `api/health` is the live probe: it
answers questions about *this* database right now.

`/api/health` returns `200` for `ok` and `warning`, and `503` for `critical`,
so a normal uptime monitor can watch it without parsing JSON. The body lists
every check with a one-line sentence, which is what you paste into `/incidents`.

## Where an incident goes

**`/incidents`** (clinic staff, or the platform admin for anything platform-wide).
Open it while it is happening, not afterwards:

1. **Open** — title, severity, and the time it was actually detected. Attach an
   open `system_alerts` row if the platform raised one.
2. **Acknowledge** ("I am on it") — records who took it. Time-to-acknowledge is
   measured from *detection*, so a late acknowledgement cannot flatter the
   response time.
3. **Add entries** as you go — actions, causes, decisions, references. The
   timeline is append-only; a correction is a new entry that says it corrects
   one.
4. **Mitigate** when the bleeding stops, **resolve** when it is fixed. Record the
   cause while it is fresh — that is the entry that stops the next one.

Nothing pages anybody. That is a provider contract (PagerDuty, Opsgenie, an
on-call phone) and this codebase cannot supply it. This document says who to
call; `/incidents` says what happened.

Example of a real failure it reports:

```json
{"key":"notification_worker","severity":"critical",
 "summary":"7 message(s) waiting; the oldest is 40 minutes overdue — check the worker"}
```

That is the failure a plain HTTP 200 would hide: the site is up, patients are
being added to queues, and nobody is being told their turn.

### What each check means

| check | fails when | first thing to do |
| --- | --- | --- |
| `database` | cannot read, or >500 ms | check the DB host; look at disk first |
| `notification_worker` | a due message is >10 min old (warn) / >30 min (critical) | `php tools/queue-worker.php`; check cron |
| `backups` | no record, or older than `BACKUP_MAX_AGE_HOURS` (48 h) | run `tools/backup.php`, read the failure |
| `schema_parity` | `sqlite.sql` and `mysql.sql` disagree | a migration is missing one dialect |
| `stale_queues` | a session is still `open` from an earlier date | close the day; see below |
| `long_waits` | a patient has waited over 45 minutes | walk to the desk and look |
| `disk` | >85 % (warn) / >95 % (critical) | backups and logs are the usual cause |

Alerts are written to `system_alerts` and cleared automatically when the check
recovers, so the admin dashboard shows a history rather than a snapshot.

## Scheduled jobs

Two commands, both idempotent:

```cron
# every 2 minutes: deliver queued SMS, and generate "turn approaching"
*/2 * * * * cd /srv/queuepilot && php tools/queue-worker.php >> storage/logs/worker.log 2>&1

# 02:15 nightly: back up, verify by restoring, record the result
15 2 * * * cd /srv/queuepilot && php tools/backup.php >> storage/logs/backup.log 2>&1
```

The worker is safe to run every two minutes *and* safe to run as a loop
(`--loop 30`) if you prefer a supervisor. Deduplication is in the database, not
in the scheduler.

### Why the backup job is not a shell one-liner

`tools/backup.php` does four things a `mysqldump > file` does not:

1. Takes a **consistent snapshot** (`VACUUM INTO` on SQLite; `mysqldump
   --single-transaction` on MySQL) so a live queue is not locked.
2. **Opens the dump** in a scratch database and runs `integrity_check` +
   `foreign_key_check`, and confirms the tables that hold the irreplaceable
   data are present.
3. Rejects an implausibly small file — a 0-byte dump is the most common silent
   backup failure and the one that is discovered during an incident.
4. Writes `storage/backups/last-backup.json`, which the health check reads.
   A backup nobody verified shows up as a warning before it is needed.

`php tools/backup.php --verify` re-runs the restore drill against the most
recent archive at any time. Run it after changing the backup host.

## Stale sessions ("the queue from yesterday is still open")

Symptom: `stale_queues` is not `ok`, and today's numbers look wrong.

This is a *process* failure, not a bug: someone went home without closing the
day. Do not delete rows. Close the session:

```sql
UPDATE queues SET status = 'closed'
 WHERE session_date < CURRENT_DATE AND status = 'open';
```

Any entry still `waiting` at that point is a patient who was never seen.
Reception should call them; the record stays in `queue_entries` either way, so
the report for that day remains truthful.

## When the display screen goes blank

The lobby screen polls `/api/display/{key}`. Two independent things can stop it:

* **The key was rotated** — the screen shows "offline". Re-open the screen from
  Settings → Waiting-room display on the new key.
* **Rate limiting** — the per-key ceiling is 90 requests/minute
  (`DISPLAY_KEY_PER_MIN`). One screen polls every few seconds; a room full of
  screens or a refresh loop can reach this. The response is a `429` with
  `Retry-After`, and the screen shows the stale-data pill rather than a blank.

The key is a bearer credential: anyone who photographs the screen has it. It is
scoped to tokens, rooms and clinic names — never patient names, never phone
numbers. Rotate it in Settings if a screen is decommissioned.

## Step-up authentication

Clinic owners and platform administrators can turn on TOTP from
**Settings → Account security**. What to know operationally:

* Losing the phone is not a lockout: there are eight single-use recovery codes,
  shown once at enrolment.
* Losing both means an administrator clears the second factor. That is a
  database change (`totp_secret`, `totp_enabled_at`, `totp_recovery` set to
  NULL) and must be logged in the incident record — it is a deliberate
  weakening of the account.
* TOTP secrets are encrypted with a key derived from `APP_KEY`. **Changing
  `APP_KEY` invalidates every enrolled secret.** Plan it: ask owners to
  re-enrol, or do not rotate the key casually.

## Staff onboarding

Invitations, not shared passwords:

1. An owner opens **Settings → Invite a staff member**.
2. With `MAIL_TRANSPORT` unset the invitation is not emailed; the link is shown
   once to the owner to pass on. This is deliberate — claiming an email was
   sent when no transport exists is worse than saying so.
3. The link is single-use, expires in 72 hours, and is stored only as a hash.
4. The invitee sets their own password; nobody else ever knows it.

## Changing `APP_KEY` or `SESSION_PATH`

Two settings that break sign-in if changed carelessly:

* **`APP_KEY`** feeds CSRF and the TOTP encryption key. Rotating it signs
  everyone out (acceptable) and invalidates 2FA enrolments (not acceptable by
  surprise). Rotate in a maintenance window and tell owners.
* **`SESSION_PATH`** must be writable by PHP. In this repository it is left
  unset on purpose so the app uses `storage/sessions`. Pointing it at
  `/var/lib/php/sessions` on a container without that directory makes every
  login fail with a 419, which looks like a CSRF bug and is not one.

## Escalation

1. `/api/health` → which check is `critical`.
2. `storage/logs/app.log` → the same minute.
3. `audit_logs` for the clinic → what a person did just before it broke.
4. If patient data may be involved: follow `docs/CONTINUITY.md` (paper
   fallback), and do not restart into a backup without a second pair of eyes on
   which rows would be lost.
