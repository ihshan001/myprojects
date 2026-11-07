-- =====================================================================
-- 2026_11_05_0001 — integrity constraints the application cannot fake
-- (MySQL 8.0 variant)
--
-- MySQL has no partial indexes, so the same invariants are expressed with
-- generated columns that collapse to NULL once a row leaves the active
-- window. MySQL permits many NULLs in a UNIQUE index, which is exactly the
-- behaviour needed: finished rows never collide, live rows always do.
--
--   1. A patient must not hold two ACTIVE entries in the same queue.
--   2. A doctor must not be double-booked into the same slot.
-- =====================================================================

ALTER TABLE queue_entries
    ADD COLUMN active_patient_key BIGINT
        GENERATED ALWAYS AS (
            CASE WHEN status IN ('waiting', 'called', 'in_consultation')
                 THEN patient_id ELSE NULL END
        ) STORED,
    ADD UNIQUE KEY uq_active_entry_per_patient (queue_id, active_patient_key);

ALTER TABLE appointments
    ADD COLUMN live_slot_key DATETIME
        GENERATED ALWAYS AS (
            CASE WHEN status IN ('booked', 'checked_in')
                 THEN scheduled_for ELSE NULL END
        ) STORED,
    ADD UNIQUE KEY uq_appointment_slot (clinic_id, doctor_id, live_slot_key);
