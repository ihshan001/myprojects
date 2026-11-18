-- Who the invoice is addressed to, and what it says about tax.
--
-- A clinic's accountant needs the invoice in the *business's* name with its
-- registration number — not the doctor's name from the signup form — and a
-- purchase-order reference is what makes it payable inside a hospital's
-- accounts process. None of that is patient data; all of it is needed before
-- the first invoice can be filed.
--
-- legal_name and address_line already exist on clinics; these are the three
-- that were missing.

ALTER TABLE clinics ADD COLUMN tax_id TEXT;
ALTER TABLE clinics ADD COLUMN billing_email TEXT;
ALTER TABLE clinics ADD COLUMN po_number TEXT;

CREATE INDEX IF NOT EXISTS idx_clinics_po ON clinics (po_number);
