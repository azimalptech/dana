-- FR-15.14 (client, 2026-08-27): deleting a teacher removes the row
-- entirely — «отключён» leftovers read as still-existing staff. A hard
-- delete needs the columns that reference a teacher to tolerate the
-- reference going away:
--
--   classrooms.teacher_id   a CLOSED course keeps its history but loses
--                           the pointer (active courses block the delete
--                           and must be reassigned first);
--   classrooms.created_by   provenance, not ownership — nullable audit;
--   notifications.sender_id the students' inbox copies survive the
--                           sender's departure.
--
-- MariaDB keeps the FK constraints (still RESTRICT) — only NULL becomes
-- an allowed value, so an existing id remains impossible to orphan.

ALTER TABLE classrooms
    MODIFY teacher_id BIGINT UNSIGNED NULL,
    MODIFY created_by BIGINT UNSIGNED NULL;

ALTER TABLE notifications
    MODIFY sender_id BIGINT UNSIGNED NULL;
