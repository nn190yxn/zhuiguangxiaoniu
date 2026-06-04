<?php
/**
 * 知识库分类清单API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if (!$userId) {
        jsonResponse(401, '请先登录', null, 401);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(1, '不支持的请求方法');
        exit;
    }

    $type = isset($_GET['type']) ? trim($_GET['type']) : '';
    $role = isset($_GET['role']) ? trim($_GET['role']) : '';
    $stage = isset($_GET['stage']) ? trim($_GET['stage']) : '';

    if (!$role || !$stage) {
        $stmt = $db->prepare("SELECT role, stage FROM staffs WHERE user_id = ? AND status = 1 LIMIT 1");
        $stmt->execute([$userId]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($staff) {
            if (!$role && !empty($staff['role'])) {
                $role = $staff['role'];
            }
            if (!$stage && !empty($staff['stage'])) {
                $stage = $staff['stage'];
            }
        }
    }

    $joinConditions = ["k.category_id = c.id", "k.status = 1"];
    $params = [];

    if ($role && $stage) {
        $joinConditions[] = "(k.is_public = 1 OR (JSON_CONTAINS(k.target_roles, ?) AND JSON_CONTAINS(k.target_stages, ?)))";
        $params[] = json_encode($role, JSON_UNESCAPED_UNICODE);
        $params[] = json_encode($stage, JSON_UNESCAPED_UNICODE);
    } elseif ($role) {
        $joinConditions[] = "(k.is_public = 1 OR JSON_CONTAINS(k.target_roles, ?))";
        $params[] = json_encode($role, JSON_UNESCAPED_UNICODE);
    } elseif ($stage) {
        $joinConditions[] = "(k.is_public = 1 OR JSON_CONTAINS(k.target_stages, ?))";
        $params[] = json_encode($stage, JSON_UNESCAPED_UNICODE);
    } else {
        $joinConditions[] = "k.is_public = 1";
    }

    $where = "WHERE 1 = 1";
    if ($type) {
        $where .= " AND c.type = ?";
        $params[] = $type;
    }

    $joinSql = implode(' AND ', $joinConditions);
    $sql = "SELECT c.id, c.name, c.code, c.type, c.description, c.icon, c.sort_order,
            COUNT(k.id) AS item_count
            FROM knowledge_categories c
            LEFT JOIN knowledge_items k ON $joinSql
            $where
            GROUP BY c.id, c.name, c.code, c.type, c.description, c.icon, c.sort_order
            ORDER BY c.type ASC, c.sort_order ASC, c.id ASC";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $types = [
        ['type' => '', 'name' => '全部', 'count' => 0],
        ['type' => 'action', 'name' => '动作库', 'count' => 0],
        ['type' => 'script', 'name' => '话术库', 'count' => 0],
        ['type' => 'knowledge_card', 'name' => '知识卡', 'count' => 0],
    ];
    $typeIndex = [];
    foreach ($types as $idx => $row) {
        $typeIndex[$row['type']] = $idx;
    }

    foreach ($categories as &$category) {
        $category['id'] = (int)$category['id'];
        $category['sort_order'] = (int)$category['sort_order'];
        $category['item_count'] = (int)$category['item_count'];
        if (isset($typeIndex[$category['type']])) {
            $types[$typeIndex[$category['type']]]['count'] += $category['item_count'];
            $types[0]['count'] += $category['item_count'];
        }
    }
    unset($category);

    jsonResponse(0, 'success', [
        'types' => $types,
        'categories' => $categories
    ]);
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
