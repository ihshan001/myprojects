-- 2026_11_05_0002 — see the sqlite variant for the rationale.
ALTER TABLE queues
    ADD COLUMN delay_minutes INT NOT NULL DEFAULT 0,
    ADD COLUMN delay_note VARCHAR(180) NULL;

ALTER TABLE clinics
    ADD COLUMN allow_online_booking TINYINT(1) NOT NULL DEFAULT 0;
