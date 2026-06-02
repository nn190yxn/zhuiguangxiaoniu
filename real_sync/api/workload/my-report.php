<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
handleCORS();

try {
    $context = appRequireStaffContext();
    $input = $_GET;
    $date = appRequireDate($input, 'date', '日期');
    $role = appRoleCode(appOptionalString($input, 'role', (string)($context['role'] ?? '')));
    $storeId = isset($input['store_id']) ? appRequireInt($input, 'store_id', '门店') : (int)($context['store_id'] ?? 0);
    if (!workloadAllowedRoleForContext($context, $role)) {
        appJsonError(403, '无权限查看该岗位日报');
    }
    appRequireViewStore($context, $storeId);
    $staffId = (int)($context['staff_id'] ?? 0);
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM workload_daily_reports WHERE report_date=? AND store_id=? AND staff_id=? AND role_code=? LIMIT 1");
    $stmt->execute([$date, $storeId, $staffId, $role]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    $values = [];
    if ($report) {
        $valStmt = $pdo->prepare("SELECT m.metric_code, v.numeric_value, v.text_value, v.json_value FROM workload_daily_report_values v JOIN metric_definitions m ON m.id=v.metric_id WHERE v.report_id=?");
        $valStmt->execute([(int)$report['id']]);
        foreach ($valStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[$row['metric_code']] = $row['numeric_value'] !== null ? (float)$row['numeric_value'] : ($row['text_value'] ?? null);
        }
    }
    appJsonSuccess(['report' => $report ?: null, 'values' => $values]);
} catch (Throwable $e) {
    appLogEvent('workload.my_report_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取我的日报失败');
}
