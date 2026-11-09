-- Per-doctor queue prefix.
--
-- A clinic runs one queue per consulting doctor, and the token labels are how
-- the waiting room tells them apart: A012 is Dr. Amara, B012 is Dr. Nuwan. The
-- prefix used to be derived from the doctor's name at queue-creation time, and
-- because every doctor is stored as "Dr. <name>", every queue in a clinic ended
-- up sharing the prefix "D" — five queues all issuing D001.
--
-- The prefix is therefore a first-class property of the doctor, allocated once
-- and reused for every session the doctor ever runs. Labels must stay stable:
-- a printed ticket references a prefix, and a display screen must not silently
-- renumber a queue under a patient who is already holding a slip.

ALTER TABLE doctors ADD COLUMN queue_prefix TEXT NULL;

CREATE UNIQUE INDEX ux_doctor_queue_prefix ON doctors(clinic_id, queue_prefix);
