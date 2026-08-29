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
require_once __DIR__ . '/../common/context.php';

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

function records_normalize_json($value, int $maxBytes = 65536): array
{
    if (!is_array($value)) {
        return array();
    }

    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || strlen($encoded) > $maxBytes) {
        return array();
    }

    return $value;
}

function records_scope(array $context, array $record): bool
{
    if (!empty($context['is_hq'])) {
        return true;
    }

    $recordUserId = (int) ($record['created_by_user_id'] ?? 0);
    $recordStoreId = (int) ($record['store_id'] ?? 0);
    if (($context['role'] ?? '') === 'manager') {
        return $recordStoreId > 0
            && $recordStoreId === (int) ($context['store_id'] ?? 0);
    }

    return $recordUserId > 0 && $recordUserId === (int) ($context['user_id'] ?? 0);
}

function records_is_detail_request(): bool
{
    return isset($_GET['id']) && trim((string) $_GET['id']) !== '';
}

function records_public_list_item(array $record): array
{
    $item = $record;
    if (empty($item['data_completeness'])) {
        $item['data_completeness'] = 'summary';
    }
    unset(
        $item['test_data'],
        $item['image_ratings'],
        $item['assessment_items'],
        $item['coach_context'],
        $item['goals'],
        $item['report_content']
    );
    return $item;
}

function records_matches_filters(array $record): bool
{
    $testDate = (string) ($record['test_date'] ?? '');
    $from = trim((string) ($_GET['date_from'] ?? ''));
    $to = trim((string) ($_GET['date_to'] ?? ''));
    if ($from !== '' && $testDate < $from) {
        return false;
    }
    if ($to !== '' && $testDate > $to) {
        return false;
    }

    foreach (array('store' => 'coach_store', 'coach' => 'coach_name', 'student' => 'child_name') as $queryKey => $field) {
        $filter = trim((string) ($_GET[$queryKey] ?? ''));
        if ($filter !== '' && stripos((string) ($record[$field] ?? ''), $filter) === false) {
            return false;
        }
    }

    $status = trim((string) ($_GET['status'] ?? ''));
    if ($status === '') {
        return true;
    }
    if ($status === 'fallback') {
        return (string) ($record['generation_mode'] ?? 'fallback') === 'fallback';
    }
    return (string) ($record['report_status'] ?? 'completed') === $status;
}

$context = appGetCurrentStaffContext();

if ($method === 'GET') {
    $records = array_values(array_filter(records_read_all($storagePath), static function ($record) use ($context): bool {
        return is_array($record) && records_scope($context, $record) && records_matches_filters($record);
    }));
    usort($records, static fn($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

    if (records_is_detail_request()) {
        $requestedId = trim((string) $_GET['id']);
        $allRecords = records_read_all($storagePath);
        foreach ($allRecords as $record) {
            if (!is_array($record) || (string) ($record['id'] ?? '') !== $requestedId) {
                continue;
            }
            if (!records_scope($context, $record)) {
                http_response_code(403);
                echo json_encode(array('error' => '无权访问该记录'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            if (!records_matches_filters($record)) {
                http_response_code(404);
                echo json_encode(array('error' => '记录不符合当前筛选条件'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                exit;
            }
            if (empty($record['data_completeness'])) {
                $record['data_completeness'] = 'summary';
            }
            echo json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
        http_response_code(404);
        echo json_encode(array('error' => '记录不存在或无权访问'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $total = count($records);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $pageSize = min(100, max(1, (int) ($_GET['page_size'] ?? 100)));
    $offset = ($page - 1) * $pageSize;
    $records = array_map('records_public_list_item', array_slice($records, $offset, $pageSize));
    header('X-Records-Total: ' . $total);
    echo json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(array('error' => '无效的 JSON 请求体'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$creatorName = trim((string) ($context['name'] ?? ''));
$contextStoreId = (int) ($context['store_id'] ?? 0);
$contextStoreName = trim((string) ($context['store_name'] ?? ''));
$requestedStoreName = records_trim_text($payload['coach_store'] ?? '', '未选择');
$record = array(
    'id' => bin2hex(random_bytes(8)),
    'created_by_user_id' => (int) ($context['user_id'] ?? $userId),
    'created_by_staff_id' => (int) ($context['staff_id'] ?? 0),
    'created_by_name' => $creatorName !== '' ? $creatorName : records_trim_text($payload['coach_name'] ?? '', '未填写'),
    'coach_name' => ($context['role'] ?? '') === 'coach' && $creatorName !== ''
        ? $creatorName
        : records_trim_text($payload['coach_name'] ?? '', $creatorName !== '' ? $creatorName : '未填写'),
    'store_id' => $contextStoreId,
    'coach_store' => $contextStoreName !== '' && empty($context['is_hq']) ? $contextStoreName : $requestedStoreName,
    'child_name' => records_trim_text($payload['child_name'] ?? '', '未填写'),
    'child_age' => records_trim_text($payload['child_age'] ?? '', ''),
    'test_date' => records_normalize_date($payload['test_date'] ?? ''),
    'age_group' => records_trim_text($payload['age_group'] ?? '', ''),
    'gender' => records_trim_text($payload['gender'] ?? '', ''),
    'test_data' => records_normalize_json($payload['test_data'] ?? array()),
    'image_ratings' => records_normalize_json($payload['image_ratings'] ?? array()),
    'assessment_items' => records_normalize_json($payload['assessment_items'] ?? array()),
    'coach_context' => records_normalize_json($payload['coach_context'] ?? array()),
    'goals' => records_normalize_json($payload['goals'] ?? array()),
    'report_content' => function_exists('mb_substr')
        ? mb_substr((string) ($payload['report_content'] ?? ''), 0, 200000)
        : substr((string) ($payload['report_content'] ?? ''), 0, 200000),
    'generation_mode' => records_trim_text($payload['generation_mode'] ?? '', 'fallback'),
    'report_status' => records_trim_text($payload['report_status'] ?? '', 'completed'),
    'data_completeness' => 'complete',
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
