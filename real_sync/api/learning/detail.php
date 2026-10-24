<?php
/**
 * 课程详情API
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../kernel/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

$context = platformApiContext(['domain' => 'learning', 'action' => 'learning.detail.read']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 请求');
}

$auth = platformApiAuthContext();
$auth->requireAuthenticated();
$context = $context->withActor($auth->userId(), $auth->staffId());

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if (!$courseId) {
            jsonResponse(1, '缺少课程ID');
        }

        // 获取课程信息
        $sql = "SELECT c.*, cc.name as category_name
                FROM courses c
                LEFT JOIN course_categories cc ON c.category_id = cc.id
                WHERE c.id = ? AND c.status = 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$courseId]);
        $course = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$course) {
            jsonResponse(1, '课程不存在');
        }

        // 获取章节列表
        $lessonSql = "SELECT l.id, l.title, l.duration, l.sort_order,
                     (SELECT p.is_completed FROM user_lesson_progress p WHERE p.user_id = ? AND p.lesson_id = l.id) as is_completed
                     FROM course_lessons l WHERE l.course_id = ? ORDER BY l.sort_order ASC, l.id ASC";
        $stmt = $db->prepare($lessonSql);
        $stmt->execute([$userId, $courseId]);
        $lessons = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 获取用户学习进度
        $progressSql = "SELECT * FROM user_course_progress WHERE user_id = ? AND course_id = ?";
        $stmt = $db->prepare($progressSql);
        $stmt->execute([$userId, $courseId]);
        $progress = $stmt->fetch(PDO::FETCH_ASSOC);

        // 格式化课程封面
        $course['cover_image'] = $course['cover_image'] ? getResourceUrl($course['cover_image']) : null;

        // 计算完成百分比
        $completedLessons = count(array_filter($lessons, fn($l) => $l['is_completed'] == 1));
        $totalLessons = count($lessons);
        $course['completed_lessons'] = $completedLessons;
        $course['total_lessons'] = $totalLessons;
        $course['progress_percent'] = $totalLessons > 0 ? round($completedLessons / $totalLessons * 100, 1) : 0;

        // 获取关联考试
        $examSql = "SELECT e.id, e.title, e.pass_score, e.duration, e.attempt_limit,
                   (SELECT COUNT(*) FROM exam_records er WHERE er.user_id = ? AND er.module_id = e.id AND er.is_passed = 1) as passed
                    FROM exams e WHERE e.course_id = ? AND e.is_active = 1 LIMIT 1";
        $stmt = $db->prepare($examSql);
        $stmt->execute([$userId, $courseId]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);

        $logger->log('info', 'learning.detail.read', $context, ['course_id' => $courseId]);
        platformApiResponse($context, [
            'course' => $course,
            'lessons' => $lessons,
            'progress' => $progress,
            'exam' => $exam
        ])->send();
    }
} catch (Exception $e) {
    error_log('[learning.detail] ' . $e->getMessage());
    jsonResponse(500, '服务器错误，请稍后重试');
}
