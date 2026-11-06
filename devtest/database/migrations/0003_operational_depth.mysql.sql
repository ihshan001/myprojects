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
