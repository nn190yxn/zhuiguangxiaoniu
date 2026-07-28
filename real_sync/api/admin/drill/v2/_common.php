<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/common.php';
require_once dirname(__DIR__, 3) . '/drill/v2/_common.php';

function drillAdminV2Bootstrap(string $permission, array $allowedMethods = ['GET', 'POST']): array
{
    $context = drillV2Bootstrap($allowedMethods);
    $user = getJwtCurrentUser() ?: [];
    $staff = getStaffByUserId((int) $context['user_id']) ?: [];
    if (!adminHasPermission($permission, $user, $staff)) {
        appLogEvent('drill.v2.admin_permission_denied', [
            'permission' => $permission,
            'staff_id' => $context['staff_id'] ?? null,
        ]);
        drillV2Error(403, '你没有权限访问该演练管理模块', [], 403);
    }
    return [$context, $user, $staff];
}
