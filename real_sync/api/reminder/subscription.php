<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

handleCORS();

try {
    $context = appRequireStaffContext();
    $pdo = reminderDb();
    reminderEnsureSchema($pdo);

    $userId = (int)($context['user_id'] ?? 0);
    $staffId = (int)($context['staff_id'] ?? 0);
    if ($userId <= 0) {
        appJsonError(401, '请先登录');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->prepare("SELECT id, scene_code, template_key, openid, accept_status, extra_json, granted_at, last_seen_at, updated_at
            FROM mini_user_subscriptions
            WHERE user_id = ?
            ORDER BY scene_code ASC, template_key ASC");
        $stmt->execute([$userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        appJsonSuccess(['list' => $rows]);
    }

    $input = appInputArray();
    $sceneCode = appRequireString($input, 'scene_code', '场景');
    $templateKey = appRequireString($input, 'template_key', '模板键');
    $acceptStatus = appRequireEnum($input, 'accept_status', ['accept', 'reject', 'ban', 'unknown'], '授权状态');
    $staff = getStaffByUserId($userId) ?: [];
    $openid = appOptionalString($input, 'openid', (string)($staff['openid'] ?? ''));
    $extraJson = json_encode($input['extra'] ?? new stdClass(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $stmt = $pdo->prepare("INSERT INTO mini_user_subscriptions (user_id, staff_id, scene_code, template_key, openid, accept_status, extra_json, granted_at, last_seen_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, CASE WHEN ? = 'accept' THEN NOW() ELSE NULL END, NOW())
        ON DUPLICATE KEY UPDATE
            staff_id = VALUES(staff_id),
            scene_code = VALUES(scene_code),
            openid = VALUES(openid),
            accept_status = VALUES(accept_status),
            extra_json = VALUES(extra_json),
            granted_at = CASE WHEN VALUES(accept_status) = 'accept' THEN NOW() ELSE granted_at END,
            last_seen_at = NOW()");
    $stmt->execute([
        $userId,
        $staffId,
        $sceneCode,
        $templateKey,
        $openid,
        $acceptStatus,
        $extraJson === false ? '{}' : $extraJson,
        $acceptStatus,
    ]);

    appLogEvent('reminder.subscription_saved', ['staff_id' => $staffId, 'scene_code' => $sceneCode, 'template_key' => $templateKey, 'accept_status' => $acceptStatus]);
    appJsonSuccess([], '保存成功');
} catch (Throwable $e) {
    appLogEvent('reminder.subscription_error', ['error' => $e->getMessage()]);
    appJsonError(500, '保存提醒授权失败');
}
