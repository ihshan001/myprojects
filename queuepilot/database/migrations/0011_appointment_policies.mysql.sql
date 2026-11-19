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
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id          INT UNSIGNED NOT NULL,
  kind               VARCHAR(16) NOT NULL,
  doctor_id          INT UNSIGNED NULL,
  late_grace_minutes SMALLINT UNSIGNED NULL,
  horizon_days       SMALLINT UNSIGNED NULL,
  allow_walkins      TINYINT(1) NULL,   -- NULL inherits from the kind row
  is_active          TINYINT(1) NOT NULL DEFAULT 1,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NULL,
  -- One policy per doctor per kind, and one kind-wide row per kind. The two
  -- guards used to be partial unique indexes (`... WHERE doctor_id IS NOT
  -- NULL`), which MySQL does not have. MySQL treats NULLs as distinct in a
  -- UNIQUE key, so (clinic_id, kind, doctor_id) already allows any number of
  -- kind-wide rows — and the second guard is what stops that. The generated
  -- column collapses "doctor_id is NULL" to a constant so the key can cover it.
  kindwide_key       TINYINT
                     GENERATED ALWAYS AS (IF(doctor_id IS NULL, 1, NULL)) STORED,
  UNIQUE KEY ux_policy_doctor (clinic_id, kind, doctor_id),
  UNIQUE KEY ux_policy_kindwide (clinic_id, kind, kindwide_key),
  CONSTRAINT ck_policy_kind CHECK (kind IN ('consultation','lab','pharmacy','general')),
  CONSTRAINT ck_policy_grace CHECK (late_grace_minutes IS NULL OR late_grace_minutes BETWEEN 0 AND 240),
  CONSTRAINT ck_policy_horizon CHECK (horizon_days IS NULL OR horizon_days BETWEEN 1 AND 365),
  CONSTRAINT fk_policy_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE,
  CONSTRAINT fk_policy_doctor FOREIGN KEY (doctor_id) REFERENCES doctors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
