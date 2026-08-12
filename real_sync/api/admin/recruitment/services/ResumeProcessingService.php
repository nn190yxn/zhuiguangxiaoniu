<?php

declare(strict_types=1);

require_once __DIR__ . '/ResumeTextExtractor.php';
require_once __DIR__ . '/ResumeFieldNormalizer.php';
require_once __DIR__ . '/ResumeAiAdapter.php';
require_once __DIR__ . '/ResumeCandidateService.php';
require_once __DIR__ . '/ResumeMatchingService.php';
require_once __DIR__ . '/ResumeGradeService.php';
require_once __DIR__ . '/ResumeClassificationService.php';
require_once dirname(__DIR__) . '/platform/RecruitmentPlatformJobAdapter.php';

final class ResumeProcessingService
{
    private PDO $pdo;
    private ResumeTextExtractor $extractor;
    private ResumeFieldNormalizer $normalizer;
    private ResumeAiAdapter $ai;
    private ResumeCandidateService $candidates;
    private ResumeMatchingService $matching;
    private ResumeGradeService $grading;
    private ResumeClassificationService $classification;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->extractor = new ResumeTextExtractor($pdo);
        $this->normalizer = new ResumeFieldNormalizer();
        $this->ai = new ResumeAiAdapter($pdo);
        $this->candidates = new ResumeCandidateService($pdo);
        $this->matching = new ResumeMatchingService($pdo);
        $this->grading = new ResumeGradeService($pdo);
        $this->classification = new ResumeClassificationService($pdo);
    }

    public function processJob(array $job): array
    {
        $jobType = (string) ($job['job_type'] ?? '');
        if ($jobType === 'match') {
            return $this->processMatch($job);
        }
        if ($jobType === 'grade') {
            return $this->processGrade($job);
        }
        if ($jobType !== 'extract') {
            throw new RecruitmentAdminException('Worker 任务类型无效：' . $jobType, 422);
        }
        $documentId = (int) $job['document_id'];
        $mixed = $this->isMixedDocument($documentId);
        if ($mixed) {
            $textResult = $this->extractor->extract($documentId, null, (int) $job['id']);
            $profile = $this->normalizer->protectProfile($this->normalizer->deterministicProfile($textResult['pages']));
            $classification = $this->classification->classify($documentId, $profile);
            if (($classification['status'] ?? '') !== 'classified') {
                $this->completeDocument($documentId, $this->documentBatchId($documentId));
                return ['processing_version_id' => 0, 'classification' => $classification];
            }
        }
        $context = $this->documentContext($documentId);
        $processing = $this->processingVersion($context, (string) $job['idempotency_hash']);
        $processingVersionId = (int) $processing['id'];
        $local = $this->existingExtraction($processingVersionId);
        if ($local === null) {
            $textResult = $textResult ?? $this->extractor->extract((int) $context['document_id'], $processingVersionId, (int) $job['id']);
            $profile = $profile ?? $this->normalizer->protectProfile($this->normalizer->deterministicProfile($textResult['pages']));
            $local = ['profile' => $profile, 'pages' => $textResult['pages'], 'text_hash' => hash('sha256', $textResult['text'])];
            $stmt = $this->pdo->prepare(
                "INSERT INTO recruitment_extraction_results (processing_version_id, fields_json, confidence_json, status) VALUES (?, ?, ?, 'succeeded')"
            );
            $stmt->execute([
                $processingVersionId,
                json_encode($local, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode(array_map(static fn (array $field): float => (float) $field['confidence'], $profile), JSON_UNESCAPED_SLASHES),
            ]);
        }

        $model = $this->existingModelResult($processingVersionId);
        if ($model === null) {
            $aiProfile = $this->ai->extractProfile(
                $local['pages'],
                $context,
                (int) $context['document_id'],
                $processingVersionId,
                (int) $job['id']
            );
            $model = $this->normalizer->protectProfile(ResumeProfileSchema::merge($local['profile'], $aiProfile, $local['pages']));
            $stmt = $this->pdo->prepare(
                "INSERT INTO recruitment_model_results (processing_version_id, model_output_json, evidence_summary_json, status) VALUES (?, ?, ?, 'succeeded')"
            );
            $stmt->execute([
                $processingVersionId,
                json_encode($model, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                json_encode($this->evidenceSummary($model), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $nextHash = hash('sha256', $processing['content_hash'] . ':match');
        $this->enqueueNextJob((int) $context['document_id'], 'match', $nextHash, $processingVersionId);
        $this->markDocumentExtracted((int) $context['document_id']);
        return ['processing_version_id' => $processingVersionId, 'profile' => $model, 'pages' => $local['pages']];
    }

    private function processMatch(array $job): array
    {
        $processingVersionId = $this->jobProcessingVersion($job);
        $profile = $this->existingModelResult($processingVersionId);
        if ($profile === null) {
            throw new RecruitmentAdminException('匹配任务缺少模型结构化结果', 409);
        }
        $application = $this->candidates->applicationForProcessing($processingVersionId, $profile);
        $context = $this->documentContext((int) $job['document_id']);
        $pages = $this->loadPages($processingVersionId);
        $evidence = $this->matching->match((int) $application['id'], $profile, $context, $pages);
        $nextHash = hash('sha256', (string) $job['idempotency_hash'] . ':grade');
        $this->enqueueNextJob((int) $job['document_id'], 'grade', $nextHash, $processingVersionId);
        return ['processing_version_id' => $processingVersionId, 'application_id' => (int) $application['id'], 'evidence_count' => count($evidence)];
    }

    private function processGrade(array $job): array
    {
        $processingVersionId = $this->jobProcessingVersion($job);
        $stmt = $this->pdo->prepare('SELECT * FROM recruitment_applications WHERE current_processing_version_id = ? LIMIT 1');
        $stmt->execute([$processingVersionId]);
        $application = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$application) {
            throw new RecruitmentAdminException('评分任务缺少候选人投递', 409);
        }
        $evidenceStmt = $this->pdo->prepare('SELECT * FROM recruitment_match_evidence WHERE application_id = ? ORDER BY id ASC');
        $evidenceStmt->execute([(int) $application['id']]);
        $context = $this->documentContext((int) $job['document_id']);
        $grade = $this->grading->grade((int) $application['id'], $processingVersionId, $evidenceStmt->fetchAll(PDO::FETCH_ASSOC) ?: [], $context);
        $this->completeDocument((int) $job['document_id'], (int) $context['batch_id']);
        return ['processing_version_id' => $processingVersionId, 'application_id' => (int) $application['id'], 'grade' => $grade];
    }

    private function enqueueNextJob(int $documentId, string $jobType, string $idempotencyHash, int $processingVersionId): void
    {
        $ownsTransaction = !$this->pdo->inTransaction();
        if ($ownsTransaction) {
            $this->pdo->beginTransaction();
        }
        try {
            $next = $this->pdo->prepare(
                'INSERT IGNORE INTO recruitment_resume_jobs (document_id, job_type, status, idempotency_hash, processing_version_id) VALUES (?, ?, \'pending\', ?, ?)'
            );
            $next->execute([$documentId, $jobType, $idempotencyHash, $processingVersionId]);
            $stmt = $this->pdo->prepare('SELECT * FROM recruitment_resume_jobs WHERE document_id = ? AND idempotency_hash = ? LIMIT 1');
            $stmt->execute([$documentId, $idempotencyHash]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                throw new RuntimeException('招聘后续任务创建失败');
            }
            (new RecruitmentPlatformJobAdapter($this->pdo))->enqueue($job);
            if ($ownsTransaction) {
                $this->pdo->commit();
            }
        } catch (Throwable $error) {
            if ($ownsTransaction && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function reprocess(int $documentId, int $staffId): array
    {
        if ($this->documentBatchId($documentId) <= 0) {
            throw new RecruitmentAdminException('简历文档不存在', 404);
        }
        $this->pdo->beginTransaction();
        try {
            $running = $this->pdo->prepare("SELECT COUNT(*) FROM recruitment_resume_jobs WHERE document_id = ? AND status = 'running'");
            $running->execute([$documentId]);
            if ((int) $running->fetchColumn() > 0) {
                throw new RecruitmentAdminException('文档任务正在运行，请等待当前租约结束后重处理', 409);
            }
            $cancel = $this->pdo->prepare("UPDATE recruitment_resume_jobs SET status = 'cancelled', locked_at = NULL, locked_by = NULL, lease_expires_at = NULL WHERE document_id = ? AND status IN ('pending', 'ai_pending_retry', 'ai_retry_exhausted', 'failed')");
            $cancel->execute([$documentId]);
            $supersede = $this->pdo->prepare("UPDATE recruitment_processing_versions SET status = 'superseded' WHERE document_id = ? AND status = 'active'");
            $supersede->execute([$documentId]);
            $hash = hash('sha256', $documentId . ':extract:manual:' . microtime(true) . ':' . random_bytes(8));
            $stmt = $this->pdo->prepare(
                "INSERT INTO recruitment_resume_jobs (document_id, job_type, status, idempotency_hash) VALUES (?, 'extract', 'pending', ?)"
            );
            $stmt->execute([$documentId, $hash]);
            $jobId = (int) $this->pdo->lastInsertId();
            $document = $this->pdo->prepare("UPDATE recruitment_resume_documents SET status = 'queued', failure_stage = NULL, failure_code = NULL, failure_message = NULL WHERE id = ?");
            $document->execute([$documentId]);
            $this->pdo->commit();
            return ['job_id' => $jobId, 'document_id' => $documentId, 'requested_by' => $staffId];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function retry(int $documentId, int $staffId): array
    {
        $this->documentContext($documentId);
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM recruitment_resume_jobs WHERE document_id = ? AND status IN ('failed', 'ai_retry_exhausted') ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $stmt->execute([$documentId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$job) {
                throw new RecruitmentAdminException('当前文档没有可人工重试的失败任务', 409);
            }
            $maxAttempts = (int) $job['attempt_count'] + 3;
            $update = $this->pdo->prepare("UPDATE recruitment_resume_jobs SET status = 'pending', max_attempts = ?, available_at = NOW(), locked_at = NULL, locked_by = NULL, lease_expires_at = NULL, failure_code = NULL, failure_message = NULL WHERE id = ?");
            $update->execute([$maxAttempts, (int) $job['id']]);
            $document = $this->pdo->prepare("UPDATE recruitment_resume_documents SET status = 'queued', failure_stage = NULL, failure_code = NULL, failure_message = NULL WHERE id = ?");
            $document->execute([$documentId]);
            $this->pdo->commit();
            return ['job_id' => (int) $job['id'], 'job_type' => (string) $job['job_type'], 'document_id' => $documentId, 'requested_by' => $staffId];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function documentContext(int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT document.id AS document_id, document.document_sha256, document.revision_no, batch.id AS batch_id, '
            . 'COALESCE(document.assigned_requirement_id, batch.requirement_id) AS requirement_id, COALESCE(scope.rule_version_id, batch.rule_version_id) AS rule_version_id, rule.position_name_snapshot, rule.job_description, rule.hard_conditions_json, '
            . 'rule.experience_rules_json, rule.keyword_rules_json, rule.grade_rules_json, rule.prompt_version '
            . 'FROM recruitment_resume_documents document '
            . 'JOIN recruitment_resume_batches batch ON batch.id = document.batch_id '
            . 'LEFT JOIN recruitment_resume_batch_requirements scope ON scope.batch_id = batch.id AND scope.requirement_id = document.assigned_requirement_id '
            . 'JOIN recruitment_rule_versions rule ON rule.id = COALESCE(scope.rule_version_id, batch.rule_version_id) '
            . 'WHERE document.id = ? LIMIT 1'
        );
        $stmt->execute([$documentId]);
        $context = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$context) {
            throw new RecruitmentAdminException('简历文档不存在', 404);
        }
        return $context;
    }

    private function processingVersion(array $context, string $jobHash): array
    {
        $contentHash = hash('sha256', implode(':', [
            $context['document_sha256'], $context['revision_no'], $context['requirement_id'], $context['rule_version_id'],
            'parser-v1', 'ocr-' . getenv('RECRUITMENT_OCR_PROVIDER'), 'model-' . $this->ai->provider(),
            (string) $context['prompt_version'], 'evidence-v1', 'score-v1', $jobHash,
        ]));
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO recruitment_processing_versions (document_id, requirement_id, rule_version_id, parser_version, ocr_version, model_provider, model_name, prompt_version, evidence_validator_version, scoring_version, content_hash, status) VALUES (?, ?, ?, 'parser-v1', ?, ?, ?, ?, 'evidence-v1', 'score-v1', ?, 'active')"
        );
        $stmt->execute([
            (int) $context['document_id'], (int) $context['requirement_id'], (int) $context['rule_version_id'],
            'ocr-' . (getenv('RECRUITMENT_OCR_PROVIDER') ?: 'local'), $this->ai->provider(),
            (string) (getenv('RECRUITMENT_AI_MODEL') ?: 'runtime-default'),
            (string) ($context['prompt_version'] ?: 'resume-screening-v1'), $contentHash,
        ]);
        $get = $this->pdo->prepare('SELECT * FROM recruitment_processing_versions WHERE document_id = ? AND requirement_id = ? AND rule_version_id = ? AND content_hash = ? LIMIT 1');
        $get->execute([(int) $context['document_id'], (int) $context['requirement_id'], (int) $context['rule_version_id'], $contentHash]);
        $row = $get->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new RecruitmentAdminException('处理版本创建失败', 500);
        }
        return $row;
    }

    private function existingExtraction(int $processingVersionId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT fields_json FROM recruitment_extraction_results WHERE processing_version_id = ? AND status = 'succeeded' LIMIT 1");
        $stmt->execute([$processingVersionId]);
        $decoded = json_decode((string) ($stmt->fetchColumn() ?: ''), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function existingModelResult(int $processingVersionId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT model_output_json FROM recruitment_model_results WHERE processing_version_id = ? AND status = 'succeeded' LIMIT 1");
        $stmt->execute([$processingVersionId]);
        $decoded = json_decode((string) ($stmt->fetchColumn() ?: ''), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function loadPages(int $processingVersionId): array
    {
        $extraction = $this->existingExtraction($processingVersionId);
        return is_array($extraction['pages'] ?? null) ? $extraction['pages'] : [];
    }

    private function jobProcessingVersion(array $job): int
    {
        $id = (int) ($job['processing_version_id'] ?? 0);
        if ($id > 0) {
            return $id;
        }
        $stmt = $this->pdo->prepare("SELECT id FROM recruitment_processing_versions WHERE document_id = ? AND status = 'active' ORDER BY id DESC LIMIT 1");
        $stmt->execute([(int) $job['document_id']]);
        $id = (int) ($stmt->fetchColumn() ?: 0);
        if ($id <= 0) {
            throw new RecruitmentAdminException('任务缺少处理版本', 409);
        }
        return $id;
    }

    private function evidenceSummary(array $profile): array
    {
        $summary = [];
        foreach ($profile as $field => $value) {
            $summary[$field] = ['confidence' => $value['confidence'] ?? 0, 'evidence_count' => count($value['evidence'] ?? [])];
        }
        return $summary;
    }

    private function markDocumentExtracted(int $documentId): void
    {
        $document = $this->pdo->prepare("UPDATE recruitment_resume_documents SET status = 'processing' WHERE id = ? AND status IN ('queued', 'processing', 'failed')");
        $document->execute([$documentId]);
        $files = $this->pdo->prepare(
            "UPDATE recruitment_resume_files file JOIN recruitment_resume_document_pages page ON page.resume_file_id = file.id SET file.status = 'processing' WHERE page.document_id = ?"
        );
        $files->execute([$documentId]);
    }

    private function isMixedDocument(int $documentId): bool
    {
        $stmt = $this->pdo->prepare("SELECT batch.intake_mode = 'mixed_requirements' FROM recruitment_resume_documents document JOIN recruitment_resume_batches batch ON batch.id = document.batch_id WHERE document.id = ?");
        $stmt->execute([$documentId]);
        return (bool) $stmt->fetchColumn();
    }

    private function documentBatchId(int $documentId): int
    {
        $stmt = $this->pdo->prepare('SELECT batch_id FROM recruitment_resume_documents WHERE id = ?');
        $stmt->execute([$documentId]);
        return (int) $stmt->fetchColumn();
    }

    private function completeDocument(int $documentId, int $batchId): void
    {
        $this->pdo->beginTransaction();
        try {
            $document = $this->pdo->prepare("UPDATE recruitment_resume_documents SET status = 'completed', failure_stage = NULL, failure_code = NULL, failure_message = NULL WHERE id = ?");
            $document->execute([$documentId]);
            $files = $this->pdo->prepare(
                "UPDATE recruitment_resume_files file JOIN recruitment_resume_document_pages page ON page.resume_file_id = file.id SET file.status = 'completed', file.failure_stage = NULL, file.failure_code = NULL, file.failure_message = NULL WHERE page.document_id = ?"
            );
            $files->execute([$documentId]);
            $batch = $this->pdo->prepare(
                "UPDATE recruitment_resume_batches SET processed_count = (SELECT COUNT(*) FROM recruitment_resume_documents WHERE batch_id = ? AND status = 'completed'), failed_count = (SELECT COUNT(*) FROM recruitment_resume_documents WHERE batch_id = ? AND status = 'failed'), grade_a_count = (SELECT COUNT(*) FROM recruitment_applications application JOIN recruitment_resume_documents document ON document.id = application.document_id WHERE document.batch_id = ? AND application.effective_grade = 'A'), grade_b_count = (SELECT COUNT(*) FROM recruitment_applications application JOIN recruitment_resume_documents document ON document.id = application.document_id WHERE document.batch_id = ? AND application.effective_grade = 'B'), grade_c_count = (SELECT COUNT(*) FROM recruitment_applications application JOIN recruitment_resume_documents document ON document.id = application.document_id WHERE document.batch_id = ? AND application.effective_grade = 'C'), status = CASE WHEN NOT EXISTS (SELECT 1 FROM recruitment_resume_documents WHERE batch_id = ? AND status IN ('draft', 'queued', 'processing')) THEN CASE WHEN EXISTS (SELECT 1 FROM recruitment_resume_documents WHERE batch_id = ? AND status = 'failed') THEN 'partial_failed' ELSE 'completed' END ELSE 'processing' END, completed_at = CASE WHEN NOT EXISTS (SELECT 1 FROM recruitment_resume_documents WHERE batch_id = ? AND status IN ('draft', 'queued', 'processing')) THEN NOW() ELSE NULL END WHERE id = ?"
            );
            $batch->execute([$batchId, $batchId, $batchId, $batchId, $batchId, $batchId, $batchId, $batchId, $batchId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
