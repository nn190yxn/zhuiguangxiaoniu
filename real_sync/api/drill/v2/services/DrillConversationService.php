<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillAttemptStateMachine.php';
require_once __DIR__ . '/DrillConversationPolicy.php';
require_once __DIR__ . '/DrillPlanPolicy.php';
require_once __DIR__ . '/DrillAiAdapter.php';
require_once __DIR__ . '/DrillContentPolicy.php';
require_once dirname(__DIR__, 3) . '/platform/JobQueue.php';

final class DrillStateConflictException extends DomainException
{
}

final class DrillConversationService
{
    public function __construct(private PDO $pdo, private ?DrillAiAdapter $ai = null)
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

            $rubricId = $this->lockVersionDefinition(
                (int) $assignment['domain_id'],
                (int) $assignment['process_version_id'],
                (int) $item['scenario_version_id'],
                (int) $item['rubric_version_id'],
                (int) $calibration['version_id']
            );

            $stageId = $this->initialStageId((int) $assignment['process_version_id'], (int) $item['scenario_version_id'], $practiceType);
            $attemptId = $this->insertAttempt([
                'assignment_id' => $assignmentId,
                'plan_id' => (int) $assignment['plan_id'],
                'plan_item_id' => $planItemId,
                'staff_id' => $staffId,
                'domain_id' => (int) $assignment['domain_id'],
                'rubric_id' => $rubricId,
                'process_version_id' => (int) $assignment['process_version_id'],
                'scenario_version_id' => (int) $item['scenario_version_id'],
                'rubric_version_id' => (int) $item['rubric_version_id'],
                'calibration_version_id' => (int) $calibration['version_id'],
                'practice_type' => $practiceType,
                'evaluation_context' => $evaluationContext,
                'persona_snapshot_json' => $this->enrichPersonaSnapshot($this->personaSnapshot($scenario['snapshot']), (int) $assignment['domain_id']),
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
        DateTimeImmutable $now,
        array $selectionContext = []
    ): array {
        DrillConversationPolicy::assertAttemptDefinition($practiceType, $evaluationContext);
        return $this->transaction(function () use ($staffId, $practiceType, $evaluationContext, $definition, $snapshots, $sessionGoal, $now, $selectionContext): array {
            $domainId = (int) ($definition['domain_id'] ?? 0);
            $processVersionId = (int) ($definition['process_version_id'] ?? 0);
            $scenarioVersionId = (int) ($definition['scenario_version_id'] ?? 0);
            $rubricVersionId = (int) ($definition['rubric_version_id'] ?? 0);
            $calibrationVersionId = (int) ($definition['calibration_version_id'] ?? 0);
            if ($domainId <= 0 || $processVersionId <= 0 || $scenarioVersionId <= 0 || $rubricVersionId <= 0 || $calibrationVersionId <= 0) {
                throw new DomainException('演练实例版本定义不完整。');
            }
            $rubricId = $this->lockVersionDefinition($domainId, $processVersionId, $scenarioVersionId, $rubricVersionId, $calibrationVersionId);
            $stageId = $this->initialStageId($processVersionId, $scenarioVersionId, $practiceType);
            $personaSnapshot = $this->applySelectionContextToPersona(
                $this->personaSnapshot($snapshots['persona'] ?? ($snapshots['scenario'] ?? [])),
                $selectionContext,
                $domainId
            );
            $attemptId = $this->insertAttempt([
                'assignment_id' => null,
                'plan_id' => null,
                'plan_item_id' => null,
                'staff_id' => $staffId,
                'domain_id' => $domainId,
                'rubric_id' => $rubricId,
                'process_version_id' => $processVersionId,
                'scenario_version_id' => $scenarioVersionId,
                'rubric_version_id' => $rubricVersionId,
                'calibration_version_id' => $calibrationVersionId,
                'practice_type' => $practiceType,
                'evaluation_context' => $evaluationContext,
                'persona_snapshot_json' => $this->enrichPersonaSnapshot($personaSnapshot, $domainId),
                'process_snapshot_json' => $snapshots['process'] ?? [],
                'scenario_snapshot_json' => $snapshots['scenario'] ?? [],
                'rubric_snapshot_json' => $snapshots['rubric'] ?? [],
                'calibration_snapshot_json' => $snapshots['calibration'] ?? [],
                'session_goal_json' => array_merge($sessionGoal, ['selection_context' => $selectionContext]),
                'current_stage_id' => $stageId,
                'started_at' => $now->format('Y-m-d H:i:s'),
            ]);
            $this->initializeStageProgress($attemptId, $processVersionId, $stageId, $now);
            $participant = $this->pdo->prepare("INSERT IGNORE INTO drill_attempt_participants (attempt_id, participant_key, staff_id, role_code, source_type, mapping_status, mapping_confidence, confirmed_by, confirmed_at) VALUES (?, 'employee', ?, 'employee', 'self_practice', 'confirmed', 1, ?, CURRENT_TIMESTAMP)");
            $participant->execute([$attemptId, $staffId, $staffId]);
            $subject = $this->pdo->prepare("INSERT IGNORE INTO drill_attempt_score_subjects (attempt_id, participant_key, subject_type, status, confirmed_by, confirmed_at) VALUES (?, 'employee', 'employee', 'confirmed', ?, CURRENT_TIMESTAMP)");
            $subject->execute([$attemptId, $staffId]);
            return $this->resumeAttempt($attemptId, $staffId);
        });
    }

    public function createSelfPractice(int $staffId, int $scenarioVersionId, array $sessionGoal, DateTimeImmutable $now, array $selectionContext = []): array
    {
        $scenario = $this->pdo->prepare(
            "SELECT domain.id AS domain_id, process.id AS process_version_id, scenario_version.id AS scenario_version_id, "
            . "scenario_version.title, scenario_version.customer_profile_json, scenario_version.objectives_json, scenario_version.key_actions_json, "
            . "scenario_version.standard_expressions_json, scenario_version.risk_expressions_json, scenario_version.prompt_policy_json "
            . "FROM drill_scenario_versions scenario_version "
            . "INNER JOIN drill_scenarios scenario ON scenario.id = scenario_version.scenario_id "
            . "INNER JOIN drill_training_domains domain ON domain.id = scenario.domain_id "
            . "INNER JOIN drill_process_stages stage ON stage.id = scenario.stage_id "
            . "INNER JOIN drill_process_versions process ON process.id = stage.process_version_id "
            . "WHERE scenario_version.id = ? AND scenario_version.status = 'published' AND scenario.status = 'active' "
            . "AND domain.status = 'active' AND process.status = 'published' LIMIT 1"
        );
        $scenario->execute([$scenarioVersionId]);
        $definition = $scenario->fetch(PDO::FETCH_ASSOC);
        if (!$definition) {
            throw new DomainException('自主练习场景不可用或已下架。');
        }

        $rubric = $this->pdo->prepare(
            "SELECT rubric_version.id AS rubric_version_id, rubric_version.dimensions_json, rubric_version.critical_items_json, "
            . "rubric_version.score_policy_json, rubric_version.max_score, rubric_version.pass_score, rubric.mode, calibration.* "
            . "FROM drill_rubrics rubric "
            . "INNER JOIN drill_rubric_versions rubric_version ON rubric_version.rubric_id = rubric.id "
            . "INNER JOIN drill_score_calibration_versions calibration ON calibration.rubric_version_id = rubric_version.id "
            . "WHERE rubric.domain_id = ? AND rubric.status = 'active' AND rubric_version.status = 'published' "
            . "AND calibration.domain_id = ? AND calibration.evaluation_context = 'ai_roleplay' AND calibration.status = 'published' "
            . "ORDER BY rubric_version.version_no DESC, calibration.version_no DESC LIMIT 1"
        );
        $rubric->execute([(int) $definition['domain_id'], (int) $definition['domain_id']]);
        $rubricRow = $rubric->fetch(PDO::FETCH_ASSOC);
        if (!$rubricRow) {
            throw new DomainException('自主练习场景缺少可用的 AI 对练评分规则和校准版本。');
        }

        $personas = $this->pdo->prepare(
            'SELECT dimension.dimension_code, persona.value_code, persona.source, persona.source_ref '
            . 'FROM drill_scenario_personas persona INNER JOIN drill_persona_dimensions dimension ON dimension.id = persona.dimension_id '
            . 'WHERE persona.scenario_version_id = ? ORDER BY dimension.dimension_code'
        );
        $personas->execute([$scenarioVersionId]);
        $personaRows = $personas->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $definition['rubric_version_id'] = (int) $rubricRow['rubric_version_id'];
        $definition['calibration_version_id'] = (int) $rubricRow['id'];

        return $this->createPractice(
            $staffId,
            'self_practice',
            'ai_roleplay',
            $definition,
            [
                'persona' => $personaRows,
                'process' => ['version_id' => (int) $definition['process_version_id']],
                'scenario' => [
                    'version_id' => $scenarioVersionId,
                    'title' => $definition['title'],
                    'customer_profile' => $this->decode((string) $definition['customer_profile_json']),
                    'objectives' => $this->decode((string) $definition['objectives_json']),
                    'key_actions' => $this->decode((string) $definition['key_actions_json']),
                    'standard_expressions' => $this->decode((string) $definition['standard_expressions_json']),
                    'risk_expressions' => $this->decode((string) $definition['risk_expressions_json']),
                    'prompt_policy' => $this->decode((string) $definition['prompt_policy_json']),
                    'personas' => $personaRows,
                ],
                'rubric' => [
                    'version_id' => (int) $rubricRow['rubric_version_id'],
                    'dimensions' => $this->decode((string) $rubricRow['dimensions_json']),
                    'critical_items' => $this->decode((string) $rubricRow['critical_items_json']),
                    'score_policy' => $this->decode((string) $rubricRow['score_policy_json']),
                    'max_score' => (float) $rubricRow['max_score'],
                    'pass_score' => $rubricRow['pass_score'] === null ? null : (float) $rubricRow['pass_score'],
                    'mode' => $rubricRow['mode'],
                ],
                'calibration' => $rubricRow,
            ],
            $sessionGoal + ['source' => 'self_practice', 'scenario_version_id' => $scenarioVersionId],
            $now,
            $this->normalizeSelectionContext($selectionContext)
        );
    }

    public function resumeAttempt(int $attemptId, int $staffId): array
    {
        $attempt = $this->fetchOwnedAttempt($attemptId, $staffId);
        $stageProgress = $this->stageProgress($attemptId);
        $turns = $this->completedTurns($attemptId);
        return [
            'attempt' => $this->normalizeAttempt($attempt),
            'stage_progress' => $stageProgress,
            'turns' => $turns,
            'practice_context' => $this->practiceContext($attempt, $stageProgress, $turns),
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

        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $attempt = $this->ownedAttempt($attemptId, $staffId);
            if ((int) $attempt['status_version'] !== $expectedVersion) {
                throw new DrillStateConflictException('演练实例状态已更新，请恢复最新进度后重试。');
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
            $employeeTurnId = (int) $this->pdo->lastInsertId();
            $this->recordTextEvidenceSegment($attemptId, $staffId, $employeeTurnId, $employeeContent, $now);
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
                throw new DrillStateConflictException('演练轮次发生并发更新。');
            }
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return [
                'attempt_id' => $attemptId,
                'status' => $activeStatus,
                'status_version' => $expectedVersion + 1,
                'last_completed_turn_no' => $customerTurnNo,
                'employee_turn' => ['turn_no' => $employeeTurnNo, 'content' => $employeeContent],
                'customer_turn' => ['turn_no' => $customerTurnNo, 'content' => $customerContent, 'generation_metadata' => $generationMetadata],
            ];
        } catch (Throwable $throwable) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $throwable;
        }
    }

    public function submitTextTurnWithGeneratedCustomer(
        int $attemptId,
        int $staffId,
        int $expectedVersion,
        string $employeeContent,
        DateTimeImmutable $now
    ): array {
        if ($this->ai === null) {
            throw new DrillAiRetryableException('销售演练 AI 客户回应服务暂不可用。');
        }
        $attempt = $this->fetchOwnedAttempt($attemptId, $staffId);
        $generated = $this->ai->generateCustomerTurn([
            'customer_profile' => $this->decode((string) $attempt['persona_snapshot_json']),
            'scenario_goal' => $this->decode((string) $attempt['session_goal_json']),
            'current_stage' => ['stage_id' => (int) $attempt['current_stage_id']],
            'history' => $this->completedTurns($attemptId),
        ]);
        return $this->submitTextTurn(
            $attemptId,
            $staffId,
            $expectedVersion,
            $employeeContent,
            (string) $generated['content'],
            $generated['metadata'] + ['intent' => $generated['intent']],
            $now
        );
    }

    public function advanceStage(int $attemptId, int $staffId, int $expectedVersion, DateTimeImmutable $now): array
    {
        return $this->transaction(function () use ($attemptId, $staffId, $expectedVersion, $now): array {
            $attempt = $this->ownedAttempt($attemptId, $staffId);
            if ((int) $attempt['status_version'] !== $expectedVersion) {
                throw new DrillStateConflictException('演练实例状态已更新，请恢复最新进度后重试。');
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
                throw new DrillStateConflictException('演练板块发生并发更新。');
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
                throw new DrillStateConflictException('演练实例状态已更新，请恢复最新进度后重试。');
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
                throw new DrillStateConflictException('演练结束发生并发更新。');
            }
            $subjects = $this->pdo->prepare("SELECT id FROM drill_attempt_score_subjects WHERE attempt_id = ? AND status = 'confirmed'");
            $subjects->execute([$attemptId]);
            $scoreSubjectIds = array_map('intval', $subjects->fetchAll(PDO::FETCH_COLUMN) ?: []);
            if ($scoreSubjectIds === []) {
                throw new DomainException('演练实例缺少已确认的评分对象。');
            }

            $queue = new PlatformJobQueueService(new PlatformPdoJobQueueStore($this->pdo));
            $jobs = [];
            foreach ($scoreSubjectIds as $scoreSubjectId) {
                $jobs[] = $queue->enqueue(
                    'drill.evaluation.process',
                    'drill_attempt',
                    (string) $attemptId,
                    'drill.evaluation.process:' . $attemptId . ':' . $scoreSubjectId,
                    ['attempt_id' => $attemptId, 'score_subject_id' => $scoreSubjectId],
                    5,
                    3
                );
            }
            return [
                'attempt_id' => $attemptId,
                'status' => $nextStatus,
                'status_version' => $expectedVersion + 1,
                'evaluation_jobs' => array_map(static fn(array $job): int => (int) $job['id'], $jobs),
            ];
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

    private function lockVersionDefinition(int $domainId, int $processVersionId, int $scenarioVersionId, int $rubricVersionId, int $calibrationVersionId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT rubric.id FROM drill_process_versions process '
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
        $rubricId = $stmt->fetchColumn();
        if ($rubricId === false) {
            throw new DomainException('演练实例版本定义不可用或训练域不一致。');
        }
        return (int) $rubricId;
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

    private function personaSnapshot(array $snapshot): array
    {
        $profile = $snapshot['customer_profile'] ?? null;
        if (is_array($profile) && $profile !== []) {
            return $profile;
        }
        return (array) ($snapshot['personas'] ?? []);
    }

    private function enrichPersonaSnapshot(array $profile, int $domainId): array
    {
        if ($profile === [] || !$this->isNewSigningDomain($domainId)) {
            return $profile;
        }
        $requiredTags = $profile['course_match_context']['required_tags'] ?? [];
        if (!is_array($requiredTags) || $requiredTags === []) {
            return $profile;
        }
        $placeholders = implode(', ', array_fill(0, count($requiredTags), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT course.id, course.title, course.description, GROUP_CONCAT(tag.need_code ORDER BY tag.sort_order, tag.need_code) AS need_tags "
            . "FROM courses course INNER JOIN drill_course_need_tags tag ON tag.course_id = course.id "
            . "WHERE course.status = 1 AND tag.status = 'active' AND tag.need_code IN ($placeholders) "
            . 'GROUP BY course.id, course.title, course.description ORDER BY course.sort_order, course.id'
        );
        $stmt->execute($requiredTags);
        $courses = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $course) {
            $course['id'] = (int) $course['id'];
            $course['need_tags'] = explode(',', (string) $course['need_tags']);
            $courses[] = $course;
        }
        $profile['course_match_context']['matched_courses'] = $courses;
        return $profile;
    }

    private function isNewSigningDomain(int $domainId): bool
    {
        $stmt = $this->pdo->prepare('SELECT domain_code FROM drill_training_domains WHERE id = ? LIMIT 1');
        $stmt->execute([$domainId]);
        return $stmt->fetchColumn() === DrillContentPolicy::NEW_SIGN_DOMAIN;
    }

    private function normalizeSelectionContext(array $selectionContext): array
    {
        $filters = [];
        foreach (['age_band', 'primary_need', 'communication_style', 'current_status', 'course_tag'] as $key) {
            if (isset($selectionContext['filters'][$key]) && trim((string) $selectionContext['filters'][$key]) !== '') {
                $filters[$key] = trim((string) $selectionContext['filters'][$key]);
            }
        }
        $mode = ($selectionContext['mode'] ?? '') === 'random' ? 'random' : 'filtered';
        $randomSeed = isset($selectionContext['random_seed']) && is_numeric($selectionContext['random_seed'])
            ? (int) $selectionContext['random_seed']
            : null;
        return [
            'mode' => $mode,
            'filters' => $filters,
            'random_seed' => $mode === 'random' ? ($randomSeed ?? random_int(1, PHP_INT_MAX)) : null,
        ];
    }

    private function applySelectionContextToPersona(array $profile, array $selectionContext, int $domainId): array
    {
        $filters = (array) ($selectionContext['filters'] ?? []);
        if ($filters === [] && ($selectionContext['mode'] ?? '') !== 'random') {
            return $profile;
        }
        $resolvedFilters = $filters;
        $generatedProfile = [];
        $stmt = $this->pdo->prepare(
            "SELECT dimension_code, dimension_name, value_code, name, description FROM drill_persona_dimensions "
            . "WHERE domain_id = ? AND status = 'active' AND dimension_code IN ('age_band', 'primary_need', 'communication_style', 'current_status', 'course_tag') "
            . 'ORDER BY dimension_code, sort_order, id'
        );
        $stmt->execute([$domainId]);
        $options = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $option) {
            $options[(string) $option['dimension_code']][] = $option;
        }
        foreach ($filters as $dimensionCode => $valueCode) {
            $validCodes = array_column($options[$dimensionCode] ?? [], 'value_code');
            if (!in_array($valueCode, $validCodes, true)) {
                throw new DomainException('家长画像选项无效或已停用。');
            }
        }
        if (($selectionContext['mode'] ?? '') === 'random') {
            $seed = (int) ($selectionContext['random_seed'] ?? 0);
            foreach ($options as $dimensionCode => $dimensionOptions) {
                if (isset($resolvedFilters[$dimensionCode]) || $dimensionOptions === []) {
                    continue;
                }
                $index = hexdec(substr(hash('sha256', $seed . '|' . $dimensionCode), 0, 8)) % count($dimensionOptions);
                $selected = $dimensionOptions[$index];
                $resolvedFilters[$dimensionCode] = (string) $selected['value_code'];
                $generatedProfile[$dimensionCode] = [
                    'value_code' => (string) $selected['value_code'],
                    'name' => (string) $selected['name'],
                    'description' => (string) ($selected['description'] ?? ''),
                ];
            }
        }
        if (array_is_list($profile)) {
            $profile = ['dimensions' => $profile];
        }
        $profile['selection_context'] = $selectionContext;
        $profile['profile_overrides'] = $resolvedFilters;
        $profile['generated_profile'] = $generatedProfile;
        if (isset($resolvedFilters['course_tag'])) {
            $requiredTags = (array) ($profile['course_match_context']['required_tags'] ?? []);
            $requiredTags[] = (string) $resolvedFilters['course_tag'];
            $profile['course_match_context']['required_tags'] = array_values(array_unique($requiredTags));
        }
        return $profile;
    }

    private function insertAttempt(array $values): int
    {
        $insert = $this->pdo->prepare(
            "INSERT INTO drill_attempts (assignment_id, plan_id, plan_item_id, staff_id, domain_id, rubric_id, process_version_id, scenario_version_id, rubric_version_id, calibration_version_id, practice_type, evaluation_context, status, persona_snapshot_json, persona_snapshot_hash, process_snapshot_json, process_snapshot_hash, scenario_snapshot_json, scenario_snapshot_hash, rubric_snapshot_json, rubric_snapshot_hash, calibration_snapshot_json, calibration_snapshot_hash, session_goal_json, session_goal_snapshot_hash, current_stage_id, started_at) "
            . "VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
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
            $values['rubric_id'],
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
                throw new DrillStateConflictException('演练实例状态已更新，请恢复最新进度后重试。');
            }
            $nextStatus = DrillAttemptStateMachine::transition((string) $attempt['status'], $event);
            $updated = $this->pdo->prepare('UPDATE drill_attempts SET status = ?, status_version = status_version + 1 WHERE id = ? AND status_version = ?');
            $updated->execute([$nextStatus, $attemptId, $expectedVersion]);
            if ($updated->rowCount() !== 1) {
                throw new DrillStateConflictException('演练实例发生并发更新。');
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

    private function practiceContext(array $attempt, array $stageProgress, array $turns): array
    {
        $scenario = $this->decode((string) $attempt['scenario_snapshot_json']);
        $currentStage = null;
        foreach ($stageProgress as $stage) {
            if (($stage['status'] ?? '') === 'active') {
                $currentStage = $stage;
                break;
            }
        }
        if ($currentStage === null) {
            foreach ($stageProgress as $stage) {
                if ((int) ($stage['stage_id'] ?? 0) === (int) ($attempt['current_stage_id'] ?? 0)) {
                    $currentStage = $stage;
                    break;
                }
            }
        }

        return [
            'scenario' => [
                'title' => (string) ($scenario['title'] ?? ''),
                'objectives' => (array) ($scenario['objectives'] ?? []),
                'standard_expressions' => (array) ($scenario['standard_expressions'] ?? []),
                'prompt_policy' => (array) ($scenario['prompt_policy'] ?? []),
            ],
            'persona' => $this->decode((string) $attempt['persona_snapshot_json']),
            'current_stage' => $currentStage,
            'recent_turns' => array_slice($turns, -4),
        ];
    }

    private function recordTextEvidenceSegment(int $attemptId, int $staffId, int $turnId, string $content, DateTimeImmutable $now): void
    {
        $timestamp = $now->format('Y-m-d H:i:s');
        $checksum = hash('sha256', 'drill-text-turn:' . $turnId . ':' . $content);
        $asset = $this->pdo->prepare(
            "INSERT INTO drill_audio_assets (attempt_id, staff_id, asset_type, storage_path, mime_type, byte_size, checksum, purpose_code, access_scope_json, retention_until, status) "
            . "VALUES (?, ?, 'text_input', ?, 'text/plain', ?, ?, 'evaluation_evidence', ?, DATE_ADD(?, INTERVAL 365 DAY), 'completed')"
        );
        $asset->execute([$attemptId, $staffId, 'drill://text-turn/' . $turnId, strlen($content), $checksum, $this->json(['scope' => 'attempt']), $timestamp]);
        $assetId = (int) $this->pdo->lastInsertId();

        $transcript = $this->pdo->prepare(
            "INSERT INTO drill_transcripts (attempt_id, audio_asset_id, turn_id, transcript_type, provider, content, confidence, status, completed_at) "
            . "VALUES (?, ?, ?, 'final', 'internal_text', ?, 1, 'completed', ?)"
        );
        $transcript->execute([$attemptId, $assetId, $turnId, $content, $timestamp]);
        $transcriptId = (int) $this->pdo->lastInsertId();

        $segment = $this->pdo->prepare(
            "INSERT INTO drill_transcript_segments (attempt_id, transcript_id, segment_no, speaker_key, role_code, starts_ms, ends_ms, content, mapping_confidence, mapping_status) "
            . "VALUES (?, ?, 1, 'employee', 'employee', 0, 0, ?, 1, 'confirmed')"
        );
        $segment->execute([$attemptId, $transcriptId, $content]);
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
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $throwable) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
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
