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

    $type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
    $contentType = isset($_GET['content_type']) ? trim((string)$_GET['content_type']) : '';
    $domainCode = isset($_GET['domain_code']) ? trim((string)$_GET['domain_code']) : '';
    $riskLevel = isset($_GET['risk_level']) ? trim((string)$_GET['risk_level']) : '';

    $joinConditions = [
        "k.category_id = c.id",
        "k.status = 1",
        "k.publication_status = 'published'",
    ];
    $params = [];
    foreach ([
        'k.content_type' => $contentType,
        'k.domain_code' => $domainCode,
        'k.risk_level' => $riskLevel,
    ] as $column => $value) {
        if ($value !== '') {
            $joinConditions[] = $column . ' = ?';
            $params[] = $value;
        }
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
