<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadSourcePolicyService.php';
require_once __DIR__ . '/services/WorkloadMetricVersionService.php';
require_once __DIR__ . '/services/WorkloadEffectiveValueService.php';
require_once __DIR__ . '/services/WorkloadDailyTrackingService.php';
handleCORS();

try {
    $context = appRequireStaffContext();
    if (!appCanAccessWorkload(['role' => $context['role'] ?? ''], $context)) {
        appJsonError(403, '无权限查看员工工作量明细');
    }

    $staffId = appRequireInt(['staff_id' => appOptionalString($_GET, 'staff_id', '')], 'staff_id', '员工 ID');
    $date = appRequireDate(['date' => appOptionalString($_GET, 'date', date('Y-m-d'))], 'date', '日期');

    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $sourcePolicy = new WorkloadSourcePolicyService($pdo);
    $includedSources = $sourcePolicy->defaultIncludedSources();
    $includedCondition = WorkloadSourcePolicyService::includedByDefaultCondition('r');
    $metricVersionService = new WorkloadMetricVersionService($pdo);
    $metricMetadata = $metricVersionService->responseMetadata(
        ['date' => $date, 'staff_id' => $staffId],
        $includedSources
    );

    $staffStmt = $pdo->prepare("SELECT s.id AS staff_id, s.name AS staff_name, s.role, s.store_id, st.name AS store_name, s.status
        FROM staffs s
        LEFT JOIN stores st ON st.id=s.store_id
        WHERE s.id=?");
    $staffStmt->execute([$staffId]);
    $staff = $staffStmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff || (int)($staff['status'] ?? 0) !== 1) {
        appJsonError(404, '员工不存在或已停用');
    }

    $storeId = (int)($staff['store_id'] ?? 0);
    if (!appCanViewAll($context)) {
        $myStoreId = (int)($context['store_id'] ?? 0);
        if ($myStoreId <= 0 || $storeId !== $myStoreId) {
            appJsonError(403, '无权查看该员工工作量');
        }
    }

    $roleCode = appRoleCode((string)($staff['role'] ?? ''));
    $tpl = workloadTemplate($pdo, $roleCode);
    $templateItems = $tpl ? $tpl['items'] : [];

    $reportStmt = $pdo->prepare("SELECT r.id, r.report_date, r.store_id, st.name AS store_name, r.staff_id, s.name AS staff_name,
            r.role_code, r.submit_status, r.remarks, r.submitted_at, r.updated_at
        FROM workload_daily_reports r
        JOIN staffs s ON s.id=r.staff_id AND s.status=1
        LEFT JOIN stores st ON st.id=r.store_id
        WHERE r.report_date=? AND r.staff_id=? AND $includedCondition");
    $reportStmt->execute([$date, $staffId]);
    $report = $reportStmt->fetch(PDO::FETCH_ASSOC);

    $values = [];
    $evidences = [];
    $reportId = 0;
    $submitStatus = 'missing';

    if ($report) {
        $reportId = (int)$report['id'];
        $submitStatus = (string)($report['submit_status'] ?? 'missing');

        $valueExpressions = WorkloadEffectiveValueService::sqlExpressions();
        $valStmt = $pdo->prepare("SELECT m.metric_code, m.metric_name, m.unit, v.numeric_value,
                {$valueExpressions['raw_value']},
                {$valueExpressions['pending_value']},
                {$valueExpressions['effective_value']},
                {$valueExpressions['audit_mode']},
                {$valueExpressions['audit_status']}
            FROM workload_daily_report_values v
            JOIN workload_daily_reports r ON r.id = v.report_id
            JOIN metric_definitions m ON m.id=v.metric_id
            LEFT JOIN workload_role_metric_rules version_rules ON version_rules.rule_version_id = r.rule_version_id AND version_rules.metric_code = m.metric_code
            LEFT JOIN workload_metric_rules rules ON rules.role_code = r.role_code AND rules.metric_code = m.metric_code AND rules.enabled = 1
            LEFT JOIN workload_audit_tasks t ON t.report_id = r.id AND t.metric_code = m.metric_code AND t.superseded_at IS NULL AND t.audit_status <> 'superseded'
            WHERE v.report_id=?");
        $valStmt->execute([$reportId]);
        $savedValues = $valStmt->fetchAll(PDO::FETCH_ASSOC);
        $values = workloadMergeTemplateValues($templateItems, $savedValues);

        $evStmt = $pdo->prepare("SELECT id, metric_code, file_url, file_name, file_size, mime_type, created_at
            FROM workload_evidences
            WHERE deleted_at IS NULL AND report_id=?");
        $evStmt->execute([$reportId]);
        $evidences = workloadNormalizeEvidenceRows($evStmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        $values = workloadMergeTemplateValues($templateItems, []);
    }

    $dateTo = $date;
    $dateFrom = (new DateTimeImmutable($dateTo))->modify('-6 days')->format('Y-m-d');
    $dates = [];
    for ($d = new DateTimeImmutable($dateFrom), $end = new DateTimeImmutable($dateTo); $d <= $end; $d = $d->modify('+1 day')) {
        $dates[] = $d->format('Y-m-d');
    }

    $recentStmt = $pdo->prepare("SELECT r.report_date, r.submit_status
        FROM workload_daily_reports r
        WHERE r.report_date BETWEEN ? AND ? AND r.staff_id=? AND $includedCondition");
    $recentStmt->execute([$dateFrom, $dateTo, $staffId]);
    $recentRows = $recentStmt->fetchAll(PDO::FETCH_ASSOC);
    $recentByDate = [];
    foreach ($recentRows as $row) {
        $recentByDate[(string)$row['report_date']] = (string)$row['submit_status'];
    }

    $recentList = [];
    foreach ($dates as $d) {
        $recentList[] = [
            'date' => $d,
            'status' => $recentByDate[$d] ?? 'missing',
            'is_selected' => $d === $date,
        ];
    }

    $tracking = (new WorkloadDailyTrackingService($pdo))->dailyTracking($context, [
        'date' => $date,
        'staff_id' => $staffId,
        'role_code' => $roleCode,
    ]);
    $dailySettlement = $tracking['items'][0] ?? null;

    appLogEvent('workload.staff_detail', array_merge(['staff_id' => $staffId, 'date' => $date, 'viewer_staff_id' => $context['staff_id'] ?? null], $metricVersionService->auditContext()));
    appJsonSuccess([
        'staff' => [
            'staff_id' => $staffId,
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'role_code' => $roleCode,
            'store_id' => $storeId,
            'store_name' => (string)($staff['store_name'] ?? ''),
        ],
        'date' => $date,
        'source_policy' => ['included_by_default' => $includedSources],
        'submit_status' => $submitStatus,
        'remarks' => $report ? (string)($report['remarks'] ?? '') : '',
        'submitted_at' => $report ? (string)($report['submitted_at'] ?? '') : '',
        'updated_at' => $report ? (string)($report['updated_at'] ?? '') : '',
        'values' => $values,
        'evidences' => $evidences,
        'evidence_count' => count($evidences),
        'report_id' => $reportId,
        'recent_days' => $recentList,
        'daily_settlement' => $dailySettlement,
    ] + $metricMetadata);
} catch (Throwable $e) {
    appLogEvent('workload.staff_detail_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取员工工作量明细失败');
}

function workloadMergeTemplateValues(array $templateItems, array $savedValues): array {
    if (!$templateItems) {
        return array_map(static function ($value) {
            return [
                'metric_code' => (string)($value['metric_code'] ?? ''),
                'metric_name' => (string)($value['metric_name'] ?? ($value['metric_code'] ?? '')),
                'unit' => (string)($value['unit'] ?? ''),
                'numeric_value' => (float)($value['numeric_value'] ?? 0),
                'raw_value' => (float)($value['raw_value'] ?? ($value['numeric_value'] ?? 0)),
                'pending_value' => (float)($value['pending_value'] ?? 0),
                'effective_value' => (float)($value['effective_value'] ?? ($value['numeric_value'] ?? 0)),
                'audit_mode' => (string)($value['audit_mode'] ?? 'none'),
                'audit_status' => (string)($value['audit_status'] ?? ''),
                'is_filled' => true,
            ];
        }, $savedValues);
    }
    $savedByCode = [];
    foreach ($savedValues as $value) {
        $savedByCode[(string)($value['metric_code'] ?? '')] = $value;
    }
    $rows = [];
    foreach ($templateItems as $item) {
        $code = (string)($item['metric_code'] ?? '');
        $saved = $savedByCode[$code] ?? [];
        $rawValue = isset($saved['raw_value']) ? (float)$saved['raw_value'] : (float)($item['default_value'] ?? 0);
        $rows[] = [
            'metric_code' => $code,
            'metric_name' => (string)($item['metric_name'] ?? $code),
            'unit' => (string)($item['unit'] ?? ''),
            'numeric_value' => $rawValue,
            'raw_value' => $rawValue,
            'pending_value' => (float)($saved['pending_value'] ?? 0),
            'effective_value' => isset($saved['effective_value']) ? (float)$saved['effective_value'] : $rawValue,
            'audit_mode' => (string)($saved['audit_mode'] ?? 'none'),
            'audit_status' => (string)($saved['audit_status'] ?? ''),
            'is_filled' => isset($savedByCode[$code]),
            'need_evidence' => (int)($item['need_evidence'] ?? 0),
        ];
    }
    return $rows;
}
