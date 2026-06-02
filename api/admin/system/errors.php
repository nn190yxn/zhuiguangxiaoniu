<?php
require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    adminRequireAuth(static fn($user, $staff) => isSuperAdminUser($user));

    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = min(100, max(1, (int)($_GET['page_size'] ?? 20)));
    $offset = ($page - 1) * $pageSize;
    $dateFrom = isset($_GET['date_from']) && $_GET['date_from'] ? $_GET['date_from'] : date('Y-m-d', strtotime('-6 days'));
    $dateTo = isset($_GET['date_to']) && $_GET['date_to'] ? $_GET['date_to'] : date('Y-m-d');
    $level = trim((string)($_GET['level'] ?? ''));
    $module = trim((string)($_GET['module'] ?? ''));

    if (!adminTableExists($db, 'system_error_logs')) {
        jsonResponse(0, 'success', [
            'summary' => [
                'total' => 0,
                'critical' => 0,
                'warning' => 0,
                'today' => 0,
            ],
            'list' => [],
            'pagination' => [
                'total' => 0,
                'page' => $page,
                'page_size' => $pageSize,
            ],
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'level' => $level,
                'module' => $module,
            ],
            'meta' => [
                'available' => false,
                'message' => 'system_error_logs 尚未落库，当前返回空结果。',
            ],
        ]);
    }

    $where = 'WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)';
    $params = [$dateFrom, $dateTo];
    if ($level !== '') {
        $where .= ' AND level = ?';
        $params[] = $level;
    }
    if ($module !== '') {
        $where .= ' AND module = ?';
        $params[] = $module;
    }

    $sql = "SELECT * FROM system_error_logs $where ORDER BY created_at DESC LIMIT ?, ?";
    $stmt = $db->prepare($sql);
    $queryParams = $params;
    $queryParams[] = $offset;
    $queryParams[] = $pageSize;
    $stmt->execute($queryParams);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare("SELECT COUNT(*) FROM system_error_logs $where");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $summarySql = "SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN level IN ('critical', 'fatal', 'error') THEN 1 ELSE 0 END) AS critical,
        SUM(CASE WHEN level IN ('warning', 'warn') THEN 1 ELSE 0 END) AS warning,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today
        FROM system_error_logs $where";
    $summaryStmt = $db->prepare($summarySql);
    $summaryStmt->execute($params);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    jsonResponse(0, 'success', [
        'summary' => [
            'total' => (int)($summary['total'] ?? 0),
            'critical' => (int)($summary['critical'] ?? 0),
            'warning' => (int)($summary['warning'] ?? 0),
            'today' => (int)($summary['today'] ?? 0),
        ],
        'list' => $list,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ],
        'filters' => [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'level' => $level,
            'module' => $module,
        ],
        'meta' => [
            'available' => true,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[admin.system.errors] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
