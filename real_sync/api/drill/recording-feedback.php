<?php
/**
 * 演练录音反馈查询API
 * GET /api/drill/recording-feedback.php?recording_id=xxx
 * GET /api/drill/recording-feedback.php?task_id=xxx
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

if ($method !== 'GET') {
    jsonResponse(1, '不支持的请求方法');
}

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if (!$userId) {
        jsonResponse(401, '请先登录');
    }

    $recordingId = isset($_GET['recording_id']) ? (int)$_GET['recording_id'] : 0;
    $taskId = isset($_GET['task_id']) ? (int)$_GET['task_id'] : 0;

    if (!$recordingId && !$taskId) {
        jsonResponse(1, '缺少参数：recording_id 或 task_id');
    }

    if ($recordingId > 0) {
        // 按录音ID查询
        $sql = "SELECT f.*, r.audio_url, r.audio_duration, r.created_at as recording_time,
                       ds.content as script_content, ds.scene
                FROM script_ai_feedback f
                JOIN drill_recordings r ON f.recording_id = r.id
                JOIN drill_scripts ds ON f.script_id = ds.id
                WHERE f.recording_id = ? AND f.user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$recordingId, $userId]);
        $feedback = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$feedback) {
            jsonResponse(1, '反馈记录不存在');
        }

        // 获取维度名称
        $dimSql = "SELECT dimension_code, dimension_name, weight FROM script_evaluation_dimensions WHERE status = 1";
        $stmt = $db->prepare($dimSql);
        $stmt->execute();
        $dimensions = [];
        while ($dim = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $dimensions[$dim['dimension_code']] = $dim;
        }

        // 组装返回数据
        $dimensionScores = json_decode($feedback['dimension_scores'], true) ?: [];
        $formattedScores = [];
        foreach ($dimensionScores as $code => $score) {
            $dimInfo = $dimensions[$code] ?? ['dimension_name' => $code, 'weight' => 0];
            $formattedScores[] = [
                'code' => $code,
                'name' => $dimInfo['dimension_name'],
                'score' => (int)$score,
                'weight' => (float)$dimInfo['weight'],
                'weighted_score' => round((int)$score * (float)$dimInfo['weight'], 1)
            ];
        }

        jsonResponse(0, 'success', [
            'id' => $feedback['id'],
            'recording_id' => $feedback['recording_id'],
            'audio_url' => $feedback['audio_url'],
            'audio_duration' => $feedback['audio_duration'],
            'recording_time' => $feedback['recording_time'],
            'transcribed_text' => $feedback['transcribed_text'],
            'script_content' => $feedback['script_content'],
            'scene' => $feedback['scene'],
            'total_score' => (int)$feedback['total_score'],
            'level' => $feedback['level'],
            'feedback' => $feedback['feedback'],
            'suggestions' => json_decode($feedback['suggestions'], true) ?: [],
            'dimension_scores' => $formattedScores,
            'model_used' => $feedback['model_used'],
            'processing_time' => $feedback['processing_time']
        ]);

    } else {
        // 按任务ID查询所有录音反馈
        $sql = "SELECT f.id, f.recording_id, f.total_score, f.level, f.created_at,
                       r.audio_url, r.audio_duration, ds.scene
                FROM script_ai_feedback f
                JOIN drill_recordings r ON f.recording_id = r.id
                JOIN drill_scripts ds ON f.script_id = ds.id
                WHERE r.task_id = ? AND f.user_id = ?
                ORDER BY f.created_at DESC
                LIMIT 10";
        $stmt = $db->prepare($sql);
        $stmt->execute([$taskId, $userId]);

        $list = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $list[] = [
                'id' => $row['id'],
                'recording_id' => $row['recording_id'],
                'audio_url' => $row['audio_url'],
                'audio_duration' => $row['audio_duration'],
                'scene' => $row['scene'],
                'total_score' => (int)$row['total_score'],
                'level' => $row['level'],
                'created_at' => $row['created_at']
            ];
        }

        jsonResponse(0, 'success', ['list' => $list]);
    }

} catch (Exception $e) {
    error_log('recording-feedback error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
