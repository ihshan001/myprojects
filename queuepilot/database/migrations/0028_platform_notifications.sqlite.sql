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
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
  kind       TEXT NOT NULL DEFAULT 'info',
  title      TEXT NOT NULL,
  body       TEXT,
  link       TEXT,
  read_at    TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_pnotice_user ON platform_notifications (user_id, read_at);
