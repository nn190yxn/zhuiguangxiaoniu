<?php
/**
 * 课程列表API
 * 修复：要求登录
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../kernel/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

$context = platformApiContext(['domain' => 'learning', 'action' => 'learning.list.read']);
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
    $userId = getCurrentUserId();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = min(100, max(1, (int)($_GET['page_size'] ?? 10)));
        $offset = ($page - 1) * $pageSize;

        $where = "WHERE c.status = 1";
        $params = [];

        if ($categoryId > 0) {
            $where .= " AND c.category_id = ?";
            $params[] = $categoryId;
        }

        // 获取总数
        $countSql = "SELECT COUNT(*) FROM courses c $where";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // 获取列表
        $sql = "SELECT c.*, cc.name as category_name,
                (SELECT progress FROM user_course_progress WHERE user_id = ? AND course_id = c.id) as user_progress,
                (SELECT status FROM user_course_progress WHERE user_id = ? AND course_id = c.id) as user_status
                FROM courses c
                LEFT JOIN course_categories cc ON c.category_id = cc.id
                $where
                ORDER BY c.is_required DESC, c.sort_order ASC, c.id ASC
                LIMIT $offset, $pageSize";

        $params = array_merge([$userId, $userId], $params);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 格式化数据
        foreach ($list as &$item) {
            $item['cover_image'] = $item['cover_image'] ? getResourceUrl($item['cover_image']) : null;
            $item['is_completed'] = $item['user_status'] == 1;
            $item['is_started'] = $item['user_progress'] > 0;
        }

        $logger->log('info', 'learning.list.read', $context, ['total' => (int)$total]);
        platformApiResponse($context, [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize
        ])->send();
    }
} catch (Exception $e) {
    error_log('[learning.list] ' . $e->getMessage());
    jsonResponse(500, '服务器错误，请稍后重试');
}
