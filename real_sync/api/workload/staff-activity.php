<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
handleCORS();

try {
    $context = appRequireStaffContext();
    if (!appCanAccessWorkload(['role' => $context['role'] ?? ''], $context)) {
        appJsonError(403, '无权限查看员工工作量明细');
    }

    $dateTo = appRequireDate(['date_to' => appOptionalString($_GET, 'date_to', date('Y-m-d'))], 'date_to', '结束日期');
    $dateFrom = appOptionalString($_GET, 'date_from', (new DateTimeImmutable($dateTo))->modify('-6 days')->format('Y-m-d'));
    $dateFrom = appRequireDate(['date_from' => $dateFrom], 'date_from', '开始日期');
    $dateTo = appRequireDate(['date_to' => $dateTo], 'date_to', '结束日期');
    if ($dateFrom > $dateTo) {
        appJsonError(400, '开始日期不能晚于结束日期');
    }
    $daysCount = (new DateTimeImmutable($dateFrom))->diff(new DateTimeImmutable($dateTo))->days + 1;
    if ($daysCount > 31) {
        appJsonError(400, '最多查看 31 天员工明细');
    }

    $storeId = isset($_GET['store_id']) && (int)$_GET['store_id'] > 0 ? (int)$_GET['store_id'] : 0;
    if (!appCanViewAll($context)) {
        $storeId = (int)($context['store_id'] ?? 0);
        if ($storeId <= 0) {
            appJsonError(403, '当前账号未绑定门店，无法查看员工工作量明细');
        }
    }
    if ($storeId > 0) {
        appRequireViewStore($context, $storeId);
    }

    $role = appOptionalString($_GET, 'role', '');
    $roleAliases = [];
    if ($role !== '') {
        $role = appRoleCode($role);
        if (!in_array($role, ['sales', 'coach'], true)) {
            appJsonError(400, '岗位只支持 sales 或 coach');
        }
        $roleAliases = $role === 'coach' ? ['coach', '教练', '实习教练'] : ['sales', 'consultant', 'sale', '销售', '实习销售'];
    }

    $pdo = workloadDb();
    workloadEnsureSchema($pdo);

    $dates = [];
    for ($d = new DateTimeImmutable($dateFrom), $end = new DateTimeImmutable($dateTo); $d <= $end; $d = $d->modify('+1 day')) {
        $dates[] = $d->format('Y-m-d');
    }

    $staffWhere = "s.status=1 AND s.store_id IS NOT NULL AND s.role IN ('sales','coach','consultant','实习销售','实习教练','销售','教练')";
    $staffParams = [];
    if ($storeId > 0) {
        $staffWhere .= ' AND s.store_id=?';
        $staffParams[] = $storeId;
    }
    if ($role !== '') {
        $staffWhere .= ' AND s.role IN (' . implode(',', array_fill(0, count($roleAliases), '?')) . ')';
        array_push($staffParams, ...$roleAliases);
    }
    $staffStmt = $pdo->prepare("SELECT s.id AS staff_id, s.name AS staff_name, s.role, s.store_id, st.name AS store_name
        FROM staffs s
        LEFT JOIN stores st ON st.id=s.store_id
        WHERE $staffWhere
        ORDER BY st.sort_order ASC, FIELD(s.role,'sales','consultant','实习销售','销售','coach','实习教练','教练'), s.name ASC");
    $staffStmt->execute($staffParams);
    $staffRows = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

    $staffById = [];
    $templateItemsByRole = [];
    foreach ($staffRows as $staff) {
        $sid = (int)$staff['staff_id'];
        $roleCode = appRoleCode((string)($staff['role'] ?? ''));
        $staffById[$sid] = [
            'staff_id' => $sid,
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'role_code' => $roleCode,
            'store_id' => (int)($staff['store_id'] ?? 0),
            'store_name' => (string)($staff['store_name'] ?? ''),
            'expected_count' => count($dates),
            'submitted_count' => 0,
            'draft_count' => 0,
            'missing_count' => count($dates),
            'reports' => [],
        ];
        if (!isset($templateItemsByRole[$roleCode])) {
            $tpl = workloadTemplate($pdo, $roleCode);
            $templateItemsByRole[$roleCode] = $tpl ? $tpl['items'] : [];
        }
    }

    $reportWhere = 'r.report_date BETWEEN ? AND ?';
    $reportParams = [$dateFrom, $dateTo];
    if ($storeId > 0) {
        $reportWhere .= ' AND r.store_id=?';
        $reportParams[] = $storeId;
    }
    if ($role !== '') {
        $reportWhere .= ' AND r.role_code IN (' . implode(',', array_fill(0, count($roleAliases), '?')) . ')';
        array_push($reportParams, ...$roleAliases);
    }

    $reportStmt = $pdo->prepare("SELECT r.id, r.report_date, r.store_id, st.name AS store_name, r.staff_id, s.name AS staff_name,
            r.role_code, r.submit_status, r.remarks, r.submitted_at, r.updated_at
        FROM workload_daily_reports r
        JOIN staffs s ON s.id=r.staff_id AND s.status=1
        LEFT JOIN stores st ON st.id=r.store_id
        WHERE $reportWhere
        ORDER BY r.report_date DESC, st.sort_order ASC, r.role_code ASC, s.name ASC");
    $reportStmt->execute($reportParams);
    $reports = $reportStmt->fetchAll(PDO::FETCH_ASSOC);

    $reportIds = array_map(static fn($r) => (int)$r['id'], $reports);
    $valuesByReport = [];
    $evidenceByReport = [];
    if ($reportIds) {
        $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
        $valueStmt = $pdo->prepare("SELECT v.report_id, m.metric_code, m.metric_name, m.unit, m.sort_order, v.numeric_value
            FROM workload_daily_report_values v
            JOIN metric_definitions m ON m.id=v.metric_id
            WHERE v.report_id IN ($placeholders)
            ORDER BY m.sort_order ASC, m.metric_code ASC");
        $valueStmt->execute($reportIds);
        foreach ($valueStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $valuesByReport[(int)$row['report_id']][] = $row;
        }

        $evidenceStmt = $pdo->prepare("SELECT id, report_id, metric_code, file_url, file_name, file_size, mime_type, created_at
            FROM workload_evidences
            WHERE deleted_at IS NULL AND report_id IN ($placeholders)
            ORDER BY created_at ASC, id ASC");
        $evidenceStmt->execute($reportIds);
        foreach (workloadNormalizeEvidenceRows($evidenceStmt->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $evidenceByReport[(int)$row['report_id']][] = $row;
        }
    }

    $reportDatesByStaff = [];
    foreach ($reports as $report) {
        $sid = (int)$report['staff_id'];
        if (!isset($staffById[$sid])) {
            $staffById[$sid] = [
                'staff_id' => $sid,
                'staff_name' => (string)($report['staff_name'] ?? ''),
                'role_code' => appRoleCode((string)($report['role_code'] ?? '')),
                'store_id' => (int)($report['store_id'] ?? 0),
                'store_name' => (string)($report['store_name'] ?? ''),
                'expected_count' => count($dates),
                'submitted_count' => 0,
                'draft_count' => 0,
                'missing_count' => count($dates),
                'reports' => [],
            ];
        }
        $rid = (int)$report['id'];
        $values = workloadMergeTemplateValues($templateItemsByRole[appRoleCode((string)($report['role_code'] ?? ''))] ?? [], $valuesByReport[$rid] ?? []);
        $evidences = $evidenceByReport[$rid] ?? [];
        $status = (string)($report['submit_status'] ?? '');
        if ($status === 'submitted') {
            $staffById[$sid]['submitted_count']++;
        } elseif ($status === 'draft') {
            $staffById[$sid]['draft_count']++;
        }
        $reportDatesByStaff[$sid][(string)$report['report_date']] = true;
        $staffById[$sid]['reports'][] = [
            'id' => $rid,
            'report_date' => (string)$report['report_date'],
            'submit_status' => $status,
            'remarks' => (string)($report['remarks'] ?? ''),
            'submitted_at' => (string)($report['submitted_at'] ?? ''),
            'updated_at' => (string)($report['updated_at'] ?? ''),
            'values' => $values,
            'evidences' => $evidences,
            'evidence_count' => count($evidences),
        ];
    }

    foreach ($staffById as $sid => &$staff) {
        $reported = count($reportDatesByStaff[$sid] ?? []);
        $staff['missing_count'] = max(0, (int)$staff['expected_count'] - $reported);
        $staff['missing_dates'] = array_values(array_filter($dates, static fn($date) => empty($reportDatesByStaff[$sid][$date])));
        $roleCode = appRoleCode((string)($staff['role_code'] ?? ''));
        foreach ($staff['missing_dates'] as $missingDate) {
            $staff['reports'][] = [
                'id' => 0,
                'report_date' => $missingDate,
                'submit_status' => 'missing',
                'remarks' => '',
                'submitted_at' => '',
                'updated_at' => '',
                'values' => workloadMergeTemplateValues($templateItemsByRole[$roleCode] ?? [], []),
                'evidences' => [],
                'evidence_count' => 0,
            ];
        }
        usort($staff['reports'], static fn($a, $b) => strcmp((string)($b['report_date'] ?? ''), (string)($a['report_date'] ?? '')));
    }
    unset($staff);

    appLogEvent('workload.staff_activity', ['staff_id' => $context['staff_id'] ?? null, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'store_id' => $storeId, 'role' => $role]);
    appJsonSuccess([
        'filters' => ['date_from' => $dateFrom, 'date_to' => $dateTo, 'store_id' => $storeId, 'role' => $role],
        'dates' => $dates,
        'staff_rows' => array_values($staffById),
    ]);
} catch (Throwable $e) {
    appLogEvent('workload.staff_activity_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取员工工作量明细失败');
}

function workloadMergeTemplateValues(array $templateItems, array $savedValues): array {
    if (!$templateItems) {
        return $savedValues;
    }
    $savedByCode = [];
    foreach ($savedValues as $value) {
        $savedByCode[(string)($value['metric_code'] ?? '')] = $value;
    }
    $rows = [];
    foreach ($templateItems as $item) {
        $code = (string)($item['metric_code'] ?? '');
        $saved = $savedByCode[$code] ?? [];
        $rows[] = [
            'metric_code' => $code,
            'metric_name' => (string)($item['metric_name'] ?? $code),
            'unit' => (string)($item['unit'] ?? ''),
            'sort_order' => (int)($item['item_sort_order'] ?? ($item['sort_order'] ?? 0)),
            'numeric_value' => isset($saved['numeric_value']) ? (float)$saved['numeric_value'] : (float)($item['default_value'] ?? 0),
            'is_filled' => isset($savedByCode[$code]),
            'need_evidence' => (int)($item['need_evidence'] ?? 0),
            'min_evidence_count' => workloadEvidenceMinLimit($item),
        ];
    }
    return $rows;
}
