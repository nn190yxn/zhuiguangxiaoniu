<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadReportStateService.php';
require_once __DIR__ . '/services/WorkloadSourcePolicyService.php';
require_once __DIR__ . '/services/WorkloadMetricVersionService.php';
require_once __DIR__ . '/services/WorkloadRoleRuleVersionService.php';
require_once __DIR__ . '/services/WorkloadAuditTaskService.php';
require_once __DIR__ . '/services/WorkloadAnalyticsCacheService.php';
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
    $source = appOptionalString($input, 'source', 'h5');
    $values = $input['values'] ?? [];
    if (!is_array($values) || $values === []) {
        appJsonError(400, '指标值不能为空');
    }
    if (!workloadAllowedRoleForContext($context, $role)) {
        appJsonError(403, '无权限提交该岗位日报');
    }
    if ($role !== (string)($context['role'] ?? '')) {
        appJsonError(403, '只能提交本人岗位日报');
    }
    if (!appCanViewStore($context, $storeId)) {
        $myStoreId = (int)($context['store_id'] ?? 0);
        if ($myStoreId > 0 || !appCanEditOwn($context)) {
            appJsonError(403, '只能提交本人所属门店日报');
        }
    }
    $staffId = (int)($context['staff_id'] ?? 0);
    appRequireOperateStaff($context, $staffId, $storeId);

    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    workloadEnsureAuditSchema($pdo);
    workloadEnsureAuditRules($pdo);
    $sourcePolicy = (new WorkloadSourcePolicyService($pdo))->policy($source);
    $source = $sourcePolicy['source_code'];
    $metricVersionService = new WorkloadMetricVersionService($pdo);
    $metricVersion = $metricVersionService->current();
    $roleRuleService = new WorkloadRoleRuleVersionService($pdo);
    $roleRuleVersion = $roleRuleService->activeForDate($role, $date);
    
    $tpl = workloadTemplate($pdo, $role, $roleRuleVersion['template_id']);
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
        $normalizedValues[$code] = $numeric;
    }
    $storeMetricSummary = $role === 'manager' ? workloadManagerStoreMetricSummary($pdo, $date, $storeId) : [];
    if ($storeMetricSummary !== []) {
        $normalizedValues = workloadApplyManagerStoreMetricSummary($normalizedValues, $storeMetricSummary, $roleRuleVersion['metric_rules']);
    }
    $roleRuleService->validateValues($normalizedValues, $roleRuleVersion, false);

    $stateService = new WorkloadReportStateService($pdo);
    $pdo->beginTransaction();
    $stateService->assertEmployeeWritable($date);
    $stmt = $pdo->prepare("SELECT id, submit_status, role_code FROM workload_daily_reports WHERE report_date=? AND store_id=? AND staff_id=? ORDER BY id ASC FOR UPDATE");
    $stmt->execute([$date, $storeId, $staffId]);
    $existing = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
        if (appRoleCode((string)($candidate['role_code'] ?? '')) === $role) {
            $existing = $candidate;
            break;
        }
    }
    if ($existing && ($existing['submit_status'] ?? '') === 'submitted') {
        throw new WorkloadReportStateException('日报已提交，请通过管理更正流程处理', 409);
    }

    if ($existing) {
        $reportId = (int)$existing['id'];
        $update = $pdo->prepare("UPDATE workload_daily_reports SET template_id=?, metric_version_id=?, rule_version_id=?, submit_status=?, source=?, remarks=?, submitted_at=IF(?='submitted', NOW(), submitted_at), updated_at=NOW() WHERE id=?");
        $update->execute([(int)$tpl['template']['id'], $metricVersion['id'], $roleRuleVersion['id'], $status, $source, $remarks, $status, $reportId]);
    } else {
        $insert = $pdo->prepare("INSERT INTO workload_daily_reports (report_date, store_id, staff_id, role_code, template_id, metric_version_id, rule_version_id, submit_status, source, remarks, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(?='submitted', NOW(), NULL))");
        $insert->execute([$date, $storeId, $staffId, $role, (int)$tpl['template']['id'], $metricVersion['id'], $roleRuleVersion['id'], $status, $source, $remarks, $status]);
        $reportId = (int)$pdo->lastInsertId();
    }
    $valueStmt = $pdo->prepare("INSERT INTO workload_daily_report_values (report_id, metric_id, numeric_value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE numeric_value=VALUES(numeric_value), text_value=NULL, json_value=NULL, updated_at=NOW()");
    foreach ($normalizedValues as $code => $numeric) {
        $valueStmt->execute([$reportId, (int)$metricMap[$code]['id'], $numeric]);
    }

    if ($status === 'submitted') {
        $rules = $roleRuleVersion['metric_rules'];

        $evidenceCountMap = [];
        if ($rules) {
            $evidenceStmt = $pdo->prepare("SELECT metric_code, COUNT(*) AS evidence_count FROM workload_evidences WHERE report_id = ? AND deleted_at IS NULL GROUP BY metric_code");
            $evidenceStmt->execute([$reportId]);
            foreach ($evidenceStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $evidenceCountMap[(string)$row['metric_code']] = (int)($row['evidence_count'] ?? 0);
            }
            $evidenceCountMap = workloadApplyManagerStoreEvidenceCounts($evidenceCountMap, $storeMetricSummary, 'manager_store_poi_checkin');
        }

        $roleRuleService->validateValues($normalizedValues, $roleRuleVersion, true, $evidenceCountMap);

        (new WorkloadAuditTaskService($pdo))->replaceForSubmission(
            $reportId,
            $staffId,
            $storeId,
            $role,
            $normalizedValues,
            $rules
        );
    }

    $obligation = $stateService->synchronizeReport($reportId);
    $stateService->assertEmployeeWritable($date);
    $pdo->commit();
    (new WorkloadAnalyticsCacheService())->invalidate([
        'date' => $date,
        'store_id' => $storeId,
        'staff_id' => $staffId,
        'role_code' => $role,
        'metric_codes' => array_keys($normalizedValues),
        'source' => $source,
    ]);
    appLogEvent('workload.save_report', array_merge(['staff_id' => $staffId, 'store_id' => $storeId, 'role' => $role, 'report_id' => $reportId, 'status' => $status, 'rule_version' => $roleRuleVersion['version_code'], 'rule_version_id' => $roleRuleVersion['id']], $metricVersionService->auditContext()));
    appJsonSuccess([
        'report_id' => $reportId,
        'submit_status' => $status,
        'obligation_id' => $obligation['obligation_id'],
        'completion_status' => $obligation['completion_status'],
        'deadline_at' => $obligation['deadline_at'],
        'metric_version' => $metricVersion['version_code'],
        'rule_version' => $roleRuleVersion['version_code'],
    ], '保存成功');
} catch (WorkloadReportStateException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appJsonError($e->statusCode(), $e->getMessage());
} catch (WorkloadSourcePolicyException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appJsonError($e->statusCode(), $e->getMessage());
} catch (WorkloadRoleRuleVersionException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appJsonError($e->statusCode(), $e->getMessage());
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    appLogEvent('workload.save_report_error', ['error' => $e->getMessage()]);
    appJsonError(500, '保存日报失败');
}
