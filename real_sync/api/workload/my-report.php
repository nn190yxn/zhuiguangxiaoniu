<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadReportStateService.php';
require_once __DIR__ . '/services/WorkloadAuditTaskService.php';
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
    $stmt = $pdo->prepare("SELECT * FROM workload_daily_reports WHERE report_date=? AND store_id=? AND staff_id=? ORDER BY id ASC");
    $stmt->execute([$date, $storeId, $staffId]);
    $report = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
        if (appRoleCode((string)($candidate['role_code'] ?? '')) === $role) {
            $report = $candidate;
            break;
        }
    }
    $values = [];
    if ($report) {
        $valStmt = $pdo->prepare("SELECT m.metric_code, v.numeric_value, v.text_value, v.json_value FROM workload_daily_report_values v JOIN metric_definitions m ON m.id=v.metric_id WHERE v.report_id=?");
        $valStmt->execute([(int)$report['id']]);
        foreach ($valStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $values[$row['metric_code']] = $row['numeric_value'] !== null ? (float)$row['numeric_value'] : ($row['text_value'] ?? null);
        }
    }
    $storeMetricSummary = $role === 'manager' ? workloadManagerStoreMetricSummary($pdo, $date, $storeId) : [];
    if ($storeMetricSummary !== []) {
        $roleRuleVersion = (new WorkloadRoleRuleVersionService($pdo))->activeForDate($role, $date);
        $values = workloadApplyManagerStoreMetricSummary($values, $storeMetricSummary, $roleRuleVersion['metric_rules']);
    }
    $state = (new WorkloadReportStateService($pdo))->stateForScope(
        $date,
        $storeId,
        $staffId,
        $role
    );
    $auditState = $report
        ? (new WorkloadAuditTaskService($pdo))->employeeReviewState((int) $report['id'], $staffId)
        : ['tasks' => [], 'pending_items' => [], 'needs_resubmit_count' => 0];
    appJsonSuccess([
        'report' => $report ?: null,
        'values' => $values,
        'store_metric_summary' => $storeMetricSummary,
        'obligation' => $state['obligation'],
        'completion_status' => $state['completion_status'],
        'deadline_at' => $state['deadline_at'],
        'is_writable' => $state['is_writable'],
        'pending_items' => array_values(array_merge($state['pending_items'], $auditState['pending_items'])),
        'is_weekly_rest_day' => $state['is_weekly_rest_day'],
        'audit_tasks' => $auditState['tasks'],
        'needs_resubmit_count' => $auditState['needs_resubmit_count'],
    ]);
} catch (Throwable $e) {
    appLogEvent('workload.my_report_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取我的日报失败');
}
