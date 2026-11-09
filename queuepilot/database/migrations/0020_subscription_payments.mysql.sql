-- Subscriptions and payments as first-class records.
-- See 0020_subscription_payments.sqlite.sql for why each needs its own table.

CREATE TABLE payments (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clinic_id     BIGINT UNSIGNED NOT NULL,
    invoice_id    BIGINT UNSIGNED NOT NULL,
    amount        DECIMAL(12,2) NOT NULL,
    currency      VARCHAR(8) NOT NULL DEFAULT 'LKR',
    method        VARCHAR(24) NULL,
    reference     VARCHAR(120) NULL,
    status        VARCHAR(16) NOT NULL,
    submitted_by  BIGINT UNSIGNED NULL,
    submitted_at  DATETIME NOT NULL,
    decided_by    BIGINT UNSIGNED NULL,
    decided_at    DATETIME NULL,
    decision_note VARCHAR(280) NULL,
    created_at    DATETIME NOT NULL,
    CONSTRAINT fk_payment_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE,
    CONSTRAINT fk_payment_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    INDEX ix_payment_clinic (clinic_id, submitted_at DESC),
    INDEX ix_payment_invoice (invoice_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subscriptions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    clinic_id     BIGINT UNSIGNED NOT NULL,
    plan_id       BIGINT UNSIGNED NULL,
    status        VARCHAR(16) NOT NULL,
    trial_ends_on DATE NULL,
    renews_on     DATE NULL,
    started_at    DATETIME NOT NULL,
    ended_at      DATETIME NULL,
    created_by    BIGINT UNSIGNED NULL,
    note          VARCHAR(280) NULL,
    created_at    DATETIME NOT NULL,
    CONSTRAINT fk_subscription_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE,
    CONSTRAINT fk_subscription_plan FOREIGN KEY (plan_id) REFERENCES plans (id) ON DELETE SET NULL,
    INDEX ix_subscription_clinic (clinic_id, started_at DESC),
    INDEX ix_subscription_open (clinic_id, ended_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
