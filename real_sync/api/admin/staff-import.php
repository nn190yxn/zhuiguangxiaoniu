<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/services/StaffImportService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '仅支持 POST 请求');
}

try {
    [, $user, $operatorStaff] = adminRequirePermission('staff.create');
    $payload = readStaffImportPayload();
    $result = (new StaffImportService(getDB()))->import(
        $payload['records'],
        $payload['batch_key'],
        $user,
        $operatorStaff ?: [],
        $payload['metadata']
    );
    jsonResponse(0, 'success', $result);
} catch (StaffImportBatchConflictException $error) {
    jsonResponse(409, $error->getMessage());
} catch (StaffImportValidationException $error) {
    jsonResponse(400, $error->getMessage());
} catch (Throwable $error) {
    error_log('[admin.staff.import] ' . $error->getMessage());
    jsonResponse(1, '员工导入失败');
}

function readStaffImportPayload(): array {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $input = adminJsonInput();
        $records = $input['records'] ?? $input;
        return [
            'records' => is_array($records) ? array_values($records) : [],
            'batch_key' => trim((string)($input['batch_key'] ?? '')),
            'metadata' => ['file_name' => null, 'file_sha256' => null],
        ];
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        return ['records' => [], 'batch_key' => '', 'metadata' => []];
    }

    $fileName = (string)$_FILES['file']['name'];
    $temporaryPath = (string)$_FILES['file']['tmp_name'];
    $batchKey = trim((string)($_POST['batch_key'] ?? ''));
    $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    if ($extension === 'json') {
        $data = json_decode((string)file_get_contents($temporaryPath), true);
        $records = is_array($data) ? ($data['records'] ?? $data) : [];
        $fileBatchKey = is_array($data) ? (string)($data['batch_key'] ?? '') : '';
        return importFilePayload($records, $fileName, $temporaryPath, $batchKey ?: $fileBatchKey);
    }
    if ($extension !== 'csv') {
        throw new StaffImportValidationException('员工导入仅支持 JSON 或 CSV');
    }

    $handle = fopen($temporaryPath, 'r');
    if (!$handle) {
        throw new StaffImportValidationException('无法读取 CSV 文件');
    }
    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        return importFilePayload([], $fileName, $temporaryPath, $batchKey);
    }
    $headers = array_map(fn($value) => trim((string)$value), $headers);
    $rows = [];
    while (($row = fgetcsv($handle)) !== false) {
        $item = [];
        foreach ($headers as $index => $key) {
            if ($key !== '') {
                $item[$key] = trim((string)($row[$index] ?? ''));
            }
        }
        $rows[] = $item;
    }
    fclose($handle);
    return importFilePayload($rows, $fileName, $temporaryPath, $batchKey);
}

function importFilePayload($records, string $fileName, string $temporaryPath, string $batchKey): array {
    return [
        'records' => is_array($records) ? array_values($records) : [],
        'batch_key' => trim($batchKey),
        'metadata' => [
            'file_name' => $fileName,
            'file_sha256' => hash_file('sha256', $temporaryPath) ?: null,
        ],
    ];
}
