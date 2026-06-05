<?php
/**
 * 积分记录API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if ($method === 'GET') {
        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $pageSize = min(100, max(1, isset($_GET['page_size']) ? (int)$_GET['page_size'] : 20));
        $type = isset($_GET['type']) ? $_GET['type'] : '';
        $offset = ($page - 1) * $pageSize;

        $where = "WHERE pr.user_id = ?";
        $params = [$userId];

        if ($type === 'earn') {
            $where .= " AND pr.points > 0";
        } elseif ($type === 'spend') {
            $where .= " AND pr.points < 0";
        }

        // 获取总数
        $countSql = "SELECT COUNT(*) FROM points_records pr $where";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // 获取记录
        $sql = "SELECT pr.*, pbr.name as rule_name
                FROM points_records pr
                LEFT JOIN points_rules pbr ON pr.rule_id = pbr.id
                $where
                ORDER BY pr.created_at DESC
                LIMIT ? OFFSET ?";

        $params[] = $pageSize;
        $params[] = $offset;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($list as &$item) {
            $item['points_display'] = $item['points'] > 0 ? '+' . $item['points'] : $item['points'];
        }

        jsonResponse(0, 'success', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize
        ]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    error_log('points/records error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误，请稍后重试');
}
