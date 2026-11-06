-- =====================================================================
-- 2026_11_05_0003 — operational depth
--
-- Everything here exists because a real clinic asked for it, not because a
-- feature list looked short:
--
--   * `on_hold` — "the patient is here but has stepped out for an X-ray".
--     Skipping them loses their place and marking them no-show is a lie.
--     A hold is a distinct, visible, reversible state.
--   * `organisations` — a group that runs several branches needs to see the
--     group, not three disconnected logins. Clinics keep their own data;
--     the organisation is an optional parent, never a tenant boundary.
--   * `doctor_leave` — a doctor who is away must stop appearing on the
--     booking form and must not silently soak up appointments.
--   * `staff_invites` — a clinic admin invites a colleague by email instead
--     of hand-typing a password. Token is stored hashed; single use.
--   * `rate_limits` — the display key is a bearer credential on a public
--     URL. It needs a counter that survives a restart and is shared between
--     processes, which is why it is a table and not an APCu counter.
--   * TOTP columns on `users` — step-up authentication for privileged roles.
--   * `queue_entries.approach_notified_at` — "your turn is approaching"
--     is a separate, once-only message from "your turn is now".
-- =====================================================================

-- ---------- 1. on_hold needs a new CHECK constraint (SQLite rebuild) -----
-- SQLite cannot alter a CHECK constraint, so the table is rebuilt. Columns
-- are copied positionally, which is why the column order below is identical
-- to the original definition; `PRAGMA foreign_key_check` and
-- `PRAGMA integrity_check` are asserted afterwards in the test suite.
PRAGMA foreign_keys = OFF;
BEGIN;
CREATE TABLE queue_entries_new (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id      INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  queue_id       INTEGER NOT NULL REFERENCES queues(id) ON DELETE CASCADE,
  patient_id     INTEGER NOT NULL REFERENCES patients(id) ON DELETE CASCADE,
  appointment_id INTEGER REFERENCES appointments(id) ON DELETE SET NULL,
  token_no       INTEGER NOT NULL,
  token_label    TEXT NOT NULL,
  status         TEXT NOT NULL DEFAULT 'waiting',
  priority       INTEGER NOT NULL DEFAULT 0,
  priority_reason TEXT,
  sort_weight    INTEGER NOT NULL DEFAULT 0,
  source         TEXT NOT NULL DEFAULT 'walkin',
  joined_at      TEXT NOT NULL,
  called_at      TEXT,
  started_at     TEXT,
  finished_at    TEXT,
  wait_minutes   INTEGER,
  consult_minutes INTEGER,
  call_count     INTEGER NOT NULL DEFAULT 0,
  note           TEXT,
  created_by     INTEGER,
  track_token    TEXT NOT NULL UNIQUE,
  track_expires_at TEXT,
  version        INTEGER NOT NULL DEFAULT 0,
  approach_notified_at TEXT,
  UNIQUE (queue_id, token_label),
  CONSTRAINT ck_entry_status CHECK (status IN ('waiting','called','in_consultation','completed','skipped','no_show','cancelled','transferred','on_hold'))
);
INSERT INTO queue_entries_new
  (id, clinic_id, queue_id, patient_id, appointment_id, token_no, token_label, status,
   priority, priority_reason, sort_weight, source, joined_at, called_at, started_at,
   finished_at, wait_minutes, consult_minutes, call_count, note, created_by,
   track_token, track_expires_at, version)
SELECT
   id, clinic_id, queue_id, patient_id, appointment_id, token_no, token_label, status,
   priority, priority_reason, sort_weight, source, joined_at, called_at, started_at,
   finished_at, wait_minutes, consult_minutes, call_count, note, created_by,
   track_token, track_expires_at, version
FROM queue_entries;
DROP TABLE queue_entries;
ALTER TABLE queue_entries_new RENAME TO queue_entries;
COMMIT;
PRAGMA foreign_keys = ON;

-- Indexes and the partial unique index are recreated against the new table.
CREATE INDEX IF NOT EXISTS idx_qe_board ON queue_entries (queue_id, status, priority, sort_weight, id);
CREATE INDEX IF NOT EXISTS idx_qe_day ON queue_entries (clinic_id, joined_at);
-- on_hold OCCUPIES the patient's place: a patient on hold must not be able to
-- take a second token in the same queue by walking back to reception.
CREATE UNIQUE INDEX IF NOT EXISTS uq_active_entry_per_patient
    ON queue_entries (queue_id, patient_id)
    WHERE status IN ('waiting', 'called', 'in_consultation', 'on_hold');

-- ---------- 2. organisations + branches --------------------------------
CREATE TABLE IF NOT EXISTS organisations (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  name       TEXT NOT NULL,
  slug       TEXT NOT NULL UNIQUE,
  owner_email TEXT,
  notes      TEXT,
  is_active  INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);

ALTER TABLE clinics ADD COLUMN organisation_id INTEGER REFERENCES organisations(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_clinic_org ON clinics (organisation_id);

-- ---------- 3. doctor leave -------------------------------------------
-- A leave row is a *claim* over a date range. Booking and check-in consult it;
-- nothing is auto-cancelled, because cancelling a patient's appointment is a
-- clinical/administrative decision a human must make.
CREATE TABLE IF NOT EXISTS doctor_leave (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id   INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  doctor_id   INTEGER NOT NULL REFERENCES doctors(id) ON DELETE CASCADE,
  starts_on   TEXT NOT NULL,
  ends_on     TEXT NOT NULL,
  reason      TEXT,
  created_by  INTEGER,
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_leave_doctor ON doctor_leave (doctor_id, starts_on, ends_on);
CREATE INDEX IF NOT EXISTS idx_leave_clinic ON doctor_leave (clinic_id, starts_on);

-- ---------- 4. staff invites ------------------------------------------
-- token_hash, not token: the plaintext lives only in the email. If the
-- database leaks, the invites are not usable.
CREATE TABLE IF NOT EXISTS staff_invites (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id   INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  email       TEXT NOT NULL,
  role        TEXT NOT NULL,
  token_hash  TEXT NOT NULL UNIQUE,
  invited_by  INTEGER,
  expires_at  TEXT NOT NULL,
  accepted_at TEXT,
  accepted_user_id INTEGER,
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_invite_clinic ON staff_invites (clinic_id, created_at);

-- ---------- 5. durable rate limiting ----------------------------------
-- `bucket` is a coarse identity ("display:KEY", "login:ip:1.2.3.4"), never a
-- raw credential. `window_start` is bucketed to whole minutes so the table
-- stays small and pruning is a single DELETE.
CREATE TABLE IF NOT EXISTS rate_limits (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  bucket       TEXT NOT NULL,
  window_start INTEGER NOT NULL,
  hits         INTEGER NOT NULL DEFAULT 0,
  UNIQUE (bucket, window_start)
);
CREATE INDEX IF NOT EXISTS idx_rate_window ON rate_limits (window_start);

-- ---------- 6. step-up / two-factor -----------------------------------
-- totp_secret is stored encrypted-at-rest by the app (see TotpService) so a
-- database dump alone cannot mint codes. Recovery codes are stored as hashes.
ALTER TABLE users ADD COLUMN totp_secret TEXT;
ALTER TABLE users ADD COLUMN totp_enabled_at TEXT;
ALTER TABLE users ADD COLUMN totp_recovery TEXT;

-- ---------- 7. per-clinic notification opt-ins ------------------------
-- Off by default: an SMS costs money and must never be sent because we
-- shipped a default that happened to be on.
ALTER TABLE clinics ADD COLUMN notify_approach INTEGER NOT NULL DEFAULT 0;
ALTER TABLE clinics ADD COLUMN approach_threshold INTEGER NOT NULL DEFAULT 2;

-- ---------- 8. hold metadata ------------------------------------------
-- Why someone is on hold and since when — reception needs to chase them.
ALTER TABLE queue_entries ADD COLUMN hold_reason TEXT;
ALTER TABLE queue_entries ADD COLUMN hold_since TEXT;

-- ---------- 9. platform alerts (monitoring) ---------------------------
-- A monitoring endpoint is only useful if something records what it saw.
-- Alerts are also what the admin dashboard reads; this avoids a polling
-- agent having to remember anything in memory.
CREATE TABLE IF NOT EXISTS system_alerts (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id  INTEGER REFERENCES clinics(id) ON DELETE CASCADE,
  severity   TEXT NOT NULL DEFAULT 'warning',
  code       TEXT NOT NULL,
  message    TEXT NOT NULL,
  context_json TEXT,
  resolved_at TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_alert_open ON system_alerts (resolved_at, created_at);
