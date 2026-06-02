<?php
/**
 * Exam submit API with keyword matching for text questions.
 * Supports: choice, judge, and text (short answer/scenario) questions.
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

function normalize_exam_answer($value) {
    if (is_array($value)) {
        sort($value);
        return json_encode($value, JSON_UNESCAPED_UNICODE);
    }
    if (is_string($value)) {
        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            sort($decoded);
            return json_encode($decoded, JSON_UNESCAPED_UNICODE);
        }
        if (is_string($decoded)) { return trim($decoded); }
        return trim($value);
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    return (string)$value;
}

/**
 * Score text question by keyword matching.
 * Returns score (0 to maxScore) based on keyword coverage.
 */
function score_text_question($userAnswer, $analysis, $maxScore) {
    if (!$userAnswer || !trim($userAnswer)) {
        return 0;
    }

    // Extract keywords from analysis field
    // Format: "评分要点：xxx；关键词：A、B、C、D"
    $keywords = [];
    if (preg_match('/关键词[：:]\s*(.+)/u', $analysis, $matches)) {
        $kwStr = trim($matches[1]);
        // Split by Chinese comma, enumeration comma, space, or semicolon
        $keywords = preg_split('/[、，,;\s]+/u', $kwStr, -1, PREG_SPLIT_NO_EMPTY);
        $keywords = array_map('trim', $keywords);
        $keywords = array_filter($keywords, function($k) { return mb_strlen($k) >= 1; });
    }

    if (empty($keywords)) {
        // Fallback: return partial credit if answer is non-empty
        return round($maxScore * 0.3);
    }

    // Match keywords in user answer
    $matchedCount = 0;
    foreach ($keywords as $kw) {
        if (mb_strpos($userAnswer, $kw) !== false) {
            $matchedCount++;
        }
    }

    // Score based on match ratio
    $totalKeywords = count($keywords);
    $ratio = $matchedCount / max($totalKeywords, 1);

    if ($ratio >= 0.6) {
        return $maxScore; // Full credit
    } elseif ($ratio >= 0.3) {
        return round($maxScore * 0.7); // 70% credit
    } elseif ($ratio > 0) {
        return round($maxScore * 0.3); // 30% credit
    }

    return 0;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(1, '不支持的请求方法');
    }

    $db = getDB();
    $userId = getCurrentUserId();
    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data)) {
        $data = $_POST;
    }

    $examId = isset($data['exam_id']) ? (int)$data['exam_id'] : 0;
    $sourceExamId = isset($data['source_exam_id']) ? (int)$data['source_exam_id'] : $examId;
    $selectedExamId = isset($data['selected_exam_id']) ? (int)$data['selected_exam_id'] : $examId;
    $paperCode = strtoupper(trim((string)($data['paper_code'] ?? '')));
    $answers = isset($data['answers']) && is_array($data['answers']) ? $data['answers'] : [];
    $timeSpent = isset($data['time_spent']) ? (int)$data['time_spent'] : 0;

    if (!$examId) {
        jsonResponse(1, '缺少考试ID');
    }

    // Get selected paper exam info
    $stmt = $db->prepare('SELECT * FROM exams WHERE id = ? AND is_active = 1');
    $stmt->execute([$selectedExamId]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam) {
        jsonResponse(1, '考试不存在');
    }

    // Get all questions with full details
    $stmt = $db->prepare('SELECT id, question_type, answer, score, analysis FROM exam_questions WHERE exam_id = ? ORDER BY sort_order');
    $stmt->execute([$selectedExamId]);
    $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $score = 0;
    $correctCount = 0;
    $wrongAnswers = [];
    $questionResults = [];

    foreach ($questions as $question) {
        $qid = (string)$question['id'];
        $userAnswer = $answers[$qid] ?? ($answers[$question['id']] ?? null);
        $qScore = (int)$question['score'];
        $earnedScore = 0;
        $isCorrect = false;

        if ($question['question_type'] === 4) {
            // Text question (short answer / scenario) - use keyword matching
            $earnedScore = score_text_question($userAnswer, $question['analysis'], $qScore);
            $isCorrect = $earnedScore > 0;
        } else {
            // Choice or judge question - exact match
            $expected = normalize_exam_answer($question['answer']);
            $actual = normalize_exam_answer($userAnswer);
            $isCorrect = ($actual !== '' && $actual === $expected);
            $earnedScore = $isCorrect ? $qScore : 0;
        }

        $score += $earnedScore;
        if ($isCorrect) {
            $correctCount++;
        } else {
            $wrongAnswers[] = [
                'question_id' => (int)$question['id'],
                'answer' => $userAnswer,
                'correct_answer' => $question['answer'],
                'earned_score' => $earnedScore,
                'max_score' => $qScore
            ];
        }

        $questionResults[] = [
            'question_id' => (int)$question['id'],
            'question_type' => (int)$question['question_type'],
            'earned_score' => $earnedScore,
            'max_score' => $qScore,
            'is_correct' => $isCorrect
        ];
    }

    $totalQuestions = count($questions);
    $wrongCount = count($wrongAnswers);
    $isPassed = $score >= (int)$exam['pass_score'];

    // Delete any in_progress records for this source exam
    $db->prepare("DELETE FROM exam_records WHERE user_id = ? AND exam_type = 'course_exam' AND status = 'in_progress' AND module_id = ?")->execute([$userId, $sourceExamId]);

    if (!is_array($answers)) {
        $answers = [];
    }
    $answers['__meta'] = [
        'source_exam_id' => $sourceExamId,
        'selected_exam_id' => $selectedExamId,
        'paper_code' => in_array($paperCode, ['A', 'B'], true) ? $paperCode : ((string)($exam['exam_paper'] ?? 'A') ?: 'A'),
    ];

    // Save exam record
    $stmt = $db->prepare("INSERT INTO exam_records (user_id, module_id, exam_type, total_score, passing_score, is_passed, answers, wrong_answers, duration, status, completed_at) VALUES (?, ?, 'course_exam', ?, ?, ?, ?, ?, ?, 'completed', NOW())");
    $stmt->execute([
        $userId,
        $sourceExamId,
        $score,
        (int)$exam['pass_score'],
        $isPassed ? 1 : 0,
        json_encode($answers, JSON_UNESCAPED_UNICODE),
        json_encode($wrongAnswers, JSON_UNESCAPED_UNICODE),
        $timeSpent
    ]);

    jsonResponse(0, 'success', [
        'score' => $score,
        'pass_score' => (int)$exam['pass_score'],
        'is_passed' => $isPassed,
        'correct_count' => $correctCount,
        'wrong_count' => $wrongCount,
        'total_count' => $totalQuestions,
        'question_results' => $questionResults
    ]);
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
