-- Per-clinic hostnames, an unguessable operations URL, and a real dunning clock.
--
-- Until now one deployment served every clinic on one host, and `APP_URL` was
-- a single value. Clinics now get their own subdomains:
--
--   <slug>.queue.lk          the patient-facing display and tracking site
--   <ops_slug>.queue.lk      the staff backend, on an unguessable path
--
-- Two consequences that matter more than they look:
--
-- 1. The hostname becomes part of authentication. A session established on
--    one clinic's host must not be usable on another's, so the session cookie
--    is scoped per clinic elsewhere; here we only record which host is whose.
-- 2. Nothing may be derived from the request host without a matching row.
--    An unknown host is a 404, not a fallback to the first clinic.
--
-- `grace_until` is the five-day warning window before suspension. Storing the
-- date rather than counting days means the rule survives missed cron runs: if
-- the invoice job does not run on Monday, a clinic does not get an extra week.

ALTER TABLE clinics ADD COLUMN site_host TEXT NULL;
ALTER TABLE clinics ADD COLUMN ops_host TEXT NULL;
ALTER TABLE clinics ADD COLUMN ops_code TEXT NULL;
ALTER TABLE clinics ADD COLUMN grace_until TEXT NULL;
ALTER TABLE clinics ADD COLUMN billing_cycle TEXT NOT NULL DEFAULT 'monthly';

-- Both hostnames are lookup keys on every single request, and both must be
-- unique: two clinics sharing a host is a data leak, not a configuration error.
CREATE UNIQUE INDEX IF NOT EXISTS idx_clinics_site_host ON clinics (site_host);
CREATE UNIQUE INDEX IF NOT EXISTS idx_clinics_ops_host ON clinics (ops_host);
CREATE INDEX IF NOT EXISTS idx_clinics_grace_until ON clinics (grace_until);

-- Invoices are now raised monthly in advance, so the period is the month being
-- paid for rather than the month just finished. Existing rows keep their
-- meaning; the flag lets the two conventions coexist during the changeover.
ALTER TABLE invoices ADD COLUMN billing_mode TEXT NOT NULL DEFAULT 'arrears';
CREATE INDEX IF NOT EXISTS idx_invoices_status ON invoices (status);

-- A yearly payer should not be invoiced twelve times. An annual invoice covers
-- twelve months in one period, so the period bounds must be able to express it.
ALTER TABLE subscriptions ADD COLUMN cycle TEXT NOT NULL DEFAULT 'monthly';
