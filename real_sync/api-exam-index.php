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
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(1, '不支持的请求方法');
    }

    $db = getDB();
    $action = isset($_GET['action']) ? trim($_GET['action']) : 'detail';
    $examId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($action === 'assign') {
        if (!$examId) {
            jsonResponse(1, '缺少考试ID');
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
            jsonResponse(0, 'success', [
                'source_exam_id' => $examId,
                'selected_exam_id' => $examId,
                'paper_code' => 'A',
                'mode' => 'single',
            ]);
        }

        $choice = $candidates[random_int(0, count($candidates) - 1)];
        jsonResponse(0, 'success', [
            'source_exam_id' => $examId,
            'selected_exam_id' => (int)$choice['id'],
            'paper_code' => (string)$choice['exam_paper'],
            'mode' => 'ab_random',
        ]);
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
