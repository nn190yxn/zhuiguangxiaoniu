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
        return trim($value);
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    return (string)$value;
}

/**
 * Score text question by keyword matching.
 * Uses ONLY per-question keywords extracted from the question's own analysis field.
 * Returns score (0 to maxScore) based on keyword coverage.
 *
 * Analysis field format:
 *   "评分要点：...；关键词：key1,key2,key3"
 * If "关键词：" prefix exists, those keywords are used exclusively.
 * Otherwise, meaningful terms are extracted from the analysis text.
 */
function score_text_question($questionId, $userAnswer, $analysis, $maxScore) {
    if (!$userAnswer || !trim($userAnswer)) {
        return 0;
    }

    $userAnswer = trim($userAnswer);
    $keywords = [];

    // Step 1: Try to extract keywords from "关键词：" section
    if ($analysis && preg_match('/关键词[：:]\s*([\x{4e00}-\x{9fff}a-zA-Z0-9%\-，、,.\s]+)/u', $analysis, $match)) {
        $keywordStr = trim($match[1]);
        // Split by common separators: comma, enumeration comma, space
        $raw = preg_split('/[，、,\s]+/u', $keywordStr, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($raw as $kw) {
            $kw = trim($kw);
            if (mb_strlen($kw) >= 1 && mb_strlen($kw) <= 20) {
                $keywords[] = $kw;
            }
        }
    }

    // Step 2: If no keywords extracted, extract meaningful terms from analysis
    if (empty($keywords) && $analysis) {
        preg_match_all('/[\x{4e00}-\x{9fff}]{2,8}/u', $analysis, $matches);
        $extracted = array_filter($matches[0], function($kw) {
            return mb_strlen($kw) >= 2;
        });
        $keywords = array_unique(array_values($extracted));
    }

    // Remove duplicates
    $keywords = array_unique($keywords);

    if (empty($keywords)) {
        return 0;
    }

    // Step 3: Count matched keywords (only from this question's keywords)
    $matchedCount = 0;
    foreach ($keywords as $kw) {
        if (mb_strpos($userAnswer, $kw) !== false) {
            $matchedCount++;
        }
    }

    $totalKeywords = count($keywords);

    // Step 4: Score calculation with reasonable thresholds
    // >= 60% matched: full score
    // >= 30% matched: 70% of max score
    // > 0 matched: 30% of max score
    // 0 matched: 0
    $ratio = $matchedCount / max($totalKeywords, 1);
    if ($ratio >= 0.6) {
        return $maxScore;
    } elseif ($ratio >= 0.3) {
        return (int)round($maxScore * 0.7);
    } elseif ($matchedCount > 0) {
        return (int)round($maxScore * 0.3);
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
    $answers = isset($data['answers']) && is_array($data['answers']) ? $data['answers'] : [];
    $timeSpent = isset($data['time_spent']) ? (int)$data['time_spent'] : 0;

    if (!$examId) {
        jsonResponse(1, '缺少考试ID');
    }

    // Get exam info
    $stmt = $db->prepare('SELECT * FROM exams WHERE id = ? AND is_active = 1');
    $stmt->execute([$examId]);
    $exam = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$exam) {
        jsonResponse(1, '考试不存在');
    }

    // 防重复提交：检查用户是否已提交过此考试
    $checkStmt = $db->prepare('SELECT COUNT(*) FROM exam_records WHERE user_id = ? AND module_id = ? AND exam_type = \'course_exam\' AND status = \'completed\'');
    $checkStmt->execute([$userId, $examId]);
    if ((int)$checkStmt->fetchColumn() > 0) {
        jsonResponse(1, '您已提交过此考试，不可重复提交');
    }

    // 防作弊：校验最短答题时间（每题至少10秒）
    $stmt = $db->prepare('SELECT COUNT(*) FROM exam_questions WHERE exam_id = ?');
    $stmt->execute([$examId]);
    $questionCount = (int)$stmt->fetchColumn();
    $minTime = $questionCount * 10;
    if ($timeSpent > 0 && $timeSpent < $minTime) {
        error_log("Exam cheat detected: user $userId, exam $examId, time $timeSpent < $minTime");
        jsonResponse(1, '答题时间过短，请认真作答');
    }

    // Get all questions with full details
    $stmt = $db->prepare('SELECT id, question_type, answer, score, analysis FROM exam_questions WHERE exam_id = ? ORDER BY sort_order');
    $stmt->execute([$examId]);
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
            $earnedScore = score_text_question($question['id'], $userAnswer, $question['analysis'], $qScore);
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

    // Save exam record
    $stmt = $db->prepare("INSERT INTO exam_records (user_id, module_id, exam_type, total_score, passing_score, is_passed, answers, wrong_answers, duration, status, completed_at) VALUES (?, ?, 'course_exam', ?, ?, ?, ?, ?, ?, 'completed', NOW())");
    $stmt->execute([
        $userId,
        $examId,
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
    error_log('Exam submit error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误，请稍后重试');
}
