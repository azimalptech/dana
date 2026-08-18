SET NAMES utf8mb4;

-- Turns `generation_runs` into a work queue the panel can drive.
--
-- Generating a unit means OCR'ing its pages, proposing vocabulary,
-- writing a grammar explanation and producing four exercise types — for
-- every section. That is minutes of model time, far past any sensible
-- HTTP timeout, so the panel enqueues and polls instead of waiting.

ALTER TABLE generation_runs
  MODIFY target ENUM('exercises','grammar','both','section','exercise_set','ocr') NOT NULL;

-- What to generate: the exercise type for a single-set run, the page
-- range for an OCR run, and so on.
ALTER TABLE generation_runs
  ADD COLUMN params JSON NULL AFTER target;

-- Human-readable trail of what the worker did, shown live in the panel.
ALTER TABLE generation_runs
  ADD COLUMN progress TEXT NULL AFTER validation;

-- Lets a worker claim a job atomically without two workers taking the
-- same one, and keeps the "what is queued" lookup cheap.
ALTER TABLE generation_runs
  ADD COLUMN claimed_at DATETIME NULL AFTER started_at;

CREATE INDEX ix_runs_queue ON generation_runs (status, id);
