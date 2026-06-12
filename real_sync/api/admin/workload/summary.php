<?php
require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__, 2) . '/workload/_common.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    workloadEnsureSchema($db);
    $context = adminRequireAuth('adminCanAccessWorkload');
    $staff = $context[2] ?? [];

    $date = isset($_GET['date']) && $_GET['date'] ? $_GET['date'] : date('Y-m-d');
    $role = isset($_GET['role']) ? trim((string)$_GET['role']) : '';

    // 切换新表
    $newTableAvailable = adminTableExists($db, 'workload_daily_reports');
    $metricTable = adminTableExists($db, 'metric_definitions');

    if (!$newTableAvailable) {
        jsonResponse(0, 'success', [
            'summary' => [
                'submitted_count' => 0,
                'expected_count' => 0,
                'completion_rate' => 0,
                'abnormal_count' => 0,
            ],
            'by_role' => [],
            'by_staff' => [],
            'list' => [],
            'filters' => ['date' => $date, 'role' => $role],
            'meta' => ['source' => 'workload_daily_reports', 'available' => false],
        ]);
    }

    $headquarterAccess = adminCanAccessHeadquarter(getJwtCurrentUser(), $staff);
    $managerStoreId = (int)($staff['store_id'] ?? 0);
    $storeIds = [];
    if (!$headquarterAccess && $managerStoreId > 0) {
        $storeIds[] = $managerStoreId;
    }

    // 查询今日已提交的报告
    $sql = 'SELECT r.id, r.report_date, r.store_id, st.name AS store_name, r.staff_id, 
                   s.name AS staff_name, s.role AS role_code, 
                   r.submit_status, r.source, r.remarks
            FROM workload_daily_reports r
            LEFT JOIN stores st ON st.id = r.store_id
            LEFT JOIN staffs s ON s.id = r.staff_id
            WHERE r.report_date = ?';
    $params = [$date];
    
    if ($role !== '') {
        $sql .= ' AND r.role_code = ?';
        $params[] = $role;
    }
    if ($storeIds) {
        $sql .= ' AND r.store_id = ?';
        $params[] = $managerStoreId;
    }
    // 只统计已提交的，或者草稿也显示但标记不同？
    // 为了兼容旧版 "submitted_count"，这里统计所有存在的记录，并在业务层区分状态
    $sql .= ' ORDER BY st.name ASC, r.role_code ASC, s.name ASC';
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $reports = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 添加诊断日志
    error_log('[admin.workload.summary_query] date=' . $date . ', role=' . $role . ', store_ids=' . json_encode($storeIds) . ', report_count=' . count($reports) . ', sql=' . $sql . ', params=' . json_encode($params));

    // 获取指标值
    $reportIds = array_map(fn($r) => (int)$r['id'], $reports);
    $valuesByReport = [];
    if ($reportIds && $metricTable) {
        $placeholders = implode(',', array_fill(0, count($reportIds), '?'));
        $valSql = "SELECT v.report_id, m.metric_code, m.metric_name, m.unit,
                          SUM(CASE WHEN COALESCE(rules.audit_mode, 'none') = 'full' THEN IF(t.audit_status = 'approved', v.numeric_value, 0) ELSE v.numeric_value END) AS total_value,
                          SUM(v.numeric_value) AS submitted_value
                   FROM workload_daily_report_values v
                   JOIN workload_daily_reports r ON r.id = v.report_id
                   JOIN metric_definitions m ON m.id = v.metric_id
                   LEFT JOIN workload_metric_rules rules ON rules.role_code = r.role_code AND rules.metric_code = m.metric_code AND rules.enabled = 1
                   LEFT JOIN workload_audit_tasks t ON t.report_id = r.id AND t.metric_code = m.metric_code
                   WHERE v.report_id IN ($placeholders)
                   GROUP BY v.report_id, m.metric_code, m.metric_name, m.unit";
        $valStmt = $db->prepare($valSql);
        $valStmt->execute($reportIds);
        while ($vRow = $valStmt->fetch(PDO::FETCH_ASSOC)) {
            $rid = (int)$vRow['report_id'];
            if (!isset($valuesByReport[$rid])) $valuesByReport[$rid] = [];
            $valuesByReport[$rid][] = $vRow;
        }
    }

    // 预期应提交人员
    $expectedSql = "SELECT s.id AS staff_id, s.name AS staff_name, s.role, s.store_id, st.name AS store_name
                    FROM staffs s
                    LEFT JOIN stores st ON st.id = s.store_id
                    WHERE s.status = 1";
    $expectedParams = [];
    if ($storeIds) {
        $expectedSql .= " AND s.store_id = ?";
        $expectedParams[] = $managerStoreId;
    }
    if ($role !== '') {
        $expectedSql .= " AND s.role = ?";
        $expectedParams[] = $role;
    } else {
        $expectedSql .= " AND s.role IN ('sales','coach')";
    }
    $expectedSql .= " ORDER BY st.name ASC, FIELD(s.role, 'coach', 'sales'), s.name ASC";
    $expectedStmt = $db->prepare($expectedSql);
    $expectedStmt->execute($expectedParams);
    $expectedStaffRows = $expectedStmt->fetchAll(PDO::FETCH_ASSOC);
    $expectedCount = count($expectedStaffRows);

    $byRole = [];
    $byStaff = [];
    $list = [];
    $templateItemsByRole = [];
    $submittedCount = 0;
    $draftCount = 0;
    $abnormalCount = 0;
    $reportedStaffIds = [];

    foreach ($reports as $row) {
        $reportId = (int)$row['id'];
        $roleType = (string)($row['role_code'] ?? '');
        $staffName = trim((string)($row['staff_name'] ?? ''));
        $storeName = (string)($row['store_name'] ?? '');
        $status = (string)($row['submit_status'] ?? '');
        $staffId = (int)($row['staff_id'] ?? 0);
        if ($staffId > 0) {
            $reportedStaffIds[$staffId] = true;
        }
        
        if ($status === 'submitted') {
            $submittedCount++;
        } elseif ($status === 'draft') {
            $draftCount++;
        }

        $values = $valuesByReport[$reportId] ?? [];
        if (!isset($templateItemsByRole[$roleType])) {
            $templateItemsByRole[$roleType] = adminWorkloadTemplateItems($db, $roleType);
        }
        $detailValues = adminWorkloadMergeTemplateValues($templateItemsByRole[$roleType], $values);
        $summaryParts = [];
        $score = 0;
        foreach ($detailValues as $v) {
            $val = (float)($v['numeric_value'] ?? 0);
            $score += $val;
            $summaryParts[] = ($v['metric_name'] ?? $v['metric_code']) . ':' . $val;
        }
        
        $summaryText = implode(' / ', $summaryParts);
        $isSubmitted = $status === 'submitted';
        $abnormal = $isSubmitted && $score <= 0; // 只把已提交且数值为0视为异常
        if ($abnormal) {
            $abnormalCount++;
        }

        // 按角色聚合
        if (!isset($byRole[$roleType])) {
            $byRole[$roleType] = ['role' => $roleType, 'report_count' => 0, 'submitted_count' => 0, 'draft_count' => 0, 'score_total' => 0, 'abnormal_count' => 0];
        }
        $byRole[$roleType]['report_count']++;
        if ($isSubmitted) {
            $byRole[$roleType]['submitted_count']++;
        } elseif ($status === 'draft') {
            $byRole[$roleType]['draft_count']++;
        }
        $byRole[$roleType]['score_total'] += $score;
        if ($abnormal) $byRole[$roleType]['abnormal_count']++;

        // 按员工聚合
        if ($staffName !== '') {
            $staffKey = $storeName . '::' . $roleType . '::' . $staffName;
            if (!isset($byStaff[$staffKey])) {
                $byStaff[$staffKey] = [
                    'store_name' => $storeName,
                    'store_id' => (int)($row['store_id'] ?? 0),
                    'staff_name' => $staffName,
                    'staff_id' => $staffId,
                    'role' => $roleType,
                    'report_count' => 0,
                    'submitted_count' => 0,
                    'draft_count' => 0,
                    'score_total' => 0,
                    'abnormal_count' => 0,
                ];
            }
            $byStaff[$staffKey]['report_count']++;
            if ($isSubmitted) {
                $byStaff[$staffKey]['submitted_count']++;
            } elseif ($status === 'draft') {
                $byStaff[$staffKey]['draft_count']++;
            }
            $byStaff[$staffKey]['score_total'] += $score;
            if ($abnormal) $byStaff[$staffKey]['abnormal_count']++;
        }

        $list[] = [
            'date' => $row['report_date'],
            'store_name' => $storeName,
            'store_id' => (int)($row['store_id'] ?? 0),
            'staff_name' => $staffName ?: '-',
            'staff_id' => $staffId,
            'role' => $roleType,
            'summary' => $summaryText ?: '无数值项',
            'values' => $detailValues,
            'score' => round($score, 1),
            'status' => $status,
            'abnormal' => $abnormal,
        ];
    }

    $missingStaff = [];
    foreach ($expectedStaffRows as $staffRow) {
        $sid = (int)($staffRow['staff_id'] ?? 0);
        if ($sid <= 0 || isset($reportedStaffIds[$sid])) {
            continue;
        }
        $staffName = trim((string)($staffRow['staff_name'] ?? ''));
        $storeName = (string)($staffRow['store_name'] ?? '');
        $roleType = (string)($staffRow['role'] ?? '');
        $missingItem = [
            'store_name' => $storeName,
            'store_id' => (int)($staffRow['store_id'] ?? 0),
            'staff_name' => $staffName,
            'staff_id' => $sid,
            'role' => $roleType,
            'report_count' => 0,
            'submitted_count' => 0,
            'draft_count' => 0,
            'score_total' => 0,
            'abnormal_count' => 0,
            'status' => 'missing',
        ];
        $byStaff[$storeName . '::' . $roleType . '::' . $staffName] = $missingItem;
        $missingStaff[] = $missingItem;
        $list[] = [
            'date' => $date,
            'store_name' => $storeName,
            'store_id' => (int)($staffRow['store_id'] ?? 0),
            'staff_name' => $staffName ?: '-',
            'staff_id' => $sid,
            'role' => $roleType,
            'summary' => '未提交',
            'values' => adminWorkloadMergeTemplateValues(adminWorkloadTemplateItems($db, $roleType), []),
            'score' => 0,
            'status' => 'missing',
            'abnormal' => false,
        ];
    }

    $completionRate = $expectedCount > 0 ? round($submittedCount / $expectedCount * 100, 1) : 0;

    usort($list, static fn($a, $b) => $b['score'] <=> $a['score']);
    uasort($byStaff, static fn($a, $b) => $b['score_total'] <=> $a['score_total']);

    jsonResponse(0, 'success', [
        'summary' => [
            'report_count' => count($reports),
            'submitted_count' => $submittedCount,
            'draft_count' => $draftCount,
            'missing_count' => count($missingStaff),
            'expected_count' => $expectedCount,
            'completion_rate' => $completionRate,
            'abnormal_count' => $abnormalCount,
        ],
        'by_role' => array_values($byRole),
        'by_staff' => array_values($byStaff),
        'missing_staff' => $missingStaff,
        'list' => $list,
        'filters' => ['date' => $date, 'role' => $role],
        'meta' => ['source' => 'workload_daily_reports', 'available' => true],
    ]);
} catch (Throwable $e) {
    error_log('[admin.workload.summary] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}

function adminWorkloadTemplateItems(PDO $db, string $role): array {
    $stmt = $db->prepare("SELECT id FROM workload_templates WHERE role_code=? AND is_active=1 ORDER BY version_no DESC, id DESC LIMIT 1");
    $stmt->execute([$role]);
    $templateId = (int)$stmt->fetchColumn();
    if ($templateId <= 0) {
        return [];
    }
    $itemStmt = $db->prepare("SELECT m.metric_code, m.metric_name, m.unit, m.default_value, COALESCE(i.sort_order, m.sort_order) AS sort_order
        FROM workload_template_items i
        JOIN metric_definitions m ON m.id=i.metric_id
        WHERE i.template_id=? AND m.is_active=1 AND i.is_visible=1
        ORDER BY COALESCE(i.sort_order, m.sort_order), m.sort_order, m.id");
    $itemStmt->execute([$templateId]);
    return $itemStmt->fetchAll(PDO::FETCH_ASSOC);
}

function adminWorkloadMergeTemplateValues(array $templateItems, array $savedValues): array {
    if (!$templateItems) {
        return array_map(static function ($value) {
            return [
                'metric_code' => (string)($value['metric_code'] ?? ''),
                'metric_name' => (string)($value['metric_name'] ?? ($value['metric_code'] ?? '')),
                'unit' => (string)($value['unit'] ?? ''),
                'numeric_value' => (float)($value['total_value'] ?? 0),
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
        $rows[] = [
            'metric_code' => $code,
            'metric_name' => (string)($item['metric_name'] ?? $code),
            'unit' => (string)($item['unit'] ?? ''),
            'numeric_value' => isset($saved['total_value']) ? (float)$saved['total_value'] : (float)($item['default_value'] ?? 0),
            'is_filled' => isset($savedByCode[$code]),
        ];
    }
    return $rows;
}
