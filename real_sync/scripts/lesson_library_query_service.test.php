<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/kernel/ApiException.php';
require_once __DIR__ . '/../api/lesson-library/LessonLibraryQueryService.php';

$check = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$database = new PDO('sqlite::memory:');
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database->exec('CREATE TABLE lesson_submissions (
    id INTEGER PRIMARY KEY,
    store_id INTEGER,
    store_name TEXT NOT NULL,
    author_staff_id INTEGER,
    author_name TEXT NOT NULL,
    course_line TEXT NOT NULL,
    class_level TEXT NOT NULL,
    lesson_date TEXT NOT NULL,
    title TEXT NOT NULL,
    status TEXT NOT NULL,
    current_version_id INTEGER,
    approved_version_id INTEGER,
    library_status TEXT NOT NULL,
    library_published_at TEXT,
    library_published_by_staff_id INTEGER
)');
$database->exec('CREATE TABLE lesson_versions (
    id INTEGER PRIMARY KEY,
    submission_id INTEGER NOT NULL,
    version_no INTEGER NOT NULL,
    content_json TEXT NOT NULL,
    source_snapshot_json TEXT,
    version_type TEXT NOT NULL,
    is_submitted INTEGER NOT NULL,
    is_immutable INTEGER NOT NULL,
    created_by INTEGER NOT NULL,
    created_at TEXT NOT NULL
)');

$submission = $database->prepare('INSERT INTO lesson_submissions VALUES (?, 7, ?, 21, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 31)');
$submission->execute([1, '一店', '教练甲', '体适能', '启蒙班', '2026-09-01', '平衡训练', 'approved', 12, 11, 'published', '2026-09-05 10:00:00']);
$submission->execute([2, '一店', '教练乙', '体适能', '启蒙班', '2026-09-02', '隐藏教案', 'approved', 21, 21, 'hidden', null]);
$submission->execute([3, '一店', '教练丙', '体适能', '进阶班', '2026-09-03', '状态异常', 'supervisor_review', 31, 31, 'published', '2026-09-05 11:00:00']);
$submission->execute([4, '一店', '教练丁', '体适能', '进阶班', '2026-09-04', '可变版本', 'approved', 41, 41, 'published', '2026-09-05 12:00:00']);
$submission->execute([5, '二店', '教练戊', '感统', '进阶班', '2026-09-05', '触觉训练', 'approved', 51, 51, 'published', '2026-09-05 13:00:00']);

$version = $database->prepare('INSERT INTO lesson_versions VALUES (?, ?, ?, ?, ?, ?, ?, ?, 21, ?)');
$version->execute([11, 1, 1, '{"title":"批准内容"}', '{"source":"review"}', 'submitted', 1, 1, '2026-09-01 09:00:00']);
$version->execute([12, 1, 2, '{"title":"后续草稿"}', null, 'draft', 0, 0, '2026-09-02 09:00:00']);
$version->execute([21, 2, 1, '{}', null, 'submitted', 1, 1, '2026-09-02 09:00:00']);
$version->execute([31, 3, 1, '{}', null, 'submitted', 1, 1, '2026-09-03 09:00:00']);
$version->execute([41, 4, 1, '{}', null, 'submitted', 1, 0, '2026-09-04 09:00:00']);
$version->execute([51, 5, 1, '{"title":"感统批准内容"}', null, 'submitted', 1, 1, '2026-09-05 09:00:00']);

$service = new LessonLibraryQueryService($database);
$firstPage = $service->list(['page' => 1, 'page_size' => 1]);
$check($firstPage['total'] === 2, '正式库应只包含两个有效批准版本');
$check(count($firstPage['list']) === 1 && $firstPage['list'][0]['submission_id'] === 5, '列表应按发布时间和 ID 稳定倒序分页');
$check($firstPage['list'][0]['canonical_route'] === '/lesson-library.html?id=5', '列表应返回稳定规范路由');

$filtered = $service->list(['course_line' => '体适能', 'class_level' => '启蒙班', 'q' => '平衡']);
$check($filtered['total'] === 1 && $filtered['list'][0]['submission_id'] === 1, '列表筛选应限定正式教案结果');

$detail = $service->detail(1);
$check($detail['lesson']['approved_version_id'] === 11, '详情应绑定批准版本 ID');
$check($detail['approved_version']['id'] === 11, '详情应返回批准版本');
$check($detail['approved_version']['content']['title'] === '批准内容', '详情不得读取后续当前草稿');
$check($detail['approved_version']['is_immutable'] === 1, '详情版本应保持不可变');

try {
    $service->detail(2);
    throw new RuntimeException('隐藏教案详情应被正式库隔离');
} catch (PlatformApiException $error) {
    $check($error->httpStatus() === 404, '隐藏教案应返回 404');
    $check($error->errorCode() === 'lesson_library_item_not_found', '隐藏教案应返回稳定错误码');
}

fwrite(STDOUT, "lesson_library_query_service.test.php passed\n");
