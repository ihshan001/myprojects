-- =====================================================================
-- 0025 — per-clinic hosts, ops URL, dunning grace, billing cycle
--
-- Twin of 0025_tenant_hosts.sqlite.sql. Same columns; only the dialect
-- differs, plus MySQL needs a prefix on the VARCHAR collation of the
-- unique host columns so a trailing-dot or case variant cannot create a
-- duplicate tenant.
-- =====================================================================

ALTER TABLE clinics
  ADD COLUMN site_host VARCHAR(190) NULL,
  ADD COLUMN ops_host VARCHAR(190) NULL,
  ADD COLUMN ops_code VARCHAR(64) NULL,
  ADD COLUMN grace_until DATETIME NULL,
  ADD COLUMN billing_cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly';

CREATE UNIQUE INDEX idx_clinics_site_host ON clinics (site_host);
CREATE UNIQUE INDEX idx_clinics_ops_host ON clinics (ops_host);
CREATE INDEX idx_clinics_grace_until ON clinics (grace_until);

ALTER TABLE invoices
  ADD COLUMN billing_mode ENUM('arrears','advance') NOT NULL DEFAULT 'arrears';

CREATE INDEX idx_invoices_status ON invoices (status);

ALTER TABLE subscriptions
  ADD COLUMN cycle ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly';
