<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
handleCORS();

try {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        appJsonError(405, '不支持的请求方法');
    }
    $context = appRequireStaffContext();
    $input = appInputArray();
    $date = appRequireDate($input, 'report_date', '日期');
    $role = appRoleCode(appRequireString($input, 'role_code', '岗位'));
    $storeId = appRequireInt($input, 'store_id', '门店');
    $status = appRequireEnum($input, 'submit_status', ['draft', 'submitted'], '提交状态');
    $remarks = mb_substr(appOptionalString($input, 'remarks'), 0, 255);
    $source = mb_substr(appOptionalString($input, 'source', 'h5'), 0, 16);
    $values = $input['values'] ?? [];
    if (!is_array($values) || $values === []) {
        appJsonError(400, '指标值不能为空');
    }
    if (!workloadAllowedRoleForContext($context, $role)) {
        appJsonError(403, '无权限提交该岗位日报');
    }
    appRequireViewStore($context, $storeId);
    $staffId = (int)($context['staff_id'] ?? 0);
    appRequireOperateStaff($context, $staffId, $storeId);

    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $tpl = workloadTemplate($pdo, $role);
    if (!$tpl) {
        appJsonError(404, '日报模板不存在');
    }
    $metricMap = workloadMetricMap($pdo, $role);
    $normalizedValues = [];
    foreach ($values as $row) {
        if (!is_array($row)) continue;
        $code = trim((string)($row['metric_code'] ?? ''));
        if ($code === '' || !isset($metricMap[$code])) {
            appJsonError(400, '存在不支持的指标：' . $code);
        }
        if ((int)$metricMap[$code]['is_system_calculated'] === 1) {
            appJsonError(400, '系统计算指标不允许手工提交：' . $code);
        }
        $value = $row['value'] ?? 0;
        if (!is_numeric($value)) {
            appJsonError(400, '指标值必须是数字：' . $code);
        }
        $numeric = (float)$value;
        $min = $metricMap[$code]['min_value'];
        $max = $metricMap[$code]['max_value'];
        if ($min !== null && $numeric < (float)$min) appJsonError(400, '指标值不能小于最小值：' . $code);
        if ($max !== null && $numeric > (float)$max) appJsonError(400, '指标值超过最大值：' . $code);
        $normalizedValues[$code] = $numeric;
    }
    foreach ($metricMap as $code => $metric) {
        if ((int)$metric['is_required'] === 1 && !array_key_exists($code, $normalizedValues)) {
            appJsonError(400, '缺少必填指标：' . $metric['metric_name']);
        }
    }

    $pdo->beginTransaction();
    $stmt = $pdo->prepare("SELECT id FROM workload_daily_reports WHERE report_date=? AND store_id=? AND staff_id=? AND role_code=? LIMIT 1");
    $stmt->execute([$date, $storeId, $staffId, $role]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        $reportId = (int)$existing['id'];
        $update = $pdo->prepare("UPDATE workload_daily_reports SET template_id=?, submit_status=?, source=?, remarks=?, submitted_at=IF(?='submitted', NOW(), submitted_at), updated_at=NOW() WHERE id=?");
        $update->execute([(int)$tpl['template']['id'], $status, $source, $remarks, $status, $reportId]);
    } else {
        $insert = $pdo->prepare("INSERT INTO workload_daily_reports (report_date, store_id, staff_id, role_code, template_id, submit_status, source, remarks, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, IF(?='submitted', NOW(), NULL))");
        $insert->execute([$date, $storeId, $staffId, $role, (int)$tpl['template']['id'], $status, $source, $remarks, $status]);
        $reportId = (int)$pdo->lastInsertId();
    }
    $valueStmt = $pdo->prepare("INSERT INTO workload_daily_report_values (report_id, metric_id, numeric_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE numeric_value=VALUES(numeric_value), text_value=NULL, json_value=NULL, updated_at=NOW()");
    foreach ($normalizedValues as $code => $numeric) {
        $valueStmt->execute([$reportId, (int)$metricMap[$code]['id'], $numeric]);
    }
    $pdo->commit();
    appLogEvent('workload.save_report', ['staff_id' => $staffId, 'store_id' => $storeId, 'role' => $role, 'report_id' => $reportId, 'status' => $status]);
    appJsonSuccess(['report_id' => $reportId, 'submit_status' => $status], '保存成功');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appLogEvent('workload.save_report_error', ['error' => $e->getMessage()]);
    appJsonError(500, '保存日报失败');
}
