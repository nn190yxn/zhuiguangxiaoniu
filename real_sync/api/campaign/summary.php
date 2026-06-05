<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../common/context.php';
handleCORS();

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($data) {
        if (!is_string($data)) {
            return $data;
        }
        $trimmed = trim($data);
        if ($trimmed === 'N;') {
            return null;
        }
        if ($trimmed === '') {
            return $data;
        }
        if (!preg_match('/^(a|O|s|i|b|d|C):|^N;/', $trimmed)) {
            return $data;
        }
        $value = @unserialize($trimmed);
        return $value === false && $trimmed !== 'b:0;' ? $data : $value;
    }
}

// Auth check
$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(401, '请先登录');
}

handleCORS();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

function campaignSummaryWpRole(PDO $pdo, int $userId): string {
    if ($userId <= 0) {
        return 'staff';
    }
    $stmt = $pdo->prepare("SELECT meta_value FROM wp_usermeta WHERE user_id = ? AND meta_key = 'wp_capabilities' LIMIT 1");
    $stmt->execute([$userId]);
    $roleMeta = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($roleMeta) {
        $caps = maybe_unserialize($roleMeta['meta_value']);
        if (is_array($caps)) {
            if (isset($caps['administrator'])) {
                return 'admin';
            }
            if (isset($caps['editor'])) {
                return 'manager';
            }
        }
    }
    return 'staff';
}

function campaignSummaryAuth(PDO $pdo): array {
    $userId = getCurrentUserId();
    $wpUser = getCurrentUser();
    $staff = getStaffByUserId($userId);
    $appContext = appGetCurrentStaffContext();
    $wpRole = campaignSummaryWpRole($pdo, $userId);
    $staffRole = appRoleCode((string)($appContext['role'] ?? ($staff['role'] ?? '')));
    $displayName = trim((string) ($staff['name'] ?? ($wpUser['nickname'] ?? ($wpUser['username'] ?? ''))));
    $tokens = array_values(array_unique(array_filter([
        strtolower($wpRole),
        strtolower($staffRole),
        strtolower(normalizeStaffRoleCode($staffRole)),
    ])));
    $has = static function(array $candidates) use ($tokens): bool {
        foreach ($candidates as $candidate) {
            if (in_array(strtolower($candidate), $tokens, true)) {
                return true;
            }
        }
        return false;
    };
    $isWhitelist = !empty($appContext['is_hq']);
    $isAdminLike = $has(['admin', 'administrator']);
    $isCeo = $has(['ceo', '总经理']);
    $isOps = $has(['operation', 'operations', 'operator', 'ops', '运营', '总部运营']);
    $isFinance = $has(['finance', 'financial', '财务']);
    $isStoreManager = $has(['store_manager', 'shop_manager', 'manager', '店长']) && !$isAdminLike && !$isCeo && !$isOps && !$isFinance;
    $managedStores = [];
    if ($isStoreManager) {
        $storeId = (int) ($staff['store_id'] ?? 0);
        $storeMap = [
            1 => '未来方舟蘑菇城',
            2 => '小河',
            3 => '小十字',
            4 => '未来方舟青少店',
            5 => '玖福城',
        ];
        if (isset($storeMap[$storeId])) {
            $managedStores[] = $storeMap[$storeId];
        }
        $staffName = trim((string) ($staff['name'] ?? ''));
        $staffPhone = trim((string) ($staff['phone'] ?? ''));
        if ($staffName === '盛明菲' || $staffPhone === '18798742857') {
            $managedStores[] = '小河';
            $managedStores[] = '小十字';
        }
        $managedStores = array_values(array_unique(array_filter($managedStores)));
    }
    $canEditAllData = $isWhitelist || $isAdminLike || $isCeo || $isOps || $isFinance;
    $canEditStoreAllData = $canEditAllData || $isStoreManager;
    $canEditRevenue = $canEditStoreAllData;
    $allowedRoles = $canEditStoreAllData ? ['销售', '教练', '店长', '直播运营', '内容运营'] : [];
    if (!$canEditStoreAllData) {
        if ($has(['sales', 'consultant', '销售'])) $allowedRoles[] = '销售';
        if ($has(['coach', '教练'])) $allowedRoles[] = '教练';
        if ($isStoreManager) $allowedRoles[] = '店长';
        if ($has(['直播运营', 'live_ops', 'live_operation'])) $allowedRoles[] = '直播运营';
        if ($has(['内容运营', 'content_ops', 'content_operation'])) $allowedRoles[] = '内容运营';
    }
    return [
        'displayName' => $displayName,
        'wpRole' => $wpRole,
        'staffRole' => $staffRole,
        'allowedRoles' => array_values(array_unique($allowedRoles)),
        'managedStores' => $managedStores,
        'canEditRevenue' => $canEditRevenue,
        'canEditAllData' => $canEditAllData,
        'canEditStoreAllData' => $canEditStoreAllData,
        'canManageTargets' => $canEditAllData,
    ];
}

try {
    $pdo = getDB();
    $auth = campaignSummaryAuth($pdo);

    // Fetch all entries
    $stmt = $pdo->query("SELECT * FROM campaign_daily_entries ORDER BY entry_date, store, role_type");
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch all channel entries
    $chStmt = $pdo->query("SELECT * FROM campaign_channel_entries ORDER BY entry_date, store, channel");
    $chEntries = $chStmt->fetchAll(PDO::FETCH_ASSOC);

    // Rebuild state (same logic as frontend aggregateData)
    $STATE = [
        'total_rev' => 0,
        'renew_cnt' => 0,
        'new_cnt' => 0,
        'camp_cnt' => 0,
        'funnel' => ['resources' => 0, 'promise' => 0, 'visit' => 0, 'trial' => 0, 'deal' => 0],
        'channels' => ['douyin' => 0, 'meituan' => 0, 'referral' => 0, 'offline' => 0, 'other' => 0],
        'stores' => [],
        'sales' => [],
        'coaches' => [],
        'renew' => [
            'p0touch' => 0, 'p0renew' => 0, 'p0amt' => 0,
            'p1touch' => 0, 'p1renew' => 0, 'p1amt' => 0,
            'p2touch' => 0, 'p2renew' => 0, 'p2amt' => 0,
            'p3touch' => 0, 'p3renew' => 0, 'p3amt' => 0,
        ],
        'lastUpdate' => '',
    ];

    $CHANNELS = ['douyin', 'meituan', 'referral', 'offline', 'other'];
    $STORES = ['小河', '小十字', '未来方舟蘑菇城', '未来方舟青少店', '玖福城'];
    $salesMap = [];
    $coachMap = [];

    foreach ($STORES as $s) {
        $STATE['stores'][$s] = [
            'renew' => 0, 'newSign' => 0, 'leads' => 0, 'live' => 0, 'camp' => 0,
            'classHours' => 0, 'renewAmt' => 0, 'newAmt' => 0,
            'channels' => ['douyin' => 0, 'meituan' => 0, 'referral' => 0, 'offline' => 0, 'other' => 0],
        ];
    }

    foreach ($entries as $row) {
        $role = $row['role_type'];
        $store = $row['store'];
        $d = json_decode($row['data_json'], true) ?: [];

        if ($role === '销售') {
            $STATE['funnel']['resources'] += $d['resources'] ?? 0;
            $STATE['funnel']['promise'] += $d['promise'] ?? 0;
            $STATE['funnel']['visit'] += $d['visit'] ?? 0;
            $STATE['funnel']['trial'] += $d['trial'] ?? 0;
            $STATE['funnel']['deal'] += $d['deal'] ?? 0;
            $STATE['new_cnt'] += $d['deal'] ?? 0;
            $STATE['stores'][$store]['newSign'] += $d['deal'] ?? 0;
            $personName = $row['person_name'] ?? '';
            if ($personName !== '') {
                $k = $personName . '-' . $store;
                if (!isset($salesMap[$k])) $salesMap[$k] = ['name' => $personName, 'store' => $store, 'resources' => 0, 'promise' => 0, 'visit' => 0, 'deal' => 0];
                $salesMap[$k]['resources'] += $d['resources'] ?? 0;
                $salesMap[$k]['promise'] += $d['promise'] ?? 0;
                $salesMap[$k]['visit'] += $d['visit'] ?? 0;
                $salesMap[$k]['deal'] += $d['deal'] ?? 0;
            }
        }

        if ($role === '教练') {
            $STATE['stores'][$store]['classHours'] += $d['classHours'] ?? 0;
            $STATE['stores'][$store]['camp'] += $d['campRec'] ?? 0;
            $STATE['camp_cnt'] += $d['campRec'] ?? 0;
            $personName = $row['person_name'] ?? '';
            if ($personName !== '') {
                $k = $personName . '-' . $store;
                if (!isset($coachMap[$k])) $coachMap[$k] = ['name' => $personName, 'store' => $store, 'planHours' => 0, 'classHours' => 0, 'groupToPrivate' => 0];
                $coachMap[$k]['planHours'] += $d['planHours'] ?? 0;
                $coachMap[$k]['classHours'] += $d['classHours'] ?? 0;
                $coachMap[$k]['groupToPrivate'] += $d['groupToPrivate'] ?? 0;
            }
        }

        if ($role === '店长') {
            $STATE['stores'][$store]['renew'] += $d['renew'] ?? 0;
            $STATE['stores'][$store]['newSign'] += $d['newSign'] ?? 0;
            $STATE['stores'][$store]['renewAmt'] += $d['renewAmt'] ?? 0;
            $STATE['stores'][$store]['newAmt'] += $d['newAmt'] ?? 0;
            $STATE['renew_cnt'] += $d['renew'] ?? 0;
            $STATE['new_cnt'] += $d['newSign'] ?? 0;
            $STATE['total_rev'] += ($d['renewAmt'] ?? 0) + ($d['newAmt'] ?? 0);

            foreach (['p0', 'p1', 'p2', 'p3'] as $p) {
                if (!empty($d[$p . 'touch'])) $STATE['renew'][$p . 'touch'] += $d[$p . 'touch'];
                if (!empty($d[$p . 'renew'])) $STATE['renew'][$p . 'renew'] += $d[$p . 'renew'];
                if (!empty($d[$p . 'amt'])) $STATE['renew'][$p . 'amt'] += $d[$p . 'amt'];
            }
        }

        if ($role === '直播运营') {
            $STATE['stores'][$store]['live'] += $d['liveCount'] ?? 0;
            $STATE['stores'][$store]['camp'] += $d['campLive'] ?? 0;
            $STATE['camp_cnt'] += $d['campLive'] ?? 0;
        }
    }

    foreach ($chEntries as $row) {
        $store = $row['store'];
        $channel = $row['channel'];
        $count = (int) $row['count_val'];
        if (in_array($channel, $CHANNELS, true)) {
            $STATE['stores'][$store]['channels'][$channel] += $count;
            $STATE['stores'][$store]['leads'] += $count;
            $STATE['channels'][$channel] += $count;
        }
    }

    $STATE['total_rev'] = round($STATE['total_rev'] / 10000 * 10) / 10;
    $STATE['sales'] = array_values($salesMap);
    $STATE['coaches'] = array_values($coachMap);

    jsonSuccess([
        'state' => $STATE,
        'auth' => $auth,
        'entries_count' => count($entries),
        'channel_entries_count' => count($chEntries),
    ]);
} catch (Throwable $e) {
    error_log('Campaign summary error: ' . $e->getMessage());
    jsonError(500, '获取汇总数据失败：' . $e->getMessage());
}
