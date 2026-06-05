<?php
/**
 * 更新问卷状态API
 * POST /api/survey/update.php
 *
 * 操作：发布(draft->active) / 关闭(active->closed) / 删除
 * 请求体: { "id": 1, "action": "publish" | "close" | "delete" }
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(400, '仅支持POST请求');
}

try {
    $db = getDB();
    $user = getJwtCurrentUser();
    if (!$user) {
        jsonResponse(401, '请先登录');
    }

    $input = getRequestInput();
    $id = isset($input['id']) ? (int)$input['id'] : 0;
    $action = isset($input['action']) ? trim($input['action']) : '';

    if ($id <= 0) {
        jsonResponse(400, '缺少问卷ID');
    }

    // 验证权限
    $stmt = $db->prepare("SELECT id, status, creator_id FROM surveys WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$survey) {
        jsonResponse(404, '问卷不存在');
    }
    if (!canAccessSurvey($user, $survey)) {
        jsonResponse(403, '无权限操作此问卷');
    }

    switch ($action) {
        case 'publish':
            if ($survey['status'] !== 'draft') {
                jsonResponse(400, '只有草稿状态的问卷可以发布');
            }
            $stmt = $db->prepare("UPDATE surveys SET status = 'active', start_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            $stmt = $db->prepare("SELECT share_code FROM surveys WHERE id = ?");
            $stmt->execute([$id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            jsonResponse(0, '发布成功', ['share_link' => buildSurveyMiniProgramLink($row['share_code'])]);

        case 'close':
            if ($survey['status'] !== 'active') {
                jsonResponse(400, '只有已发布的问卷可以关闭');
            }
            $stmt = $db->prepare("UPDATE surveys SET status = 'closed', end_at = NOW() WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse(0, '问卷已关闭');

        case 'delete':
            $stmt = $db->prepare("DELETE FROM surveys WHERE id = ? AND status = 'draft'");
            $stmt->execute([$id]);
            if ($stmt->rowCount() === 0) {
                jsonResponse(400, '只能删除草稿状态的问卷');
            }
            jsonResponse(0, '已删除');

        default:
            jsonResponse(400, '未知操作，支持: publish / close / delete');
    }

} catch (Exception $e) {
    error_log('survey/update error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
