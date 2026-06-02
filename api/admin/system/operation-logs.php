<?php
require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    adminRequireAuth(static fn($user, $staff) => isSuperAdminUser($user));

    ensureAdminOperationLogsTable($db);

    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = min(100, max(1, (int)($_GET['page_size'] ?? 20)));
    $offset = ($page - 1) * $pageSize;
    $dateFrom = isset($_GET['date_from']) && $_GET['date_from'] ? $_GET['date_from'] : date('Y-m-d', strtotime('-6 days'));
    $dateTo = isset($_GET['date_to']) && $_GET['date_to'] ? $_GET['date_to'] : date('Y-m-d');
    $module = trim((string)($_GET['module'] ?? ''));
    $action = trim((string)($_GET['action'] ?? ''));

    $where = 'WHERE l.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)';
    $params = [$dateFrom, $dateTo];
    if ($module !== '') {
        $where .= ' AND l.module = ?';
        $params[] = $module;
    }
    if ($action !== '') {
        $where .= ' AND l.action = ?';
        $params[] = $action;
    }

    $sql = "SELECT
            l.id,
            l.operator_user_id,
            l.operator_staff_id,
            l.module,
            l.action,
            l.target_type,
            l.target_id,
            l.ip_address,
            l.created_at,
            s.name AS operator_name
        FROM admin_operation_logs l
        LEFT JOIN staffs s ON s.id = l.operator_staff_id
        $where
        ORDER BY l.created_at DESC
        LIMIT ?, ?";
    $stmt = $db->prepare($sql);
    $queryParams = $params;
    $queryParams[] = $offset;
    $queryParams[] = $pageSize;
    $stmt->execute($queryParams);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT COUNT(*) FROM admin_operation_logs l $where");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    jsonResponse(0, 'success', [
        'list' => $list,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ],
        'filters' => [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'module' => $module,
            'action' => $action,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[admin.system.operation-logs] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
