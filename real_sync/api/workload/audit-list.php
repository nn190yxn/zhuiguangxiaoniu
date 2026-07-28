<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadEffectiveValueService.php';
require_once __DIR__ . '/services/WorkloadPermissionScopeService.php';
handleCORS();

try {
    $context = appRequireStaffContext();
    
    $pdo = workloadDb();
    workloadEnsureAuditSchema($pdo);
    $scope = (new WorkloadPermissionScopeService($pdo))->resolve($context);
    if ($scope['scope_type'] === 'staff') {
        appJsonError(403, '无权限查看审核列表');
    }

    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = min(50, max(10, (int)($_GET['page_size'] ?? 20)));
    $offset = ($page - 1) * $pageSize;
    
    $traceStatusExpression = "CASE WHEN t.audit_status = 'superseded' THEN COALESCE(supersede_log.before_status, t.audit_status) ELSE t.audit_status END";
    $where = "1=1";
    $params = [];

    if ($scope['scope_type'] === 'stores') {
        $storeIds = array_values(array_map('intval', $scope['store_ids']));
        $where .= ' AND t.store_id IN (' . implode(',', array_fill(0, count($storeIds), '?')) . ')';
        array_push($params, ...$storeIds);
    }
    
    if (isset($_GET['store_id']) && $_GET['store_id'] > 0) {
        $where .= " AND t.store_id = ?";
        $params[] = (int)$_GET['store_id'];
    }
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $status = (string) $_GET['status'];
        $allowedStatuses = ['pending', 'approved', 'rejected', 'needs_resubmit', 'superseded'];
        if (!in_array($status, $allowedStatuses, true)) {
            appJsonError(400, '无效审核状态');
        }
        $where .= $status === 'superseded'
            ? " AND t.audit_status = ?"
            : " AND $traceStatusExpression = ?";
        $params[] = $status;
    } elseif ((string) ($_GET['include_history'] ?? '') !== '1') {
        $where .= " AND t.superseded_at IS NULL AND t.audit_status <> 'superseded'";
    }
    
    $valueExpressions = WorkloadEffectiveValueService::sqlExpressions(
        't.submitted_value',
        "COALESCE(version_rules.audit_mode, rules.audit_mode, 'full')",
        $traceStatusExpression,
        't.id'
    );
    $fromSql = " FROM workload_audit_tasks t
        JOIN workload_daily_reports r ON r.id = t.report_id
        LEFT JOIN staffs s ON s.id = t.staff_id
        LEFT JOIN stores st ON st.id = t.store_id
        LEFT JOIN metric_definitions m ON m.metric_code = t.metric_code AND m.role_code = t.role_code
        LEFT JOIN workload_role_metric_rules version_rules ON version_rules.rule_version_id = r.rule_version_id AND version_rules.metric_code = t.metric_code
        LEFT JOIN workload_metric_rules rules ON rules.role_code = t.role_code AND rules.metric_code = t.metric_code AND rules.enabled = 1
        LEFT JOIN workload_audit_logs supersede_log ON supersede_log.id = (
            SELECT latest_supersede.id
            FROM workload_audit_logs latest_supersede
            WHERE latest_supersede.task_id = t.id
              AND latest_supersede.after_status = 'superseded'
            ORDER BY latest_supersede.id DESC
            LIMIT 1
        )
        LEFT JOIN (
            SELECT report_id, metric_code, GROUP_CONCAT(file_url ORDER BY created_at ASC SEPARATOR ',') AS evidence_urls
            FROM workload_evidences WHERE deleted_at IS NULL
            GROUP BY report_id, metric_code
        ) ev ON ev.report_id = t.report_id AND ev.metric_code = t.metric_code";
    $fromSql .= "
        LEFT JOIN (
            SELECT source_report.report_date, source_report.store_id, 'manager_store_poi_checkin' AS metric_code,
                GROUP_CONCAT(source_evidence.file_url ORDER BY source_evidence.created_at ASC SEPARATOR ',') AS evidence_urls
            FROM workload_evidences source_evidence
            JOIN workload_daily_reports source_report ON source_report.id = source_evidence.report_id
            WHERE source_report.role_code IN ('coach', 'sales')
              AND source_evidence.metric_code IN ('coach_store_poi_checkin', 'sales_store_poi_checkin')
              AND source_evidence.deleted_at IS NULL
            GROUP BY source_report.report_date, source_report.store_id
        ) store_ev ON store_ev.report_date = r.report_date
            AND store_ev.store_id = t.store_id
            AND store_ev.metric_code = t.metric_code
            AND t.role_code = 'manager'";
    $countStmt = $pdo->prepare("SELECT COUNT(DISTINCT t.id)$fromSql WHERE $where");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT t.id, t.report_id, t.staff_id, t.store_id, t.role_code, t.metric_code, t.task_version, t.previous_task_id, t.submitted_value, t.audit_status, t.audit_comment, t.auditor_staff_id, t.audited_at, t.superseded_at, t.created_at,
        $traceStatusExpression AS trace_status,
        {$valueExpressions['raw_value']},
        {$valueExpressions['pending_value']},
        {$valueExpressions['effective_value']},
        {$valueExpressions['rejected_value']},
        s.name AS staff_name, st.name AS store_name, m.metric_name,
        CONCAT_WS(',', ev.evidence_urls, store_ev.evidence_urls) AS evidence_urls
        $fromSql
        WHERE $where
        ORDER BY t.created_at DESC
        LIMIT ?, ?");
        
    $stmt->execute([...$params, $offset, $pageSize]);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($list as &$row) {
        $urls = [];
        if (!empty($row['evidence_urls'])) {
            $urls = array_map(static function ($url) {
                return workloadPublicUrl(trim($url));
            }, explode(',', (string)$row['evidence_urls']));
        }
        $row['evidence_urls'] = array_values(array_filter($urls, static fn(string $url): bool => $url !== ''));
    }
    unset($row);

    $logsByTask = [];
    $taskIds = array_values(array_map(static fn(array $row): int => (int) $row['id'], $list));
    if ($taskIds !== []) {
        $logStmt = $pdo->prepare(
            'SELECT log.id, log.task_id, log.operator_staff_id, operator.name AS operator_name, '
            . 'log.before_status, log.after_status, log.comment, log.created_at '
            . 'FROM workload_audit_logs log LEFT JOIN staffs operator ON operator.id = log.operator_staff_id '
            . 'WHERE log.task_id IN (' . implode(',', array_fill(0, count($taskIds), '?')) . ') '
            . 'ORDER BY log.task_id, log.id'
        );
        $logStmt->execute($taskIds);
        foreach ($logStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $log) {
            $log['id'] = (int) $log['id'];
            $log['task_id'] = (int) $log['task_id'];
            $log['operator_staff_id'] = (int) $log['operator_staff_id'];
            $logsByTask[$log['task_id']][] = $log;
        }
    }
    foreach ($list as &$row) {
        $row['audit_logs'] = $logsByTask[(int) $row['id']] ?? [];
    }
    unset($row);

    appJsonSuccess([
        'list' => $list,
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
            'total_pages' => (int) ceil($total / $pageSize),
        ],
        'permission_scope' => $scope,
    ]);

} catch (WorkloadPermissionScopeException $e) {
    appJsonError($e->statusCode(), $e->getMessage());
} catch (Throwable $e) {
    appLogEvent('workload.audit_list_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取列表失败');
}
