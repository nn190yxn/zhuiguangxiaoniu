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
    json_response(0, 'success', wecomBuildConsistencyAudit($pdo, (string)($_GET['date'] ?? '')));
} catch (Throwable $e) {
    json_response(500, '获取企业微信消息口径一致性审计失败：' . $e->getMessage());
}
