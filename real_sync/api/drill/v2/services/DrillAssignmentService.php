<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillAssignmentStateMachine.php';
require_once __DIR__ . '/DrillPlanPolicy.php';
require_once __DIR__ . '/DrillPrerequisiteFactsResolver.php';

final class DrillAssignmentService
{
    private DrillPrerequisiteFactsResolver $prerequisiteFactsResolver;

    public function __construct(private PDO $pdo, ?DrillPrerequisiteFactsResolver $prerequisiteFactsResolver = null)
    {
        $this->prerequisiteFactsResolver = $prerequisiteFactsResolver ?? new DrillPrerequisiteFactsResolver($pdo);
    }

    public function transition(int $assignmentId, string $event, int $expectedVersion, DateTimeImmutable $now): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT assignment.*, prerequisite.evaluation_status, plan_snapshot.snapshot_json AS plan_snapshot_json FROM drill_assignments assignment '
                . 'LEFT JOIN drill_publication_snapshots plan_snapshot ON plan_snapshot.publication_id = assignment.publication_id '
                . "AND plan_snapshot.snapshot_type = 'plan' AND plan_snapshot.snapshot_key = 'plan' "
                . 'LEFT JOIN drill_assignment_prerequisite_snapshots prerequisite ON prerequisite.id = '
                . '(SELECT latest.id FROM drill_assignment_prerequisite_snapshots latest WHERE latest.assignment_id = assignment.id ORDER BY latest.id DESC LIMIT 1) '
                . 'WHERE assignment.id = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$assignmentId]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$assignment) {
                throw new DomainException('员工训练任务不存在。');
            }
            if ((int) $assignment['status_version'] !== $expectedVersion) {
                throw new DomainException('员工训练任务状态已更新，请刷新后重试。');
            }
            if (in_array($event, ['start', 'retry', 'reopen'], true)) {
                DrillAssignmentStateMachine::assertStartable(
                    (string) $assignment['status'],
                    new DateTimeImmutable((string) $assignment['starts_at']),
                    new DateTimeImmutable((string) $assignment['due_at']),
                    $now,
                    ($assignment['evaluation_status'] ?? 'blocked') === 'eligible'
                );
            }
            $nextStatus = DrillAssignmentStateMachine::transition((string) $assignment['status'], $event);
            $completedAt = $nextStatus === 'passed' ? $now->format('Y-m-d H:i:s') : null;
            $failedIncrement = $event === 'ai_fail' ? 1 : 0;
            $maximumFailedAttempts = $this->maximumFailedAttempts($assignment['plan_snapshot_json'] ?? null);
            if (
                $event === 'ai_fail'
                && $maximumFailedAttempts !== null
                && (int) $assignment['failed_attempts'] + 1 >= $maximumFailedAttempts
            ) {
                $nextStatus = 'coaching_required';
            }
            $update = $this->pdo->prepare(
                'UPDATE drill_assignments SET status = ?, failed_attempts = failed_attempts + ?, completed_at = ?, status_version = status_version + 1 WHERE id = ? AND status_version = ?'
            );
            $update->execute([$nextStatus, $failedIncrement, $completedAt, $assignmentId, $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('员工训练任务发生并发更新。');
            }
            $this->pdo->prepare(
                'INSERT INTO drill_audit_logs (action, object_type, object_id, before_snapshot_json, after_snapshot_json) VALUES (?, ?, ?, ?, ?)'
            )->execute([
                'assignment.' . $event,
                'drill_assignment',
                $assignmentId,
                json_encode(['status' => $assignment['status'], 'status_version' => $expectedVersion], JSON_THROW_ON_ERROR),
                json_encode(['status' => $nextStatus, 'status_version' => $expectedVersion + 1], JSON_THROW_ON_ERROR),
            ]);
            $this->pdo->commit();
            return ['assignment_id' => $assignmentId, 'status' => $nextStatus, 'status_version' => $expectedVersion + 1];
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function refreshPrerequisites(int $assignmentId, int $actorStaffId): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'SELECT assignment.id, assignment.staff_id, plan.domain_id, '
                . 'COALESCE(prerequisite.policy_snapshot_json, '
                . "JSON_UNQUOTE(JSON_EXTRACT(plan_snapshot.snapshot_json, '$.prerequisite_policy_json')), "
                . 'plan.prerequisite_policy_json, JSON_ARRAY()) AS policy_snapshot_json '
                . 'FROM drill_assignments assignment '
                . 'INNER JOIN drill_plan_publications publication ON publication.id = assignment.publication_id '
                . 'INNER JOIN drill_plans plan ON plan.id = publication.plan_id '
                . 'LEFT JOIN drill_publication_snapshots plan_snapshot ON plan_snapshot.publication_id = publication.id '
                . "AND plan_snapshot.snapshot_type = 'plan' AND plan_snapshot.snapshot_key = 'plan' "
                . 'LEFT JOIN drill_assignment_prerequisite_snapshots prerequisite ON prerequisite.id = '
                . '(SELECT latest.id FROM drill_assignment_prerequisite_snapshots latest WHERE latest.assignment_id = assignment.id ORDER BY latest.id DESC LIMIT 1) '
                . 'WHERE assignment.id = ? LIMIT 1 FOR UPDATE'
            );
            $stmt->execute([$assignmentId]);
            $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$assignment) {
                throw new DomainException('员工训练任务不存在。');
            }
            $policy = json_decode((string) $assignment['policy_snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
            $policy = is_array($policy) ? $policy : [];
            $facts = $this->prerequisiteFactsResolver->resolve((int) $assignment['staff_id'], (int) $assignment['domain_id'], $policy);
            $evaluation = DrillPlanPolicy::evaluatePrerequisites($policy, $facts);
            $insert = $this->pdo->prepare(
                'INSERT INTO drill_assignment_prerequisite_snapshots (assignment_id, evaluation_status, policy_hash, policy_snapshot_json, evaluation_result_json) VALUES (?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $assignmentId,
                $evaluation['eligible'] ? 'eligible' : 'blocked',
                DrillPlanPolicy::snapshotHash($policy),
                json_encode($policy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode($evaluation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $this->pdo->prepare(
                'INSERT INTO drill_audit_logs (actor_staff_id, action, object_type, object_id, after_snapshot_json) VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $actorStaffId,
                'assignment.prerequisites_refreshed',
                'drill_assignment',
                $assignmentId,
                json_encode($evaluation, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            $this->pdo->commit();
            return ['assignment_id' => $assignmentId] + $evaluation;
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function enqueueDueReminders(DateTimeImmutable $now, DateInterval $leadTime): int
    {
        $availableUntil = $now->add($leadTime);
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO drill_notifications (notification_key, recipient_staff_id, notification_type, object_type, object_id, channel, payload_json) SELECT CONCAT('drill-assignment-due:', assignment.id), assignment.staff_id, 'drill_assignment_due', 'drill_assignment', assignment.id, 'in_app', JSON_OBJECT('assignment_id', assignment.id, 'due_at', assignment.due_at) FROM drill_assignments assignment WHERE assignment.status IN ('assigned', 'in_progress', 'retry_available', 'coaching_required') AND assignment.due_at > ? AND assignment.due_at <= ?"
        );
        $stmt->execute([$now->format('Y-m-d H:i:s'), $availableUntil->format('Y-m-d H:i:s')]);
        return $stmt->rowCount();
    }

    private function maximumFailedAttempts(mixed $planSnapshotJson): ?int
    {
        if (!is_string($planSnapshotJson) || $planSnapshotJson === '') {
            return null;
        }
        $plan = json_decode($planSnapshotJson, true, 512, JSON_THROW_ON_ERROR);
        $passPolicy = json_decode((string) ($plan['pass_policy_json'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR);
        return isset($passPolicy['maximum_failed_attempts']) ? (int) $passPolicy['maximum_failed_attempts'] : null;
    }
}
