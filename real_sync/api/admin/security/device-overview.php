<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = getDB();
    [$userId, $user, $staff] = adminRequirePermission('staff.audit_view');

    $view = trim((string)($_GET['view'] ?? 'both'));
    if (!in_array($view, ['alerts', 'usage', 'both'], true)) {
        jsonResponse(1, '不支持的 view 参数');
    }

    $base = 'FROM device_logins dl '
        . 'LEFT JOIN staffs s ON s.id = dl.staff_id '
        . 'LEFT JOIN stores st ON st.id = s.store_id '
        . 'WHERE dl.is_active = 1';

    $result = [];

    if ($view === 'alerts' || $view === 'both') {
        // 多设备账号：按员工聚合去重设备指纹，2 台及以上视为风险预警
        $stmt = $db->prepare(
            'SELECT dl.staff_id, COALESCE(s.name, "未知员工") AS staff_name, '
            . 'COALESCE(st.name, "-") AS store_name, '
            . 'COUNT(DISTINCT dl.device_fingerprint) AS device_count, '
            . 'MAX(dl.last_login) AS last_login '
            . $base
            . ' GROUP BY dl.staff_id, s.name, st.name '
            . 'HAVING device_count >= 2 '
            . 'ORDER BY device_count DESC, last_login DESC LIMIT 100'
        );
        $stmt->execute();
        $result['alerts'] = array_map(static function (array $row): array {
            return [
                'staff_id' => (int)$row['staff_id'],
                'staff_name' => (string)$row['staff_name'],
                'store_name' => (string)$row['store_name'],
                'device_count' => (int)$row['device_count'],
                'last_login' => $row['last_login'] ? date('Y-m-d H:i', strtotime((string)$row['last_login'])) : '-',
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    if ($view === 'usage' || $view === 'both') {
        $stmt = $db->prepare(
            'SELECT dl.staff_id, COALESCE(s.name, "未知员工") AS staff_name, '
            . 'COALESCE(st.name, "-") AS store_name, '
            . 'COUNT(DISTINCT dl.device_fingerprint) AS unique_devices, '
            . 'COALESCE(SUM(dl.login_count), 0) AS total_logins, '
            . 'COALESCE(SUM(dl.is_trusted), 0) AS trusted_count, '
            . 'MAX(dl.last_login) AS last_login '
            . $base
            . ' GROUP BY dl.staff_id, s.name, st.name '
            . 'ORDER BY last_login DESC LIMIT 100'
        );
        $stmt->execute();
        $result['usage'] = array_map(static function (array $row): array {
            return [
                'staff_id' => (int)$row['staff_id'],
                'staff_name' => (string)$row['staff_name'],
                'store_name' => (string)$row['store_name'],
                'unique_devices' => (int)$row['unique_devices'],
                'total_logins' => (int)$row['total_logins'],
                'trusted_count' => (int)$row['trusted_count'],
                'last_login' => $row['last_login'] ? date('Y-m-d H:i', strtotime((string)$row['last_login'])) : '-',
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    jsonResponse(0, 'success', $result);
} catch (Throwable $e) {
    error_log('[admin.security.device-overview] ' . $e->getMessage());
    jsonResponse(500, '设备安全概览加载失败');
}
