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
