<?php
/**
 * 知识库用户进度API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = (int)getCurrentUserId();
    if ($userId <= 0) {
        jsonResponse(401, '请先登录', null, 401);
        exit;
    }

    if ($method === 'GET') {
        // 保留历史完成记录读取；下架知识保留记录但不返回其内容元数据。
        // 新知识浏览不会自动创建完成记录。
        $sql = "SELECT kp.*, k.title, k.category_id, c.name as category_name
                FROM user_knowledge_progress kp
                LEFT JOIN knowledge_items k
                  ON kp.knowledge_id = k.id
                 AND k.status = 1 AND k.publication_status = 'published'
                LEFT JOIN knowledge_categories c ON k.category_id = c.id
                WHERE kp.user_id = ? AND kp.is_completed = 1
                ORDER BY kp.completed_at DESC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId]);
        $completed = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
    } elseif ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
        jsonResponse(1, '知识库学习完成与进度写入已停用');
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
