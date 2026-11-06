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
