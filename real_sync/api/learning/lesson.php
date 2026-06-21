<?php
/**
 * 章节详情/内容API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if ($method === 'GET') {
        $lessonId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if (!$lessonId) {
            jsonResponse(1, '缺少章节ID');
        }

        // 获取章节信息
        $sql = "SELECT l.*, c.id as course_id, c.title as course_title
                FROM course_lessons l
                JOIN courses c ON l.course_id = c.id
                WHERE l.id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$lessonId]);
        $lesson = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$lesson) {
            jsonResponse(1, '章节不存在');
        }

        // 格式化媒体URL
        if ($lesson['media_url']) {
            $lesson['media_url'] = getResourceUrl($lesson['media_url']);
        }

        // 标记已读
        $readSql = "INSERT INTO user_lesson_progress (user_id, lesson_id, course_id, is_completed, completed_at)
                    VALUES (?, ?, ?, 1, NOW())
                    ON DUPLICATE KEY UPDATE is_completed = 1, completed_at = NOW()";
        $stmt = $db->prepare($readSql);
        $stmt->execute([$userId, $lessonId, $lesson['course_id']]);

        // 更新课程整体进度
        updateCourseProgress($userId, $lesson['course_id']);

        // 获取上一章/下一章，避免 UNION + ORDER BY 在部分 MySQL 版本下解析失败。
        $navigation = ['prev' => null, 'next' => null];

        $prevSql = "SELECT id, title FROM course_lessons WHERE course_id = ? AND sort_order < (SELECT sort_order FROM course_lessons WHERE id = ?) ORDER BY sort_order DESC LIMIT 1";
        $stmt = $db->prepare($prevSql);
        $stmt->execute([$lesson['course_id'], $lessonId]);
        $prev = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($prev) {
            $navigation['prev'] = ['id' => $prev['id'], 'title' => $prev['title']];
        }

        $nextSql = "SELECT id, title FROM course_lessons WHERE course_id = ? AND sort_order > (SELECT sort_order FROM course_lessons WHERE id = ?) ORDER BY sort_order ASC LIMIT 1";
        $stmt = $db->prepare($nextSql);
        $stmt->execute([$lesson['course_id'], $lessonId]);
        $next = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($next) {
            $navigation['next'] = ['id' => $next['id'], 'title' => $next['title']];
        }

        jsonResponse(0, 'success', [
            'lesson' => $lesson,
            'navigation' => $navigation
        ]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}

/**
 * 更新课程整体进度
 */
function updateCourseProgress($userId, $courseId) {
    $db = getDB();

    // 获取课程总章节数
    $totalSql = "SELECT COUNT(*) FROM course_lessons WHERE course_id = ?";
    $stmt = $db->prepare($totalSql);
    $total = $stmt->fetchColumn();

    // 获取已完成章节数
    $completedSql = "SELECT COUNT(*) FROM user_lesson_progress WHERE user_id = ? AND course_id = ? AND is_completed = 1";
    $stmt = $db->prepare($completedSql);
    $completed = $stmt->fetchColumn();

    $progress = $total > 0 ? round($completed / $total * 100, 2) : 0;
    $status = $progress >= 100 ? 1 : 0;

    // 更新进度
    $updateSql = "INSERT INTO user_course_progress (user_id, course_id, progress, status, completed_at)
                  VALUES (?, ?, ?, ?, ?)
                  ON DUPLICATE KEY UPDATE progress = ?, status = ?, completed_at = ?";
    $stmt = $db->prepare($updateSql);
    $completedAt = $status == 1 ? date('Y-m-d H:i:s') : null;
    $stmt->execute([$userId, $courseId, $progress, $status, $completedAt, $progress, $status, $completedAt]);

    // 如果完成课程，奖励积分
    if ($status == 1) {
        awardPoints($userId, 'course_complete', $courseId);
    }
}

/**
 * 奖励积分
 */
function awardPoints($userId, $ruleCode, $sourceId = null) {
    $db = getDB();

    // 获取规则
    $ruleSql = "SELECT * FROM points_rules WHERE code = ? AND status = 1";
    $stmt = $db->prepare($ruleSql);
    $stmt->execute([$ruleCode]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rule) return;

    // 检查每日上限
    if ($rule['daily_limit'] > 0) {
        $todayStart = date('Y-m-d 00:00:00');
        $dailySql = "SELECT COUNT(*) FROM points_records WHERE user_id = ? AND rule_id = ? AND created_at >= ?";
        $stmt = $db->prepare($dailySql);
        $stmt->execute([$userId, $rule['id'], $todayStart]);
        $todayCount = $stmt->fetchColumn();

        if ($todayCount >= $rule['daily_limit']) return;
    }

    // 检查总上限
    if ($rule['total_limit'] > 0) {
        $totalSql = "SELECT COUNT(*) FROM points_records WHERE user_id = ? AND rule_id = ?";
        $stmt = $db->prepare($totalSql);
        $stmt->execute([$userId, $rule['id']]);
        $totalCount = $stmt->fetchColumn();

        if ($totalCount >= $rule['total_limit']) return;
    }

    // 更新用户积分
    $userPointsSql = "INSERT INTO user_points (user_id, total_points, accumulated_points) VALUES (?, ?, ?)
                       ON DUPLICATE KEY UPDATE total_points = total_points + ?, accumulated_points = accumulated_points + ?";
    $stmt = $db->prepare($userPointsSql);
    $stmt->execute([$userId, $rule['points'], $rule['points'], $rule['points'], $rule['points']]);

    // 记录积分变动
    $balanceSql = "SELECT total_points FROM user_points WHERE user_id = ?";
    $stmt = $db->prepare($balanceSql);
    $stmt->execute([$userId]);
    $balance = $stmt->fetchColumn();

    $recordSql = "INSERT INTO points_records (user_id, rule_id, points, balance, type, source, source_id, description)
                  VALUES (?, ?, ?, ?, 'earn', ?, ?, ?)";
    $stmt = $db->prepare($recordSql);
    $stmt->execute([$userId, $rule['id'], $rule['points'], $balance, $rule['rule_type'], $sourceId, $rule['name']]);
}
