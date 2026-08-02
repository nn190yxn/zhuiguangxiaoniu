<?php
declare(strict_types=1);

require_once __DIR__ . '/WorkloadPlatformAdapter.php';
require_once dirname(__DIR__) . '/services/WorkloadExportService.php';
require_once dirname(__DIR__) . '/services/WorkloadExportJobService.php';

final class WorkloadPlatformJobAdapter
{
    public function __construct(private PDO $db)
    {
    }

    public function processNextExport(?callable $pulse = null): array
    {
        WorkloadPlatformAdapter::assertReady($this->db);
        $jobs = new WorkloadExportJobService($this->db);
        $job = $jobs->claimNext();
        if ($job === null) {
            return ['status' => 'idle', 'processed' => 0];
        }
        try {
            $pulse?->__invoke();
            $staffContext = $jobs->workerContext($job);
            $filters = json_decode((string)$job['filters_json'], true, 512, JSON_THROW_ON_ERROR);
            $export = (new WorkloadExportService($this->db))->plan((string)$job['export_type'], $filters, $staffContext);
            $directory = $jobs->exportDirectory();
            if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                throw new RuntimeException('无法创建导出目录');
            }
            $filePath = $directory . '/' . (string)$job['job_key'] . '.csv';
            $stream = fopen($filePath, 'wb');
            if ($stream === false) {
                throw new RuntimeException('无法写入导出文件');
            }
            try {
                $rowCount = (new WorkloadExportService($this->db))->writeCsv($export, $stream);
            } finally {
                fclose($stream);
            }
            chmod($filePath, 0600);
            $pulse?->__invoke();
            $jobs->complete((int)$job['id'], $filePath, $rowCount);
            return ['status' => 'completed', 'processed' => 1, 'job_id' => (string)$job['job_key'], 'row_count' => $rowCount];
        } catch (Throwable $error) {
            $jobs->fail((int)$job['id'], $error);
            throw $error;
        }
    }
}
