-- Public display keys.
--
-- A waiting-room screen is opened by pasting a URL onto a TV and then
-- forgotten for two years. The key that opens it was a single row in
-- `settings`, which meant:
--
--   * one key per clinic, so every screen shared one credential;
--   * no name, so nobody could tell "reception TV" from "upstairs ward";
--   * rotation replaced the value in place, so there was no way to take one
--     screen offline without taking all of them offline;
--   * no record of when a key was last used, so an abandoned screen could not
--     be told apart from a live one.
--
-- The PDF lists `public_display_keys` as an entity, and the security section
-- asks for links to be "purpose-limited" and "revoked" appropriately. Both
-- need per-screen keys.

CREATE TABLE public_display_keys (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    clinic_id     INTEGER NOT NULL REFERENCES clinics(id) ON DELETE CASCADE,
    department_id INTEGER NULL REFERENCES departments(id) ON DELETE CASCADE,
    name          TEXT NOT NULL,          -- "Reception TV", "Ward 2 landing"
    display_key   TEXT NOT NULL,
    kind          TEXT NOT NULL DEFAULT 'waiting_room',  -- waiting_room | department
    created_by    INTEGER NULL,
    created_at    TEXT NOT NULL,
    last_used_at  TEXT NULL,
    revoked_at    TEXT NULL
);

CREATE UNIQUE INDEX ux_public_display_key ON public_display_keys(display_key);
CREATE INDEX ix_public_display_clinic ON public_display_keys(clinic_id, revoked_at);
