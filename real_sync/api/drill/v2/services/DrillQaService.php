<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillAiAdapter.php';

final class DrillQaService
{
    private PDO $pdo;
    private DrillAiAdapter $ai;

    public function __construct(PDO $pdo, DrillAiAdapter $ai)
    {
        $this->pdo = $pdo;
        $this->ai = $ai;
    }

    public function catalog(): array
    {
        $rows = $this->pdo->query(
            'SELECT s.section_code, s.section_name, s.sort_order, COUNT(q.id) AS question_count '
            . 'FROM drill_qa_sections s '
            . 'LEFT JOIN drill_qa_questions q ON q.section_id = s.id AND q.status = \'active\' '
            . 'WHERE s.status = \'active\' '
            . 'GROUP BY s.id, s.section_code, s.section_name, s.sort_order '
            . 'ORDER BY s.sort_order ASC, s.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        $sections = [];
        $total = 0;
        foreach ($rows as $row) {
            $count = (int) $row['question_count'];
            $total += $count;
            $sections[] = [
                'code' => (string) $row['section_code'],
                'name' => (string) $row['section_name'],
                'question_count' => $count,
            ];
        }

        return [
            'sections' => $sections,
            'total_questions' => $total,
            'default_counts' => [5, 10, 20],
        ];
    }

    public function createSession(int $staffId, string $sectionCode, int $questionCount, DateTimeImmutable $now): array
    {
        $sectionCode = trim($sectionCode);
        $sectionId = null;
        if ($sectionCode !== '' && $sectionCode !== 'all') {
            $stmt = $this->pdo->prepare(
                'SELECT id, section_code, section_name FROM drill_qa_sections '
                . 'WHERE section_code = ? AND status = \'active\' LIMIT 1'
            );
            $stmt->execute([$sectionCode]);
            $section = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$section) {
                throw new DomainException('所选篇目不存在或已停用。');
            }
            $sectionId = (int) $section['id'];
        }

        $sql = 'SELECT id, question_no, question, reference_answer FROM drill_qa_questions WHERE status = \'active\'';
        $params = [];
        if ($sectionId !== null) {
            $sql .= ' AND section_id = ?';
            $params[] = $sectionId;
        }
        $sql .= ' ORDER BY RAND()';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $questions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($questions === []) {
            throw new DomainException('题库暂无题目，请先联系管理员导入题库。');
        }

        $count = min(max(1, $questionCount), 50, count($questions));
        $chosen = array_slice($questions, 0, $count);
        $questionIds = array_map(static fn(array $question): int => (int) $question['id'], $chosen);

        $insert = $this->pdo->prepare(
            'INSERT INTO drill_qa_sessions (staff_id, section_id, question_count, question_ids_json, current_index, status, started_at) '
            . 'VALUES (?, ?, ?, ?, 0, \'active\', ?)'
        );
        $insert->execute([
            $staffId,
            $sectionId,
            $count,
            json_encode($questionIds, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $now->format('Y-m-d H:i:s'),
        ]);
        $sessionId = (int) $this->pdo->lastInsertId();

        $session = [
            'session_id' => $sessionId,
            'question_count' => $count,
            'current_index' => 0,
            'status' => 'active',
        ];

        return [
            'session' => $session,
            'question' => $this->questionView($chosen[0]),
        ];
    }

    public function sessionState(int $staffId, int $sessionId): array
    {
        $session = $this->loadSession($staffId, $sessionId);
        $state = $this->sessionView($session);
        $currentQuestion = null;
        if ($session['status'] === 'active') {
            $currentQuestion = $this->currentQuestion($session);
        }
        if ($currentQuestion !== null) {
            $state['question'] = $currentQuestion;
        }
        return $state;
    }

    public function submitAnswer(int $staffId, int $sessionId, string $answer, DateTimeImmutable $now): array
    {
        $answer = trim($answer);
        if ($answer === '') {
            throw new InvalidArgumentException('回答内容不能为空。');
        }
        if (mb_strlen($answer) > 5000) {
            throw new InvalidArgumentException('回答内容过长，请精简后提交。');
        }

        $this->pdo->beginTransaction();
        try {
            $session = $this->loadSessionForUpdate($staffId, $sessionId);
            if ($session['status'] !== 'active') {
                throw new DomainException('本次 Q&A 已结束，无法继续作答。');
            }
            if ((int) $session['current_index'] >= (int) $session['question_count']) {
                throw new DomainException('本次 Q&A 已完成全部题目。');
            }

            $question = $this->currentQuestion($session);
            $question['section_name'] = $this->sectionName((int) $session['section_id']);
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }

        $score = $this->ai->scoreQaAnswer([
            'section_name' => $question['section_name'],
            'question' => $question['question'],
            'reference_answer' => $question['reference_answer'],
            'staff_answer' => $answer,
        ]);
        $payload = $score['payload'];

        $this->pdo->beginTransaction();
        try {
            $session = $this->loadSessionForUpdate($staffId, $sessionId);
            if ($session['status'] !== 'active') {
                throw new DomainException('本次 Q&A 已结束，无法继续作答。');
            }
            $currentIndex = (int) $session['current_index'];
            $questionIds = json_decode((string) $session['question_ids_json'], true);
            $questionId = is_array($questionIds) ? (int) ($questionIds[$currentIndex] ?? 0) : 0;

            $insert = $this->pdo->prepare(
                'INSERT INTO drill_qa_answers (session_id, question_id, question_no, question, staff_answer, score, dimension_scores_json, ai_feedback, ai_metadata_json, created_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $sessionId,
                $questionId,
                (int) $question['question_no'],
                $question['question'],
                $answer,
                (float) ($payload['total_score'] ?? 0),
                json_encode($payload['dimension_scores'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (string) ($payload['feedback'] ?? ''),
                json_encode($score['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $now->format('Y-m-d H:i:s'),
            ]);

            $newIndex = $currentIndex + 1;
            $isCompleted = $newIndex >= (int) $session['question_count'];
            if ($isCompleted) {
                $avgScore = $this->averageScore((int) $session['id']);
                $levelName = self::levelName($avgScore);
                $update = $this->pdo->prepare(
                    'UPDATE drill_qa_sessions SET current_index = ?, status = \'completed\', total_score = ?, level_name = ?, completed_at = ? '
                    . 'WHERE id = ?'
                );
                $update->execute([$newIndex, $avgScore, $levelName, $now->format('Y-m-d H:i:s'), $sessionId]);
            } else {
                $update = $this->pdo->prepare(
                    'UPDATE drill_qa_sessions SET current_index = ? WHERE id = ?'
                );
                $update->execute([$newIndex, $sessionId]);
            }
            $this->pdo->commit();
        } catch (Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }

        $session = $this->loadSession($staffId, $sessionId);
        $state = $this->sessionView($session);
        if ($state['status'] === 'active') {
            $state['question'] = $this->currentQuestion($session);
        }

        return [
            'answer' => [
                'question_no' => (int) $question['question_no'],
                'score' => (float) ($payload['total_score'] ?? 0),
                'feedback' => (string) ($payload['feedback'] ?? ''),
            ],
            'score_result' => [
                'total_score' => (float) ($payload['total_score'] ?? 0),
                'dimension_scores' => $this->normalizeDimensionScores($payload['dimension_scores'] ?? []),
                'feedback' => (string) ($payload['feedback'] ?? ''),
                'suggestions' => array_values(array_filter((array) ($payload['suggestions'] ?? []), 'is_string')),
                'reference_highlights' => array_values(array_filter((array) ($payload['reference_highlights'] ?? []), 'is_string')),
                'reference_answer' => $question['reference_answer'],
            ],
            'session' => $state,
        ];
    }

    public function history(int $staffId, int $limit = 20): array
    {
        $limit = min(max(1, $limit), 50);
        $stmt = $this->pdo->prepare(
            'SELECT s.id, s.section_id, s.question_count, s.current_index, s.status, s.total_score, s.level_name, s.started_at, s.completed_at, '
            . 'sec.section_name '
            . 'FROM drill_qa_sessions s '
            . 'LEFT JOIN drill_qa_sections sec ON sec.id = s.section_id '
            . 'WHERE s.staff_id = ? '
            . 'ORDER BY s.id DESC '
            . 'LIMIT ' . $limit
        );
        $stmt->execute([$staffId]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = [
                'session_id' => (int) $row['id'],
                'section_name' => (string) ($row['section_name'] ?: '综合随机'),
                'question_count' => (int) $row['question_count'],
                'answered_count' => (int) $row['current_index'],
                'status' => (string) $row['status'],
                'total_score' => $row['total_score'] === null ? null : (float) $row['total_score'],
                'level_name' => (string) ($row['level_name'] ?? ''),
                'started_at' => (string) $row['started_at'],
                'completed_at' => $row['completed_at'] === null ? null : (string) $row['completed_at'],
            ];
        }
        return ['items' => $items];
    }

    public function detail(int $staffId, int $sessionId): array
    {
        $session = $this->loadSession($staffId, $sessionId);
        $state = $this->sessionView($session);

        $stmt = $this->pdo->prepare(
            'SELECT question_no, question, staff_answer, score, dimension_scores_json, ai_feedback, created_at '
            . 'FROM drill_qa_answers WHERE session_id = ? ORDER BY id ASC'
        );
        $stmt->execute([$sessionId]);
        $answers = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dims = json_decode((string) ($row['dimension_scores_json'] ?? '[]'), true);
            $answers[] = [
                'question_no' => (int) $row['question_no'],
                'question' => (string) $row['question'],
                'staff_answer' => (string) $row['staff_answer'],
                'score' => (float) $row['score'],
                'dimension_scores' => $this->normalizeDimensionScores(is_array($dims) ? $dims : []),
                'feedback' => (string) ($row['ai_feedback'] ?? ''),
                'created_at' => (string) $row['created_at'],
            ];
        }
        $state['answers'] = $answers;
        return $state;
    }

    private function loadSession(int $staffId, int $sessionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_qa_sessions WHERE id = ? AND staff_id = ? LIMIT 1');
        $stmt->execute([$sessionId, $staffId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new DomainException('Q&A 练习记录不存在。');
        }
        return $session;
    }

    private function loadSessionForUpdate(int $staffId, int $sessionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_qa_sessions WHERE id = ? AND staff_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$sessionId, $staffId]);
        $session = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$session) {
            throw new DomainException('Q&A 练习记录不存在。');
        }
        return $session;
    }

    private function sessionView(array $session): array
    {
        return [
            'session_id' => (int) $session['id'],
            'section_id' => $session['section_id'] === null ? null : (int) $session['section_id'],
            'section_name' => $this->sectionName((int) $session['section_id']),
            'question_count' => (int) $session['question_count'],
            'answered_count' => (int) $session['current_index'],
            'status' => (string) $session['status'],
            'total_score' => $session['total_score'] === null ? null : (float) $session['total_score'],
            'level_name' => (string) ($session['level_name'] ?? ''),
            'started_at' => (string) $session['started_at'],
            'completed_at' => $session['completed_at'] === null ? null : (string) $session['completed_at'],
        ];
    }

    private function currentQuestion(array $session): array
    {
        $questionIds = json_decode((string) $session['question_ids_json'], true);
        $index = (int) $session['current_index'];
        $questionId = is_array($questionIds) ? (int) ($questionIds[$index] ?? 0) : 0;
        if ($questionId <= 0) {
            throw new DomainException('题目数据异常，请联系管理员。');
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, question_no, question, reference_answer FROM drill_qa_questions WHERE id = ? AND status = \'active\' LIMIT 1'
        );
        $stmt->execute([$questionId]);
        $question = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$question) {
            throw new DomainException('当前题目不存在或已停用。');
        }
        return $question;
    }

    private function questionView(array $question): array
    {
        return [
            'question_id' => (int) $question['id'],
            'question_no' => (int) $question['question_no'],
            'question' => (string) $question['question'],
        ];
    }

    private function sectionName(int $sectionId): string
    {
        if ($sectionId <= 0) {
            return '综合随机';
        }
        static $cache = [];
        if (!isset($cache[$sectionId])) {
            $stmt = $this->pdo->prepare('SELECT section_name FROM drill_qa_sections WHERE id = ? LIMIT 1');
            $stmt->execute([$sectionId]);
            $name = (string) $stmt->fetchColumn();
            $cache[$sectionId] = $name !== '' ? $name : '';
        }
        return $cache[$sectionId] !== '' ? $cache[$sectionId] : '综合随机';
    }

    private function averageScore(int $sessionId): float
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(AVG(score), 0) FROM drill_qa_answers WHERE session_id = ?');
        $stmt->execute([$sessionId]);
        return (float) $stmt->fetchColumn();
    }

    private function normalizeDimensionScores(array $raw): array
    {
        $labels = [
            'keyword_coverage' => '核心关键词覆盖',
            'concept_coverage' => '核心概念覆盖',
            'accuracy' => '答案准确性',
            'completeness' => '答案完整性',
        ];
        $result = [];
        foreach ($labels as $code => $label) {
            $item = $raw[$code] ?? [];
            $score = isset($item['score']) ? (float) $item['score'] : 0.0;
            $result[] = [
                'code' => $code,
                'label' => $label,
                'score' => $score,
                'level' => (string) ($item['level'] ?? ''),
                'evidence' => (string) ($item['evidence'] ?? ''),
            ];
        }
        return $result;
    }

    public static function levelName(float $score): string
    {
        if ($score >= 90) {
            return '优秀';
        }
        if ($score >= 75) {
            return '良好';
        }
        if ($score >= 60) {
            return '合格';
        }
        return '待提升';
    }
}
