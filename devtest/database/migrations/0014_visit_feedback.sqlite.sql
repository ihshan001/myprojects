-- =====================================================================
-- 2026_11_05_0014 — patient satisfaction, asked at the one moment it is honest
--
-- A satisfaction survey that is emailed a week later measures whether people
-- answer email. The only moment a patient can truthfully rate a visit is
-- while they are still in the building — and the only place this product has
-- their attention then is the tracking link they are already watching.
--
-- Three deliberate constraints, each of which a worse design would drop:
--
--   * **One answer per visit.** The unique index is on `entry_id`, so the
--     tracking page can be refreshed, reloaded and shared without ever
--     producing a second rating. The link is not a survey invitation someone
--     can answer twice to be helpful.
--   * **Only after a completed visit.** The controller refuses a rating on a
--     waiting or called visit: "how was it?" cannot be answered about a
--     consultation that has not happened. Cancelled and no-show visits are
--     also not rated — the patient has nothing to report on the doctor.
--   * **The comment is optional and never required.** A star-only rating is
--     useful; forcing prose loses the people with the least time, who are
--     usually the ones waiting longest.
--
-- Nothing here identifies the patient beyond the queue entry that already
-- exists: the row points at a visit, not at a person, so aggregate reports
-- cannot be joined back into a name by accident.
-- =====================================================================

CREATE TABLE IF NOT EXISTS visit_feedback (
  id          INTEGER PRIMARY KEY AUTOINCREMENT,
  clinic_id   INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
  entry_id    INTEGER NOT NULL REFERENCES queue_entries(id) ON DELETE CASCADE,
  doctor_id   INTEGER,
  department_id INTEGER,

  -- 1..5. A five-point scale with named ends, because "rate 1 to 10" invites
  -- argument about what a 7 means and produces numbers nobody can act on.
  rating      INTEGER NOT NULL,

  -- Kept apart rather than one blob: "the waiting was fine but the doctor
  -- rushed me" is the sentence an owner can do something about, and it
  -- disappears into an average if it is only a comment.
  wait_rating INTEGER,
  comment     TEXT,

  created_at  TEXT NOT NULL DEFAULT (datetime('now')),

  CONSTRAINT ck_feedback_rating CHECK (rating BETWEEN 1 AND 5),
  CONSTRAINT ck_feedback_wait CHECK (wait_rating IS NULL OR wait_rating BETWEEN 1 AND 5)
);

CREATE UNIQUE INDEX IF NOT EXISTS ux_feedback_entry ON visit_feedback (entry_id);
CREATE INDEX IF NOT EXISTS idx_feedback_clinic ON visit_feedback (clinic_id, created_at);
CREATE INDEX IF NOT EXISTS idx_feedback_doctor ON visit_feedback (clinic_id, doctor_id, created_at);
