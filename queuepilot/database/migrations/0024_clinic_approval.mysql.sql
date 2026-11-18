-- =====================================================================
-- 0024 — clinic approval lifecycle
--
-- Twin of 0024_clinic_approval.sqlite.sql. Same four columns and two
-- indexes; only the dialect differs. See the SQLite file for why.
-- =====================================================================

ALTER TABLE clinics
  ADD COLUMN approved_at DATETIME NULL,
  ADD COLUMN approved_by INTEGER NULL,
  ADD COLUMN declined_at DATETIME NULL,
  ADD COLUMN decision_reason TEXT NULL;

CREATE INDEX idx_clinics_billing_status ON clinics (billing_status);
CREATE INDEX idx_clinics_trial_ends ON clinics (trial_ends_on);
