-- =====================================================================
-- 2026_11_09_0023 — priority rules: explicit order, vitals, change history
--
-- Three changes, all in service of one screen.
--
-- 1. ORDER BECOMES EXPLICIT. Rules used to be evaluated strongest-first
--    (`effect DESC, id ASC`), which meant the only way to say "this policy
--    outranks that one" was to inflate a number until it won. That is a
--    poor interface for a clinical policy: the manager thinks in a ranked
--    list, and the list should be the truth. `sort_order` is that list, and
--    the FIRST matching rule in it now decides the arrival.
--
--    The backfill is the important part. Existing rules are numbered in the
--    order the old engine would have evaluated them, so upgrading a live
--    clinic does not silently change who gets called next. New rules append
--    at the end, where a manager can see and move them.
--
-- 2. TEMPERATURE IS A MATCHABLE FACT. "Fever above 39C" is a real triage
--    policy that the engine previously could not express, so it could only
--    be applied by hand, fifty times a day. A rule may now bound the
--    recorded temperature, and the entry carries the reading it was judged
--    on. NULL means "not measured" and never matches a temperature rule —
--    an unmeasured patient is not an afebrile patient.
--
--    Stored as tenths of a degree Celsius in an INTEGER (`temp_c_x10`) so
--    38.7 is 387 exactly. A REAL column would put a clinical threshold on
--    floating-point comparison, and 39.0 is a boundary that must be exact.
--
-- 3. RULE CHANGES ARE HISTORY. A priority order that nobody typed has to be
--    explainable afterwards, and "the rule may have changed since" is how an
--    audit trail becomes a guess. Every create/edit/reorder/toggle/delete
--    writes a row here with the actor and the before/after payload.
-- =====================================================================

ALTER TABLE priority_rules ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0;
ALTER TABLE priority_rules ADD COLUMN min_temp_x10 INTEGER;
ALTER TABLE priority_rules ADD COLUMN max_temp_x10 INTEGER;
ALTER TABLE priority_rules ADD COLUMN updated_at TEXT;

-- The reading the rule was judged on, kept on the entry for the same reason
-- the reason is: it must survive the rule being edited or deleted.
ALTER TABLE queue_entries ADD COLUMN temp_c_x10 INTEGER;

-- Rank existing rules exactly as the old engine evaluated them, so the
-- upgrade is behaviour-preserving. Correlated COUNT rather than a window
-- function: this has to run on the same SQLite the app already ships.
UPDATE priority_rules
   SET sort_order = (
     SELECT COUNT(*) FROM priority_rules AS peer
      WHERE peer.clinic_id = priority_rules.clinic_id
        AND (peer.effect > priority_rules.effect
             OR (peer.effect = priority_rules.effect AND peer.id <= priority_rules.id))
   );

UPDATE priority_rules SET updated_at = created_at WHERE updated_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_rule_order ON priority_rules (clinic_id, sort_order);

CREATE TABLE IF NOT EXISTS priority_rule_history (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id   INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  rule_id     INTEGER,                 -- no FK: history outlives the rule
  rule_name   TEXT NOT NULL,           -- named even after deletion
  action      TEXT NOT NULL,           -- created | updated | reordered | enabled | disabled | deleted
  actor_id    INTEGER,
  detail      TEXT,                    -- human sentence, written at the time
  before_json TEXT,
  after_json  TEXT,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT ck_rule_history_action CHECK (
    action IN ('created','updated','reordered','enabled','disabled','deleted')
  )
);

CREATE INDEX IF NOT EXISTS idx_rule_history_clinic ON priority_rule_history (clinic_id, id DESC);
