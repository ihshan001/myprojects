-- =====================================================================
-- 2026_11_05_0015 — a doctor's own "I am starting now"
--
-- A queue being `open` says the front desk is taking names for that service.
-- It does not say the doctor is in the building. Those are different facts
-- and conflating them produced a real, ordinary failure: reception opens the
-- day at 08:00, the doctor arrives at 09:10, and patients who arrived early
-- are called into an empty room — or, worse, the board says "now serving"
-- for somebody nobody is serving.
--
-- Two nullable timestamps, so the state is derivable rather than stored
-- twice:
--
--   * `started_at` NULL      → the session has not been started by a doctor
--   * `started_at` set       → calls are allowed from that moment
--
-- Deliberately no `ended_at`: ending a session is `closed`, which already
-- exists with an acknowledgement path for unseen patients. Adding a second
-- way to end a day would give the same state two names, which is how a board
-- and a report come to disagree.
--
-- `started_by` records who pressed it. A doctor starting another doctor's
-- session is not the same event as the doctor starting their own, and the
-- audit trail should not have to guess which happened.
-- =====================================================================

ALTER TABLE queues ADD COLUMN started_at TEXT;
ALTER TABLE queues ADD COLUMN started_by INTEGER;

-- Existing open sessions on the day this ships are treated as started, so a
-- live clinic is not locked out by the upgrade at 09:00 on a busy morning.
-- Sessions created later start unstarted and must be started deliberately.
UPDATE queues SET started_at = COALESCE(created_at, datetime('now')) WHERE status = 'open' AND started_at IS NULL;

CREATE INDEX idx_queue_started ON queues (clinic_id, session_date, started_at);
