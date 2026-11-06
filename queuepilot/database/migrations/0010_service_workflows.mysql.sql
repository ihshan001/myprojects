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
  id           INTEGER PRIMARY KEY AUTO_INCREMENT,
  clinic_id    INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  queue_id     INTEGER REFERENCES queues(id) ON DELETE CASCADE,
  kind         TEXT,
  name         TEXT NOT NULL,
  position     INTEGER NOT NULL DEFAULT 1,
  is_required  INTEGER NOT NULL DEFAULT 1,
  is_active    INTEGER NOT NULL DEFAULT 1,
  created_at   TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
  CONSTRAINT ck_wstep_kind CHECK (kind IS NULL OR kind IN ('consultation','lab','pharmacy','general')),
  CONSTRAINT ck_wstep_position CHECK (position BETWEEN 1 AND 20)
);
CREATE INDEX IF NOT EXISTS idx_wstep_clinic ON workflow_steps (clinic_id, kind, is_active);

-- Which steps an individual visit has actually passed, and who said so.
CREATE TABLE IF NOT EXISTS entry_step_done (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  clinic_id   INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  entry_id    INTEGER NOT NULL REFERENCES queue_entries(id) ON DELETE CASCADE,
  step_id     INTEGER NOT NULL REFERENCES workflow_steps(id) ON DELETE CASCADE,
  done_by     INTEGER,
  done_at     TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
  UNIQUE (entry_id, step_id)
);
CREATE INDEX IF NOT EXISTS idx_stepdone_entry ON entry_step_done (entry_id);
