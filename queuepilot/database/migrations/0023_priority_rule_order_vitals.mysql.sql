-- =====================================================================
-- priority rules: explicit order, vitals, change history
--
-- Twin of 0023_priority_rule_order_vitals.sqlite.sql. Same three changes;
-- only the dialect differs. See the SQLite file for why.
--
-- 1. Order becomes explicit: `sort_order` is a ranked list and the first
--    matching rule in it decides, replacing "highest effect wins". Existing
--    rules are numbered in the order the old engine evaluated them, so the
--    upgrade does not change who gets called next.
-- 2. Temperature becomes a matchable fact, stored as tenths of a degree in
--    an INTEGER so a 39.0 threshold is exact.
-- 3. Rule changes become history, so a priority nobody typed is explainable.
-- =====================================================================

ALTER TABLE priority_rules
  ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0,
  ADD COLUMN min_temp_x10 INTEGER NULL,
  ADD COLUMN max_temp_x10 INTEGER NULL,
  ADD COLUMN updated_at DATETIME NULL;

ALTER TABLE queue_entries ADD COLUMN temp_c_x10 INTEGER NULL;

UPDATE priority_rules pr
  JOIN (
    SELECT p.id,
           (SELECT COUNT(*) FROM priority_rules AS peer
             WHERE peer.clinic_id = p.clinic_id
               AND (peer.effect > p.effect
                    OR (peer.effect = p.effect AND peer.id <= p.id))) AS rank
      FROM priority_rules p
  ) ranked ON ranked.id = pr.id
   SET pr.sort_order = ranked.rank;

UPDATE priority_rules SET updated_at = created_at WHERE updated_at IS NULL;

CREATE INDEX idx_rule_order ON priority_rules (clinic_id, sort_order);

CREATE TABLE IF NOT EXISTS priority_rule_history (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  clinic_id   BIGINT UNSIGNED NOT NULL,
  rule_id     BIGINT UNSIGNED NULL,        -- no FK: history outlives the rule
  rule_name   VARCHAR(120) NOT NULL,       -- named even after deletion
  action      VARCHAR(16)  NOT NULL,
  actor_id    BIGINT UNSIGNED NULL,
  detail      TEXT NULL,
  before_json TEXT NULL,
  after_json  TEXT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rule_history_clinic (clinic_id, id),
  CONSTRAINT ck_rule_history_action CHECK (
    action IN ('created','updated','reordered','enabled','disabled','deleted')
  ),
  CONSTRAINT fk_rule_history_clinic FOREIGN KEY (clinic_id)
    REFERENCES clinics (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
