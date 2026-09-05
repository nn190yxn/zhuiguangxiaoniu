<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/kernel/ApiException.php';
require_once __DIR__ . '/../api/lesson-library/LessonArchiveService.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('CREATE TABLE lesson_submissions (
    id INTEGER PRIMARY KEY,
    status TEXT NOT NULL,
    status_version INTEGER NOT NULL,
    approved_version_id INTEGER,
    library_status TEXT NOT NULL,
    library_published_at TEXT,
    library_published_by_staff_id INTEGER
)');
$database->exec('CREATE TABLE lesson_versions (
    id INTEGER PRIMARY KEY,
    submission_id INTEGER NOT NULL,
    is_submitted INTEGER NOT NULL,
    is_immutable INTEGER NOT NULL
)');
$database->exec('CREATE TABLE lesson_review_tasks (id INTEGER PRIMARY KEY, submission_id INTEGER NOT NULL, version_id INTEGER NOT NULL)');
$database->exec('CREATE TABLE lesson_exports (id INTEGER PRIMARY KEY, submission_id INTEGER NOT NULL, version_id INTEGER NOT NULL)');
$database->exec('CREATE TABLE lesson_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL,
    version_id INTEGER,
    actor_staff_id INTEGER,
    action TEXT NOT NULL,
    from_status TEXT,
    to_status TEXT,
    metadata_json TEXT
)');

$database->exec("INSERT INTO lesson_submissions VALUES (1, 'approved', 4, 11, 'published', '2026-09-05 10:00:00', 31)");
$database->exec('INSERT INTO lesson_versions VALUES (11, 1, 1, 1)');
$database->exec('INSERT INTO lesson_review_tasks VALUES (21, 1, 11)');
$database->exec('INSERT INTO lesson_exports VALUES (31, 1, 11)');

$service = new LessonArchiveService($database);
$result = $service->archive(1, 41, 4, '课程标准更新');
$check($result['status'] === 'archived' && $result['library_status'] === 'archived', '归档应同步更新主状态和正式库状态');
$check($result['approved_version_id'] === 11 && $result['status_version'] === 5, '归档应保留批准版本并推进状态版本');

$submission = $database->query('SELECT * FROM lesson_submissions WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
$check($submission['status'] === 'archived' && $submission['library_status'] === 'archived', '归档记录应退出正式库活跃发现');
$check((int) $submission['approved_version_id'] === 11, '批准版本引用应保留');
$check($submission['library_published_at'] === '2026-09-05 10:00:00' && (int) $submission['library_published_by_staff_id'] === 31, '正式库发布历史应保留');
$check((int) $database->query('SELECT COUNT(*) FROM lesson_versions WHERE submission_id = 1')->fetchColumn() === 1, '版本引用应保留');
$check((int) $database->query('SELECT COUNT(*) FROM lesson_review_tasks WHERE submission_id = 1')->fetchColumn() === 1, '审核任务引用应保留');
$check((int) $database->query('SELECT COUNT(*) FROM lesson_exports WHERE submission_id = 1')->fetchColumn() === 1, '导出引用应保留');

$audit = $database->query('SELECT * FROM lesson_audit_logs WHERE submission_id = 1')->fetch(PDO::FETCH_ASSOC);
$metadata = json_decode((string) $audit['metadata_json'], true, 512, JSON_THROW_ON_ERROR);
$check($audit['action'] === 'lesson_archived' && $audit['from_status'] === 'approved' && $audit['to_status'] === 'archived', '归档审计应记录状态迁移');
$check((int) $audit['version_id'] === 11 && (int) $audit['actor_staff_id'] === 41, '归档审计应绑定批准版本和操作人');
$check($metadata['reason'] === '课程标准更新' && $metadata['previous_library_status'] === 'published', '归档审计应记录原因和原正式库状态');

$database->exec("INSERT INTO lesson_submissions VALUES (2, 'approved', 3, 12, 'published', '2026-09-05 11:00:00', 31)");
$database->exec('INSERT INTO lesson_versions VALUES (12, 2, 1, 1)');
try {
    $service->archive(2, 41, 2);
    throw new RuntimeException('旧状态版本应触发冲突');
} catch (PlatformApiException $error) {
    $check($error->httpStatus() === 409 && $error->errorCode() === 'lesson_submission_conflict', '旧状态版本应返回稳定冲突');
}
$check($database->query("SELECT status FROM lesson_submissions WHERE id = 2")->fetchColumn() === 'approved', '冲突事务应保持原状态');

fwrite(STDOUT, "lesson_archive_service.test.php passed\n");
