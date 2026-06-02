<?php
/**
 * Exam auto-save progress API
 * Saves current exam state for resume within 24 hours
 */
require_once __DIR__ . '/../config.php';
handleCORS();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'Method not allowed');
}
$userId = getCurrentUserId();
if (!$userId) {
    jsonError(401, '请先登录');
}
$input = getRequestInput();
$examType = trim($input['exam_type'] ?? '');
$answers = $input['answers'] ?? null;
$duration = (int)($input['duration'] ?? 0);
$sourceExamId = (int)($input['source_exam_id'] ?? 0);
$selectedExamId = (int)($input['selected_exam_id'] ?? 0);
$paperCode = strtoupper(trim((string)($input['paper_code'] ?? '')));

if (!$examType) {
    jsonError(400, '缺少考试类型');
}
if ($sourceExamId <= 0 || $selectedExamId <= 0) {
    jsonError(400, '缺少试卷标识');
}

if (!is_array($answers)) {
    $answers = [];
}
$meta = [
    'source_exam_id' => $sourceExamId,
    'selected_exam_id' => $selectedExamId,
    'paper_code' => in_array($paperCode, ['A', 'B'], true) ? $paperCode : 'A',
];
$answers['__meta'] = $meta;

$db = getDB();

// Check if there's an existing in_progress record within 24 hours
$stmt = $db->prepare("SELECT id FROM exam_records 
    WHERE user_id = ? AND exam_type = ? AND status = 'in_progress' 
    AND module_id = ?
    AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
    LIMIT 1");
$stmt->execute([$userId, $examType, $sourceExamId]);
$existing = $stmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    // Update existing record
    try {
        $stmt = $db->prepare("UPDATE exam_records SET answers = ?, duration = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([json_encode($answers, JSON_UNESCAPED_UNICODE), $duration, $existing['id']]);
    } catch (Throwable $e) {
        // 兼容旧表结构：无 updated_at 列
        $stmt = $db->prepare("UPDATE exam_records SET answers = ?, duration = ? WHERE id = ?");
        $stmt->execute([json_encode($answers, JSON_UNESCAPED_UNICODE), $duration, $existing['id']]);
    }
    jsonSuccess(['id' => $existing['id'], 'message' => '保存成功']);
} else {
    // Create new record
    $stmt = $db->prepare("INSERT INTO exam_records (user_id, module_id, exam_type, status, answers, duration, created_at, updated_at)
        VALUES (?, ?, 'in_progress', ?, ?, NOW(), NOW())");
    $stmt->execute([$userId, $sourceExamId, $examType, json_encode($answers, JSON_UNESCAPED_UNICODE), $duration]);
    $id = (int)$db->lastInsertId();
    jsonSuccess(['id' => $id, 'message' => '保存成功']);
}
