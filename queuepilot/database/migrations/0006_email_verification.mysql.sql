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
