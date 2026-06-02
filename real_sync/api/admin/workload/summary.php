<?php
require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

try {
    $db = getDB();
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
        $valSql = "SELECT v.report_id, m.metric_code, m.metric_name, m.unit, SUM(v.numeric_value) AS total_value
                   FROM workload_daily_report_values v
                   JOIN metric_definitions m ON m.id = v.metric_id
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

    // 预期应提交人数
    $expectedStmt = $db->prepare("SELECT COUNT(*) FROM staffs WHERE status = 1 AND role IN ('sales','coach')");
    if ($storeIds) {
         $expectedStmt = $db->prepare("SELECT COUNT(*) FROM staffs WHERE status = 1 AND role IN ('sales','coach') AND store_id = ?");
         $expectedStmt->execute([$managerStoreId]);
    } else {
         if ($role !== '') {
             $expectedStmt = $db->prepare("SELECT COUNT(*) FROM staffs WHERE status = 1 AND role = ?");
             $expectedStmt->execute([$role]);
         } else {
             $expectedStmt->execute();
         }
    }
    $expectedCount = (int)$expectedStmt->fetchColumn();

    $byRole = [];
    $byStaff = [];
    $list = [];
    $submittedCount = 0;
    $abnormalCount = 0;

    foreach ($reports as $row) {
        $reportId = (int)$row['id'];
        $roleType = (string)($row['role_code'] ?? '');
        $staffName = trim((string)($row['staff_name'] ?? ''));
        $storeName = (string)($row['store_name'] ?? '');
        $status = (string)($row['submit_status'] ?? '');
        
        if ($status === 'submitted') {
            $submittedCount++;
        }

        $values = $valuesByReport[$reportId] ?? [];
        $summaryParts = [];
        $score = 0;
        foreach ($values as $v) {
            $val = (float)($v['total_value'] ?? 0);
            $score += $val;
            $summaryParts[] = ($v['metric_name'] ?? $v['metric_code']) . ':' . $val;
        }
        
        $summaryText = implode(' / ', array_slice($summaryParts, 0, 4));
        $abnormal = $score <= 0; // 如果提交但数值为0视为异常
        if ($abnormal) {
            $abnormalCount++;
        }

        // 按角色聚合
        if (!isset($byRole[$roleType])) {
            $byRole[$roleType] = ['role' => $roleType, 'submitted_count' => 0, 'score_total' => 0, 'abnormal_count' => 0];
        }
        $byRole[$roleType]['submitted_count']++;
        $byRole[$roleType]['score_total'] += $score;
        if ($abnormal) $byRole[$roleType]['abnormal_count']++;

        // 按员工聚合
        if ($staffName !== '') {
            $staffKey = $storeName . '::' . $roleType . '::' . $staffName;
            if (!isset($byStaff[$staffKey])) {
                $byStaff[$staffKey] = [
                    'store_name' => $storeName,
                    'staff_name' => $staffName,
                    'role' => $roleType,
                    'submitted_count' => 0,
                    'score_total' => 0,
                    'abnormal_count' => 0,
                ];
            }
            $byStaff[$staffKey]['submitted_count']++;
            $byStaff[$staffKey]['score_total'] += $score;
            if ($abnormal) $byStaff[$staffKey]['abnormal_count']++;
        }

        $list[] = [
            'date' => $row['report_date'],
            'store_name' => $storeName,
            'staff_name' => $staffName ?: '-',
            'role' => $roleType,
            'summary' => $summaryText ?: '无数值项',
            'score' => round($score, 1),
            'status' => $status,
            'abnormal' => $abnormal,
        ];
    }

    $completionRate = $expectedCount > 0 ? round($submittedCount / $expectedCount * 100, 1) : 0;

    usort($list, static fn($a, $b) => $b['score'] <=> $a['score']);
    uasort($byStaff, static fn($a, $b) => $b['score_total'] <=> $a['score_total']);

    jsonResponse(0, 'success', [
        'summary' => [
            'submitted_count' => $submittedCount,
            'expected_count' => $expectedCount,
            'completion_rate' => $completionRate,
            'abnormal_count' => $abnormalCount,
        ],
        'by_role' => array_values($byRole),
        'by_staff' => array_values(array_slice($byStaff, 0, 12)),
        'list' => $list,
        'filters' => ['date' => $date, 'role' => $role],
        'meta' => ['source' => 'workload_daily_reports', 'available' => true],
    ]);
} catch (Throwable $e) {
    error_log('[admin.workload.summary] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
