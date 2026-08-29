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
    $courseId = (int)($_GET['course_id'] ?? 0);
    $level = trim((string)($_GET['level'] ?? ''));
    $where = ['e.is_active = 1'];
    $params = [];
    if ($courseId > 0) {
        $where[] = 'e.course_id = ?';
        $params[] = $courseId;
    }
    $sql = 'SELECT e.id, e.course_id, e.title, e.description, e.exam_type, e.total_score, e.pass_score, e.duration, e.attempt_limit,
        c.title AS module_name, (SELECT COUNT(*) FROM exam_questions q WHERE q.exam_id = e.id) AS question_count
        FROM exams e LEFT JOIN courses c ON c.id = e.course_id WHERE ' . implode(' AND ', $where) . ' ORDER BY e.course_id ASC, e.id DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $mappedLevel = examLevel($row['exam_type'] ?? '', $row['title'] ?? '');
        if ($level !== '' && $mappedLevel !== $level) {
            continue;
        }
        if ((int)$row['question_count'] <= 0) {
            continue;
        }
        $items[] = [
            'id' => (int)$row['id'],
            'course_id' => (int)$row['course_id'],
            'module_code' => 'course_' . (int)$row['course_id'],
            'module_name' => (string)($row['module_name'] ?: '销售模块'),
            'title' => (string)$row['title'],
            'description' => (string)($row['description'] ?? ''),
            'level' => $mappedLevel,
            'total_score' => (int)($row['total_score'] ?? 100),
            'pass_score' => (int)($row['pass_score'] ?? 60),
            'duration' => (int)($row['duration'] ?? 0),
            'attempt_limit' => (int)($row['attempt_limit'] ?? 0),
            'question_count' => (int)$row['question_count'],
        ];
    }
    jsonSuccess(['list' => $items, 'filters' => ['course_id' => $courseId, 'level' => $level]]);
} catch (Throwable $error) {
    error_log('Exam list error: ' . $error->getMessage());
    jsonError(500, '试卷列表暂时不可用');
}

function examLevel($examType, $title): string
{
    $value = mb_strtolower(trim((string)$examType . ' ' . (string)$title));
    if (preg_match('/专业|进阶|advanced|professional/u', $value)) {
        return 'professional';
    }
    if (preg_match('/基础|入门|basic|intro/u', $value)) {
        return 'basic';
    }
    return 'unclassified';
}
