-- Per-doctor queue prefix. See 0018_doctor_queue_prefix.sqlite.sql for why.
ALTER TABLE doctors ADD COLUMN queue_prefix VARCHAR(8) NULL AFTER name;

CREATE UNIQUE INDEX ux_doctor_queue_prefix ON doctors (clinic_id, queue_prefix);
