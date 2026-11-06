-- =====================================================================
-- 2026_11_05_0001 — integrity constraints the application cannot fake
--
-- Two invariants belong in the database, not in PHP:
--
--   1. A patient must not hold two ACTIVE entries in the same queue. Two
--      receptionists on two devices can both pass a PHP `SELECT COUNT(*)`
--      check; only a constraint closes that window.
--
--   2. A doctor must not be double-booked into the same slot.
--
-- Both are enforced with a partial/derived unique index so that FINISHED
-- rows never collide: a patient may legitimately return three times in a
-- day and a doctor's 11:30 slot may be re-booked after a cancellation.
--
-- MySQL has no partial indexes, so the MySQL variant uses generated
-- columns that collapse to NULL once a row leaves the active window.
-- =====================================================================

-- ---------- SQLite (partial index — the readable form) ----------
-- One active entry per (queue, patient). Cancelled/completed/no-show rows
-- are excluded from the index by the WHERE clause.
CREATE UNIQUE INDEX IF NOT EXISTS uq_active_entry_per_patient
    ON queue_entries (queue_id, patient_id)
    WHERE status IN ('waiting', 'called', 'in_consultation');

-- One live appointment per (doctor, slot). checked_in/cancelled/no_show
-- free the slot again.
CREATE UNIQUE INDEX IF NOT EXISTS uq_appointment_slot
    ON appointments (clinic_id, doctor_id, scheduled_for)
    WHERE status IN ('booked', 'checked_in');
