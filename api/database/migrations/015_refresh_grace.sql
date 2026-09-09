-- Session lifetime (FR-15.15).
--
-- Rotation is single-use, and a token presented twice was treated as
-- theft: the whole family was revoked and every device signed out. In
-- practice the second presentation is almost never a thief — it is a
-- retry after a lost response, or a second browser tab holding the
-- copy it read at page load. Real data from the dev database: 18
-- sessions killed in one second for one admin, 14 for a teacher, and
-- repeated pairs for the superadmin. That is the "logged out again and
-- again" the client reported.
--
-- `revoked_reason` lets the refresh path tell those apart: only a token
-- retired by ROTATION is eligible for the short grace window, and only
-- within it. A token revoked by logout, by the single-session rule, or
-- by a previous theft detection is never re-usable, and a rotated token
-- replayed after the window still burns the family.
ALTER TABLE refresh_tokens
  ADD COLUMN revoked_reason VARCHAR(16) NULL COMMENT 'rotated | logout | superseded | reuse | closure'
    AFTER revoked_at;

-- Everything already revoked predates the distinction. Rotation is by
-- far the most common reason, but assuming that here would hand a grace
-- window to tokens that were revoked by logout. Leaving them NULL means
-- "unknown", and unknown is not eligible — the safe reading.

-- Chains the successor to its parent, so a grace-window retry can be
-- logged as one session rather than looking like an unexplained extra.
ALTER TABLE refresh_tokens
  ADD COLUMN parent_id BIGINT UNSIGNED NULL AFTER user_id,
  ADD KEY ix_refresh_parent (parent_id);
