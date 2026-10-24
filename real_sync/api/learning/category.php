<?php
/**
 * 课程分类API
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../kernel/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

$context = platformApiContext(['domain' => 'learning', 'action' => 'learning.category.read']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 请求');
}

$auth = platformApiAuthContext();
$auth->requireAuthenticated();
$context = $context->withActor($auth->userId(), $auth->staffId());

try {
    $db = getDB();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $parentId = isset($_GET['parent_id']) ? (int)$_GET['parent_id'] : 0;

        $sql = "SELECT * FROM course_categories WHERE status = 1 AND parent_id = ? ORDER BY sort_order ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$parentId]);
        $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $logger->log('info', 'learning.category.read', $context, ['total' => count($categories)]);
        platformApiResponse($context, ['list' => $categories])->send();
    }
} catch (Exception $e) {
    error_log('[learning.category] ' . $e->getMessage());
    jsonResponse(500, '服务器错误，请稍后重试');
}
