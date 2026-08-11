<?php

declare(strict_types=1);

require_once __DIR__ . '/ResumeOcrAdapter.php';

final class ResumeTextExtractor
{
    private PDO $pdo;
    private ResumeOcrAdapter $ocr;
    private string $storageRoot;

    public function __construct(PDO $pdo, ?ResumeOcrAdapter $ocr = null, ?string $storageRoot = null)
    {
        $this->pdo = $pdo;
        $this->ocr = $ocr ?? new ResumeOcrAdapter($pdo);
        // Uploads are stored by PlatformPrivateFileStorage, so workers must read its same root.
        $configuredRoot = trim((string) ($storageRoot ?? getenv('PLATFORM_PRIVATE_FILE_ROOT') ?: ''));
        $this->storageRoot = $configuredRoot !== '' ? rtrim($configuredRoot, DIRECTORY_SEPARATOR) : dirname(__DIR__, 4) . '/.private/platform-files';
    }

    public function extract(int $documentId, ?int $processingVersionId = null, ?int $jobId = null): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT page.page_order, page.file_page_no, file.id AS file_id, file.storage_key, file.mime_type, file.sha256 '
            . 'FROM recruitment_resume_document_pages page JOIN recruitment_resume_files file ON file.id = page.resume_file_id '
            . 'WHERE page.document_id = ? ORDER BY page.page_order ASC'
        );
        $stmt->execute([$documentId]);
        $sources = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$sources) {
            throw new RecruitmentAdminException('简历文档没有可处理页面', 422);
        }
        $pages = [];
        foreach ($sources as $source) {
            $path = $this->controlledPath((string) $source['storage_key']);
            if ((string) $source['mime_type'] === 'application/pdf') {
                foreach ($this->extractPdf($path, $documentId, $processingVersionId, $jobId) as $pdfPage) {
                    $pdfPage['page_no'] = count($pages) + 1;
                    $pdfPage['file_id'] = (int) $source['file_id'];
                    $pages[] = $pdfPage;
                }
            } else {
                $pages[] = [
                    'page_no' => count($pages) + 1,
                    'file_id' => (int) $source['file_id'],
                    'source' => 'ocr',
                    'text' => $this->ocr->extract($path, $documentId, $processingVersionId, $jobId),
                ];
            }
        }
        return ['pages' => $pages, 'text' => implode("\n\n", array_column($pages, 'text')), 'ocr_provider' => $this->ocr->provider()];
    }

    private function extractPdf(string $path, int $documentId, ?int $processingVersionId, ?int $jobId): array
    {
        $text = $this->runProcess(['pdftotext', '-layout', '-enc', 'UTF-8', $path, '-'], 60);
        $rawPages = preg_split('/\f/', $text) ?: [];
        if ($rawPages && trim((string) end($rawPages)) === '') {
            array_pop($rawPages);
        }
        if (!$rawPages) {
            $rawPages = [''];
        }
        $pages = [];
        foreach ($rawPages as $index => $pageText) {
            $pageText = trim((string) $pageText);
            if (mb_strlen(preg_replace('/\s+/u', '', $pageText) ?? '', 'UTF-8') >= 40) {
                $pages[] = ['source' => 'pdf_text', 'text' => $pageText];
                continue;
            }
            $imagePath = tempnam(sys_get_temp_dir(), 'resume-page-');
            if ($imagePath === false) {
                throw new RuntimeException('无法创建 PDF OCR 临时文件');
            }
            @unlink($imagePath);
            $imagePath .= '.png';
            try {
                $prefix = substr($imagePath, 0, -4);
                $this->runProcess(['pdftoppm', '-f', (string) ($index + 1), '-l', (string) ($index + 1), '-singlefile', '-png', '-r', '160', $path, $prefix], 60);
                $pages[] = ['source' => 'ocr', 'text' => $this->ocr->extract($imagePath, $documentId, $processingVersionId, $jobId)];
            } finally {
                if (is_file($imagePath)) {
                    @unlink($imagePath);
                }
            }
        }
        return $pages;
    }

    private function controlledPath(string $storageKey): string
    {
        if ($storageKey === '' || str_contains($storageKey, '..') || str_starts_with($storageKey, '/')) {
            throw new RecruitmentAdminException('简历存储键无效', 500);
        }
        $path = $this->storageRoot . '/' . $storageKey;
        $realPath = realpath($path);
        $realRoot = realpath($this->storageRoot);
        if ($realPath === false || $realRoot === false || !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new RecruitmentAdminException('简历原始文件不存在', 404);
        }
        return $realPath;
    }

    private function runProcess(array $command, int $timeout): string
    {
        $pipes = [];
        $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('无法启动文档解析程序');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeout;
        while (true) {
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                proc_terminate($process);
                throw new RuntimeException('文档解析超时');
            }
            usleep(20000);
        }
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 && trim($stdout) === '') {
            throw new RuntimeException('文档解析失败：' . mb_substr(trim($stderr), 0, 300, 'UTF-8'));
        }
        return $stdout;
    }
}
