-- =====================================================================
-- 2026_11_05_0011 — per-service appointment policy
--
-- Three policies already existed and were all clinic-wide or absent:
--
--   * Late grace was one number on the clinic row. A laboratory can accept
--     a sample thirty minutes after the booked time because the bench is
--     still running; a fifteen-minute consultation is genuinely gone after
--     twenty. One number cannot describe both.
--   * The booking horizon was a global setting (default 30 days). A physio
--     course is booked six weeks out; a flu clinic wants nothing beyond
--     tomorrow.
--   * Whether a walk-in may join a service at all was not expressible.
--
-- A policy row overrides the clinic default for one queue kind, and
-- optionally for one doctor. NULL means "inherit".
--
-- The row is keyed by kind rather than by queue because queues are created
-- each morning and a policy written on Monday must still apply on Friday.
-- A doctor-level row is allowed because "Dr. Silva only takes same-day
-- bookings" is a real rule in a real clinic.
-- =====================================================================

CREATE TABLE IF NOT EXISTS appointment_policies (
  id                INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id         INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  kind              TEXT NOT NULL,
  doctor_id         INTEGER REFERENCES doctors(id) ON DELETE CASCADE,
  late_grace_minutes INTEGER,
  horizon_days      INTEGER,
  allow_walkins     INTEGER,   -- NULL inherits from the kind row
  is_active         INTEGER NOT NULL DEFAULT 1,
  created_at        TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at        TEXT,
  CONSTRAINT ck_policy_kind CHECK (kind IN ('consultation','lab','pharmacy','general')),
  CONSTRAINT ck_policy_grace CHECK (late_grace_minutes IS NULL OR late_grace_minutes BETWEEN 0 AND 240),
  CONSTRAINT ck_policy_horizon CHECK (horizon_days IS NULL OR horizon_days BETWEEN 1 AND 365)
);

-- One policy per clinic per kind per doctor. A NULL doctor_id is the
-- kind-wide row; SQLite treats NULLs as distinct in a unique index, so the
-- kind-wide row is protected by a partial index instead.
CREATE UNIQUE INDEX IF NOT EXISTS ux_policy_doctor
  ON appointment_policies (clinic_id, kind, doctor_id) WHERE doctor_id IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS ux_policy_kindwide
  ON appointment_policies (clinic_id, kind) WHERE doctor_id IS NULL;
