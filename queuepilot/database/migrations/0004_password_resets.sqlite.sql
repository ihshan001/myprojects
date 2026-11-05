-- =====================================================================
-- 2026_11_05_0004 — password recovery
--
-- Self-service reset is a prerequisite for a real SaaS: locking a clinic
-- owner out of their workspace because they forgot a password is not a
-- support process, it is an outage.
--
-- The token is stored only as a SHA-256 hash. The plaintext exists in the
-- email and nowhere else, so a database dump cannot be replayed into an
-- account — the same rule already applied to staff invitations.
-- =====================================================================

CREATE TABLE IF NOT EXISTS password_resets (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash   TEXT NOT NULL UNIQUE,
  expires_at   TEXT NOT NULL,
  used_at      TEXT,
  requested_ip TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_reset_user ON password_resets (user_id, used_at);
CREATE INDEX IF NOT EXISTS idx_reset_expiry ON password_resets (expires_at);
