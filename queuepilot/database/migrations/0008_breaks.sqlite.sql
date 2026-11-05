-- =====================================================================
-- 2026_11_05_0008 — break management
--
-- Pausing a queue carried a free-text reason and nothing else. Two things
-- were missing and both matter to a clinic manager reading the month
-- afterwards:
--
--   * What kind of pause it was. "Lunch" and "emergency" are both a pause
--     with a note, but only one of them is expected and plannable. Without a
--     category the report cannot say "this clinic lost 40 minutes a day to
--     unscheduled breaks".
--   * How long it lasted, measured rather than reconstructed. The queue row
--     holds one open pause at a time, so `break_started_at` on it is enough;
--     every completed pause lands in `queue_breaks` with both ends.
--
-- A scheduled break is stored separately from an actual one, because a
-- planned 13:00 lunch that never happened is not an event, and an unplanned
-- 15:40 pause that did is not a plan. Conflating them is how a "breaks"
-- report becomes fiction.
-- =====================================================================

ALTER TABLE queues ADD COLUMN break_started_at TEXT;
ALTER TABLE queues ADD COLUMN break_kind TEXT;

CREATE TABLE IF NOT EXISTS queue_breaks (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id   INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  queue_id    INTEGER NOT NULL REFERENCES queues(id) ON DELETE CASCADE,
  kind        TEXT NOT NULL DEFAULT 'unspecified',
  reason      TEXT,
  started_at  TEXT NOT NULL,
  ended_at    TEXT,
  minutes     INTEGER,
  created_by  INTEGER,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT ck_break_kind CHECK (kind IN (
    'lunch','tea','prayer','unscheduled','emergency','equipment','end_of_day','unspecified'
  ))
);
CREATE INDEX IF NOT EXISTS idx_break_clinic_day ON queue_breaks (clinic_id, started_at);
CREATE INDEX IF NOT EXISTS idx_break_queue ON queue_breaks (queue_id, ended_at);

-- A planned break, so the board can say "back at 14:00" instead of "paused".
CREATE TABLE IF NOT EXISTS scheduled_breaks (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id   INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  doctor_id   INTEGER REFERENCES doctors(id) ON DELETE CASCADE,
  weekday     INTEGER NOT NULL,
  starts_at   TEXT NOT NULL,
  ends_at     TEXT NOT NULL,
  kind        TEXT NOT NULL DEFAULT 'lunch',
  is_active   INTEGER NOT NULL DEFAULT 1,
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT ck_sbreak_weekday CHECK (weekday BETWEEN 0 AND 6),
  CONSTRAINT ck_sbreak_kind CHECK (kind IN (
    'lunch','tea','prayer','unscheduled','emergency','equipment','end_of_day','unspecified'
  ))
);
CREATE INDEX IF NOT EXISTS idx_sbreak_clinic ON scheduled_breaks (clinic_id, weekday, is_active);
