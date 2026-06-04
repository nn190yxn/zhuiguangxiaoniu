<?php
require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    [$userId, $user, $staff] = adminRequireAuth(static fn($user, $staff) => isSuperAdminUser($user));

    ensureLoginAuditTable($db);

    $dateFrom = isset($_GET['date_from']) && $_GET['date_from'] ? $_GET['date_from'] : date('Y-m-d', strtotime('-6 days'));
    $dateTo = isset($_GET['date_to']) && $_GET['date_to'] ? $_GET['date_to'] : date('Y-m-d');
    $loginType = isset($_GET['login_type']) ? trim((string)$_GET['login_type']) : '';
    $loginStatus = isset($_GET['login_status']) ? trim((string)$_GET['login_status']) : '';
    $source = isset($_GET['source']) ? trim((string)$_GET['source']) : '';
    $staffId = max(0, (int)($_GET['staff_id'] ?? 0));
    $storeId = max(0, (int)($_GET['store_id'] ?? 0));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $pageSize = min(100, max(1, (int)($_GET['page_size'] ?? 20)));
    $offset = ($page - 1) * $pageSize;

    $where = 'WHERE l.created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY)';
    $params = [$dateFrom, $dateTo];
    if ($loginType !== '') {
        $where .= ' AND l.login_type = ?';
        $params[] = $loginType;
    }
    if ($loginStatus !== '') {
        $where .= ' AND l.login_status = ?';
        $params[] = $loginStatus;
    }
    if ($source !== '') {
        $where .= ' AND l.source = ?';
        $params[] = $source;
    }
    if ($staffId > 0) {
        $where .= ' AND l.staff_id = ?';
        $params[] = $staffId;
    }
    if ($storeId > 0) {
        $where .= ' AND s.store_id = ?';
        $params[] = $storeId;
    }

    $sql = "SELECT l.*, s.name AS staff_name, st.name AS store_name
        FROM login_audit_logs l
        LEFT JOIN staffs s ON s.id = l.staff_id
        LEFT JOIN stores st ON st.id = s.store_id
        $where
        ORDER BY l.created_at DESC
        LIMIT ?, ?";
    $stmt = $db->prepare($sql);
    $queryParams = $params;
    $queryParams[] = $offset;
    $queryParams[] = $pageSize;
    $stmt->execute($queryParams);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $countSql = "SELECT COUNT(*)
        FROM login_audit_logs l
        LEFT JOIN staffs s ON s.id = l.staff_id
        LEFT JOIN stores st ON st.id = s.store_id
        $where";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $summarySql = "SELECT
        SUM(CASE WHEN DATE(l.created_at) = CURDATE() AND l.login_status = 'success' THEN 1 ELSE 0 END) AS today_login_success,
        SUM(CASE WHEN DATE(l.created_at) = CURDATE() AND l.login_status = 'failure' THEN 1 ELSE 0 END) AS today_login_failure,
        SUM(CASE WHEN DATE(l.created_at) = CURDATE() AND l.message = 'new_device' THEN 1 ELSE 0 END) AS new_device_count
        FROM login_audit_logs l";
    $summary = $db->query($summarySql)->fetch(PDO::FETCH_ASSOC) ?: [];

    jsonResponse(0, 'success', [
        'viewer' => [
            'user_id' => (int)$userId,
            'staff_id' => (int)($staff['id'] ?? 0),
            'name' => $staff['name'] ?? ($user['username'] ?? '管理员'),
        ],
        'summary' => [
            'today_login_success' => (int)($summary['today_login_success'] ?? 0),
            'today_login_failure' => (int)($summary['today_login_failure'] ?? 0),
            'new_device_count' => (int)($summary['new_device_count'] ?? 0),
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
            'login_type' => $loginType,
            'login_status' => $loginStatus,
            'source' => $source,
            'staff_id' => $staffId,
            'store_id' => $storeId,
        ],
    ]);
} catch (Throwable $e) {
    error_log('[admin.security.login-audit] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
