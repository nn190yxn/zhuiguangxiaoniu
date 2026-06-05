<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$allowedMethods = array('GET', 'POST', 'OPTIONS');
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if (!in_array($method, $allowedMethods, true)) {
    http_response_code(405);
    echo json_encode(array('error' => 'Method not allowed', 'allowed' => $allowedMethods), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../config.php';

// Auth check
$userId = getCurrentUserId();
if (!$userId) {
    http_response_code(401);
    echo json_encode(array('error' => '请先登录'), JSON_UNESCAPED_UNICODE);
    exit;
}


function records_resolve_storage_path(): string
{
    $baseDir = realpath(dirname(__DIR__, 2)) ?: dirname(__DIR__, 2);
    $candidates = array(
        $baseDir . '/wp-content/uploads/fitness-records.json',
        rtrim(sys_get_temp_dir(), '/') . '/fitness-records.json',
    );

    foreach ($candidates as $path) {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            continue;
        }

        if (!file_exists($path)) {
            $created = @file_put_contents($path, '[]');
            if ($created === false) {
                continue;
            }
        }

        if (is_writable($path)) {
            return $path;
        }
    }

    throw new RuntimeException('记录目录不可写');
}

try {
    $storagePath = records_resolve_storage_path();
} catch (RuntimeException $exception) {
    http_response_code(500);
    echo json_encode(array('error' => $exception->getMessage()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function records_read_all(string $path): array
{
    if (!is_file($path)) {
        return array();
    }

    $raw = file_get_contents($path);
    if ($raw === false || $raw === '') {
        return array();
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : array();
}

function records_trim_text($value, string $fallback): string
{
    $text = trim((string) $value);
    return $text !== '' ? $text : $fallback;
}

function records_normalize_date($value): string
{
    $text = trim((string) $value);
    if ($text === '') {
        return date('Y-m-d');
    }

    $timestamp = strtotime($text);
    return $timestamp ? date('Y-m-d', $timestamp) : date('Y-m-d');
}

if ($method === 'GET') {
    $records = records_read_all($storagePath);
    usort($records, static fn($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));
    echo json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(array('error' => '无效的 JSON 请求体'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$record = array(
    'id' => bin2hex(random_bytes(8)),
    'coach_name' => records_trim_text($payload['coach_name'] ?? '', '未填写'),
    'coach_store' => records_trim_text($payload['coach_store'] ?? '', '未选择'),
    'child_name' => records_trim_text($payload['child_name'] ?? '', '未填写'),
    'child_age' => records_trim_text($payload['child_age'] ?? '', ''),
    'test_date' => records_normalize_date($payload['test_date'] ?? ''),
    'created_at' => gmdate('c'),
);

$fileHandle = fopen($storagePath, 'c+');
if ($fileHandle === false) {
    http_response_code(500);
    echo json_encode(array('error' => '记录文件打开失败'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!flock($fileHandle, LOCK_EX)) {
    fclose($fileHandle);
    http_response_code(500);
    echo json_encode(array('error' => '记录文件加锁失败'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$existingRaw = stream_get_contents($fileHandle);
$existing = json_decode($existingRaw !== false ? $existingRaw : '[]', true);
if (!is_array($existing)) {
    $existing = array();
}

$existing[] = $record;
usort($existing, static fn($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

rewind($fileHandle);
ftruncate($fileHandle, 0);
$written = fwrite($fileHandle, json_encode($existing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
fflush($fileHandle);
flock($fileHandle, LOCK_UN);
fclose($fileHandle);

if ($written === false) {
    http_response_code(500);
    echo json_encode(array('error' => '记录写入失败'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(201);
echo json_encode(array('message' => '记录已保存', 'record' => $record), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
