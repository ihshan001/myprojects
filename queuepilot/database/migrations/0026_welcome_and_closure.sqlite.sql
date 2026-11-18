-- Welcome links, explicit closure approval, and a yearly discount.
--
-- Two things this fixes.
--
-- 1. **A password should never travel by email.** The plan was to send the
--    owner a generated password and tell them to change it, which is the
--    weakest possible start: the password sits in an inbox forever, in a
--    provider's logs, and in whatever the clinic forwards it to. Instead the
--    owner receives a single-use link and chooses their own password, which
--    is both safer and less work for them. `must_change_password` exists for
--    the case where a password *is* issued by hand over the phone.
--
-- 2. **A closed clinic was being deleted on a timer.** `requestClosure()` set
--    purge_after = +90 days and `tools/purge.php` deleted whatever was due,
--    with nobody spoken to in between. The owner's instruction is the
--    opposite: after 90 days the clinic is *contacted* and a person decides.
--    `closed_at` therefore no longer authorises deletion on its own — a purge
--    requires `purge_approved_at`, which only a human sets.

ALTER TABLE users ADD COLUMN must_change_password INTEGER NOT NULL DEFAULT 0;

ALTER TABLE clinics ADD COLUMN purge_approved_at TEXT NULL;
ALTER TABLE clinics ADD COLUMN purge_approved_by INTEGER NULL;

-- Instalment pricing. Storing the yearly price rather than a percentage keeps
-- the invoice arithmetic in one place and lets a plan be priced differently
-- year to year; `yearly_discount_pct` exists so the admin screen can explain
-- the default rather than presenting a bare number.
ALTER TABLE plans ADD COLUMN price_yearly REAL NULL;
ALTER TABLE plans ADD COLUMN yearly_discount_pct REAL NOT NULL DEFAULT 20;

CREATE INDEX IF NOT EXISTS idx_clinics_purge_due ON clinics (purge_after, purge_approved_at);
