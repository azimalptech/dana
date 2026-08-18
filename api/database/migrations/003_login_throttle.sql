SET NAMES utf8mb4;

-- Brute-force protection for login.
--
-- Logins are phone numbers in a known format (+993 + 8 digits) and
-- passwords are short by policy, so the credential space is small enough
-- to guess at. Without throttling, an attacker can try passwords against
-- a known student number as fast as the server will answer.
--
-- Recorded per (login, ip) so one abusive source cannot lock every
-- student out of their own account by failing against their number.

CREATE TABLE login_attempts (
  id           BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  login        VARCHAR(20) NOT NULL,
  ip           VARCHAR(45) NULL,
  succeeded    TINYINT(1) NOT NULL DEFAULT 0,
  attempted_at DATETIME NOT NULL,
  KEY ix_attempts_lookup (login, ip, attempted_at),
  KEY ix_attempts_cleanup (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
