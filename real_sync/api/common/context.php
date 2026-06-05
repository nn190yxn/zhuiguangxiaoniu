<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/helpers.php';

function appRoleCode(string $role): string {
    $role = strtolower(trim($role));
    $map = [
        'consultant' => 'sales',
        'sale' => 'sales',
        'sales' => 'sales',
        '销售' => 'sales',
        '实习销售' => 'sales',
        'coach' => 'coach',
        '教练' => 'coach',
        '实习教练' => 'coach',
        'manager' => 'manager',
        'store_manager' => 'manager',
        'shop_manager' => 'manager',
        '店长' => 'manager',
        'operation' => 'operation',
        'operations' => 'operation',
        'operator' => 'operation',
        'ops' => 'operation',
        '运营' => 'operation',
        '总部运营' => 'operation',
        'finance' => 'finance',
        'financial' => 'finance',
        '财务' => 'finance',
        'admin' => 'admin',
        'administrator' => 'admin',
        'ceo' => 'ceo',
        '总经理' => 'ceo',
        'staff' => 'staff',
    ];
    if (function_exists('normalizeStaffRoleCode')) {
        $normalized = strtolower((string) normalizeStaffRoleCode($role));
        if (isset($map[$normalized])) {
            return $map[$normalized];
        }
    }
    return $map[$role] ?? $role;
}

function appRoleLabel(string $role): string {
    $labels = [
        'sales' => '销售',
        'coach' => '教练',
        'manager' => '店长',
        'operation' => '总部运营',
        'finance' => '财务',
        'admin' => '管理员',
        'ceo' => '总经理',
        'staff' => '员工',
    ];
    return $labels[appRoleCode($role)] ?? $role;
}

function appRoleTokensFromUser(array $user = null, array $staff = null): array {
    $rawRole = strtolower(trim((string)($user['role'] ?? '')));
    $staffRole = strtolower(trim((string)($staff['role'] ?? '')));

    return array_values(array_unique(array_filter([
        $rawRole,
        appRoleCode($rawRole),
        $staffRole,
        appRoleCode($staffRole),
    ])));
}

function appIsSuperAdmin(array $user = null, array $staff = null): bool {
    return in_array('admin', appRoleTokensFromUser($user, $staff), true)
        || in_array('ceo', appRoleTokensFromUser($user, $staff), true);
}

function appIsHeadquarter(array $user = null, array $staff = null): bool {
    $tokens = appRoleTokensFromUser($user, $staff);
    foreach (['admin', 'ceo', 'ops', 'operation', 'operations', 'operator', 'finance'] as $allowed) {
        if (in_array($allowed, $tokens, true)) {
            return true;
        }
    }
    return false;
}

function appCanAccessHeadquarter(array $user = null, array $staff = null): bool {
    if (!$user && !$staff) {
        return false;
    }
    return appIsHeadquarter($user, $staff);
}

function appCanAccessPerformance(array $user = null, array $staff = null): bool {
    return appCanAccessHeadquarter($user, $staff)
        || in_array('manager', appRoleTokensFromUser($user, $staff), true);
}

function appCanAccessWorkload(array $user = null, array $staff = null): bool {
    return appCanAccessHeadquarter($user, $staff)
        || in_array('manager', appRoleTokensFromUser($user, $staff), true);
}

function appStoreNameById(int $storeId): string {
    $map = [
        1 => '未来方舟蘑菇城',
        2 => '小河',
        3 => '小十字',
        4 => '未来方舟青少店',
        5 => '玖福城',
    ];
    return $map[$storeId] ?? '';
}

function appCanViewAll(array $context): bool {
    return !empty($context['permissions']['can_view_all']);
}

function appCanEditAll(array $context): bool {
    return !empty($context['permissions']['can_edit_all']);
}

function appCanViewStore(array $context, int $storeId): bool {
    if (appCanViewAll($context)) {
        return true;
    }
    if ($storeId <= 0) {
        return false;
    }
    return (int)($context['store_id'] ?? 0) === $storeId;
}

function appCanEditStore(array $context, int $storeId): bool {
    if (appCanEditAll($context)) {
        return true;
    }
    if (!in_array((string)($context['role'] ?? ''), ['manager'], true)) {
        return false;
    }
    return appCanViewStore($context, $storeId);
}

function appCanEditOwn(array $context): bool {
    return !empty($context['permissions']['can_edit_own']);
}

function appCanOperateStaff(array $context, int $targetStaffId, ?int $targetStoreId = null): bool {
    if (appCanEditAll($context)) {
        return true;
    }
    if ($targetStaffId > 0 && (int)($context['staff_id'] ?? 0) === $targetStaffId && appCanEditOwn($context)) {
        return true;
    }
    if ((string)($context['role'] ?? '') === 'manager' && $targetStoreId !== null) {
        return appCanViewStore($context, $targetStoreId);
    }
    return false;
}

function appRequireViewStore(array $context, int $storeId): void {
    if (!appCanViewStore($context, $storeId)) {
        appLogEvent('permission.view_store_denied', ['staff_id' => $context['staff_id'] ?? null, 'store_id' => $storeId]);
        appJsonError(403, '无权限查看该门店数据');
    }
}

function appRequireEditStore(array $context, int $storeId): void {
    if (!appCanEditStore($context, $storeId)) {
        appLogEvent('permission.edit_store_denied', ['staff_id' => $context['staff_id'] ?? null, 'store_id' => $storeId]);
        appJsonError(403, '无权限编辑该门店数据');
    }
}

function appRequireOperateStaff(array $context, int $targetStaffId, ?int $targetStoreId = null): void {
    if (!appCanOperateStaff($context, $targetStaffId, $targetStoreId)) {
        appLogEvent('permission.operate_staff_denied', ['staff_id' => $context['staff_id'] ?? null, 'target_staff_id' => $targetStaffId, 'target_store_id' => $targetStoreId]);
        appJsonError(403, '无权限操作该员工数据');
    }
}

function appGetCurrentStaffContext(): array {
    $userId = (int) getCurrentUserId();
    if ($userId <= 0) {
        return ['authenticated' => false];
    }

    $wpUser = getCurrentUser() ?: [];
    $staff = getStaffByUserId($userId) ?: [];
    $rawRole = (string) ($staff['role'] ?? ($wpUser['role'] ?? 'staff'));
    $roleCode = appRoleCode($rawRole);
    $storeId = (int) ($staff['store_id'] ?? 0);
    $storeName = appStoreNameById($storeId);
    $phone = trim((string) ($staff['phone'] ?? ($wpUser['username'] ?? '')));
    $name = trim((string) ($staff['name'] ?? ($wpUser['nickname'] ?? ($wpUser['username'] ?? ''))));

    $isAdmin = appIsSuperAdmin(['role' => $roleCode], $staff);
    $isManager = $roleCode === 'manager';
    $isHq = appIsHeadquarter(['role' => $roleCode], $staff);

    return [
        'authenticated' => true,
        'user_id' => $userId,
        'staff_id' => isset($staff['id']) ? (int) $staff['id'] : null,
        'name' => $name,
        'phone' => $phone,
        'role' => $roleCode,
        'role_name' => appRoleLabel($roleCode),
        'raw_role' => $rawRole,
        'store_id' => $storeId ?: null,
        'store_name' => $storeName,
        'is_admin' => $isAdmin,
        'is_manager' => $isManager,
        'is_hq' => $isHq,
        'permissions' => [
            'can_view_all' => $isHq,
            'can_edit_all' => $isHq,
            'can_view_store' => $isHq || $isManager || $storeId > 0,
            'can_edit_own' => in_array($roleCode, ['sales', 'coach', 'manager', 'operation', 'finance', 'admin', 'ceo'], true),
            'can_view_hq' => $isHq,
        ],
    ];
}

function appRequireStaffContext(): array {
    $context = appGetCurrentStaffContext();
    if (empty($context['authenticated'])) {
        appLogEvent('auth.required_failed');
        appJsonError(401, '请先登录');
    }
    return $context;
}
