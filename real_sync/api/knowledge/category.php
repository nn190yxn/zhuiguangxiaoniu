<?php
/**
 * 知识库分类API
 * GET /api/knowledge/category.php
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    getCurrentUserId();

    if ($method !== 'GET') {
        jsonResponse(1, '不支持的请求方法');
    }

    $sql = "SELECT id, name, code, type, description, icon, sort_order
            FROM knowledge_categories
            WHERE status = 1
            ORDER BY sort_order ASC, id ASC";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $grouped = [];
    foreach ($list as $row) {
        $type = (string)($row['type'] ?? 'general');
        if (!isset($grouped[$type])) {
            $grouped[$type] = [];
        }
        $grouped[$type][] = $row;
    }

    jsonResponse(0, 'success', [
        'flat' => $list,
        'grouped' => $grouped,
    ]);
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
