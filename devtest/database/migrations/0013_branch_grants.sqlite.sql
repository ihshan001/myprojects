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
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  clinic_id  INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  granted_by INTEGER,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_branch_grant ON branch_grants (user_id, clinic_id);
CREATE INDEX IF NOT EXISTS idx_branch_grant_clinic ON branch_grants (clinic_id);
