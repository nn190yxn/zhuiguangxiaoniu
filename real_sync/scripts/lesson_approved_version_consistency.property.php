<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/kernel/ApiException.php';
require_once __DIR__ . '/../api/lesson-reviews/LessonReviewDecisionService.php';
require_once __DIR__ . '/../api/lesson-library/LessonLibraryQueryService.php';

$caseCount = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT);
if ($caseCount === false || $caseCount < 1 || $caseCount > 1000) {
    fwrite(STDERR, "case count must be between 1 and 1000\n");
    exit(1);
}

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$database->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
$database->exec(<<<'SQL'
CREATE TABLE lesson_submissions (
    id INTEGER PRIMARY KEY,
    store_id INTEGER,
    store_name TEXT NOT NULL,
    author_staff_id INTEGER NOT NULL,
    author_name TEXT NOT NULL,
    course_line TEXT NOT NULL,
    class_level TEXT NOT NULL,
    lesson_date TEXT NOT NULL,
    title TEXT NOT NULL,
    status TEXT NOT NULL,
    status_version INTEGER NOT NULL,
    current_version_id INTEGER NOT NULL,
    approved_version_id INTEGER,
    library_status TEXT NOT NULL,
    library_published_at TEXT,
    library_published_by_staff_id INTEGER
);
CREATE TABLE lesson_versions (
    id INTEGER PRIMARY KEY,
    submission_id INTEGER NOT NULL,
    version_no INTEGER NOT NULL,
    content_json TEXT NOT NULL,
    source_snapshot_json TEXT,
    version_type TEXT NOT NULL,
    is_submitted INTEGER NOT NULL,
    is_immutable INTEGER NOT NULL,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL,
    UNIQUE (submission_id, version_no)
);
CREATE TABLE lesson_review_tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL,
    version_id INTEGER NOT NULL,
    reviewer_staff_id INTEGER NOT NULL,
    reviewer_role TEXT NOT NULL,
    stage TEXT NOT NULL,
    status TEXT NOT NULL,
    decision TEXT,
    comments TEXT,
    decided_at TEXT
);
CREATE TABLE lesson_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL,
    version_id INTEGER,
    actor_staff_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    from_status TEXT,
    to_status TEXT,
    metadata_json TEXT
);
SQL);

$insertSubmission = $database->prepare(
    "INSERT INTO lesson_submissions (id, store_id, store_name, author_staff_id, author_name, course_line, class_level, lesson_date, title, status, status_version, current_version_id, approved_version_id, library_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'supervisor_review', ?, ?, NULL, 'hidden')"
);
$insertVersion = $database->prepare(
    'INSERT INTO lesson_versions (id, submission_id, version_no, content_json, source_snapshot_json, version_type, is_submitted, is_immutable, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertTask = $database->prepare(
    "INSERT INTO lesson_review_tasks (submission_id, version_id, reviewer_staff_id, reviewer_role, stage, status) VALUES (?, ?, ?, 'teaching_supervisor', 'supervisor_review', 'pending')"
);
$changeCurrent = $database->prepare('UPDATE lesson_submissions SET current_version_id = ? WHERE id = ?');
$approvedRelation = $database->prepare(
    "SELECT task.version_id AS task_version_id, submission.approved_version_id FROM lesson_submissions submission JOIN lesson_review_tasks task ON task.submission_id = submission.id AND task.stage = 'supervisor_review' AND task.status = 'completed' AND task.decision = 'approved' WHERE submission.id = ?"
);

$decisionService = new LessonReviewDecisionService($database);
$libraryService = new LessonLibraryQueryService($database);
$expected = [];
$perturbedCount = 0;

for ($seed = 1; $seed <= $caseCount; $seed++) {
    $submissionId = $seed;
    $reviewerStaffId = 5000 + ($seed % 17);
    $approvedVersionId = $seed * 100 + (($seed * 37) % 41) + 1;
    $laterVersionId = $seed * 100 + (($seed * 53) % 41) + 50;
    $approvedVersionNo = ($seed % 7) + 1;
    $laterVersionNo = $approvedVersionNo + 1;
    $statusVersion = ($seed % 9) + 1;
    $createdAt = sprintf('2026-09-%02d 08:%02d:00', ($seed % 28) + 1, $seed % 60);

    $insertSubmission->execute([
        $submissionId,
        ($seed % 13) + 1,
        '属性门店-' . ($seed % 13),
        1000 + $seed,
        '属性教练-' . $seed,
        $seed % 2 === 0 ? '体适能' : '感统',
        '级别-' . ($seed % 5),
        sprintf('2026-08-%02d', ($seed % 28) + 1),
        '批准版本一致性-' . $seed,
        $statusVersion,
        $approvedVersionId,
    ]);
    $insertVersion->execute([
        $approvedVersionId,
        $submissionId,
        $approvedVersionNo,
        json_encode(['marker' => 'approved-' . $seed], JSON_THROW_ON_ERROR),
        json_encode(['seed' => $seed], JSON_THROW_ON_ERROR),
        'submitted',
        1,
        1,
        1000 + $seed,
        $createdAt,
    ]);
    $insertVersion->execute([
        $laterVersionId,
        $submissionId,
        $laterVersionNo,
        json_encode(['marker' => 'later-' . $seed], JSON_THROW_ON_ERROR),
        null,
        'draft',
        0,
        0,
        1000 + $seed,
        $createdAt,
    ]);
    $insertTask->execute([$submissionId, $approvedVersionId, $reviewerStaffId]);
    $taskId = (int) $database->lastInsertId();

    $decision = $decisionService->decide($taskId, $reviewerStaffId, 'approved', '属性测试终审通过', ['supervisor_review']);
    $check((int) $decision['version_id'] === $approvedVersionId, '终审响应版本不等于任务版本，seed=' . $seed);
    $check((int) $decision['approved_version_id'] === $approvedVersionId, '终审响应批准版本错误，seed=' . $seed);

    if ($seed % 3 === 0) {
        $changeCurrent->execute([$laterVersionId, $submissionId]);
        $perturbedCount++;
    }

    $approvedRelation->execute([$submissionId]);
    $relation = $approvedRelation->fetch();
    $detail = $libraryService->detail($submissionId);
    $ids = [
        (int) $relation['task_version_id'],
        (int) $relation['approved_version_id'],
        (int) $detail['lesson']['approved_version_id'],
        (int) $detail['approved_version']['id'],
    ];
    $check(count(array_unique($ids)) === 1, '任务、主记录和正式库版本不一致，seed=' . $seed);
    $check($detail['approved_version']['content']['marker'] === 'approved-' . $seed, '正式库读取了其他版本内容，seed=' . $seed);
    $expected[$submissionId] = ['version_id' => $approvedVersionId, 'version_no' => $approvedVersionNo];
}

$listChecked = 0;
for ($page = 1; $page <= (int) ceil($caseCount / 50); $page++) {
    $result = $libraryService->list(['page' => $page, 'page_size' => 50]);
    $check($result['total'] === $caseCount, '正式库列表总数错误');
    foreach ($result['list'] as $item) {
        $submissionId = (int) $item['submission_id'];
        $check((int) $item['approved_version_id'] === $expected[$submissionId]['version_id'], '列表批准版本错误，submission=' . $submissionId);
        $check((int) $item['version_no'] === $expected[$submissionId]['version_no'], '列表版本号错误，submission=' . $submissionId);
        $listChecked++;
    }
}
$check($listChecked === $caseCount, '正式库分页未覆盖全部属性样本');

echo json_encode([
    'case_count' => $caseCount,
    'detail_checked' => $caseCount,
    'list_checked' => $listChecked,
    'current_version_perturbations' => $perturbedCount,
    'mismatch_count' => 0,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
