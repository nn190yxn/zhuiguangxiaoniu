<?php
require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    adminRequireAuth(static fn($user, $staff) => isSuperAdminUser($user));

    $staffId = max(0, (int)($_GET['staff_id'] ?? 0));
    if ($staffId <= 0) {
        jsonResponse(1, '缺少 staff_id');
    }

    ensureLoginAuditTable($db);

    $hasOpenid = adminColumnExists($db, 'staffs', 'openid');
    $hasOpenidBoundAt = adminColumnExists($db, 'staffs', 'openid_bound_at');
    $hasUserId = adminColumnExists($db, 'staffs', 'user_id');

    $selectParts = [
        's.id', 's.employee_no', 's.name', 's.phone', 's.role', 's.stage', 's.status', 's.store_id',
        'st.name AS store_name'
    ];
    if ($hasUserId) {
        $selectParts[] = 's.user_id';
    } else {
        $selectParts[] = 'NULL AS user_id';
    }
    if ($hasOpenid) {
        $selectParts[] = 's.openid';
    } else {
        $selectParts[] = 'NULL AS openid';
    }
    if ($hasOpenidBoundAt) {
        $selectParts[] = 's.openid_bound_at';
    } else {
        $selectParts[] = 'NULL AS openid_bound_at';
    }

    $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM staffs s LEFT JOIN stores st ON st.id = s.store_id WHERE s.id = ? LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute([$staffId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        jsonResponse(404, '员工不存在');
    }

    $deviceStats = [
        'total_devices' => 0,
        'trusted_devices' => 0,
        'recent_login' => null,
    ];
    $devices = [];
    if (adminTableExists($db, 'device_logins')) {
        $stmt = $db->prepare('SELECT * FROM device_logins WHERE staff_id = ? ORDER BY last_login DESC LIMIT 20');
        $stmt->execute([$staffId]);
        $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $deviceStats['total_devices'] = count($devices);
        $deviceStats['trusted_devices'] = count(array_filter($devices, static fn($row) => (int)($row['is_trusted'] ?? 0) === 1));
        $deviceStats['recent_login'] = $devices[0]['last_login'] ?? null;
    }

    $stmt = $db->prepare('SELECT id, login_type, login_status, source, ip_address, message, created_at FROM login_audit_logs WHERE staff_id = ? ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([$staffId]);
    $recentLoginAudits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $item['status_text'] = (int)($item['status'] ?? 0) === 1 ? '启用' : '停用';
    $item['wechat_bound'] = !empty($item['openid']);
    $item['wechat_bound_text'] = !empty($item['openid']) ? '已绑定' : '未绑定';
    $item['must_change_password'] = null;

    jsonResponse(0, 'success', [
        'item' => $item,
        'devices' => $devices,
        'device_stats' => $deviceStats,
        'recent_login_audits' => $recentLoginAudits,
    ]);
} catch (Throwable $e) {
    error_log('[admin.staff.detail] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
