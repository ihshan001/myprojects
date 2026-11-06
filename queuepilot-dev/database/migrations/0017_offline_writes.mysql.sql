-- =====================================================================
-- 2026_11_05_0017 — offline writes
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
-- =====================================================================

CREATE TABLE IF NOT EXISTS offline_blocks (
    id          INTEGER PRIMARY KEY AUTO_INCREMENT,
    clinic_id   INTEGER NOT NULL,
    queue_id    INTEGER NOT NULL,
    from_no     INTEGER NOT NULL,
    to_no       INTEGER NOT NULL,
    issued_by   INTEGER,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at  DATETIME NOT NULL,
    FOREIGN KEY (clinic_id)   REFERENCES clinics(id) ON DELETE CASCADE,
    FOREIGN KEY (queue_id)    REFERENCES queues(id) ON DELETE CASCADE,
    FOREIGN KEY (issued_by)   REFERENCES users(id) ON DELETE SET NULL,
    INDEX ix_offline_block_window (clinic_id, queue_id, from_no, to_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS offline_reservations (
    id          INTEGER PRIMARY KEY AUTO_INCREMENT,
    clinic_id   INTEGER NOT NULL,
    reservation VARCHAR(64) NOT NULL,
    block_id    INTEGER NOT NULL,
    queue_id    INTEGER NOT NULL,
    token_no    INTEGER NOT NULL,
    token_label VARCHAR(16) NOT NULL,
    entry_id    INTEGER NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (clinic_id)   REFERENCES clinics(id) ON DELETE CASCADE,
    FOREIGN KEY (block_id)    REFERENCES offline_blocks(id) ON DELETE CASCADE,
    FOREIGN KEY (queue_id)    REFERENCES queues(id) ON DELETE CASCADE,
    FOREIGN KEY (entry_id)    REFERENCES queue_entries(id) ON DELETE CASCADE,
    UNIQUE KEY ux_offline_reservation (reservation),
    UNIQUE KEY ux_offline_reservation_label (clinic_id, queue_id, token_label)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
