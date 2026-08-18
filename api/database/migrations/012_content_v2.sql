SET NAMES utf8mb4;

-- ============================================================ content v2
-- The client's canonical content files (Listening/Grammar/Vocabulary/
-- UnitQuiz/Wordlist .xlsx, 2026-08-13) define the content model:
--
--  - every question carries its file identity (Question ID), Topic,
--    Subtopic, an English Rule (instruction) and an English Answer
--    Description (shown after answering);
--  - question stems and MC options are [audio|image|text] parts where
--    audio/image arrive as NOTES ("seven", "IMG_PEN") and the actual
--    file is uploaded later in the panel. A question with missing media
--    is auto-hidden from students (client decision 2026-08-13);
--  - the Unit Quiz is a FIXED random draw per child unit: N vocabulary +
--    N grammar + N listening eligible questions, same set for everyone.

ALTER TABLE questions
    ADD COLUMN external_code VARCHAR(24) NULL DEFAULT NULL AFTER id,
    ADD COLUMN topic VARCHAR(120) NULL DEFAULT NULL AFTER quiz_eligible,
    ADD COLUMN subtopic VARCHAR(120) NULL DEFAULT NULL AFTER topic,
    ADD COLUMN rule_en VARCHAR(255) NULL DEFAULT NULL AFTER subtopic,
    ADD COLUMN answer_description_en VARCHAR(500) NULL DEFAULT NULL AFTER rule_en,
    ADD UNIQUE KEY uq_questions_external_code (external_code);

ALTER TABLE vocabulary_items
    ADD COLUMN external_code VARCHAR(24) NULL DEFAULT NULL AFTER id,
    ADD COLUMN category VARCHAR(120) NULL DEFAULT NULL AFTER external_code,
    ADD COLUMN word_type ENUM('word','phrase') NOT NULL DEFAULT 'word' AFTER category,
    ADD UNIQUE KEY uq_vocabulary_external_code (external_code);

-- Per-child-unit quiz targets (UnitQuiz.xlsx "Unit Quiz Target"). NULL =
-- no target defined; the quiz then serves every eligible question as
-- before, so old units keep working until their targets are entered.
ALTER TABLE unit_sections
    ADD COLUMN quiz_target_vocabulary TINYINT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN quiz_target_grammar TINYINT UNSIGNED NULL DEFAULT NULL,
    ADD COLUMN quiz_target_listening TINYINT UNSIGNED NULL DEFAULT NULL;

-- The FIXED quiz draw (client decision 2026-08-13: one set for
-- everyone). Regenerated only when the superadmin redraws or a drawn
-- question stops being servable.
CREATE TABLE quiz_draws (
    unit_section_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (unit_section_id, question_id),
    KEY idx_quiz_draws_unit (unit_section_id),
    CONSTRAINT fk_quiz_draws_unit FOREIGN KEY (unit_section_id)
        REFERENCES unit_sections (id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_draws_question FOREIGN KEY (question_id)
        REFERENCES questions (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Books are gone from the product entirely (client, 2026-08-13):
-- content is uploaded manually or via xlsx, so a classroom no longer
-- names a book set. The column stays for old rows but stops being
-- required.
ALTER TABLE classrooms
    MODIFY COLUMN book_set_id BIGINT UNSIGNED NULL DEFAULT NULL;
