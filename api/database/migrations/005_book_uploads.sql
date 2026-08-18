SET NAMES utf8mb4;

-- Resumable, chunked book uploads.
--
-- FR-3.11 allows 500 MB per file, but PHP's upload_max_filesize is 40 MB
-- here and would be some other arbitrary value on the production host.
-- Rather than depend on server configuration we accept the file in small
-- pieces, which also means a dropped connection resumes instead of
-- restarting a 500 MB transfer.

CREATE TABLE book_uploads (
  id            BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  book_set_id   BIGINT UNSIGNED NOT NULL,
  kind          ENUM('students_book','workbook') NOT NULL,
  title         VARCHAR(200) NOT NULL,
  filename      VARCHAR(255) NOT NULL,
  expected_size BIGINT UNSIGNED NOT NULL,
  received_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
  temp_path     VARCHAR(255) NOT NULL,
  status        ENUM('open','complete','failed') NOT NULL DEFAULT 'open',
  created_by    BIGINT UNSIGNED NOT NULL,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  KEY ix_uploads_status (status, created_at),
  CONSTRAINT fk_upload_set  FOREIGN KEY (book_set_id) REFERENCES book_sets(id) ON DELETE CASCADE,
  CONSTRAINT fk_upload_user FOREIGN KEY (created_by)  REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
