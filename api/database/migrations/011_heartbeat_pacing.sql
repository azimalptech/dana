SET NAMES utf8mb4;

-- FR-8.11 hardening: study time must reflect real elapsed foreground
-- time, not how fast a client can loop the heartbeat. Recording the last
-- heartbeat lets the server credit min(claimed, wall-clock since last),
-- so a burst of calls can no longer fill a 12-hour day in seconds.
ALTER TABLE student_activity_days
    ADD COLUMN last_heartbeat_at DATETIME NULL DEFAULT NULL AFTER seconds_studied;
