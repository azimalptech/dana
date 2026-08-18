SET NAMES utf8mb4;

-- 008 — the 2026-08-08 redesign (§13 of 01-REQUIREMENTS.md).
--
-- Hierarchy gains a typed-section layer under the child unit
-- (units = parent units, unit_sections = child units 1A/1B — table
-- names kept, meanings renamed in code). Points and unlocking are
-- removed wholesale; attempts become repeatable rows with history.
--
-- DATA: destroys all exercise sets/questions (FR-13.16, client's
-- explicit choice) and every progress/score row. PRESERVES entered
-- vocabulary and grammar by repointing them into auto-created
-- Vocabulary/Grammar sections (FR-13.16).

-- ------------------------------------------------- 1. the section layer

CREATE TABLE sections (
  id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  unit_section_id BIGINT UNSIGNED NOT NULL,           -- the child unit (1A, 1B…)
  type            ENUM('grammar','vocabulary','listening','quiz') NOT NULL,
  title_tk        VARCHAR(200) NULL,
  title_ru        VARCHAR(200) NULL,
  -- FR-13.20: visibility gate. Students read published sections only.
  status          ENUM('draft','published') NOT NULL DEFAULT 'draft',
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  KEY ix_sections_child (unit_section_id, sort_order),
  -- At most one Exam Quiz per child unit is enforced in code (partial
  -- unique keys are not expressible in MariaDB 10.4).
  CONSTRAINT fk_sections_child FOREIGN KEY (unit_section_id)
    REFERENCES unit_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------- 2. preserve vocabulary & grammar

-- One Vocabulary section per child unit that has words. Published:
-- these words were already live to students.
INSERT INTO sections (unit_section_id, type, title_tk, title_ru, status, sort_order, created_at, updated_at)
SELECT DISTINCT v.unit_section_id, 'vocabulary', 'Sözlük', 'Словарь', 'published', 0, NOW(), NOW()
FROM vocabulary_items v;

ALTER TABLE vocabulary_items ADD COLUMN section_id BIGINT UNSIGNED NULL AFTER id;

UPDATE vocabulary_items v
JOIN sections s ON s.unit_section_id = v.unit_section_id AND s.type = 'vocabulary'
SET v.section_id = s.id;

ALTER TABLE vocabulary_items
  MODIFY section_id BIGINT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_vocab_typed_section FOREIGN KEY (section_id)
    REFERENCES sections(id) ON DELETE CASCADE;

ALTER TABLE vocabulary_items
  DROP FOREIGN KEY fk_vocab_section,
  DROP COLUMN unit_section_id;

-- One Grammar section per child unit that has an explanation. The
-- section inherits the explanation's publish state; from here on the
-- SECTION is the visibility gate and grammar_explanations.status goes.
INSERT INTO sections (unit_section_id, type, title_tk, title_ru, status, sort_order, created_at, updated_at)
SELECT g.unit_section_id, 'grammar', 'Grammatika', 'Грамматика',
       IF(g.status = 'published', 'published', 'draft'), 1, NOW(), NOW()
FROM grammar_explanations g;

ALTER TABLE grammar_explanations ADD COLUMN section_id BIGINT UNSIGNED NULL AFTER id;

UPDATE grammar_explanations g
JOIN sections s ON s.unit_section_id = g.unit_section_id AND s.type = 'grammar'
SET g.section_id = s.id;

ALTER TABLE grammar_explanations
  MODIFY section_id BIGINT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_grammar_typed_section FOREIGN KEY (section_id)
    REFERENCES sections(id) ON DELETE CASCADE;

ALTER TABLE grammar_explanations
  DROP FOREIGN KEY fk_grammar_section,
  DROP FOREIGN KEY fk_grammar_run,
  DROP KEY uq_grammar_section,
  DROP COLUMN unit_section_id,
  DROP COLUMN generated_run_id,
  DROP COLUMN status;

-- --------------------------------------- 3. exercises restart from zero

-- FR-13.16: the client chose to drop existing exercises outright.
DELETE FROM exercise_sets;

ALTER TABLE exercise_sets
  ADD COLUMN section_id BIGINT UNSIGNED NULL AFTER id;

ALTER TABLE exercise_sets
  DROP FOREIGN KEY fk_sets_section,
  DROP FOREIGN KEY fk_sets_run,
  DROP COLUMN unit_section_id,
  DROP COLUMN generated_run_id;

ALTER TABLE exercise_sets
  MODIFY section_id BIGINT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_sets_typed_section FOREIGN KEY (section_id)
    REFERENCES sections(id) ON DELETE CASCADE;

-- FR-13.13 / FR-13.14: question media type, media file, quiz eligibility.
-- AI-era provenance columns go — nothing writes them any more.
ALTER TABLE questions
  ADD COLUMN question_type ENUM('text','audio','picture') NOT NULL DEFAULT 'text' AFTER exercise_set_id,
  ADD COLUMN media_path VARCHAR(255) NULL AFTER question_type,
  ADD COLUMN quiz_eligible TINYINT(1) NOT NULL DEFAULT 0 AFTER media_path;

ALTER TABLE questions
  DROP FOREIGN KEY fk_q_book,
  DROP COLUMN source_book_id,
  DROP COLUMN source_page,
  DROP COLUMN source_sentence,
  DROP COLUMN change_ratio;

-- ------------------------------------------- 4. points & unlocking die

DROP TABLE IF EXISTS section_unlocks;
DROP TABLE IF EXISTS question_results;
DROP TABLE IF EXISTS student_scores;
DROP TABLE IF EXISTS exercise_attempts;

-- ------------------------------------------------- 5. attempts, anew

-- One row per COMPLETED run of a section (FR-13.5: nothing exists for
-- an unfinished run — there is deliberately no in_progress state and
-- no mid-attempt storage). Repeatable without limit (FR-13.6).
CREATE TABLE section_attempts (
  id             BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  student_id     BIGINT UNSIGNED NOT NULL,
  section_id     BIGINT UNSIGNED NOT NULL,
  classroom_id   BIGINT UNSIGNED NOT NULL,
  attempt_no     SMALLINT UNSIGNED NOT NULL,
  question_count SMALLINT UNSIGNED NOT NULL,
  correct_count  SMALLINT UNSIGNED NOT NULL,
  -- correct/question_count at completion time, 0–100 with 2 decimals.
  percent        DECIMAL(5,2) NOT NULL,
  completed_at   DATETIME NOT NULL,
  created_at     DATETIME NOT NULL,
  UNIQUE KEY uq_attempt_no (student_id, section_id, attempt_no),
  KEY ix_attempts_section (section_id, student_id),
  KEY ix_attempts_class (classroom_id),
  CONSTRAINT chk_attempt_counts CHECK (correct_count <= question_count),
  CONSTRAINT chk_attempt_percent CHECK (percent >= 0 AND percent <= 100),
  CONSTRAINT fk_satt_student FOREIGN KEY (student_id)   REFERENCES users(id)      ON DELETE CASCADE,
  CONSTRAINT fk_satt_section FOREIGN KEY (section_id)   REFERENCES sections(id)   ON DELETE CASCADE,
  CONSTRAINT fk_satt_class   FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Per-question record of one attempt — the teacher's review material.
-- Cascades with its attempt; cascades with a deleted question, which is
-- safe now because averages live on section_attempts, not here.
CREATE TABLE attempt_answers (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  attempt_id  BIGINT UNSIGNED NOT NULL,
  question_id BIGINT UNSIGNED NOT NULL,
  given       JSON NOT NULL,
  is_correct  TINYINT(1) NOT NULL,
  sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  KEY ix_answers_attempt (attempt_id),
  KEY ix_answers_question (question_id),
  CONSTRAINT fk_ans_attempt  FOREIGN KEY (attempt_id)  REFERENCES section_attempts(id) ON DELETE CASCADE,
  CONSTRAINT fk_ans_question FOREIGN KEY (question_id) REFERENCES questions(id)        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NFR-2/NFR-3: rollups read this aggregate, never the attempt log.
-- avg percent = percent_sum / attempts, computed in code.
CREATE TABLE student_section_stats (
  student_id        BIGINT UNSIGNED NOT NULL,
  section_id        BIGINT UNSIGNED NOT NULL,
  classroom_id      BIGINT UNSIGNED NOT NULL,
  attempts          SMALLINT UNSIGNED NOT NULL,
  percent_sum       DECIMAL(10,2) NOT NULL,
  last_completed_at DATETIME NOT NULL,
  PRIMARY KEY (student_id, section_id),
  KEY ix_stats_class (classroom_id),
  KEY ix_stats_section (section_id),
  CONSTRAINT fk_stat_student FOREIGN KEY (student_id)   REFERENCES users(id)      ON DELETE CASCADE,
  CONSTRAINT fk_stat_section FOREIGN KEY (section_id)   REFERENCES sections(id)   ON DELETE CASCADE,
  CONSTRAINT fk_stat_class   FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
