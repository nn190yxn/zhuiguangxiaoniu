<?php
/**
 * 语音通关记录提交 API
 *
 * 提交语音通关结果，如果通过则更新 user_pass_progress。
 *
 * POST /api/pass/voice-submit.php
 * Body: {
 *   "stage_id": 1,
 *   "text": "语音识别文本",
 *   "assess_result": { ... },  // voice-assess.php 返回的结果
 *   "audio_url": "可选: 录音文件URL"
 * }
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '仅支持 POST 请求');
}

try {
    $db = getDB();
    $userId = getCurrentUserId();
    $user = getJwtCurrentUser();

    $body = json_decode(file_get_contents('php://input'), true);
    $stageId = isset($body['stage_id']) ? (int)$body['stage_id'] : 0;
    $text = isset($body['text']) ? trim((string)$body['text']) : '';
    $audioUrl = isset($body['audio_url']) ? trim((string)$body['audio_url']) : '';
    $assessResult = isset($body['assess_result']) ? $body['assess_result'] : null;

    if (!$stageId) {
        jsonResponse(1, '缺少阶段ID');
    }

    // 获取阶段信息
    $stageSql = "SELECT * FROM pass_stages WHERE id = ? AND is_active = 1";
    $stmt = $db->prepare($stageSql);
    $stmt->execute([$stageId]);
    $stage = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$stage) {
        jsonResponse(1, '通关阶段不存在');
    }

    // 权限校验
    $effectiveRole = normalizeStaffRoleCode(getEffectiveStaffRole($user));
    if (!isPassStageRoleAllowed($stage['role'], $effectiveRole)) {
        jsonResponse(403, '无权进行该阶段的语音通关');
    }

    // 如果没有传入 assess_result，重新评估
    if ($assessResult === null) {
        jsonResponse(1, '请先调用 voice-assess.php 进行语音评估');
    }

    $result = $assessResult['result'] ?? 'failed';
    $totalScore = (int)($assessResult['total_score'] ?? 0);
    $ruleScore = (int)($assessResult['rule_score'] ?? 0);
    $aiScore = (int)($assessResult['ai_score'] ?? 0);
    $forbiddenHit = (int)($assessResult['forbidden_hit'] ?? 0);
    $keyPointsMissed = $assessResult['key_points_missed'] ?? [];
    $feedback = $assessResult['feedback'] ?? '';
    $attemptNum = (int)($assessResult['attempt_num'] ?? 1);

    // 获取当前尝试次数
    $attemptSql = "SELECT COUNT(*) as cnt FROM pass_voice_records WHERE user_id = ? AND stage_id = ?";
    $stmt = $db->prepare($attemptSql);
    $stmt->execute([$userId, $stageId]);
    $attemptNum = (int)$stmt->fetchColumn() + 1;

    // 插入语音通关记录
    $insertSql = "INSERT INTO pass_voice_records
        (user_id, stage_id, audio_url, asr_text, asr_confidence,
         rule_score, ai_score, total_score, result, forbidden_hit,
         key_points_missed, ai_feedback, attempt_num)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $db->prepare($insertSql);
    $stmt->execute([
        $userId,
        $stageId,
        $audioUrl ?: null,
        $text,
        $assessResult['asr_confidence'] ?? 1.0,
        $ruleScore,
        $aiScore,
        $totalScore,
        $result,
        $forbiddenHit,
        json_encode($keyPointsMissed, JSON_UNESCAPED_UNICODE),
        $feedback,
        $attemptNum,
    ]);

    $recordId = (int)$db->lastInsertId();

    // 如果通过，更新 user_pass_progress
    $progressUpdated = false;
    if ($result === 'passed') {
        // 检查是否已有进度记录
        $progressSql = "SELECT id FROM user_pass_progress WHERE user_id = ? AND stage_id = ?";
        $stmt = $db->prepare($progressSql);
        $stmt->execute([$userId, $stageId]);
        $existingProgress = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existingProgress) {
            $updateSql = "UPDATE user_pass_progress
                SET status = 'completed',
                    completed_at = NOW(),
                    attempts_count = attempts_count + 1,
                    updated_at = NOW()
                WHERE id = ?";
            $stmt = $db->prepare($updateSql);
            $stmt->execute([$existingProgress['id']]);
        } else {
            $insertProgressSql = "INSERT INTO user_pass_progress
                (user_id, stage_id, status, progress_percent, attempts_count, started_at, completed_at)
                VALUES (?, ?, 'completed', 100.00, 1, NOW(), NOW())";
            $stmt = $db->prepare($insertProgressSql);
            $stmt->execute([$userId, $stageId]);
        }
        $progressUpdated = true;
    }

    jsonResponse(0, $result === 'passed' ? '通关成功' : '通关未通过', [
        'record_id' => $recordId,
        'result' => $result,
        'total_score' => $totalScore,
        'attempt_num' => $attemptNum,
        'progress_updated' => $progressUpdated,
        'feedback' => $feedback,
    ]);

} catch (Exception $e) {
    jsonResponse(1, '提交失败: ' . $e->getMessage());
}
