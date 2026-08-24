<?php
/**
 * Pure access policy for training modules.
 *
 * This file deliberately has no database, HTTP or JWT dependencies so the
 * role matrix can be reviewed and tested independently.
 */
final class TrainingAccessPolicy
{
    public static function normalizeStaffRole($role)
    {
        $role = strtolower(trim((string)$role));
        if ($role === 'consultant' || $role === 'newbie') {
            return 'sales';
        }
        return $role;
    }

    public static function moduleRoleForStaff($role)
    {
        $role = self::normalizeStaffRole($role);
        return $role === 'sales' ? 'consultant' : $role;
    }

    public static function isManagementJwtRole($role)
    {
        return in_array(strtolower(trim((string)$role)), ['admin', 'manager'], true);
    }

    public static function canAccessModule(array $context, $moduleRole)
    {
        if (empty($context['authenticated'])) {
            return false;
        }
        if (!empty($context['is_management'])) {
            return true;
        }

        $requiredRole = strtolower(trim((string)$moduleRole));
        if ($requiredRole === '') {
            return true;
        }

        return self::moduleRoleForStaff($context['staff_role'] ?? '') === $requiredRole;
    }
}
