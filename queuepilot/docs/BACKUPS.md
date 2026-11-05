# Backups and restoration

A backup that has never been restored is not a backup. This document is the
whole procedure, including the verification step that most plans skip.

> **The verification step is now automatic.** `tools/backup.php` takes a
> snapshot and then *restores it into a scratch database* on every run:
> `integrity_check`, `foreign_key_check`, required tables, row counts, and a
> sanity check that the file is not suspiciously small. It records the outcome
> in `storage/backups/last-backup.json`, and `/api/health` reports **critical**
> when that record is missing or older than 48 hours.
>
> ```bash
> php tools/backup.php             # back up, verify, record, apply retention
> php tools/backup.php --verify    # re-run the drill against the newest archive
> php tools/backup.php --keep 30   # retention override
> ```
>
> What remains a *deployment* decision rather than code: GPG encryption before
> the archive leaves the host, and the off-host copy. Those are below, because
> they depend on your key custody and storage.

## What to back up

| Item | Why | Where |
| --- | --- | --- |
| The MySQL database | patients, queues, audit log, invoices | nightly full dump |
| `storage/logs/` | incident forensics | weekly, short retention |
| `.env` | credentials and keys | **separate** secure store, never in the dump |
| Uploaded logos (`public/uploads`) | cosmetics, cheap to include | nightly |

Nothing else matters. Sessions are disposable; the CSS builds from source.

## Nightly dump

```bash
mysqldump --single-transaction --quick --routines --triggers \
  --default-character-set=utf8mb4 \
  -h "$DB_HOST" -u "$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
  | gzip -9 > "/backup/queuepilot-$(date +%F).sql.gz"
```

`--single-transaction` matters: it dumps a consistent snapshot without locking
the queue table, so a 2 a.m. backup does not stall a night clinic.

Never run `mysqldump` without it on a live queue — the dump will block writes.

## Encryption (before it leaves the host)

```bash
gpg --batch --yes --encrypt --recipient ops@example.lk \
  --output "/backup/queuepilot-$(date +%F).sql.gz.gpg" \
  "/backup/queuepilot-$(date +%F).sql.gz"
rm -f "/backup/queuepilot-$(date +%F).sql.gz"
```

Key storage: the private key lives with the operator, **not** on the database
host. A stolen backup without the key is useless; a stolen key with the backup
on the same disk is a breach.

## Off-host copy

Keep three generations, at least one off the primary host:

* tonight on the host (fast restores)
* last 7 days in object storage in a different country/region
* last 30 days on a monthly rotation

An attacker who owns the web server must not be able to delete the backups.
That is the whole point of the second location.

## Restoring (practise this quarterly)

```bash
# 1. Restore into a scratch database first — never straight over production
mysql -h "$DB_HOST" -u root -p -e "CREATE DATABASE queuepilot_restore"
gunzip -c backup.sql.gz | mysql -h "$DB_HOST" -u root -p queuepilot_restore

# 2. Point the app at it and verify
DB_DATABASE=queuepilot_restore php tools/healthcheck.php
DB_DATABASE=queuepilot_restore php tools/migrate.php   # brings forward any new migrations
```

`healthcheck.php` asserts the tables exist, the plans are seeded, and both
hand-written schemas still describe the same database. A restore that passes it
is a restore that will boot.

3. Spot-check reality, not just row counts:
   * today's queue has the tokens the paper log says it should
   * the last 10 audit entries are present and attributed
   * a known patient's history is intact
4. Only then point production at the restored database.

## Recovery time expectations

| Scenario | Target |
| --- | --- |
| Bad deploy, config error | under 15 minutes (redeploy previous release) |
| Corrupted table, same day | under 1 hour (restore + reconcile paper log) |
| Total host loss | under 4 hours (new host, restore, DNS) |

Write the actual measured times down after each drill. An untested "4 hours" is
a guess, and the clinic will ask.

## Retention and the law

Patient contact details and queue history are personal data. Agree a retention
period with each clinic (the audit log is also evidence for disputes). Deleting
a clinic's data is a deliberate, ticketed action with written confirmation —
never a side effect of a billing suspension. Suspension blocks access; it does
not delete.
