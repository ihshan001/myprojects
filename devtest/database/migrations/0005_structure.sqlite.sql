-- =====================================================================
-- 2026_11_05_0005 — departments, schedules, holidays, support, notices
--
-- Everything in the 123-item coverage tracker that needs a new shape rather
-- than a new screen lives here. Each table exists because a scenario cannot
-- be answered from the existing schema:
--
--   * `departments`   — "Department management", "Department-based queues",
--                       "Department-specific displays", "Department reports".
--                       `specialties` is a clinical label; a department is an
--                       operational unit that owns queues and rooms.
--   * `doctor_schedules` — "Doctor schedules". A weekday grid per doctor, so
--                       the booking page can stop guessing from clinic hours
--                       alone.
--   * `holidays`      — "Operating hours and holidays". A clinic-wide closed
--                       date that overrides both clinic hours and any doctor's
--                       schedule.
--   * `support_tickets` / `support_replies` — "Customer support".
--   * `staff_notifications` — "In-system notifications" for staff, distinct
--                       from the patient-facing `notifications` queue.
--
-- Additive only. No existing column is altered or dropped, so an install
-- that has already run 0003/0004 keeps working.
-- =====================================================================

-- ---------- 1. departments ------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id     INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  name          TEXT NOT NULL,
  code          TEXT,
  description   TEXT,
  floor         TEXT,
  lead_doctor_id INTEGER REFERENCES doctors(id) ON DELETE SET NULL,
  sort_order    INTEGER NOT NULL DEFAULT 0,
  is_active     INTEGER NOT NULL DEFAULT 1,
  created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_dept_clinic ON departments (clinic_id, is_active, sort_order);
CREATE UNIQUE INDEX IF NOT EXISTS uq_dept_name ON departments (clinic_id, name);

-- A queue belongs to at most one department. Nullable: existing queues keep
-- working and a single-doctor clinic never has to create a department.
ALTER TABLE queues ADD COLUMN department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_queue_dept ON queues (department_id);

-- A doctor is posted to a department. `specialty_id` stays: what a doctor
-- practises and where they sit are different questions.
ALTER TABLE doctors ADD COLUMN department_id INTEGER REFERENCES departments(id) ON DELETE SET NULL;
CREATE INDEX IF NOT EXISTS idx_doctor_dept ON doctors (department_id);

-- ---------- 2. doctor schedules ------------------------------------------
-- One row per weekday the doctor actually works. A doctor with no rows is
-- treated as "follows clinic hours" so existing installs do not suddenly
-- show every doctor as unavailable.
CREATE TABLE IF NOT EXISTS doctor_schedules (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id  INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  doctor_id  INTEGER NOT NULL REFERENCES doctors(id) ON DELETE CASCADE,
  weekday    INTEGER NOT NULL CHECK (weekday BETWEEN 0 AND 6),
  starts_at  TEXT NOT NULL,
  ends_at    TEXT NOT NULL,
  is_active  INTEGER NOT NULL DEFAULT 1,
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT ck_sched_order CHECK (ends_at > starts_at)
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_sched_slot ON doctor_schedules (doctor_id, weekday, starts_at);
CREATE INDEX IF NOT EXISTS idx_sched_clinic ON doctor_schedules (clinic_id, doctor_id, weekday);

-- ---------- 3. holidays ---------------------------------------------------
-- `date` is a plain YYYY-MM-DD so a recurring annual holiday is expressed by
-- inserting one row per year rather than by a rule engine nobody tests.
CREATE TABLE IF NOT EXISTS holidays (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id  INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  date       TEXT NOT NULL,
  name       TEXT NOT NULL,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_holiday_day ON holidays (clinic_id, date);
CREATE INDEX IF NOT EXISTS idx_holiday_lookup ON holidays (clinic_id, date);

-- ---------- 4. support ----------------------------------------------------
CREATE TABLE IF NOT EXISTS support_tickets (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id   INTEGER REFERENCES clinics(id) ON DELETE CASCADE,
  user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
  subject     TEXT NOT NULL,
  body        TEXT NOT NULL,
  category    TEXT NOT NULL DEFAULT 'general',
  priority    TEXT NOT NULL DEFAULT 'normal',
  status      TEXT NOT NULL DEFAULT 'open',
  created_at  TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at  TEXT NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT ck_ticket_status CHECK (status IN ('open','waiting','resolved','closed')),
  CONSTRAINT ck_ticket_priority CHECK (priority IN ('low','normal','high','urgent'))
);
CREATE INDEX IF NOT EXISTS idx_ticket_clinic ON support_tickets (clinic_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_ticket_status ON support_tickets (status, priority);

CREATE TABLE IF NOT EXISTS support_replies (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  ticket_id  INTEGER NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
  user_id    INTEGER REFERENCES users(id) ON DELETE SET NULL,
  body       TEXT NOT NULL,
  is_staff   INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_reply_ticket ON support_replies (ticket_id, created_at);

-- ---------- 5. in-system staff notifications -----------------------------
-- Written in the same transaction as the event that caused them, so a staff
-- member cannot be shown a notice for something that rolled back.
CREATE TABLE IF NOT EXISTS staff_notifications (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id  INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  user_id    INTEGER REFERENCES users(id) ON DELETE CASCADE,
  kind       TEXT NOT NULL DEFAULT 'info',
  title      TEXT NOT NULL,
  body       TEXT,
  link       TEXT,
  read_at    TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_snotice_user ON staff_notifications (clinic_id, user_id, read_at);

-- ---------- 6. retention and closure -------------------------------------
-- "Account suspension and closure" and "Data retention and deletion" need a
-- date the software can act on, not a status someone remembers to change.
ALTER TABLE clinics ADD COLUMN suspended_at TEXT;
ALTER TABLE clinics ADD COLUMN suspension_reason TEXT;
ALTER TABLE clinics ADD COLUMN closure_requested_at TEXT;
ALTER TABLE clinics ADD COLUMN closed_at TEXT;
ALTER TABLE clinics ADD COLUMN purge_after TEXT;
