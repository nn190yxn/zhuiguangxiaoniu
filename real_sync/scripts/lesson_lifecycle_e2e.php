<?php

declare(strict_types=1);

require_once __DIR__ . '/../api/kernel/ApiException.php';
require_once __DIR__ . '/../api/platform/PrivateFileStorage.php';
require_once __DIR__ . '/../api/lesson-submissions/LessonWordParser.php';
require_once __DIR__ . '/../api/lesson-submissions/LessonSubmissionService.php';
require_once __DIR__ . '/../api/lesson-submissions/LessonDraftService.php';
require_once __DIR__ . '/../api/lesson-submissions/LessonAceRuleChecker.php';
require_once __DIR__ . '/../api/lesson-submissions/LessonSubmissionReviewService.php';
require_once __DIR__ . '/../api/lesson-reviews/LessonReviewDecisionService.php';
require_once __DIR__ . '/../api/lesson-library/LessonLibraryQueryService.php';
require_once __DIR__ . '/../api/lesson-library/LessonArchiveService.php';

const AUTHOR_STAFF_ID = 1;
const MANAGER_STAFF_ID = 2;
const SUPERVISOR_STAFF_ID = 3;

function database(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
    return $pdo;
}

function createSchema(PDO $pdo): void
{
    $pdo->exec(<<<'SQL'
CREATE TABLE staffs (
    id INTEGER PRIMARY KEY,
    user_id INTEGER,
    name TEXT NOT NULL,
    store_id INTEGER,
    role TEXT NOT NULL,
    status INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE lesson_submissions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    store_id INTEGER,
    store_name TEXT NOT NULL,
    author_staff_id INTEGER NOT NULL,
    author_name TEXT NOT NULL,
    course_line TEXT NOT NULL,
    class_level TEXT NOT NULL,
    lesson_date TEXT NOT NULL,
    title TEXT NOT NULL,
    status TEXT NOT NULL,
    status_version INTEGER NOT NULL DEFAULT 1,
    current_version_id INTEGER,
    approved_version_id INTEGER,
    library_status TEXT NOT NULL DEFAULT 'hidden',
    library_published_at TEXT,
    library_published_by_staff_id INTEGER,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE lesson_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL,
    version_no INTEGER NOT NULL,
    content_json TEXT NOT NULL,
    source_snapshot_json TEXT,
    changed_fields_json TEXT,
    version_type TEXT NOT NULL,
    is_submitted INTEGER NOT NULL DEFAULT 0,
    is_immutable INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (submission_id, version_no)
);
CREATE TABLE lesson_source_files (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL,
    original_name TEXT NOT NULL,
    storage_key TEXT NOT NULL,
    mime_type TEXT NOT NULL,
    extension TEXT NOT NULL,
    byte_size INTEGER NOT NULL,
    sha256 TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'active',
    uploaded_by INTEGER NOT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE lesson_parse_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL,
    source_file_id INTEGER NOT NULL,
    parser_version TEXT NOT NULL,
    status TEXT NOT NULL,
    location_map_json TEXT,
    error_code TEXT,
    error_message TEXT,
    started_at TEXT,
    completed_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE lesson_suggestions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL,
    version_id INTEGER NOT NULL,
    suggestion_type TEXT,
    priority TEXT,
    field_path TEXT,
    message TEXT,
    reason TEXT,
    source_type TEXT,
    knowledge_item_id INTEGER,
    knowledge_version_id INTEGER,
    decision TEXT NOT NULL DEFAULT 'pending',
    decided_by INTEGER,
    decided_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
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
    decided_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE lesson_audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    submission_id INTEGER NOT NULL,
    version_id INTEGER,
    actor_staff_id INTEGER NOT NULL,
    action TEXT NOT NULL,
    from_status TEXT,
    to_status TEXT,
    metadata_json TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);
}

function createDocx(string $path): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('DOCX 测试夹具创建失败');
    }
    $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>
</Types>
XML);
    $zip->addFromString('_rels/.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>
</Relationships>
XML);
    $zip->addFromString('word/document.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>
  <w:p><w:r><w:t>教案标题：生命周期测试教案</w:t></w:r></w:p>
  <w:p><w:r><w:t>A目标：完成基础移动练习</w:t></w:r></w:p>
  <w:p><w:r><w:t>身体安全：保持距离并听从停止信号</w:t></w:r></w:p>
  <w:sectPr/>
</w:body></w:document>
XML);
    $zip->close();
}

function setup(string $dbPath, string $fixturePath, string $storageRoot): array
{
    if (!is_dir($storageRoot) && !mkdir($storageRoot, 0700, true) && !is_dir($storageRoot)) {
        throw new RuntimeException('私有存储目录创建失败');
    }
    $pdo = database($dbPath);
    createSchema($pdo);
    $staff = $pdo->prepare('INSERT INTO staffs (id, user_id, name, store_id, role, status) VALUES (?, ?, ?, ?, ?, 1)');
    $staff->execute([AUTHOR_STAFF_ID, 101, '测试教练', 10, 'coach']);
    $staff->execute([MANAGER_STAFF_ID, 102, '测试店长', 10, 'manager']);
    $staff->execute([SUPERVISOR_STAFF_ID, 103, '测试教学主管', null, 'teaching_supervisor']);

    $service = new LessonSubmissionService($pdo, new PlatformPrivateFileStorage($storageRoot));
    $created = $service->create([
        'store_id' => 10,
        'store_name' => '生命周期测试门店',
        'author_name' => '测试教练',
        'course_line' => '体适能',
        'class_level' => '基础班',
        'lesson_date' => '2026-09-05',
        'title' => '生命周期测试教案',
    ], AUTHOR_STAFF_ID);
    createDocx($fixturePath);
    return ['submission_id' => $created['id'], 'fixture_path' => $fixturePath, 'status' => $created['status'], 'status_version' => 1];
}

function validContent(array $metadata): array
{
    return [
        'metadata' => [...$metadata, 'course_duration_minutes' => 45],
        'objectives' => ['athletic' => '完成基础移动练习', 'cognitive' => '说出两条安全规则', 'engagement' => '主动参与小组任务'],
        'learner_focus' => '关注首次参与课程的学员',
        'safety' => ['physical' => '保持距离，听到停止信号立即停手', 'psychological' => '使用正向反馈并允许选择难度'],
        'equipment' => [['name' => '标志桶', 'quantity' => 8]],
        'phases' => [['name' => '基础移动', 'duration_minutes' => 20, 'activity' => '分组完成走跑跳组合']],
        'progressions' => [['base' => '直线移动', 'easier' => '降低速度', 'harder' => '增加方向变化']],
        'assistant_responsibilities' => '负责器材、安全距离和个别学员支持',
        'reflection' => ['athletic' => '记录动作完成质量', 'cognitive' => '检查安全规则掌握情况', 'engagement' => '记录主动参与次数'],
    ];
}

function completeLifecycle(string $dbPath, string $storageRoot, int $submissionId, int $sourceFileId): array
{
    $pdo = database($dbPath);
    $submissionService = new LessonSubmissionService($pdo, new PlatformPrivateFileStorage($storageRoot));
    $parsed = $submissionService->parseUploadedFile($submissionId, $sourceFileId, AUTHOR_STAFF_ID);
    if ($parsed['status'] !== 'editable' || $parsed['version_no'] !== 2) throw new RuntimeException('解析阶段状态错误');

    $metadata = $pdo->query('SELECT store_id, store_name, author_name, course_line, class_level, lesson_date, title FROM lesson_submissions WHERE id = ' . $submissionId)->fetch();
    $draft = (new LessonDraftService($pdo))->saveDraft($submissionId, validContent($metadata), AUTHOR_STAFF_ID, 2, '补齐 ACE 必填内容');
    if ($draft['version_no'] !== 3 || $draft['status_version'] !== 3) throw new RuntimeException('编辑阶段版本错误');

    $submitted = (new LessonSubmissionReviewService($pdo))->submit($submissionId, AUTHOR_STAFF_ID, 3);
    if ($submitted['status'] !== 'store_review' || $submitted['status_version'] !== 4) throw new RuntimeException('提交审核阶段状态错误');

    $decisionService = new LessonReviewDecisionService($pdo);
    $storeDecision = $decisionService->decide((int) $submitted['review_task_id'], MANAGER_STAFF_ID, 'approved', '店长审核通过', ['store_review']);
    if ($storeDecision['status'] !== 'supervisor_review' || !$storeDecision['next_review_task_id']) throw new RuntimeException('店长审核阶段状态错误');
    $supervisorDecision = $decisionService->decide((int) $storeDecision['next_review_task_id'], SUPERVISOR_STAFF_ID, 'approved', '主管审核通过', ['supervisor_review']);
    if ($supervisorDecision['status'] !== 'approved' || $supervisorDecision['library_status'] !== 'published') throw new RuntimeException('主管审核阶段状态错误');

    $library = new LessonLibraryQueryService($pdo);
    $publishedList = $library->list(['q' => '生命周期测试']);
    $publishedDetail = $library->detail($submissionId);
    $approvedVersionId = (int) $supervisorDecision['approved_version_id'];
    if ($publishedList['total'] !== 1 || (int) $publishedDetail['approved_version']['id'] !== $approvedVersionId) throw new RuntimeException('正式库批准版本错误');

    $archived = (new LessonArchiveService($pdo))->archive($submissionId, SUPERVISOR_STAFF_ID, 6, '生命周期测试归档');
    $archivedList = $library->list(['q' => '生命周期测试']);
    if ($archivedList['total'] !== 0 || $archived['status_version'] !== 7) throw new RuntimeException('归档阶段状态错误');
    try {
        $library->detail($submissionId);
        throw new RuntimeException('归档教案仍可从正式库读取');
    } catch (PlatformApiException $error) {
        if ($error->errorCode() !== 'lesson_library_item_not_found') throw $error;
    }

    $state = $pdo->query('SELECT status, status_version, current_version_id, approved_version_id, library_status FROM lesson_submissions WHERE id = ' . $submissionId)->fetch();
    $counts = [];
    foreach (['lesson_versions', 'lesson_source_files', 'lesson_parse_runs', 'lesson_review_tasks', 'lesson_audit_logs'] as $table) {
        $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table . ' WHERE submission_id = ' . $submissionId)->fetchColumn();
    }
    if ($counts['lesson_versions'] !== 3 || $counts['lesson_source_files'] !== 1 || $counts['lesson_parse_runs'] !== 1 || $counts['lesson_review_tasks'] !== 2 || $counts['lesson_audit_logs'] < 7) {
        throw new RuntimeException('归档后历史记录不完整');
    }
    return [
        'states' => ['created' => 'draft', 'parsed' => $parsed['status'], 'edited' => $draft['status'], 'submitted' => $submitted['status'], 'store_approved' => $storeDecision['status'], 'supervisor_approved' => $supervisorDecision['status'], 'archived' => $archived['status']],
        'final_state' => $state,
        'published_item' => $publishedDetail['lesson'],
        'history_counts' => $counts,
    ];
}

function uploadRequest(): void
{
    header('Content-Type: application/json; charset=utf-8');
    try {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_SERVER['REQUEST_URI'] ?? '') !== '/upload') {
            http_response_code(404);
            echo json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR);
            return;
        }
        $dbPath = (string) getenv('LESSON_E2E_DB');
        $storageRoot = (string) getenv('LESSON_E2E_STORAGE');
        $submissionId = (int) getenv('LESSON_E2E_SUBMISSION');
        $file = $_FILES['lesson_file'] ?? null;
        if (!is_array($file)) throw new InvalidArgumentException('缺少 lesson_file');
        $result = (new LessonSubmissionService(database($dbPath), new PlatformPrivateFileStorage($storageRoot)))->upload($submissionId, $file, AUTHOR_STAFF_ID);
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        http_response_code(500);
        echo json_encode(['error' => get_class($error), 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}

if (PHP_SAPI === 'cli-server') {
    uploadRequest();
    return;
}

try {
    $command = $argv[1] ?? '';
    $result = match ($command) {
        'setup' => setup($argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? ''),
        'complete' => completeLifecycle($argv[2] ?? '', $argv[3] ?? '', (int) ($argv[4] ?? 0), (int) ($argv[5] ?? 0)),
        default => throw new InvalidArgumentException('未知测试命令'),
    };
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
