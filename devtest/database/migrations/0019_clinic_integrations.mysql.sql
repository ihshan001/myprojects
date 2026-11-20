-- =====================================================================
-- 2026_11_08_0019 — clinic integrations registry
--
-- MySQL twin of the SQLite migration of the same number. See the SQLite
-- file for the reasoning; the schema is identical apart from types.
-- =====================================================================

CREATE TABLE IF NOT EXISTS integrations (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    clinic_id    BIGINT UNSIGNED NOT NULL,
    provider     VARCHAR(64)  NOT NULL,
    status       VARCHAR(16)  NOT NULL DEFAULT 'off',
    config       TEXT         NULL,
    connected_at DATETIME     NULL,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_integrations_clinic_provider (clinic_id, provider),
    KEY idx_integrations_clinic (clinic_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
