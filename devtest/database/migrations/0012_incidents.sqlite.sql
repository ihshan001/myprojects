-- =====================================================================
-- 2026_11_05_0012 — incident records
--
-- An incident used to live in three places: a `system_alerts` row that said
-- something was wrong, a paragraph in the on-call runbook telling someone
-- what to do, and whatever the person on call typed into Slack. Nothing
-- joined them, and nothing recorded the decisions taken at 03:00 that make
-- sense afterwards only if somebody wrote them down.
--
-- This is deliberately small and honest:
--
--   * An incident has a severity, a state and a timeline of entries.
--   * The timeline is append-only. Editing an incident log is how an audit
--     trail becomes fiction; a correction is another entry that says so.
--   * A system alert can be attached, so "what was the actual error" is one
--     click from "what did we decide".
--   * Time to acknowledge and time to resolve are computed from the entries,
--     not typed in — a number nobody can check is not a measurement.
--
-- What this is NOT: a paging integration. Somebody still has to notice and
-- open the page. That is a provider contract, not code.
-- =====================================================================

CREATE TABLE IF NOT EXISTS incidents (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id    INTEGER REFERENCES clinics(id) ON DELETE CASCADE,  -- NULL = platform-wide
  reference    TEXT NOT NULL,
  title        TEXT NOT NULL,
  severity     TEXT NOT NULL DEFAULT 'minor',
  status       TEXT NOT NULL DEFAULT 'open',
  summary      TEXT,
  detected_at  TEXT NOT NULL,
  acknowledged_at TEXT,
  resolved_at  TEXT,
  created_by   INTEGER,
  created_at   TEXT NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT ck_inc_severity CHECK (severity IN ('minor','major','critical')),
  CONSTRAINT ck_inc_status CHECK (status IN ('open','mitigated','resolved'))
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_incident_reference ON incidents (reference);
CREATE INDEX IF NOT EXISTS idx_incident_status ON incidents (status, detected_at);

CREATE TABLE IF NOT EXISTS incident_entries (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  incident_id INTEGER NOT NULL REFERENCES incidents(id) ON DELETE CASCADE,
  kind        TEXT NOT NULL DEFAULT 'note',
  body        TEXT NOT NULL,
  actor_id    INTEGER,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT ck_inc_kind CHECK (kind IN ('note','action','decision','cause','link'))
);
CREATE INDEX IF NOT EXISTS idx_incident_entries ON incident_entries (incident_id, created_at);

-- Which infrastructure alert belongs to which incident, so the timeline can
-- say what the platform was complaining about at the time.
ALTER TABLE system_alerts ADD COLUMN incident_id INTEGER REFERENCES incidents(id) ON DELETE SET NULL;
