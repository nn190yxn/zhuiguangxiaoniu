<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/common/context.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        jsonResponse(405, '仅支持 GET 请求');
        exit;
    }
    $userId = (int)getCurrentUserId();
    $staff = $userId > 0 ? getStaffByUserId($userId) : [];
    $role = appRoleCode((string)($staff['role'] ?? ''));
    if ($userId <= 0) {
        jsonResponse(401, '请先登录', null, 401);
        exit;
    }
    if (!in_array($role, ['admin', 'ceo', 'operation', 'manager'], true)) {
        jsonResponse(403, '无权查看搜索治理数据', null, 403);
        exit;
    }

    $limit = max(1, min(100, (int)($_GET['limit'] ?? 20)));
    $db = getDB();
    $stmt = $db->prepare(
        'SELECT query_text, COUNT(*) AS search_count, MAX(created_at) AS last_seen
         FROM search_query_logs
         GROUP BY query_text
         ORDER BY search_count DESC, last_seen DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    jsonResponse(0, 'success', ['items' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    error_log('[admin.search.no_results] ' . $e->getMessage());
    jsonResponse(1, '搜索治理数据暂时不可用');
}
