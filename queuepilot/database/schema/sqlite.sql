-- =====================================================================
-- QueuePilot — SQLite schema (executable engine in this sandbox)
-- Semantically identical to database/schema/mysql.sql: same tables,
-- columns, uniqueness rules, check constraints and indexes.
-- Portable SQL only (no sqlite-only tricks) so both stay in sync.
-- =====================================================================

CREATE TABLE IF NOT EXISTS plans (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  code          TEXT NOT NULL UNIQUE,
  name          TEXT NOT NULL,
  price_monthly REAL NOT NULL DEFAULT 0,
  currency      TEXT NOT NULL DEFAULT 'LKR',
  max_doctors   INTEGER NOT NULL DEFAULT 1,
  max_staff     INTEGER NOT NULL DEFAULT 1,
  max_queues    INTEGER NOT NULL DEFAULT 2,
  features      TEXT,
  sort_order    INTEGER NOT NULL DEFAULT 0,
  is_active     INTEGER NOT NULL DEFAULT 1,
  created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS clinics (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  slug           TEXT NOT NULL UNIQUE,
  name           TEXT NOT NULL,
  legal_name     TEXT,
  phone          TEXT,
  email          TEXT,
  address_line   TEXT,
  city           TEXT,
  district       TEXT,
  timezone       TEXT NOT NULL DEFAULT 'Asia/Colombo',
  locale         TEXT NOT NULL DEFAULT 'en',
  logo_path      TEXT,
  brand_color    TEXT NOT NULL DEFAULT '#0F766E',
  currency       TEXT NOT NULL DEFAULT 'LKR',
  token_prefix       TEXT NOT NULL DEFAULT '',
  token_reset_policy TEXT NOT NULL DEFAULT 'daily',
  late_grace_minutes INTEGER NOT NULL DEFAULT 15,
  appointment_weight INTEGER NOT NULL DEFAULT 1,
  walkin_weight      INTEGER NOT NULL DEFAULT 3,
  mandate_return_mins INTEGER NOT NULL DEFAULT 40,
  plan_id        INTEGER REFERENCES plans(id) ON DELETE SET NULL,
  billing_status TEXT NOT NULL DEFAULT 'trial',
  trial_ends_on  TEXT,
  renews_on      TEXT,
  is_active      INTEGER NOT NULL DEFAULT 1,
  created_at     TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at     TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS users (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id     INTEGER REFERENCES clinics(id) ON DELETE CASCADE,
  role          TEXT NOT NULL,
  name          TEXT NOT NULL,
  email         TEXT NOT NULL UNIQUE,
  phone         TEXT,
  password_hash TEXT NOT NULL,
  doctor_id     INTEGER,
  locale        TEXT NOT NULL DEFAULT 'en',
  is_active     INTEGER NOT NULL DEFAULT 1,
  last_login_at TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT ck_user_role CHECK (role IN ('super_admin','clinic_admin','receptionist','doctor'))
);
CREATE INDEX IF NOT EXISTS idx_users_clinic ON users (clinic_id, role);

CREATE TABLE IF NOT EXISTS specialties (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  name      TEXT NOT NULL,
  UNIQUE (clinic_id, name)
);

CREATE TABLE IF NOT EXISTS rooms (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  name      TEXT NOT NULL,
  floor     TEXT
);

CREATE TABLE IF NOT EXISTS doctors (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id     INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  specialty_id  INTEGER REFERENCES specialties(id) ON DELETE SET NULL,
  room_id       INTEGER REFERENCES rooms(id) ON DELETE SET NULL,
  name          TEXT NOT NULL,
  reg_no        TEXT,
  consult_fee   REAL NOT NULL DEFAULT 0,
  avg_consult_minutes INTEGER NOT NULL DEFAULT 10,
  is_active     INTEGER NOT NULL DEFAULT 1,
  created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_doc_clinic ON doctors (clinic_id, is_active);

CREATE TABLE IF NOT EXISTS queues (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id      INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  doctor_id      INTEGER REFERENCES doctors(id) ON DELETE SET NULL,
  name           TEXT NOT NULL,
  kind           TEXT NOT NULL DEFAULT 'consultation',
  status         TEXT NOT NULL DEFAULT 'open',
  session_date   TEXT NOT NULL,
  token_prefix   TEXT NOT NULL DEFAULT '',
  last_token_no  INTEGER NOT NULL DEFAULT 0,
  token_padding  INTEGER NOT NULL DEFAULT 3,
  created_at     TEXT NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT ck_queue_status CHECK (status IN ('open','paused','closed')),
  CONSTRAINT ck_queue_kind CHECK (kind IN ('consultation','lab','pharmacy','general'))
);
CREATE INDEX IF NOT EXISTS idx_q_live ON queues (clinic_id, status, session_date);
CREATE INDEX IF NOT EXISTS idx_q_doctor_date ON queues (doctor_id, session_date);

CREATE TABLE IF NOT EXISTS patients (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id     INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  code          TEXT NOT NULL,
  full_name     TEXT NOT NULL,
  phone         TEXT,
  gender        TEXT,
  birth_year    INTEGER,
  notes         TEXT,
  is_blocked    INTEGER NOT NULL DEFAULT 0,
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at    TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE (clinic_id, code)
);
CREATE INDEX IF NOT EXISTS idx_pat_phone ON patients (clinic_id, phone);

CREATE TABLE IF NOT EXISTS appointments (
  id            INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id     INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  doctor_id     INTEGER NOT NULL REFERENCES doctors(id) ON DELETE CASCADE,
  patient_id    INTEGER NOT NULL REFERENCES patients(id) ON DELETE CASCADE,
  scheduled_for TEXT NOT NULL,
  duration_minutes INTEGER NOT NULL DEFAULT 15,
  status        TEXT NOT NULL DEFAULT 'booked',
  source        TEXT NOT NULL DEFAULT 'online',
  notes         TEXT,
  created_at    TEXT NOT NULL DEFAULT (datetime('now')),
  CONSTRAINT ck_appt_status CHECK (status IN ('booked','checked_in','served','no_show','cancelled'))
);
CREATE INDEX IF NOT EXISTS idx_appt_day ON appointments (clinic_id, doctor_id, scheduled_for);

CREATE TABLE IF NOT EXISTS queue_entries (
  id             INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id      INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  queue_id       INTEGER NOT NULL REFERENCES queues(id) ON DELETE CASCADE,
  patient_id     INTEGER NOT NULL REFERENCES patients(id) ON DELETE CASCADE,
  appointment_id INTEGER REFERENCES appointments(id) ON DELETE SET NULL,
  token_no       INTEGER NOT NULL,
  token_label    TEXT NOT NULL,
  status         TEXT NOT NULL DEFAULT 'waiting',
  priority       INTEGER NOT NULL DEFAULT 0,
  priority_reason TEXT,
  sort_weight    INTEGER NOT NULL DEFAULT 0,
  source         TEXT NOT NULL DEFAULT 'walkin',
  joined_at      TEXT NOT NULL,
  called_at      TEXT,
  started_at     TEXT,
  finished_at    TEXT,
  wait_minutes   INTEGER,
  consult_minutes INTEGER,
  call_count     INTEGER NOT NULL DEFAULT 0,
  note           TEXT,
  created_by     INTEGER,
  track_token    TEXT NOT NULL UNIQUE,
  track_expires_at TEXT,
  version        INTEGER NOT NULL DEFAULT 0,
  UNIQUE (queue_id, token_label),
  CONSTRAINT ck_entry_status CHECK (status IN ('waiting','called','in_consultation','completed','skipped','no_show','cancelled','transferred'))
);
CREATE INDEX IF NOT EXISTS idx_qe_board ON queue_entries (queue_id, status, priority, sort_weight, id);
CREATE INDEX IF NOT EXISTS idx_qe_day ON queue_entries (clinic_id, joined_at);

CREATE TABLE IF NOT EXISTS queue_events (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id    INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  queue_id     INTEGER REFERENCES queues(id) ON DELETE SET NULL,
  entry_id     INTEGER REFERENCES queue_entries(id) ON DELETE CASCADE,
  actor_id     INTEGER,
  action       TEXT NOT NULL,
  from_status  TEXT,
  to_status    TEXT,
  reason       TEXT,
  meta_json    TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_qev_entry ON queue_events (entry_id, id);

CREATE TABLE IF NOT EXISTS notifications (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id    INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  entry_id     INTEGER REFERENCES queue_entries(id) ON DELETE CASCADE,
  channel      TEXT NOT NULL DEFAULT 'sms',
  template     TEXT NOT NULL,
  to_number    TEXT,
  payload_json TEXT,
  dedupe_key   TEXT NOT NULL UNIQUE,
  status       TEXT NOT NULL DEFAULT 'queued',
  attempts     INTEGER NOT NULL DEFAULT 0,
  last_error   TEXT,
  scheduled_at TEXT NOT NULL,
  sent_at      TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_notif_due ON notifications (status, scheduled_at);

CREATE TABLE IF NOT EXISTS audit_logs (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id    INTEGER,
  actor_id     INTEGER,
  action       TEXT NOT NULL,
  entity       TEXT,
  entity_id    INTEGER,
  ip           TEXT,
  meta_json    TEXT,
  created_at   TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_audit_clinic ON audit_logs (clinic_id, id);

CREATE TABLE IF NOT EXISTS invoices (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id   INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  number      TEXT NOT NULL UNIQUE,
  period_from TEXT NOT NULL,
  period_to   TEXT NOT NULL,
  amount      REAL NOT NULL,
  currency    TEXT NOT NULL DEFAULT 'LKR',
  status      TEXT NOT NULL DEFAULT 'unpaid',
  paid_at     TEXT,
  method      TEXT,
  reference   TEXT,
  created_at  TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS login_attempts (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  email      TEXT NOT NULL,
  ip         TEXT NOT NULL,
  successful INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_attempt ON login_attempts (email, ip, created_at);

CREATE TABLE IF NOT EXISTS settings (
  id        INTEGER PRIMARY KEY AUTOINCREMENT,
  scope     TEXT NOT NULL,
  owner_id  INTEGER,
  key_name  TEXT NOT NULL,
  value     TEXT,
  UNIQUE (scope, owner_id, key_name)
);
