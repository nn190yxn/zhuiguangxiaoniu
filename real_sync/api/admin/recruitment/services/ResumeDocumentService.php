<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/platform/RecruitmentPlatformJobAdapter.php';

final class ResumeDocumentService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createForFile(array $file, int $staffId): array
    {
        return $this->createRevision(
            (int) $file['batch_id'],
            [(int) $file['id']],
            (string) $file['sha256'],
            (string) $file['mime_type'] === 'application/pdf' ? 'pdf' : 'image_group',
            $staffId
        );
    }

    public function groupImages(int $batchId, array $fileIds, int $staffId, ?int $supersedeDocumentId = null): array
    {
        $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds))));
        if (count($fileIds) < 1) {
            throw new RecruitmentAdminException('图片文档至少需要一页');
        }
        $files = $this->filesForBatch($batchId, $fileIds);
        if (count($files) !== count($fileIds)) {
            throw new RecruitmentAdminException('图片文件不存在或不属于当前批次', 404);
        }
        $byId = [];
        foreach ($files as $file) {
            if (strpos((string) $file['mime_type'], 'image/') !== 0) {
                throw new RecruitmentAdminException('图片合组仅支持 JPG、PNG 和 WEBP');
            }
            $byId[(int) $file['id']] = $file;
        }
        $ordered = array_map(static fn (int $id): array => $byId[$id], $fileIds);
        $digest = hash('sha256', implode(':', array_map(static fn (array $file): string => (string) $file['sha256'], $ordered)));

        $this->pdo->beginTransaction();
        try {
            $document = $this->createRevision($batchId, $fileIds, $digest, 'image_group', $staffId);
            if ($supersedeDocumentId !== null && $supersedeDocumentId > 0) {
                $this->supersede($batchId, $supersedeDocumentId, (int) $document['id']);
            }
            $this->pdo->commit();
            return $document;
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    public function splitImages(int $batchId, int $documentId, int $staffId): array
    {
        $pages = $this->documentPages($batchId, $documentId);
        if (!$pages) {
            throw new RecruitmentAdminException('待拆分文档不存在', 404);
        }
        $this->pdo->beginTransaction();
        try {
            $documents = [];
            foreach ($pages as $page) {
                $documents[] = $this->createForFile($page, $staffId);
            }
            $firstId = (int) ($documents[0]['id'] ?? 0);
            $this->supersede($batchId, $documentId, $firstId);
            $this->pdo->commit();
            return ['documents' => $documents, 'superseded_document_id' => $documentId];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function createRevision(int $batchId, array $fileIds, string $digest, string $type, int $staffId): array
    {
        $revision = $this->nextRevision($batchId, $digest);
        $stmt = $this->pdo->prepare(
            "INSERT INTO recruitment_resume_documents (batch_id, document_type, document_sha256, revision_no, status, created_by) VALUES (?, ?, ?, ?, 'queued', ?)"
        );
        $stmt->execute([$batchId, $type, $digest, $revision, $staffId ?: null]);
        $documentId = (int) $this->pdo->lastInsertId();
        $pageStmt = $this->pdo->prepare(
            'INSERT INTO recruitment_resume_document_pages (document_id, resume_file_id, page_order, file_page_no, page_sha256) VALUES (?, ?, ?, 1, ?)'
        );
        foreach (array_values($fileIds) as $index => $fileId) {
            $hashStmt = $this->pdo->prepare('SELECT sha256 FROM recruitment_resume_files WHERE id = ? LIMIT 1');
            $hashStmt->execute([(int) $fileId]);
            $pageStmt->execute([$documentId, (int) $fileId, $index + 1, (string) $hashStmt->fetchColumn()]);
        }
        $jobHash = hash('sha256', $digest . ':extract:v1');
        $job = $this->pdo->prepare(
            "INSERT IGNORE INTO recruitment_resume_jobs (document_id, job_type, status, idempotency_hash) VALUES (?, 'extract', 'pending', ?)"
        );
        $job->execute([$documentId, $jobHash]);
        $jobRow = $this->pdo->prepare('SELECT * FROM recruitment_resume_jobs WHERE document_id = ? AND idempotency_hash = ? LIMIT 1');
        $jobRow->execute([$documentId, $jobHash]);
        $queuedJob = $jobRow->fetch(PDO::FETCH_ASSOC);
        if (!$queuedJob) {
            throw new RuntimeException('招聘处理任务创建失败');
        }
        (new RecruitmentPlatformJobAdapter($this->pdo))->enqueue($queuedJob);
        $get = $this->pdo->prepare('SELECT * FROM recruitment_resume_documents WHERE id = ? LIMIT 1');
        $get->execute([$documentId]);
        return $get->fetch(PDO::FETCH_ASSOC) ?: ['id' => $documentId];
    }

    private function supersede(int $batchId, int $documentId, int $replacementId): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE recruitment_resume_documents SET status = 'superseded', superseded_by_id = ? WHERE id = ? AND batch_id = ? AND status IN ('draft', 'queued', 'failed')"
        );
        $stmt->execute([$replacementId, $documentId, $batchId]);
        if ($stmt->rowCount() !== 1) {
            throw new RecruitmentAdminException('当前文档状态无法创建新修订', 409);
        }
        $cancel = $this->pdo->prepare(
            "UPDATE recruitment_resume_jobs SET status = 'cancelled', locked_at = NULL, locked_by = NULL, lease_expires_at = NULL WHERE document_id = ? AND status IN ('pending', 'ai_pending_retry', 'failed')"
        );
        $cancel->execute([$documentId]);
    }

    private function filesForBatch(int $batchId, array $fileIds): array
    {
        $placeholders = implode(', ', array_fill(0, count($fileIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM recruitment_resume_files WHERE batch_id = ? AND id IN (' . $placeholders . ") AND status <> 'skipped'"
        );
        $stmt->execute(array_merge([$batchId], $fileIds));
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function documentPages(int $batchId, int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT file.* FROM recruitment_resume_document_pages page '
            . 'JOIN recruitment_resume_documents document ON document.id = page.document_id '
            . 'JOIN recruitment_resume_files file ON file.id = page.resume_file_id '
            . 'WHERE document.id = ? AND document.batch_id = ? ORDER BY page.page_order ASC'
        );
        $stmt->execute([$documentId, $batchId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function nextRevision(int $batchId, string $digest): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(revision_no), 0) FROM recruitment_resume_documents WHERE batch_id = ? AND document_sha256 = ?');
        $stmt->execute([$batchId, $digest]);
        return ((int) $stmt->fetchColumn()) + 1;
    }
}
