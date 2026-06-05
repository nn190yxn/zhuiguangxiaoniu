<?php
/**
 * 课程详情API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if ($method === 'GET') {
        $courseId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if (!$courseId) {
            jsonResponse(1, '缺少课程ID');
        }

        // 获取课程信息
        $sql = "SELECT c.*, cc.name as category_name
                FROM courses c
                LEFT JOIN course_categories cc ON c.category_id = cc.id
                WHERE c.id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$courseId]);
        $course = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$course) {
            jsonResponse(1, '课程不存在');
        }

        // 获取章节列表
        $lessonSql = "SELECT id, title, duration, sort_order,
                     (SELECT is_completed FROM user_lesson_progress WHERE user_id = ? AND lesson_id = id) as is_completed
                     FROM course_lessons WHERE course_id = ? ORDER BY sort_order ASC";
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

        jsonResponse(0, 'success', [
            'course' => $course,
            'lessons' => $lessons,
            'progress' => $progress,
            'exam' => $exam
        ]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
