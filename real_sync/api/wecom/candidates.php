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
    $keyword = $_GET['keyword'] ?? '';
    $mobile = $_GET['mobile'] ?? '';
    $name = $_GET['name'] ?? '';
    $employeeNo = $_GET['employee_no'] ?? '';
    $limit = (int)($_GET['limit'] ?? 20);

    $list = wecomFindCandidateStaffs($pdo, [
        'keyword' => $keyword,
        'mobile' => $mobile,
        'name' => $name,
        'employee_no' => $employeeNo,
        'limit' => $limit,
    ]);

    json_response(0, 'success', [
        'filters' => [
            'keyword' => $keyword,
            'mobile' => $mobile,
            'name' => $name,
            'employee_no' => $employeeNo,
            'limit' => $limit,
        ],
        'list' => $list,
    ]);
} catch (Throwable $e) {
    json_response(500, '获取候选员工失败：' . $e->getMessage());
}
