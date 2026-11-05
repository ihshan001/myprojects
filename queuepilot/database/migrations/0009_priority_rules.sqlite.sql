-- =====================================================================
-- 2026_11_05_0009 — priority rules
--
-- Priority was one thing: a clinician pressing "flag urgent" on a single
-- entry and typing a reason. That is the right escape hatch and it is the
-- wrong answer to "this clinic always sees children under five ahead of
-- adults, in the morning only, for two of its doctors". A rule is written
-- once and applies to every arrival afterwards.
--
-- The shape is deliberately small. A rule matches on facts the token issue
-- path already knows — source (appointment or walk-in), patient age, queue
-- kind, doctor, and the hour of the day — and it either adds a boost
-- (a larger `priority`, so the patient sorts ahead) or a penalty (a
-- smaller / negative one, so a routine review sorts behind).
--
-- Rules are per clinic and can be scoped to one queue. The highest matching
-- boost applies once; boosts do not stack, because "child + appointment +
-- morning" is one clinical decision about one patient, and adding three
-- numbers together turns a policy into an accident.
-- =====================================================================

CREATE TABLE IF NOT EXISTS priority_rules (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id     INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  queue_id      INTEGER REFERENCES queues(id) ON DELETE CASCADE,
  name          TEXT NOT NULL,
  -- Match conditions. NULL means "any".
  source        TEXT,            -- appointment | walkin
  kind          TEXT,            -- consultation | lab | pharmacy | general
  doctor_id     INTEGER REFERENCES doctors(id) ON DELETE CASCADE,
  min_age       INTEGER,         -- inclusive
  max_age       INTEGER,         -- inclusive
  from_hour     INTEGER,         -- inclusive, 0-23 clinic-local
  to_hour       INTEGER,         -- exclusive, 1-24
  -- Effect. `priority` is the value written on the entry.
  effect        INTEGER NOT NULL DEFAULT 1,
  reason        TEXT NOT NULL,
  is_active     INTEGER NOT NULL DEFAULT 1,
  created_by    INTEGER,
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT ck_rule_effect CHECK (effect BETWEEN -5 AND 9 AND effect <> 0),
  CONSTRAINT ck_rule_from CHECK (from_hour IS NULL OR from_hour BETWEEN 0 AND 23),
  CONSTRAINT ck_rule_to CHECK (to_hour IS NULL OR to_hour BETWEEN 1 AND 24),
  CONSTRAINT ck_rule_span CHECK (from_hour IS NULL OR to_hour IS NULL OR from_hour < to_hour),
  CONSTRAINT ck_rule_age CHECK (min_age IS NULL OR max_age IS NULL OR min_age <= max_age),
  CONSTRAINT ck_rule_source CHECK (source IS NULL OR source IN ('appointment','walkin')),
  CONSTRAINT ck_rule_kind CHECK (kind IS NULL OR kind IN ('consultation','lab','pharmacy','general'))
);

CREATE INDEX IF NOT EXISTS idx_rule_clinic_active ON priority_rules (clinic_id, is_active);

-- What a rule did, on the entry it did it to. A priority the patient did not
-- ask for and no member of staff typed has to be explainable afterwards, so
-- the reason travels with the entry rather than being recomputed later from
-- a rule that may since have been edited.
ALTER TABLE queue_entries ADD COLUMN priority_rule_id INTEGER;
