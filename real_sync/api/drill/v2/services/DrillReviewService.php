<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillReviewPolicy.php';
require_once __DIR__ . '/DrillGrowthService.php';

final class DrillReviewService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function routeEvaluation(int $evaluationId, DateTimeImmutable $now): array
    {
        return $this->transaction(function () use ($evaluationId, $now): array {
            $row = $this->evaluation($evaluationId);
            if ($row['assignment_id'] === null) {
                return ['status' => 'practice_completed', 'evaluation_id' => $evaluationId];
            }
            $policy = $this->decode((string) $row['pass_policy_json']);
            $critical = $this->decode((string) $row['critical_results_json']);
            $result = DrillReviewPolicy::passResult((float) $row['total_score'], $critical, $policy);
            if (!$result['passed']) {
                return $this->failAssignment($row, $result, $now);
            }
            $reviewerId = $this->reviewer((int) $row['publication_id']);
            $this->pdo->prepare("UPDATE drill_assignments SET status = 'awaiting_review', status_version = status_version + 1 WHERE id = ? AND status = 'ai_evaluating'")->execute([(int) $row['assignment_id']]);
            $insert = $this->pdo->prepare("INSERT INTO drill_review_tasks (assignment_id, attempt_id, evaluation_id, reviewer_staff_id, status, ai_score, review_snapshot_json) VALUES (?, ?, ?, ?, 'pending', ?, ?)");
            $insert->execute([(int) $row['assignment_id'], (int) $row['attempt_id'], $evaluationId, $reviewerId, (float) $row['total_score'], $this->json(['pass_result' => $result, 'ai_critical_results' => $critical])]);
            $reviewId = (int) $this->pdo->lastInsertId();
            $this->notify($reviewerId, 'drill-review:' . $reviewId, 'drill_review', 'drill_review_task', $reviewId, ['assignment_id' => (int) $row['assignment_id'], 'attempt_id' => (int) $row['attempt_id']]);
            return ['status' => 'awaiting_review', 'review_task_id' => $reviewId, 'pass_result' => $result];
        });
    }

    public function evidence(int $reviewTaskId): array
    {
        $task = $this->pdo->prepare('SELECT task.*, evaluation.dimension_scores_json, evaluation.critical_results_json, evaluation.suggestions_json FROM drill_review_tasks task INNER JOIN drill_evaluations evaluation ON evaluation.id = task.evaluation_id AND evaluation.attempt_id = task.attempt_id WHERE task.id = ?');
        $task->execute([$reviewTaskId]);
        $result = $task->fetch(PDO::FETCH_ASSOC) ?: throw new DomainException('复核任务不存在。');
        $segments = $this->pdo->prepare('SELECT segment.*, evidence.dimension_code, evidence.criterion_code, evidence.evidence_type, evidence.quoted_text FROM drill_evaluation_evidence evidence INNER JOIN drill_transcript_segments segment ON segment.id = evidence.segment_id AND segment.attempt_id = evidence.attempt_id WHERE evidence.evaluation_id = ? ORDER BY segment.starts_ms, segment.id');
        $segments->execute([(int) $result['evaluation_id']]);
        $history = $this->pdo->prepare('SELECT id, status, failed_attempts, created_at, completed_at FROM drill_assignments WHERE id = ?');
        $history->execute([(int) $result['assignment_id']]);
        return ['review' => $result, 'evidence' => $segments->fetchAll(PDO::FETCH_ASSOC), 'assignment_history' => $history->fetchAll(PDO::FETCH_ASSOC)];
    }

    public function complete(int $reviewTaskId, int $reviewerId, string $decision, float $finalScore, string $comment, string $adjustmentReason, DateTimeImmutable $now): array
    {
        return $this->transaction(function () use ($reviewTaskId, $reviewerId, $decision, $finalScore, $comment, $adjustmentReason, $now): array {
            $row = $this->review($reviewTaskId, $reviewerId);
            $critical = $this->decode((string) $row['critical_results_json']);
            $policy = $this->decode((string) $row['pass_policy_json']);
            $pass = DrillReviewPolicy::assertReviewDecision($decision, (float) $row['ai_score'], $finalScore, $critical, $policy, $adjustmentReason);
            $update = $this->pdo->prepare("UPDATE drill_review_tasks SET status = 'completed', decision = ?, comment = ?, final_score = ?, score_adjustment_json = ?, adjustment_reason = ?, reviewed_at = ? WHERE id = ? AND status = 'pending'");
            $update->execute([$decision, $comment, $finalScore, $this->json(['ai_score' => (float) $row['ai_score'], 'manual_score' => $finalScore, 'delta' => round($finalScore - (float) $row['ai_score'], 2)]), $adjustmentReason ?: null, $now->format('Y-m-d H:i:s'), $reviewTaskId]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('复核任务已处理。');
            }
            if ($decision === 'passed') {
                $this->pdo->prepare("UPDATE drill_assignments SET status = 'passed', completed_at = ?, status_version = status_version + 1 WHERE id = ? AND status = 'awaiting_review'")->execute([$now->format('Y-m-d H:i:s'), (int) $row['assignment_id']]);
                $certificationId = $this->certify($row, $finalScore, $critical, $adjustmentReason, $now);
                (new DrillGrowthService($this->pdo))->record((int) $row['attempt_id'], (int) $row['evaluation_id'], $finalScore, $now);
                return ['status' => 'passed', 'certification_id' => $certificationId, 'pass_result' => $pass];
            }
            $status = $decision === 'coaching_required' ? 'coaching_required' : 'retry_available';
            $this->pdo->prepare('UPDATE drill_assignments SET status = ?, status_version = status_version + 1 WHERE id = ? AND status = ?')->execute([$status, (int) $row['assignment_id'], 'awaiting_review']);
            return ['status' => $status, 'pass_result' => $pass];
        });
    }

    public function reassign(int $reviewTaskId, int $actorId, int $reviewerId): void
    {
        $this->transaction(function () use ($reviewTaskId, $actorId, $reviewerId): void {
            $task = $this->pdo->prepare('SELECT task.*, assignment.publication_id FROM drill_review_tasks task INNER JOIN drill_assignments assignment ON assignment.id = task.assignment_id WHERE task.id = ? FOR UPDATE');
            $task->execute([$reviewTaskId]);
            $row = $task->fetch(PDO::FETCH_ASSOC) ?: throw new DomainException('复核任务不存在。');
            if ($row['status'] !== 'pending' || !$this->reviewerIsActive((int) $row['publication_id'], $reviewerId)) {
                throw new DomainException('新复核人当前不可用。');
            }
            $this->pdo->prepare('UPDATE drill_review_tasks SET reviewer_staff_id = ? WHERE id = ?')->execute([$reviewerId, $reviewTaskId]);
            $this->audit($actorId, 'review.reassigned', 'drill_review_task', $reviewTaskId, ['reviewer_staff_id' => $row['reviewer_staff_id']], ['reviewer_staff_id' => $reviewerId]);
        });
    }

    private function failAssignment(array $row, array $result, DateTimeImmutable $now): array
    {
        $nextFailures = (int) $row['failed_attempts'] + 1;
        $status = $nextFailures >= 3 ? 'coaching_required' : 'retry_available';
        $this->pdo->prepare('UPDATE drill_assignments SET status = ?, failed_attempts = ?, status_version = status_version + 1 WHERE id = ? AND status = ?')->execute([$status, $nextFailures, (int) $row['assignment_id'], 'ai_evaluating']);
        if ($status === 'coaching_required') {
            $coach = $this->reviewer((int) $row['publication_id']);
            $this->pdo->prepare("INSERT INTO drill_coaching_tasks (assignment_id, coach_staff_id, trigger_attempt_id, status, failure_count_snapshot) VALUES (?, ?, ?, 'open', ?) ON DUPLICATE KEY UPDATE trigger_attempt_id = VALUES(trigger_attempt_id), failure_count_snapshot = VALUES(failure_count_snapshot)")->execute([(int) $row['assignment_id'], $coach, (int) $row['attempt_id'], $nextFailures]);
        }
        return ['status' => $status, 'failed_attempts' => $nextFailures, 'pass_result' => $result, 'evaluated_at' => $now->format(DATE_ATOM)];
    }

    private function certify(array $row, float $finalScore, array $critical, string $adjustmentReason, DateTimeImmutable $now): int
    {
        $snapshot = ['ai' => ['score' => (float) $row['ai_score'], 'critical_results' => $critical], 'manual' => ['score' => $finalScore, 'adjustment_reason' => $adjustmentReason], 'final' => ['score' => $finalScore, 'decision' => 'passed', 'reviewer_staff_id' => (int) $row['reviewer_staff_id']]];
        $stmt = $this->pdo->prepare('INSERT INTO drill_certifications (certification_no, assignment_id, attempt_id, review_task_id, evaluation_id, plan_id, staff_id, reviewer_staff_id, ai_score, final_score, critical_results_json, result_snapshot_json, ai_snapshot_json, manual_adjustment_json, final_snapshot_json, certified_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute(['DRILL-' . (int) $row['assignment_id'] . '-' . (int) $row['attempt_id'], (int) $row['assignment_id'], (int) $row['attempt_id'], (int) $row['id'], (int) $row['evaluation_id'], (int) $row['plan_id'], (int) $row['staff_id'], (int) $row['reviewer_staff_id'], (float) $row['ai_score'], $finalScore, $this->json($critical), $this->json($snapshot), $this->json($snapshot['ai']), $this->json($snapshot['manual']), $this->json($snapshot['final']), $now->format('Y-m-d H:i:s')]);
        return (int) $this->pdo->lastInsertId();
    }

    private function evaluation(int $id): array { $stmt = $this->pdo->prepare("SELECT evaluation.*, attempt.assignment_id, assignment.publication_id, assignment.failed_attempts, plan.pass_policy_json FROM drill_evaluations evaluation INNER JOIN drill_attempts attempt ON attempt.id = evaluation.attempt_id LEFT JOIN drill_assignments assignment ON assignment.id = attempt.assignment_id LEFT JOIN drill_plan_publications publication ON publication.id = assignment.publication_id LEFT JOIN drill_plans plan ON plan.id = publication.plan_id WHERE evaluation.id = ? AND evaluation.status = 'completed' FOR UPDATE"); $stmt->execute([$id]); return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new DomainException('完整评分不存在。'); }
    private function review(int $id, int $reviewerId): array { $stmt = $this->pdo->prepare("SELECT task.*, evaluation.critical_results_json, plan.pass_policy_json, plan.id AS plan_id, assignment.staff_id FROM drill_review_tasks task INNER JOIN drill_evaluations evaluation ON evaluation.id = task.evaluation_id INNER JOIN drill_assignments assignment ON assignment.id = task.assignment_id INNER JOIN drill_plan_publications publication ON publication.id = assignment.publication_id INNER JOIN drill_plans plan ON plan.id = publication.plan_id WHERE task.id = ? AND task.reviewer_staff_id = ? FOR UPDATE"); $stmt->execute([$id, $reviewerId]); return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new DomainException('复核任务不可处理。'); }
    private function reviewer(int $publicationId): int { $stmt = $this->pdo->prepare("SELECT reviewer_staff_id FROM drill_publication_reviewers WHERE publication_id = ? AND status = 'active' ORDER BY priority, id LIMIT 1"); $stmt->execute([$publicationId]); $id = (int) $stmt->fetchColumn(); if ($id <= 0) throw new DomainException('任务缺少有效复核人。'); return $id; }
    private function reviewerIsActive(int $publicationId, int $reviewerId): bool { $stmt = $this->pdo->prepare("SELECT 1 FROM drill_publication_reviewers WHERE publication_id = ? AND reviewer_staff_id = ? AND status = 'active'"); $stmt->execute([$publicationId, $reviewerId]); return (bool) $stmt->fetchColumn(); }
    private function notify(int $recipient, string $key, string $type, string $objectType, int $objectId, array $payload): void { $this->pdo->prepare('INSERT IGNORE INTO drill_notifications (notification_key, recipient_staff_id, notification_type, object_type, object_id, channel, payload_json) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$key, $recipient, $type, $objectType, $objectId, 'in_app', $this->json($payload)]); }
    private function audit(int $actor, string $action, string $type, int $id, array $before, array $after): void { $this->pdo->prepare('INSERT INTO drill_audit_logs (actor_staff_id, action, object_type, object_id, before_snapshot_json, after_snapshot_json) VALUES (?, ?, ?, ?, ?, ?)')->execute([$actor, $action, $type, $id, $this->json($before), $this->json($after)]); }
    private function decode(string $json): array { $value = json_decode($json, true); return is_array($value) ? $value : []; }
    private function json(array $value): string { return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR); }
    private function transaction(callable $callback): mixed { $managed = !$this->pdo->inTransaction(); if ($managed) $this->pdo->beginTransaction(); try { $result = $callback(); if ($managed) $this->pdo->commit(); return $result; } catch (Throwable $error) { if ($managed && $this->pdo->inTransaction()) $this->pdo->rollBack(); throw $error; } }
}
