<?php

declare(strict_types=1);

require_once __DIR__ . '/DrillContentPolicy.php';
require_once __DIR__ . '/DrillNewSignContentPackage.php';

final class DrillNewSignContentImporter
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function import(int $actorStaffId): array
    {
        if ($actorStaffId <= 0) {
            throw new InvalidArgumentException('导入员工 ID 必须为正整数。');
        }
        $package = DrillNewSignContentPackage::payload();
        DrillContentPolicy::assertOrderedStages($this->expectedStages());

        $this->pdo->beginTransaction();
        try {
            $existing = $this->existingBatch($package['batch_code']);
            if ($existing !== null) {
                $this->pdo->commit();
                return $existing + ['idempotent' => true];
            }

            $domainId = $this->domainId((string) $package['domain_code']);
            $processVersionId = $this->publishedProcessVersionId($domainId);
            $batchId = $this->createBatch($domainId, $package, $actorStaffId);
            $this->importPersonas($batchId, $domainId, $package['personas']);

            $rubricVersions = [];
            foreach ($package['rubrics'] as $rubric) {
                DrillContentPolicy::assertRubricConfig($rubric);
                DrillContentPolicy::assertDimensionMappings(
                    $rubric['dimensions'],
                    $package['mappings'],
                    array_column($this->expectedStages(), 'stage_code')
                );
                $rubricVersions[$rubric['rubric_code']] = $this->importRubric(
                    $batchId,
                    $domainId,
                    $processVersionId,
                    $rubric,
                    $package['mappings'],
                    $actorStaffId
                );
            }

            $materialItems = [];
            foreach ($package['reference_materials'] as $material) {
                $materialItems[$material['material_code']] = $this->importReferenceMaterial($batchId, $domainId, $material, $actorStaffId);
            }
            foreach ($package['calibrations'] as $index => $calibration) {
                $this->importCalibration($batchId, $domainId, $calibration, $rubricVersions, $actorStaffId, $index + 1);
            }
            $this->importReviewIssues($batchId, $domainId, $package['review_issues'], $materialItems);

            $summary = [
                'personas' => array_sum(array_map('count', $package['personas'])),
                'rubrics' => count($package['rubrics']),
                'rubric_mappings' => count($package['mappings']) * count($package['rubrics']),
                'reference_materials' => count($package['reference_materials']),
                'calibrations' => count($package['calibrations']),
                'blocking_issues' => count($package['review_issues']),
            ];
            $complete = $this->pdo->prepare(
                "UPDATE drill_content_import_batches SET status = 'review_pending', summary_json = ?, completed_at = CURRENT_TIMESTAMP WHERE id = ?"
            );
            $complete->execute([$this->json($summary), $batchId]);
            $this->audit($actorStaffId, 'content_package.import', 'content_import_batch', $batchId, null, $summary);
            $this->pdo->commit();
            return ['batch_id' => $batchId, 'status' => 'review_pending', 'summary' => $summary, 'idempotent' => false];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function importPersonas(int $batchId, int $domainId, array $personas): void
    {
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO drill_persona_dimensions '
            . '(domain_id, dimension_code, dimension_name, value_code, name, description, sort_order, status, source_type, source_ref) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, 'active', 'content_package', ?)"
        );
        $dimensionOrder = 0;
        foreach ($personas as $dimensionCode => $values) {
            $dimensionOrder++;
            $valueOrder = 0;
            foreach ($values as $valueCode => $name) {
                $valueOrder++;
                $sortOrder = $dimensionOrder * 100 + $valueOrder;
                $insert->execute([$domainId, $dimensionCode, $dimensionCode, $valueCode, $name, null, $sortOrder, DrillNewSignContentPackage::BATCH_CODE]);
                $select = $this->pdo->prepare('SELECT id FROM drill_persona_dimensions WHERE domain_id = ? AND dimension_code = ? AND value_code = ?');
                $select->execute([$domainId, $dimensionCode, $valueCode]);
                $this->importItem($batchId, $domainId, 'persona', $dimensionCode . '.' . $valueCode, (int) $select->fetchColumn(), null, '受控画像维度', ['name' => $name]);
            }
        }
    }

    private function importRubric(
        int $batchId,
        int $domainId,
        int $processVersionId,
        array $rubric,
        array $mappings,
        int $actorStaffId
    ): array {
        $stableInsert = $this->pdo->prepare(
            'INSERT IGNORE INTO drill_rubrics (domain_id, rubric_code, name, mode, source_type, source_ref) '
            . "VALUES (?, ?, ?, ?, 'content_package', ?)"
        );
        $stableInsert->execute([$domainId, $rubric['rubric_code'], $rubric['name'], $rubric['mode'], $rubric['source_ref']]);
        $stableSelect = $this->pdo->prepare('SELECT id FROM drill_rubrics WHERE domain_id = ? AND rubric_code = ? FOR UPDATE');
        $stableSelect->execute([$domainId, $rubric['rubric_code']]);
        $rubricId = (int) $stableSelect->fetchColumn();

        $versionSelect = $this->pdo->prepare('SELECT id, version_no FROM drill_rubric_versions WHERE rubric_id = ? AND source_ref = ? FOR UPDATE');
        $versionSelect->execute([$rubricId, $rubric['source_ref']]);
        $version = $versionSelect->fetch(PDO::FETCH_ASSOC);
        if (!$version) {
            $numbers = $this->pdo->prepare('SELECT version_no FROM drill_rubric_versions WHERE rubric_id = ? ORDER BY version_no FOR UPDATE');
            $numbers->execute([$rubricId]);
            $versionNo = DrillContentVersionStateMachine::nextVersionNo($numbers->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $snapshot = [
                'dimensions' => $rubric['dimensions'],
                'critical_items' => $rubric['critical_items'],
                'score_policy' => $rubric['score_policy'] + ['allowed_contexts' => $rubric['contexts']],
            ];
            $versionInsert = $this->pdo->prepare(
                'INSERT INTO drill_rubric_versions '
                . '(rubric_id, version_no, status, dimensions_json, critical_items_json, score_policy_json, max_score, pass_score, '
                . "content_hash, source_type, source_ref, created_by, updated_by) VALUES (?, ?, 'draft', ?, ?, ?, ?, ?, ?, 'content_package', ?, ?, ?)"
            );
            $versionInsert->execute([
                $rubricId,
                $versionNo,
                $this->json($rubric['dimensions']),
                $this->json($rubric['critical_items']),
                $this->json($snapshot['score_policy']),
                $rubric['max_score'],
                $rubric['pass_score'],
                DrillContentVersionStateMachine::snapshotHash($snapshot),
                $rubric['source_ref'],
                $actorStaffId,
                $actorStaffId,
            ]);
            $version = ['id' => (int) $this->pdo->lastInsertId(), 'version_no' => $versionNo];
        }
        $versionId = (int) $version['id'];
        $stageStmt = $this->pdo->prepare('SELECT id, stage_code FROM drill_process_stages WHERE process_version_id = ?');
        $stageStmt->execute([$processVersionId]);
        $stageIds = array_column($stageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id', 'stage_code');
        $mappingInsert = $this->pdo->prepare(
            'INSERT IGNORE INTO drill_rubric_stage_mappings '
            . '(domain_id, rubric_id, rubric_version_id, dimension_code, process_version_id, process_stage_id, mapping_weight, source_type, source_ref) '
            . "VALUES (?, ?, ?, ?, ?, ?, ?, 'content_package', ?)"
        );
        foreach ($mappings as $mapping) {
            $mappingInsert->execute([$domainId, $rubricId, $versionId, $mapping['dimension_code'], $processVersionId, $stageIds[$mapping['stage_code']], $mapping['weight'], $rubric['source_ref']]);
        }
        $this->importItem($batchId, $domainId, 'rubric', $rubric['rubric_code'], $rubricId, $versionId, $rubric['source_ref'], $rubric);
        return ['rubric_id' => $rubricId, 'version_id' => $versionId];
    }

    private function importReferenceMaterial(int $batchId, int $domainId, array $material, int $actorStaffId): array
    {
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO drill_reference_materials (domain_id, material_code, name, material_type, created_by_staff_id) VALUES (?, ?, ?, ?, ?)'
        );
        $insert->execute([$domainId, $material['material_code'], $material['name'], $material['material_type'], $actorStaffId]);
        $select = $this->pdo->prepare('SELECT id FROM drill_reference_materials WHERE domain_id = ? AND material_code = ? FOR UPDATE');
        $select->execute([$domainId, $material['material_code']]);
        $materialId = (int) $select->fetchColumn();
        $contentHash = DrillContentVersionStateMachine::snapshotHash($material['content']);
        $versionInsert = $this->pdo->prepare(
            'INSERT IGNORE INTO drill_reference_material_versions '
            . '(reference_material_id, domain_id, version_code, title, source_locator, source_name, content_snapshot_json, content_hash, '
            . "authorization_status, status, review_summary_json) VALUES (?, ?, 'v1', ?, ?, ?, ?, ?, 'pending', 'review_pending', ?)"
        );
        $versionInsert->execute([
            $materialId,
            $domainId,
            $material['name'],
            $material['source_ref'],
            '新签skill-1.zip',
            $this->json($material['content']),
            $contentHash,
            $this->json(['release_blocked' => true, 'reason' => '等待业务核验、授权和有效期']),
        ]);
        $versionSelect = $this->pdo->prepare('SELECT id FROM drill_reference_material_versions WHERE reference_material_id = ? AND version_code = ?');
        $versionSelect->execute([$materialId, 'v1']);
        $versionId = (int) $versionSelect->fetchColumn();
        $itemId = $this->importItem($batchId, $domainId, 'reference_material', $material['material_code'], $materialId, $versionId, $material['source_ref'], $material);
        return ['item_id' => $itemId, 'material_id' => $materialId, 'version_id' => $versionId];
    }

    private function importCalibration(int $batchId, int $domainId, array $calibration, array $rubricVersions, int $actorStaffId, int $sequence): void
    {
        $rubric = $rubricVersions[$calibration['rubric_code']] ?? null;
        if (!$rubric) {
            throw new DomainException('校准锚点缺少对应评分规则。');
        }
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO drill_score_calibration_versions '
            . '(domain_id, rubric_id, rubric_version_id, evaluation_context, version_no, test_sample_snapshot_json, human_benchmark_snapshot_json, '
            . "weight_changes_json, threshold_changes_json, version_notes, sample_size, agreement_rate, status) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, 0, 0, 'draft')"
        );
        $insert->execute([
            $domainId,
            $rubric['rubric_id'],
            $rubric['version_id'],
            $calibration['evaluation_context'],
            $this->json([]),
            $this->json($calibration['anchors']),
            $this->json([]),
            $this->json(['grade_thresholds' => ['excellent' => 85, 'good' => 70, 'qualified' => 60]]),
            '来自新签评分校准锚点，等待样本试评和人工对照。',
        ]);
        $select = $this->pdo->prepare(
            'SELECT id FROM drill_score_calibration_versions WHERE rubric_version_id = ? AND evaluation_context = ? AND version_no = 1'
        );
        $select->execute([$rubric['version_id'], $calibration['evaluation_context']]);
        $calibrationId = (int) $select->fetchColumn();
        $this->importItem($batchId, $domainId, 'calibration', $calibration['rubric_code'] . '.' . $calibration['evaluation_context'], $calibrationId, $calibrationId, '新签skill/references/评分校准锚点.md', $calibration);
    }

    private function importReviewIssues(int $batchId, int $domainId, array $issues, array $materialItems): void
    {
        $itemByIssue = [
            'package_lessons_conflict' => $materialItems['new_sign_packages_pricing_v1']['item_id'],
            'brand_numbers_unverified' => $materialItems['new_sign_brand_course_fab_v1']['item_id'],
            'effect_claims_unverified' => $materialItems['new_sign_brand_course_fab_v1']['item_id'],
            'case_authorization_missing' => $materialItems['new_sign_case_library_v1']['item_id'],
            'material_validity_missing' => $materialItems['new_sign_calibration_anchors_v1']['item_id'],
        ];
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO drill_content_review_issues '
            . '(batch_id, item_id, domain_id, issue_code, issue_category, severity, subject, details_json, issue_fingerprint, status) '
            . "VALUES (?, ?, ?, ?, ?, 'blocking', ?, ?, ?, 'open')"
        );
        foreach ($issues as $issue) {
            $fingerprint = hash('sha256', $issue['code'] . ':' . $this->json($issue['details']));
            $insert->execute([$batchId, $itemByIssue[$issue['code']] ?? null, $domainId, $issue['code'], $issue['category'], $issue['subject'], $this->json($issue['details']), $fingerprint]);
        }
    }

    private function importItem(int $batchId, int $domainId, string $type, string $code, ?int $targetId, ?int $versionId, string $sourceRef, array $snapshot): int
    {
        $json = $this->json($snapshot);
        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO drill_content_import_items '
            . '(batch_id, domain_id, content_type, stable_code, target_id, target_version_id, review_status, source_ref, payload_hash, source_snapshot_json) '
            . "VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, ?, ?)"
        );
        $insert->execute([$batchId, $domainId, $type, $code, $targetId, $versionId, $sourceRef, hash('sha256', $json), $json]);
        $select = $this->pdo->prepare('SELECT id FROM drill_content_import_items WHERE batch_id = ? AND content_type = ? AND stable_code = ?');
        $select->execute([$batchId, $type, $code]);
        return (int) $select->fetchColumn();
    }

    private function createBatch(int $domainId, array $package, int $actorStaffId): int
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO drill_content_import_batches (domain_id, batch_code, source_name, source_hash, status, summary_json, imported_by_staff_id) '
            . "VALUES (?, ?, ?, ?, 'importing', ?, ?)"
        );
        $insert->execute([$domainId, $package['batch_code'], $package['source_name'], DrillNewSignContentPackage::hash(), $this->json([]), $actorStaffId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function existingBatch(string $batchCode): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id AS batch_id, status, summary_json FROM drill_content_import_batches WHERE batch_code = ? FOR UPDATE');
        $stmt->execute([$batchCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['batch_id'] = (int) $row['batch_id'];
        $row['summary'] = json_decode((string) $row['summary_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['summary_json']);
        return $row;
    }

    private function domainId(string $domainCode): int
    {
        $stmt = $this->pdo->prepare("SELECT id FROM drill_training_domains WHERE domain_code = ? AND status = 'active' FOR UPDATE");
        $stmt->execute([$domainCode]);
        $id = (int) $stmt->fetchColumn();
        if ($id <= 0) {
            throw new DomainException('新签训练域不存在或已归档。');
        }
        return $id;
    }

    private function publishedProcessVersionId(int $domainId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM drill_process_versions WHERE domain_id = ? AND status = 'published' ORDER BY version_no DESC LIMIT 1 FOR UPDATE"
        );
        $stmt->execute([$domainId]);
        $id = (int) $stmt->fetchColumn();
        if ($id <= 0) {
            throw new DomainException('新签训练域缺少已发布流程版本。');
        }
        return $id;
    }

    private function expectedStages(): array
    {
        $codes = ['lead_preparation', 'invitation_confirmation', 'arrival_reception', 'needs_diagnosis', 'assessment_experience', 'solution_value', 'objection_signing_handoff', 'followup_referral'];
        return array_map(static fn(string $code, int $index): array => ['stage_code' => $code, 'sort_order' => $index + 1], $codes, array_keys($codes));
    }

    private function audit(int $actorStaffId, string $action, string $type, int $id, ?array $before, array $after): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO drill_audit_logs (actor_staff_id, action, object_type, object_id, before_snapshot_json, after_snapshot_json) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$actorStaffId, $action, $type, $id, $before === null ? null : $this->json($before), $this->json($after)]);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
