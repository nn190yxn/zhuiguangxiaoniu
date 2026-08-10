<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillAiAdapter.php';
require_once __DIR__ . '/DrillEvaluationPolicy.php';
require_once __DIR__ . '/DrillEvaluationReportService.php';
require_once __DIR__ . '/DrillReviewService.php';
require_once __DIR__ . '/DrillGrowthService.php';
require_once __DIR__ . '/DrillLearningService.php';

final class DrillEvaluationService
{
    public function __construct(private PDO $pdo, private DrillAiAdapter $ai)
    {
    }

    public function evaluate(int $attemptId, int $scoreSubjectId, DateTimeImmutable $now): array
    {
        $this->pdo->beginTransaction();
        try {
            $attempt = $this->attempt($attemptId);
            $subject = $this->subject($attemptId, $scoreSubjectId);
            $rubric = $this->rubric((int) $attempt['rubric_version_id']);
            DrillEvaluationPolicy::assertRoute((string) $attempt['domain_code'], (string) $attempt['evaluation_context'], (string) $rubric['rubric_code']);
            $segments = DrillEvaluationPolicy::scoreableSegments($this->segments($attemptId), (string) $subject['participant_key'], (string) $attempt['evaluation_context']);
            $aiResult = $this->ai->evaluateAttempt(['attempt' => $attempt, 'rubric' => $rubric['snapshot'], 'score_subject' => $subject, 'segments' => $segments]);
            $payload = $aiResult['payload'];
            DrillEvaluationPolicy::validateAiEvaluation($payload, $segments, (float) $rubric['snapshot']['max_score']);
            $calculated = DrillEvaluationPolicy::score($rubric['snapshot'], $payload);
            $evaluationId = $this->upsertEvaluation($attempt, $scoreSubjectId, $calculated, $payload, $aiResult['metadata'], $now);
            $this->persistEvidence($attemptId, $evaluationId, (int) $attempt['rubric_version_id'], $payload, $segments);
            $report = (new DrillEvaluationReportService($this->pdo))->create($attemptId, $evaluationId, (string) $attempt['evaluation_context'], $calculated, $payload, $this->references($attemptId), $now);
            $referenceOnly = (string) ($payload['evidence_status'] ?? '') === 'deterministic_reference';
            $review = $referenceOnly
                ? ['status' => 'reference_completed', 'evaluation_id' => $evaluationId]
                : (new DrillReviewService($this->pdo))->routeEvaluation($evaluationId, $now);
            if (!$referenceOnly && $review['status'] === 'practice_completed') {
                (new DrillGrowthService($this->pdo))->record($attemptId, $evaluationId, (float) $calculated['total_score'], $now);
            }
            $learning = $referenceOnly
                ? ['recommendations' => []]
                : (new DrillLearningService($this->pdo))->generateRecommendationsInTransaction($attemptId, $evaluationId);
            $this->pdo->prepare("UPDATE drill_attempts SET status = 'evaluated', status_version = status_version + 1 WHERE id = ? AND status = 'evaluating'")->execute([$attemptId]);
            $this->pdo->commit();
            return ['evaluation_id' => $evaluationId, 'status' => 'completed', 'score' => $calculated, 'report' => $report, 'review' => $review, 'learning' => $learning, 'ai' => $aiResult['metadata']];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $this->markRetryPending($attemptId, $scoreSubjectId, $error);
            throw $error;
        }
    }

    private function upsertEvaluation(array $attempt, int $subjectId, array $calculated, array $payload, array $metadata, DateTimeImmutable $now): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO drill_evaluations (attempt_id, score_subject_id, rubric_version_id, calibration_version_id, evaluation_context, source, total_score, dimension_scores_json, critical_results_json, suggestions_json, provider, model, prompt_version, duration_ms, raw_response_ref, status, failure_code, completed_at) VALUES (?, ?, ?, ?, ?, 'hybrid', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', NULL, ?) ON DUPLICATE KEY UPDATE total_score = VALUES(total_score), dimension_scores_json = VALUES(dimension_scores_json), critical_results_json = VALUES(critical_results_json), suggestions_json = VALUES(suggestions_json), provider = VALUES(provider), model = VALUES(model), prompt_version = VALUES(prompt_version), duration_ms = VALUES(duration_ms), raw_response_ref = VALUES(raw_response_ref), status = 'completed', failure_code = NULL, completed_at = VALUES(completed_at)");
        $stmt->execute([(int) $attempt['id'], $subjectId, (int) $attempt['rubric_version_id'], (int) $attempt['calibration_version_id'], (string) $attempt['evaluation_context'], $calculated['total_score'], $this->json($calculated['dimension_scores']), $this->json($calculated['critical_results']), $this->json((array) ($payload['suggestions'] ?? [])), $metadata['provider'], $metadata['model'], $metadata['prompt_version'], $metadata['duration_ms'], $metadata['raw_response_ref'], $now->format('Y-m-d H:i:s')]);
        $select = $this->pdo->prepare("SELECT id FROM drill_evaluations WHERE attempt_id = ? AND score_subject_id = ? AND source = 'hybrid' AND rubric_version_id = ?");
        $select->execute([(int) $attempt['id'], $subjectId, (int) $attempt['rubric_version_id']]);
        return (int) $select->fetchColumn();
    }

    private function persistEvidence(int $attemptId, int $evaluationId, int $rubricVersionId, array $payload, array $segments): void
    {
        $byId = array_column($segments, null, 'id');
        $stmt = $this->pdo->prepare('INSERT INTO drill_evaluation_evidence (attempt_id, evaluation_id, segment_id, rubric_version_id, dimension_code, criterion_code, evidence_type, quoted_text, speaker_role, starts_ms, ends_ms, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE quoted_text = VALUES(quoted_text), status = VALUES(status)');
        foreach ((array) ($payload['evidence'] ?? []) as $item) {
            $segment = $byId[(int) $item['segment_id']];
            $stmt->execute([$attemptId, $evaluationId, (int) $segment['id'], $rubricVersionId, (string) ($item['dimension_code'] ?? ''), (string) ($item['criterion_code'] ?? ''), (string) ($item['evidence_type'] ?? 'quote'), (string) ($item['quoted_text'] ?? $segment['content']), (string) $segment['role_code'], (int) $segment['starts_ms'], (int) $segment['ends_ms'], (string) ($item['status'] ?? 'supported')]);
        }
    }

    private function markRetryPending(int $attemptId, int $subjectId, Throwable $error): void
    {
        try {
            $attempt = $this->pdo->prepare('SELECT rubric_version_id, calibration_version_id, evaluation_context FROM drill_attempts WHERE id = ?');
            $attempt->execute([$attemptId]);
            $row = $attempt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { return; }
            $stmt = $this->pdo->prepare("INSERT INTO drill_evaluations (attempt_id, score_subject_id, rubric_version_id, calibration_version_id, evaluation_context, source, status, failure_code) VALUES (?, ?, ?, ?, ?, 'hybrid', 'retry_pending', ?) ON DUPLICATE KEY UPDATE status = 'retry_pending', failure_code = VALUES(failure_code)");
            $stmt->execute([$attemptId, $subjectId, (int) $row['rubric_version_id'], (int) $row['calibration_version_id'], (string) $row['evaluation_context'], $error instanceof DrillAiRetryableException ? 'ai_retryable' : 'structure_invalid']);
        } catch (Throwable) {
        }
    }

    private function attempt(int $attemptId): array
    {
        $stmt = $this->pdo->prepare('SELECT attempt.*, domain.domain_code FROM drill_attempts attempt INNER JOIN drill_training_domains domain ON domain.id = attempt.domain_id WHERE attempt.id = ? FOR UPDATE');
        $stmt->execute([$attemptId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new DomainException('演练实例不存在。');
    }
    private function subject(int $attemptId, int $subjectId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM drill_attempt_score_subjects WHERE id = ? AND attempt_id = ? AND status = 'confirmed' FOR UPDATE");
        $stmt->execute([$subjectId, $attemptId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new DomainException('评分对象尚未确认。');
    }
    private function rubric(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT version.*, rubric.rubric_code, rubric.mode FROM drill_rubric_versions version INNER JOIN drill_rubrics rubric ON rubric.id = version.rubric_id WHERE version.id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new DomainException('评分规则不存在。');
        $row['snapshot'] = ['mode' => $row['mode'], 'dimensions' => json_decode((string) $row['dimensions_json'], true) ?: [], 'critical_items' => json_decode((string) $row['critical_items_json'], true) ?: [], 'score_policy' => json_decode((string) $row['score_policy_json'], true) ?: [], 'max_score' => (float) $row['max_score']];
        return $row;
    }
    private function segments(int $attemptId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM drill_transcript_segments WHERE attempt_id = ? AND mapping_status IN ('mapped', 'confirmed') ORDER BY starts_ms, id");
        $stmt->execute([$attemptId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    private function references(int $attemptId): array
    {
        $stmt = $this->pdo->prepare('SELECT material_version_id, purpose_code, content_hash, binding_snapshot_json FROM drill_attempt_reference_bindings WHERE attempt_id = ?');
        $stmt->execute([$attemptId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
}
