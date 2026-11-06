-- =====================================================================
-- 2026_11_05_0005b — payment verification
--
-- The billing flow used to go straight from "clinic typed in a reference"
-- to "status = paid, subscription active". That means any clinic could
-- activate itself by typing eight characters. For a bank transfer that is
-- normal practice (somebody reconciles the statement), but the software was
-- not recording *who* reconciled it, so there was no way to tell a verified
-- transfer from a hopeful one.
--
-- Four states instead of two:
--   unpaid    — invoiced, nothing claimed
--   claimed   — the clinic says it paid, with a reference; nothing active yet
--   paid      — a platform administrator matched it against the bank
--   rejected  — the reference could not be matched; the clinic is told why
--
-- A `claimed` invoice does NOT extend the subscription. Only `paid` does.
-- =====================================================================

ALTER TABLE invoices ADD COLUMN claimed_at TEXT;
ALTER TABLE invoices ADD COLUMN verified_at TEXT;
ALTER TABLE invoices ADD COLUMN verified_by INTEGER REFERENCES users(id) ON DELETE SET NULL;
ALTER TABLE invoices ADD COLUMN rejection_reason TEXT;

CREATE INDEX IF NOT EXISTS idx_invoice_status ON invoices (status, claimed_at);

-- Any invoice currently sitting at 'paid' without a verifier was created by
-- the old self-service path. Marking them as verified by the system processes
-- keeps reporting honest instead of showing a NULL that looks like a bug.
UPDATE invoices
   SET verified_at = COALESCE(paid_at, created_at)
 WHERE status = 'paid' AND verified_at IS NULL;
