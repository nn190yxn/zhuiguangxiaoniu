<?php

declare(strict_types=1);

require_once __DIR__ . '/ResumeProcessingService.php';

final class ResumeClassificationReviewService
{
    private PDO $pdo;
    private RecruitmentPermissionService $permissions;

    public function __construct(PDO $pdo, RecruitmentPermissionService $permissions)
    {
        $this->pdo = $pdo;
        $this->permissions = $permissions;
    }

    public function list(array $scope): array
    {
        [$where, $params] = $this->permissions->requirementWhereClause($scope, 'requirement');
        $stmt = $this->pdo->prepare(
            'SELECT document.id, document.batch_id, document.classification_status, document.classification_version_id, document.status AS processing_status, file.original_name '
            . 'FROM recruitment_resume_documents document '
            . 'JOIN recruitment_resume_batches batch ON batch.id = document.batch_id '
            . 'JOIN recruitment_resume_batch_requirements batch_requirement ON batch_requirement.batch_id = batch.id '
            . 'JOIN recruitment_requirements requirement ON requirement.id = batch_requirement.requirement_id '
            . 'LEFT JOIN recruitment_resume_document_pages page ON page.document_id = document.id AND page.page_order = 1 '
            . 'LEFT JOIN recruitment_resume_files file ON file.id = page.resume_file_id '
            . "WHERE document.classification_status IN ('needs_confirmation', 'awaiting_rule') AND {$where} "
            . 'GROUP BY document.id ORDER BY document.updated_at ASC LIMIT 100'
        );
        $stmt->execute($params);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($items as &$item) {
            $item['candidates'] = $this->candidates((int) $item['classification_version_id'], $scope);
        }
        unset($item);
        return ['list' => $items, 'scope' => $scope];
    }

    public function confirm(int $documentId, int $requirementId, int $versionId, string $reason, array $scope, int $staffId): array
    {
        if ($documentId <= 0 || $requirementId <= 0 || $versionId <= 0 || trim($reason) === '') {
            throw new RecruitmentAdminException('简历、岗位、分类版本和确认原因均为必填项');
        }
        if (!$this->permissions->canAccessRequirement($scope, $requirementId)) {
            throw new RecruitmentAdminException('你没有权限选择该招聘岗位', 403);
        }
        $this->pdo->beginTransaction();
        try {
            $document = $this->lockedDocument($documentId);
            if ((int) $document['classification_version_id'] !== $versionId || (string) $document['classification_status'] !== 'needs_confirmation') {
                throw new RecruitmentAdminException('这份简历刚刚被其他人处理，请刷新后查看', 409);
            }
            $candidate = $this->pdo->prepare('SELECT 1 FROM recruitment_resume_batch_requirements WHERE batch_id = ? AND requirement_id = ? LIMIT 1');
            $candidate->execute([(int) $document['batch_id'], $requirementId]);
            if (!$candidate->fetchColumn()) {
                throw new RecruitmentAdminException('所选岗位不在该批次的候选岗位范围内', 422);
            }
            $previous = $this->classification($versionId);
            $newVersionId = $this->insertVersion($documentId, $previous, $requirementId, $staffId, 'manual_confirm');
            $this->pdo->prepare("UPDATE recruitment_resume_documents SET assigned_requirement_id = ?, classification_status = 'classified', classification_version_id = ? WHERE id = ?")->execute([$requirementId, $newVersionId, $documentId]);
            $this->pdo->prepare('INSERT INTO recruitment_resume_classification_reviews (document_id, before_version_id, after_version_id, selected_requirement_id, review_reason, reviewer_staff_id) VALUES (?, ?, ?, ?, ?, ?)')->execute([$documentId, $versionId, $newVersionId, $requirementId, mb_substr(trim($reason), 0, 1000, 'UTF-8'), $staffId]);
            $this->pdo->commit();
            $job = (new ResumeProcessingService($this->pdo))->reprocess($documentId, $staffId);
            return ['document_id' => $documentId, 'classification_version_id' => $newVersionId, 'selected_requirement_id' => $requirementId, 'classification_status' => 'classified', 'job' => $job];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function reclassify(int $documentId, int $versionId, array $scope, int $staffId): array
    {
        $document = $this->lockedDocument($documentId);
        if ((int) $document['classification_version_id'] !== $versionId) {
            throw new RecruitmentAdminException('这份简历刚刚被其他人处理，请刷新后查看', 409);
        }
        $this->assertAccessibleDocument($documentId, $scope);
        return (new ResumeProcessingService($this->pdo))->reprocess($documentId, $staffId);
    }

    private function lockedDocument(int $documentId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_resume_documents WHERE id = ? FOR UPDATE');
        $stmt->execute([$documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            throw new RecruitmentAdminException('简历文档不存在', 404);
        }
        return $document;
    }

    private function assertAccessibleDocument(int $documentId, array $scope): void
    {
        [$where, $params] = $this->permissions->requirementWhereClause($scope, 'requirement');
        $stmt = $this->pdo->prepare('SELECT 1 FROM recruitment_resume_documents document JOIN recruitment_resume_batch_requirements scope_requirement ON scope_requirement.batch_id = document.batch_id JOIN recruitment_requirements requirement ON requirement.id = scope_requirement.requirement_id WHERE document.id = ? AND ' . $where . ' LIMIT 1');
        $stmt->execute(array_merge([$documentId], $params));
        if (!$stmt->fetchColumn()) {
            throw new RecruitmentAdminException('你没有权限处理该简历', 403);
        }
    }

    private function classification(int $versionId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_resume_classification_versions WHERE id = ? LIMIT 1');
        $stmt->execute([$versionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: throw new RecruitmentAdminException('分类版本不存在', 404);
    }

    private function insertVersion(int $documentId, array $previous, int $requirementId, int $staffId, string $reason): int
    {
        $versionNo = $this->pdo->prepare('SELECT COALESCE(MAX(version_no), 0) + 1 FROM recruitment_resume_classification_versions WHERE document_id = ?');
        $versionNo->execute([$documentId]);
        $insert = $this->pdo->prepare("INSERT INTO recruitment_resume_classification_versions (document_id, version_no, candidate_scope_hash, classifier_version, status, selected_requirement_id, confidence_level, confidence_score, reason_code, evidence_json, created_by) VALUES (?, ?, ?, ?, 'classified', ?, 'high', 100, ?, ?, ?)");
        $insert->execute([$documentId, (int) $versionNo->fetchColumn(), (string) $previous['candidate_scope_hash'], 'mixed-resume-v1', $requirementId, $reason, (string) ($previous['evidence_json'] ?? ''), $staffId ?: null]);
        return (int) $this->pdo->lastInsertId();
    }

    private function candidates(int $versionId, array $scope): array
    {
        if ($versionId <= 0) {
            return [];
        }
        [$where, $params] = $this->permissions->requirementWhereClause($scope, 'requirement');
        $stmt = $this->pdo->prepare('SELECT candidate.requirement_id, requirement.position_name_snapshot, candidate.rank_no, candidate.score, candidate.evidence_json FROM recruitment_resume_classification_candidates candidate JOIN recruitment_requirements requirement ON requirement.id = candidate.requirement_id WHERE candidate.classification_version_id = ? AND ' . $where . ' ORDER BY candidate.rank_no ASC');
        $stmt->execute(array_merge([$versionId], $params));
        $candidates = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($candidates as &$candidate) {
            $evidence = json_decode((string) ($candidate['evidence_json'] ?? ''), true);
            $candidate['evidence_summary'] = $this->evidenceSummary(is_array($evidence) ? $evidence : []);
            unset($candidate['evidence_json']);
        }
        unset($candidate);
        return $candidates;
    }

    private function evidenceSummary(array $evidence): array
    {
        $labels = ['filename' => '文件名', 'keyword' => '关键词', 'hard_condition' => '硬性条件', 'experience' => '相关经验'];
        $summary = [];
        foreach ($evidence as $item) {
            if (!is_array($item) || empty($item['matched'])) {
                continue;
            }
            $label = $labels[(string) ($item['type'] ?? '')] ?? '';
            if ($label !== '' && !in_array($label, $summary, true)) {
                $summary[] = $label;
            }
        }
        return $summary;
    }
}
