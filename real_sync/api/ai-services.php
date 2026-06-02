<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ai-runtime.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

$currentUserId = getCurrentUserId();
if ($currentUserId <= 0) {
    http_response_code(401);
    echo json_encode(array('error' => '请先登录'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ai_build_public_base_url(): string
{
    $https = (string) ($_SERVER['HTTPS'] ?? '');
    $forwardedProto = trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = ($forwardedProto !== '' ? $forwardedProto : (($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http'));
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));

    if ($host === '') {
        throw new RuntimeException('无法确定当前站点地址');
    }

    return $scheme . '://' . $host;
}

function ai_resolve_public_upload_dir(string $subDir): array
{
    $subDir = trim($subDir, '/');
    if ($subDir === '') {
        throw new RuntimeException('上传目录配置无效');
    }

    $baseDir = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $relativeDir = '/wp-content/uploads/' . $subDir;
    $absoluteDir = $baseDir . $relativeDir;

    return array($relativeDir, $absoluteDir);
}

function ai_guess_image_extension(string $mimeSubtype): string
{
    $mimeSubtype = strtolower(trim($mimeSubtype));
    $map = array(
        'jpeg' => 'jpg',
        'jpg' => 'jpg',
        'png' => 'png',
        'webp' => 'webp',
        'gif' => 'gif',
    );

    return $map[$mimeSubtype] ?? 'jpg';
}

function ai_cleanup_ocr_cache(string $directory, int $ttlSeconds = 21600): void
{
    if (!is_dir($directory)) {
        return;
    }

    $now = time();
    $entries = @scandir($directory);
    if (!is_array($entries)) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . '/' . $entry;
        if (!is_file($path)) {
            continue;
        }

        $modifiedAt = @filemtime($path);
        if ($modifiedAt === false) {
            continue;
        }

        if (($now - $modifiedAt) >= $ttlSeconds) {
            @unlink($path);
        }
    }
}

function ai_records_storage_ready(): bool
{
    $baseDir = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
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
            return true;
        }
    }

    return false;
}

function ai_store_ocr_image(string $imageInput): string
{
    $imageInput = trim($imageInput);
    if ($imageInput === '') {
        throw new InvalidArgumentException('缺少图片数据');
    }

    if (preg_match('#^https?://#i', $imageInput) === 1) {
        return $imageInput;
    }

    $extension = 'jpg';
    $base64Body = $imageInput;

    if (preg_match('#^data:image/([^;]+);base64,(.+)$#is', $imageInput, $matches) === 1) {
        $extension = ai_guess_image_extension($matches[1]);
        $base64Body = $matches[2];
    }

    $base64Body = preg_replace('/\s+/', '', $base64Body) ?? '';
    if ($base64Body === '') {
        throw new InvalidArgumentException('图片数据为空');
    }

    $binary = base64_decode($base64Body, true);
    if ($binary === false) {
        throw new InvalidArgumentException('图片 base64 数据无效');
    }

    list($relativeDir, $absoluteDir) = ai_resolve_public_upload_dir('ocr-cache');
    if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0775, true) && !is_dir($absoluteDir)) {
        throw new RuntimeException('无法创建 OCR 图片缓存目录');
    }

    $indexPath = $absoluteDir . '/index.html';
    if (!is_file($indexPath)) {
        @file_put_contents($indexPath, '');
    }

    ai_cleanup_ocr_cache($absoluteDir);

    $fileName = 'ocr-' . date('Ymd-His') . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
    $absolutePath = $absoluteDir . '/' . $fileName;
    if (file_put_contents($absolutePath, $binary) === false) {
        throw new RuntimeException('无法写入 OCR 图片缓存');
    }

    return ai_build_public_base_url() . $relativeDir . '/' . $fileName;
}

if ($method === 'GET') {
    echo json_encode(array(
        'reportAiReady' => ai_has_service('deepseek'),
        'ocrReady' => ai_ocr_ready(),
        'smartLessonReady' => true,
        'recordsReady' => ai_records_storage_ready(),
        'message' => '体测页已切换为后台统一配置模式，员工端不再填写 API Key。',
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(array('error' => 'Method not allowed'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$payload = json_decode(file_get_contents('php://input') ?: '{}', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(array('error' => '无效的 JSON 请求体'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$action = (string) ($payload['action'] ?? '');

try {
    if ($action === 'plan') {
        $prompt = trim((string) ($payload['prompt'] ?? ''));
        if ($prompt === '') {
            throw new InvalidArgumentException('缺少报告提示词');
        }

        $content = ai_deepseek_chat(
            $prompt,
            '你是追光小牛运动成长中心的专业运动规划师，精通ACE成长体系。你擅长根据儿童体测数据生成专业、温暖、有说服力的个性化运动规划报告。请使用HTML标签格式化你的回复。注意：不要在回复中出现任何关于AI、人工智能、大模型、系统生成等相关字眼，要以专业运动教练的口吻撰写。',
            3000,
            0.7
        );

        echo json_encode(array('content' => $content), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'ocr') {
        $imageDataUrl = trim((string) ($payload['imageDataUrl'] ?? ''));
        $prompt = trim((string) ($payload['prompt'] ?? ''));
        if ($imageDataUrl === '' || $prompt === '') {
            throw new InvalidArgumentException('缺少图片或识别提示词');
        }

        $imageUrl = ai_store_ocr_image($imageDataUrl);
        $result = ai_ocr_fitness_image($imageDataUrl, $imageUrl, $prompt);
        echo json_encode(array('result' => $result), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    throw new InvalidArgumentException('未知的动作类型');
} catch (InvalidArgumentException $exception) {
    http_response_code(400);
    echo json_encode(array('error' => $exception->getMessage()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (RuntimeException $exception) {
    http_response_code(503);
    echo json_encode(array('error' => $exception->getMessage()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $exception) {
    http_response_code(500);
    echo json_encode(array('error' => '后台 AI 服务暂时不可用'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
