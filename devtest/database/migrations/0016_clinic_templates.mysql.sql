-- =====================================================================
-- 2026_11_05_0016 — per-clinic message wording
--
-- Every clinic sent exactly the words in NotificationService::TEMPLATES.
-- For a Sinhala-medium clinic in Negombo that is not a limitation, it is a
-- defect: a patient receives a message in a language they may not read,
-- about the one thing they are anxious about.
--
-- Design, and the reasoning that shaped it:
--
--   * **Override, never replace.** A clinic that has edited nothing gets the
--     shipped copy, and a template added in a later release appears for
--     everyone. A clinic that edits one template overrides only that one.
--   * **One row per template, keyed by name.** Values are looked up as a map
--     at send time, so the hot path is one small query per clinic, cached by
--     the request.
--   * **Placeholders are validated on save, not at 03:00.** A template that
--     drops {token} is a message that never says the patient's number; a
--     template with {toke} sends the literal braces to a patient. Both are
--     refused when a person is looking at the form.
--   * **Locale is deliberately absent.** A per-locale template set is a
--     translation workflow with a review step, not a text box. Claiming
--     "multilingual templates" because there is a `locale` column would be
--     the kind of overstatement this document exists to avoid.
-- =====================================================================

CREATE TABLE IF NOT EXISTS notification_templates (
  id          INTEGER PRIMARY KEY AUTO_INCREMENT,
  clinic_id   INTEGER NOT NULL,
  template    TEXT NOT NULL,
  body        TEXT NOT NULL,
  updated_by  INTEGER,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (clinic_id) REFERENCES clinics(id) ON DELETE CASCADE,
  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL,
  UNIQUE KEY ux_notification_template (clinic_id, template)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
