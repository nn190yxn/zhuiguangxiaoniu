<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
handleCORS();

function workloadDashboardExpectedSql(bool $withStoreFilter = false): array {
    $sql = "SELECT s.id AS staff_id, s.name AS staff_name, s.role, s.store_id, st.name AS store_name
        FROM staffs s
        LEFT JOIN stores st ON st.id = s.store_id
        WHERE s.status = 1
          AND s.store_id IS NOT NULL
          AND s.role IN ('sales','coach','consultant','实习销售','实习教练','销售','教练')";
    $params = [];
    if ($withStoreFilter) {
        $sql .= " AND s.store_id = ?";
    }
    $sql .= " ORDER BY st.name, FIELD(s.role,'sales','consultant','实习销售','销售','coach','实习教练','教练'), s.name";
    return [$sql, $params];
}

function workloadDashboardPercent(int $part, int $total): float {
    return $total > 0 ? round($part * 100 / $total, 1) : 0.0;
}

function workloadDashboardDateRange(string $dateFrom, string $dateTo): array {
    $dates = [];
    $start = new DateTimeImmutable($dateFrom);
    $end = new DateTimeImmutable($dateTo);
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $dates[] = $d->format('Y-m-d');
    }
    return $dates;
}

try {
    $context = appRequireStaffContext();
    if (!appCanAccessWorkload(['role' => $context['role'] ?? ''], $context)) {
        appJsonError(403, '无权限查看工作量驾驶舱');
    }

    $dateTo = appOptionalString($_GET, 'date_to', date('Y-m-d'));
    $dateFrom = appOptionalString($_GET, 'date_from', (new DateTimeImmutable($dateTo))->modify('-6 days')->format('Y-m-d'));
    $dateFrom = appRequireDate(['date_from' => $dateFrom], 'date_from', '开始日期');
    $dateTo = appRequireDate(['date_to' => $dateTo], 'date_to', '结束日期');
    if ($dateFrom > $dateTo) {
        appJsonError(400, '开始日期不能晚于结束日期');
    }

    $days = workloadDashboardDateRange($dateFrom, $dateTo);
    if (count($days) > 31) {
        appJsonError(400, '驾驶舱最多查看 31 天数据');
    }

    $storeId = isset($_GET['store_id']) && (int)$_GET['store_id'] > 0 ? (int)$_GET['store_id'] : 0;
    if (!appCanViewAll($context)) {
        $storeId = (int)($context['store_id'] ?? 0);
        if ($storeId <= 0) {
            appJsonError(403, '当前账号未绑定门店，无法查看工作量驾驶舱');
        }
    }
    if ($storeId > 0) {
        appRequireViewStore($context, $storeId);
    }

    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    workloadEnsureAuditSchema($pdo);

    [$expectedSql] = workloadDashboardExpectedSql($storeId > 0);
    $expectedStmt = $pdo->prepare($expectedSql);
    $expectedStmt->execute($storeId > 0 ? [$storeId] : []);
    $expectedStaff = $expectedStmt->fetchAll(PDO::FETCH_ASSOC);
    $expectedByStaff = [];
    $storeRows = [];
    foreach ($expectedStaff as $staff) {
        $sid = (int)$staff['staff_id'];
        $sStoreId = (int)$staff['store_id'];
        $expectedByStaff[$sid] = $staff;
        if (!isset($storeRows[$sStoreId])) {
            $storeRows[$sStoreId] = [
                'store_id' => $sStoreId,
                'store_name' => (string)($staff['store_name'] ?? ''),
                'expected_staff_count' => 0,
                'expected_count' => 0,
                'submitted_count' => 0,
                'draft_count' => 0,
                'reported_count' => 0,
                'missing_count' => 0,
                'submission_rate' => 0,
            ];
        }
        $storeRows[$sStoreId]['expected_staff_count']++;
    }
    foreach ($storeRows as &$row) {
        $row['expected_count'] = (int)$row['expected_staff_count'] * count($days);
        $row['missing_count'] = $row['expected_count'];
    }
    unset($row);

    $reportWhere = "r.report_date BETWEEN ? AND ?";
    $reportParams = [$dateFrom, $dateTo];
    if ($storeId > 0) {
        $reportWhere .= " AND r.store_id = ?";
        $reportParams[] = $storeId;
    }

    $reportStmt = $pdo->prepare("SELECT r.id, r.report_date, r.store_id, st.name AS store_name, r.staff_id, s.name AS staff_name, r.role_code, r.submit_status, r.updated_at
        FROM workload_daily_reports r
        JOIN staffs s ON s.id = r.staff_id AND s.status = 1
        LEFT JOIN stores st ON st.id = r.store_id
        WHERE $reportWhere
        ORDER BY r.report_date DESC, r.store_id, r.role_code, s.name");
    $reportStmt->execute($reportParams);
    $reports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

    $submittedByDate = array_fill_keys($days, 0);
    $draftByDate = array_fill_keys($days, 0);
    $reportedKeys = [];
    $draftExceptions = [];
    $submittedCount = 0;
    $draftCount = 0;
    $reportIds = [];
    foreach ($reports as $report) {
        $rid = (int)$report['id'];
        $reportIds[] = $rid;
        $date = (string)$report['report_date'];
        $sid = (int)$report['staff_id'];
        $sStoreId = (int)$report['store_id'];
        $status = (string)$report['submit_status'];
        $reportedKeys[$date . ':' . $sid] = $report;
        if (!isset($storeRows[$sStoreId])) {
            $storeRows[$sStoreId] = [
                'store_id' => $sStoreId,
                'store_name' => (string)($report['store_name'] ?? ''),
                'expected_staff_count' => 0,
                'expected_count' => 0,
                'submitted_count' => 0,
                'draft_count' => 0,
                'reported_count' => 0,
                'missing_count' => 0,
                'submission_rate' => 0,
            ];
        }
        $storeRows[$sStoreId]['reported_count']++;
        if ($status === 'submitted') {
            $submittedCount++;
            $submittedByDate[$date] = (int)($submittedByDate[$date] ?? 0) + 1;
            $storeRows[$sStoreId]['submitted_count']++;
        } elseif ($status === 'draft') {
            $draftCount++;
            $draftByDate[$date] = (int)($draftByDate[$date] ?? 0) + 1;
            $storeRows[$sStoreId]['draft_count']++;
            $draftExceptions[] = [
                'type' => 'draft',
                'date' => $date,
                'store_id' => $sStoreId,
                'store_name' => (string)($report['store_name'] ?? ''),
                'staff_id' => $sid,
                'staff_name' => (string)($report['staff_name'] ?? ''),
                'role_code' => appRoleCode((string)$report['role_code']),
                'updated_at' => (string)($report['updated_at'] ?? ''),
                'message' => '日报仍为草稿，未计入运营指标',
            ];
        }
    }

    $missingExceptions = [];
    foreach ($days as $date) {
        foreach ($expectedStaff as $staff) {
            $sid = (int)$staff['staff_id'];
            if (isset($reportedKeys[$date . ':' . $sid])) {
                continue;
            }
            $sStoreId = (int)$staff['store_id'];
            $missingExceptions[] = [
                'type' => 'missing',
                'date' => $date,
                'store_id' => $sStoreId,
                'store_name' => (string)($staff['store_name'] ?? ''),
                'staff_id' => $sid,
                'staff_name' => (string)($staff['staff_name'] ?? ''),
                'role_code' => appRoleCode((string)$staff['role']),
                'message' => '未提交日报',
            ];
        }
    }

    foreach ($storeRows as &$row) {
        $row['missing_count'] = max(0, (int)$row['expected_count'] - (int)$row['reported_count']);
        $row['submission_rate'] = workloadDashboardPercent((int)$row['submitted_count'], (int)$row['expected_count']);
    }
    unset($row);
    usort($storeRows, static function ($a, $b) {
        if ((float)$a['submission_rate'] === (float)$b['submission_rate']) {
            return (int)$b['missing_count'] <=> (int)$a['missing_count'];
        }
        return (float)$a['submission_rate'] <=> (float)$b['submission_rate'];
    });

    $metricRows = [];
    if ($reportIds) {
        $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
        $metricStmt = $pdo->prepare("SELECT r.role_code, m.metric_code, m.metric_name, m.unit,
                SUM(CASE WHEN COALESCE(rules.audit_mode, 'none') = 'full' THEN IF(t.audit_status = 'approved', v.numeric_value, 0) ELSE v.numeric_value END) AS metric_value
            FROM workload_daily_report_values v
            JOIN workload_daily_reports r ON r.id = v.report_id
            JOIN metric_definitions m ON m.id = v.metric_id
            LEFT JOIN workload_metric_rules rules ON rules.role_code = r.role_code AND rules.metric_code = m.metric_code AND rules.enabled = 1
            LEFT JOIN workload_audit_tasks t ON t.report_id = r.id AND t.metric_code = m.metric_code
            WHERE r.id IN ($placeholders) AND r.submit_status = 'submitted'
            GROUP BY r.role_code, m.metric_code, m.metric_name, m.unit, m.sort_order
            ORDER BY r.role_code, m.sort_order, m.metric_code");
        $metricStmt->execute($reportIds);
        $metricRows = $metricStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $evidenceIssueRows = [];
    if ($reportIds) {
        $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
        $evidenceStmt = $pdo->prepare("SELECT r.report_date, r.store_id, st.name AS store_name, r.staff_id, s.name AS staff_name, r.role_code, m.metric_code, m.metric_name,
                COALESCE(v.numeric_value, 0) AS metric_value, rules.min_evidence_count, COUNT(e.id) AS evidence_count
            FROM workload_daily_reports r
            JOIN workload_daily_report_values v ON v.report_id = r.id
            JOIN metric_definitions m ON m.id = v.metric_id
            JOIN workload_metric_rules rules ON rules.role_code = r.role_code AND rules.metric_code = m.metric_code AND rules.enabled = 1 AND rules.need_evidence = 1
            LEFT JOIN workload_evidences e ON e.report_id = r.id AND e.metric_code = m.metric_code AND e.deleted_at IS NULL
            JOIN staffs s ON s.id = r.staff_id AND s.status = 1
            LEFT JOIN stores st ON st.id = r.store_id
            WHERE r.id IN ($placeholders) AND r.submit_status = 'submitted' AND COALESCE(v.numeric_value, 0) > 0
            GROUP BY r.report_date, r.store_id, st.name, r.staff_id, s.name, r.role_code, m.metric_code, m.metric_name, v.numeric_value, rules.min_evidence_count
            HAVING evidence_count < rules.min_evidence_count
            ORDER BY r.report_date DESC, r.store_id, s.name
            LIMIT 100");
        $evidenceStmt->execute($reportIds);
        $evidenceIssueRows = $evidenceStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $auditWhere = "created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)";
    $auditParams = [$dateFrom, $dateTo];
    if ($storeId > 0) {
        $auditWhere .= " AND store_id = ?";
        $auditParams[] = $storeId;
    }
    $auditStmt = $pdo->prepare("SELECT audit_status, COUNT(*) AS count
        FROM workload_audit_tasks
        WHERE $auditWhere
        GROUP BY audit_status");
    $auditStmt->execute($auditParams);
    $auditCounts = [];
    foreach ($auditStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $auditCounts[(string)$row['audit_status']] = (int)$row['count'];
    }

    $expectedCount = count($expectedStaff) * count($days);
    $trendRows = [];
    $expectedPerDay = count($expectedStaff);
    foreach ($days as $date) {
        $trendRows[] = [
            'date' => $date,
            'expected_count' => $expectedPerDay,
            'submitted_count' => (int)($submittedByDate[$date] ?? 0),
            'draft_count' => (int)($draftByDate[$date] ?? 0),
            'missing_count' => max(0, $expectedPerDay - (int)($submittedByDate[$date] ?? 0) - (int)($draftByDate[$date] ?? 0)),
            'submission_rate' => workloadDashboardPercent((int)($submittedByDate[$date] ?? 0), $expectedPerDay),
        ];
    }

    $exceptions = array_slice(array_merge($missingExceptions, $draftExceptions), 0, 120);
    foreach ($evidenceIssueRows as $row) {
        $exceptions[] = [
            'type' => 'evidence_missing',
            'date' => (string)$row['report_date'],
            'store_id' => (int)$row['store_id'],
            'store_name' => (string)($row['store_name'] ?? ''),
            'staff_id' => (int)$row['staff_id'],
            'staff_name' => (string)($row['staff_name'] ?? ''),
            'role_code' => appRoleCode((string)$row['role_code']),
            'metric_code' => (string)$row['metric_code'],
            'metric_name' => (string)$row['metric_name'],
            'message' => '已提交但凭证数量不足',
        ];
    }

    appLogEvent('workload.dashboard', ['staff_id' => $context['staff_id'] ?? null, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'store_id' => $storeId]);
    appJsonSuccess([
        'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'store_id' => $storeId],
        'kpis' => [
            'expected_count' => $expectedCount,
            'submitted_count' => $submittedCount,
            'draft_count' => $draftCount,
            'missing_count' => count($missingExceptions),
            'submission_rate' => workloadDashboardPercent($submittedCount, $expectedCount),
            'pending_audit_count' => (int)($auditCounts['pending'] ?? 0),
            'evidence_issue_count' => count($evidenceIssueRows),
        ],
        'store_rank_rows' => array_values($storeRows),
        'trend_rows' => $trendRows,
        'metric_rows' => $metricRows,
        'exceptions' => $exceptions,
        'audit_counts' => $auditCounts,
    ]);
} catch (Throwable $e) {
    appLogEvent('workload.dashboard_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取工作量驾驶舱失败');
}
