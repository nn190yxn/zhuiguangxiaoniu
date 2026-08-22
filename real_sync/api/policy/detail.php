<?php
/**
 * 制度详情API
 */
require_once __DIR__ . '/../config.php';
handleCORS();
// Auth check
$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(401, '请先登录');
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(1, '不支持的请求方法');
}

try {
    $policyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if (!$policyId) {
        jsonResponse(1, '缺少制度ID');
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT id, title, doc_key, content, category, workflow, keywords, version, is_need_confirm, created_at, updated_at FROM policies WHERE id = ?");
    $stmt->execute([$policyId]);
    $policy = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$policy) {
        jsonResponse(1, '制度不存在');
    }

    $policy['is_need_confirm'] = (int)$policy['is_need_confirm'];
    $policy['keywords'] = $policy['keywords'] ? array_values(array_filter(array_map('trim', explode(',', $policy['keywords'])))) : [];
    $policy['created_at'] = $policy['created_at'] ? date('Y-m-d H:i', strtotime($policy['created_at'])) : null;
    $policy['updated_at'] = $policy['updated_at'] ? date('Y-m-d H:i', strtotime($policy['updated_at'])) : null;
    $readStatus = [
        'notification_id' => null,
        'policy_id' => $policyId,
        'is_read' => false,
        'is_confirmed' => false,
    ];

    $stmt = $db->prepare("SELECT id, is_read, is_confirmed FROM policy_notifications WHERE policy_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1");
    $stmt->execute([$policyId, $userId]);
    $notification = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($notification) {
        $readStatus['notification_id'] = 'policy:' . (int)$notification['id'];
        $readStatus['is_read'] = (int)$notification['is_read'] === 1;
        $readStatus['is_confirmed'] = (int)$notification['is_confirmed'] === 1;
    } else {
        $stmt = $db->prepare('SELECT 1 FROM policy_read_history WHERE policy_id = ? AND user_id = ? LIMIT 1');
        $stmt->execute([$policyId, $userId]);
        $readStatus['is_confirmed'] = (bool)$stmt->fetchColumn();
        $readStatus['is_read'] = $readStatus['is_confirmed'];
    }

    jsonResponse(0, 'success', ['policy' => $policy, 'read_status' => $readStatus]);
} catch (Exception $e) {
    error_log('policy/detail error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
