-- =====================================================================
-- 2026_11_05_0010 — configurable service workflows
--
-- The funnel was fixed: waiting → called → in consultation → completed.
-- That is right for a doctor's room and wrong for a laboratory, where the
-- visit is not "completed" when the nurse finishes talking — it is completed
-- when the sample is taken, the result is verified and the report is handed
-- over. A pharmacy counter has its own three steps. Without a way to name
-- those steps, the only honest label on the button is "completed", and the
-- record cannot say which part of the service actually happened.
--
-- A workflow is a list of **steps a service requires before completion**.
-- The step is not a status: the entry is still `in_consultation` until the
-- last one. Making each step a status would mean rewriting the state machine
-- per clinic and breaking the board, the display and the tracker for every
-- status they have never seen.
--
-- Each step belongs to a clinic and, optionally, to one queue kind or one
-- queue. A completion is refused while a required step is unticked, and the
-- refusal names the steps. Steps are recorded with who ticked them and when.
-- =====================================================================

CREATE TABLE IF NOT EXISTS workflow_steps (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id    INT UNSIGNED NOT NULL,
  queue_id     INT UNSIGNED NULL,
  kind         VARCHAR(16) NULL,
  name         VARCHAR(120) NOT NULL,
  position     TINYINT UNSIGNED NOT NULL DEFAULT 1,
  is_required  TINYINT(1) NOT NULL DEFAULT 1,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_wstep_clinic (clinic_id, kind, is_active),
  CONSTRAINT ck_wstep_kind CHECK (kind IS NULL OR kind IN ('consultation','lab','pharmacy','general')),
  CONSTRAINT ck_wstep_position CHECK (position BETWEEN 1 AND 20),
  CONSTRAINT fk_wstep_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE,
  CONSTRAINT fk_wstep_queue FOREIGN KEY (queue_id) REFERENCES queues (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which steps an individual visit has actually passed, and who said so.
CREATE TABLE IF NOT EXISTS entry_step_done (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  clinic_id   INT UNSIGNED NOT NULL,
  entry_id    INT UNSIGNED NOT NULL,
  step_id     INT UNSIGNED NOT NULL,
  done_by     INT UNSIGNED NULL,
  done_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY ux_stepdone (entry_id, step_id),
  KEY idx_stepdone_entry (entry_id),
  CONSTRAINT fk_stepdone_clinic FOREIGN KEY (clinic_id) REFERENCES clinics (id) ON DELETE CASCADE,
  CONSTRAINT fk_stepdone_entry FOREIGN KEY (entry_id) REFERENCES queue_entries (id) ON DELETE CASCADE,
  CONSTRAINT fk_stepdone_step FOREIGN KEY (step_id) REFERENCES workflow_steps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
