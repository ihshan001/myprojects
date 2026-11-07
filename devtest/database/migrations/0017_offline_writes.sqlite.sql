-- Offline writes.
--
-- `offline_blocks` records that a queue's ticket numbers A031–A040 were set
-- aside for a desk that may go offline. The numbers are already past
-- `last_token_no`, so nothing online can be issued them; this table exists so
-- a reconciliation can tell a number we reserved apart from one somebody
-- invented.
--
-- `offline_reservations` is the idempotency guard. `reservation` is UNIQUE
-- across the whole table, not just per clinic: a replayed sync that arrives
-- twice — the browser retried, or two tabs woke up — must produce one patient
-- and one ticket, and the database is the only place that can promise that
-- under concurrency.

CREATE TABLE offline_blocks (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    clinic_id   INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    queue_id    INTEGER NOT NULL REFERENCES queues(id) ON DELETE CASCADE,
    from_no     INTEGER NOT NULL,
    to_no       INTEGER NOT NULL,
    issued_by   INTEGER NULL,
    created_at  TEXT NOT NULL,
    expires_at  TEXT NOT NULL
);

CREATE INDEX ix_offline_block_window ON offline_blocks(clinic_id, queue_id, from_no, to_no);

CREATE TABLE offline_reservations (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    clinic_id   INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    reservation TEXT NOT NULL,
    block_id    INTEGER NOT NULL REFERENCES offline_blocks(id) ON DELETE CASCADE,
    queue_id    INTEGER NOT NULL,
    token_no    INTEGER NOT NULL,
    token_label TEXT NOT NULL,
    entry_id    INTEGER NOT NULL REFERENCES queue_entries(id) ON DELETE CASCADE,
    created_at  TEXT NOT NULL
);

CREATE UNIQUE INDEX ux_offline_reservation ON offline_reservations(reservation);
CREATE UNIQUE INDEX ux_offline_reservation_label ON offline_reservations(clinic_id, queue_id, token_label);
