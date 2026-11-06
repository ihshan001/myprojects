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
