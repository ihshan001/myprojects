-- =====================================================================
-- 2026_11_08_0018 — priority rules: explicit order, vitals, change history
--
-- MySQL twin of the SQLite migration; see that file for the reasoning.
-- Summary: rules become an explicitly ranked list and the FIRST match in
-- that list decides an arrival; temperature becomes a matchable fact stored
-- in tenths of a degree so a 39.0 threshold compares exactly; and every rule
-- change is recorded so an ordering nobody typed stays explainable.
--
-- The backfill ranks existing rules exactly as the old engine evaluated them
-- (effect DESC, id ASC) so upgrading a live clinic does not change who is
-- called next.
-- =====================================================================

ALTER TABLE priority_rules ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0;
ALTER TABLE priority_rules ADD COLUMN min_temp_x10 INTEGER;
ALTER TABLE priority_rules ADD COLUMN max_temp_x10 INTEGER;
ALTER TABLE priority_rules ADD COLUMN updated_at TEXT;

ALTER TABLE queue_entries ADD COLUMN temp_c_x10 INTEGER;

UPDATE priority_rules
   SET sort_order = (
     SELECT COUNT(*) FROM (SELECT clinic_id, effect, id FROM priority_rules) AS peer
      WHERE peer.clinic_id = priority_rules.clinic_id
        AND (peer.effect > priority_rules.effect
             OR (peer.effect = priority_rules.effect AND peer.id <= priority_rules.id))
   );

UPDATE priority_rules SET updated_at = created_at WHERE updated_at IS NULL;

CREATE INDEX idx_rule_order ON priority_rules (clinic_id, sort_order);

CREATE TABLE IF NOT EXISTS priority_rule_history (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  clinic_id   INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  rule_id     INTEGER,
  rule_name   TEXT NOT NULL,
  action      TEXT NOT NULL,
  actor_id    INTEGER,
  detail      TEXT,
  before_json TEXT,
  after_json  TEXT,
  created_at  TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
  CONSTRAINT ck_rule_history_action CHECK (
    action IN ('created','updated','reordered','enabled','disabled','deleted')
  )
);

CREATE INDEX idx_rule_history_clinic ON priority_rule_history (clinic_id, id DESC);