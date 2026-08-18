SET NAMES utf8mb4;

-- In-flight state for an exercise attempt.
--
-- FR-8.7 says points reach the server only when the set is COMPLETED,
-- and FR-12.7 says a wrong answer re-queues the question until it is
-- answered correctly. Between those two rules an attempt needs somewhere
-- to remember "question 3 was wrong first time and is still unresolved"
-- without touching question_results — because a row there means points
-- have been awarded, permanently and once only (FR-8.4).
--
-- Shape: { "<question_id>": { "first_correct": bool, "attempts": int,
--                             "resolved": bool } }
--
-- Cleared on completion, when it becomes question_results rows.

ALTER TABLE exercise_attempts
  ADD COLUMN progress JSON NULL AFTER question_count;

-- One attempt row per student per set.
--
-- Re-doing a set (FR-8.6) resets this row rather than adding another.
-- History is not lost that matters: points were fixed permanently on the
-- first completion, so a second run has nothing new to record. Keeping
-- one row also makes "start attempt" idempotent — a retried request
-- resumes the existing attempt instead of opening a parallel one that
-- could be completed twice.
ALTER TABLE exercise_attempts
  ADD CONSTRAINT uq_attempt UNIQUE (student_id, exercise_set_id);
