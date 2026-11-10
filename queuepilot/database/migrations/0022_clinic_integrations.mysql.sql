-- =====================================================================
-- clinic integrations registry
--
-- The PDF's screen map lists "integrations" under Notifications, and the
-- notification section asks for "configurable integrations: SMS, Email,
-- WhatsApp where available". Until now whether a clinic had an integration
-- was answerable only by reading .env — which is the wrong place: it is
-- per-deployment, not per-clinic, and a clinic owner can neither see nor
-- change it.
--
-- So the connection is now a row. The row is the clinic's own record of
-- what it has connected, why, and when. It does NOT replace .env —
-- credentials still live outside the database — it records that a
-- connection exists and lets an owner switch a working integration off
-- without editing a file.
--
-- `status` is 'connected' or 'off'. Disconnecting flips the status rather
-- than deleting the row, so reconnecting an integration the clinic has
-- already configured does not mean retyping its settings.
-- =====================================================================

CREATE TABLE integrations (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clinic_id    BIGINT UNSIGNED NOT NULL,
    -- Stable machine name from the catalog in IntegrationService, not a
    -- display string. A display name would need translating; a key does not.
    provider     VARCHAR(48) NOT NULL,
    status       VARCHAR(16) NOT NULL DEFAULT 'off',
    -- JSON: non-secret settings only (sender id, account reference, which
    -- branches are in scope). Credentials are deliberately never written
    -- here — see IntegrationService::connect().
    config       TEXT NULL,
    connected_at DATETIME NULL,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ux_integrations_clinic_provider (clinic_id, provider),
    INDEX idx_integrations_clinic (clinic_id),
    CONSTRAINT fk_integrations_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
