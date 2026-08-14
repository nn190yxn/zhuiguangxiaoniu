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
    $date = appRequireDate(['date' => appOptionalString($_GET, 'date', date('Y-m-d'))], 'date', '日期');
    $ruleVersion = (new WorkloadRoleRuleVersionService($pdo))->activeForDate($role, $date);
    $tpl = workloadTemplate($pdo, $role, $ruleVersion['template_id']);
    if (!$tpl) {
        appJsonError(404, '日报模板不存在');
    }
    appLogEvent('workload.template', ['staff_id' => $context['staff_id'] ?? null, 'role' => $role]);
    appJsonSuccess([
        'template_code' => $tpl['template']['template_code'],
        'template_name' => $tpl['template']['template_name'],
        'role' => $role,
        'rule_version' => $ruleVersion['version_code'],
        'description' => $ruleVersion['description'],
        'minimum_positive_metrics' => $ruleVersion['minimum_positive_metrics'],
        'items' => array_values(array_filter(array_map(static function(array $item) use ($ruleVersion): ?array {
        $rule = $ruleVersion['metric_rules'][$item['metric_code']] ?? null;
        if (!$rule) return null;
        return [
            'metric_code' => $item['metric_code'],
            'metric_name' => $rule['metric_name'],
            'category' => $item['metric_category'],
            'unit' => $rule['unit'],
             'value_type' => $rule['value_type'],
              'required' => $rule['is_required'],
              'allow_zero' => $rule['allow_zero'],
             'editable' => (bool)$item['is_editable'],
             'default_value' => $item['default_value'],
             'min_value' => $rule['min_value'],
             'max_value' => $rule['max_value'],
             'need_evidence' => $rule['need_evidence'],
             'min_evidence_count' => $rule['min_evidence_count'],
             'max_evidence_count' => $rule['max_evidence_count'],
              'audit_mode' => $rule['audit_mode'],
              'input_hint' => $item['metric_code'] === 'sales_deal_amount'
                  ? '填写当天全部成交总金额。金额大于 0 元计 1 点，满 4000 元计 2 点；提交时上传成交系统截图。'
                  : '',
          ];
         }, $tpl['items']))),
    ]);
} catch (Throwable $e) {
    appLogEvent('workload.template_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取日报模板失败');
}
