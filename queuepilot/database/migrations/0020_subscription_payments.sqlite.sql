-- Subscriptions and payments as first-class records.
--
-- Two things were being kept in a single mutable row each, and in both cases
-- the history was the point:
--
-- `payments` — a clinic claims a bank transfer by posting a reference, and a
-- platform admin verifies or rejects it. That claim lived on the invoice, so a
-- rejected payment reset the invoice to 'unpaid' and left nothing behind: no
-- record that the clinic said it had paid, no record of who rejected it or
-- why. A clinic that genuinely transferred money and was rejected for a typo
-- has no evidence it tried, and the platform cannot see a repeat attempt. The
-- PDF lists `payments` as "Submitted and verified payments" — both halves.
--
-- `subscriptions` — plan membership lived in clinics.plan_id / billing_status,
-- which can only ever describe the present. "What plan were we on in June?"
-- and "when did we upgrade?" were unanswerable, and a downgrade that lost a
-- paid feature was not reconstructable.

CREATE TABLE payments (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    clinic_id    INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    invoice_id   INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
    amount       REAL NOT NULL,
    currency     TEXT NOT NULL DEFAULT 'LKR',
    method       TEXT NULL,               -- bank_transfer | card | cash
    reference    TEXT NULL,               -- the slip / reference the clinic typed
    status       TEXT NOT NULL,           -- claimed | verified | rejected
    submitted_by INTEGER NULL,
    submitted_at TEXT NOT NULL,
    decided_by   INTEGER NULL,
    decided_at   TEXT NULL,
    decision_note TEXT NULL,
    created_at   TEXT NOT NULL
);

CREATE INDEX ix_payment_clinic ON payments(clinic_id, submitted_at DESC);
CREATE INDEX ix_payment_invoice ON payments(invoice_id, status);

CREATE TABLE subscriptions (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    clinic_id    INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    plan_id      INTEGER NULL REFERENCES plans(id) ON DELETE SET NULL,
    status       TEXT NOT NULL,           -- trialing | active | past_due | suspended | cancelled
    trial_ends_on TEXT NULL,
    renews_on    TEXT NULL,
    started_at   TEXT NOT NULL,
    ended_at     TEXT NULL,               -- NULL = the current subscription
    created_by   INTEGER NULL,
    note         TEXT NULL,
    created_at   TEXT NOT NULL
);

CREATE INDEX ix_subscription_clinic ON subscriptions(clinic_id, started_at DESC);
-- One open subscription per clinic. SQLite cannot express this as a partial
-- unique index on ended_at IS NULL, so the service enforces it when opening a
-- new row; the index keeps that check cheap.
CREATE INDEX ix_subscription_open ON subscriptions(clinic_id, ended_at);
