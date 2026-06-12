<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
handleCORS();

try {
    $context = appRequireStaffContext();
    $date = appRequireDate($_GET, 'date', '日期');
    $storeId = isset($_GET['store_id']) ? appRequireInt($_GET, 'store_id', '门店') : (int)($context['store_id'] ?? 0);
    appRequireEditStore($context, $storeId);
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);

    $stmt = $pdo->prepare("SELECT r.*, s.name AS staff_name, st.name AS store_name
        FROM workload_daily_reports r
        JOIN staffs s ON s.id=r.staff_id AND s.status=1
        LEFT JOIN stores st ON st.id=r.store_id
        WHERE r.report_date=? AND r.store_id=?
        ORDER BY r.updated_at DESC, r.id DESC");
    $stmt->execute([$date, $storeId]);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $staffStmt = $pdo->prepare("SELECT id, name, role, job_title, phone
        FROM staffs
        WHERE store_id=? AND status=1 AND role IN ('sales','coach','consultant','实习销售','实习教练','销售','教练')
        ORDER BY FIELD(role,'sales','consultant','实习销售','销售','coach','实习教练','教练'), id");
    $staffStmt->execute([$storeId]);
    $expectedStaff = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

    $reportIds = array_map(static fn($r) => (int)$r['id'], $reports);
    $valuesByReport = [];
    $valueSumsByReport = [];
    if ($reportIds) {
        $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
        $valStmt = $pdo->prepare("SELECT v.report_id, m.metric_code, m.metric_name, m.role_code, m.metric_category, m.unit, v.numeric_value,
                CASE WHEN COALESCE(rules.audit_mode, 'none') = 'full' THEN IF(t.audit_status = 'approved', v.numeric_value, 0) ELSE v.numeric_value END AS effective_value,
                COALESCE(rules.audit_mode, 'none') AS audit_mode,
                COALESCE(t.audit_status, '') AS audit_status
            FROM workload_daily_report_values v
            JOIN workload_daily_reports r ON r.id = v.report_id
            JOIN metric_definitions m ON m.id=v.metric_id
            LEFT JOIN workload_metric_rules rules ON rules.role_code = r.role_code AND rules.metric_code = m.metric_code AND rules.enabled = 1
            LEFT JOIN workload_audit_tasks t ON t.report_id = r.id AND t.metric_code = m.metric_code
            WHERE v.report_id IN ($placeholders)");
        $valStmt->execute($reportIds);
        foreach ($valStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rid = (int)$row['report_id'];
            $valuesByReport[$rid][] = $row;
            $valueSumsByReport[$rid] = (float)($valueSumsByReport[$rid] ?? 0) + (float)($row['effective_value'] ?? 0);
        }
    }

    $evidenceByReport = [];
    if ($reportIds) {
        $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
        $evidenceStmt = $pdo->prepare("SELECT id, report_id, metric_code, file_url, file_name, file_size, mime_type, created_at
            FROM workload_evidences
            WHERE report_id IN ($placeholders) AND deleted_at IS NULL
            ORDER BY created_at ASC, id ASC");
        $evidenceStmt->execute($reportIds);
        foreach ($evidenceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $evidenceByReport[(int)$row['report_id']][] = $row;
        }
    }

    $roleSummary = [];
    foreach ($reports as &$report) {
        $rid = (int)$report['id'];
        $report['values'] = $valuesByReport[$rid] ?? [];
        $report['metric_value_sum'] = round((float)($valueSumsByReport[$rid] ?? 0), 2);
        $report['evidences'] = $evidenceByReport[$rid] ?? [];
        $report['evidence_count'] = count($report['evidences']);
        $role = $report['role_code'];
        if (!isset($roleSummary[$role])) {
            $roleSummary[$role] = ['role' => $role, 'submitted_count' => 0, 'metrics' => []];
        }
        if ($report['submit_status'] === 'submitted') {
            $roleSummary[$role]['submitted_count']++;
        }
        if ($report['submit_status'] !== 'submitted') {
            continue;
        }
        foreach ($report['values'] as $value) {
            $code = $value['metric_code'];
            if (!isset($roleSummary[$role]['metrics'][$code])) {
                $roleSummary[$role]['metrics'][$code] = ['metric_code' => $code, 'metric_name' => $value['metric_name'], 'unit' => $value['unit'], 'value' => 0];
            }
            $roleSummary[$role]['metrics'][$code]['value'] += (float)($value['numeric_value'] ?? 0);
        }
    }
    unset($report);

    foreach ($roleSummary as &$summary) {
        $summary['metrics'] = array_values($summary['metrics']);
    }
    unset($summary);

    $reportsByStaff = [];
    $submittedStaffIds = [];
    $draftStaff = [];
    foreach ($reports as $report) {
        $sid = (int)$report['staff_id'];
        $reportsByStaff[$sid] = $report;
        if ($report['submit_status'] === 'submitted') {
            $submittedStaffIds[$sid] = true;
        } else {
            $draftStaff[] = [
                'staff_id' => $sid,
                'staff_name' => $report['staff_name'] ?? '',
                'role_code' => $report['role_code'] ?? '',
                'updated_at' => $report['updated_at'] ?? '',
            ];
        }
    }

    $missingStaff = [];
    foreach ($expectedStaff as $staff) {
        $sid = (int)$staff['id'];
        if (!isset($reportsByStaff[$sid])) {
            $missingStaff[] = [
                'staff_id' => $sid,
                'staff_name' => $staff['name'],
                'role_code' => appRoleCode((string)$staff['role']),
                'job_title' => $staff['job_title'] ?? '',
            ];
        }
    }

    $expectedCount = count($expectedStaff);
    $submittedCount = count($submittedStaffIds);
    $submissionRate = $expectedCount > 0 ? round($submittedCount * 100 / $expectedCount, 1) : 0;

    appLogEvent('workload.store_summary', ['staff_id' => $context['staff_id'] ?? null, 'store_id' => $storeId, 'date' => $date]);
    appJsonSuccess([
        'date' => $date,
        'store_id' => $storeId,
        'expected_count' => $expectedCount,
        'submitted_count' => $submittedCount,
        'missing_count' => count($missingStaff),
        'draft_count' => count($draftStaff),
        'submission_rate' => $submissionRate,
        'report_count' => count($reports),
        'role_summary' => array_values($roleSummary),
        'missing_staff' => $missingStaff,
        'draft_staff' => $draftStaff,
        'reports' => $reports,
    ]);
} catch (Throwable $e) {
    appLogEvent('workload.store_summary_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取门店汇总失败');
}
