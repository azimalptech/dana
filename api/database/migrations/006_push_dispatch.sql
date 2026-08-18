SET NAMES utf8mb4;

-- Push dispatch state on notifications.
--
-- Sending FCM messages inline in the HTTP request is fine for one
-- classroom and hopeless for "all users" at the 100,000-student target
-- (NFR-1) — a sequential send would hold the request open for hours.
-- Small audiences are delivered inline; anything larger is marked
-- 'pending' and the generation worker delivers it in the background.
-- The in-app inbox is written either way, so push state never gates the
-- message itself (FR-10.3).

ALTER TABLE notifications
  ADD COLUMN push_status ENUM('skipped','pending','sending','done') NOT NULL DEFAULT 'skipped',
  ADD COLUMN push_sent INT UNSIGNED NULL,
  ADD COLUMN push_failed INT UNSIGNED NULL;

CREATE INDEX ix_notifications_push ON notifications (push_status, id);
