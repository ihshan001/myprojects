-- =====================================================================
-- 2026_11_05_0006 — per-clinic feature gates (SaaS entitlement)
--
-- The platform operator decides which clinic gets which feature. A clinic's
-- effective feature set is:
--
--   1. An explicit row in clinic_features (operator override) — wins.
--   2. Otherwise, the plan's `features` list (comma-separated feature keys).
--   3. Otherwise, off.
--
-- Enforcement happens in the service layer (NotificationService, QueueService,
-- etc.), never merely by hiding a button in the UI. SMS metering lives here
-- too: every accepted outbound SMS is counted so the operator can bill for it.
-- =====================================================================

CREATE TABLE IF NOT EXISTS clinic_features (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id  INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  feature    TEXT NOT NULL,
  enabled    INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (clinic_id, feature)
);
CREATE INDEX IF NOT EXISTS idx_clinic_feature ON clinic_features (clinic_id, feature);

-- SMS usage metering: one row per clinic per calendar month. The count is
-- incremented by the worker when a message is accepted by the provider.
CREATE TABLE IF NOT EXISTS sms_usage (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id   INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  year        INTEGER NOT NULL,
  month       INTEGER NOT NULL,
  sent_count  INTEGER NOT NULL DEFAULT 0,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (clinic_id, year, month)
);
CREATE INDEX IF NOT EXISTS idx_sms_usage_clinic ON sms_usage (clinic_id, year, month);
