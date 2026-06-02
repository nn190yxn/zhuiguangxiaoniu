<?php
require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    [, , $staff] = adminRequireAuth('adminCanAccessWorkload');

    $date = isset($_GET['date']) && $_GET['date'] ? $_GET['date'] : date('Y-m-d');
    $role = isset($_GET['role']) ? trim((string)$_GET['role']) : '';

    if (!adminTableExists($db, 'campaign_daily_entries')) {
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
            'meta' => ['source' => 'campaign_daily_entries', 'available' => false],
        ]);
    }

    $headquarterAccess = adminCanAccessHeadquarter(getJwtCurrentUser(), $staff);
    $managerStoreId = (int)($staff['store_id'] ?? 0);
    $storeMap = adminStoreNameById($db);
    $managerStoreName = $managerStoreId > 0 ? ($storeMap[$managerStoreId] ?? '') : '';

    $sql = 'SELECT entry_date, store, role_type, person_name, data_json FROM campaign_daily_entries WHERE entry_date = ?';
    $params = [$date];
    if ($role !== '') {
        $sql .= ' AND role_type = ?';
        $params[] = $role;
    }
    $sql .= ' ORDER BY store ASC, role_type ASC, person_name ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $roleSet = [];
    $staffSet = [];
    $byRole = [];
    $byStaff = [];
    $list = [];
    $abnormalCount = 0;

    foreach ($rows as $row) {
        $storeName = (string)($row['store'] ?? '');
        if (!$headquarterAccess && $managerStoreName !== '' && $storeName !== $managerStoreName) {
            continue;
        }

        $roleType = (string)($row['role_type'] ?? '');
        $personName = trim((string)($row['person_name'] ?? ''));
        $payload = json_decode((string)($row['data_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $metricValues = array_filter($payload, static fn($value) => is_numeric($value));
        $score = array_sum(array_map('floatval', $metricValues));
        $metricCount = count($metricValues);
        $status = $metricCount > 0 ? '已提交' : '空白';
        $abnormal = $metricCount === 0 || $score <= 0;

        if (!isset($byRole[$roleType])) {
            $byRole[$roleType] = ['role' => $roleType, 'submitted_count' => 0, 'score_total' => 0, 'abnormal_count' => 0];
        }
        $byRole[$roleType]['submitted_count']++;
        $byRole[$roleType]['score_total'] += $score;
        if ($abnormal) {
            $byRole[$roleType]['abnormal_count']++;
        }

        if ($personName !== '') {
            $staffKey = $storeName . '::' . $roleType . '::' . $personName;
            if (!isset($byStaff[$staffKey])) {
                $byStaff[$staffKey] = [
                    'store_name' => $storeName,
                    'staff_name' => $personName,
                    'role' => $roleType,
                    'submitted_count' => 0,
                    'score_total' => 0,
                    'abnormal_count' => 0,
                ];
            }
            $byStaff[$staffKey]['submitted_count']++;
            $byStaff[$staffKey]['score_total'] += $score;
            if ($abnormal) {
                $byStaff[$staffKey]['abnormal_count']++;
            }
            $staffSet[$staffKey] = true;
        }

        $roleSet[$roleType] = true;
        if ($abnormal) {
            $abnormalCount++;
        }

        $summaryText = implode(' / ', array_map(
            static fn($key, $value) => $key . ':' . $value,
            array_slice(array_keys($payload), 0, 4),
            array_slice(array_values($payload), 0, 4)
        ));

        $list[] = [
            'date' => (string)$row['entry_date'],
            'store_name' => $storeName,
            'staff_name' => $personName ?: '-',
            'role' => $roleType,
            'summary' => $summaryText ?: '无数值项',
            'score' => round($score, 1),
            'status' => $status,
            'abnormal' => $abnormal,
        ];
    }

    $submittedCount = count($list);
    $expectedCount = $submittedCount > 0 ? max($submittedCount, count($staffSet) ?: $submittedCount) : 0;
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
        'meta' => ['source' => 'campaign_daily_entries', 'available' => true],
    ]);
} catch (Throwable $e) {
    error_log('[admin.workload.summary] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
