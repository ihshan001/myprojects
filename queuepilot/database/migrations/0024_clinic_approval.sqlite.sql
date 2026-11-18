-- Signup no longer grants access.
--
-- Registering a clinic used to write billing_status='trial' and hand the owner
-- a working session immediately, so the platform team had no way to say no: a
-- stranger could register and put real patients in a queue in the same minute.
-- The lifecycle now starts at 'pending', which the front controller refuses to
-- serve, and only a platform decision moves it forward.
--
-- approved_at / declined_at / decision_reason exist so the admin queue can
-- show how long somebody has been waiting and why they were turned away.
-- trial_ends_on already existed but nothing ever compared it to today, so an
-- expired trial kept working forever; tools/trials.php now closes that loop.

ALTER TABLE clinics ADD COLUMN approved_at TEXT NULL;
ALTER TABLE clinics ADD COLUMN approved_by INTEGER NULL;
ALTER TABLE clinics ADD COLUMN declined_at TEXT NULL;
ALTER TABLE clinics ADD COLUMN decision_reason TEXT NULL;

-- A pending clinic is invisible to the display-key and invoice sweeps until it
-- is approved, so the queue reads this often.
CREATE INDEX IF NOT EXISTS idx_clinics_billing_status ON clinics (billing_status);

-- Support needs to answer "what is this clinic waiting on?" in one query, and
-- "which trials lapse this week?" from cron.
CREATE INDEX IF NOT EXISTS idx_clinics_trial_ends ON clinics (trial_ends_on);
