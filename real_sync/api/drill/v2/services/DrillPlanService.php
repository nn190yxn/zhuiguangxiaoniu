<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillPlanPolicy.php';
require_once __DIR__ . '/DrillPlanTargetResolver.php';
require_once __DIR__ . '/DrillPrerequisiteFactsResolver.php';

final class DrillPlanService
{
    private DrillPrerequisiteFactsResolver $prerequisiteFactsResolver;

    public function __construct(private PDO $pdo, ?DrillPrerequisiteFactsResolver $prerequisiteFactsResolver = null)
    {
        $this->prerequisiteFactsResolver = $prerequisiteFactsResolver ?? new DrillPrerequisiteFactsResolver($pdo);
    }

    public function createDraft(
        int $domainId,
        int $processVersionId,
        array $definition,
        array $items,
        array $scopes,
        int $actorStaffId
    ): array {
        DrillPlanPolicy::assertDefinition($definition, $items, $scopes);
        $scopes = DrillPlanPolicy::normalizeScopes($scopes);

        return $this->transaction(function () use ($domainId, $processVersionId, $definition, $items, $scopes, $actorStaffId): array {
            $this->lockPublishedProcess($domainId, $processVersionId);
            $insert = $this->pdo->prepare(
                "INSERT INTO drill_plans (domain_id, process_version_id, plan_code, name, plan_type, status, pass_policy_json, prerequisite_policy_json, recording_retention_days, minimum_client_version, source_type, source_ref, created_by, updated_by) VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                $domainId,
                $processVersionId,
                trim((string) $definition['plan_code']),
                trim((string) $definition['name']),
                $definition['plan_type'],
                $this->json($definition['pass_policy']),
                $this->json($definition['prerequisite_policy'] ?? []),
                (int) ($definition['recording_retention_days'] ?? 180),
                $definition['minimum_client_version'] ?? null,
                trim((string) ($definition['source_type'] ?? 'manual')),
                $definition['source_ref'] ?? null,
                $actorStaffId,
                $actorStaffId,
            ]);
            $planId = (int) $this->pdo->lastInsertId();
            $this->insertItems($planId, $items);
            $this->insertScopes($planId, $scopes);
            $this->audit('plan.created', 'plan', $planId, null, ['status' => 'draft', 'definition' => $definition], $actorStaffId);
            return ['plan_id' => $planId, 'status' => 'draft'];
        });
    }

    public function publish(
        int $planId,
        string $publicationKey,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $dueAt,
        array $reviewerStaffIds,
        array $permissions,
        int $actorStaffId
    ): array {
        DrillPlanPolicy::assertPermission($permissions);
        DrillPlanPolicy::assertPublicationWindow($startsAt, $dueAt);
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/', $publicationKey)) {
            throw new DomainException('计划发布幂等键无效。');
        }

        return $this->transaction(function () use ($planId, $publicationKey, $startsAt, $dueAt, $reviewerStaffIds, $actorStaffId): array {
            $plan = $this->lockPlan($planId);
            $scopes = $this->planScopes($planId);
            $reviewerStaffIds = array_values(array_unique(array_map('intval', $reviewerStaffIds)));
            sort($reviewerStaffIds, SORT_NUMERIC);
            $requestHash = DrillPlanPolicy::publicationRequestHash(
                $planId,
                $startsAt,
                $dueAt,
                $reviewerStaffIds,
                $scopes,
                $this->publicationDefinitionHash($plan)
            );
            $existing = $this->existingPublication($planId, $publicationKey, $requestHash);
            if ($existing !== null) {
                return $existing;
            }
            $targetStaffIds = (new DrillPlanTargetResolver($this->pdo))->resolve($scopes, $startsAt);
            if ($targetStaffIds === []) {
                throw new DomainException('当前目标范围没有可发布的有效员工。');
            }
            $reviewers = $this->reviewerCandidates($reviewerStaffIds);
            DrillPlanPolicy::assertReviewers($reviewers);
            $snapshots = $this->contentSnapshots($plan, $startsAt, $dueAt);

            $publicationNo = $this->nextPublicationNo($planId);
            $insertPublication = $this->pdo->prepare(
                "INSERT INTO drill_plan_publications (plan_id, publication_no, publication_key, publication_request_hash, status, target_scope_json, starts_at, due_at, published_by, published_at) VALUES (?, ?, ?, ?, 'published', ?, ?, ?, ?, CURRENT_TIMESTAMP)"
            );
            $insertPublication->execute([
                $planId,
                $publicationNo,
                $publicationKey,
                $requestHash,
                $this->json(['rules' => $scopes, 'resolved_staff_ids' => $targetStaffIds]),
                $startsAt->format('Y-m-d H:i:s'),
                $dueAt->format('Y-m-d H:i:s'),
                $actorStaffId,
            ]);
            $publicationId = (int) $this->pdo->lastInsertId();
            $this->insertReviewers($publicationId, $reviewers);
            $this->insertSnapshots($publicationId, $snapshots);
            $created = $this->createAssignments(
                $publicationId,
                $targetStaffIds,
                $startsAt,
                $dueAt,
                (int) $plan['domain_id'],
                $this->decode((string) ($plan['prerequisite_policy_json'] ?? '[]')),
            );
            $this->pdo->prepare("UPDATE drill_plans SET status = 'published', updated_by = ? WHERE id = ?")->execute([$actorStaffId, $planId]);
            $this->audit('plan.published', 'plan_publication', $publicationId, null, [
                'plan_id' => $planId,
                'publication_no' => $publicationNo,
                'target_count' => count($targetStaffIds),
                'assignment_count' => $created,
            ], $actorStaffId);

            return [
                'publication_id' => $publicationId,
                'publication_no' => $publicationNo,
                'status' => 'published',
                'target_count' => count($targetStaffIds),
                'assignment_count' => $created,
            ];
        });
    }

    private function insertItems(int $planId, array $items): void
    {
        $itemInsert = $this->pdo->prepare(
            'INSERT INTO drill_plan_items (plan_id, scenario_version_id, rubric_version_id, sort_order, required, evaluation_context, pass_policy_json) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $referenceInsert = $this->pdo->prepare(
            'INSERT INTO drill_plan_item_reference_bindings (plan_item_id, material_version_id, purpose_code, required) VALUES (?, ?, ?, ?)'
        );
        foreach ($items as $item) {
            $itemInsert->execute([
                $planId,
                (int) $item['scenario_version_id'],
                (int) $item['rubric_version_id'],
                (int) $item['sort_order'],
                !empty($item['required']) ? 1 : 0,
                $item['evaluation_context'] ?? 'ai_roleplay',
                isset($item['pass_policy']) ? $this->json($item['pass_policy']) : null,
            ]);
            $planItemId = (int) $this->pdo->lastInsertId();
            foreach (array_values(array_unique(array_map('intval', $item['material_version_ids'] ?? []))) as $materialVersionId) {
                $referenceInsert->execute([$planItemId, $materialVersionId, 'training_reference', 1]);
            }
        }
    }

    private function insertScopes(int $planId, array $scopes): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO drill_plan_target_scopes (plan_id, target_type, target_key, include_mode, source_ref) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($scopes as $scope) {
            $insert->execute([$planId, $scope['target_type'], $scope['target_key'], $scope['include_mode'], $scope['source_ref']]);
        }
    }

    private function contentSnapshots(array $plan, DateTimeImmutable $startsAt, DateTimeImmutable $dueAt): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT item.*, scenario.domain_id AS scenario_domain_id, scenario.status AS scenario_status, '
            . 'scenario_version.status AS scenario_version_status, scenario_version.content_hash AS scenario_hash, '
            . 'scenario_version.title AS scenario_title, scenario_version.customer_profile_json, scenario_version.objectives_json, '
            . 'scenario_version.key_actions_json, scenario_version.standard_expressions_json, scenario_version.risk_expressions_json, scenario_version.prompt_policy_json, '
            . 'rubric.domain_id AS rubric_domain_id, rubric.status AS rubric_status, rubric.mode AS rubric_mode, '
            . 'rubric_version.status AS rubric_version_status, rubric_version.content_hash AS rubric_hash, '
            . 'rubric_version.dimensions_json, rubric_version.critical_items_json, rubric_version.score_policy_json, rubric_version.max_score, rubric_version.pass_score '
            . 'FROM drill_plan_items item '
            . 'INNER JOIN drill_scenario_versions scenario_version ON scenario_version.id = item.scenario_version_id '
            . 'INNER JOIN drill_scenarios scenario ON scenario.id = scenario_version.scenario_id '
            . 'INNER JOIN drill_rubric_versions rubric_version ON rubric_version.id = item.rubric_version_id '
            . 'INNER JOIN drill_rubrics rubric ON rubric.id = rubric_version.rubric_id '
            . 'WHERE item.plan_id = ? ORDER BY item.sort_order FOR UPDATE'
        );
        $stmt->execute([(int) $plan['id']]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        DrillPlanPolicy::assertItems($items, (string) $plan['plan_type']);
        $snapshots = [[
            'type' => 'plan',
            'key' => 'plan',
            'version_id' => (int) $plan['id'],
            'hash' => DrillPlanPolicy::snapshotHash($plan),
            'snapshot' => $plan,
        ]];
        $process = $this->publishedProcess((int) $plan['process_version_id'], (int) $plan['domain_id']);
        $snapshots[] = $this->versionSnapshot(
            'process',
            (int) $process['id'],
            DrillPlanPolicy::snapshotHash($process),
            $process
        );
        $composition = $this->planComposition($items);
        $snapshots[] = [
            'type' => 'plan_composition',
            'key' => 'plan_composition',
            'version_id' => (int) $plan['id'],
            'hash' => DrillPlanPolicy::snapshotHash($composition),
            'snapshot' => $composition,
        ];
        foreach ($items as $item) {
            if ((int) $item['scenario_domain_id'] !== (int) $plan['domain_id'] || (int) $item['rubric_domain_id'] !== (int) $plan['domain_id']) {
                throw new DomainException('计划内容版本与训练域不一致。');
            }
            if ($item['scenario_status'] !== 'active' || $item['scenario_version_status'] !== 'published' || $item['rubric_status'] !== 'active' || $item['rubric_version_status'] !== 'published') {
                throw new DomainException('计划包含未发布或已停用的场景与评分规则。');
            }
            $mapping = $this->publishedMapping((int) $item['rubric_version_id']);
            $calibration = $this->publishedCalibration((int) $plan['domain_id'], (int) $item['rubric_version_id'], (string) $item['evaluation_context']);
            $scenarioSnapshot = [
                'version_id' => (int) $item['scenario_version_id'],
                'title' => $item['scenario_title'],
                'customer_profile' => $this->decode((string) $item['customer_profile_json']),
                'objectives' => $this->decode((string) $item['objectives_json']),
                'key_actions' => $this->decode((string) $item['key_actions_json']),
                'standard_expressions' => $this->decode((string) $item['standard_expressions_json']),
                'risk_expressions' => $this->decode((string) $item['risk_expressions_json']),
                'prompt_policy' => $this->decode((string) $item['prompt_policy_json']),
                'personas' => $this->scenarioPersonas((int) $item['scenario_version_id']),
            ];
            $rubricSnapshot = [
                'version_id' => (int) $item['rubric_version_id'],
                'dimensions' => $this->decode((string) $item['dimensions_json']),
                'critical_items' => $this->decode((string) $item['critical_items_json']),
                'score_policy' => $this->decode((string) $item['score_policy_json']),
                'max_score' => (float) $item['max_score'],
                'pass_score' => $item['pass_score'] === null ? null : (float) $item['pass_score'],
                'mode' => $item['rubric_mode'],
            ];
            $snapshots[] = $this->versionSnapshot('scenario', (int) $item['scenario_version_id'], (string) $item['scenario_hash'], $scenarioSnapshot);
            $snapshots[] = $this->versionSnapshot('rubric', (int) $item['rubric_version_id'], (string) $item['rubric_hash'], $rubricSnapshot);
            $snapshots[] = $this->versionSnapshot('knowledge_mapping', (int) $mapping['id'], (string) $mapping['mapping_hash'], $this->mappingSnapshot($mapping));
            $snapshots[] = $this->versionSnapshot('calibration', (int) $calibration['id'], DrillPlanPolicy::snapshotHash($calibration), $calibration);
            foreach ($this->publishedMaterials((int) $item['id'], (int) $plan['domain_id'], $startsAt, $dueAt) as $material) {
                $snapshots[] = $this->versionSnapshot('reference_material', (int) $material['id'], (string) $material['content_hash'], $material);
            }
        }
        return $snapshots;
    }

    private function createAssignments(
        int $publicationId,
        array $staffIds,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $dueAt,
        int $domainId,
        array $policy
    ): int {
        $insert = $this->pdo->prepare(
            "INSERT IGNORE INTO drill_assignments (publication_id, staff_id, status, starts_at, due_at) VALUES (?, ?, 'assigned', ?, ?)"
        );
        $snapshotInsert = $this->pdo->prepare(
            'INSERT IGNORE INTO drill_assignment_prerequisite_snapshots (assignment_id, evaluation_status, policy_hash, policy_snapshot_json, evaluation_result_json) VALUES (?, ?, ?, ?, ?)'
        );
        $notificationInsert = $this->pdo->prepare(
            "INSERT IGNORE INTO drill_notifications (notification_key, recipient_staff_id, notification_type, object_type, object_id, channel, payload_json) VALUES (?, ?, 'drill_assignment_created', 'drill_assignment', ?, 'in_app', ?)"
        );
        $created = 0;
        foreach ($staffIds as $staffId) {
            $insert->execute([$publicationId, $staffId, $startsAt->format('Y-m-d H:i:s'), $dueAt->format('Y-m-d H:i:s')]);
            if ($insert->rowCount() !== 1) {
                continue;
            }
            $created++;
            $assignmentId = (int) $this->pdo->lastInsertId();
            $facts = $this->prerequisiteFactsResolver->resolve($staffId, $domainId, $policy);
            $evaluation = DrillPlanPolicy::evaluatePrerequisites($policy, $facts);
            $snapshotInsert->execute([
                $assignmentId,
                $evaluation['eligible'] ? 'eligible' : 'blocked',
                DrillPlanPolicy::snapshotHash($policy),
                $this->json($policy),
                $this->json($evaluation),
            ]);
            $notificationInsert->execute([
                'drill-assignment-created:' . $assignmentId,
                $staffId,
                $assignmentId,
                $this->json(['assignment_id' => $assignmentId, 'starts_at' => $startsAt->format(DATE_ATOM), 'due_at' => $dueAt->format(DATE_ATOM)]),
            ]);
        }
        return $created;
    }

    private function lockPublishedProcess(int $domainId, int $processVersionId): void
    {
        $stmt = $this->pdo->prepare("SELECT id FROM drill_process_versions WHERE id = ? AND domain_id = ? AND status = 'published' LIMIT 1 FOR UPDATE");
        $stmt->execute([$processVersionId, $domainId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('训练计划必须绑定当前训练域内已发布的流程版本。');
        }
    }

    private function lockPlan(int $planId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT plan.* FROM drill_plans plan INNER JOIN drill_process_versions process ON process.id = plan.process_version_id INNER JOIN drill_training_domains domain ON domain.id = plan.domain_id WHERE plan.id = ? AND plan.status IN ('draft', 'published') AND process.status = 'published' AND domain.status = 'active' LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$planId]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$plan) {
            throw new DomainException('训练计划不存在或当前版本不可发布。');
        }
        return $plan;
    }

    private function planScopes(int $planId): array
    {
        $stmt = $this->pdo->prepare('SELECT target_type, target_key, include_mode, source_ref FROM drill_plan_target_scopes WHERE plan_id = ? ORDER BY id FOR UPDATE');
        $stmt->execute([$planId]);
        return DrillPlanPolicy::normalizeScopes($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    private function publicationDefinitionHash(array $plan): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, scenario_version_id, rubric_version_id, sort_order, required, evaluation_context, pass_policy_json '
            . 'FROM drill_plan_items WHERE plan_id = ? ORDER BY sort_order FOR UPDATE'
        );
        $stmt->execute([(int) $plan['id']]);
        $definition = [
            'domain_id' => (int) $plan['domain_id'],
            'process_version_id' => (int) $plan['process_version_id'],
            'plan_code' => $plan['plan_code'],
            'name' => $plan['name'],
            'plan_type' => $plan['plan_type'],
            'pass_policy' => $this->decode((string) $plan['pass_policy_json']),
            'prerequisite_policy' => $this->decode((string) ($plan['prerequisite_policy_json'] ?? '[]')),
            'recording_retention_days' => (int) $plan['recording_retention_days'],
            'minimum_client_version' => $plan['minimum_client_version'],
            'source_type' => $plan['source_type'],
            'source_ref' => $plan['source_ref'],
            'composition' => $this->planComposition($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []),
        ];
        return DrillPlanPolicy::snapshotHash($definition);
    }

    private function reviewerCandidates(array $staffIds): array
    {
        $staffIds = array_values(array_unique(array_map('intval', $staffIds)));
        if ($staffIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
        $stmt = $this->pdo->prepare("SELECT id AS staff_id, role, status, lifecycle_status FROM staffs WHERE id IN ($placeholders) FOR UPDATE");
        $stmt->execute($staffIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $byId = [];
        foreach ($rows as $row) {
            $role = strtolower(trim((string) $row['role']));
            $role = in_array($role, ['operations', 'operator', 'ops'], true) ? 'operation' : $role;
            $byId[(int) $row['staff_id']] = [
                'staff_id' => (int) $row['staff_id'],
                'active' => (int) $row['status'] === 1 && $row['lifecycle_status'] === 'active',
                'can_review' => in_array($role, ['admin', 'administrator', 'operation'], true),
            ];
        }
        return array_map(static fn(int $staffId): array => $byId[$staffId] ?? ['staff_id' => $staffId, 'active' => false, 'can_review' => false], $staffIds);
    }

    private function publishedMapping(int $rubricVersionId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM drill_knowledge_mapping_versions WHERE rubric_version_id = ? AND status = 'published' ORDER BY version_no DESC LIMIT 1 FOR UPDATE");
        $stmt->execute([$rubricVersionId]);
        $mapping = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mapping) {
            throw new DomainException('计划评分规则缺少已发布的知识映射。');
        }
        return $mapping;
    }

    private function mappingSnapshot(array $mapping): array
    {
        $knowledge = $this->pdo->prepare(
            'SELECT dimension_code, criterion_code, knowledge_point_id, knowledge_point_version_id, is_primary FROM drill_rubric_knowledge_links WHERE mapping_version_id = ? ORDER BY dimension_code, criterion_code, knowledge_point_id FOR UPDATE'
        );
        $knowledge->execute([(int) $mapping['id']]);
        $resources = $this->pdo->prepare(
            'SELECT knowledge_point_id, knowledge_point_version_id, learning_resource_id, learning_resource_version_id, priority FROM drill_knowledge_resource_links WHERE mapping_version_id = ? ORDER BY knowledge_point_id, priority, learning_resource_id FOR UPDATE'
        );
        $resources->execute([(int) $mapping['id']]);
        return [
            'version' => $mapping,
            'knowledge_links' => $knowledge->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'resource_links' => $resources->fetchAll(PDO::FETCH_ASSOC) ?: [],
        ];
    }

    private function planComposition(array $items): array
    {
        $references = $this->pdo->prepare(
            'SELECT material_version_id, purpose_code, required FROM drill_plan_item_reference_bindings WHERE plan_item_id = ? ORDER BY purpose_code, material_version_id FOR UPDATE'
        );
        $composition = [];
        foreach ($items as $item) {
            $references->execute([(int) $item['id']]);
            $composition[] = [
                'plan_item_id' => (int) $item['id'],
                'scenario_version_id' => (int) $item['scenario_version_id'],
                'rubric_version_id' => (int) $item['rubric_version_id'],
                'sort_order' => (int) $item['sort_order'],
                'required' => (bool) $item['required'],
                'evaluation_context' => $item['evaluation_context'],
                'pass_policy' => $item['pass_policy_json'] === null ? null : $this->decode((string) $item['pass_policy_json']),
                'reference_bindings' => $references->fetchAll(PDO::FETCH_ASSOC) ?: [],
            ];
        }
        return $composition;
    }

    private function scenarioPersonas(int $scenarioVersionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dimension.dimension_code, persona.value_code, persona.source, persona.source_ref FROM drill_scenario_personas persona INNER JOIN drill_persona_dimensions dimension ON dimension.id = persona.dimension_id WHERE persona.scenario_version_id = ? ORDER BY dimension.dimension_code FOR UPDATE'
        );
        $stmt->execute([$scenarioVersionId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function publishedCalibration(int $domainId, int $rubricVersionId, string $context): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM drill_score_calibration_versions WHERE domain_id = ? AND rubric_version_id = ? AND evaluation_context = ? AND status = 'published' ORDER BY version_no DESC LIMIT 1 FOR UPDATE");
        $stmt->execute([$domainId, $rubricVersionId, $context]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new DomainException('计划评分规则缺少当前评估上下文的已发布校准版本。');
        }
        return $row;
    }

    private function publishedMaterials(
        int $planItemId,
        int $domainId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $dueAt
    ): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT version.*, material.status AS material_status FROM drill_plan_item_reference_bindings binding INNER JOIN drill_reference_material_versions version ON version.id = binding.material_version_id INNER JOIN drill_reference_materials material ON material.id = version.reference_material_id WHERE binding.plan_item_id = ? FOR UPDATE'
        );
        $stmt->execute([$planItemId]);
        $materials = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($materials as $material) {
            $validFrom = new DateTimeImmutable((string) ($material['effective_from'] ?? ''));
            $validUntil = new DateTimeImmutable((string) ($material['effective_until'] ?? ''));
            if ((int) $material['domain_id'] !== $domainId
                || $material['status'] !== 'published'
                || $material['authorization_status'] !== 'authorized'
                || $material['material_status'] !== 'active'
                || $startsAt < $validFrom
                || $dueAt >= $validUntil
            ) {
                throw new DomainException('计划参考资料存在未发布、未授权、已失效或跨训练域版本。');
            }
        }
        return $materials;
    }

    private function publishedProcess(int $processVersionId, int $domainId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM drill_process_versions WHERE id = ? AND domain_id = ? AND status = 'published' LIMIT 1 FOR UPDATE");
        $stmt->execute([$processVersionId, $domainId]);
        $process = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$process) {
            throw new DomainException('计划流程版本未发布或不属于当前训练域。');
        }
        return $process;
    }

    private function nextPublicationNo(int $planId): int
    {
        $stmt = $this->pdo->prepare('SELECT publication_no FROM drill_plan_publications WHERE plan_id = ? ORDER BY publication_no FOR UPDATE');
        $stmt->execute([$planId]);
        $numbers = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        return $numbers === [] ? 1 : max($numbers) + 1;
    }

    private function existingPublication(int $planId, string $publicationKey, string $requestHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT publication.id AS publication_id, publication.publication_no, publication.publication_request_hash, publication.status, COUNT(assignment.id) AS assignment_count '
            . 'FROM drill_plan_publications publication LEFT JOIN drill_assignments assignment ON assignment.publication_id = publication.id '
            . 'WHERE publication.plan_id = ? AND publication.publication_key = ? '
            . 'GROUP BY publication.id, publication.publication_no, publication.publication_request_hash, publication.status LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$planId, $publicationKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        if (!hash_equals((string) $row['publication_request_hash'], $requestHash)) {
            throw new DomainException('计划发布幂等键已用于不同请求。');
        }
        return [
            'publication_id' => (int) $row['publication_id'],
            'publication_no' => (int) $row['publication_no'],
            'status' => $row['status'],
            'target_count' => (int) $row['assignment_count'],
            'assignment_count' => (int) $row['assignment_count'],
            'idempotent_replay' => true,
        ];
    }

    private function insertReviewers(int $publicationId, array $reviewers): void
    {
        $insert = $this->pdo->prepare('INSERT INTO drill_publication_reviewers (publication_id, reviewer_staff_id, priority) VALUES (?, ?, ?)');
        foreach ($reviewers as $priority => $reviewer) {
            $insert->execute([$publicationId, $reviewer['staff_id'], $priority + 1]);
        }
    }

    private function insertSnapshots(int $publicationId, array $snapshots): void
    {
        $insert = $this->pdo->prepare('INSERT INTO drill_publication_snapshots (publication_id, snapshot_type, snapshot_key, version_id, content_hash, snapshot_json) VALUES (?, ?, ?, ?, ?, ?)');
        $inserted = [];
        foreach ($snapshots as $snapshot) {
            $identity = $snapshot['type'] . ':' . $snapshot['key'];
            if (isset($inserted[$identity])) {
                continue;
            }
            $inserted[$identity] = true;
            $insert->execute([$publicationId, $snapshot['type'], $snapshot['key'], $snapshot['version_id'], $snapshot['hash'], $this->json($snapshot['snapshot'])]);
        }
    }

    private function versionSnapshot(string $type, int $versionId, string $hash, array $snapshot): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            throw new DomainException('发布内容版本缺少有效内容哈希。');
        }
        return ['type' => $type, 'key' => $type . ':' . $versionId, 'version_id' => $versionId, 'hash' => $hash, 'snapshot' => $snapshot];
    }

    private function audit(string $action, string $objectType, int $objectId, ?array $before, array $after, int $actorStaffId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO drill_audit_logs (actor_staff_id, action, object_type, object_id, before_snapshot_json, after_snapshot_json) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->execute([$actorStaffId, $action, $objectType, $objectId, $before === null ? null : $this->json($before), $this->json($after)]);
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

    private function decode(string $value): array
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }
}
