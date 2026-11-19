-- ============================================================================
--  QueuePilot — complete MySQL schema and seed data
-- ============================================================================
--
--  ONE FILE. Import it into an empty database and the application runs.
--
--      mysql -u USER -p DATABASE_NAME < database/install.sql
--
--  or phpMyAdmin -> select the database -> Import -> choose this file -> Go.
--
--  What it does, in order:
--    1. Creates the base schema (16 tables)
--    2. Applies all 30 migrations (ALTERs + the other 36 tables)
--    3. Inserts the four subscription plans
--    4. Records what was applied, so tools/migrate.php will not re-run it
--
--  Result: 52 tables. It creates NO clinic, NO patient and NO user — an
--  install with demo data in it is not a production install. Create your
--  platform admin afterwards with:
--
--      php tools/make-admin.php --email=you@example.com
--
--  Requirements: MySQL 5.7+ / MariaDB 10.3+, utf8mb4, InnoDB.
--  Not meant to be re-run on a populated database. Every CREATE TABLE is
--  IF NOT EXISTS and every INSERT is idempotent, but CREATE INDEX and ALTER
--  TABLE have no such guard in MySQL (CREATE INDEX ... IF NOT EXISTS only
--  arrived in MySQL 8.0.29 and never reached MariaDB) — so a second run stops
--  at the first duplicate index or column. That is the correct outcome: it
--  fails immediately instead of half-applying over live data.
--
--  To build a second copy, import into an empty database.
--
--  GENERATED FILE. Built by tools/build-install-sql.php from the schema and
--  migrations. Do not edit; edit the migration and rebuild.
--
--  Built from: 31 source files
-- ============================================================================

SET NAMES utf8mb4;
SET @OLD_SQL_MODE = @@SQL_MODE;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION';

-- ==========================================================================
--  0000_schema
-- ==========================================================================

-- =====================================================================
-- QueuePilot — MySQL 8 schema (production target)
-- Mirrors database/schema/sqlite.sql exactly (same tables, columns,
-- constraints and indexes) so the PHP layer runs unchanged on either.
-- Run: mysql -u root -p queuepilot < database/schema/mysql.sql
-- =====================================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE IF NOT EXISTS plans (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40)  NOT NULL UNIQUE,
  name          VARCHAR(80)  NOT NULL,
  price_monthly DECIMAL(10,2) NOT NULL DEFAULT 0,
  currency      CHAR(3) NOT NULL DEFAULT 'LKR',
  max_doctors   INT NOT NULL DEFAULT 1,
  max_staff     INT NOT NULL DEFAULT 1,
  max_queues    INT NOT NULL DEFAULT 2,
  features      TEXT NULL,
  sort_order    INT NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS clinics (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  slug           VARCHAR(60) NOT NULL UNIQUE,
  name           VARCHAR(160) NOT NULL,
  legal_name     VARCHAR(160) NULL,
  phone          VARCHAR(32) NULL,
  email          VARCHAR(160) NULL,
  address_line   VARCHAR(255) NULL,
  city           VARCHAR(80) NULL,
  district       VARCHAR(80) NULL,
  timezone       VARCHAR(64) NOT NULL DEFAULT 'Asia/Colombo',
  locale         VARCHAR(8) NOT NULL DEFAULT 'en',
  logo_path      VARCHAR(255) NULL,
  brand_color    VARCHAR(16) NOT NULL DEFAULT '#0F766E',
  currency       CHAR(3) NOT NULL DEFAULT 'LKR',
  -- queue policy
  token_prefix       VARCHAR(8)  NOT NULL DEFAULT '',
  token_reset_policy VARCHAR(16) NOT NULL DEFAULT 'daily',   -- daily | session
  late_grace_minutes INT NOT NULL DEFAULT 15,
  appointment_weight INT NOT NULL DEFAULT 1,
  walkin_weight      INT NOT NULL DEFAULT 3,
  mandate_return_mins INT NOT NULL DEFAULT 40,
  -- subscription
  plan_id        INT UNSIGNED NULL,
  billing_status VARCHAR(24) NOT NULL DEFAULT 'trial',       -- trial | active | past_due | suspended | cancelled
  trial_ends_on  DATE NULL,
  renews_on      DATE NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_clinics_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id     INT UNSIGNED NULL,          -- NULL => platform staff
  role          VARCHAR(24) NOT NULL,       -- super_admin | clinic_admin | receptionist | doctor
  name          VARCHAR(120) NOT NULL,
  email         VARCHAR(160) NOT NULL UNIQUE,
  phone         VARCHAR(32) NULL,
  password_hash VARCHAR(255) NOT NULL,
  doctor_id     INT UNSIGNED NULL,
  locale        VARCHAR(8) NOT NULL DEFAULT 'en',
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_users_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_users_clinic ON users (clinic_id, role);

CREATE TABLE IF NOT EXISTS specialties (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id INT UNSIGNED NOT NULL,
  name      VARCHAR(80) NOT NULL,
  CONSTRAINT fk_spec_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  UNIQUE KEY uq_spec (clinic_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rooms (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id INT UNSIGNED NOT NULL,
  name      VARCHAR(60) NOT NULL,
  floor     VARCHAR(24) NULL,
  CONSTRAINT fk_rooms_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doctors (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id     INT UNSIGNED NOT NULL,
  specialty_id  INT UNSIGNED NULL,
  room_id       INT UNSIGNED NULL,
  name          VARCHAR(120) NOT NULL,
  reg_no        VARCHAR(60) NULL,
  consult_fee   DECIMAL(10,2) NOT NULL DEFAULT 0,
  avg_consult_minutes INT NOT NULL DEFAULT 10,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_doc_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_spec FOREIGN KEY (specialty_id) REFERENCES specialties(id) ON DELETE SET NULL,
  CONSTRAINT fk_doc_room FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_doc_clinic ON doctors (clinic_id, is_active);

CREATE TABLE IF NOT EXISTS queues (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id      INT UNSIGNED NOT NULL,
  doctor_id      INT UNSIGNED NULL,
  name           VARCHAR(100) NOT NULL,
  kind           VARCHAR(24) NOT NULL DEFAULT 'consultation',  -- consultation | lab | pharmacy | general
  status         VARCHAR(16) NOT NULL DEFAULT 'open',          -- open | paused | closed
  session_date   DATE NOT NULL,
  token_prefix   VARCHAR(8) NOT NULL DEFAULT '',
  last_token_no  INT NOT NULL DEFAULT 0,
  token_padding  TINYINT NOT NULL DEFAULT 3,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_q_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_q_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_q_live ON queues (clinic_id, status, session_date);
CREATE INDEX idx_q_doctor_date ON queues (doctor_id, session_date);

CREATE TABLE IF NOT EXISTS patients (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id     INT UNSIGNED NOT NULL,
  code          VARCHAR(24) NOT NULL,
  full_name     VARCHAR(160) NOT NULL,
  phone         VARCHAR(32) NULL,
  gender        VARCHAR(16) NULL,
  birth_year    SMALLINT NULL,
  notes         VARCHAR(500) NULL,
  is_blocked    TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pat_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  UNIQUE KEY uq_pat_code (clinic_id, code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_pat_phone ON patients (clinic_id, phone);

CREATE TABLE IF NOT EXISTS appointments (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id     INT UNSIGNED NOT NULL,
  doctor_id     INT UNSIGNED NOT NULL,
  patient_id    INT UNSIGNED NOT NULL,
  scheduled_for DATETIME NOT NULL,
  duration_minutes INT NOT NULL DEFAULT 15,
  status        VARCHAR(24) NOT NULL DEFAULT 'booked',  -- booked | checked_in | served | no_show | cancelled
  source        VARCHAR(16) NOT NULL DEFAULT 'online',  -- online | phone | walkin
  notes         VARCHAR(500) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_appt_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_appt_doc FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
  CONSTRAINT fk_appt_pat FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_appt_day ON appointments (clinic_id, doctor_id, scheduled_for);

CREATE TABLE IF NOT EXISTS queue_entries (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id      INT UNSIGNED NOT NULL,
  queue_id       INT UNSIGNED NOT NULL,
  patient_id     INT UNSIGNED NOT NULL,
  appointment_id INT UNSIGNED NULL,
  token_no       INT NOT NULL,
  token_label    VARCHAR(24) NOT NULL,
  -- waiting | called | in_consultation | completed | skipped | no_show | cancelled | transferred
  status         VARCHAR(24) NOT NULL DEFAULT 'waiting',
  priority       TINYINT NOT NULL DEFAULT 0,
  priority_reason VARCHAR(160) NULL,
  sort_weight    INT NOT NULL DEFAULT 0,
  source         VARCHAR(16) NOT NULL DEFAULT 'walkin',
  joined_at      DATETIME NOT NULL,
  called_at      DATETIME NULL,
  started_at     DATETIME NULL,
  finished_at    DATETIME NULL,
  wait_minutes   INT NULL,
  consult_minutes INT NULL,
  call_count     INT NOT NULL DEFAULT 0,
  note           VARCHAR(300) NULL,
  created_by     INT UNSIGNED NULL,
  -- public tracking link: random, unguessable, rotatable
  track_token    VARCHAR(64) NOT NULL UNIQUE,
  track_expires_at DATETIME NULL,
  version        INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_qe_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_qe_queue FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE CASCADE,
  CONSTRAINT fk_qe_patient FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
  CONSTRAINT fk_qe_appt FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE SET NULL,
  UNIQUE KEY uq_token_per_queue (queue_id, token_label),
  CONSTRAINT ck_qe_status CHECK (status IN ('waiting','called','in_consultation','completed','skipped','no_show','cancelled','transferred'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_qe_board ON queue_entries (queue_id, status, priority, sort_weight, id);
CREATE INDEX idx_qe_day ON queue_entries (clinic_id, joined_at);

CREATE TABLE IF NOT EXISTS queue_events (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id    INT UNSIGNED NOT NULL,
  queue_id     INT UNSIGNED NULL,
  entry_id     INT UNSIGNED NULL,
  actor_id     INT UNSIGNED NULL,
  action       VARCHAR(40) NOT NULL,
  from_status  VARCHAR(24) NULL,
  to_status    VARCHAR(24) NULL,
  reason       VARCHAR(200) NULL,
  meta_json    TEXT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_qev_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_qev_entry FOREIGN KEY (entry_id) REFERENCES queue_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_qev_entry ON queue_events (entry_id, id);

CREATE TABLE IF NOT EXISTS notifications (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id    INT UNSIGNED NOT NULL,
  entry_id     INT UNSIGNED NULL,
  channel      VARCHAR(16) NOT NULL DEFAULT 'sms',   -- sms | whatsapp | voice
  template     VARCHAR(40) NOT NULL,
  to_number    VARCHAR(32) NULL,
  payload_json TEXT NULL,
  dedupe_key   VARCHAR(120) NOT NULL,
  status       VARCHAR(16) NOT NULL DEFAULT 'queued', -- queued | sent | failed | abandoned
  attempts     INT NOT NULL DEFAULT 0,
  last_error   VARCHAR(300) NULL,
  scheduled_at DATETIME NOT NULL,
  sent_at      DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  UNIQUE KEY uq_notif_dedupe (dedupe_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_notif_due ON notifications (status, scheduled_at);

CREATE TABLE IF NOT EXISTS audit_logs (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id    INT UNSIGNED NULL,
  actor_id     INT UNSIGNED NULL,
  action       VARCHAR(60) NOT NULL,
  entity       VARCHAR(40) NULL,
  entity_id    INT UNSIGNED NULL,
  ip           VARCHAR(60) NULL,
  meta_json    TEXT NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_audit_clinic ON audit_logs (clinic_id, id);

CREATE TABLE IF NOT EXISTS invoices (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id   INT UNSIGNED NOT NULL,
  number      VARCHAR(40) NOT NULL UNIQUE,
  period_from DATE NOT NULL,
  period_to   DATE NOT NULL,
  amount      DECIMAL(10,2) NOT NULL,
  currency    CHAR(3) NOT NULL DEFAULT 'LKR',
  status      VARCHAR(16) NOT NULL DEFAULT 'unpaid',  -- unpaid | paid | void
  paid_at     DATETIME NULL,
  method      VARCHAR(24) NULL,
  reference   VARCHAR(80) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_inv_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(160) NOT NULL,
  ip         VARCHAR(60) NOT NULL,
  successful TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_attempt ON login_attempts (email, ip, created_at);

CREATE TABLE IF NOT EXISTS settings (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  scope     VARCHAR(16) NOT NULL,       -- platform | clinic
  owner_id  INT UNSIGNED NULL,
  key_name  VARCHAR(80) NOT NULL,
  value     TEXT NULL,
  UNIQUE KEY uq_setting (scope, owner_id, key_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
--  1_0001_queue_integrity.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0001 — integrity constraints the application cannot fake
-- (MySQL 8.0 variant)
--
-- MySQL has no partial indexes, so the same invariants are expressed with
-- generated columns that collapse to NULL once a row leaves the active
-- window. MySQL permits many NULLs in a UNIQUE index, which is exactly the
-- behaviour needed: finished rows never collide, live rows always do.
--
--   1. A patient must not hold two ACTIVE entries in the same queue.
--   2. A doctor must not be double-booked into the same slot.
-- =====================================================================

ALTER TABLE queue_entries
    ADD COLUMN active_patient_key BIGINT
        GENERATED ALWAYS AS (
            CASE WHEN status IN ('waiting', 'called', 'in_consultation')
                 THEN patient_id ELSE NULL END
        ) STORED,
    ADD UNIQUE KEY uq_active_entry_per_patient (queue_id, active_patient_key);

ALTER TABLE appointments
    ADD COLUMN live_slot_key DATETIME
        GENERATED ALWAYS AS (
            CASE WHEN status IN ('booked', 'checked_in')
                 THEN scheduled_for ELSE NULL END
        ) STORED,
    ADD UNIQUE KEY uq_appointment_slot (clinic_id, doctor_id, live_slot_key);

-- ==========================================================================
--  1_0002_delays_and_online_booking.mysql
-- ==========================================================================

-- 2026_11_05_0002 — see the sqlite variant for the rationale.
ALTER TABLE queues
    ADD COLUMN delay_minutes INT NOT NULL DEFAULT 0,
    ADD COLUMN delay_note VARCHAR(180) NULL;

ALTER TABLE clinics
    ADD COLUMN allow_online_booking TINYINT(1) NOT NULL DEFAULT 0;

-- ==========================================================================
--  1_0003_operational_depth.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0003 — operational depth (MySQL 8.0 variant)
--
-- MySQL can ALTER a CHECK constraint, so unlike the SQLite variant there is
-- no table rebuild here: drop the old constraint, extend the allowed set.
-- The generated column `active_patient_key` from migration 0001 must be
-- recreated too, because `on_hold` must behave like a live status.
-- =====================================================================

-- ---------- 1. on_hold ------------------------------------------------
ALTER TABLE queue_entries DROP CONSTRAINT ck_qe_status;
ALTER TABLE queue_entries ADD CONSTRAINT ck_qe_status
    CHECK (status IN ('waiting','called','in_consultation','completed','skipped','no_show','cancelled','transferred','on_hold'));

-- Recreate the active-entry guard so a held patient keeps their slot and
-- cannot be handed a duplicate token.
ALTER TABLE queue_entries DROP INDEX uq_active_entry_per_patient;
ALTER TABLE queue_entries DROP COLUMN active_patient_key;
ALTER TABLE queue_entries
    ADD COLUMN active_patient_key BIGINT
        GENERATED ALWAYS AS (
            CASE WHEN status IN ('waiting', 'called', 'in_consultation', 'on_hold')
                 THEN patient_id ELSE NULL END
        ) STORED,
    ADD UNIQUE KEY uq_active_entry_per_patient (queue_id, active_patient_key);

-- ---------- 2. organisations + branches -------------------------------
CREATE TABLE IF NOT EXISTS organisations (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(160) NOT NULL,
  slug        VARCHAR(80) NOT NULL UNIQUE,
  owner_email VARCHAR(160) NULL,
  notes       VARCHAR(500) NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE clinics ADD COLUMN organisation_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_clinic_org FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE SET NULL;
CREATE INDEX idx_clinic_org ON clinics (organisation_id);

-- ---------- 3. doctor leave -------------------------------------------
CREATE TABLE IF NOT EXISTS doctor_leave (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id  INT UNSIGNED NOT NULL,
  doctor_id  INT UNSIGNED NOT NULL,
  starts_on  DATE NOT NULL,
  ends_on    DATE NOT NULL,
  reason     VARCHAR(255) NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_leave_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_leave_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
  CONSTRAINT ck_leave_range CHECK (ends_on >= starts_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_leave_doctor ON doctor_leave (doctor_id, starts_on, ends_on);
CREATE INDEX idx_leave_clinic ON doctor_leave (clinic_id, starts_on);

-- ---------- 4. staff invites ------------------------------------------
CREATE TABLE IF NOT EXISTS staff_invites (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id        INT UNSIGNED NOT NULL,
  email            VARCHAR(160) NOT NULL,
  role             VARCHAR(24) NOT NULL,
  token_hash       CHAR(64) NOT NULL UNIQUE,
  invited_by       INT UNSIGNED NULL,
  expires_at       DATETIME NOT NULL,
  accepted_at      DATETIME NULL,
  accepted_user_id INT UNSIGNED NULL,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_invite_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  UNIQUE KEY uq_invite_open (clinic_id, email, accepted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_invite_clinic ON staff_invites (clinic_id, created_at);

-- ---------- 5. durable rate limiting ----------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  bucket       VARCHAR(120) NOT NULL,
  window_start BIGINT NOT NULL,
  hits         INT UNSIGNED NOT NULL DEFAULT 0,
  UNIQUE KEY uq_rate_bucket (bucket, window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_rate_window ON rate_limits (window_start);

-- ---------- 6. step-up / two-factor -----------------------------------
ALTER TABLE users
    ADD COLUMN totp_secret     TEXT NULL,
    ADD COLUMN totp_enabled_at DATETIME NULL,
    ADD COLUMN totp_recovery   TEXT NULL;

-- ---------- 7. per-clinic notification opt-ins ------------------------
ALTER TABLE clinics
    ADD COLUMN notify_approach  TINYINT(1) NOT NULL DEFAULT 0,
    ADD COLUMN approach_threshold INT NOT NULL DEFAULT 2;

-- ---------- 8. hold metadata ------------------------------------------
ALTER TABLE queue_entries
    ADD COLUMN hold_reason VARCHAR(255) NULL,
    ADD COLUMN hold_since  DATETIME NULL;

-- The "your turn is approaching" message is a separate, once-only message from
-- "your turn is now", so it needs its own claimed-at stamp. The SQLite half has
-- had this column since this migration was written; the MySQL half never added
-- it. NotificationService::notifyApproaching() selects and updates it, so on
-- MySQL every queue-worker run for a clinic that had turned the option on threw
-- — and the option was on the settings screen, so it was one toggle away.
ALTER TABLE queue_entries
    ADD COLUMN approach_notified_at DATETIME NULL;

-- ---------- 9. platform alerts ----------------------------------------
CREATE TABLE IF NOT EXISTS system_alerts (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id    INT UNSIGNED NULL,
  severity     VARCHAR(16) NOT NULL DEFAULT 'warning',
  code         VARCHAR(64) NOT NULL,
  message      VARCHAR(500) NOT NULL,
  context_json TEXT NULL,
  resolved_at  DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_alert_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_alert_open ON system_alerts (resolved_at, created_at);

-- ==========================================================================
--  1_0004_password_resets.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0004 — password recovery (MySQL 8.0 variant)
-- =====================================================================

CREATE TABLE IF NOT EXISTS password_resets (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  token_hash   CHAR(64) NOT NULL UNIQUE,
  expires_at   DATETIME NOT NULL,
  used_at      DATETIME NULL,
  requested_ip VARCHAR(64) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_reset_user ON password_resets (user_id, used_at);
CREATE INDEX idx_reset_expiry ON password_resets (expires_at);

-- ==========================================================================
--  1_0005_structure.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0005 — departments, schedules, holidays, support, notices
-- (MySQL 8.0 variant)
--
-- Mirrors the SQLite migration exactly. MySQL can ALTER in place, so unlike
-- the SQLite file there are no table rebuilds.
-- =====================================================================

-- ---------- 1. departments ------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id      INT UNSIGNED NOT NULL,
  name           VARCHAR(120) NOT NULL,
  code           VARCHAR(20) NULL,
  description    VARCHAR(500) NULL,
  floor          VARCHAR(60) NULL,
  lead_doctor_id INT UNSIGNED NULL,
  sort_order     INT NOT NULL DEFAULT 0,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dept_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_dept_lead FOREIGN KEY (lead_doctor_id) REFERENCES doctors(id) ON DELETE SET NULL,
  UNIQUE KEY uq_dept_name (clinic_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_dept_clinic ON departments (clinic_id, is_active, sort_order);

ALTER TABLE queues ADD COLUMN department_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_queue_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;
CREATE INDEX idx_queue_dept ON queues (department_id);

ALTER TABLE doctors ADD COLUMN department_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_doctor_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;
CREATE INDEX idx_doctor_dept ON doctors (department_id);

-- ---------- 2. doctor schedules ------------------------------------------
CREATE TABLE IF NOT EXISTS doctor_schedules (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id  INT UNSIGNED NOT NULL,
  doctor_id  INT UNSIGNED NOT NULL,
  weekday    TINYINT NOT NULL,
  starts_at  TIME NOT NULL,
  ends_at    TIME NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sched_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_sched_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
  CONSTRAINT ck_sched_weekday CHECK (weekday BETWEEN 0 AND 6),
  CONSTRAINT ck_sched_order CHECK (ends_at > starts_at),
  UNIQUE KEY uq_sched_slot (doctor_id, weekday, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_sched_clinic ON doctor_schedules (clinic_id, doctor_id, weekday);

-- ---------- 3. holidays ---------------------------------------------------
CREATE TABLE IF NOT EXISTS holidays (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id  INT UNSIGNED NOT NULL,
  date       DATE NOT NULL,
  name       VARCHAR(120) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_holiday_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_holiday_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_holiday_day (clinic_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_holiday_lookup ON holidays (clinic_id, date);

-- ---------- 4. support ----------------------------------------------------
CREATE TABLE IF NOT EXISTS support_tickets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id  INT UNSIGNED NULL,
  user_id    INT UNSIGNED NULL,
  subject    VARCHAR(200) NOT NULL,
  body       TEXT NOT NULL,
  category   VARCHAR(40) NOT NULL DEFAULT 'general',
  priority   VARCHAR(20) NOT NULL DEFAULT 'normal',
  status     VARCHAR(20) NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ticket_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT ck_ticket_status CHECK (status IN ('open','waiting','resolved','closed')),
  CONSTRAINT ck_ticket_priority CHECK (priority IN ('low','normal','high','urgent'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_ticket_clinic ON support_tickets (clinic_id, status, created_at);
CREATE INDEX idx_ticket_status ON support_tickets (status, priority);

CREATE TABLE IF NOT EXISTS support_replies (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ticket_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  body       TEXT NOT NULL,
  is_staff   TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reply_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_reply_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_reply_ticket ON support_replies (ticket_id, created_at);

-- ---------- 5. in-system staff notifications -----------------------------
CREATE TABLE IF NOT EXISTS staff_notifications (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  kind       VARCHAR(30) NOT NULL DEFAULT 'info',
  title      VARCHAR(200) NOT NULL,
  body       VARCHAR(500) NULL,
  link       VARCHAR(255) NULL,
  read_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_snotice_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_snotice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_snotice_user ON staff_notifications (clinic_id, user_id, read_at);

-- ---------- 6. retention, suspension and closure -------------------------
ALTER TABLE clinics
    ADD COLUMN suspended_at DATETIME NULL,
    ADD COLUMN suspension_reason VARCHAR(255) NULL,
    ADD COLUMN closure_requested_at DATETIME NULL,
    ADD COLUMN closed_at DATETIME NULL,
    ADD COLUMN purge_after DATETIME NULL;

-- ==========================================================================
--  1_0005b_invoice_verification.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0005b — payment verification (MySQL 8.0 variant)
-- =====================================================================

ALTER TABLE invoices
    ADD COLUMN claimed_at DATETIME NULL,
    ADD COLUMN verified_at DATETIME NULL,
    ADD COLUMN verified_by INT UNSIGNED NULL,
    ADD COLUMN rejection_reason VARCHAR(255) NULL,
    ADD CONSTRAINT fk_invoice_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX idx_invoice_status ON invoices (status, claimed_at);

UPDATE invoices SET verified_at = COALESCE(paid_at, created_at)
 WHERE status = 'paid' AND verified_at IS NULL;

-- ==========================================================================
--  1_0006_email_verification.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0006 — email verification (MySQL)
-- See the SQLite file for the reasoning.
-- =====================================================================

ALTER TABLE users ADD COLUMN email_verified_at VARCHAR(19) NULL;
ALTER TABLE users ADD COLUMN verification_sent_at VARCHAR(19) NULL;

UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS email_verifications (
  id         BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  token_hash CHAR(64) NOT NULL UNIQUE,
  expires_at VARCHAR(19) NOT NULL,
  used_at    VARCHAR(19) NULL,
  created_at VARCHAR(19) NOT NULL,
  INDEX idx_verify_user (user_id, used_at),
  INDEX idx_verify_expiry (expires_at),
  CONSTRAINT fk_verify_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW unverified_clinics AS
SELECT c.id AS clinic_id, c.name AS clinic_name, u.id AS user_id, u.email, u.created_at
  FROM clinics c
  JOIN users u ON u.clinic_id = c.id AND u.role = 'clinic_admin'
 WHERE u.email_verified_at IS NULL AND u.is_active = 1;

-- ==========================================================================
--  1_0006_feature_gate.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0006 — per-clinic feature gates (SaaS entitlement)
-- MySQL variant. Same semantics as the SQLite migration.
-- =====================================================================

CREATE TABLE IF NOT EXISTS clinic_features (
  id         INTEGER PRIMARY KEY AUTO_INCREMENT,
  clinic_id  INTEGER NOT NULL,
  feature    VARCHAR(64) NOT NULL,
  enabled    TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  UNIQUE KEY uq_clinic_feature (clinic_id, feature),
  KEY idx_clinic_feature (clinic_id, feature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sms_usage (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  clinic_id   INTEGER NOT NULL,
  year        INTEGER NOT NULL,
  month       INTEGER NOT NULL,
  sent_count  INTEGER NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  UNIQUE KEY uq_sms_usage_clinic (clinic_id, year, month),
  KEY idx_sms_usage_clinic (clinic_id, year, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================================================
--  1_0007_invoice_periods.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0007 — one invoice per clinic per billing period (MySQL)
--
-- MySQL has no partial indexes. The equivalent guard is a generated column
-- that is NULL for cancelled invoices, with a unique index over it: NULLs do
-- not collide in a MySQL unique index, so cancelled rows are exempt and live
-- rows are not. See the SQLite file for the reasoning.
-- =====================================================================

ALTER TABLE invoices
  ADD COLUMN period_guard VARCHAR(10)
    GENERATED ALWAYS AS (IF(status IN ('cancelled','void'), NULL, period_from)) STORED;

CREATE UNIQUE INDEX uq_invoice_period ON invoices (clinic_id, period_guard);

-- ==========================================================================
--  1_0008_breaks.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0008 — break management (MySQL). See the SQLite file.
-- =====================================================================

ALTER TABLE queues ADD COLUMN break_started_at VARCHAR(19) NULL;
ALTER TABLE queues ADD COLUMN break_kind VARCHAR(24) NULL;

CREATE TABLE IF NOT EXISTS queue_breaks (
  id         BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  clinic_id  BIGINT UNSIGNED NOT NULL,
  queue_id   BIGINT UNSIGNED NOT NULL,
  kind       VARCHAR(24) NOT NULL DEFAULT 'unspecified',
  reason     VARCHAR(255) NULL,
  started_at VARCHAR(19) NOT NULL,
  ended_at   VARCHAR(19) NULL,
  minutes    INT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at VARCHAR(19) NOT NULL,
  INDEX idx_break_clinic_day (clinic_id, started_at),
  INDEX idx_break_queue (queue_id, ended_at),
  CONSTRAINT fk_break_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_break_queue FOREIGN KEY (queue_id) REFERENCES queues(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduled_breaks (
  id         BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  clinic_id  BIGINT UNSIGNED NOT NULL,
  doctor_id  BIGINT UNSIGNED NULL,
  weekday    TINYINT NOT NULL,
  starts_at  VARCHAR(5) NOT NULL,
  ends_at    VARCHAR(5) NOT NULL,
  kind       VARCHAR(24) NOT NULL DEFAULT 'lunch',
  is_active  TINYINT NOT NULL DEFAULT 1,
  created_at VARCHAR(19) NOT NULL,
  INDEX idx_sbreak_clinic (clinic_id, weekday, is_active),
  CONSTRAINT fk_sbreak_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_sbreak_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
--  1_0009_priority_rules.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0009 — priority rules
--
-- Priority was one thing: a clinician pressing "flag urgent" on a single
-- entry and typing a reason. That is the right escape hatch and it is the
-- wrong answer to "this clinic always sees children under five ahead of
-- adults, in the morning only, for two of its doctors". A rule is written
-- once and applies to every arrival afterwards.
--
-- The shape is deliberately small. A rule matches on facts the token issue
-- path already knows — source (appointment or walk-in), patient age, queue
-- kind, doctor, and the hour of the day — and it either adds a boost
-- (a larger `priority`, so the patient sorts ahead) or a penalty (a
-- smaller / negative one, so a routine review sorts behind).
--
-- Rules are per clinic and can be scoped to one queue. The highest matching
-- boost applies once; boosts do not stack, because "child + appointment +
-- morning" is one clinical decision about one patient, and adding three
-- numbers together turns a policy into an accident.
-- =====================================================================

CREATE TABLE IF NOT EXISTS priority_rules (
  id            INTEGER PRIMARY KEY AUTO_INCREMENT,
  clinic_id     INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  queue_id      INTEGER REFERENCES queues(id) ON DELETE CASCADE,
  name          TEXT NOT NULL,
  -- Match conditions. NULL means "any".
  source        TEXT,            -- appointment | walkin
  kind          TEXT,            -- consultation | lab | pharmacy | general
  doctor_id     INTEGER REFERENCES doctors(id) ON DELETE CASCADE,
  min_age       INTEGER,         -- inclusive
  max_age       INTEGER,         -- inclusive
  from_hour     INTEGER,         -- inclusive, 0-23 clinic-local
  to_hour       INTEGER,         -- exclusive, 1-24
  -- Effect. `priority` is the value written on the entry.
  effect        INTEGER NOT NULL DEFAULT 1,
  reason        TEXT NOT NULL,
  is_active     INTEGER NOT NULL DEFAULT 1,
  created_by    INTEGER,
  created_at    TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
  CONSTRAINT ck_rule_effect CHECK (effect BETWEEN -5 AND 9 AND effect <> 0),
  CONSTRAINT ck_rule_from CHECK (from_hour IS NULL OR from_hour BETWEEN 0 AND 23),
  CONSTRAINT ck_rule_to CHECK (to_hour IS NULL OR to_hour BETWEEN 1 AND 24),
  CONSTRAINT ck_rule_span CHECK (from_hour IS NULL OR to_hour IS NULL OR from_hour < to_hour),
  CONSTRAINT ck_rule_age CHECK (min_age IS NULL OR max_age IS NULL OR min_age <= max_age),
  CONSTRAINT ck_rule_source CHECK (source IS NULL OR source IN ('appointment','walkin')),
  CONSTRAINT ck_rule_kind CHECK (kind IS NULL OR kind IN ('consultation','lab','pharmacy','general'))
);

CREATE INDEX IF NOT EXISTS idx_rule_clinic_active ON priority_rules (clinic_id, is_active);

-- What a rule did, on the entry it did it to. A priority the patient did not
-- ask for and no member of staff typed has to be explainable afterwards, so
-- the reason travels with the entry rather than being recomputed later from
-- a rule that may since have been edited.
ALTER TABLE queue_entries ADD COLUMN priority_rule_id INTEGER;

-- ==========================================================================
--  1_0010_service_workflows.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0010 — configurable service workflows
--
-- The funnel was fixed: waiting → called → in consultation → completed.
-- That is right for a doctor's room and wrong for a laboratory, where the
-- visit is not "completed" when the nurse finishes talking — it is completed
-- when the sample is taken, the result is verified and the report is handed
-- over. A pharmacy counter has its own three steps. Without a way to name
-- those steps, the only honest label on the button is "completed", and the
-- record cannot say which part of the service actually happened.
--
-- A workflow is a list of **steps a service requires before completion**.
-- The step is not a status: the entry is still `in_consultation` until the
-- last one. Making each step a status would mean rewriting the state machine
-- per clinic and breaking the board, the display and the tracker for every
-- status they have never seen.
--
-- Each step belongs to a clinic and, optionally, to one queue kind or one
-- queue. A completion is refused while a required step is unticked, and the
-- refusal names the steps. Steps are recorded with who ticked them and when.
-- =====================================================================

CREATE TABLE IF NOT EXISTS workflow_steps (
  id           INTEGER PRIMARY KEY AUTO_INCREMENT,
  clinic_id    INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  queue_id     INTEGER REFERENCES queues(id) ON DELETE CASCADE,
  kind         TEXT,
  name         TEXT NOT NULL,
  position     INTEGER NOT NULL DEFAULT 1,
  is_required  INTEGER NOT NULL DEFAULT 1,
  is_active    INTEGER NOT NULL DEFAULT 1,
  created_at   TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
  CONSTRAINT ck_wstep_kind CHECK (kind IS NULL OR kind IN ('consultation','lab','pharmacy','general')),
  CONSTRAINT ck_wstep_position CHECK (position BETWEEN 1 AND 20)
);
CREATE INDEX IF NOT EXISTS idx_wstep_clinic ON workflow_steps (clinic_id, kind, is_active);

-- Which steps an individual visit has actually passed, and who said so.
CREATE TABLE IF NOT EXISTS entry_step_done (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  clinic_id   INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  entry_id    INTEGER NOT NULL REFERENCES queue_entries(id) ON DELETE CASCADE,
  step_id     INTEGER NOT NULL REFERENCES workflow_steps(id) ON DELETE CASCADE,
  done_by     INTEGER,
  done_at     TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
  UNIQUE (entry_id, step_id)
);
CREATE INDEX IF NOT EXISTS idx_stepdone_entry ON entry_step_done (entry_id);

-- ==========================================================================
--  1_0011_appointment_policies.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0011 — per-service appointment policy
--
-- Three policies already existed and were all clinic-wide or absent:
--
--   * Late grace was one number on the clinic row. A laboratory can accept
--     a sample thirty minutes after the booked time because the bench is
--     still running; a fifteen-minute consultation is genuinely gone after
--     twenty. One number cannot describe both.
--   * The booking horizon was a global setting (default 30 days). A physio
--     course is booked six weeks out; a flu clinic wants nothing beyond
--     tomorrow.
--   * Whether a walk-in may join a service at all was not expressible.
--
-- A policy row overrides the clinic default for one queue kind, and
-- optionally for one doctor. NULL means "inherit".
--
-- The row is keyed by kind rather than by queue because queues are created
-- each morning and a policy written on Monday must still apply on Friday.
-- A doctor-level row is allowed because "Dr. Silva only takes same-day
-- bookings" is a real rule in a real clinic.
-- =====================================================================

CREATE TABLE IF NOT EXISTS appointment_policies (
  id                INTEGER PRIMARY KEY AUTO_INCREMENT,
  clinic_id         INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  kind              TEXT NOT NULL,
  doctor_id         INTEGER REFERENCES doctors(id) ON DELETE CASCADE,
  late_grace_minutes INTEGER,
  horizon_days      INTEGER,
  allow_walkins     INTEGER,   -- NULL inherits from the kind row
  is_active         INTEGER NOT NULL DEFAULT 1,
  created_at        TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
  updated_at        TEXT,
  CONSTRAINT ck_policy_kind CHECK (kind IN ('consultation','lab','pharmacy','general')),
  CONSTRAINT ck_policy_grace CHECK (late_grace_minutes IS NULL OR late_grace_minutes BETWEEN 0 AND 240),
  CONSTRAINT ck_policy_horizon CHECK (horizon_days IS NULL OR horizon_days BETWEEN 1 AND 365)
);

-- One policy per clinic per kind per doctor. A NULL doctor_id is the
-- kind-wide row; SQLite treats NULLs as distinct in a unique index, so the
-- kind-wide row is protected by a partial index instead.
CREATE UNIQUE INDEX IF NOT EXISTS ux_policy_doctor
  ON appointment_policies (clinic_id, kind, doctor_id) WHERE doctor_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS ux_policy_kindwide
  ON appointment_policies (clinic_id, kind) WHERE doctor_id IS NULL;

-- ==========================================================================
--  1_0012_incidents.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0012 — incident records
--
-- An incident used to live in three places: a `system_alerts` row that said
-- something was wrong, a paragraph in the on-call runbook telling someone
-- what to do, and whatever the person on call typed into Slack. Nothing
-- joined them, and nothing recorded the decisions taken at 03:00 that make
-- sense afterwards only if somebody wrote them down.
--
-- This is deliberately small and honest:
--
--   * An incident has a severity, a state and a timeline of entries.
--   * The timeline is append-only. Editing an incident log is how an audit
--     trail becomes fiction; a correction is another entry that says so.
--   * A system alert can be attached, so "what was the actual error" is one
--     click from "what did we decide".
--   * Time to acknowledge and time to resolve are computed from the entries,
--     not typed in — a number nobody can check is not a measurement.
--
-- What this is NOT: a paging integration. Somebody still has to notice and
-- open the page. That is a provider contract, not code.
-- =====================================================================

CREATE TABLE IF NOT EXISTS incidents (
  id           INTEGER PRIMARY KEY AUTO_INCREMENT,
  clinic_id    INTEGER REFERENCES clinics(id) ON DELETE CASCADE,  -- NULL = platform-wide
  reference    TEXT NOT NULL,
  title        TEXT NOT NULL,
  severity     TEXT NOT NULL DEFAULT 'minor',
  status       TEXT NOT NULL DEFAULT 'open',
  summary      TEXT,
  detected_at  TEXT NOT NULL,
  acknowledged_at TEXT,
  resolved_at  TEXT,
  created_by   INTEGER,
  created_at   TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
  CONSTRAINT ck_inc_severity CHECK (severity IN ('minor','major','critical')),
  CONSTRAINT ck_inc_status CHECK (status IN ('open','mitigated','resolved'))
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_incident_reference ON incidents (reference);
CREATE INDEX IF NOT EXISTS idx_incident_status ON incidents (status, detected_at);

CREATE TABLE IF NOT EXISTS incident_entries (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  incident_id INTEGER NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
  kind        TEXT NOT NULL DEFAULT 'note',
  body        TEXT NOT NULL,
  actor_id    INTEGER,
  created_at  TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
  CONSTRAINT ck_inc_kind CHECK (kind IN ('note','action','decision','cause','link'))
);
CREATE INDEX IF NOT EXISTS idx_incident_entries ON incident_entries (incident_id, created_at);

-- Which infrastructure alert belongs to which incident, so the timeline can
-- say what the platform was complaining about at the time.
ALTER TABLE system_alerts ADD COLUMN incident_id INTEGER REFERENCES incidents(id) ON DELETE SET NULL;

-- ==========================================================================
--  1_0013_branch_grants.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0013 — per-branch staff scoping
--
-- Until now a user belonged to exactly one clinic (`users.clinic_id`), and
-- an organisation could own several branches. That left a real gap: the
-- manager who runs two branches had two logins, and the owner of a group
-- had no way to let someone work across branches *without* giving them the
-- platform-wide super admin.
--
-- The design keeps every existing query safe by construction:
--
--   * `users.clinic_id` stays the **home branch**. It is not replaced.
--   * These tables are an **additive grant**. A user with no rows here is
--     exactly as scoped as before — this migration changes nothing for
--     anybody who does not opt in.
--   * `Auth::clinicId()` resolves the *active* branch, and only ever returns
--     a branch that is either the user's home or granted in this table. So
--     no controller is "newly opened"; one that forgets to check is still
--     confined to a single clinic, and the switcher cannot be used to reach
--     a clinic the user was not granted.
--
-- Why a table and not a JSON column: revoking one branch must not rewrite a
-- role, and "who can see this branch" has to be answerable in one query for
-- the owner's screen.
-- =====================================================================

CREATE TABLE IF NOT EXISTS branch_grants (
  id         INTEGER PRIMARY KEY AUTO_INCREMENT,
  user_id    INTEGER NOT NULL,
  clinic_id  INTEGER NOT NULL,
  granted_by INTEGER,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id)    REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (clinic_id)  REFERENCES clinics(id) ON DELETE CASCADE,
  UNIQUE KEY ux_branch_grant (user_id, clinic_id),
  KEY idx_branch_grant_clinic (clinic_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================================================
--  1_0014_visit_feedback.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0014 — patient satisfaction, asked at the one moment it is honest
--
-- A satisfaction survey that is emailed a week later measures whether people
-- answer email. The only moment a patient can truthfully rate a visit is
-- while they are still in the building — and the only place this product has
-- their attention then is the tracking link they are already watching.
--
-- Three deliberate constraints, each of which a worse design would drop:
--
--   * **One answer per visit.** The unique index is on `entry_id`, so the
--     tracking page can be refreshed, reloaded and shared without ever
--     producing a second rating. The link is not a survey invitation someone
--     can answer twice to be helpful.
--   * **Only after a completed visit.** The controller refuses a rating on a
--     waiting or called visit: "how was it?" cannot be answered about a
--     consultation that has not happened. Cancelled and no-show visits are
--     also not rated — the patient has nothing to report on the doctor.
--   * **The comment is optional and never required.** A star-only rating is
--     useful; forcing prose loses the people with the least time, who are
--     usually the ones waiting longest.
--
-- Nothing here identifies the patient beyond the queue entry that already
-- exists: the row points at a visit, not at a person, so aggregate reports
-- cannot be joined back into a name by accident.
-- =====================================================================

CREATE TABLE IF NOT EXISTS visit_feedback (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  clinic_id   INTEGER NOT NULL,
  entry_id    INTEGER NOT NULL,
  doctor_id   INTEGER,
  department_id INTEGER,

  -- 1..5. A five-point scale with named ends, because "rate 1 to 10" invites
  -- argument about what a 7 means and produces numbers nobody can act on.
  rating      INTEGER NOT NULL,

  -- Kept apart rather than one blob: "the waiting was fine but the doctor
  -- rushed me" is the sentence an owner can do something about, and it
  -- disappears into an average if it is only a comment.
  wait_rating INTEGER,
  comment     TEXT,

  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  CONSTRAINT ck_feedback_rating CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT ck_feedback_wait CHECK (wait_rating IS NULL OR wait_rating BETWEEN 1 AND 5),
  FOREIGN KEY (clinic_id)   REFERENCES clinics(id) ON DELETE CASCADE,
  FOREIGN KEY (entry_id)    REFERENCES queue_entries(id) ON DELETE CASCADE,
  FOREIGN KEY (doctor_id)   REFERENCES doctors(id) ON DELETE SET NULL,
  FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
  UNIQUE KEY ux_feedback_entry (entry_id),
  KEY idx_feedback_clinic (clinic_id, created_at),
  KEY idx_feedback_doctor (clinic_id, doctor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================================================
--  1_0015_session_start.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0015 — a doctor's own "I am starting now"
--
-- A queue being `open` says the front desk is taking names for that service.
-- It does not say the doctor is in the building. Those are different facts
-- and conflating them produced a real, ordinary failure: reception opens the
-- day at 08:00, the doctor arrives at 09:10, and patients who arrived early
-- are called into an empty room — or, worse, the board says "now serving"
-- for somebody nobody is serving.
--
-- Two nullable timestamps, so the state is derivable rather than stored
-- twice:
--
--   * `started_at` NULL      → the session has not been started by a doctor
--   * `started_at` set       → calls are allowed from that moment
--
-- Deliberately no `ended_at`: ending a session is `closed`, which already
-- exists with an acknowledgement path for unseen patients. Adding a second
-- way to end a day would give the same state two names, which is how a board
-- and a report come to disagree.
--
-- `started_by` records who pressed it. A doctor starting another doctor's
-- session is not the same event as the doctor starting their own, and the
-- audit trail should not have to guess which happened.
-- =====================================================================

ALTER TABLE queues ADD COLUMN started_at TEXT;
ALTER TABLE queues ADD COLUMN started_by INTEGER;

-- Existing open sessions on the day this ships are treated as started, so a
-- live clinic is not locked out by the upgrade at 09:00 on a busy morning.
-- Sessions created later start unstarted and must be started deliberately.
UPDATE queues SET started_at = COALESCE(created_at, NOW()) WHERE status = 'open' AND started_at IS NULL;

CREATE INDEX idx_queue_started ON queues (clinic_id, session_date, started_at);

-- ==========================================================================
--  1_0016_clinic_templates.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0016 — per-clinic message wording
--
-- Every clinic sent exactly the words in NotificationService::TEMPLATES.
-- For a Sinhala-medium clinic in Negombo that is not a limitation, it is a
-- defect: a patient receives a message in a language they may not read,
-- about the one thing they are anxious about.
--
-- Design, and the reasoning that shaped it:
--
--   * **Override, never replace.** A clinic that has edited nothing gets the
--     shipped copy, and a template added in a later release appears for
--     everyone. A clinic that edits one template overrides only that one.
--   * **One row per template, keyed by name.** Values are looked up as a map
--     at send time, so the hot path is one small query per clinic, cached by
--     the request.
--   * **Placeholders are validated on save, not at 03:00.** A template that
--     drops {token} is a message that never says the patient's number; a
--     template with {toke} sends the literal braces to a patient. Both are
--     refused when a person is looking at the form.
--   * **Locale is deliberately absent.** A per-locale template set is a
--     translation workflow with a review step, not a text box. Claiming
--     "multilingual templates" because there is a `locale` column would be
--     the kind of overstatement this document exists to avoid.
-- =====================================================================

CREATE TABLE IF NOT EXISTS notification_templates (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  clinic_id   INTEGER NOT NULL,
  template    TEXT NOT NULL,
  body        TEXT NOT NULL,
  updated_by  INTEGER,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY ux_notification_template (clinic_id, template)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================================================
--  1_0017_offline_writes.mysql
-- ==========================================================================

-- =====================================================================
-- 2026_11_05_0017 — offline writes
--
-- `offline_blocks` records that a queue's ticket numbers A031–A040 were set
-- aside for a desk that may go offline. The numbers are already past
-- `last_token_no`, so nothing online can be issued them; this table exists so
-- a reconciliation can tell a number we reserved apart from one somebody
-- invented.
--
-- `offline_reservations` is the idempotency guard. `reservation` is UNIQUE
-- across the whole table, not just per clinic: a replayed sync that arrives
-- twice — the browser retried, or two tabs woke up — must produce one patient
-- and one ticket, and the database is the only place that can promise that
-- under concurrency.
-- =====================================================================

CREATE TABLE IF NOT EXISTS offline_blocks (
    id          INTEGER PRIMARY KEY AUTO_INCREMENT,
    clinic_id   INTEGER NOT NULL,
    queue_id    INTEGER NOT NULL,
    from_no     INTEGER NOT NULL,
    to_no       INTEGER NOT NULL,
    issued_by   INTEGER,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NOT NULL,
    FOREIGN KEY (clinic_id)   REFERENCES clinics(id) ON DELETE CASCADE,
    FOREIGN KEY (queue_id)    REFERENCES queues(id) ON DELETE CASCADE,
    FOREIGN KEY (issued_by)   REFERENCES users(id) ON DELETE SET NULL,
    INDEX ix_offline_block_window (clinic_id, queue_id, from_no, to_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offline_reservations (
    id          INTEGER PRIMARY KEY AUTO_INCREMENT,
    clinic_id   INTEGER NOT NULL,
    reservation VARCHAR(64) NOT NULL,
    block_id    INTEGER NOT NULL,
    queue_id    INTEGER NOT NULL,
    token_no    INTEGER NOT NULL,
    token_label VARCHAR(16) NOT NULL,
    entry_id    INTEGER NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (clinic_id)   REFERENCES clinics(id) ON DELETE CASCADE,
    FOREIGN KEY (block_id)    REFERENCES offline_blocks(id) ON DELETE CASCADE,
    FOREIGN KEY (queue_id)    REFERENCES queues(id) ON DELETE CASCADE,
    FOREIGN KEY (entry_id)    REFERENCES queue_entries(id) ON DELETE CASCADE,
    UNIQUE KEY ux_offline_reservation (reservation),
    UNIQUE KEY ux_offline_reservation_label (clinic_id, queue_id, token_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================================================
--  1_0018_doctor_queue_prefix.mysql
-- ==========================================================================

-- Per-doctor queue prefix. See 0018_doctor_queue_prefix.sqlite.sql for why.
ALTER TABLE doctors ADD COLUMN queue_prefix VARCHAR(8) NULL AFTER name;

CREATE UNIQUE INDEX ux_doctor_queue_prefix ON doctors (clinic_id, queue_prefix);

-- ==========================================================================
--  1_0019_notification_logs.mysql
-- ==========================================================================

-- Delivery history for notifications.
-- See 0019_notification_logs.sqlite.sql for why this is a separate,
-- append-only table rather than more columns on `notifications`.

CREATE TABLE IF NOT EXISTS notification_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clinic_id       BIGINT UNSIGNED NOT NULL,
    notification_id BIGINT UNSIGNED NOT NULL,
    attempt         INT UNSIGNED NOT NULL,
    channel         VARCHAR(16) NOT NULL,
    outcome         VARCHAR(16) NOT NULL,
    error           VARCHAR(290) NULL,
    created_at      DATETIME NOT NULL,
    CONSTRAINT fk_nlog_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE,
    CONSTRAINT fk_nlog_message FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE,
    INDEX ix_notification_log_message (notification_id, attempt),
    INDEX ix_notification_log_clinic (clinic_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================================================
--  1_0020_subscription_payments.mysql
-- ==========================================================================

-- Subscriptions and payments as first-class records.
-- See 0020_subscription_payments.sqlite.sql for why each needs its own table.

CREATE TABLE IF NOT EXISTS payments (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clinic_id     BIGINT UNSIGNED NOT NULL,
    invoice_id    BIGINT UNSIGNED NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    currency      VARCHAR(8) NOT NULL DEFAULT 'LKR',
    method        VARCHAR(24) NULL,
    reference     VARCHAR(120) NULL,
    status        VARCHAR(16) NOT NULL,
    submitted_by  BIGINT UNSIGNED NULL,
    submitted_at  DATETIME NOT NULL,
    decided_by    BIGINT UNSIGNED NULL,
    decided_at    DATETIME NULL,
    decision_note VARCHAR(280) NULL,
    created_at    DATETIME NOT NULL,
    CONSTRAINT fk_payment_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    INDEX ix_payment_clinic (clinic_id, submitted_at DESC),
    INDEX ix_payment_invoice (invoice_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS subscriptions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clinic_id     BIGINT UNSIGNED NOT NULL,
    plan_id       BIGINT UNSIGNED NULL,
    status        VARCHAR(16) NOT NULL,
    trial_ends_on DATE NULL,
    renews_on     DATE NULL,
    started_at    DATETIME NOT NULL,
    ended_at      DATETIME NULL,
    created_by    BIGINT UNSIGNED NULL,
    note          VARCHAR(280) NULL,
    created_at    DATETIME NOT NULL,
    CONSTRAINT fk_subscription_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscription_plan FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE SET NULL,
    INDEX ix_subscription_clinic (clinic_id, started_at DESC),
    INDEX ix_subscription_open (clinic_id, ended_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================================================
--  1_0021_public_display_keys.mysql
-- ==========================================================================

-- Public display keys. See 0021_public_display_keys.sqlite.sql for why one
-- shared, unnamed, in-place-rotated key is not enough.

CREATE TABLE IF NOT EXISTS public_display_keys (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clinic_id     BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    name          VARCHAR(80) NOT NULL,
    display_key   VARCHAR(64) NOT NULL,
    kind          VARCHAR(24) NOT NULL DEFAULT 'waiting_room',
    created_by    BIGINT UNSIGNED NULL,
    created_at    DATETIME NOT NULL,
    last_used_at  DATETIME NULL,
    revoked_at    DATETIME NULL,
    CONSTRAINT fk_pdk_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE,
    CONSTRAINT fk_pdk_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE,
    UNIQUE KEY ux_public_display_key (display_key),
    INDEX ix_public_display_clinic (clinic_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================================================
--  1_0022_clinic_integrations.mysql
-- ==========================================================================

-- =====================================================================
-- clinic integrations registry
--
-- The PDF's screen map lists "integrations" under Notifications, and the
-- notification section asks for "configurable integrations: SMS, Email,
-- WhatsApp where available". Until now whether a clinic had an integration
-- was answerable only by reading .env — which is the wrong place: it is
-- per-deployment, not per-clinic, and a clinic owner can neither see nor
-- change it.
--
-- So the connection is now a row. The row is the clinic's own record of
-- what it has connected, why, and when. It does NOT replace .env —
-- credentials still live outside the database — it records that a
-- connection exists and lets an owner switch a working integration off
-- without editing a file.
--
-- `status` is 'connected' or 'off'. Disconnecting flips the status rather
-- than deleting the row, so reconnecting an integration the clinic has
-- already configured does not mean retyping its settings.
-- =====================================================================

CREATE TABLE IF NOT EXISTS integrations (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clinic_id    BIGINT UNSIGNED NOT NULL,
    -- Stable machine name from the catalog in IntegrationService, not a
    -- display string. A display name would need translating; a key does not.
    provider     VARCHAR(48) NOT NULL,
    status       VARCHAR(16) NOT NULL DEFAULT 'off',
    -- JSON: non-secret settings only (sender id, account reference, which
    -- branches are in scope). Credentials are deliberately never written
    -- here — see IntegrationService::connect().
    config       TEXT NULL,
    connected_at DATETIME NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ux_integrations_clinic_provider (clinic_id, provider),
    INDEX idx_integrations_clinic (clinic_id),
    CONSTRAINT fk_integrations_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ==========================================================================
--  1_0023_priority_rule_order_vitals.mysql
-- ==========================================================================

-- =====================================================================
-- priority rules: explicit order, vitals, change history
--
-- Twin of 0023_priority_rule_order_vitals.sqlite.sql. Same three changes;
-- only the dialect differs. See the SQLite file for why.
--
-- 1. Order becomes explicit: `sort_order` is a ranked list and the first
--    matching rule in it decides, replacing "highest effect wins". Existing
--    rules are numbered in the order the old engine evaluated them, so the
--    upgrade does not change who gets called next.
-- 2. Temperature becomes a matchable fact, stored as tenths of a degree in
--    an INTEGER so a 39.0 threshold is exact.
-- 3. Rule changes become history, so a priority nobody typed is explainable.
-- =====================================================================

ALTER TABLE priority_rules
  ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0,
  ADD COLUMN min_temp_x10 INTEGER NULL,
  ADD COLUMN max_temp_x10 INTEGER NULL,
  ADD COLUMN updated_at DATETIME NULL;

ALTER TABLE queue_entries ADD COLUMN temp_c_x10 INTEGER NULL;

UPDATE priority_rules pr
  JOIN (
    SELECT p.id,
           (SELECT COUNT(*) FROM priority_rules AS peer
             WHERE peer.clinic_id = p.clinic_id
               AND (peer.effect > p.effect
                    OR (peer.effect = p.effect AND peer.id <= p.id))) AS rank
      FROM priority_rules p
  ) ranked ON ranked.id = pr.id
   SET pr.sort_order = ranked.rank;

UPDATE priority_rules SET updated_at = created_at WHERE updated_at IS NULL;

CREATE INDEX idx_rule_order ON priority_rules (clinic_id, sort_order);

CREATE TABLE IF NOT EXISTS priority_rule_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  clinic_id   BIGINT UNSIGNED NOT NULL,
  rule_id     BIGINT UNSIGNED NULL,        -- no FK: history outlives the rule
  rule_name   VARCHAR(120) NOT NULL,       -- named even after deletion
  action      VARCHAR(16)  NOT NULL,
  actor_id    BIGINT UNSIGNED NULL,
  detail      TEXT NULL,
  before_json TEXT NULL,
  after_json  TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rule_history_clinic (clinic_id, id),
  CONSTRAINT ck_rule_history_action CHECK (
    action IN ('created','updated','reordered','enabled','disabled','deleted')
  ),
  CONSTRAINT fk_rule_history_clinic FOREIGN KEY (clinic_id)
    REFERENCES clinics (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ==========================================================================
--  1_0024_clinic_approval.mysql
-- ==========================================================================

-- =====================================================================
-- 0024 — clinic approval lifecycle
--
-- Twin of 0024_clinic_approval.sqlite.sql. Same four columns and two
-- indexes; only the dialect differs. See the SQLite file for why.
-- =====================================================================

ALTER TABLE clinics
  ADD COLUMN approved_at DATETIME NULL,
  ADD COLUMN approved_by INTEGER NULL,
  ADD COLUMN declined_at DATETIME NULL,
  ADD COLUMN decision_reason TEXT NULL;

CREATE INDEX idx_clinics_billing_status ON clinics (billing_status);
CREATE INDEX idx_clinics_trial_ends ON clinics (trial_ends_on);

-- ==========================================================================
--  1_0025_tenant_hosts.mysql
-- ==========================================================================

-- =====================================================================
-- 0025 — per-clinic hosts, ops URL, dunning grace, billing cycle
--
-- Twin of 0025_tenant_hosts.sqlite.sql. Same columns; only the dialect
-- differs, plus MySQL needs a prefix on the VARCHAR collation of the
-- unique host columns so a trailing-dot or case variant cannot create a
-- duplicate tenant.
-- =====================================================================

ALTER TABLE clinics
  ADD COLUMN site_host VARCHAR(190) NULL,
  ADD COLUMN ops_host VARCHAR(190) NULL,
  ADD COLUMN ops_code VARCHAR(64) NULL,
  ADD COLUMN grace_until DATETIME NULL,
  ADD COLUMN billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly';

CREATE UNIQUE INDEX idx_clinics_site_host ON clinics (site_host);
CREATE UNIQUE INDEX idx_clinics_ops_host ON clinics (ops_host);
CREATE INDEX idx_clinics_grace_until ON clinics (grace_until);

ALTER TABLE invoices
  ADD COLUMN billing_mode ENUM('arrears','advance') NOT NULL DEFAULT 'arrears';

CREATE INDEX idx_invoices_status ON invoices (status);

ALTER TABLE subscriptions
  ADD COLUMN cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly';

-- ==========================================================================
--  1_0026_welcome_and_closure.mysql
-- ==========================================================================

-- =====================================================================
-- 0026 — welcome links, explicit closure approval, yearly pricing
--
-- Twin of 0026_welcome_and_closure.sqlite.sql. See that file for why.
-- =====================================================================

ALTER TABLE users
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE clinics
  ADD COLUMN purge_approved_at DATETIME NULL,
  ADD COLUMN purge_approved_by INTEGER NULL;

ALTER TABLE plans
  ADD COLUMN price_yearly DECIMAL(12,2) NULL,
  ADD COLUMN yearly_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 20;

CREATE INDEX idx_clinics_purge_due ON clinics (purge_after, purge_approved_at);

-- ==========================================================================
--  1_0027_billing_identity.mysql
-- ==========================================================================

-- See the SQLite file for the reasoning. Same columns, MySQL spellings.

ALTER TABLE clinics ADD COLUMN tax_id VARCHAR(64) NULL;
ALTER TABLE clinics ADD COLUMN billing_email VARCHAR(160) NULL;
ALTER TABLE clinics ADD COLUMN po_number VARCHAR(64) NULL;

CREATE INDEX idx_clinics_po ON clinics (po_number);

-- ==========================================================================
--  1_0028_platform_notifications.mysql
-- ==========================================================================

-- Platform-level notices for the people who run the service.
--
-- `staff_notifications` cannot hold these: it has a NOT NULL foreign key to
-- `clinics(id)`, and a platform admin sits outside every clinic. Reusing the
-- table with a borrowed clinic id would file "a clinic applied" under some
-- unrelated tenant and leak it into that clinic's bell.
--
-- Two events, and both are decisions that cannot be made by software:
--   apply    a clinic is waiting for approval or a trial
--   claim    a clinic says it paid; somebody has to check the bank
--
-- Rows are addressed to a user, or to user_id NULL for every platform admin.
CREATE TABLE IF NOT EXISTS platform_notifications (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NULL,
  kind       VARCHAR(20) NOT NULL DEFAULT 'info',
  title      VARCHAR(200) NOT NULL,
  body       VARCHAR(500) NULL,
  link       VARCHAR(255) NULL,
  read_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pnotice_user (user_id, read_at),
  CONSTRAINT fk_pnotice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ---------------------------------------------------------------- plans
-- The four subscription plans. Yearly prices are the monthly rate times
-- twelve less 20%, which is the discount the pricing page advertises.
-- 'enterprise' is priced on application, so 0 is deliberate.
INSERT INTO `plans`
  (`code`, `name`, `price_monthly`, `currency`, `max_doctors`, `max_staff`,
   `max_queues`, `features`, `sort_order`, `is_active`, `price_yearly`,
   `yearly_discount_pct`, `created_at`)
VALUES
  ('starter', 'Starter', 3500, 'LKR', 1, 2, 2, 'Single doctor, one queue, live tracking links', 1, 1, 33600, 20, NOW()),
  ('professional', 'Professional', 7900, 'LKR', 5, 8, 6, 'Multi-doctor, appointments, SMS credits, reports', 2, 1, 75840, 20, NOW()),
  ('clinic_plus', 'Clinic Plus', 14900, 'LKR', 15, 25, 20, 'Priority support, WhatsApp, custom branding, display screens', 3, 1, 143040, 20, NOW()),
  ('enterprise', 'Enterprise', 0, 'LKR', 999, 999, 999, 'Multi-branch, SSO, dedicated onboarding, API access', 4, 1, 0, 20, NOW())
ON DUPLICATE KEY UPDATE
  `name` = VALUES(`name`), `price_monthly` = VALUES(`price_monthly`),
  `price_yearly` = VALUES(`price_yearly`), `max_doctors` = VALUES(`max_doctors`),
  `max_staff` = VALUES(`max_staff`), `max_queues` = VALUES(`max_queues`),
  `features` = VALUES(`features`), `sort_order` = VALUES(`sort_order`);

-- --------------------------------------------------- migration bookkeeping
-- tools/migrate.php reads this to decide what is already applied. Without
-- these rows it re-applies every migration on the next deploy and dies on
-- the first duplicate column.
CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `migration` VARCHAR(191) NOT NULL,
  `applied_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ux_schema_migrations` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `schema_migrations` (`migration`, `applied_at`) VALUES
  ('0000_schema', NOW()),
  ('1_0001_queue_integrity.mysql', NOW()),
  ('1_0002_delays_and_online_booking.mysql', NOW()),
  ('1_0003_operational_depth.mysql', NOW()),
  ('1_0004_password_resets.mysql', NOW()),
  ('1_0005_structure.mysql', NOW()),
  ('1_0005b_invoice_verification.mysql', NOW()),
  ('1_0006_email_verification.mysql', NOW()),
  ('1_0006_feature_gate.mysql', NOW()),
  ('1_0007_invoice_periods.mysql', NOW()),
  ('1_0008_breaks.mysql', NOW()),
  ('1_0009_priority_rules.mysql', NOW()),
  ('1_0010_service_workflows.mysql', NOW()),
  ('1_0011_appointment_policies.mysql', NOW()),
  ('1_0012_incidents.mysql', NOW()),
  ('1_0013_branch_grants.mysql', NOW()),
  ('1_0014_visit_feedback.mysql', NOW()),
  ('1_0015_session_start.mysql', NOW()),
  ('1_0016_clinic_templates.mysql', NOW()),
  ('1_0017_offline_writes.mysql', NOW()),
  ('1_0018_doctor_queue_prefix.mysql', NOW()),
  ('1_0019_notification_logs.mysql', NOW()),
  ('1_0020_subscription_payments.mysql', NOW()),
  ('1_0021_public_display_keys.mysql', NOW()),
  ('1_0022_clinic_integrations.mysql', NOW()),
  ('1_0023_priority_rule_order_vitals.mysql', NOW()),
  ('1_0024_clinic_approval.mysql', NOW()),
  ('1_0025_tenant_hosts.mysql', NOW()),
  ('1_0026_welcome_and_closure.mysql', NOW()),
  ('1_0027_billing_identity.mysql', NOW()),
  ('1_0028_platform_notifications.mysql', NOW())
ON DUPLICATE KEY UPDATE `migration` = VALUES(`migration`);


-- ============================================================================
--  Done. Verify:
--
--    SELECT COUNT(*) FROM information_schema.tables
--     WHERE table_schema = DATABASE();     -- expect 52
--    SELECT COUNT(*) FROM plans;           -- expect 4
--    SELECT COUNT(*) FROM schema_migrations;  -- expect 31
--
--  Then create the platform admin:
--    php tools/make-admin.php --email=you@example.com
--
--  And check the host is configured correctly:
--    php tools/host-drill.php
-- ============================================================================

SET SQL_MODE = @OLD_SQL_MODE;