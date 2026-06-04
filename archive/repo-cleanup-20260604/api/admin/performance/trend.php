<?php
require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    [, , $staff] = adminRequireAuth('adminCanAccessPerformance');

    $dateFrom = isset($_GET['date_from']) && $_GET['date_from'] ? $_GET['date_from'] : date('Y-m-d', strtotime('-13 days'));
    $dateTo = isset($_GET['date_to']) && $_GET['date_to'] ? $_GET['date_to'] : date('Y-m-d');
    $storeId = isset($_GET['store_id']) ? (int)$_GET['store_id'] : 0;
    $role = isset($_GET['role']) ? trim((string)$_GET['role']) : '';

    $storeMap = adminStoreNameById($db);
    $allowedStoreIds = [];
    if (!adminCanAccessHeadquarter(getJwtCurrentUser(), $staff) && in_array('manager', adminRoleTokens(getJwtCurrentUser(), $staff), true)) {
        $managedStoreId = (int)($staff['store_id'] ?? 0);
        if ($managedStoreId > 0) {
            $allowedStoreIds[] = $managedStoreId;
        }
    }

    if (!adminTableExists($db, 'campaign_daily_entries')) {
        jsonResponse(0, 'success', [
            'trend' => [],
            'store_breakdown' => [],
            'role_breakdown' => [],
            'list' => [],
            'filters' => compact('dateFrom', 'dateTo', 'storeId', 'role'),
            'meta' => ['source' => 'campaign_daily_entries', 'available' => false],
        ]);
    }

    $sql = 'SELECT entry_date, store, role_type, person_name, data_json FROM campaign_daily_entries WHERE entry_date BETWEEN ? AND ?';
    $params = [$dateFrom, $dateTo];
    if ($role !== '') {
        $sql .= ' AND role_type = ?';
        $params[] = $role;
    }
    $sql .= ' ORDER BY entry_date ASC, store ASC, role_type ASC, person_name ASC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $trendMap = [];
    $storeBreakdown = [];
    $roleBreakdown = [];
    $list = [];

    foreach ($rows as $row) {
        $storeName = (string)($row['store'] ?? '');
        $resolvedStoreId = array_search($storeName, $storeMap, true);
        $resolvedStoreId = $resolvedStoreId === false ? 0 : (int)$resolvedStoreId;

        if ($storeId > 0 && $resolvedStoreId !== $storeId) {
            continue;
        }
        if ($allowedStoreIds && !in_array($resolvedStoreId, $allowedStoreIds, true)) {
            continue;
        }

        $entryDate = (string)$row['entry_date'];
        $roleType = (string)($row['role_type'] ?? '');
        $personName = (string)($row['person_name'] ?? '');
        $payload = json_decode((string)($row['data_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        $newSign = 0;
        $renew = 0;
        $revenue = 0;

        if ($roleType === '销售') {
            $newSign = (int)($payload['deal'] ?? 0);
        } elseif ($roleType === '店长') {
            $newSign = (int)($payload['newSign'] ?? 0);
            $renew = (int)($payload['renew'] ?? 0);
            $revenue = (float)($payload['renewAmt'] ?? 0) + (float)($payload['newAmt'] ?? 0);
        }

        if (!isset($trendMap[$entryDate])) {
            $trendMap[$entryDate] = ['date' => $entryDate, 'new_sign_count' => 0, 'renew_count' => 0, 'revenue' => 0];
        }
        $trendMap[$entryDate]['new_sign_count'] += $newSign;
        $trendMap[$entryDate]['renew_count'] += $renew;
        $trendMap[$entryDate]['revenue'] += $revenue;

        if (!isset($storeBreakdown[$storeName])) {
            $storeBreakdown[$storeName] = ['store_name' => $storeName, 'new_sign_count' => 0, 'renew_count' => 0, 'revenue' => 0];
        }
        $storeBreakdown[$storeName]['new_sign_count'] += $newSign;
        $storeBreakdown[$storeName]['renew_count'] += $renew;
        $storeBreakdown[$storeName]['revenue'] += $revenue;

        if (!isset($roleBreakdown[$roleType])) {
            $roleBreakdown[$roleType] = ['role' => $roleType, 'new_sign_count' => 0, 'renew_count' => 0, 'revenue' => 0];
        }
        $roleBreakdown[$roleType]['new_sign_count'] += $newSign;
        $roleBreakdown[$roleType]['renew_count'] += $renew;
        $roleBreakdown[$roleType]['revenue'] += $revenue;

        if ($newSign > 0 || $renew > 0 || $revenue > 0) {
            $list[] = [
                'date' => $entryDate,
                'store_name' => $storeName,
                'new_sign_count' => $newSign,
                'renew_count' => $renew,
                'revenue' => (int)round($revenue),
                'owner_name' => $personName ?: '-',
                'role' => $roleType,
            ];
        }
    }

    jsonResponse(0, 'success', [
        'trend' => array_values($trendMap),
        'store_breakdown' => array_values($storeBreakdown),
        'role_breakdown' => array_values($roleBreakdown),
        'list' => $list,
        'filters' => [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'store_id' => $storeId,
            'role' => $role,
        ],
        'meta' => ['source' => 'campaign_daily_entries', 'available' => true],
    ]);
} catch (Throwable $e) {
    error_log('[admin.performance.trend] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
