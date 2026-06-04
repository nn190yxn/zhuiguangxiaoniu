<?php
require_once dirname(__DIR__) . '/common.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    [, $user, $operatorStaff] = adminRequireAuth(static fn($user, $staff) => isSuperAdminUser($user));
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(1, '不支持的请求方法');
    }

    $input = adminJsonInput();
    $staffId = max(0, (int)($input['staff_id'] ?? 0));
    if ($staffId <= 0) {
        jsonResponse(1, '缺少 staff_id');
    }

    $stmt = $db->prepare('SELECT * FROM staffs WHERE id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) {
        jsonResponse(404, '员工不存在');
    }

    $fields = [];
    $params = [];
    foreach (['name', 'phone', 'role'] as $column) {
        if (array_key_exists($column, $input)) {
            $fields[] = "$column = ?";
            $params[] = trim((string)$input[$column]);
        }
    }
    if (array_key_exists('store_id', $input)) {
        $fields[] = 'store_id = ?';
        $params[] = max(0, (int)$input['store_id']);
    }
    if (array_key_exists('status', $input)) {
        $fields[] = 'status = ?';
        $params[] = (int)$input['status'] === 1 ? 1 : 0;
    }

    if (!$fields) {
        jsonResponse(1, '没有可更新字段');
    }

    $db->beginTransaction();
    try {
        $params[] = $staffId;
        $stmt = $db->prepare('UPDATE staffs SET ' . implode(', ', $fields) . ', updated_at = NOW() WHERE id = ?');
        $stmt->execute($params);

        if (array_key_exists('status', $input)) {
            $linkedUserId = (int)($before['user_id'] ?? 0);
            if ($linkedUserId > 0) {
                $db->prepare('UPDATE wp_users SET user_status = ? WHERE ID = ?')->execute([(int)$input['status'] === 1 ? 0 : 1, $linkedUserId]);
            }
        }

        $stmt = $db->prepare('SELECT * FROM staffs WHERE id = ? LIMIT 1');
        $stmt->execute([$staffId]);
        $after = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        adminRecordOperation($db, $user, $operatorStaff, [
            'module' => 'staff',
            'action' => 'update',
            'target_type' => 'staff',
            'target_id' => (string)$staffId,
            'before' => $before,
            'after' => $after,
        ]);

        $db->commit();
    } catch (Throwable $txe) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $txe;
    }

    jsonResponse(0, 'success', ['item' => $after]);
} catch (Throwable $e) {
    error_log('[admin.staff.update] ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
