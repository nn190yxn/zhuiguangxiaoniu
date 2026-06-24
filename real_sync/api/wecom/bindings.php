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
    $result = wecomListBindings($pdo, [
        'page' => (int)($_GET['page'] ?? 1),
        'page_size' => (int)($_GET['page_size'] ?? 20),
        'keyword' => $_GET['keyword'] ?? '',
        'binding_status' => $_GET['binding_status'] ?? '',
        'store_id' => (int)($_GET['store_id'] ?? 0),
        'role' => $_GET['role'] ?? '',
    ]);

    json_response(0, 'success', array_merge($result, [
        'filters' => [
            'page' => (int)($_GET['page'] ?? 1),
            'page_size' => (int)($_GET['page_size'] ?? 20),
            'keyword' => (string)($_GET['keyword'] ?? ''),
            'binding_status' => (string)($_GET['binding_status'] ?? ''),
            'store_id' => (int)($_GET['store_id'] ?? 0),
            'role' => (string)($_GET['role'] ?? ''),
        ],
    ]));
} catch (Throwable $e) {
    json_response(500, '获取企业微信映射列表失败：' . $e->getMessage());
}
