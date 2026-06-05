<?php
/**
 * Exam resume API - load saved progress
 */
require_once __DIR__ . '/../config.php';
handleCORS();
$userId = getCurrentUserId();
if (!$userId) {
    jsonError(401, '请先登录');
}
$db = getDB();

$examType = trim($_GET['exam_type'] ?? '');
 $sourceExamId = (int)($_GET['source_exam_id'] ?? 0);
if (!$examType) {
    jsonError(400, '缺少考试类型');
}
if ($sourceExamId <= 0) {
    jsonError(400, '缺少试卷标识');
}

// Find in_progress record within 24 hours
$stmt = $db->prepare("SELECT * FROM exam_records
    WHERE user_id = ? AND exam_type = ? AND status = 'in_progress'
    AND module_id = ?
    AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY created_at DESC LIMIT 1");
$stmt->execute([$userId, $examType, $sourceExamId]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    jsonSuccess(['has_progress' => false]);
}

$answers = json_decode($record['answers'] ?? '{}', true) ?: [];
$meta = is_array($answers['__meta'] ?? null) ? $answers['__meta'] : [];

jsonSuccess([
    'has_progress' => true,
    'record_id' => (int)$record['id'],
    'answers' => $answers,
    'duration' => (int)$record['duration'],
    'source_exam_id' => (int)($meta['source_exam_id'] ?? $record['module_id'] ?? 0),
    'selected_exam_id' => (int)($meta['selected_exam_id'] ?? 0),
    'paper_code' => (string)($meta['paper_code'] ?? 'A'),
    'created_at' => $record['created_at'],
    'expires_at' => date('Y-m-d H:i:s', strtotime($record['created_at'] . ' +24 hours')),
]);
