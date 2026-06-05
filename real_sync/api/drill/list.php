<?php
/**
 * 演练任务列表API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if ($method === 'GET') {
        $role = isset($_GET['role']) ? trim($_GET['role']) : '';
        $stage = isset($_GET['stage']) ? trim($_GET['stage']) : '';
        $status = isset($_GET['status']) ? trim($_GET['status']) : '';

        $where = "WHERE t.status = 1";
        $params = [];

        if ($role) {
            $where .= " AND t.role = ?";
            $params[] = $role;
        }

        if ($stage) {
            $where .= " AND t.stage = ?";
            $params[] = $stage;
        }

        // 获取用户的演练任务
        $sql = "SELECT t.*, dt.status as user_status, dt.current_step, dt.progress, dt.best_score, dt.attempts_count, dt.step_status,
                dt.started_at, dt.completed_at,
                (SELECT title FROM knowledge_items WHERE id = t.knowledge_card_id) as knowledge_card_title
                FROM drill_templates t
                LEFT JOIN user_drill_tasks dt ON t.id = dt.template_id AND dt.user_id = ?
                $where
                ORDER BY t.order_index ASC, t.id ASC";

        $params = array_merge([$userId], $params);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($list as &$item) {
            $item['steps'] = $item['steps'] ? json_decode($item['steps'], true) : [1, 2, 3, 4];
            $item['step_names'] = $item['step_names'] ? json_decode($item['step_names'], true) : [
                1 => '学习知识',
                2 => '背诵话术',
                3 => '模拟演练',
                4 => '通关认证'
            ];
            $item['step_status'] = !empty($item['step_status']) ? json_decode($item['step_status'], true) : [];
        }

        // 按状态筛选
        if ($status) {
            $list = array_filter($list, function($item) use ($status) {
                return $item['user_status'] === $status;
            });
            $list = array_values($list);
        }

        jsonResponse(0, 'success', ['list' => array_values($list)]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
