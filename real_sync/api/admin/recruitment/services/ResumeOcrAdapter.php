<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/platform/RecruitmentPlatformOcrAdapter.php';

final class ResumeOcrAdapter
{
    private PDO $pdo;
    private RecruitmentPlatformOcrAdapter $platform;

    public function __construct(PDO $pdo, ?string $provider = null)
    {
        $this->pdo = $pdo;
        $requested = strtolower(trim((string) ($provider ?? 'baidu_ocr')));
        if (!in_array($requested, ['baidu', 'baidu_ocr'], true)) {
            throw new RuntimeException('OCR 提供方不受支持：' . $requested);
        }
        $this->platform = new RecruitmentPlatformOcrAdapter($pdo);
    }

    public function extract(string $path, int $documentId, ?int $processingVersionId = null, ?int $jobId = null): string
    {
        $started = microtime(true);
        try {
            $extracted = $this->platform->extract($path, 'recruitment.ocr:' . $documentId . ':' . (int) $processingVersionId . ':' . (int) $jobId);
            $result = $extracted['text'];
            $processor = $extracted['processor'];
            $result = trim($result);
            if ($result === '') {
                throw new RuntimeException('OCR 未识别到文字');
            }
            $this->recordRun($processingVersionId, $documentId, $jobId, $processor, 'succeeded', null, null, $started, hash('sha256', $result));
            return $result;
        } catch (Throwable $error) {
            $processor = isset($processor) && is_array($processor) ? $processor : ['provider' => 'baidu_ocr', 'service_region' => 'unapproved'];
            $this->recordRun($processingVersionId, $documentId, $jobId, $processor, 'retryable_failed', 'ocr_failed', $error->getMessage(), $started, null);
            throw $error;
        }
    }

    public function provider(): string
    {
        return 'baidu_ocr';
    }

    private function recordRun(?int $processingVersionId, int $documentId, ?int $jobId, array $processor, string $status, ?string $errorCode, ?string $message, float $started, ?string $outputHash): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO recruitment_ai_runs (processing_version_id, document_id, job_id, run_type, provider, service_region, model, input_summary_hash, output_summary_hash, duration_ms, status, error_code, error_message) VALUES (?, ?, ?, 'ocr', ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $processingVersionId, $documentId, $jobId,
            (string) ($processor['provider'] ?? 'baidu_ocr'),
            (string) ($processor['service_region'] ?? ''),
            'general_basic',
            null, $outputHash, (int) round((microtime(true) - $started) * 1000),
            $status, $errorCode, $message !== null ? mb_substr($message, 0, 500, 'UTF-8') : null,
        ]);
    }
}
