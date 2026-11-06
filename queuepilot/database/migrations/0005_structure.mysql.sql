-- =====================================================================
-- 2026_11_05_0005 — departments, schedules, holidays, support, notices
-- (MySQL 8.0 variant)
--
-- Mirrors the SQLite migration exactly. MySQL can ALTER in place, so unlike
-- the SQLite file there are no table rebuilds.
-- =====================================================================

-- ---------- 1. departments ------------------------------------------------
CREATE TABLE IF NOT EXISTS departments (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id      INT UNSIGNED NOT NULL,
  name           VARCHAR(120) NOT NULL,
  code           VARCHAR(20) NULL,
  description    VARCHAR(500) NULL,
  floor          VARCHAR(60) NULL,
  lead_doctor_id INT UNSIGNED NULL,
  sort_order     INT NOT NULL DEFAULT 0,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_dept_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_dept_lead FOREIGN KEY (lead_doctor_id) REFERENCES doctors(id) ON DELETE SET NULL,
  UNIQUE KEY uq_dept_name (clinic_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_dept_clinic ON departments (clinic_id, is_active, sort_order);

ALTER TABLE queues ADD COLUMN department_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_queue_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;
CREATE INDEX idx_queue_dept ON queues (department_id);

ALTER TABLE doctors ADD COLUMN department_id INT UNSIGNED NULL,
    ADD CONSTRAINT fk_doctor_dept FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL;
CREATE INDEX idx_doctor_dept ON doctors (department_id);

-- ---------- 2. doctor schedules ------------------------------------------
CREATE TABLE IF NOT EXISTS doctor_schedules (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id  INT UNSIGNED NOT NULL,
  doctor_id  INT UNSIGNED NOT NULL,
  weekday    TINYINT NOT NULL,
  starts_at  TIME NOT NULL,
  ends_at    TIME NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sched_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_sched_doctor FOREIGN KEY (doctor_id) REFERENCES doctors(id) ON DELETE CASCADE,
  CONSTRAINT ck_sched_weekday CHECK (weekday BETWEEN 0 AND 6),
  CONSTRAINT ck_sched_order CHECK (ends_at > starts_at),
  UNIQUE KEY uq_sched_slot (doctor_id, weekday, starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_sched_clinic ON doctor_schedules (clinic_id, doctor_id, weekday);

-- ---------- 3. holidays ---------------------------------------------------
CREATE TABLE IF NOT EXISTS holidays (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id  INT UNSIGNED NOT NULL,
  date       DATE NOT NULL,
  name       VARCHAR(120) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_holiday_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_holiday_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY uq_holiday_day (clinic_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_holiday_lookup ON holidays (clinic_id, date);

-- ---------- 4. support ----------------------------------------------------
CREATE TABLE IF NOT EXISTS support_tickets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id  INT UNSIGNED NULL,
  user_id    INT UNSIGNED NULL,
  subject    VARCHAR(200) NOT NULL,
  body       TEXT NOT NULL,
  category   VARCHAR(40) NOT NULL DEFAULT 'general',
  priority   VARCHAR(20) NOT NULL DEFAULT 'normal',
  status     VARCHAR(20) NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ticket_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_ticket_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT ck_ticket_status CHECK (status IN ('open','waiting','resolved','closed')),
  CONSTRAINT ck_ticket_priority CHECK (priority IN ('low','normal','high','urgent'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_ticket_clinic ON support_tickets (clinic_id, status, created_at);
CREATE INDEX idx_ticket_status ON support_tickets (status, priority);

CREATE TABLE IF NOT EXISTS support_replies (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ticket_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  body       TEXT NOT NULL,
  is_staff   TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_reply_ticket FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  CONSTRAINT fk_reply_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_reply_ticket ON support_replies (ticket_id, created_at);

-- ---------- 5. in-system staff notifications -----------------------------
CREATE TABLE IF NOT EXISTS staff_notifications (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id  INT UNSIGNED NOT NULL,
  user_id    INT UNSIGNED NULL,
  kind       VARCHAR(30) NOT NULL DEFAULT 'info',
  title      VARCHAR(200) NOT NULL,
  body       VARCHAR(500) NULL,
  link       VARCHAR(255) NULL,
  read_at    DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_snotice_clinic FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  CONSTRAINT fk_snotice_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE INDEX idx_snotice_user ON staff_notifications (clinic_id, user_id, read_at);

-- ---------- 6. retention, suspension and closure -------------------------
ALTER TABLE clinics
    ADD COLUMN suspended_at DATETIME NULL,
    ADD COLUMN suspension_reason VARCHAR(255) NULL,
    ADD COLUMN closure_requested_at DATETIME NULL,
    ADD COLUMN closed_at DATETIME NULL,
    ADD COLUMN purge_after DATETIME NULL;
