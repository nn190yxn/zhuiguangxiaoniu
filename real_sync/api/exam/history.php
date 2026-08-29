<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
handleCORS();

$userId = getCurrentUserId();
if (!$userId) {
    jsonError(401, '请先登录');
}

try {
    $db = getDB();
    $month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? (string)$_GET['month'] : date('Y-m');
    $start = $month . '-01 00:00:00';
    $end = date('Y-m-d H:i:s', strtotime($start . ' +1 month'));
    $stmt = $db->prepare('SELECT r.id, r.module_id AS exam_id, r.total_score, r.passing_score, r.is_passed, r.status, r.duration, r.completed_at, e.title, e.course_id, c.title AS module_name
        FROM exam_records r LEFT JOIN exams e ON e.id = r.module_id LEFT JOIN courses c ON c.id = e.course_id
        WHERE r.user_id = ? AND r.exam_type = \'course_exam\' AND r.created_at >= ? AND r.created_at < ? ORDER BY r.created_at DESC');
    $stmt->execute([(int)$userId, $start, $end]);
    $items = array_map(static function (array $row): array {
        return [
            'id' => (int)$row['id'],
            'exam_id' => (int)$row['exam_id'],
            'module_code' => 'course_' . (int)($row['course_id'] ?? 0),
            'module_name' => (string)($row['module_name'] ?: '销售模块'),
            'title' => (string)($row['title'] ?? '考核'),
            'score' => $row['total_score'] === null ? null : (float)$row['total_score'],
            'pass_score' => (float)($row['passing_score'] ?? 60),
            'passed' => (bool)$row['is_passed'],
            'status' => (string)$row['status'],
            'completed_at' => $row['completed_at'],
        ];
    }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    jsonSuccess(['month' => $month, 'list' => $items]);
} catch (Throwable $error) {
    error_log('Exam history error: ' . $error->getMessage());
    jsonError(500, '考核历史暂时不可用');
}
