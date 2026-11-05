-- =====================================================================
-- 2026_11_05_0002 — delay notices + opt-in online booking
--
-- 1. `queues.delay_minutes` and `queues.delay_note` let staff say "Dr X is
--    running 20 minutes behind". The waiting-room screen shows it, and the
--    patient estimate is shifted by it. A delay is an announcement, not a
--    state change: the queue keeps working and tokens keep flowing.
--
-- 2. `clinics.allow_online_booking` is opt-in per clinic. A clinic that
--    does not use online booking must not expose a public booking form,
--    and the default is OFF so nothing is exposed by accident.
-- =====================================================================

ALTER TABLE queues ADD COLUMN delay_minutes INTEGER NOT NULL DEFAULT 0;
ALTER TABLE queues ADD COLUMN delay_note TEXT;

ALTER TABLE clinics ADD COLUMN allow_online_booking INTEGER NOT NULL DEFAULT 0;
