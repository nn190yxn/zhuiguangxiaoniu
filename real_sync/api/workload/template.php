<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
handleCORS();

try {
    $context = appRequireStaffContext();
    $role = appRoleCode(appOptionalString($_GET, 'role', (string)($context['role'] ?? '')));
    if (!workloadAllowedRoleForContext($context, $role)) {
        appJsonError(403, '无权限查看该岗位模板');
    }
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $tpl = workloadTemplate($pdo, $role);
    if (!$tpl) {
        appJsonError(404, '日报模板不存在');
    }
    appLogEvent('workload.template', ['staff_id' => $context['staff_id'] ?? null, 'role' => $role]);
    appJsonSuccess([
        'template_code' => $tpl['template']['template_code'],
        'template_name' => $tpl['template']['template_name'],
        'role' => $role,
        'items' => array_map(static function(array $item): array {
        return [
            'metric_code' => $item['metric_code'],
            'metric_name' => $item['metric_name'],
            'category' => $item['metric_category'],
            'unit' => $item['unit'],
             'value_type' => $item['value_type'],
             'required' => (bool)$item['is_required'],
             'editable' => (bool)$item['is_editable'],
             'default_value' => $item['default_value'],
             'need_evidence' => (int)($item['need_evidence'] ?? 0),
             'min_evidence_count' => workloadEvidenceMinLimit($item),
             'max_evidence_count' => workloadEvidenceMaxLimit($item),
         ];
         }, $tpl['items']),
    ]);
} catch (Throwable $e) {
    appLogEvent('workload.template_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取日报模板失败');
}
