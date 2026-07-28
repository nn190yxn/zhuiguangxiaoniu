<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillContentPolicy.php';

final class DrillContentService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createProcessDraft(
        int $domainId,
        string $name,
        array $stages,
        int $actorStaffId,
        string $sourceType,
        ?string $sourceRef = null
    ): array {
        $this->positiveId($domainId, '训练域 ID');
        $this->positiveId($actorStaffId, '操作员工 ID');
        DrillContentPolicy::assertOrderedStages($stages);

        return $this->transaction(function () use ($domainId, $name, $stages, $sourceType, $sourceRef): array {
            $this->lockDomain($domainId);
            $versionNo = $this->nextVersion('drill_process_versions', 'domain_id', $domainId);
            $insert = $this->pdo->prepare(
                'INSERT INTO drill_process_versions (domain_id, version_no, name, status, source_type, source_ref) '
                . "VALUES (?, ?, ?, 'draft', ?, ?)"
            );
            $insert->execute([$domainId, $versionNo, trim($name), trim($sourceType), $sourceRef]);
            $processVersionId = (int) $this->pdo->lastInsertId();

            $stageInsert = $this->pdo->prepare(
                'INSERT INTO drill_process_stages '
                . '(process_version_id, stage_code, name, description, sort_order, required, status, source_type, source_ref) '
                . "VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?)"
            );
            foreach ($stages as $stage) {
                $stageInsert->execute([
                    $processVersionId,
                    $stage['stage_code'],
                    trim((string) ($stage['name'] ?? '')),
                    $stage['description'] ?? null,
                    (int) $stage['sort_order'],
                    !empty($stage['required']) ? 1 : 0,
                    trim($sourceType),
                    $sourceRef,
                ]);
            }
            return ['id' => $processVersionId, 'domain_id' => $domainId, 'version_no' => $versionNo, 'status' => 'draft'];
        });
    }

    public function transitionProcessVersion(int $versionId, string $event, int $actorStaffId): array
    {
        return $this->transaction(function () use ($versionId, $event, $actorStaffId): array {
            $stmt = $this->pdo->prepare('SELECT * FROM drill_process_versions WHERE id = ? LIMIT 1 FOR UPDATE');
            $stmt->execute([$versionId]);
            $version = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$version) {
                throw new DomainException('流程版本不存在。');
            }
            $nextStatus = DrillContentVersionStateMachine::transition((string) $version['status'], $event);
            if ($nextStatus === 'published') {
                $count = $this->pdo->prepare("SELECT COUNT(*) FROM drill_process_stages WHERE process_version_id = ? AND status = 'active'");
                $count->execute([$versionId]);
                if ((int) $count->fetchColumn() <= 0) {
                    throw new DomainException('流程版本至少包含一个有效板块。');
                }
                $archive = $this->pdo->prepare(
                    "UPDATE drill_process_versions SET status = 'archived', archived_at = CURRENT_TIMESTAMP "
                    . "WHERE domain_id = ? AND status = 'published' AND id <> ?"
                );
                $archive->execute([(int) $version['domain_id'], $versionId]);
            }
            $sets = ['status = ?'];
            $params = [$nextStatus];
            if ($nextStatus === 'published') {
                $sets[] = 'published_at = CURRENT_TIMESTAMP';
            } elseif ($nextStatus === 'archived') {
                $sets[] = 'archived_at = CURRENT_TIMESTAMP';
            }
            $params[] = $versionId;
            $update = $this->pdo->prepare('UPDATE drill_process_versions SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $update->execute($params);
            $this->audit('process_version.' . $event, 'process_version', $versionId, $version, ['status' => $nextStatus], $actorStaffId, null);
            return ['version_id' => $versionId, 'status' => $nextStatus];
        });
    }

    public function upsertPersonaValue(
        int $domainId,
        string $dimensionCode,
        string $dimensionName,
        string $valueCode,
        string $name,
        int $sortOrder,
        string $sourceType = 'manual',
        ?string $sourceRef = null
    ): array {
        return $this->transaction(function () use ($domainId, $dimensionCode, $dimensionName, $valueCode, $name, $sortOrder, $sourceType, $sourceRef): array {
            $this->lockDomain($domainId);
            if (!preg_match('/^[a-z][a-z0-9_]{1,63}$/', $dimensionCode) || !preg_match('/^[a-z][a-z0-9_]{1,63}$/', $valueCode) || $sortOrder <= 0) {
                throw new DomainException('画像维度编码、值编码或排序无效。');
            }
            $insert = $this->pdo->prepare(
                'INSERT INTO drill_persona_dimensions '
                . '(domain_id, dimension_code, dimension_name, value_code, name, sort_order, status, source_type, source_ref) '
                . "VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?) "
                . 'ON DUPLICATE KEY UPDATE dimension_name = VALUES(dimension_name), name = VALUES(name), sort_order = VALUES(sort_order), '
                . "status = 'active', source_type = VALUES(source_type), source_ref = VALUES(source_ref)"
            );
            $insert->execute([$domainId, $dimensionCode, trim($dimensionName), $valueCode, trim($name), $sortOrder, $sourceType, $sourceRef]);
            $select = $this->pdo->prepare('SELECT id, domain_id, dimension_code, value_code, status FROM drill_persona_dimensions WHERE domain_id = ? AND dimension_code = ? AND value_code = ?');
            $select->execute([$domainId, $dimensionCode, $valueCode]);
            return $select->fetch(PDO::FETCH_ASSOC) ?: [];
        });
    }

    public function createScenarioDraft(
        int $domainId,
        int $stageId,
        string $scenarioCode,
        string $name,
        string $difficulty,
        array $payload,
        array $personaSelection,
        int $actorStaffId,
        string $sourceType = 'manual',
        ?string $sourceRef = null
    ): array {
        DrillContentPolicy::assertScenarioPayload($payload);
        $this->positiveId($actorStaffId, '操作员工 ID');

        return $this->transaction(function () use ($domainId, $stageId, $scenarioCode, $name, $difficulty, $payload, $personaSelection, $actorStaffId, $sourceType, $sourceRef): array {
            $this->assertStageInDomain($stageId, $domainId);
            $allowed = $this->allowedPersonaValues($domainId);
            $persona = DrillContentPolicy::normalizePersona($allowed, $personaSelection);

            $scenarioId = $this->stableScenarioId($domainId, $stageId, $scenarioCode, $name, $difficulty, $sourceType, $sourceRef);
            $versionNo = $this->nextVersion('drill_scenario_versions', 'scenario_id', $scenarioId);
            $snapshot = [
                'title' => trim((string) $payload['title']),
                'customer_profile' => $payload['customer_profile'],
                'objectives' => $payload['objectives'],
                'key_actions' => $payload['key_actions'],
                'standard_expressions' => $payload['standard_expressions'],
                'risk_expressions' => $payload['risk_expressions'],
                'prompt_policy' => $payload['prompt_policy'],
                'persona' => $persona,
            ];
            $insert = $this->pdo->prepare(
                'INSERT INTO drill_scenario_versions '
                . '(scenario_id, version_no, status, title, customer_profile_json, objectives_json, key_actions_json, '
                . 'standard_expressions_json, risk_expressions_json, prompt_policy_json, content_hash, source_type, source_ref, created_by, updated_by) '
                . "VALUES (?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                $scenarioId,
                $versionNo,
                $snapshot['title'],
                $this->json($snapshot['customer_profile']),
                $this->json($snapshot['objectives']),
                $this->json($snapshot['key_actions']),
                $this->json($snapshot['standard_expressions']),
                $this->json($snapshot['risk_expressions']),
                $this->json($snapshot['prompt_policy']),
                DrillContentVersionStateMachine::snapshotHash($snapshot),
                $sourceType,
                $sourceRef,
                $actorStaffId,
                $actorStaffId,
            ]);
            $versionId = (int) $this->pdo->lastInsertId();
            $this->insertPersonas($versionId, $domainId, $persona, $sourceType, $sourceRef);

            return ['scenario_id' => $scenarioId, 'version_id' => $versionId, 'version_no' => $versionNo, 'status' => 'draft', 'persona' => $persona];
        });
    }

    public function generateScenarioCandidate(
        int $domainId,
        int $stageId,
        string $scenarioCode,
        string $name,
        string $difficulty,
        array $personaSelection,
        int $actorStaffId,
        callable $generator,
        ?string $sourceRef = null
    ): array {
        $allowed = $this->allowedPersonaValues($domainId);
        $persona = DrillContentPolicy::normalizePersona($allowed, $personaSelection);
        $payload = $generator(['domain_id' => $domainId, 'stage_id' => $stageId, 'persona' => $persona]);
        if (!is_array($payload)) {
            throw new DomainException('AI 候选场景生成器必须返回结构化草稿。');
        }
        return $this->createScenarioDraft(
            $domainId,
            $stageId,
            $scenarioCode,
            $name,
            $difficulty,
            $payload,
            $persona,
            $actorStaffId,
            'ai_candidate',
            $sourceRef
        );
    }

    public function updateScenarioDraft(int $versionId, array $payload, int $actorStaffId): array
    {
        DrillContentPolicy::assertScenarioPayload($payload);
        return $this->transaction(function () use ($versionId, $payload, $actorStaffId): array {
            $version = $this->lockScenarioVersion($versionId);
            DrillContentVersionStateMachine::assertContentMutable((string) $version['status']);
            $snapshot = [
                'title' => trim((string) $payload['title']),
                'customer_profile' => $payload['customer_profile'],
                'objectives' => $payload['objectives'],
                'key_actions' => $payload['key_actions'],
                'standard_expressions' => $payload['standard_expressions'],
                'risk_expressions' => $payload['risk_expressions'],
                'prompt_policy' => $payload['prompt_policy'],
            ];
            $update = $this->pdo->prepare(
                'UPDATE drill_scenario_versions SET title = ?, customer_profile_json = ?, objectives_json = ?, key_actions_json = ?, '
                . 'standard_expressions_json = ?, risk_expressions_json = ?, prompt_policy_json = ?, content_hash = ?, updated_by = ? WHERE id = ?'
            );
            $update->execute([
                $snapshot['title'],
                $this->json($snapshot['customer_profile']),
                $this->json($snapshot['objectives']),
                $this->json($snapshot['key_actions']),
                $this->json($snapshot['standard_expressions']),
                $this->json($snapshot['risk_expressions']),
                $this->json($snapshot['prompt_policy']),
                DrillContentVersionStateMachine::snapshotHash($snapshot),
                $actorStaffId,
                $versionId,
            ]);
            return ['version_id' => $versionId, 'status' => 'draft', 'content_hash' => DrillContentVersionStateMachine::snapshotHash($snapshot)];
        });
    }

    public function transitionScenarioVersion(int $versionId, string $event, int $actorStaffId, ?string $reason = null): array
    {
        $this->positiveId($actorStaffId, '操作员工 ID');
        return $this->transaction(function () use ($versionId, $event, $actorStaffId, $reason): array {
            $version = $this->lockScenarioVersion($versionId);
            $nextStatus = DrillContentVersionStateMachine::transition((string) $version['status'], $event);
            if ($nextStatus === 'published') {
                DrillContentPolicy::assertHumanReviewedCandidate(
                    (string) $version['source_type'],
                    $actorStaffId,
                    gmdate('Y-m-d H:i:s')
                );
            }

            $fields = ['status = ?', 'updated_by = ?'];
            $params = [$nextStatus, $actorStaffId];
            if ($event === 'submit_review') {
                $fields[] = 'submitted_by = ?';
                $fields[] = 'submitted_at = CURRENT_TIMESTAMP';
                $params[] = $actorStaffId;
            } elseif ($event === 'approve') {
                $fields[] = 'reviewed_by = ?';
                $fields[] = 'reviewed_at = CURRENT_TIMESTAMP';
                $fields[] = 'published_by = ?';
                $fields[] = 'published_at = CURRENT_TIMESTAMP';
                array_push($params, $actorStaffId, $actorStaffId);
            } elseif ($event === 'archive') {
                $fields[] = 'archived_by = ?';
                $fields[] = 'archived_at = CURRENT_TIMESTAMP';
                $params[] = $actorStaffId;
            }
            $params[] = $versionId;
            $update = $this->pdo->prepare('UPDATE drill_scenario_versions SET ' . implode(', ', $fields) . ' WHERE id = ?');
            $update->execute($params);
            if ($event === 'archive') {
                $archive = $this->pdo->prepare("UPDATE drill_scenarios SET status = 'archived' WHERE id = ?");
                $archive->execute([(int) $version['scenario_id']]);
            }
            $this->audit('scenario_version.' . $event, 'scenario_version', $versionId, $version, ['status' => $nextStatus], $actorStaffId, $reason);
            return ['version_id' => $versionId, 'status' => $nextStatus];
        });
    }

    public function reviseScenario(int $publishedVersionId, int $actorStaffId): array
    {
        return $this->transaction(function () use ($publishedVersionId, $actorStaffId): array {
            $version = $this->lockScenarioVersion($publishedVersionId);
            if ($version['status'] !== 'published') {
                throw new DomainException('只有已发布场景版本可以创建修订草稿。');
            }
            $nextVersionNo = $this->nextVersion('drill_scenario_versions', 'scenario_id', (int) $version['scenario_id']);
            $insert = $this->pdo->prepare(
                'INSERT INTO drill_scenario_versions '
                . '(scenario_id, version_no, status, title, customer_profile_json, objectives_json, key_actions_json, standard_expressions_json, '
                . 'risk_expressions_json, prompt_policy_json, content_hash, source_type, source_ref, created_by, updated_by) '
                . "SELECT scenario_id, ?, 'draft', title, customer_profile_json, objectives_json, key_actions_json, standard_expressions_json, "
                . "risk_expressions_json, prompt_policy_json, content_hash, 'revision', CONCAT('scenario-version:', id), ?, ? FROM drill_scenario_versions WHERE id = ?"
            );
            $insert->execute([$nextVersionNo, $actorStaffId, $actorStaffId, $publishedVersionId]);
            $newVersionId = (int) $this->pdo->lastInsertId();
            $copy = $this->pdo->prepare(
                'INSERT INTO drill_scenario_personas (scenario_version_id, dimension_id, value_code, source, source_ref) '
                . "SELECT ?, dimension_id, value_code, 'revision', CONCAT('scenario-version:', scenario_version_id) FROM drill_scenario_personas WHERE scenario_version_id = ?"
            );
            $copy->execute([$newVersionId, $publishedVersionId]);
            return ['version_id' => $newVersionId, 'version_no' => $nextVersionNo, 'status' => 'draft', 'source_version_id' => $publishedVersionId];
        });
    }

    public function listPublishedCatalog(int $domainId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT scenario.id AS scenario_id, scenario.scenario_code, scenario.name, scenario.status AS scenario_status, '
            . 'version.id AS version_id, version.version_no, version.status AS version_status, version.title '
            . 'FROM drill_scenarios scenario INNER JOIN drill_scenario_versions version ON version.scenario_id = scenario.id '
            . "WHERE scenario.domain_id = ? AND scenario.status = 'active' AND version.status = 'published' ORDER BY scenario.id, version.version_no DESC"
        );
        $stmt->execute([$domainId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function stableScenarioId(int $domainId, int $stageId, string $code, string $name, string $difficulty, string $sourceType, ?string $sourceRef): int
    {
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO drill_scenarios (domain_id, stage_id, scenario_code, name, difficulty, source_type, source_ref) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$domainId, $stageId, trim($code), trim($name), trim($difficulty), trim($sourceType), $sourceRef]);
        $select = $this->pdo->prepare('SELECT id, domain_id, stage_id FROM drill_scenarios WHERE domain_id = ? AND scenario_code = ? FOR UPDATE');
        $select->execute([$domainId, trim($code)]);
        $scenario = $select->fetch(PDO::FETCH_ASSOC);
        if (!$scenario || (int) $scenario['stage_id'] !== $stageId) {
            throw new DomainException('场景编码已绑定其他训练板块。');
        }
        return (int) $scenario['id'];
    }

    private function insertPersonas(int $versionId, int $domainId, array $persona, string $sourceType, ?string $sourceRef): void
    {
        $select = $this->pdo->prepare(
            "SELECT id FROM drill_persona_dimensions WHERE domain_id = ? AND dimension_code = ? AND value_code = ? AND status = 'active'"
        );
        $insert = $this->pdo->prepare(
            'INSERT INTO drill_scenario_personas (scenario_version_id, dimension_id, value_code, source, source_ref) VALUES (?, ?, ?, ?, ?)'
        );
        foreach ($persona as $dimensionCode => $valueCode) {
            $select->execute([$domainId, $dimensionCode, $valueCode]);
            $dimensionId = (int) $select->fetchColumn();
            if ($dimensionId <= 0) {
                throw new DomainException('画像值在保存前已失效。');
            }
            $insert->execute([$versionId, $dimensionId, $valueCode, $sourceType, $sourceRef]);
        }
    }

    private function allowedPersonaValues(int $domainId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT dimension_code, value_code FROM drill_persona_dimensions WHERE domain_id = ? AND status = 'active' ORDER BY sort_order, id"
        );
        $stmt->execute([$domainId]);
        $allowed = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $allowed[(string) $row['dimension_code']][] = (string) $row['value_code'];
        }
        return $allowed;
    }

    private function assertStageInDomain(int $stageId, int $domainId): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT stage.id FROM drill_process_stages stage INNER JOIN drill_process_versions version ON version.id = stage.process_version_id '
            . 'WHERE stage.id = ? AND version.domain_id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$stageId, $domainId]);
        if (!$stmt->fetchColumn()) {
            throw new DomainException('流程板块不属于指定训练域。');
        }
    }

    private function lockDomain(int $domainId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_training_domains WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$domainId]);
        $domain = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$domain || $domain['status'] !== 'active') {
            throw new DomainException('训练域不存在或已归档。');
        }
        return $domain;
    }

    private function lockScenarioVersion(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_scenario_versions WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$versionId]);
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$version) {
            throw new DomainException('场景版本不存在。');
        }
        return $version;
    }

    private function nextVersion(string $table, string $parentColumn, int $parentId): int
    {
        $allowed = [
            'drill_process_versions' => 'domain_id',
            'drill_scenario_versions' => 'scenario_id',
            'drill_rubric_versions' => 'rubric_id',
        ];
        if (($allowed[$table] ?? null) !== $parentColumn) {
            throw new LogicException('不支持的内容版本表。');
        }
        $stmt = $this->pdo->prepare(sprintf('SELECT version_no FROM %s WHERE %s = ? ORDER BY version_no FOR UPDATE', $table, $parentColumn));
        $stmt->execute([$parentId]);
        return DrillContentVersionStateMachine::nextVersionNo($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function audit(string $action, string $objectType, int $objectId, array $before, array $after, int $actorStaffId, ?string $reason): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO drill_audit_logs (actor_staff_id, action, object_type, object_id, before_snapshot_json, after_snapshot_json, reason) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$actorStaffId, $action, $objectType, $objectId, $this->json($before), $this->json($after), $reason]);
    }

    private function transaction(callable $operation): array
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('内容领域服务必须拥有业务事务。');
        }
        $this->pdo->beginTransaction();
        try {
            $result = $operation();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function positiveId(int $id, string $label): int
    {
        if ($id <= 0) {
            throw new InvalidArgumentException($label . ' 必须为正整数。');
        }
        return $id;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
