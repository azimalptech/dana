SET NAMES utf8mb4;

-- The teacher-app course card (Figma 154:298) carries a schedule line:
-- "Mon, Wed, Fri, Sun | 12:00 - 14:35". Free text, because that is
-- exactly what the design shows and nothing computes on it. Set by the
-- centre admin; shown wherever the classroom is named.
ALTER TABLE classrooms
  ADD COLUMN schedule_note VARCHAR(120) NULL AFTER capacity;
