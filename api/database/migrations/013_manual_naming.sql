-- 013 — manual naming (client decision 2026-08-20: "no auto numbers,
-- I name units completely myself").
--
-- `units.name`: when set, it IS the unit's display identity, verbatim.
-- The numeric `number` column stays as the internal ordering /
-- uniqueness key (uq_unit) and as the fallback identity for legacy rows
-- ("Юнит {number}" style composition wherever the client composes it).
--
-- `unit_sections.label`: when set, it IS the child unit's display label
-- verbatim; legacy rows keep composing "{number}-{code}" wherever the
-- current code does. `code` stays required — it is the uniqueness key
-- (uq_section) and the xlsx import/export join key.

ALTER TABLE units ADD COLUMN name VARCHAR(120) NULL AFTER number;

ALTER TABLE unit_sections ADD COLUMN label VARCHAR(32) NULL AFTER code;
