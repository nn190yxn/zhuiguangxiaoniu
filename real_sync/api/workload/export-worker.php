<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadExportService.php';
require_once __DIR__ . '/services/WorkloadExportJobService.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = workloadDb();
$jobs = new WorkloadExportJobService($pdo);
$job = $jobs->claimNext();
if ($job === null) {
    fwrite(STDOUT, "No pending workload export jobs.\n");
    exit(0);
}

try {
    $context = $jobs->workerContext($job);
    $filters = json_decode((string) $job['filters_json'], true, 512, JSON_THROW_ON_ERROR);
    $service = new WorkloadExportService($pdo);
    $export = $service->plan((string) $job['export_type'], $filters, $context);
    $directory = $jobs->exportDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
        throw new RuntimeException('无法创建导出目录');
    }
    $filePath = $directory . '/' . (string) $job['job_key'] . '.csv';
    $stream = fopen($filePath, 'wb');
    if ($stream === false) throw new RuntimeException('无法写入导出文件');
    try {
        $rowCount = $service->writeCsv($export, $stream);
    } finally {
        fclose($stream);
    }
    $jobs->complete((int) $job['id'], $filePath, $rowCount);
    fwrite(STDOUT, 'Completed workload export job ' . (string) $job['job_key'] . ".\n");
} catch (Throwable $error) {
    $jobs->fail((int) $job['id'], $error);
    fwrite(STDERR, 'Failed workload export job: ' . $error->getMessage() . "\n");
    exit(1);
}
