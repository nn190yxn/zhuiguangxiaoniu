<?php

declare(strict_types=1);

final class ResumeGradeService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function grade(int $applicationId, int $processingVersionId, array $evidence, array $rule): array
    {
        $result = $this->calculate($evidence, $rule);
        $rawScore = $result['raw_score'];
        $totalScore = $result['total_score'];
        $systemGrade = $result['system_grade'];
        $queueStatus = $result['queue_status'];
        $archiveReason = $result['archive_reason'];
        $reason = $result['reason'];
        $aMin = $result['a_min'];
        $bMin = $result['b_min'];
        $unknownCount = $result['unknown_count'];

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO recruitment_grade_results (processing_version_id, application_id, raw_score, total_score, system_grade, score_adjustment_reason, grade_snapshot_json) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE raw_score = VALUES(raw_score), total_score = VALUES(total_score), system_grade = VALUES(system_grade), score_adjustment_reason = VALUES(score_adjustment_reason), grade_snapshot_json = VALUES(grade_snapshot_json)'
            );
            $snapshot = ['evidence' => $evidence, 'a_min' => $aMin, 'b_min' => $bMin, 'unknown_count' => $unknownCount];
            $stmt->execute([$processingVersionId, $applicationId, $rawScore, $totalScore, $systemGrade, $reason ?: null, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            $update = $this->pdo->prepare(
                'UPDATE recruitment_applications SET system_grade = ?, effective_grade = COALESCE(manual_grade, ?), raw_score = ?, total_score = ?, score_adjustment_reason = ?, queue_status = ?, archive_reason = ?, archived_at = CASE WHEN ? = \'review_archive\' THEN COALESCE(archived_at, NOW()) ELSE NULL END WHERE id = ?'
            );
            $update->execute([$systemGrade, $systemGrade, $rawScore, $totalScore, $reason ?: null, $queueStatus, $archiveReason, $queueStatus, $applicationId]);
            $this->pdo->commit();
            return ['raw_score' => $rawScore, 'total_score' => $totalScore, 'system_grade' => $systemGrade, 'queue_status' => $queueStatus, 'reason' => $reason];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function calculate(array $evidence, array $rule): array
    {
        $gradeRules = json_decode((string) ($rule['grade_rules_json'] ?? '[]'), true) ?: [];
        $aMin = (float) ($gradeRules['A']['min'] ?? 80);
        $bMin = (float) ($gradeRules['B']['min'] ?? 60);
        $rawScore = min(100.0, max(0.0, array_sum(array_map(static fn (array $item): float => (float) ($item['score'] ?? 0), $evidence))));
        $totalScore = $rawScore;
        $reasons = [];
        $hardUnmatched = $this->countStatus($evidence, 'hard_condition', 'unmatched') > 0;
        $unknownCount = $this->countUnknown($evidence);
        $experienceReady = $this->allMatched($evidence, 'experience');
        $keywordsReady = $this->allMatched($evidence, 'keyword');
        if ($hardUnmatched) {
            $totalScore = min($totalScore, max(0.0, $bMin - 1));
            $reasons[] = '合法硬性条件未满足';
        }
        if ($unknownCount > 0) {
            $totalScore = min($totalScore, max($bMin, $aMin - 1));
            $reasons[] = '关键信息需人工确认';
        }
        if ($totalScore >= $aMin && (!$experienceReady || !$keywordsReady)) {
            $totalScore = max($bMin, $aMin - 1);
            $reasons[] = 'A 级经验与关键词双门槛尚未同时满足';
        }
        $systemGrade = $totalScore >= $aMin && !$hardUnmatched && $experienceReady && $keywordsReady
            ? 'A'
            : ($totalScore >= $bMin && !$hardUnmatched ? 'B' : 'C');
        $queueStatus = in_array($systemGrade, ['A', 'B'], true) ? 'appointment' : 'review_archive';
        $archiveReason = $systemGrade === 'C' ? 'grade_c' : null;
        $reason = implode('；', array_values(array_unique($reasons)));

        return [
            'raw_score' => $rawScore,
            'total_score' => $totalScore,
            'system_grade' => $systemGrade,
            'queue_status' => $queueStatus,
            'archive_reason' => $archiveReason,
            'reason' => $reason,
            'a_min' => $aMin,
            'b_min' => $bMin,
            'unknown_count' => $unknownCount,
        ];
    }

    private function countStatus(array $evidence, string $type, string $status): int
    {
        return count(array_filter($evidence, static fn (array $item): bool => $item['dimension_type'] === $type && $item['match_status'] === $status));
    }

    private function countUnknown(array $evidence): int
    {
        return count(array_filter($evidence, static fn (array $item): bool => in_array($item['match_status'], ['unknown', 'manual_check'], true)));
    }

    private function allMatched(array $evidence, string $type): bool
    {
        $items = array_values(array_filter($evidence, static fn (array $item): bool => $item['dimension_type'] === $type));
        return $items === [] || count(array_filter($items, static fn (array $item): bool => $item['match_status'] === 'matched')) === count($items);
    }
}
