SET NAMES utf8mb4;

-- Dana — initial schema
-- Source of truth: docs/04-DATA-MODEL.md. Requirements: docs/01-REQUIREMENTS.md.
-- MySQL 8 / MariaDB 10.6+, InnoDB, utf8mb4.
--
-- Table order is FK-safe. The one cycle (users.classroom_id ->
-- classrooms.teacher_id -> users.id) is broken by adding that FK last.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------- org

CREATE TABLE centers (
  id         BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name       VARCHAR(160) NOT NULL,
  city       VARCHAR(120) NULL,
  address    VARCHAR(255) NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE levels (
  id         BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  name       VARCHAR(80) NOT NULL,          -- names only, no CEFR code (FR-3.13)
  slug       VARCHAR(80) NOT NULL,
  sort_order SMALLINT NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_levels_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One table for all four roles (FR-1.1..1.5).
CREATE TABLE users (
  id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  role            ENUM('superadmin','admin','teacher','student') NOT NULL,
  center_id       BIGINT UNSIGNED NULL,       -- NULL only for superadmin
  classroom_id    BIGINT UNSIGNED NULL,       -- students only, exactly one (FR-1.13)

  -- FR-1.16: login is a PHONE NUMBER entered by the teacher/admin.
  -- Stored E.164 ('+99365002233'). Unique system-wide. Never SMS-verified.
  login           VARCHAR(20) NOT NULL,

  -- Authentication path. Every login checks this and only this.
  password_hash   VARCHAR(255) NOT NULL,      -- bcrypt cost 12
  -- Display path (FR-1.10). role='student' ONLY; NULL for all other roles.
  password_ct     VARBINARY(320) NULL,        -- AES-256-GCM ciphertext
  password_iv     BINARY(12) NULL,
  password_tag    BINARY(16) NULL,
  password_set_at DATETIME NULL,

  full_name       VARCHAR(160) NOT NULL,
  interface_lang  ENUM('tk','ru') NULL,       -- no English (FR-2.6)
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_by      BIGINT UNSIGNED NULL,
  last_login_at   DATETIME NULL,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,

  UNIQUE KEY uq_users_login (login),
  KEY ix_users_center_role (center_id, role, is_active),
  KEY ix_users_classroom (classroom_id, is_active),
  CONSTRAINT fk_users_center  FOREIGN KEY (center_id)  REFERENCES centers(id),
  CONSTRAINT fk_users_creator FOREIGN KEY (created_by) REFERENCES users(id),

  -- Classroom membership belongs to a STUDENT and nobody else (FR-1.13).
  -- A teacher owns classrooms through classrooms.teacher_id and may own
  -- several (FR-1.15); a teacher row must never carry a classroom_id,
  -- or "which class is this teacher in" becomes answerable two ways.
  CONSTRAINT chk_users_classroom CHECK (classroom_id IS NULL OR role = 'student'),

  -- Only the superadmin is centre-less; everyone else is scoped (FR-1.2).
  CONSTRAINT chk_users_center CHECK (
       (role =  'superadmin' AND center_id IS NULL)
    OR (role <> 'superadmin' AND center_id IS NOT NULL)),

  -- FR-1.11: only student passwords are recoverable. Staff rows must
  -- never hold reversible ciphertext.
  CONSTRAINT chk_users_password_ct CHECK (password_ct IS NULL OR role = 'student')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FR-12.2: one active session per student.
CREATE TABLE refresh_tokens (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id     BIGINT UNSIGNED NOT NULL,
  token_hash  CHAR(64) NOT NULL,
  device_info VARCHAR(255) NULL,
  expires_at  DATETIME NOT NULL,
  revoked_at  DATETIME NULL,
  created_at  DATETIME NOT NULL,
  UNIQUE KEY uq_refresh_hash (token_hash),
  KEY ix_refresh_user (user_id, revoked_at),
  CONSTRAINT fk_refresh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------- curriculum

-- FR-3.10: Student's Book + Workbook pair. FR-3.9: no series assumed.
CREATE TABLE book_sets (
  id         BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  level_id   BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(200) NOT NULL,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  KEY ix_booksets_level (level_id, is_active),
  CONSTRAINT fk_bookset_level FOREIGN KEY (level_id) REFERENCES levels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE books (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  book_set_id BIGINT UNSIGNED NOT NULL,
  level_id    BIGINT UNSIGNED NOT NULL,
  kind        ENUM('students_book','workbook') NOT NULL,
  title       VARCHAR(200) NOT NULL,
  edition     VARCHAR(80) NULL,
  file_path   VARCHAR(255) NOT NULL,      -- outside web root, 500 MB cap (FR-3.11)
  page_count  SMALLINT UNSIGNED NULL,
  text_status ENUM('pending','extracting','ready','failed') NOT NULL DEFAULT 'pending',
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  KEY ix_books_level (level_id, kind),
  UNIQUE KEY uq_book_kind (book_set_id, kind),
  CONSTRAINT fk_books_set   FOREIGN KEY (book_set_id) REFERENCES book_sets(id) ON DELETE CASCADE,
  CONSTRAINT fk_books_level FOREIGN KEY (level_id)    REFERENCES levels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extracted page text. Generation reads ONLY from here, never the PDF.
CREATE TABLE book_pages (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  book_id     BIGINT UNSIGNED NOT NULL,
  page_number SMALLINT UNSIGNED NOT NULL,
  raw_text    MEDIUMTEXT NULL,
  UNIQUE KEY uq_page (book_id, page_number),
  CONSTRAINT fk_pages_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE units (
  id         BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  level_id   BIGINT UNSIGNED NOT NULL,
  number     SMALLINT UNSIGNED NOT NULL,
  title      VARCHAR(200) NULL,
  sort_order SMALLINT NOT NULL,
  UNIQUE KEY uq_unit (level_id, number),
  CONSTRAINT fk_units_level FOREIGN KEY (level_id) REFERENCES levels(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The atomic unit of the system: 1A, 1B, 1C, 2A … (FR-3.12).
-- Section count per unit varies; codes are free text (FR-3.9).
CREATE TABLE unit_sections (
  id             BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  unit_id        BIGINT UNSIGNED NOT NULL,
  code           VARCHAR(8) NOT NULL,       -- 'A', 'B', 'Review' → shown as 1A
  title          VARCHAR(200) NULL,
  sort_order     SMALLINT NOT NULL,
  -- Teaching order across the whole level. Drives the grammar and
  -- vocabulary ceilings (FR-4.6, FR-4.7): everything with a lower
  -- value counts as already taught.
  level_position INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_section (unit_id, code),
  KEY ix_section_position (level_position),
  CONSTRAINT fk_sections_unit FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FR-4.15: page ranges must be confirmed by a human before generation.
CREATE TABLE section_sources (
  id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  unit_section_id BIGINT UNSIGNED NOT NULL,
  book_id         BIGINT UNSIGNED NOT NULL,
  page_from       SMALLINT UNSIGNED NOT NULL,
  page_to         SMALLINT UNSIGNED NOT NULL,
  exclusions      JSON NULL,                 -- listening / reading / speaking (FR-4.9)
  confirmed_by    BIGINT UNSIGNED NULL,
  confirmed_at    DATETIME NULL,
  KEY ix_srcs_section (unit_section_id),
  CONSTRAINT fk_srcs_section FOREIGN KEY (unit_section_id) REFERENCES unit_sections(id) ON DELETE CASCADE,
  CONSTRAINT fk_srcs_book    FOREIGN KEY (book_id)         REFERENCES books(id),
  CONSTRAINT fk_srcs_user    FOREIGN KEY (confirmed_by)    REFERENCES users(id),
  CONSTRAINT chk_srcs_pages  CHECK (page_to >= page_from)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FR-4.18: provider is pluggable; Claude enabled first.
CREATE TABLE generation_runs (
  id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  unit_section_id BIGINT UNSIGNED NOT NULL,
  target          ENUM('exercises','grammar','both') NOT NULL,
  status          ENUM('queued','running','needs_review','failed','published') NOT NULL DEFAULT 'queued',
  requested_by    BIGINT UNSIGNED NOT NULL,
  provider        ENUM('claude','gemini','deepseek') NOT NULL DEFAULT 'claude',
  model           VARCHAR(64) NULL,
  prompt_version  VARCHAR(32) NULL,
  input_tokens    INT UNSIGNED NULL,
  output_tokens   INT UNSIGNED NULL,
  validation      JSON NULL,                 -- per-gate results, docs/05
  error_message   TEXT NULL,
  started_at      DATETIME NULL,
  finished_at     DATETIME NULL,
  created_at      DATETIME NOT NULL,
  KEY ix_runs_status (status, created_at),
  CONSTRAINT fk_runs_section FOREIGN KEY (unit_section_id) REFERENCES unit_sections(id) ON DELETE CASCADE,
  CONSTRAINT fk_runs_user    FOREIGN KEY (requested_by)    REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------- section content

CREATE TABLE vocabulary_items (
  id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  unit_section_id BIGINT UNSIGNED NOT NULL,
  term_en         VARCHAR(160) NOT NULL,
  part_of_speech  VARCHAR(32) NULL,
  ipa             VARCHAR(120) NULL,
  translation_tk  VARCHAR(255) NOT NULL,
  translation_ru  VARCHAR(255) NOT NULL,
  example_en      VARCHAR(500) NULL,
  audio_path      VARCHAR(255) NULL,
  sort_order      SMALLINT NOT NULL DEFAULT 0,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  KEY ix_vocab_section (unit_section_id),
  CONSTRAINT fk_vocab_section FOREIGN KEY (unit_section_id) REFERENCES unit_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE grammar_explanations (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  unit_section_id  BIGINT UNSIGNED NOT NULL,
  title_tk         VARCHAR(200) NOT NULL,
  title_ru         VARCHAR(200) NOT NULL,
  body_tk          MEDIUMTEXT NOT NULL,
  body_ru          MEDIUMTEXT NOT NULL,
  examples         JSON NULL,
  status           ENUM('draft','in_review','published') NOT NULL DEFAULT 'draft',
  generated_run_id BIGINT UNSIGNED NULL,
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NOT NULL,
  UNIQUE KEY uq_grammar_section (unit_section_id),
  CONSTRAINT fk_grammar_section FOREIGN KEY (unit_section_id)  REFERENCES unit_sections(id) ON DELETE CASCADE,
  CONSTRAINT fk_grammar_run     FOREIGN KEY (generated_run_id) REFERENCES generation_runs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exercise_sets (
  id               BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  unit_section_id  BIGINT UNSIGNED NOT NULL,
  type             ENUM('reorder','match_pairs','multiple_choice','fill_blank') NOT NULL,
  title_tk         VARCHAR(200) NOT NULL,
  title_ru         VARCHAR(200) NOT NULL,
  instructions_tk  VARCHAR(500) NULL,
  instructions_ru  VARCHAR(500) NULL,
  -- fill_blank only. v1 is always 'word_bank' (FR-4.19).
  input_mode       ENUM('typing','word_bank') NULL,
  -- match_pairs only, six modes (FR-4.16, FR-4.21).
  pair_mode        ENUM('translation','definition','sentence_halves',
                        'question_answer','synonym','antonym') NULL,
  status           ENUM('draft','in_review','published') NOT NULL DEFAULT 'draft',
  generated_run_id BIGINT UNSIGNED NULL,
  sort_order       SMALLINT NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL,
  updated_at       DATETIME NOT NULL,
  KEY ix_sets_section (unit_section_id, status),
  CONSTRAINT fk_sets_section FOREIGN KEY (unit_section_id)  REFERENCES unit_sections(id) ON DELETE CASCADE,
  CONSTRAINT fk_sets_run     FOREIGN KEY (generated_run_id) REFERENCES generation_runs(id) ON DELETE SET NULL,
  -- Type-specific settings may only appear on their own type.
  CONSTRAINT chk_sets_pair_mode  CHECK (pair_mode  IS NULL OR type = 'match_pairs'),
  CONSTRAINT chk_sets_input_mode CHECK (input_mode IS NULL OR type = 'fill_blank')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bilingual fields carry INSTRUCTIONS only; English content lives in
-- payload (FR-4.14). Editor shows prompt_tk left, prompt_ru right (FR-4.13).
CREATE TABLE questions (
  id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  exercise_set_id BIGINT UNSIGNED NOT NULL,
  sort_order      SMALLINT NOT NULL,
  prompt_tk       VARCHAR(500) NULL,
  prompt_ru       VARCHAR(500) NULL,
  payload         JSON NOT NULL,
  -- Provenance. A question that cannot name its source is a bug (FR-4.3).
  source_book_id  BIGINT UNSIGNED NULL,
  source_page     SMALLINT UNSIGNED NULL,
  source_sentence TEXT NULL,
  change_ratio    DECIMAL(4,3) NULL,         -- measured, target 0.20–0.25 (FR-4.4)
  is_human_edited TINYINT(1) NOT NULL DEFAULT 0,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  created_at      DATETIME NOT NULL,
  updated_at      DATETIME NOT NULL,
  KEY ix_q_set (exercise_set_id, sort_order),
  CONSTRAINT fk_q_set  FOREIGN KEY (exercise_set_id) REFERENCES exercise_sets(id) ON DELETE CASCADE,
  CONSTRAINT fk_q_book FOREIGN KEY (source_book_id)  REFERENCES books(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------- classrooms and unlocking

CREATE TABLE classrooms (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  center_id   BIGINT UNSIGNED NOT NULL,
  teacher_id  BIGINT UNSIGNED NOT NULL,
  level_id    BIGINT UNSIGNED NOT NULL,
  book_set_id BIGINT UNSIGNED NOT NULL,      -- SB + WB pair (FR-3.10)
  name        VARCHAR(120) NOT NULL,
  started_on  DATE NULL,
  closed_at   DATETIME NULL,                 -- course finished → purge (FR-1.14)
  capacity    SMALLINT UNSIGNED NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_by  BIGINT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  KEY ix_class_center (center_id, is_active),
  KEY ix_class_teacher (teacher_id, is_active),
  CONSTRAINT fk_class_center  FOREIGN KEY (center_id)   REFERENCES centers(id),
  CONSTRAINT fk_class_teacher FOREIGN KEY (teacher_id)  REFERENCES users(id),
  CONSTRAINT fk_class_level   FOREIGN KEY (level_id)    REFERENCES levels(id),
  CONSTRAINT fk_class_bookset FOREIGN KEY (book_set_id) REFERENCES book_sets(id),
  CONSTRAINT fk_class_creator FOREIGN KEY (created_by)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Closes the cycle. No join table: one student, one classroom (FR-1.13).
ALTER TABLE users
  ADD CONSTRAINT fk_users_classroom
  FOREIGN KEY (classroom_id) REFERENCES classrooms(id);

-- FR-7.4: teacher taps "Start teaching" on a section.
CREATE TABLE section_unlocks (
  id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  classroom_id    BIGINT UNSIGNED NOT NULL,
  unit_section_id BIGINT UNSIGNED NOT NULL,
  unlocked_by     BIGINT UNSIGNED NOT NULL,
  unlocked_at     DATETIME NOT NULL,
  UNIQUE KEY uq_unlock (classroom_id, unit_section_id),
  CONSTRAINT fk_unlock_class   FOREIGN KEY (classroom_id)    REFERENCES classrooms(id) ON DELETE CASCADE,
  CONSTRAINT fk_unlock_section FOREIGN KEY (unit_section_id) REFERENCES unit_sections(id),
  CONSTRAINT fk_unlock_user    FOREIGN KEY (unlocked_by)     REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------- progress and points

-- FR-8.4 / FR-8.6: the unique key IS the "points once per question,
-- ever" guarantee. Do not weaken it.
CREATE TABLE question_results (
  id             BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  student_id     BIGINT UNSIGNED NOT NULL,
  question_id    BIGINT UNSIGNED NOT NULL,
  classroom_id   BIGINT UNSIGNED NOT NULL,
  first_correct  TINYINT(1) NOT NULL,        -- outcome of the FIRST attempt
  points_awarded TINYINT UNSIGNED NOT NULL,  -- 5 or 3, never anything else
  attempts       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  resolved_at    DATETIME NULL,
  created_at     DATETIME NOT NULL,
  UNIQUE KEY uq_result (student_id, question_id),
  KEY ix_result_board (classroom_id, student_id),
  CONSTRAINT fk_res_student  FOREIGN KEY (student_id)   REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_res_question FOREIGN KEY (question_id)  REFERENCES questions(id) ON DELETE CASCADE,
  CONSTRAINT fk_res_class    FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE,

  -- FR-8.2 / FR-8.3: 5 for a first-attempt correct, 3 for incorrect.
  -- No other value is reachable, and the score can never disagree with
  -- the correctness flag it is derived from.
  CONSTRAINT chk_result_points CHECK (
       (first_correct = 1 AND points_awarded = 5)
    OR (first_correct = 0 AND points_awarded = 3))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- FR-8.7: rows above are written ONLY in the transaction that flips
-- status to 'completed'. An abandoned attempt scores nothing.
CREATE TABLE exercise_attempts (
  id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  student_id      BIGINT UNSIGNED NOT NULL,
  exercise_set_id BIGINT UNSIGNED NOT NULL,
  classroom_id    BIGINT UNSIGNED NOT NULL,
  status          ENUM('in_progress','completed','abandoned') NOT NULL DEFAULT 'in_progress',
  points_total    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  correct_count   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  question_count  SMALLINT UNSIGNED NOT NULL,
  started_at      DATETIME NOT NULL,
  completed_at    DATETIME NULL,
  KEY ix_attempt_student (student_id, exercise_set_id),
  CONSTRAINT fk_att_student FOREIGN KEY (student_id)      REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_set     FOREIGN KEY (exercise_set_id) REFERENCES exercise_sets(id) ON DELETE CASCADE,
  CONSTRAINT fk_att_class   FOREIGN KEY (classroom_id)    REFERENCES classrooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NFR-2: leaderboard is an aggregate, not a scan. Updated in the same
-- transaction that completes an attempt, so it cannot drift.
CREATE TABLE student_scores (
  student_id     BIGINT UNSIGNED PRIMARY KEY,
  classroom_id   BIGINT UNSIGNED NOT NULL,
  points_total   INT UNSIGNED NOT NULL DEFAULT 0,
  questions_done INT UNSIGNED NOT NULL DEFAULT 0,
  correct_count  INT UNSIGNED NOT NULL DEFAULT 0,
  last_scored_at DATETIME NULL,              -- FR-12.8 tie-break
  KEY ix_scores_board (classroom_id, points_total DESC),
  CONSTRAINT fk_scores_student FOREIGN KEY (student_id)   REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_scores_class   FOREIGN KEY (classroom_id) REFERENCES classrooms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------- engagement features

CREATE TABLE student_bookmarks (
  student_id         BIGINT UNSIGNED NOT NULL,
  vocabulary_item_id BIGINT UNSIGNED NOT NULL,
  created_at         DATETIME NOT NULL,
  PRIMARY KEY (student_id, vocabulary_item_id),
  CONSTRAINT fk_bm_student FOREIGN KEY (student_id)         REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_bm_vocab   FOREIGN KEY (vocabulary_item_id) REFERENCES vocabulary_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Serves BOTH profile stats. activity_date is computed server-side in
-- Asia/Ashgabat, never from the device clock (FR-8.10, FR-8.11).
CREATE TABLE student_activity_days (
  student_id          BIGINT UNSIGNED NOT NULL,
  activity_date       DATE NOT NULL,
  seconds_studied     INT UNSIGNED NOT NULL DEFAULT 0,
  exercises_completed SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (student_id, activity_date),
  CONSTRAINT fk_act_student FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------ notifications and auditing

CREATE TABLE notifications (
  id           BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  sender_id    BIGINT UNSIGNED NOT NULL,
  scope        ENUM('all','center','classroom') NOT NULL,
  center_id    BIGINT UNSIGNED NULL,
  classroom_id BIGINT UNSIGNED NULL,         -- FR-12.12
  title_tk     VARCHAR(160) NOT NULL,
  title_ru     VARCHAR(160) NOT NULL,
  body_tk      VARCHAR(1000) NOT NULL,
  body_ru      VARCHAR(1000) NOT NULL,
  sent_at      DATETIME NULL,
  created_at   DATETIME NOT NULL,
  KEY ix_notif_scope (scope, created_at),
  CONSTRAINT fk_notif_sender FOREIGN KEY (sender_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- In-app inbox: readable even when push is unavailable (FR-10.3).
CREATE TABLE notification_receipts (
  id              BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  notification_id BIGINT UNSIGNED NOT NULL,
  user_id         BIGINT UNSIGNED NOT NULL,
  read_at         DATETIME NULL,
  UNIQUE KEY uq_receipt (notification_id, user_id),
  KEY ix_receipt_user (user_id, read_at),
  CONSTRAINT fk_receipt_notif FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE,
  CONSTRAINT fk_receipt_user  FOREIGN KEY (user_id)         REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE device_tokens (
  id         BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  token      VARCHAR(255) NOT NULL,
  platform   ENUM('ios','android') NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uq_token (token),
  KEY ix_token_user (user_id),
  CONSTRAINT fk_token_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every password reveal lands here (FR-1.12), plus unlocks and closures.
CREATE TABLE audit_log (
  id          BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  actor_id    BIGINT UNSIGNED NULL,
  action      VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id   BIGINT UNSIGNED NULL,
  meta        JSON NULL,
  ip          VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL,
  KEY ix_audit_entity (entity_type, entity_id),
  KEY ix_audit_actor (actor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
