-- =====================================================================
-- 2026_11_05_0007 — one invoice per clinic per billing period (MySQL)
--
-- MySQL has no partial indexes. The equivalent guard is a generated column
-- that is NULL for cancelled invoices, with a unique index over it: NULLs do
-- not collide in a MySQL unique index, so cancelled rows are exempt and live
-- rows are not. See the SQLite file for the reasoning.
-- =====================================================================

ALTER TABLE invoices
  ADD COLUMN period_guard VARCHAR(10)
    GENERATED ALWAYS AS (IF(status IN ('cancelled','void'), NULL, period_from)) STORED;

CREATE UNIQUE INDEX uq_invoice_period ON invoices (clinic_id, period_guard);
