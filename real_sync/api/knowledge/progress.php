<?php
/**
 * 知识库用户进度API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if ($method === 'GET') {
        // 获取用户所有知识学习进度
        $sql = "SELECT kp.*, k.title, k.category_id, c.name as category_name
                FROM user_knowledge_progress kp
                JOIN knowledge_items k ON kp.knowledge_id = k.id
                LEFT JOIN knowledge_categories c ON k.category_id = c.id
                WHERE kp.user_id = ? AND kp.is_completed = 1
                ORDER BY kp.completed_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId]);
        $completed = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 统计
        $statsSql = "SELECT
                     COUNT(*) as total_learning,
                     SUM(CASE WHEN is_completed = 1 THEN 1 ELSE 0 END) as completed_count,
                     SUM(learning_time) as total_time
                     FROM user_knowledge_progress WHERE user_id = ?";
        $stmt = $db->prepare($statsSql);
        $stmt->execute([$userId]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        jsonResponse(0, 'success', [
            'completed_list' => $completed,
            'stats' => [
                'total_learning' => (int)$stats['total_learning'],
                'completed_count' => (int)$stats['completed_count'],
                'total_time' => (int)$stats['total_time']
            ]
        ]);

    } elseif ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);

        $knowledgeId = isset($data['knowledge_id']) ? (int)$data['knowledge_id'] : 0;
        $action = isset($data['action']) ? $data['action'] : 'update';
        $score = isset($data['score']) ? (int)$data['score'] : 0;
        $learningTime = isset($data['learning_time']) ? (int)$data['learning_time'] : 0;

        if (!$knowledgeId) {
            jsonResponse(1, '缺少知识ID');
        }

        if ($action === 'complete') {
            // 完成知识学习
            $sql = "INSERT INTO user_knowledge_progress (user_id, knowledge_id, is_completed, score, learning_time, completed_at)
                    VALUES (?, ?, 1, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE is_completed = 1, score = ?, learning_time = learning_time + ?, completed_at = NOW()";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId, $knowledgeId, $score, $learningTime, $score, $learningTime]);

            // 奖励积分
            $ruleCode = 'knowledge_complete';
            awardPoints($userId, $ruleCode, $knowledgeId);

            jsonResponse(0, '学习完成', ['points_awarded' => getPointsByRule($ruleCode)]);

        } elseif ($action === 'update') {
            // 更新学习进度
            $sql = "INSERT INTO user_knowledge_progress (user_id, knowledge_id, learning_time, updated_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE learning_time = learning_time + ?, updated_at = NOW()";
            $stmt = $db->prepare($sql);
            $stmt->execute([$userId, $knowledgeId, $learningTime, $learningTime]);

            jsonResponse(0, '进度已保存');

        } else {
            jsonResponse(1, '未知操作');
        }
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}

/**
 * 根据规则代码获取积分
 */
function getPointsByRule($ruleCode) {
    $db = getDB();
    $sql = "SELECT points FROM points_rules WHERE code = ? AND status = 1";
    $stmt = $db->prepare($sql);
    $stmt->execute([$ruleCode]);
    return $stmt->fetchColumn() ?: 0;
}

/**
 * 奖励积分
 */
function awardPoints($userId, $ruleCode, $sourceId = null) {
    $db = getDB();

    $ruleSql = "SELECT * FROM points_rules WHERE code = ? AND status = 1";
    $stmt = $db->prepare($ruleSql);
    $stmt->execute([$ruleCode]);
    $rule = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rule || $rule['points'] <= 0) return;

    // 检查每日上限
    if ($rule['daily_limit'] > 0) {
        $todayStart = date('Y-m-d 00:00:00');
        $dailySql = "SELECT COUNT(*) FROM points_records WHERE user_id = ? AND rule_id = ? AND created_at >= ?";
        $stmt = $db->prepare($dailySql);
        $stmt->execute([$userId, $rule['id'], $todayStart]);
        if ($stmt->fetchColumn() >= $rule['daily_limit']) return;
    }

    // 更新用户积分
    $userPointsSql = "INSERT INTO user_points (user_id, total_points, accumulated_points) VALUES (?, ?, ?)
                      ON DUPLICATE KEY UPDATE total_points = total_points + ?, accumulated_points = accumulated_points + ?";
    $stmt = $db->prepare($userPointsSql);
    $stmt->execute([$userId, $rule['points'], $rule['points'], $rule['points'], $rule['points']]);

    // 获取最新余额
    $balanceSql = "SELECT total_points FROM user_points WHERE user_id = ?";
    $stmt = $db->prepare($balanceSql);
    $stmt->execute([$userId]);
    $balance = $stmt->fetchColumn();

    // 记录积分变动
    $recordSql = "INSERT INTO points_records (user_id, rule_id, points, balance, type, source, source_id, description)
                  VALUES (?, ?, ?, ?, 'earn', ?, ?, ?)";
    $stmt = $db->prepare($recordSql);
    $stmt->execute([$userId, $rule['id'], $rule['points'], $balance, $rule['rule_type'], $sourceId, $rule['name']]);
}
