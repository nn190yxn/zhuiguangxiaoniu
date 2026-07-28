<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillContentVersionStateMachine.php';
require_once __DIR__ . '/DrillLearningPolicy.php';

final class DrillLearningService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createKnowledgePointDraft(int $domainId, array $payload, int $actorStaffId): array
    {
        DrillLearningPolicy::assertKnowledgePayload($payload);
        $this->positiveId($actorStaffId, '操作员工 ID');

        return $this->transaction(function () use ($domainId, $payload, $actorStaffId): array {
            $this->lockDomain($domainId);
            $insertPoint = $this->pdo->prepare(
                'INSERT IGNORE INTO drill_knowledge_points (domain_id, knowledge_code, name, description, created_by_staff_id) VALUES (?, ?, ?, ?, ?)'
            );
            $insertPoint->execute([
                $domainId,
                trim((string) $payload['knowledge_code']),
                trim((string) ($payload['name'] ?? $payload['title'])),
                $payload['description'] ?? null,
                $actorStaffId,
            ]);
            $point = $this->lockKnowledgePointByCode($domainId, (string) $payload['knowledge_code']);
            $versionNo = $this->nextVersion('drill_knowledge_point_versions', 'knowledge_point_id', (int) $point['id']);
            $snapshot = ['title' => trim((string) $payload['title']), 'content' => $payload['content']];
            $insertVersion = $this->pdo->prepare(
                "INSERT INTO drill_knowledge_point_versions (knowledge_point_id, domain_id, version_no, title, content_json, content_hash, status, review_status) VALUES (?, ?, ?, ?, ?, ?, 'draft', 'pending')"
            );
            $insertVersion->execute([
                (int) $point['id'],
                $domainId,
                $versionNo,
                $snapshot['title'],
                $this->json($snapshot['content']),
                DrillContentVersionStateMachine::snapshotHash($snapshot),
            ]);
            return [
                'knowledge_point_id' => (int) $point['id'],
                'version_id' => (int) $this->pdo->lastInsertId(),
                'version_no' => $versionNo,
                'status' => 'draft',
            ];
        });
    }

    public function createLearningResourceDraft(int $domainId, array $payload, int $actorStaffId): array
    {
        DrillLearningPolicy::assertResourcePayload($payload);
        $this->positiveId($actorStaffId, '操作员工 ID');

        return $this->transaction(function () use ($domainId, $payload, $actorStaffId): array {
            $this->lockDomain($domainId);
            $insertResource = $this->pdo->prepare(
                'INSERT IGNORE INTO drill_learning_resources (domain_id, resource_code, name, resource_type, created_by_staff_id) VALUES (?, ?, ?, ?, ?)'
            );
            $insertResource->execute([
                $domainId,
                trim((string) $payload['resource_code']),
                trim((string) ($payload['name'] ?? $payload['title'])),
                (string) $payload['resource_type'],
                $actorStaffId,
            ]);
            $resource = $this->lockResourceByCode($domainId, (string) $payload['resource_code']);
            if ($resource['resource_type'] !== $payload['resource_type']) {
                throw new DomainException('学习资源编码已绑定其他资源类型。');
            }
            $versionNo = $this->nextResourceVersionNo((int) $resource['id']);
            $versionCode = trim((string) ($payload['version_code'] ?? 'v' . $versionNo));
            $snapshot = [
                'title' => trim((string) $payload['title']),
                'mobile_locator' => trim((string) $payload['mobile_locator']),
                'content' => $payload['content'],
                'estimated_minutes' => (int) $payload['estimated_minutes'],
            ];
            $insertVersion = $this->pdo->prepare(
                "INSERT INTO drill_learning_resource_versions (learning_resource_id, domain_id, version_code, title, mobile_locator, content_json, content_hash, estimated_minutes, status, review_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', 'pending')"
            );
            $insertVersion->execute([
                (int) $resource['id'],
                $domainId,
                $versionCode,
                $snapshot['title'],
                $snapshot['mobile_locator'],
                $this->json($snapshot['content']),
                DrillContentVersionStateMachine::snapshotHash($snapshot),
                $snapshot['estimated_minutes'],
            ]);
            return [
                'learning_resource_id' => (int) $resource['id'],
                'version_id' => (int) $this->pdo->lastInsertId(),
                'version_code' => $versionCode,
                'status' => 'draft',
            ];
        });
    }

    public function transitionKnowledgePointVersion(int $versionId, string $event, int $actorStaffId): array
    {
        return $this->transitionContentVersion('knowledge', $versionId, $event, $actorStaffId);
    }

    public function transitionLearningResourceVersion(int $versionId, string $event, int $actorStaffId): array
    {
        return $this->transitionContentVersion('resource', $versionId, $event, $actorStaffId);
    }

    public function createMappingDraft(int $domainId, int $rubricVersionId, array $links, int $actorStaffId): array
    {
        $this->positiveId($actorStaffId, '操作员工 ID');

        return $this->transaction(function () use ($domainId, $rubricVersionId, $links): array {
            $this->lockDomain($domainId);
            $rubric = $this->lockRubricVersion($domainId, $rubricVersionId);
            $criticalItems = $this->decodeArray((string) $rubric['critical_items_json']);
            $expectedCriteria = DrillLearningPolicy::reinforceableCriteria($criticalItems);
            DrillLearningPolicy::assertMappingLinks($links, $expectedCriteria);

            $versions = $this->pdo->prepare('SELECT version_no FROM drill_knowledge_mapping_versions WHERE rubric_version_id = ? ORDER BY version_no FOR UPDATE');
            $versions->execute([$rubricVersionId]);
            $versionNo = DrillContentVersionStateMachine::nextVersionNo($versions->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $mappingHash = DrillContentVersionStateMachine::snapshotHash(['criteria' => $expectedCriteria, 'links' => $links]);
            $insertMapping = $this->pdo->prepare(
                "INSERT INTO drill_knowledge_mapping_versions (domain_id, rubric_id, rubric_version_id, version_no, expected_reinforceable_criteria, mapped_reinforceable_criteria, mapped_knowledge_points, mobile_resource_ready_points, mapping_hash, status) VALUES (?, ?, ?, ?, ?, 0, 0, 0, ?, 'draft')"
            );
            $insertMapping->execute([$domainId, (int) $rubric['rubric_id'], $rubricVersionId, $versionNo, count($expectedCriteria), $mappingHash]);
            $mappingVersionId = (int) $this->pdo->lastInsertId();
            $this->insertMappingLinks($mappingVersionId, $domainId, $rubricVersionId, $links);

            return ['mapping_version_id' => $mappingVersionId, 'version_no' => $versionNo, 'status' => 'draft'];
        });
    }

    public function transitionMappingVersion(int $mappingVersionId, string $event, int $actorStaffId): array
    {
        $this->positiveId($actorStaffId, '操作员工 ID');

        return $this->transaction(function () use ($mappingVersionId, $event, $actorStaffId): array {
            $mapping = $this->lockMappingVersion($mappingVersionId);
            $nextStatus = DrillLearningPolicy::transition((string) $mapping['status'], $event);
            if ($nextStatus === 'published') {
                $preflight = $this->mappingPreflight($mapping, true, null);
                if ($preflight['failures'] !== []) {
                    return [
                        'mapping_version_id' => $mappingVersionId,
                        'status' => (string) $mapping['status'],
                        'publication_blocked' => true,
                        'failures' => $preflight['failures'],
                    ];
                }
                $retire = $this->pdo->prepare("UPDATE drill_knowledge_mapping_versions SET status = 'retired' WHERE rubric_version_id = ? AND status = 'published' AND id <> ?");
                $retire->execute([(int) $mapping['rubric_version_id'], $mappingVersionId]);
                $update = $this->pdo->prepare(
                    "UPDATE drill_knowledge_mapping_versions SET status = 'published', mapped_reinforceable_criteria = ?, mapped_knowledge_points = ?, mobile_resource_ready_points = ?, published_by_staff_id = ?, published_at = CURRENT_TIMESTAMP WHERE id = ?"
                );
                $update->execute([
                    $preflight['mapped_criteria'],
                    $preflight['mapped_points'],
                    $preflight['ready_points'],
                    $actorStaffId,
                    $mappingVersionId,
                ]);
            } else {
                $update = $this->pdo->prepare('UPDATE drill_knowledge_mapping_versions SET status = ? WHERE id = ?');
                $update->execute([$nextStatus, $mappingVersionId]);
            }
            return ['mapping_version_id' => $mappingVersionId, 'status' => $nextStatus, 'publication_blocked' => false];
        });
    }

    public function assertRubricPublishable(int $rubricVersionId): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new LogicException('评分规则知识映射预检必须在发布事务内执行。');
        }
        $stmt = $this->pdo->prepare(
            "SELECT mapping.* FROM drill_knowledge_mapping_versions mapping WHERE mapping.rubric_version_id = ? AND mapping.status = 'published' ORDER BY mapping.version_no DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$rubricVersionId]);
        $mapping = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mapping) {
            throw new DomainException('评分规则缺少已发布的知识映射版本。');
        }
        $preflight = $this->mappingPreflight($mapping, false, null);
        if ($preflight['failures'] !== []) {
            throw new DomainException('评分规则知识映射不完整：' . implode('；', array_column($preflight['failures'], 'reason')));
        }
    }

    public function preparationLearning(int $staffId, int $domainId, int $rubricVersionId): array
    {
        $this->positiveId($staffId, '员工 ID');
        $mapping = $this->publishedMapping($domainId, $rubricVersionId);
        if ($mapping === null) {
            return ['mapping_version_id' => null, 'knowledge_points' => [], 'ready' => false];
        }
        $rows = $this->publishedCatalogRows((int) $mapping['id'], $domainId, null);
        $progress = $this->progressByResource($staffId, array_column($rows, 'learning_resource_version_id'));
        foreach ($rows as &$row) {
            $row['progress'] = $progress[(int) $row['learning_resource_version_id']] ?? [
                'status' => 'not_started',
                'progress_percent' => 0,
                'completed_at' => null,
            ];
        }
        unset($row);
        return [
            'mapping_version_id' => (int) $mapping['id'],
            'knowledge_points' => $this->groupCatalog($rows),
            'ready' => $rows !== [],
        ];
    }

    public function generateRecommendations(int $attemptId, int $evaluationId): array
    {
        return $this->transaction(function () use ($attemptId, $evaluationId): array {
            $evaluation = $this->lockCompletedEvaluation($attemptId, $evaluationId);
            $mapping = $this->publishedMapping((int) $evaluation['domain_id'], (int) $evaluation['rubric_version_id'], true);
            if ($mapping === null) {
                throw new DomainException('评分时没有可用的已发布知识映射。');
            }
            $failedCriteria = DrillLearningPolicy::failedCriteria($this->decodeArray((string) $evaluation['critical_results_json']));
            $recommendations = [];
            $unresolved = [];
            foreach ($failedCriteria as $criterionCode) {
                $evidence = $this->criterionEvidence($evaluationId, $attemptId, $criterionCode);
                if ($evidence === null) {
                    $unresolved[] = ['criterion_code' => $criterionCode, 'reason' => 'missing_evidence'];
                    continue;
                }
                $catalog = $this->publishedCatalogRows((int) $mapping['id'], (int) $evaluation['domain_id'], $criterionCode);
                if ($catalog === []) {
                    $gap = $this->criterionGapIdentity((int) $mapping['id'], $criterionCode, (string) $evidence['dimension_code']);
                    $this->openGap(
                        $mapping,
                        $gap['dimension_code'],
                        $criterionCode,
                        $gap['knowledge_point_id'],
                        $gap['gap_type'],
                        ['evaluation_id' => $evaluationId, 'evidence_id' => (int) $evidence['id']],
                        $attemptId
                    );
                    $unresolved[] = ['criterion_code' => $criterionCode, 'reason' => $gap['gap_type']];
                    continue;
                }
                foreach ($catalog as $item) {
                    $reason = [
                        'criterion_code' => $criterionCode,
                        'dimension_code' => (string) $evidence['dimension_code'],
                        'evidence' => [
                            'id' => (int) $evidence['id'],
                            'quoted_text' => (string) $evidence['quoted_text'],
                            'speaker_role' => (string) $evidence['speaker_role'],
                            'starts_ms' => (int) $evidence['starts_ms'],
                            'ends_ms' => (int) $evidence['ends_ms'],
                        ],
                        'mapping_hash' => (string) $mapping['mapping_hash'],
                    ];
                    $insert = $this->pdo->prepare(
                        'INSERT IGNORE INTO drill_learning_recommendations (staff_id, domain_id, attempt_id, evaluation_id, evidence_id, mapping_version_id, rubric_version_id, criterion_code, knowledge_point_id, knowledge_point_version_id, learning_resource_id, learning_resource_version_id, reason_snapshot_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $insert->execute([
                        (int) $evaluation['staff_id'],
                        (int) $evaluation['domain_id'],
                        $attemptId,
                        $evaluationId,
                        (int) $evidence['id'],
                        (int) $mapping['id'],
                        (int) $evaluation['rubric_version_id'],
                        $criterionCode,
                        (int) $item['knowledge_point_id'],
                        (int) $item['knowledge_point_version_id'],
                        (int) $item['learning_resource_id'],
                        (int) $item['learning_resource_version_id'],
                        $this->json($reason),
                    ]);
                    $recommendations[] = $item + ['criterion_code' => $criterionCode, 'reason' => $reason];
                }
            }
            return [
                'mapping_version_id' => (int) $mapping['id'],
                'recommendations' => $recommendations,
                'unresolved' => $unresolved,
                'retry' => ['allowed' => true, 'source_attempt_id' => $attemptId],
            ];
        });
    }

    public function recordProgress(
        int $staffId,
        int $domainId,
        int $learningResourceVersionId,
        float $progressPercent,
        ?int $recommendationId = null
    ): array {
        if ($progressPercent < 0 || $progressPercent > 100) {
            throw new InvalidArgumentException('学习进度必须位于 0 到 100 之间。');
        }
        return $this->transaction(function () use ($staffId, $domainId, $learningResourceVersionId, $progressPercent, $recommendationId): array {
            $binding = $recommendationId === null
                ? $this->latestPublishedResourceBinding($domainId, $learningResourceVersionId)
                : $this->lockRecommendationBinding($staffId, $domainId, $learningResourceVersionId, $recommendationId);
            $status = $progressPercent >= 100 ? 'completed' : ($progressPercent > 0 ? 'in_progress' : 'not_started');
            $upsert = $this->pdo->prepare(
                'INSERT INTO drill_learning_progress (staff_id, domain_id, mapping_version_id, knowledge_point_id, knowledge_point_version_id, learning_resource_id, learning_resource_version_id, recommendation_id, status, progress_percent, started_at, completed_at) '
                . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, IF(? > 0, CURRENT_TIMESTAMP, NULL), IF(? >= 100, CURRENT_TIMESTAMP, NULL)) '
                . 'ON DUPLICATE KEY UPDATE mapping_version_id = VALUES(mapping_version_id), knowledge_point_id = VALUES(knowledge_point_id), knowledge_point_version_id = VALUES(knowledge_point_version_id), recommendation_id = COALESCE(VALUES(recommendation_id), recommendation_id), status = VALUES(status), progress_percent = VALUES(progress_percent), started_at = IF(VALUES(progress_percent) > 0, COALESCE(started_at, CURRENT_TIMESTAMP), started_at), completed_at = IF(VALUES(progress_percent) >= 100, COALESCE(completed_at, CURRENT_TIMESTAMP), NULL)'
            );
            $upsert->execute([
                $staffId,
                $domainId,
                (int) $binding['mapping_version_id'],
                (int) $binding['knowledge_point_id'],
                (int) $binding['knowledge_point_version_id'],
                (int) $binding['learning_resource_id'],
                $learningResourceVersionId,
                $recommendationId,
                $status,
                $progressPercent,
                $progressPercent,
                $progressPercent,
            ]);
            if ($recommendationId !== null) {
                $update = $this->pdo->prepare('UPDATE drill_learning_recommendations SET status = ? WHERE id = ? AND staff_id = ?');
                $recommendationStatus = match ($status) {
                    'not_started' => 'recommended',
                    'in_progress' => 'started',
                    'completed' => 'completed',
                };
                $update->execute([$recommendationStatus, $recommendationId, $staffId]);
            }
            return [
                'knowledge_point_id' => (int) $binding['knowledge_point_id'],
                'learning_resource_version_id' => $learningResourceVersionId,
                'status' => $status,
                'progress_percent' => $progressPercent,
                'retry' => ['allowed' => $status === 'completed', 'source_attempt_id' => isset($binding['attempt_id']) ? (int) $binding['attempt_id'] : null],
            ];
        });
    }

    private function transitionContentVersion(string $type, int $versionId, string $event, int $actorStaffId): array
    {
        $this->positiveId($actorStaffId, '操作员工 ID');
        return $this->transaction(function () use ($type, $versionId, $event, $actorStaffId): array {
            $config = match ($type) {
                'knowledge' => ['table' => 'drill_knowledge_point_versions', 'parent' => 'knowledge_point_id'],
                'resource' => ['table' => 'drill_learning_resource_versions', 'parent' => 'learning_resource_id'],
                default => throw new LogicException('未知学习内容类型。'),
            };
            $stmt = $this->pdo->prepare(sprintf('SELECT * FROM %s WHERE id = ? LIMIT 1 FOR UPDATE', $config['table']));
            $stmt->execute([$versionId]);
            $version = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$version) {
                throw new DomainException('学习内容版本不存在。');
            }
            $nextStatus = DrillLearningPolicy::transition((string) $version['status'], $event);
            if ($nextStatus === 'published' && $type === 'knowledge') {
                $failures = $this->knowledgePublicationFailures($version);
                if ($failures !== []) {
                    return [
                        'version_id' => $versionId,
                        'status' => (string) $version['status'],
                        'publication_blocked' => true,
                        'failures' => $failures,
                    ];
                }
            }
            if ($nextStatus === 'published' && $type === 'resource' && trim((string) $version['mobile_locator']) === '') {
                throw new DomainException('学习资源缺少移动端入口。');
            }
            if ($nextStatus === 'published') {
                $retire = $this->pdo->prepare(sprintf("UPDATE %s SET status = 'retired' WHERE %s = ? AND status = 'published' AND id <> ?", $config['table'], $config['parent']));
                $retire->execute([(int) $version[$config['parent']], $versionId]);
            } elseif ($nextStatus === 'retired') {
                $this->createRetirementGaps($type, $version);
            }
            $sets = ['status = ?'];
            $params = [$nextStatus];
            if ($event === 'submit_review') {
                $sets[] = "review_status = 'pending'";
            } elseif ($event === 'reject') {
                $sets[] = "review_status = 'rejected'";
            } elseif ($event === 'approve') {
                array_push($sets, "review_status = 'approved'", 'published_by_staff_id = ?', 'published_at = CURRENT_TIMESTAMP');
                $params[] = $actorStaffId;
            }
            $params[] = $versionId;
            $update = $this->pdo->prepare(sprintf('UPDATE %s SET %s WHERE id = ?', $config['table'], implode(', ', $sets)));
            $update->execute($params);
            return ['version_id' => $versionId, 'status' => $nextStatus, 'publication_blocked' => false];
        });
    }

    private function insertMappingLinks(int $mappingVersionId, int $domainId, int $rubricVersionId, array $links): void
    {
        $insertPoint = $this->pdo->prepare(
            'INSERT INTO drill_rubric_knowledge_links (mapping_version_id, domain_id, rubric_version_id, dimension_code, criterion_code, knowledge_point_id, knowledge_point_version_id, is_primary) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $insertResource = $this->pdo->prepare(
            'INSERT IGNORE INTO drill_knowledge_resource_links (mapping_version_id, domain_id, knowledge_point_id, knowledge_point_version_id, learning_resource_id, learning_resource_version_id, priority) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($links as $link) {
            $point = $this->lockKnowledgeVersion($domainId, (int) $link['knowledge_point_version_id']);
            $insertPoint->execute([
                $mappingVersionId,
                $domainId,
                $rubricVersionId,
                trim((string) $link['dimension_code']),
                trim((string) $link['criterion_code']),
                (int) $point['knowledge_point_id'],
                (int) $point['id'],
                !empty($link['is_primary']) ? 1 : 0,
            ]);
            foreach (array_values(array_unique(array_map('intval', $link['learning_resource_version_ids']))) as $index => $resourceVersionId) {
                $resource = $this->lockResourceVersion($domainId, $resourceVersionId);
                $insertResource->execute([
                    $mappingVersionId,
                    $domainId,
                    (int) $point['knowledge_point_id'],
                    (int) $point['id'],
                    (int) $resource['learning_resource_id'],
                    (int) $resource['id'],
                    (int) ($link['priority'] ?? 100) + $index,
                ]);
            }
        }
    }

    private function mappingPreflight(array $mapping, bool $createGaps, ?int $sourceAttemptId): array
    {
        $rubricStmt = $this->pdo->prepare('SELECT critical_items_json FROM drill_rubric_versions WHERE id = ? LIMIT 1 FOR UPDATE');
        $rubricStmt->execute([(int) $mapping['rubric_version_id']]);
        $criticalItemsJson = $rubricStmt->fetchColumn();
        if ($criticalItemsJson === false) {
            throw new DomainException('知识映射引用的评分规则版本不存在。');
        }
        $expectedCriteria = DrillLearningPolicy::reinforceableCriteria($this->decodeArray((string) $criticalItemsJson));
        $stmt = $this->pdo->prepare(
            'SELECT link.dimension_code, link.criterion_code, link.knowledge_point_id, link.knowledge_point_version_id, point.status AS knowledge_status, '
            . 'resource.learning_resource_id, resource.learning_resource_version_id, resource_version.status AS resource_status, resource_version.mobile_locator '
            . 'FROM drill_rubric_knowledge_links link '
            . 'INNER JOIN drill_knowledge_point_versions point ON point.id = link.knowledge_point_version_id '
            . 'LEFT JOIN drill_knowledge_resource_links resource ON resource.mapping_version_id = link.mapping_version_id AND resource.knowledge_point_version_id = link.knowledge_point_version_id '
            . 'LEFT JOIN drill_learning_resource_versions resource_version ON resource_version.id = resource.learning_resource_version_id '
            . 'WHERE link.mapping_version_id = ? ORDER BY link.criterion_code, link.knowledge_point_id, resource.priority FOR UPDATE'
        );
        $stmt->execute([(int) $mapping['id']]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $criteria = [];
        $points = [];
        $readyPoints = [];
        $failures = [];
        foreach ($rows as $row) {
            $criterionCode = (string) $row['criterion_code'];
            $pointId = (int) $row['knowledge_point_id'];
            $criteria[$criterionCode] = true;
            $points[$pointId] = true;
            $publishedResource = ($row['knowledge_status'] ?? null) === 'published'
                && ($row['resource_status'] ?? null) === 'published'
                && trim((string) ($row['mobile_locator'] ?? '')) !== '';
            if ($publishedResource) {
                $readyPoints[$pointId] = true;
            }
        }
        foreach ($expectedCriteria as $criterionCode) {
            if (!isset($criteria[$criterionCode])) {
                $failure = ['criterion_code' => $criterionCode, 'reason' => 'missing_knowledge'];
                $failures[] = $failure;
                if ($createGaps) {
                    $this->openGap($mapping, 'general', $criterionCode, null, 'missing_knowledge', $failure, $sourceAttemptId);
                }
            }
        }
        foreach ($rows as $row) {
            $pointId = (int) $row['knowledge_point_id'];
            if (!isset($readyPoints[$pointId])) {
                $identity = (string) $row['criterion_code'] . ':' . $pointId;
                if (!isset($failures[$identity])) {
                    $failure = ['criterion_code' => (string) $row['criterion_code'], 'knowledge_point_id' => $pointId, 'reason' => 'missing_mobile_resource'];
                    $failures[$identity] = $failure;
                    if ($createGaps) {
                        $this->openGap($mapping, (string) $row['dimension_code'], (string) $row['criterion_code'], $pointId, 'missing_mobile_resource', $failure, $sourceAttemptId);
                    }
                }
            }
        }
        $failures = array_values($failures);
        return [
            'mapped_criteria' => count(array_intersect($expectedCriteria, array_keys($criteria))),
            'mapped_points' => count($points),
            'ready_points' => count($readyPoints),
            'failures' => $failures,
        ];
    }

    private function knowledgePublicationFailures(array $version): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT mapping.*, link.dimension_code, link.criterion_code FROM drill_rubric_knowledge_links link '
            . 'INNER JOIN drill_knowledge_mapping_versions mapping ON mapping.id = link.mapping_version_id '
            . "WHERE link.knowledge_point_version_id = ? AND mapping.status IN ('draft', 'review_pending', 'published') FOR UPDATE"
        );
        $stmt->execute([(int) $version['id']]);
        $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($mappings === []) {
            return [['reason' => 'missing_mapping']];
        }
        $resourceStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM drill_knowledge_resource_links link INNER JOIN drill_learning_resource_versions version ON version.id = link.learning_resource_version_id WHERE link.knowledge_point_version_id = ? AND version.status = 'published' AND TRIM(version.mobile_locator) <> ''"
        );
        $resourceStmt->execute([(int) $version['id']]);
        if ((int) $resourceStmt->fetchColumn() > 0) {
            return [];
        }
        foreach ($mappings as $mapping) {
            $this->openGap(
                $mapping,
                (string) $mapping['dimension_code'],
                (string) $mapping['criterion_code'],
                (int) $version['knowledge_point_id'],
                'missing_mobile_resource',
                ['knowledge_point_version_id' => (int) $version['id']],
                null
            );
        }
        return [['reason' => 'missing_mobile_resource']];
    }

    private function openGap(
        array $mapping,
        string $dimensionCode,
        string $criterionCode,
        ?int $knowledgePointId,
        string $gapType,
        array $details,
        ?int $sourceAttemptId
    ): void {
        $fingerprint = DrillLearningPolicy::gapFingerprint(
            (int) $mapping['domain_id'],
            (int) $mapping['id'],
            (int) $mapping['rubric_version_id'],
            $dimensionCode,
            $criterionCode,
            $knowledgePointId,
            $gapType
        );
        $stmt = $this->pdo->prepare(
            "INSERT INTO drill_content_gaps (domain_id, mapping_version_id, rubric_version_id, dimension_code, criterion_code, knowledge_point_id, source_attempt_id, gap_type, gap_fingerprint, status, details_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?) "
            . 'ON DUPLICATE KEY UPDATE source_attempt_id = COALESCE(source_attempt_id, VALUES(source_attempt_id)), details_json = VALUES(details_json), updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            (int) $mapping['domain_id'],
            (int) $mapping['id'],
            (int) $mapping['rubric_version_id'],
            $dimensionCode,
            $criterionCode,
            $knowledgePointId,
            $sourceAttemptId,
            $gapType,
            $fingerprint,
            $this->json($details),
        ]);
    }

    private function criterionGapIdentity(int $mappingVersionId, string $criterionCode, string $fallbackDimension): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT dimension_code, knowledge_point_id FROM drill_rubric_knowledge_links WHERE mapping_version_id = ? AND criterion_code = ? ORDER BY is_primary DESC, id LIMIT 1'
        );
        $stmt->execute([$mappingVersionId, $criterionCode]);
        $link = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$link) {
            return ['dimension_code' => $fallbackDimension, 'knowledge_point_id' => null, 'gap_type' => 'missing_knowledge'];
        }
        return [
            'dimension_code' => (string) $link['dimension_code'],
            'knowledge_point_id' => (int) $link['knowledge_point_id'],
            'gap_type' => 'missing_mobile_resource',
        ];
    }

    private function createRetirementGaps(string $type, array $version): void
    {
        if ($type === 'knowledge') {
            $sql = 'SELECT mapping.*, rubric_link.dimension_code, rubric_link.criterion_code, rubric_link.knowledge_point_id '
                . 'FROM drill_rubric_knowledge_links rubric_link INNER JOIN drill_knowledge_mapping_versions mapping ON mapping.id = rubric_link.mapping_version_id '
                . "WHERE rubric_link.knowledge_point_version_id = ? AND mapping.status = 'published' FOR UPDATE";
        } else {
            $sql = 'SELECT mapping.*, rubric_link.dimension_code, rubric_link.criterion_code, rubric_link.knowledge_point_id '
                . 'FROM drill_knowledge_resource_links resource_link '
                . 'INNER JOIN drill_knowledge_mapping_versions mapping ON mapping.id = resource_link.mapping_version_id '
                . 'INNER JOIN drill_rubric_knowledge_links rubric_link ON rubric_link.mapping_version_id = resource_link.mapping_version_id AND rubric_link.knowledge_point_version_id = resource_link.knowledge_point_version_id '
                . "WHERE resource_link.learning_resource_version_id = ? AND mapping.status = 'published' "
                . 'AND NOT EXISTS (SELECT 1 FROM drill_knowledge_resource_links alternative_link INNER JOIN drill_learning_resource_versions alternative_version ON alternative_version.id = alternative_link.learning_resource_version_id '
                . "WHERE alternative_link.mapping_version_id = resource_link.mapping_version_id AND alternative_link.knowledge_point_version_id = resource_link.knowledge_point_version_id AND alternative_link.learning_resource_version_id <> resource_link.learning_resource_version_id AND alternative_version.status = 'published' AND TRIM(alternative_version.mobile_locator) <> '') FOR UPDATE";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([(int) $version['id']]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $mapping) {
            $this->openGap(
                $mapping,
                (string) $mapping['dimension_code'],
                (string) $mapping['criterion_code'],
                (int) $mapping['knowledge_point_id'],
                'invalid_resource',
                ['retired_type' => $type, 'retired_version_id' => (int) $version['id']],
                null
            );
        }
    }

    private function publishedCatalogRows(int $mappingVersionId, int $domainId, ?string $criterionCode): array
    {
        $sql = 'SELECT mapping.id AS mapping_version_id, mapping.status AS mapping_status, link.domain_id, link.dimension_code, link.criterion_code, '
            . 'point.id AS knowledge_point_id, point_version.id AS knowledge_point_version_id, point_version.title AS knowledge_title, point_version.status AS knowledge_status, '
            . 'resource.id AS learning_resource_id, resource.resource_type, resource_version.id AS learning_resource_version_id, resource_version.title AS resource_title, '
            . 'resource_version.mobile_locator, resource_version.estimated_minutes, resource_version.status AS resource_status '
            . 'FROM drill_knowledge_mapping_versions mapping '
            . 'INNER JOIN drill_rubric_knowledge_links link ON link.mapping_version_id = mapping.id '
            . 'INNER JOIN drill_knowledge_points point ON point.id = link.knowledge_point_id '
            . 'INNER JOIN drill_knowledge_point_versions point_version ON point_version.id = link.knowledge_point_version_id '
            . 'INNER JOIN drill_knowledge_resource_links resource_link ON resource_link.mapping_version_id = mapping.id AND resource_link.knowledge_point_version_id = point_version.id '
            . 'INNER JOIN drill_learning_resources resource ON resource.id = resource_link.learning_resource_id '
            . 'INNER JOIN drill_learning_resource_versions resource_version ON resource_version.id = resource_link.learning_resource_version_id '
            . 'WHERE mapping.id = ? AND mapping.domain_id = ?';
        $params = [$mappingVersionId, $domainId];
        if ($criterionCode !== null) {
            $sql .= ' AND link.criterion_code = ?';
            $params[] = $criterionCode;
        }
        $sql .= ' ORDER BY link.criterion_code, link.is_primary DESC, resource_link.priority, resource_version.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return DrillLearningPolicy::publishedRecommendations($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $mappingVersionId, $domainId);
    }

    private function groupCatalog(array $rows): array
    {
        $points = [];
        foreach ($rows as $row) {
            $pointVersionId = (int) $row['knowledge_point_version_id'];
            if (!isset($points[$pointVersionId])) {
                $points[$pointVersionId] = [
                    'knowledge_point_id' => (int) $row['knowledge_point_id'],
                    'knowledge_point_version_id' => $pointVersionId,
                    'title' => (string) $row['knowledge_title'],
                    'trigger_criteria' => [],
                    'resources' => [],
                ];
            }
            $points[$pointVersionId]['trigger_criteria'][(string) $row['criterion_code']] = true;
            $resourceVersionId = (int) $row['learning_resource_version_id'];
            $points[$pointVersionId]['resources'][$resourceVersionId] = [
                'learning_resource_id' => (int) $row['learning_resource_id'],
                'learning_resource_version_id' => $resourceVersionId,
                'title' => (string) $row['resource_title'],
                'resource_type' => (string) $row['resource_type'],
                'mobile_locator' => (string) $row['mobile_locator'],
                'estimated_minutes' => (int) $row['estimated_minutes'],
                'progress' => $row['progress'] ?? null,
            ];
        }
        foreach ($points as &$point) {
            $point['trigger_criteria'] = array_keys($point['trigger_criteria']);
            $point['resources'] = array_values($point['resources']);
        }
        unset($point);
        return array_values($points);
    }

    private function progressByResource(int $staffId, array $resourceVersionIds): array
    {
        if ($resourceVersionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($resourceVersionIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT learning_resource_version_id, status, progress_percent, completed_at FROM drill_learning_progress WHERE staff_id = ? AND learning_resource_version_id IN (' . $placeholders . ')'
        );
        $stmt->execute(array_merge([$staffId], array_map('intval', $resourceVersionIds)));
        $progress = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $progress[(int) $row['learning_resource_version_id']] = [
                'status' => (string) $row['status'],
                'progress_percent' => (float) $row['progress_percent'],
                'completed_at' => $row['completed_at'],
            ];
        }
        return $progress;
    }

    private function lockCompletedEvaluation(int $attemptId, int $evaluationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT evaluation.*, attempt.staff_id, attempt.domain_id FROM drill_evaluations evaluation INNER JOIN drill_attempts attempt ON attempt.id = evaluation.attempt_id WHERE evaluation.id = ? AND evaluation.attempt_id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$evaluationId, $attemptId]);
        $evaluation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$evaluation || $evaluation['status'] !== 'completed' || $evaluation['critical_results_json'] === null) {
            throw new DomainException('学习推荐只能基于已完成且包含关键项结果的评分。');
        }
        return $evaluation;
    }

    private function criterionEvidence(int $evaluationId, int $attemptId, string $criterionCode): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM drill_evaluation_evidence WHERE evaluation_id = ? AND attempt_id = ? AND criterion_code = ? AND status = 'supported' ORDER BY id LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$evaluationId, $attemptId, $criterionCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function publishedMapping(int $domainId, int $rubricVersionId, bool $lock = false): ?array
    {
        $sql = "SELECT * FROM drill_knowledge_mapping_versions WHERE domain_id = ? AND rubric_version_id = ? AND status = 'published' ORDER BY version_no DESC LIMIT 1";
        if ($lock) {
            $sql .= ' FOR UPDATE';
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$domainId, $rubricVersionId]);
        $mapping = $stmt->fetch(PDO::FETCH_ASSOC);
        return $mapping ?: null;
    }

    private function latestPublishedResourceBinding(int $domainId, int $resourceVersionId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT link.mapping_version_id, link.knowledge_point_id, link.knowledge_point_version_id, link.learning_resource_id FROM drill_knowledge_resource_links link INNER JOIN drill_knowledge_mapping_versions mapping ON mapping.id = link.mapping_version_id INNER JOIN drill_knowledge_point_versions point ON point.id = link.knowledge_point_version_id INNER JOIN drill_learning_resource_versions resource ON resource.id = link.learning_resource_version_id WHERE link.domain_id = ? AND link.learning_resource_version_id = ? AND mapping.status = 'published' AND point.status = 'published' AND resource.status = 'published' AND TRIM(resource.mobile_locator) <> '' ORDER BY mapping.version_no DESC, link.priority LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$domainId, $resourceVersionId]);
        $binding = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$binding) {
            throw new DomainException('学习资源不属于当前已发布知识映射。');
        }
        return $binding;
    }

    private function lockRecommendationBinding(int $staffId, int $domainId, int $resourceVersionId, int $recommendationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT mapping_version_id, knowledge_point_id, knowledge_point_version_id, learning_resource_id, attempt_id FROM drill_learning_recommendations WHERE id = ? AND staff_id = ? AND domain_id = ? AND learning_resource_version_id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$recommendationId, $staffId, $domainId, $resourceVersionId]);
        $binding = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$binding) {
            throw new DomainException('学习推荐与员工、训练域或资源版本不匹配。');
        }
        return $binding;
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

    private function lockKnowledgePointByCode(int $domainId, string $code): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_knowledge_points WHERE domain_id = ? AND knowledge_code = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$domainId, trim($code)]);
        $point = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$point || $point['status'] !== 'active') {
            throw new DomainException('知识点稳定身份不可用。');
        }
        return $point;
    }

    private function lockResourceByCode(int $domainId, string $code): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_learning_resources WHERE domain_id = ? AND resource_code = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$domainId, trim($code)]);
        $resource = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$resource || $resource['status'] !== 'active') {
            throw new DomainException('学习资源稳定身份不可用。');
        }
        return $resource;
    }

    private function lockRubricVersion(int $domainId, int $rubricVersionId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT version.*, rubric.domain_id FROM drill_rubric_versions version INNER JOIN drill_rubrics rubric ON rubric.id = version.rubric_id WHERE version.id = ? AND rubric.domain_id = ? LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([$rubricVersionId, $domainId]);
        $rubric = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rubric) {
            throw new DomainException('评分规则版本不属于指定训练域。');
        }
        return $rubric;
    }

    private function lockKnowledgeVersion(int $domainId, int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_knowledge_point_versions WHERE id = ? AND domain_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$versionId, $domainId]);
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$version) {
            throw new DomainException('知识点版本不属于指定训练域。');
        }
        return $version;
    }

    private function lockResourceVersion(int $domainId, int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_learning_resource_versions WHERE id = ? AND domain_id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$versionId, $domainId]);
        $version = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$version) {
            throw new DomainException('学习资源版本不属于指定训练域。');
        }
        return $version;
    }

    private function lockMappingVersion(int $mappingVersionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM drill_knowledge_mapping_versions WHERE id = ? LIMIT 1 FOR UPDATE');
        $stmt->execute([$mappingVersionId]);
        $mapping = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$mapping) {
            throw new DomainException('知识映射版本不存在。');
        }
        return $mapping;
    }

    private function nextVersion(string $table, string $parentColumn, int $parentId): int
    {
        $allowed = [
            'drill_knowledge_point_versions' => 'knowledge_point_id',
            'drill_learning_resource_versions' => 'learning_resource_id',
        ];
        if (($allowed[$table] ?? null) !== $parentColumn) {
            throw new LogicException('不支持的学习内容版本表。');
        }
        $stmt = $this->pdo->prepare(sprintf('SELECT version_no FROM %s WHERE %s = ? ORDER BY version_no FOR UPDATE', $table, $parentColumn));
        $stmt->execute([$parentId]);
        return DrillContentVersionStateMachine::nextVersionNo($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    private function nextResourceVersionNo(int $resourceId): int
    {
        $stmt = $this->pdo->prepare('SELECT version_code FROM drill_learning_resource_versions WHERE learning_resource_id = ? ORDER BY id FOR UPDATE');
        $stmt->execute([$resourceId]);
        return count($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) + 1;
    }

    private function transaction(callable $operation): array
    {
        if ($this->pdo->inTransaction()) {
            throw new LogicException('学习领域服务必须拥有业务事务。');
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

    private function decodeArray(string $json): array
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) {
            throw new DomainException('学习领域引用的 JSON 结构无效。');
        }
        return $value;
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
