<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillAttemptStateMachine.php';
require_once __DIR__ . '/DrillConversationPolicy.php';
require_once __DIR__ . '/DrillPlanPolicy.php';

final class DrillConversationService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createFromAssignment(
        int $assignmentId,
        int $staffId,
        int $planItemId,
        array $sessionGoal,
        DateTimeImmutable $now
    ): array {
        return $this->transaction(function () use ($assignmentId, $staffId, $planItemId, $sessionGoal, $now): array {
            $assignment = $this->lockAssignment($assignmentId, $staffId);
            $item = $this->lockPlanItem($planItemId, (int) $assignment['plan_id']);
            $snapshots = $this->publicationSnapshots((int) $assignment['publication_id']);
            $process = $this->snapshot($snapshots, 'process', 'process:' . (int) $assignment['process_version_id']);
            $scenario = $this->snapshot($snapshots, 'scenario', 'scenario:' . (int) $item['scenario_version_id']);
            $rubric = $this->snapshot($snapshots, 'rubric', 'rubric:' . (int) $item['rubric_version_id']);
            $calibration = $this->snapshotByType($snapshots, 'calibration');
            $practiceType = (string) ($assignment['plan_type'] === 'comprehensive_certification' ? 'full_process' : 'required');
            $evaluationContext = (string) $item['evaluation_context'];
            DrillConversationPolicy::assertAttemptDefinition($practiceType, $evaluationContext);

            $stageId = $this->initialStageId((int) $assignment['process_version_id'], (int) $item['scenario_version_id'], $practiceType);
            $attemptId = $this->insertAttempt([
                'assignment_id' => $assignmentId,
                'plan_id' => (int) $assignment['plan_id'],
                'plan_item_id' => $planItemId,
                'staff_id' => $staffId,
                'domain_id' => (int) $assignment['domain_id'],
                'process_version_id' => (int) $assignment['process_version_id'],
                'scenario_version_id' => (int) $item['scenario_version_id'],
                'rubric_version_id' => (int) $item['rubric_version_id'],
                'calibration_version_id' => (int) $calibration['version_id'],
                'practice_type' => $practiceType,
                'evaluation_context' => $evaluationContext,
                'persona_snapshot_json' => $scenario['snapshot']['personas'] ?? [],
                'process_snapshot_json' => $process['snapshot'],
                'scenario_snapshot_json' => $scenario['snapshot'],
                'rubric_snapshot_json' => $rubric['snapshot'],
                'calibration_snapshot_json' => $calibration['snapshot'],
                'session_goal_json' => $sessionGoal,
                'current_stage_id' => $stageId,
                'started_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $this->initializeStageProgress($attemptId, (int) $assignment['process_version_id'], $stageId, $now);
            $this->bindReferenceMaterials($attemptId, $planItemId, $snapshots);

            $updated = $this->pdo->prepare(
                "UPDATE drill_assignments SET status = IF(status = 'assigned', 'in_progress', status), current_attempt_id = ?, status_version = status_version + 1 WHERE id = ?"
            );
            $updated->execute([$attemptId, $assignmentId]);

            return $this->resumeAttempt($attemptId, $staffId);
        });
    }

    public function createPractice(
        int $staffId,
        string $practiceType,
        string $evaluationContext,
        array $definition,
        array $snapshots,
        array $sessionGoal,
        DateTimeImmutable $now
    ): array {
        DrillConversationPolicy::assertAttemptDefinition($practiceType, $evaluationContext);
        return $this->transaction(function () use ($staffId, $practiceType, $evaluationContext, $definition, $snapshots, $sessionGoal, $now): array {
            $domainId = (int) ($definition['domain_id'] ?? 0);
            $processVersionId = (int) ($definition['process_version_id'] ?? 0);
            $scenarioVersionId = (int) ($definition['scenario_version_id'] ?? 0);
            $rubricVersionId = (int) ($definition['rubric_version_id'] ?? 0);
            $calibrationVersionId = (int) ($definition['calibration_version_id'] ?? 0);
            if ($domainId <= 0 || $processVersionId <= 0 || $scenarioVersionId <= 0 || $rubricVersionId <= 0 || $calibrationVersionId <= 0) {
                throw new DomainException('演练实例版本定义不完整。');
            }
            $this->lockVersionDefinition($domainId, $processVersionId, $scenarioVersionId, $rubricVersionId, $calibrationVersionId);
            $stageId = $this->initialStageId($processVersionId, $scenarioVersionId, $practiceType);
            $attemptId = $this->insertAttempt([
                'assignment_id' => null,
                'plan_id' => null,
                'plan_item_id' => null,
                'staff_id' => $staffId,
                'domain_id' => $domainId,
                'process_version_id' => $processVersionId,
                'scenario_version_id' => $scenarioVersionId,
                'rubric_version_id' => $rubricVersionId,
                'calibration_version_id' => $calibrationVersionId,
                'practice_type' => $practiceType,
                'evaluation_context' => $evaluationContext,
                'persona_snapshot_json' => $snapshots['persona'] ?? [],
                'process_snapshot_json' => $snapshots['process'] ?? [],
                'scenario_snapshot_json' => $snapshots['scenario'] ?? [],
                'rubric_snapshot_json' => $snapshots['rubric'] ?? [],
                'calibration_snapshot_json' => $snapshots['calibration'] ?? [],
                'session_goal_json' => $sessionGoal,
                'current_stage_id' => $stageId,
                'started_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $this->initializeStageProgress($attemptId, $processVersionId, $stageId, $now);
            return $this->resumeAttempt($attemptId, $staffId);
        });
    }

    public function resumeAttempt(int $attemptId, int $staffId): array
    {
        $attempt = $this->fetchOwnedAttempt($attemptId, $staffId);
        return [
            'attempt' => $this->normalizeAttempt($attempt),
            'stage_progress' => $this->stageProgress($attemptId),
            'turns' => $this->completedTurns($attemptId),
        ];
    }

    public function submitTextTurn(
        int $attemptId,
        int $staffId,
        int $expectedVersion,
        string $employeeContent,
        string $customerContent,
        array $generationMetadata,
        DateTimeImmutable $now
    ): array {
        $employeeContent = trim($employeeContent);
        $customerContent = trim($customerContent);
        if ($employeeContent === '' || $customerContent === '') {
            throw new DomainException('员工回答和客户回应均不能为空。');
        }

        $this->pdo->beginTransaction();
        try {
            $attempt = $this->ownedAttempt($attemptId, $staffId);
            if ((int) $attempt['status_version'] !== $expectedVersion) {
                throw new DomainException('演练实例状态已更新，请恢复最新进度后重试。');
            }
            $finalizingStatus = DrillAttemptStateMachine::transition((string) $attempt['status'], 'begin_turn');
            $maximum = $this->pdo->prepare('SELECT COALESCE(MAX(turn_no), 0) FROM drill_turns WHERE attempt_id = ? FOR UPDATE');
            $maximum->execute([$attemptId]);
            [$employeeTurnNo, $customerTurnNo] = DrillConversationPolicy::nextTurnNumbers(
                (int) $attempt['last_completed_turn_no'],
                (int) $maximum->fetchColumn()
            );
            $this->pdo->prepare('UPDATE drill_attempts SET status = ? WHERE id = ?')->execute([$finalizingStatus, $attemptId]);

            $insert = $this->pdo->prepare(
                'INSERT INTO drill_turns (attempt_id, turn_no, stage_id, speaker, input_type, content, transcription_status, generation_metadata_json, finalized_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $timestamp = $now->format('Y-m-d H:i:s');
            $insert->execute([$attemptId, $employeeTurnNo, $attempt['current_stage_id'], 'employee', 'text', $employeeContent, 'not_required', null, $timestamp]);
            $insert->execute([
                $attemptId,
                $customerTurnNo,
                $attempt['current_stage_id'],
                'customer',
                'generated',
                $customerContent,
                'not_required',
                $this->json($generationMetadata),
                $timestamp,
            ]);

            $activeStatus = DrillAttemptStateMachine::transition($finalizingStatus, 'complete_turn');
            $update = $this->pdo->prepare(
                'UPDATE drill_attempts SET status = ?, last_completed_turn_no = ?, status_version = status_version + 1 '
                . 'WHERE id = ? AND status_version = ?'
            );
            $update->execute([$activeStatus, $customerTurnNo, $attemptId, $expectedVersion]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('演练轮次发生并发更新。');
            }
            $this->pdo->commit();
            return [
                'attempt_id' => $attemptId,
                'status' => $activeStatus,
                'status_version' => $expectedVersion + 1,
                'last_completed_turn_no' => $customerTurnNo,
                'employee_turn' => ['turn_no' => $employeeTurnNo, 'content' => $employeeContent],
                'customer_turn' => ['turn_no' => $customerTurnNo, 'content' => $customerContent, 'generation_metadata' => $generationMetadata],
            ];
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function advanceStage(int $attemptId, int $staffId, int $expectedVersion, DateTimeImmutable $now): array
    {
        return $this->transaction(function () use ($attemptId, $staffId, $expectedVersion, $now): array {
            $attempt = $this->ownedAttempt($attemptId, $staffId);
            if ((int) $attempt['status_version'] !== $expectedVersion) {
                throw new DomainException('演练实例状态已更新，请恢复最新进度后重试。');
            }
            if ((string) $attempt['practice_type'] !== 'full_process') {
                throw new DomainException('只有完整流程演练可以切换板块。');
            }
            $progress = $this->stageProgress($attemptId, true);
            $next = DrillConversationPolicy::nextStage($progress);
            $timestamp = $now->format('Y-m-d H:i:s');
            $this->pdo->prepare("UPDATE drill_attempt_stage_progress SET status = 'completed', completed_at = ? WHERE id = ?")
                ->execute([$timestamp, (int) $next['current']['id']]);
            $this->pdo->prepare("UPDATE drill_attempt_stage_progress SET status = 'active', started_at = ? WHERE id = ?")
                ->execute([$timestamp, (int) $next['next']['id']]);
            $updated = $this->pdo->prepare(
                'UPDATE drill_attempts SET current_stage_id = ?, status_version = status_version + 1 WHERE id = ? AND status_version = ?'
            );
            $updated->execute([(int) $next['next']['stage_id'], $attemptId, $expectedVersion]);
            if ($updated->rowCount() !== 1) {
                throw new DomainException('演练板块发生并发更新。');
            }
            return [
                'attempt_id' => $attemptId,
                'status' => $attempt['status'],
                'status_version' => $expectedVersion + 1,
                'current_stage_id' => (int) $next['next']['stage_id'],
                'stage_progress' => $this->stageProgress($attemptId),
            ];
        });
    }

    public function pauseAttempt(int $attemptId, int $staffId, int $expectedVersion): array
    {
        return $this->transitionAttempt($attemptId, $staffId, $expectedVersion, 'pause');
    }

    public function resumePausedAttempt(int $attemptId, int $staffId, int $expectedVersion): array
    {
        return $this->transitionAttempt($attemptId, $staffId, $expectedVersion, 'resume');
    }

    public function endAttempt(int $attemptId, int $staffId, int $expectedVersion, DateTimeImmutable $now): array
    {
        return $this->transaction(function () use ($attemptId, $staffId, $expectedVersion, $now): array {
            $attempt = $this->ownedAttempt($attemptId, $staffId);
            if ((int) $attempt['status_version'] !== $expectedVersion) {
                throw new DomainException('演练实例状态已更新，请恢复最新进度后重试。');
            }
            if (DrillAttemptStateMachine::isEndReplay((string) $attempt['status'])) {
                return [
                    'attempt_id' => $attemptId,
                    'status' => $attempt['status'],
                    'status_version' => $expectedVersion,
                    'idempotent_replay' => true,
                ];
            }
            $nextStatus = DrillAttemptStateMachine::transition((string) $attempt['status'], 'end');
            $updated = $this->pdo->prepare(
                'UPDATE drill_attempts SET status = ?, completed_at = ?, status_version = status_version + 1 WHERE id = ? AND status_version = ?'
            );
            $updated->execute([$nextStatus, $now->format('Y-m-d H:i:s'), $attemptId, $expectedVersion]);
            if ($updated->rowCount() !== 1) {
                throw new DomainException('演练结束发生并发更新。');
            }
            return ['attempt_id' => $attemptId, 'status' => $nextStatus, 'status_version' => $expectedVersion + 1];
        });
    }

    private function lockAssignment(int $assignmentId, int $staffId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT assignment.*, publication.plan_id, publication.id AS publication_id, plan.domain_id, plan.process_version_id, plan.plan_type '
            . 'FROM drill_assignments assignment '
            . 'INNER JOIN drill_plan_publications publication ON publication.id = assignment.publication_id '
            . 'INNER JOIN drill_plans plan ON plan.id = publication.plan_id '
            . 'WHERE assignment.id = ? AND assignment.staff_id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$assignmentId, $staffId]);
        $assignment = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$assignment) {
            throw new DomainException('员工训练任务不存在或不属于当前员工。');
        }
        if (!in_array((string) $assignment['status'], ['assigned', 'in_progress', 'retry_available', 'coaching_required'], true)) {
            throw new DomainException('当前训练任务不能创建演练实例。');
        }
        return $assignment;
    }

    private function lockPlanItem(int $planItemId, int $planId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_plan_items WHERE id = ? AND plan_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$planItemId, $planId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new DomainException('训练计划场景不存在。');
        }
        return $item;
    }

    private function publicationSnapshots(int $publicationId): array
    {
        $stmt = $this->pdo->prepare('SELECT snapshot_type, snapshot_key, version_id, content_hash, snapshot_json FROM drill_publication_snapshots WHERE publication_id = ? FOR UPDATE');
        $stmt->execute([$publicationId]);
        $snapshots = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $snapshots[(string) $row['snapshot_type']][(string) $row['snapshot_key']] = [
                'version_id' => (int) $row['version_id'],
                'hash' => (string) $row['content_hash'],
                'snapshot' => $this->decode((string) $row['snapshot_json']),
            ];
        }
        return $snapshots;
    }

    private function snapshot(array $snapshots, string $type, string $key): array
    {
        $snapshot = $snapshots[$type][$key] ?? null;
        if ($snapshot === null) {
            throw new DomainException('演练发布快照不完整。');
        }
        return $snapshot;
    }

    private function snapshotByType(array $snapshots, string $type): array
    {
        $typed = $snapshots[$type] ?? [];
        if (count($typed) !== 1) {
            throw new DomainException('演练发布快照存在歧义。');
        }
        return array_values($typed)[0];
    }

    private function lockVersionDefinition(int $domainId, int $processVersionId, int $scenarioVersionId, int $rubricVersionId, int $calibrationVersionId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT process.id FROM drill_process_versions process '
            . 'INNER JOIN drill_scenario_versions scenario_version ON scenario_version.id = ? '
            . 'INNER JOIN drill_scenarios scenario ON scenario.id = scenario_version.scenario_id '
            . 'INNER JOIN drill_rubric_versions rubric_version ON rubric_version.id = ? '
            . 'INNER JOIN drill_rubrics rubric ON rubric.id = rubric_version.rubric_id '
            . 'INNER JOIN drill_score_calibration_versions calibration ON calibration.id = ? '
            . "WHERE process.id = ? AND process.domain_id = ? AND process.status = 'published' "
            . "AND scenario.domain_id = process.domain_id AND scenario.status = 'active' AND scenario_version.status = 'published' "
            . "AND rubric.domain_id = process.domain_id AND rubric.status = 'active' AND rubric_version.status = 'published' "
            . "AND calibration.domain_id = process.domain_id AND calibration.rubric_version_id = rubric_version.id AND calibration.status = 'published' LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$scenarioVersionId, $rubricVersionId, $calibrationVersionId, $processVersionId, $domainId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('演练实例版本定义不可用或训练域不一致。');
        }
    }

    private function initialStageId(int $processVersionId, int $scenarioVersionId, string $practiceType): ?int
    {
        if ($practiceType === 'full_process') {
            $stmt = $this->pdo->prepare("SELECT id FROM drill_process_stages WHERE process_version_id = ? AND status = 'active' ORDER BY sort_order LIMIT 1 FOR UPDATE");
            $stmt->execute([$processVersionId]);
            $stageId = $stmt->fetchColumn();
            return $stageId === false ? null : (int) $stageId;
        }
        $stmt = $this->pdo->prepare(
            'SELECT scenario.stage_id FROM drill_scenario_versions version INNER JOIN drill_scenarios scenario ON scenario.id = version.scenario_id WHERE version.id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$scenarioVersionId]);
        $stageId = $stmt->fetchColumn();
        return $stageId === false || $stageId === null ? null : (int) $stageId;
    }

    private function insertAttempt(array $values): int
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO drill_attempts (assignment_id, plan_id, plan_item_id, staff_id, domain_id, process_version_id, scenario_version_id, rubric_version_id, calibration_version_id, practice_type, evaluation_context, status, persona_snapshot_json, persona_snapshot_hash, process_snapshot_json, process_snapshot_hash, scenario_snapshot_json, scenario_snapshot_hash, rubric_snapshot_json, rubric_snapshot_hash, calibration_snapshot_json, calibration_snapshot_hash, session_goal_json, session_goal_snapshot_hash, current_stage_id, started_at) "
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $persona = $values['persona_snapshot_json'];
        $process = $values['process_snapshot_json'];
        $scenario = $values['scenario_snapshot_json'];
        $rubric = $values['rubric_snapshot_json'];
        $calibration = $values['calibration_snapshot_json'];
        $sessionGoal = $values['session_goal_json'];
        $insert->execute([
            $values['assignment_id'],
            $values['plan_id'],
            $values['plan_item_id'],
            $values['staff_id'],
            $values['domain_id'],
            $values['process_version_id'],
            $values['scenario_version_id'],
            $values['rubric_version_id'],
            $values['calibration_version_id'],
            $values['practice_type'],
            $values['evaluation_context'],
            $this->json($persona),
            DrillPlanPolicy::snapshotHash($persona),
            $this->json($process),
            DrillPlanPolicy::snapshotHash($process),
            $this->json($scenario),
            DrillPlanPolicy::snapshotHash($scenario),
            $this->json($rubric),
            DrillPlanPolicy::snapshotHash($rubric),
            $this->json($calibration),
            DrillPlanPolicy::snapshotHash($calibration),
            $this->json($sessionGoal),
            DrillPlanPolicy::snapshotHash($sessionGoal),
            $values['current_stage_id'],
            $values['started_at'],
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function initializeStageProgress(int $attemptId, int $processVersionId, ?int $activeStageId, DateTimeImmutable $now): void
    {
        $stages = $this->pdo->prepare("SELECT id, sort_order FROM drill_process_stages WHERE process_version_id = ? AND status = 'active' ORDER BY sort_order FOR UPDATE");
        $stages->execute([$processVersionId]);
        $insert = $this->pdo->prepare('INSERT INTO drill_attempt_stage_progress (attempt_id, stage_id, sort_order, status, started_at) VALUES (?, ?, ?, ?, ?)');
        foreach ($stages->fetchAll(PDO::FETCH_ASSOC) ?: [] as $stage) {
            $isActive = $activeStageId !== null && (int) $stage['id'] === $activeStageId;
            $insert->execute([$attemptId, (int) $stage['id'], (int) $stage['sort_order'], $isActive ? 'active' : 'pending', $isActive ? $now->format('Y-m-d H:i:s') : null]);
        }
    }

    private function bindReferenceMaterials(int $attemptId, int $planItemId, array $snapshots): void
    {
        $stmt = $this->pdo->prepare('SELECT material_version_id, purpose_code FROM drill_plan_item_reference_bindings WHERE plan_item_id = ? ORDER BY material_version_id FOR UPDATE');
        $stmt->execute([$planItemId]);
        $insert = $this->pdo->prepare('INSERT IGNORE INTO drill_attempt_reference_bindings (attempt_id, material_version_id, purpose_code, content_hash, binding_snapshot_json) VALUES (?, ?, ?, ?, ?)');
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $binding) {
            $materialVersionId = (int) $binding['material_version_id'];
            $material = $this->snapshot($snapshots, 'reference_material', 'reference_material:' . $materialVersionId);
            $insert->execute([$attemptId, $materialVersionId, $binding['purpose_code'], $material['hash'], $this->json($material['snapshot'])]);
        }
    }

    private function transitionAttempt(int $attemptId, int $staffId, int $expectedVersion, string $event): array
    {
        return $this->transaction(function () use ($attemptId, $staffId, $expectedVersion, $event): array {
            $attempt = $this->ownedAttempt($attemptId, $staffId);
            if ((int) $attempt['status_version'] !== $expectedVersion) {
                throw new DomainException('演练实例状态已更新，请恢复最新进度后重试。');
            }
            $nextStatus = DrillAttemptStateMachine::transition((string) $attempt['status'], $event);
            $updated = $this->pdo->prepare('UPDATE drill_attempts SET status = ?, status_version = status_version + 1 WHERE id = ? AND status_version = ?');
            $updated->execute([$nextStatus, $attemptId, $expectedVersion]);
            if ($updated->rowCount() !== 1) {
                throw new DomainException('演练实例发生并发更新。');
            }
            return ['attempt_id' => $attemptId, 'status' => $nextStatus, 'status_version' => $expectedVersion + 1];
        });
    }

    private function ownedAttempt(int $attemptId, int $staffId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_attempts WHERE id = ? AND staff_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$attemptId, $staffId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            throw new DomainException('演练实例不存在或不属于当前员工。');
        }
        return $attempt;
    }

    private function fetchOwnedAttempt(int $attemptId, int $staffId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_attempts WHERE id = ? AND staff_id = ? LIMIT 1');
        $stmt->execute([$attemptId, $staffId]);
        $attempt = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$attempt) {
            throw new DomainException('演练实例不存在或不属于当前员工。');
        }
        return $attempt;
    }

    private function stageProgress(int $attemptId, bool $forUpdate = false): array
    {
        $sql = 'SELECT progress.id, progress.stage_id, stage.stage_code, stage.name, progress.sort_order, progress.status, progress.started_at, progress.completed_at '
            . 'FROM drill_attempt_stage_progress progress INNER JOIN drill_process_stages stage ON stage.id = progress.stage_id '
            . 'WHERE progress.attempt_id = ? ORDER BY progress.sort_order';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$attemptId]);
        return array_map(static fn(array $row): array => [
            'id' => (int) $row['id'],
            'stage_id' => (int) $row['stage_id'],
            'stage_code' => $row['stage_code'],
            'name' => $row['name'],
            'sort_order' => (int) $row['sort_order'],
            'status' => $row['status'],
            'started_at' => $row['started_at'],
            'completed_at' => $row['completed_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function completedTurns(int $attemptId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, turn_no, stage_id, speaker, input_type, content, generation_metadata_json, finalized_at FROM drill_turns WHERE attempt_id = ? AND finalized_at IS NOT NULL ORDER BY turn_no'
        );
        $stmt->execute([$attemptId]);
        return array_map(fn(array $row): array => [
            'id' => (int) $row['id'],
            'turn_no' => (int) $row['turn_no'],
            'stage_id' => $row['stage_id'] === null ? null : (int) $row['stage_id'],
            'speaker' => $row['speaker'],
            'input_type' => $row['input_type'],
            'content' => $row['content'],
            'generation_metadata' => $row['generation_metadata_json'] === null ? null : $this->decode((string) $row['generation_metadata_json']),
            'finalized_at' => $row['finalized_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function normalizeAttempt(array $attempt): array
    {
        return [
            'attempt_id' => (int) $attempt['id'],
            'assignment_id' => $attempt['assignment_id'] === null ? null : (int) $attempt['assignment_id'],
            'plan_id' => $attempt['plan_id'] === null ? null : (int) $attempt['plan_id'],
            'plan_item_id' => $attempt['plan_item_id'] === null ? null : (int) $attempt['plan_item_id'],
            'staff_id' => (int) $attempt['staff_id'],
            'domain_id' => (int) $attempt['domain_id'],
            'practice_type' => $attempt['practice_type'],
            'evaluation_context' => $attempt['evaluation_context'],
            'status' => $attempt['status'],
            'current_stage_id' => $attempt['current_stage_id'] === null ? null : (int) $attempt['current_stage_id'],
            'last_completed_turn_no' => (int) $attempt['last_completed_turn_no'],
            'status_version' => (int) $attempt['status_version'],
            'started_at' => $attempt['started_at'],
            'completed_at' => $attempt['completed_at'],
            'session_goal' => $this->decode((string) $attempt['session_goal_json']),
            'snapshots' => [
                'persona_hash' => $attempt['persona_snapshot_hash'],
                'process_hash' => $attempt['process_snapshot_hash'],
                'scenario_hash' => $attempt['scenario_snapshot_hash'],
                'rubric_hash' => $attempt['rubric_snapshot_hash'],
                'calibration_hash' => $attempt['calibration_snapshot_hash'],
                'session_goal_hash' => $attempt['session_goal_snapshot_hash'],
            ],
        ];
    }

    private function transaction(callable $callback): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }
}
