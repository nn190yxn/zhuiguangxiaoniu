<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
handleCORS();

try {
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
        appJsonError(405, '不支持的请求方法');
    }

    $context = appRequireStaffContext();
    if (!appCanAccessWorkload(['role' => $context['role'] ?? ''], $context)) {
        appJsonError(403, '无权限查询工作量员工');
    }

    $name = appOptionalString($_GET, 'name', '');
    if ($name === '') {
        appJsonError(400, '请输入员工姓名');
    }
    if (mb_strlen($name) > 50) {
        appJsonError(400, '员工姓名搜索内容过长');
    }

    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $where = ['s.status = 1', 's.name LIKE ?'];
    $params = ['%' . $name . '%'];

    if (!appCanViewAll($context)) {
        $storeId = (int) ($context['store_id'] ?? 0);
        if ($storeId <= 0) {
            appJsonError(403, '当前账号未绑定门店，无法查询员工');
        }
        $where[] = 's.store_id = ?';
        $params[] = $storeId;
    }

    $stmt = $pdo->prepare(
        'SELECT s.id AS staff_id, s.name AS staff_name, s.role, s.store_id, st.name AS store_name '
        . 'FROM staffs s LEFT JOIN stores st ON st.id = s.store_id '
        . 'WHERE ' . implode(' AND ', $where) . ' '
        . 'ORDER BY s.name ASC, st.sort_order ASC, s.id ASC LIMIT 20'
    );
    $stmt->execute($params);
    $rows = array_map(static fn(array $row): array => [
        'staff_id' => (int) $row['staff_id'],
        'staff_name' => (string) ($row['staff_name'] ?? ''),
        'role_code' => appRoleCode((string) ($row['role'] ?? '')),
        'store_id' => (int) ($row['store_id'] ?? 0),
        'store_name' => (string) ($row['store_name'] ?? ''),
    ], $stmt->fetchAll(PDO::FETCH_ASSOC));

    appLogEvent('workload.staff_search', [
        'viewer_staff_id' => $context['staff_id'] ?? null,
        'query_length' => mb_strlen($name),
        'result_count' => count($rows),
    ]);
    appJsonSuccess(['list' => $rows]);
} catch (Throwable $error) {
    appLogEvent('workload.staff_search_error', ['error' => $error->getMessage()]);
    appJsonError(500, '查询工作量员工失败');
}
