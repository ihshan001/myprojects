-- Public display keys. See 0021_public_display_keys.sqlite.sql for why one
-- shared, unnamed, in-place-rotated key is not enough.

CREATE TABLE public_display_keys (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clinic_id     BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    name          VARCHAR(80) NOT NULL,
    display_key   VARCHAR(64) NOT NULL,
    kind          VARCHAR(24) NOT NULL DEFAULT 'waiting_room',
    created_by    BIGINT UNSIGNED NULL,
    created_at    DATETIME NOT NULL,
    last_used_at  DATETIME NULL,
    revoked_at    DATETIME NULL,
    CONSTRAINT fk_pdk_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE,
    CONSTRAINT fk_pdk_department FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE CASCADE,
    UNIQUE KEY ux_public_display_key (display_key),
    INDEX ix_public_display_clinic (clinic_id, revoked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
