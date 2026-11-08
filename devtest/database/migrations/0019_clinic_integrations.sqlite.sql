-- =====================================================================
-- 2026_11_08_0019 — clinic integrations registry
--
-- The settings screen lists the systems a clinic can connect to (SMS
-- gateway, email transport, accounting, and so on). Until now "is it
-- connected?" was answerable only by reading .env, which is exactly the
-- wrong place: it is per-deployment, not per-clinic, and a clinic owner
-- cannot see or change it.
--
-- So the connection is now a row. The row is the clinic's own record of
-- what it has connected, why, and when. It does NOT replace .env --
-- credentials still live outside the database -- it records that a
-- connection exists and lets an owner switch a working integration off
-- without editing a file.
--
-- `status` is 'connected' or 'off'. Disconnecting flips the status rather
-- than deleting the row, so reconnecting an integration the clinic has
-- already configured does not mean retyping its settings.
-- =====================================================================

CREATE TABLE IF NOT EXISTS integrations (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    clinic_id    INTEGER NOT NULL,
    -- Stable machine name from the catalog in IntegrationService, not a
    -- display string. A display name would need translating; a key does not.
    provider     TEXT    NOT NULL,
    status       TEXT    NOT NULL DEFAULT 'off',
    -- JSON: non-secret settings only (sender id, account reference,
    -- which branches are in scope). Credentials are deliberately never
    -- written here -- see IntegrationService::connect().
    config       TEXT    NULL,
    connected_at TEXT    NULL,
    updated_at   TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE (clinic_id, provider)
);

CREATE INDEX IF NOT EXISTS idx_integrations_clinic ON integrations (clinic_id);
