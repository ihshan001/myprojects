-- See the SQLite file for the reasoning. Same columns, MySQL spellings.

ALTER TABLE clinics ADD COLUMN tax_id VARCHAR(64) NULL;
ALTER TABLE clinics ADD COLUMN billing_email VARCHAR(160) NULL;
ALTER TABLE clinics ADD COLUMN po_number VARCHAR(64) NULL;

CREATE INDEX idx_clinics_po ON clinics (po_number);
