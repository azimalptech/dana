SET NAMES utf8mb4;

-- 010 — FR-13.18: the centre admin may VIEW current teacher passwords,
-- so teachers join students in recoverable storage (client decision,
-- security trade-off stated and accepted). Admin and superadmin
-- passwords remain hash-only, reset-only.
--
-- Existing teacher rows keep password_ct = NULL: their password was
-- hashed before recoverable storage existed and cannot be recovered.
-- The reveal endpoint answers those with a 422 telling the admin to set
-- a new password, which writes the recoverable copy.
ALTER TABLE users DROP CONSTRAINT chk_users_password_ct;
ALTER TABLE users ADD CONSTRAINT chk_users_password_ct
  CHECK (password_ct IS NULL OR role IN ('student', 'teacher'));
