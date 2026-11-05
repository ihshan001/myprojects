-- =====================================================================
-- 2026_11_05_0005b — payment verification (MySQL 8.0 variant)
-- =====================================================================

ALTER TABLE invoices
    ADD COLUMN claimed_at DATETIME NULL,
    ADD COLUMN verified_at DATETIME NULL,
    ADD COLUMN verified_by INT UNSIGNED NULL,
    ADD COLUMN rejection_reason VARCHAR(255) NULL,
    ADD CONSTRAINT fk_invoice_verifier FOREIGN KEY (verified_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE INDEX idx_invoice_status ON invoices (status, claimed_at);

UPDATE invoices SET verified_at = COALESCE(paid_at, created_at)
 WHERE status = 'paid' AND verified_at IS NULL;
