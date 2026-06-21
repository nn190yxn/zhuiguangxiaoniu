<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
handleCORS();

try {
    $context = appRequireStaffContext();
    
    // Allow both headquarters and operation roles to view audit list
    $allowedRoles = ['headquarters', 'operation'];
    if (!$context['permissions']['can_view_all'] && !in_array($context['role'], $allowedRoles, true)) {
        appJsonError(403, '无权限查看审核列表');
    }

    $pdo = workloadDb();
    workloadEnsureAuditSchema($pdo);

    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = min(50, max(10, (int)($_GET['page_size'] ?? 20)));
    $offset = ($page - 1) * $pageSize;
    
    $where = "1=1";
    $params = [];
    
    if (isset($_GET['store_id']) && $_GET['store_id'] > 0) {
        $where .= " AND t.store_id = ?";
        $params[] = (int)$_GET['store_id'];
    }
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $where .= " AND t.audit_status = ?";
        $params[] = $_GET['status'];
    }
    
    $stmt = $pdo->prepare("SELECT t.id, t.report_id, t.staff_id, t.store_id, t.role_code, t.metric_code, t.submitted_value, t.audit_status, t.audit_comment, t.created_at,
        s.name AS staff_name, st.name AS store_name, m.metric_name,
        ev.evidence_urls
        FROM workload_audit_tasks t
        LEFT JOIN staffs s ON s.id = t.staff_id
        LEFT JOIN stores st ON st.id = t.store_id
        LEFT JOIN metric_definitions m ON m.metric_code = t.metric_code AND m.role_code = t.role_code
        LEFT JOIN (
            SELECT report_id, metric_code, GROUP_CONCAT(file_url ORDER BY created_at ASC SEPARATOR ',') AS evidence_urls
            FROM workload_evidences WHERE deleted_at IS NULL
            GROUP BY report_id, metric_code
        ) ev ON ev.report_id = t.report_id AND ev.metric_code = t.metric_code
        WHERE $where
        ORDER BY t.created_at DESC
        LIMIT ?, ?");
        
    $stmt->execute([...$params, $offset, $pageSize]);
    $list = array_map(static function(array $row): array {
        $urls = array_filter(array_map('trim', explode(',', (string)($row['evidence_urls'] ?? ''))));
        $row['evidence_urls'] = implode(',', array_map('workloadPublicUrl', $urls));
        return $row;
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));

    appJsonSuccess(['list' => $list]);

} catch (Throwable $e) {
    appLogEvent('workload.audit_list_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取列表失败');
}
