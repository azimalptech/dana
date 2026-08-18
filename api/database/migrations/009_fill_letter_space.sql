SET NAMES utf8mb4;

-- 009 — Fill letter space, the fifth exercise type (FR-13.21).
ALTER TABLE exercise_sets
  MODIFY type ENUM('reorder','match_pairs','multiple_choice','fill_blank','fill_letter_space') NOT NULL;
