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
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id       INT UNSIGNED NULL,   -- NULL = platform-wide
  reference       VARCHAR(40) NOT NULL,
  title           VARCHAR(200) NOT NULL,
  severity        VARCHAR(12) NOT NULL DEFAULT 'minor',
  status          VARCHAR(12) NOT NULL DEFAULT 'open',
  summary         TEXT NULL,
  detected_at     DATETIME NOT NULL,
  acknowledged_at DATETIME NULL,
  resolved_at     DATETIME NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_incident_reference (reference),
  KEY idx_incident_status (status, detected_at),
  CONSTRAINT ck_inc_severity CHECK (severity IN ('minor','major','critical')),
  CONSTRAINT ck_inc_status CHECK (status IN ('open','mitigated','resolved')),
  CONSTRAINT fk_incident_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS incident_entries (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  incident_id INT UNSIGNED NOT NULL,
  kind        VARCHAR(12) NOT NULL DEFAULT 'note',
  body        TEXT NOT NULL,
  actor_id    INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_incident_entries (incident_id, created_at),
  CONSTRAINT ck_inc_kind CHECK (kind IN ('note','action','decision','cause','link')),
  CONSTRAINT fk_incentry_incident FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- `INTEGER REFERENCES ...` on an ALTERed column is SQLite-only: MySQL stores no
-- reference from it and silently ignores the clause, which would have left this
-- column with no foreign key at all while the schema looked correct.
ALTER TABLE system_alerts ADD COLUMN incident_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_alert_incident FOREIGN KEY (incident_id) REFERENCES incidents (id) ON DELETE SET NULL;
