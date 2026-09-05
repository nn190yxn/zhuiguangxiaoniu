ALTER TABLE lesson_submissions
    ADD COLUMN library_status VARCHAR(16) NOT NULL DEFAULT 'hidden' AFTER approved_version_id,
    ADD COLUMN library_published_at DATETIME NULL AFTER library_status,
    ADD COLUMN library_published_by_staff_id INT UNSIGNED NULL AFTER library_published_at,
    ADD KEY idx_lesson_submissions_library (library_status, library_published_at);

UPDATE lesson_submissions
SET library_status = 'published',
    library_published_at = COALESCE(library_published_at, updated_at)
WHERE status = 'approved'
  AND approved_version_id IS NOT NULL
  AND library_status = 'hidden';
