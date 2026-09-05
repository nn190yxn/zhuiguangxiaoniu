<?php
declare(strict_types=1);

final class ExamSubmissionService
{
    public function __construct(private PDO $db)
    {
    }

    public function submit(int $userId, array $input): array
    {
        $sourceExamId = (int) $input['source_exam_id'];
        $selectedExamId = (int) $input['selected_exam_id'];
        $paperCode = (string) $input['paper_code'];
        $answers = $input['answers'];
        $timeSpent = (int) $input['time_spent'];

        $stmt = $this->db->prepare('SELECT * FROM exams WHERE id = ? AND is_active = 1');
        $stmt->execute([$selectedExamId]);
        $exam = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$exam) {
            throw new PlatformApiException(422, 'exam_not_found', '考试不存在');
        }

        $stmt = $this->db->prepare(
            'SELECT id, question_type, answer, score, analysis FROM exam_questions '
            . 'WHERE exam_id = ? ORDER BY sort_order'
        );
        $stmt->execute([$selectedExamId]);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $score = 0;
        $correctCount = 0;
        $wrongAnswers = [];
        $questionResults = [];

        foreach ($questions as $question) {
            $qid = (string) $question['id'];
            $userAnswer = $answers[$qid] ?? ($answers[$question['id']] ?? null);
            $maxScore = (int) $question['score'];
            $earnedScore = 0;
            $isCorrect = false;

            if ((int) $question['question_type'] === 4) {
                $earnedScore = self::scoreTextQuestion($userAnswer, (string) $question['analysis'], $maxScore);
                $isCorrect = $earnedScore > 0;
            } else {
                $expected = self::normalizeAnswer($question['answer']);
                $actual = self::normalizeAnswer($userAnswer);
                $isCorrect = $actual !== '' && $actual === $expected;
                $earnedScore = $isCorrect ? $maxScore : 0;
            }

            $score += $earnedScore;
            if ($isCorrect) {
                $correctCount++;
            } else {
                $wrongAnswers[] = [
                    'question_id' => (int) $question['id'],
                    'answer' => $userAnswer,
                    'correct_answer' => $question['answer'],
                    'earned_score' => $earnedScore,
                    'max_score' => $maxScore,
                ];
            }

            $questionResults[] = [
                'question_id' => (int) $question['id'],
                'question_type' => (int) $question['question_type'],
                'earned_score' => $earnedScore,
                'max_score' => $maxScore,
                'is_correct' => $isCorrect,
            ];
        }

        $totalQuestions = count($questions);
        $isPassed = $score >= (int) $exam['pass_score'];

        $this->db->prepare(
            "DELETE FROM exam_records WHERE user_id = ? AND exam_type = 'course_exam' "
            . "AND status = 'in_progress' AND module_id = ?"
        )->execute([$userId, $sourceExamId]);

        $answers['__meta'] = [
            'source_exam_id' => $sourceExamId,
            'selected_exam_id' => $selectedExamId,
            'paper_code' => in_array($paperCode, ['A', 'B'], true)
                ? $paperCode
                : ((string) ($exam['exam_paper'] ?? 'A') ?: 'A'),
        ];

        $stmt = $this->db->prepare(
            "INSERT INTO exam_records (user_id, module_id, exam_type, total_score, passing_score, "
            . 'is_passed, answers, wrong_answers, duration, status, completed_at) '
            . "VALUES (?, ?, 'course_exam', ?, ?, ?, ?, ?, ?, 'completed', NOW())"
        );
        $stmt->execute([
            $userId,
            $sourceExamId,
            $score,
            (int) $exam['pass_score'],
            $isPassed ? 1 : 0,
            json_encode($answers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            json_encode($wrongAnswers, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $timeSpent,
        ]);

        return [
            'score' => $score,
            'pass_score' => (int) $exam['pass_score'],
            'is_passed' => $isPassed,
            'correct_count' => $correctCount,
            'wrong_count' => count($wrongAnswers),
            'total_count' => $totalQuestions,
            'question_results' => $questionResults,
            'exam_record_id' => (int) $this->db->lastInsertId(),
        ];
    }

    private static function normalizeAnswer(mixed $value): string
    {
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
            if (is_string($decoded)) {
                return trim($decoded);
            }
            return trim($value);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }

    private static function scoreTextQuestion(mixed $userAnswer, string $analysis, int $maxScore): int
    {
        if (!$userAnswer || !trim((string) $userAnswer)) {
            return 0;
        }
        $trimmedAnswer = trim((string) $userAnswer);
        if (mb_strlen($trimmedAnswer) < 6) {
            return 0;
        }

        $keywords = [];
        if (preg_match('/关键词[：:]\s*(.+)/u', $analysis, $matches)) {
            $keywords = preg_split('/[、，,;\s]+/u', trim($matches[1]), -1, PREG_SPLIT_NO_EMPTY);
            $keywords = array_filter(array_map('trim', $keywords), static fn(string $keyword): bool => mb_strlen($keyword) >= 1);
        }
        if ($keywords === []) {
            return (int) round($maxScore * 0.4);
        }

        $matchedCount = 0;
        foreach ($keywords as $keyword) {
            if (mb_strpos((string) $userAnswer, $keyword) !== false) {
                $matchedCount++;
            }
        }
        $ratio = $matchedCount / max(count($keywords), 1);
        if ($ratio <= 0) {
            return (int) round($maxScore * 0.4);
        }
        if ($ratio >= 0.6) {
            return $maxScore;
        }
        if ($ratio >= 0.3) {
            return (int) round($maxScore * 0.7);
        }
        return (int) round($maxScore * 0.4);
    }
}
