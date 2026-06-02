<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
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
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = getRequestInput();
$action = (string) ($input['action'] ?? '');

function campaignGetWpRole(PDO $pdo, int $userId): string {
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

function campaignNormalizeRoleTokens(array $values): array {
    $tokens = [];
    foreach ($values as $value) {
        $value = strtolower(trim((string) $value));
        if ($value !== '') {
            $tokens[] = $value;
        }
    }
    return array_values(array_unique($tokens));
}

function campaignHasAnyRoleToken(array $tokens, array $candidates): bool {
    foreach ($candidates as $candidate) {
        if (in_array(strtolower($candidate), $tokens, true)) {
            return true;
        }
    }
    return false;
}

function campaignGetManagedStores(array $staff, bool $isStoreManager): array {
    if (!$isStoreManager) {
        return [];
    }

    $stores = [];
    $storeId = (int) ($staff['store_id'] ?? 0);
    if ($storeId > 0) {
        $storeMap = [
            1 => '未来方舟蘑菇城',
            2 => '小河',
            3 => '小十字',
            4 => '未来方舟青少店',
            5 => '玖福城',
        ];
        if (isset($storeMap[$storeId])) {
            $stores[] = $storeMap[$storeId];
        }
    }

    $name = trim((string) ($staff['name'] ?? ''));
    $phone = trim((string) ($staff['phone'] ?? ''));
    if ($name === '盛明菲' || $phone === '18798742857') {
        $stores[] = '小河';
        $stores[] = '小十字';
    }

    return array_values(array_unique(array_filter($stores)));
}

function campaignBuildAuthContext(PDO $pdo): array {
    $userId = getCurrentUserId();
    $wpUser = getCurrentUser();
    $staff = getStaffByUserId($userId);
    $wpRole = campaignGetWpRole($pdo, $userId);
    $staffRole = strtolower(trim((string) ($staff['role'] ?? '')));
    $displayName = trim((string) ($staff['name'] ?? ($wpUser['nickname'] ?? ($wpUser['username'] ?? ''))));
    $tokens = campaignNormalizeRoleTokens([$wpRole, $staffRole, normalizeStaffRoleCode($staffRole)]);

    $isWhitelist = in_array($displayName, ['何梓辛', '周颖', '陈琪琪', '姚修宁'], true);
    $isAdminLike = campaignHasAnyRoleToken($tokens, ['admin', 'administrator']);
    $isCeo = campaignHasAnyRoleToken($tokens, ['ceo', '总经理']);
    $isOps = campaignHasAnyRoleToken($tokens, ['operation', 'operations', 'operator', 'ops', '运营', '总部运营']);
    $isFinance = campaignHasAnyRoleToken($tokens, ['finance', 'financial', '财务']);
    $isStoreManager = campaignHasAnyRoleToken($tokens, ['store_manager', 'shop_manager', 'manager', '店长']) && !$isOps && !$isFinance && !$isAdminLike && !$isCeo;
    $isSales = campaignHasAnyRoleToken($tokens, ['sales', 'consultant', '销售']);
    $isCoach = campaignHasAnyRoleToken($tokens, ['coach', '教练']);
    $isLiveOps = campaignHasAnyRoleToken($tokens, ['直播运营', 'live_ops', 'live_operation']);
    $isContentOps = campaignHasAnyRoleToken($tokens, ['内容运营', 'content_ops', 'content_operation']);

    $managedStores = campaignGetManagedStores($staff ?: [], $isStoreManager);
    $canEditAllData = $isWhitelist || $isAdminLike || $isCeo || $isOps || $isFinance;
    $canEditStoreAllData = $canEditAllData || $isStoreManager;
    $canEditRevenue = $canEditStoreAllData;

    $allowedRoles = [];
    if ($canEditStoreAllData) {
        $allowedRoles = ['销售', '教练', '店长', '直播运营', '内容运营'];
    } else {
        if ($isSales) {
            $allowedRoles[] = '销售';
        }
        if ($isCoach) {
            $allowedRoles[] = '教练';
        }
        if ($isStoreManager) {
            $allowedRoles[] = '店长';
        }
        if ($isLiveOps) {
            $allowedRoles[] = '直播运营';
        }
        if ($isContentOps) {
            $allowedRoles[] = '内容运营';
        }
    }

    return [
        'display_name' => $displayName,
        'wp_role' => $wpRole,
        'staff_role' => $staffRole,
        'allowed_roles' => array_values(array_unique($allowedRoles)),
        'managed_stores' => $managedStores,
        'can_edit_revenue' => $canEditRevenue,
        'can_edit_all_data' => $canEditAllData,
        'can_edit_store_all_data' => $canEditStoreAllData,
        'can_manage_targets' => $canEditAllData,
    ];
}

function campaignCanEditStore(array $auth, string $store): bool {
    if ($auth['can_edit_all_data']) {
        return true;
    }
    if (!($auth['can_edit_store_all_data'] ?? false)) {
        return true;
    }
    $managedStores = $auth['managed_stores'] ?? [];
    if (!is_array($managedStores) || $managedStores === []) {
        // Avoid blocking valid role-based staff when store scope is temporarily missing.
        return true;
    }
    return in_array($store, $managedStores, true);
}

function campaignFilterEntryPayload(array $data, array $auth, string $targetRole): array {
    if ($targetRole === '销售') {
        unset($data['renewAmt'], $data['newAmt']);
    }
    if (!$auth['can_edit_revenue']) {
        unset($data['renewAmt'], $data['newAmt']);
    }
    return $data;
}

try {
    $pdo = getDB();
    $auth = campaignBuildAuthContext($pdo);

    if ($action === 'save_entry') {
        $date = trim((string) ($input['date'] ?? ''));
        $store = trim((string) ($input['store'] ?? ''));
        $role = trim((string) ($input['role'] ?? ''));
        $name = trim((string) ($input['name'] ?? ''));
        $data = $input['data'] ?? [];

        if ($date === '' || $store === '' || $role === '') {
            jsonError(400, '缺少必填字段：date, store, role');
        }

        if (!in_array($role, ['销售', '教练', '店长', '直播运营', '内容运营'], true)) {
            jsonError(400, '不支持的录入角色');
        }

        if (!in_array($role, $auth['allowed_roles'], true)) {
            jsonError(403, '你没有该录入入口权限');
        }

        if (($auth['can_edit_store_all_data'] ?? false) && !campaignCanEditStore($auth, $store)) {
            jsonError(403, '你没有该门店的数据录入权限');
        }

        $data = campaignFilterEntryPayload(is_array($data) ? $data : [], $auth, $role);

        // Upsert: find existing record for same date+store+role+name
        $stmt = $pdo->prepare(
            "SELECT id FROM campaign_daily_entries WHERE entry_date=? AND store=? AND role_type=? AND person_name=?"
        );
        $stmt->execute([$date, $store, $role, $name]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $stmt = $pdo->prepare(
                "UPDATE campaign_daily_entries SET data_json=?, updated_at=NOW() WHERE id=?"
            );
            $stmt->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $existing['id']]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO campaign_daily_entries (entry_date, store, role_type, person_name, data_json) VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([$date, $store, $role, $name ?: null, json_encode($data, JSON_UNESCAPED_UNICODE)]);
        }

        jsonSuccess(['id' => $existing['id'] ?? $pdo->lastInsertId()], '保存成功');
    }

    if ($action === 'save_channel') {
        $date = trim((string) ($input['date'] ?? ''));
        $store = trim((string) ($input['store'] ?? ''));
        $channels = $input['channels'] ?? [];

        if ($date === '' || $store === '' || !is_array($channels)) {
            jsonError(400, '缺少必填字段：date, store, channels');
        }

        if (!($auth['can_edit_store_all_data'] ?? false)) {
            jsonError(403, '你没有全量渠道录入权限');
        }

        if (!campaignCanEditStore($auth, $store)) {
            jsonError(403, '你没有该门店的数据录入权限');
        }

        foreach ($channels as $channel => $count) {
            if (!in_array($channel, ['douyin', 'meituan', 'referral', 'offline', 'other'], true)) {
                continue;
            }
            $count = (int) ($count ?? 0);

            $stmt = $pdo->prepare(
                "SELECT id FROM campaign_channel_entries WHERE entry_date=? AND store=? AND channel=?"
            );
            $stmt->execute([$date, $store, $channel]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                $stmt = $pdo->prepare(
                    "UPDATE campaign_channel_entries SET count_val=?, updated_at=NOW() WHERE id=?"
                );
                $stmt->execute([$count, $existing['id']]);
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO campaign_channel_entries (entry_date, store, channel, count_val) VALUES (?, ?, ?, ?)"
                );
                $stmt->execute([$date, $store, $channel, $count]);
            }
        }

        jsonSuccess([], '渠道线索已保存');
    }

    jsonError(400, '未知操作：' . $action);
} catch (Throwable $e) {
    error_log('Campaign save error: ' . $e->getMessage());
    jsonError(500, '保存失败：' . $e->getMessage());
}
