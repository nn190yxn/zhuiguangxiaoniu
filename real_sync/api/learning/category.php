<?php
/**
 * 课程分类API
 */
require_once __DIR__ . '/../config.php';
handleCORS();
// Auth check
$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(401, '请先登录');
}

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();

    if ($method === 'GET') {
        $parentId = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;

        $sql = "SELECT * FROM course_categories WHERE status = 1 AND parent_id = ? ORDER BY sort_order ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$parentId]);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        jsonResponse(0, 'success', ['list' => $categories]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
