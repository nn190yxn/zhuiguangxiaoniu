<?php
declare(strict_types=1);

require_once __DIR__ . '/AuthContext.php';

final class PlatformLegacyAuthAdapter
{
    public static function fromLegacy(
        array $staffContext,
        array $user = [],
        array $staff = [],
        array $assignments = [],
        ?callable $permissionResolver = null
    ): PlatformAuthContext {
        if (empty($staffContext['authenticated'])) {
            return PlatformAuthContext::guest();
        }

        $role = self::normalizeRole((string)($staffContext['role'] ?? $staff['role'] ?? $user['role'] ?? 'staff'));
        $storeIds = [$staffContext['store_id'] ?? null, $staff['store_id'] ?? null];
        $positionIds = [$staff['primary_position_id'] ?? null];
        $assignmentIds = [];
        foreach ($assignments as $assignment) {
            if (!is_array($assignment)) {
                continue;
            }
            $assignmentIds[] = $assignment['id'] ?? null;
            $storeIds[] = $assignment['store_id'] ?? null;
            $positionIds[] = $assignment['position_id'] ?? null;
        }

        $permissions = $permissionResolver !== null
            ? (array)$permissionResolver($role, $user, $staff)
            : self::legacyPermissions($role);
        $scopeType = !empty($staffContext['permissions']['can_view_all'])
            ? 'all'
            : ($role === 'manager' ? 'stores' : 'self');

        return new PlatformAuthContext(
            true,
            isset($staffContext['user_id']) ? (int)$staffContext['user_id'] : null,
            isset($staffContext['staff_id']) ? (int)$staffContext['staff_id'] : null,
            $role,
            $storeIds,
            $positionIds,
            $assignmentIds,
            (int)($staff['session_version'] ?? $staffContext['session_version'] ?? 0),
            $permissions,
            $scopeType
        );
    }

    public static function current(array $assignments = []): PlatformAuthContext
    {
        if (!function_exists('appGetCurrentStaffContext')) {
            throw new LogicException('Legacy staff context must be loaded before creating PlatformAuthContext');
        }
        $staffContext = appGetCurrentStaffContext();
        if (empty($staffContext['authenticated'])) {
            return PlatformAuthContext::guest();
        }
        $userId = (int)($staffContext['user_id'] ?? 0);
        $user = function_exists('getJwtCurrentUser') ? (getJwtCurrentUser() ?: []) : [];
        $staff = function_exists('getStaffByUserId') && $userId > 0 ? (getStaffByUserId($userId) ?: []) : [];

        return self::fromLegacy(
            $staffContext,
            $user,
            $staff,
            $assignments,
            static function (string $role, array $legacyUser, array $legacyStaff): array {
                if (function_exists('adminPermissionsForRole')) {
                    return adminPermissionsForRole($role);
                }
                return [];
            }
        );
    }

    private static function legacyPermissions(string $role): array
    {
        return function_exists('adminPermissionsForRole') ? adminPermissionsForRole($role) : [];
    }

    private static function normalizeRole(string $role): string
    {
        if (function_exists('appRoleCode')) {
            return appRoleCode($role);
        }
        $role = strtolower(trim($role));
        return match ($role) {
            'consultant', 'sale', '销售', '实习销售' => 'sales',
            '教练', '实习教练' => 'coach',
            'store_manager', 'shop_manager', '店长' => 'manager',
            'operations', 'operator', 'ops', '运营', '总部运营' => 'operation',
            'financial', '财务' => 'finance',
            'administrator' => 'admin',
            '总经理' => 'ceo',
            default => $role === '' ? 'staff' : $role,
        };
    }
}
