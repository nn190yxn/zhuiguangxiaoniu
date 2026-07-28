<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillContentPolicy.php';
require_once __DIR__ . '/DrillLearningService.php';

final class DrillRubricService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createDraft(
        int $domainId,
        int $processVersionId,
        array $config,
        array $mappings,
        int $actorStaffId,
        string $sourceType = 'manual',
        ?string $sourceRef = null
    ): array {
        DrillContentPolicy::assertRubricConfig($config);
        if ($actorStaffId <= 0) {
            throw new InvalidArgumentException('操作员工 ID 必须为正整数。');
        }

        return $this->transaction(function () use ($domainId, $processVersionId, $config, $mappings, $actorStaffId, $sourceType, $sourceRef): array {
            $stageStmt = $this->pdo->prepare(
                'SELECT stage.id, stage.stage_code FROM drill_process_stages stage '
                . 'INNER JOIN drill_process_versions version ON version.id = stage.process_version_id '
                . "WHERE version.id = ? AND version.domain_id = ? AND stage.status = 'active' ORDER BY stage.sort_order FOR UPDATE"
            );
            $stageStmt->execute([$processVersionId, $domainId]);
            $stages = $stageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if ($stages === []) {
                throw new DomainException('评分规则必须映射到同训练域的有效流程版本。');
            }
            DrillContentPolicy::assertDimensionMappings(
                $config['dimensions'],
                $mappings,
                array_column($stages, 'stage_code')
            );

            $rubricId = $this->stableRubricId($domainId, $config, $sourceType, $sourceRef);
            $versions = $this->pdo->prepare('SELECT version_no FROM drill_rubric_versions WHERE rubric_id = ? ORDER BY version_no FOR UPDATE');
            $versions->execute([$rubricId]);
            $versionNo = DrillContentVersionStateMachine::nextVersionNo($versions->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $snapshot = [
                'dimensions' => $config['dimensions'],
                'critical_items' => $config['critical_items'] ?? [],
                'score_policy' => $config['score_policy'],
                'max_score' => (float) $config['max_score'],
                'pass_score' => isset($config['pass_score']) ? (float) $config['pass_score'] : null,
            ];
            $insert = $this->pdo->prepare(
                'INSERT INTO drill_rubric_versions '
                . '(rubric_id, version_no, status, dimensions_json, critical_items_json, score_policy_json, max_score, pass_score, '
                . 'content_hash, source_type, source_ref, created_by, updated_by) '
                . "VALUES (?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $insert->execute([
                $rubricId,
                $versionNo,
                $this->json($snapshot['dimensions']),
                $this->json($snapshot['critical_items']),
                $this->json($snapshot['score_policy']),
                $snapshot['max_score'],
                $snapshot['pass_score'],
                DrillContentVersionStateMachine::snapshotHash($snapshot),
                $sourceType,
                $sourceRef,
                $actorStaffId,
                $actorStaffId,
            ]);
            $versionId = (int) $this->pdo->lastInsertId();
            $stageIds = array_column($stages, 'id', 'stage_code');
            $mappingInsert = $this->pdo->prepare(
                'INSERT INTO drill_rubric_stage_mappings '
                . '(domain_id, rubric_id, rubric_version_id, dimension_code, process_version_id, process_stage_id, mapping_weight, source_type, source_ref) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($mappings as $mapping) {
                $mappingInsert->execute([
                    $domainId,
                    $rubricId,
                    $versionId,
                    $mapping['dimension_code'],
                    $processVersionId,
                    (int) $stageIds[$mapping['stage_code']],
                    (float) ($mapping['weight'] ?? 1),
                    $sourceType,
                    $sourceRef,
                ]);
            }
            return ['rubric_id' => $rubricId, 'version_id' => $versionId, 'version_no' => $versionNo, 'status' => 'draft'];
        });
    }

    public function transitionVersion(int $versionId, string $event, int $actorStaffId): array
    {
        return $this->transaction(function () use ($versionId, $event, $actorStaffId): array {
            $select = $this->pdo->prepare('SELECT * FROM drill_rubric_versions WHERE id = ? LIMIT 1 FOR UPDATE');
            $select->execute([$versionId]);
            $version = $select->fetch(PDO::FETCH_ASSOC);
            if (!$version) {
                throw new DomainException('评分规则版本不存在。');
            }
            $nextStatus = DrillContentVersionStateMachine::transition((string) $version['status'], $event);
            if ($nextStatus === 'published') {
                $mappingCount = $this->pdo->prepare('SELECT COUNT(DISTINCT dimension_code) FROM drill_rubric_stage_mappings WHERE rubric_version_id = ?');
                $mappingCount->execute([$versionId]);
                $dimensions = json_decode((string) $version['dimensions_json'], true, 512, JSON_THROW_ON_ERROR);
                if ((int) $mappingCount->fetchColumn() !== count($dimensions)) {
                    throw new DomainException('评分规则存在未映射到流程板块的维度。');
                }
                (new DrillLearningService($this->pdo))->assertRubricPublishable($versionId);
            }
            $sets = ['status = ?', 'updated_by = ?'];
            $params = [$nextStatus, $actorStaffId];
            if ($event === 'submit_review') {
                array_push($sets, 'submitted_by = ?', 'submitted_at = CURRENT_TIMESTAMP');
                $params[] = $actorStaffId;
            } elseif ($event === 'approve') {
                array_push($sets, 'reviewed_by = ?', 'reviewed_at = CURRENT_TIMESTAMP', 'published_by = ?', 'published_at = CURRENT_TIMESTAMP');
                array_push($params, $actorStaffId, $actorStaffId);
            } elseif ($event === 'archive') {
                array_push($sets, 'archived_by = ?', 'archived_at = CURRENT_TIMESTAMP');
                $params[] = $actorStaffId;
            }
            $params[] = $versionId;
            $update = $this->pdo->prepare('UPDATE drill_rubric_versions SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $update->execute($params);
            return ['version_id' => $versionId, 'status' => $nextStatus];
        });
    }

    private function stableRubricId(int $domainId, array $config, string $sourceType, ?string $sourceRef): int
    {
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO drill_rubrics (domain_id, rubric_code, name, mode, source_type, source_ref) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $insert->execute([$domainId, $config['rubric_code'], $config['name'], $config['mode'], $sourceType, $sourceRef]);
        $select = $this->pdo->prepare('SELECT id, mode FROM drill_rubrics WHERE domain_id = ? AND rubric_code = ? FOR UPDATE');
        $select->execute([$domainId, $config['rubric_code']]);
        $rubric = $select->fetch(PDO::FETCH_ASSOC);
        if (!$rubric || $rubric['mode'] !== $config['mode']) {
            throw new DomainException('评分规则编码已绑定其他评分模式。');
        }
        return (int) $rubric['id'];
    }

    private function transaction(callable $operation): array
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('评分规则服务必须拥有业务事务。');
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

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
