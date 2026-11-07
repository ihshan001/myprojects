-- =====================================================================
-- 2026_11_07_0018 — explicit evaluation order for priority rules
--
-- Until now the engine had no stored order. It sorted by `effect DESC` and
-- took the strongest match, which meant a manager could not express the
-- thing managers actually ask for: "under-fives first, and only then high
-- fever". Two rules with the same effect were ordered by insertion id, so
-- the tie-break was an accident of when somebody clicked Save.
--
-- `sort_order` makes the order a decision instead of a side effect. The
-- engine now takes the FIRST matching active rule in this order.
--
-- Behaviour is preserved on upgrade: the backfill ranks existing rules by
-- exactly the old ordering (effect desc, then id). First-match over that
-- order returns the same rule that strongest-match returned before, so no
-- clinic silently changes its queue order by applying this migration.
--
-- 1-based, unique per clinic in practice but not enforced by a constraint:
-- a drag writes the whole list in one transaction, and a unique index would
-- make the intermediate states of that write fail.
-- =====================================================================

ALTER TABLE priority_rules ADD COLUMN sort_order INTEGER NOT NULL DEFAULT 0;

-- Correlated count = 1-based rank within the clinic under the old ordering.
UPDATE priority_rules
   SET sort_order = (
     SELECT COUNT(*) FROM priority_rules p2
      WHERE p2.clinic_id = priority_rules.clinic_id
        AND (p2.effect > priority_rules.effect
             OR (p2.effect = priority_rules.effect AND p2.id <= priority_rules.id))
   );

CREATE INDEX IF NOT EXISTS idx_rule_order ON priority_rules (clinic_id, is_active, sort_order);
