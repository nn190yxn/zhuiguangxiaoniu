<?php
/**
 * Exam detail and questions API for mini-program course exams.
 */
require_once __DIR__ . '/../config.php';
handleCORS();
// Auth check
$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(401, '请先登录');
}

header('Content-Type: application/json');

try {
    $db = getDB();
    $input = getRequestInput();
    $action = isset($_GET['action']) ? trim($_GET['action']) : trim((string)($input['action'] ?? 'detail'));
    $examId = isset($_GET['id']) ? (int)$_GET['id'] : (int)($input['id'] ?? $input['source_exam_id'] ?? 0);

    if ($action === 'assign') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(405, '考试分配必须使用POST请求');
        }
        if (!$examId) {
            jsonResponse(1, '缺少考试ID');
        }
        $idempotencyKey = getExamAssignmentIdempotencyKey();
        if ($idempotencyKey === '') {
            jsonResponse(400, '缺少幂等键');
        }
        ensureExamAssignmentIdempotencyTable($db);
        $existingAssignment = findExamAssignmentIdempotency($db, (int)$userId, $examId, $idempotencyKey);
        if ($existingAssignment) {
            jsonResponse(0, 'success', $existingAssignment);
        }

        $stmt = $db->prepare('SELECT id, title, course_id, pass_score, duration, total_score FROM exams WHERE id = ? AND is_active = 1 LIMIT 1');
        $stmt->execute([$examId]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$exam) {
            jsonResponse(1, '考试不存在');
        }

        // 约定：同一 course_id 下 exam_paper='A'/'B' 为 AB 卷；若无该字段则用标题兜底识别
        $courseId = (int)($exam['course_id'] ?? 0);
        $hasExamPaperColumn = false;
        try {
            $colStmt = $db->query("SHOW COLUMNS FROM exams LIKE 'exam_paper'");
            $hasExamPaperColumn = (bool)$colStmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            $hasExamPaperColumn = false;
        }

        $candidates = [];
        if ($hasExamPaperColumn) {
            $stmt = $db->prepare("SELECT id, title, exam_paper FROM exams WHERE is_active = 1 AND course_id = ? AND exam_paper IN ('A','B') ORDER BY exam_paper ASC");
            $stmt->execute([$courseId]);
            $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if (count($candidates) < 2) {
            $stmt = $db->prepare("SELECT id, title FROM exams WHERE is_active = 1 AND course_id = ? ORDER BY id ASC");
            $stmt->execute([$courseId]);
            $allCourseExams = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($allCourseExams as $row) {
                $title = (string)($row['title'] ?? '');
                if (preg_match('/A卷|（A）|\(A\)|\bA\b/u', $title)) {
                    $candidates[] = ['id' => (int)$row['id'], 'title' => $title, 'exam_paper' => 'A'];
                } elseif (preg_match('/B卷|（B）|\(B\)|\bB\b/u', $title)) {
                    $candidates[] = ['id' => (int)$row['id'], 'title' => $title, 'exam_paper' => 'B'];
                }
            }
        }

        if (count($candidates) < 2) {
            $assignment = [
                'source_exam_id' => $examId,
                'selected_exam_id' => $examId,
                'paper_code' => 'A',
                'mode' => 'single',
            ];
            saveExamAssignmentIdempotency($db, (int)$userId, $examId, $idempotencyKey, $assignment);
            jsonResponse(0, 'success', $assignment);
        }

        $choice = $candidates[random_int(0, count($candidates) - 1)];
        $assignment = [
            'source_exam_id' => $examId,
            'selected_exam_id' => (int)$choice['id'],
            'paper_code' => (string)$choice['exam_paper'],
            'mode' => 'ab_random',
        ];
        saveExamAssignmentIdempotency($db, (int)$userId, $examId, $idempotencyKey, $assignment);
        jsonResponse(0, 'success', $assignment);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(1, '不支持的请求方法');
    }

    if (!$examId) {
        jsonResponse(1, '缺少考试ID');
    }

    if ($action === 'detail') {
        $stmt = $db->prepare('SELECT id, course_id, title, description, total_score, pass_score, duration, attempt_limit, points_reward FROM exams WHERE id = ? AND is_active = 1');
        $stmt->execute([$examId]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$exam) {
            jsonResponse(1, '考试不存在');
        }

        jsonResponse(0, 'success', ['exam' => $exam]);
    }

    if ($action === 'questions') {
        $stmt = $db->prepare('SELECT id, question_type, content, options, score, sort_order FROM exam_questions WHERE exam_id = ? ORDER BY sort_order ASC, id ASC');
        $stmt->execute([$examId]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($questions as &$question) {
            $question['options'] = $question['options'] ? json_decode($question['options'], true) : [];
            if (!is_array($question['options'])) {
                $question['options'] = [];
            }
        }

        jsonResponse(0, 'success', ['questions' => $questions]);
    }

    jsonResponse(1, '不支持的操作');
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}

function getExamAssignmentIdempotencyKey(): string {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $name => $value) {
        if (strtolower((string)$name) === 'idempotency-key') {
            return mb_substr(trim((string)$value), 0, 160);
        }
    }
    return mb_substr(trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')), 0, 160);
}

function ensureExamAssignmentIdempotencyTable(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS exam_assignment_idempotency (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        source_exam_id BIGINT UNSIGNED NOT NULL,
        idempotency_key_hash CHAR(64) NOT NULL,
        response_json LONGTEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uniq_exam_assignment_idempotency (user_id, source_exam_id, idempotency_key_hash),
        KEY idx_exam_assignment_source (source_exam_id, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function findExamAssignmentIdempotency(PDO $db, int $userId, int $sourceExamId, string $idempotencyKey): ?array {
    $stmt = $db->prepare('SELECT response_json FROM exam_assignment_idempotency WHERE user_id = ? AND source_exam_id = ? AND idempotency_key_hash = ? LIMIT 1');
    $stmt->execute([$userId, $sourceExamId, hash('sha256', $idempotencyKey)]);
    $raw = $stmt->fetchColumn();
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function saveExamAssignmentIdempotency(PDO $db, int $userId, int $sourceExamId, string $idempotencyKey, array $assignment): void {
    try {
        $stmt = $db->prepare('INSERT INTO exam_assignment_idempotency (user_id, source_exam_id, idempotency_key_hash, response_json) VALUES (?, ?, ?, ?)');
        $stmt->execute([$userId, $sourceExamId, hash('sha256', $idempotencyKey), json_encode($assignment, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $error) {
        $existing = findExamAssignmentIdempotency($db, $userId, $sourceExamId, $idempotencyKey);
        if ($existing) {
            return;
        }
        throw $error;
    }
}
