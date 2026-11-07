-- =====================================================================
-- 2026_11_07_0018 — explicit evaluation order for priority rules
--
-- See the SQLite sibling for the reasoning. MySQL cannot UPDATE a table
-- that also appears in a correlated subquery (error 1093), so the backfill
-- ranks into a derived table and joins it back.
--
-- The backfill reproduces the previous ordering (effect DESC, id ASC), so
-- switching the engine to first-match does not change any existing
-- clinic's queue order.
-- =====================================================================

ALTER TABLE priority_rules ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0;

UPDATE priority_rules r
  JOIN (
    SELECT id, ROW_NUMBER() OVER (PARTITION BY clinic_id ORDER BY effect DESC, id ASC) AS rn
      FROM priority_rules
  ) ranked ON ranked.id = r.id
   SET r.sort_order = ranked.rn;

CREATE INDEX idx_rule_order ON priority_rules (clinic_id, is_active, sort_order);
