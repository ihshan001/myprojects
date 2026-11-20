-- Delivery history for notifications.
--
-- `notifications` is an outbox: one row per message, and each retry overwrites
-- `status`, `attempts` and `last_error` on that same row. That is the right
-- shape for a work queue, but it means the history is destroyed by the retry —
-- after three attempts the clinic can see that the last one failed and nothing
-- about the two before it.
--
-- The PDF asks for exactly what that loses: "Use retry logic and delivery
-- logs." So attempts are appended here instead. The outbox keeps one row per
-- message; this table keeps one row per *try*, and is never updated.
--
-- It is deliberately append-only. A delivery log that can be rewritten is not
-- evidence of anything.

CREATE TABLE notification_logs (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    clinic_id       INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    notification_id INTEGER NOT NULL REFERENCES notifications(id) ON DELETE CASCADE,
    attempt         INTEGER NOT NULL,
    channel         TEXT NOT NULL,
    outcome         TEXT NOT NULL,   -- sent | logged | failed | abandoned | gated
    error           TEXT NULL,
    created_at      TEXT NOT NULL
);

CREATE INDEX ix_notification_log_message ON notification_logs(notification_id, attempt);
CREATE INDEX ix_notification_log_clinic ON notification_logs(clinic_id, created_at DESC);
