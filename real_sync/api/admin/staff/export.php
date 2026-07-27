<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/StaffDirectoryService.php';

handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(1, '仅支持 GET 请求');
}

try {
    [, $user, $staff] = adminRequirePermission('staff.view_all');
    $roles = appRoleTokensFromUser($user, $staff);
    $canViewSensitive = (bool)array_intersect(['operation', 'admin'], $roles);
    $export = (new StaffDirectoryService(getDB(), $canViewSensitive))->export($_GET);

    $filename = 'staff-directory-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('X-Export-Row-Count: ' . (int)$export['total']);

    $output = fopen('php://output', 'wb');
    if (!$output) {
        throw new RuntimeException('无法创建导出文件');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, [
        '工号', '姓名', '手机号', '门店', '主岗位', '系统角色', '人员阶段', '生命周期',
        '账号状态', '入职日期', '离职时间', '登录账号', '邮箱', '创建时间',
    ]);
    foreach ($export['rows'] as $item) {
        fputcsv($output, array_map('staffExportCsvValue', [
            $item['employee_no'],
            $item['name'],
            $item['phone'],
            $item['store_name'],
            $item['primary_position_name'],
            $item['role_name'],
            $item['stage'],
            staffExportLifecycleText((string)$item['lifecycle_status']),
            $item['account_enabled'] ? '启用' : ($item['account_linked'] ? '停用' : '未关联'),
            $item['entry_date'],
            $item['offboarded_at'],
            $item['username'],
            $item['email'],
            $item['created_at'],
        ]));
    }
    fclose($output);
} catch (StaffDirectoryExportLimitException $error) {
    jsonResponse(400, $error->getMessage());
} catch (Throwable $error) {
    error_log('[admin.staff.export] ' . $error->getMessage());
    if (!headers_sent()) {
        jsonResponse(1, '员工导出失败');
    }
}

function staffExportCsvValue($value): string {
    $value = (string)($value ?? '');
    if ($value !== '' && preg_match('/^[=+\-@]/', $value)) {
        return "'" . $value;
    }
    return $value;
}

function staffExportLifecycleText(string $status): string {
    return [
        'active' => '在职',
        'inactive' => '停用',
        'offboarded' => '离职',
    ][$status] ?? $status;
}
