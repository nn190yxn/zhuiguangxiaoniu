-- Read-only triage for the development window. Adjust dates before execution.
-- Run against an approved snapshot/read replica first. Never infer corruption
-- from these candidates alone; retain source files and historical versions.

-- Historical pending suggestions no longer attached to the current version.
SELECT s.id AS submission_id, s.status, s.current_version_id,
       COUNT(*) AS historical_pending_count
FROM lesson_submissions s
JOIN lesson_suggestions g ON g.submission_id = s.id
WHERE g.decision = 'pending'
  AND g.version_id <> s.current_version_id
  AND g.created_at >= '2026-09-05 00:00:00'
  AND g.created_at < '2026-09-08 00:00:00'
GROUP BY s.id, s.status, s.current_version_id
ORDER BY s.id
LIMIT 200;

-- Accepted-suggestion versions: candidate set for manual comparison only.
-- Return IDs instead of lesson text or author information.
SELECT v.id AS candidate_version_id, v.submission_id, v.version_no,
       v.is_submitted, v.is_immutable, v.created_at,
       JSON_UNQUOTE(JSON_EXTRACT(v.source_snapshot_json, '$.previous_version_id')) AS previous_version_id,
       JSON_UNQUOTE(JSON_EXTRACT(v.source_snapshot_json, '$.suggestion_id')) AS suggestion_id
FROM lesson_versions v
WHERE v.created_at >= '2026-09-05 00:00:00'
  AND v.created_at < '2026-09-08 00:00:00'
  AND JSON_VALID(v.source_snapshot_json)
  AND JSON_UNQUOTE(JSON_EXTRACT(v.source_snapshot_json, '$.decision')) = 'accepted'
ORDER BY v.id
LIMIT 200;

-- Valid JSON check, without returning stored content.
SELECT id AS version_id, submission_id, version_no, created_at
FROM lesson_versions
WHERE created_at >= '2026-09-05 00:00:00'
  AND created_at < '2026-09-08 00:00:00'
  AND JSON_VALID(content_json) = 0
ORDER BY id
LIMIT 200;

-- Client-only exam submissions have no reliable server-side denominator.
-- Missing exam records cannot be enumerated or recreated from this database.
