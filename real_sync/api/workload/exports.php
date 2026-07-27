<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/WorkloadExportService.php';
require_once __DIR__ . '/services/WorkloadExportJobService.php';
handleCORS();

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'POST'));
    $context = appRequireStaffContext();
    $pdo = workloadDb();
    workloadEnsureSchema($pdo);
    $jobs = new WorkloadExportJobService($pdo);
    if ($method === 'GET') {
        $jobId = trim((string) ($_GET['id'] ?? ''));
        if (!empty($_GET['download'])) {
            $download = $jobs->download($jobId, $context);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $download['filename'] . '"');
            header('X-Content-Type-Options: nosniff');
            header('X-Export-Row-Count: ' . $download['row_count']);
            readfile($download['path']);
            exit;
        }
        appJsonSuccess($jobs->status($jobId, $context));
    }
    if ($method !== 'POST') {
        appJsonError(405, '不支持的请求方法');
    }
    $input = json_decode(file_get_contents('php://input') ?: '{}', true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($input)) {
        throw new WorkloadExportException('导出请求格式无效');
    }
    $service = new WorkloadExportService($pdo);
    $exportType = strtolower(trim((string) ($input['export_type'] ?? '')));
    unset($input['export_type']);
    $export = $service->plan($exportType, $input, $context);
    if ($export['row_count'] > WorkloadExportJobService::SYNCHRONOUS_ROW_LIMIT) {
        $job = $jobs->create(
            $exportType,
            $input,
            $context,
            $export['permission_scope'],
            isset($export['metric_version_id']) ? (int) $export['metric_version_id'] : null
        );
        http_response_code(202);
        appJsonSuccess($job, '导出任务已创建');
    }
    appLogEvent('workload.export.completed', [
        'staff_id' => $context['staff_id'] ?? null,
        'export_type' => $exportType,
        'row_count' => $export['row_count'],
    ]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $export['filename'] . '"');
    header('X-Content-Type-Options: nosniff');
    header('X-Export-Row-Count: ' . $export['row_count']);
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new WorkloadExportException('无法打开导出响应流', 500);
    }
    $service->writeCsv($export, $output);
    fclose($output);
} catch (JsonException | WorkloadExportException $error) {
    $statusCode = $error instanceof WorkloadExportException ? $error->statusCode() : 400;
    appLogEvent('workload.export.rejected', ['error' => $error->getMessage()]);
    appJsonError($statusCode, $error->getMessage());
} catch (WorkloadExportJobException $error) {
    appLogEvent('workload.export.rejected', ['error' => $error->getMessage()]);
    appJsonError($error->statusCode(), $error->getMessage());
} catch (WorkloadAnalyticsQueryException | WorkloadSourcePolicyException $error) {
    appLogEvent('workload.export.rejected', ['error' => $error->getMessage()]);
    appJsonError($error->statusCode(), $error->getMessage());
} catch (Throwable $error) {
    appLogEvent('workload.export.error', ['error' => $error->getMessage()]);
    appJsonError(500, '生成工作量导出失败');
}
