<?php
/**
 * Admin toggle staff account status (enable/disable)
 */
require_once __DIR__ . '/common.php';
handleCORS();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '仅支持 POST 请求');
}

$input = adminJsonInput();
$staffId = max(0, (int)($input['staff_id'] ?? 0));
$status = (int)($input['status'] ?? 0);

if ($staffId <= 0) {
    jsonResponse(1, '缺少员工ID');
}
if (!in_array($status, [0, 1], true)) {
    jsonResponse(1, '状态值无效');
}

$db = getDB();
$stmt = $db->prepare("SELECT id FROM staffs WHERE id = ?");
$stmt->execute([$staffId]);
if (!$stmt->fetch()) {
    jsonResponse(1, '员工不存在');
}

[, $user, $operatorStaff] = adminRequireAuth(static fn($u, $s) => isSuperAdminUser($u, $s));

$stmt = $db->prepare("SELECT user_id, name FROM staffs WHERE id = ?");
$stmt->execute([$staffId]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

$db->prepare("UPDATE staffs SET status = ?, updated_at = NOW() WHERE id = ?")->execute([$status, $staffId]);

if ($staff['user_id']) {
    $db->prepare("UPDATE wp_users SET user_status = ? WHERE ID = ?")->execute([$status === 1 ? 0 : 1, (int)$staff['user_id']]);
}

adminRecordOperation($db, $user, $operatorStaff, [
    'module' => 'staff',
    'action' => 'toggle_status',
    'target_type' => 'staff',
    'target_id' => (string)$staffId,
    'before' => ['status' => $status === 1 ? 0 : 1],
    'after' => ['status' => $status],
]);

$label = $status === 1 ? '已启用' : '已停用';
jsonSuccess(['message' => $staff['name'] . ' 的账号' . $label]);
