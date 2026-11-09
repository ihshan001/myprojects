-- Delivery history for notifications.
-- See 0019_notification_logs.sqlite.sql for why this is a separate,
-- append-only table rather than more columns on `notifications`.

CREATE TABLE notification_logs (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clinic_id       BIGINT UNSIGNED NOT NULL,
    notification_id BIGINT UNSIGNED NOT NULL,
    attempt         INT UNSIGNED NOT NULL,
    channel         VARCHAR(16) NOT NULL,
    outcome         VARCHAR(16) NOT NULL,
    error           VARCHAR(290) NULL,
    created_at      DATETIME NOT NULL,
    CONSTRAINT fk_nlog_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE,
    CONSTRAINT fk_nlog_message FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE,
    INDEX ix_notification_log_message (notification_id, attempt),
    INDEX ix_notification_log_clinic (clinic_id, created_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
