-- =====================================================================
-- 2026_11_05_0006 — email verification
--
-- Until now a clinic became live the moment somebody typed an address into
-- the registration form. Nothing stopped a person from registering a
-- competitor's domain, and nothing proved the owner could receive mail at
-- the address we would later send invoices to.
--
-- The rule this implements: a newly registered clinic can sign in and look
-- around, but it cannot issue tokens to patients until the owner has proved
-- the address. That is the smallest restriction that makes verification
-- mean something without leaving a real clinic unable to start its day if
-- the mail server is down — a super admin can vouch for a clinic by hand.
--
-- Only a SHA-256 hash is stored, same as password resets. The plaintext
-- exists in the email and nowhere else.
-- =====================================================================

ALTER TABLE users ADD COLUMN email_verified_at TEXT;
ALTER TABLE users ADD COLUMN verification_sent_at TEXT;

-- Existing accounts predate the check. Marking them verified would be a lie,
-- but leaving them null would lock every demo account out of issuing tokens
-- on upgrade. A verified stamp is the honest reading: these accounts were
-- created when possession of the address was not being tested, and the
-- operator chose to continue trusting them.
UPDATE users SET email_verified_at = created_at WHERE email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS email_verifications (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id      INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash   TEXT NOT NULL UNIQUE,
  expires_at   TEXT NOT NULL,
  used_at      TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_verify_user ON email_verifications (user_id, used_at);
CREATE INDEX IF NOT EXISTS idx_verify_expiry ON email_verifications (expires_at);

-- A clinic whose owner has not verified. Derived, not denormalised: two
-- copies of one fact drift apart.
CREATE VIEW IF NOT EXISTS unverified_clinics AS
SELECT c.id AS clinic_id, c.name AS clinic_name, u.id AS user_id, u.email, u.created_at
  FROM clinics c
  JOIN users u ON u.clinic_id = c.id AND u.role = 'clinic_admin'
 WHERE u.email_verified_at IS NULL AND u.is_active = 1;
