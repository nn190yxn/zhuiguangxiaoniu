<?php
declare(strict_types=1);

/**
 * Global search API.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/search-service.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(405, '不支持的请求方法');
        exit;
    }

    $userId = (int)getCurrentUserId();
    if ($userId <= 0) {
        jsonResponse(401, '请先登录', null, 401);
        exit;
    }

    $query = trim((string)($_GET['q'] ?? ''));
    $type = trim((string)($_GET['type'] ?? 'all'));
    if ($query === '') {
        jsonResponse(400, '请输入搜索关键词');
        exit;
    }

    $db = getDB();
    $context = searchCurrentContext($db, $userId);
    $data = searchAll($db, $query, $type, $context);

    jsonResponse(0, 'success', $data);
} catch (Throwable $e) {
    error_log('[search.global] ' . $e->getMessage());
    jsonResponse(1, '搜索失败，请稍后重试');
}
