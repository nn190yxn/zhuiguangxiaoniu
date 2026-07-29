<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillGrowthPolicy.php';

final class DrillGrowthService
{
    public function __construct(private PDO $pdo) {}

    public function record(int $attemptId, int $evaluationId, float $score, DateTimeImmutable $now): array
    {
        $managed = !$this->pdo->inTransaction();
        if ($managed) $this->pdo->beginTransaction();
        try {
            $attempt = $this->pdo->prepare('SELECT attempt.*, rubric.rubric_id FROM drill_attempts attempt INNER JOIN drill_rubric_versions rubric ON rubric.id = attempt.rubric_version_id WHERE attempt.id = ? FOR UPDATE');
            $attempt->execute([$attemptId]);
            $row = $attempt->fetch(PDO::FETCH_ASSOC) ?: throw new DomainException('演练实例不存在。');
            $scopeType = $row['practice_type'] === 'full_process' ? 'full_process' : 'required_section';
            $scopeKey = $scopeType === 'full_process' ? 'full_process' : (string) $row['current_stage_id'];
            if ($scopeType === 'required_section' && $scopeKey === '') throw new DomainException('板块成绩缺少板块范围。');
            $stmt = $this->pdo->prepare('INSERT INTO drill_mastery_scores (staff_id, domain_id, scope_type, scope_key, rubric_id, rubric_version_id, latest_attempt_id, latest_score, latest_scored_at, best_attempt_id, effective_best_score, best_scored_at, attempt_count) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1) ON DUPLICATE KEY UPDATE latest_attempt_id = VALUES(latest_attempt_id), latest_score = VALUES(latest_score), latest_scored_at = VALUES(latest_scored_at), best_attempt_id = IF(VALUES(latest_score) > effective_best_score, VALUES(latest_attempt_id), best_attempt_id), effective_best_score = GREATEST(effective_best_score, VALUES(latest_score)), best_scored_at = IF(VALUES(latest_score) > effective_best_score, VALUES(latest_scored_at), best_scored_at), attempt_count = attempt_count + 1');
            $time = $now->format('Y-m-d H:i:s');
            $stmt->execute([(int) $row['staff_id'], (int) $row['domain_id'], $scopeType, $scopeKey, (int) $row['rubric_id'], (int) $row['rubric_version_id'], $attemptId, $score, $time, $attemptId, $score, $time]);
            $growth = $this->refresh((int) $row['staff_id'], (int) $row['domain_id'], (int) $row['rubric_id'], (int) $row['rubric_version_id'], $now);
            if ($managed) $this->pdo->commit();
            return ['evaluation_id' => $evaluationId, 'scope_type' => $scopeType, 'scope_key' => $scopeKey, 'growth' => $growth];
        } catch (Throwable $error) { if ($managed && $this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; }
    }

    public function markRuleUpgraded(int $domainId, int $rubricVersionId, DateTimeImmutable $now): void
    {
        $this->pdo->prepare("UPDATE drill_growth_level_snapshots SET status = 'historical', superseded_at = ? WHERE domain_id = ? AND rubric_version_id <> ? AND status = 'current' AND superseded_at IS NULL")->execute([$now->format('Y-m-d H:i:s'), $domainId, $rubricVersionId]);
    }

    private function refresh(int $staffId, int $domainId, int $rubricId, int $rubricVersionId, DateTimeImmutable $now): array
    {
        $required = $this->pdo->prepare("SELECT mastery.effective_best_score FROM drill_mastery_scores mastery INNER JOIN drill_process_stages stage ON stage.id = CAST(mastery.scope_key AS UNSIGNED) WHERE mastery.staff_id = ? AND mastery.domain_id = ? AND mastery.rubric_version_id = ? AND mastery.scope_type = 'required_section' AND stage.required = 1");
        $required->execute([$staffId, $domainId, $rubricVersionId]);
        $scores = array_map('floatval', $required->fetchAll(PDO::FETCH_COLUMN));
        $full = $this->pdo->prepare("SELECT effective_best_score FROM drill_mastery_scores WHERE staff_id = ? AND domain_id = ? AND rubric_version_id = ? AND scope_type = 'full_process' AND scope_key = 'full_process'");
        $full->execute([$staffId, $domainId, $rubricVersionId]);
        $value = $full->fetchColumn();
        $level = DrillGrowthPolicy::level($scores, $value === false ? null : (float) $value);
        $this->pdo->prepare("UPDATE drill_growth_level_snapshots SET status = 'historical', superseded_at = ? WHERE staff_id = ? AND domain_id = ? AND status IN ('current', 'reassessment_pending') AND superseded_at IS NULL")->execute([$now->format('Y-m-d H:i:s'), $staffId, $domainId]);
        if ($level['status'] === 'reassessment_pending') return $level;
        $stmt = $this->pdo->prepare("INSERT INTO drill_growth_level_snapshots (staff_id, domain_id, rubric_id, rubric_version_id, level_code, level_floor_score, level_score, required_section_min_score, full_process_score, required_sections_passed, required_sections_total, qualification_status, status, score_snapshot_json, calculated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'current', ?, ?)");
        $stmt->execute([$staffId, $domainId, $rubricId, $rubricVersionId, $level['level_code'], $level['level_floor_score'], $level['level_score'], $level['required_section_min_score'], $level['full_process_score'], $level['required_sections_passed'], $level['required_sections_total'], $level['qualification_status'], json_encode(['required_scores' => $scores, 'full_process_score' => $value], JSON_THROW_ON_ERROR), $now->format('Y-m-d H:i:s')]);
        return $level;
    }
}
