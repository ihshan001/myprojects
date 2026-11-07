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
