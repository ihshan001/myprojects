-- =====================================================================
-- 0026 — welcome links, explicit closure approval, yearly pricing
--
-- Twin of 0026_welcome_and_closure.sqlite.sql. See that file for why.
-- =====================================================================

ALTER TABLE users
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0;

ALTER TABLE clinics
  ADD COLUMN purge_approved_at DATETIME NULL,
  ADD COLUMN purge_approved_by INTEGER NULL;

ALTER TABLE plans
  ADD COLUMN price_yearly DECIMAL(12,2) NULL,
  ADD COLUMN yearly_discount_pct DECIMAL(5,2) NOT NULL DEFAULT 20;

CREATE INDEX idx_clinics_purge_due ON clinics (purge_after, purge_approved_at);
