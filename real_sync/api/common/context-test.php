<?php
declare(strict_types=1);

require_once __DIR__ . '/context.php';

handleCORS();

$context = appRequireStaffContext();
appLogEvent('common.context_test', ['staff_id' => $context['staff_id'] ?? null, 'role' => $context['role'] ?? '']);
appJsonSuccess([
    'context' => $context,
    'permission_checks' => [
        'can_view_own_store' => appCanViewStore($context, (int)($context['store_id'] ?? 0)),
        'can_edit_own' => appCanEditOwn($context),
        'can_edit_own_store' => appCanEditStore($context, (int)($context['store_id'] ?? 0)),
        'can_operate_self' => appCanOperateStaff($context, (int)($context['staff_id'] ?? 0), (int)($context['store_id'] ?? 0)),
        'can_view_all' => appCanViewAll($context),
        'can_edit_all' => appCanEditAll($context),
    ],
]);
