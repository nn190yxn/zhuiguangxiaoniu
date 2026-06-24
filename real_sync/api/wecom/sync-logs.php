<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/common.php';
require_once __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(200);
    exit;
}

adminRequireAuth('adminCanAccessHeadquarter');

try {
    $pdo = wecomDb();
    $result = wecomListSyncLogs($pdo, [
        'page' => (int)($_GET['page'] ?? 1),
        'page_size' => (int)($_GET['page_size'] ?? 20),
        'status' => $_GET['status'] ?? '',
        'sync_type' => $_GET['sync_type'] ?? 'members',
    ]);
    json_response(0, 'success', $result);
} catch (Throwable $e) {
    json_response(500, '获取企业微信同步日志失败：' . $e->getMessage());
}
