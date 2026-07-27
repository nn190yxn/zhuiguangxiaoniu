<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadSourcePolicyService.php';
require_once __DIR__ . '/services/WorkloadMetricVersionService.php';
require_once __DIR__ . '/services/WorkloadEffectiveValueService.php';
handleCORS();

try {
    $context = appRequireStaffContext();
    if (!appCanViewAll($context)) {
        appJsonError(403, '无权限查看总部汇总');
    }
    $dateFrom = appRequireDate($_GET, 'date_from', '开始日期');
    $dateTo = appRequireDate($_GET, 'date_to', '结束日期');
    if ($dateFrom > $dateTo) {
        appJsonError(400, '开始日期不能晚于结束日期');
    }
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $sourcePolicy = new WorkloadSourcePolicyService($pdo);
    $includedSources = $sourcePolicy->defaultIncludedSources();
    $includedCondition = WorkloadSourcePolicyService::includedByDefaultCondition('r');
    $metricVersionService = new WorkloadMetricVersionService($pdo);
    $metricMetadata = $metricVersionService->responseMetadata(
        ['date_from' => $dateFrom, 'date_to' => $dateTo],
        $includedSources
    );

    $days = (new DateTimeImmutable($dateFrom))->diff(new DateTimeImmutable($dateTo))->days + 1;

    $stmt = $pdo->prepare("SELECT r.report_date, r.store_id, st.name AS store_name, r.role_code, r.submit_status, COUNT(*) AS report_count
        FROM workload_daily_reports r
        JOIN staffs s ON s.id = r.staff_id AND s.status = 1
        LEFT JOIN stores st ON st.id=r.store_id
        WHERE r.report_date BETWEEN ? AND ? AND $includedCondition
        GROUP BY r.report_date, r.store_id, st.name, r.role_code, r.submit_status
        ORDER BY r.report_date DESC, r.store_id, r.role_code");
    $stmt->execute([$dateFrom, $dateTo]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $valueExpressions = WorkloadEffectiveValueService::aggregateSqlExpressions();
    $metricStmt = $pdo->prepare("SELECT r.report_date, r.store_id, st.name AS store_name, r.role_code, m.metric_code, m.metric_name, m.unit,
            {$valueExpressions['raw_value']},
            {$valueExpressions['pending_value']},
            {$valueExpressions['effective_value']}
        FROM workload_daily_report_values v
        JOIN workload_daily_reports r ON r.id=v.report_id
        JOIN staffs s ON s.id = r.staff_id AND s.status = 1
        JOIN metric_definitions m ON m.id=v.metric_id
        LEFT JOIN workload_role_metric_rules version_rules ON version_rules.rule_version_id = r.rule_version_id AND version_rules.metric_code = m.metric_code
        LEFT JOIN workload_metric_rules rules ON rules.role_code = r.role_code AND rules.metric_code = m.metric_code AND rules.enabled = 1
        LEFT JOIN workload_audit_tasks t ON t.report_id = r.id AND t.metric_code = m.metric_code AND t.superseded_at IS NULL AND t.audit_status <> 'superseded'
        LEFT JOIN stores st ON st.id=r.store_id
        WHERE r.report_date BETWEEN ? AND ? AND r.submit_status = 'submitted' AND $includedCondition
        GROUP BY r.report_date, r.store_id, st.name, r.role_code, m.metric_code, m.metric_name, m.unit
        ORDER BY r.report_date DESC, r.store_id, r.role_code, m.sort_order");
    $metricStmt->execute([$dateFrom, $dateTo]);
    $metrics = $metricStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($metrics as &$metric) {
        $metric['metric_value'] = (float)($metric['effective_value'] ?? 0);
    }
    unset($metric);

    $expectedStmt = $pdo->query("SELECT s.store_id, st.name AS store_name, COUNT(*) AS expected_staff_count
        FROM staffs s
        LEFT JOIN stores st ON st.id=s.store_id
        WHERE s.status=1 AND s.store_id IS NOT NULL AND s.role IN ('sales','coach','consultant','实习销售','实习教练','销售','教练')
        GROUP BY s.store_id, st.name
        ORDER BY s.store_id");
    $storeSubmissionRows = [];
    foreach ($expectedStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $storeId = (int)$row['store_id'];
        $expectedStaff = (int)$row['expected_staff_count'];
        $storeSubmissionRows[$storeId] = [
            'store_id' => $storeId,
            'store_name' => $row['store_name'] ?? '',
            'expected_staff_count' => $expectedStaff,
            'expected_count' => $expectedStaff * $days,
            'submitted_count' => 0,
            'draft_count' => 0,
            'missing_count' => $expectedStaff * $days,
            'submission_rate' => 0,
        ];
    }

    $actualStmt = $pdo->prepare("SELECT r.store_id, r.submit_status, COUNT(*) AS report_count
        FROM workload_daily_reports r
        JOIN staffs s ON s.id = r.staff_id AND s.status = 1
        WHERE r.report_date BETWEEN ? AND ? AND $includedCondition
        GROUP BY r.store_id, r.submit_status");
    $actualStmt->execute([$dateFrom, $dateTo]);
    foreach ($actualStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $storeId = (int)$row['store_id'];
        if (!isset($storeSubmissionRows[$storeId])) {
            $storeSubmissionRows[$storeId] = [
                'store_id' => $storeId,
                'store_name' => '',
                'expected_staff_count' => 0,
                'expected_count' => 0,
                'submitted_count' => 0,
                'draft_count' => 0,
                'missing_count' => 0,
                'submission_rate' => 0,
            ];
        }
        $count = (int)$row['report_count'];
        if ($row['submit_status'] === 'submitted') {
            $storeSubmissionRows[$storeId]['submitted_count'] += $count;
        } else {
            $storeSubmissionRows[$storeId]['draft_count'] += $count;
        }
    }
    foreach ($storeSubmissionRows as &$row) {
        $row['missing_count'] = max(0, (int)$row['expected_count'] - (int)$row['submitted_count']);
        $row['submission_rate'] = (int)$row['expected_count'] > 0 ? round((int)$row['submitted_count'] * 100 / (int)$row['expected_count'], 1) : 0;
    }
    unset($row);
    usort($storeSubmissionRows, static function ($a, $b) {
        if ($a['submission_rate'] === $b['submission_rate']) {
            return $b['missing_count'] <=> $a['missing_count'];
        }
        return $a['submission_rate'] <=> $b['submission_rate'];
    });

    appLogEvent('workload.hq_summary', array_merge(['staff_id' => $context['staff_id'] ?? null, 'date_from' => $dateFrom, 'date_to' => $dateTo], $metricVersionService->auditContext()));
    appJsonSuccess([
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'days' => $days,
        'source_policy' => ['included_by_default' => $includedSources],
        'summary_rows' => $rows,
        'metric_rows' => $metrics,
        'store_submission_rows' => array_values($storeSubmissionRows),
    ] + $metricMetadata);
} catch (Throwable $e) {
    appLogEvent('workload.hq_summary_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取总部汇总失败');
}
