<?php
/**
 * 语音转文字API
 * POST /api/drill/voice-to-text.php
 *
 * 功能：接收语音文件，返回识别后的文本
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(1, '只支持POST请求');
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(401, '请先登录');
}

if (!isset($_FILES['audio']) || $_FILES['audio']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(1, '音频文件上传失败');
}

$audioFile = $_FILES['audio'];
$tmpfile = $audioFile['tmp_name'];

// 检查文件类型
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $tmpfile);
finfo_close($finfo);

$allowedTypes = ['audio/mpeg', 'audio/wav', 'audio/mp3', 'audio/x-wav', 'audio/m4a', 'audio/aac', 'audio/mp4', 'audio/x-m4a', 'application/octet-stream'];
if (!in_array($mimeType, $allowedTypes)) {
    jsonResponse(1, '不支持的音频格式');
}

// 调用语音识别
$text = recognizeAudio($tmpfile);

if ($text) {
    jsonResponse(0, 'success', ['text' => $text]);
} else {
    jsonResponse(1, '语音识别失败，请重试');
}

/**
 * 语音识别
 * 使用智谱AI ASR模型进行语音识别
 */
function recognizeAudio($audioPath) {
    $settings = ai_load_settings();
    $apiKey = $settings['zhipu_api_key'] ?? '';

    if (empty($apiKey)) {
        return null;
    }

    $result = ai_post_audio($audioPath, $apiKey, 45);

    if (($result['status'] ?? 0) >= 300 || ($result['status'] ?? 0) === 0) {
        error_log('Voice recognition failed: HTTP ' . ($result['status'] ?? 0) . ' ' . json_encode($result['body'], JSON_UNESCAPED_UNICODE));
        return null;
    }

    $content = $result['body']['text'] ?? ($result['body']['result'] ?? '');

    // 清理返回的文本
    $text = trim($content);
    $text = preg_replace('/^["""\'\'"]|["""\'\'"]$/', '', $text);

    return $text ?: null;
}

function ai_post_audio($audioPath, $apiKey, $timeout = 45) {
    $mimeType = mime_content_type($audioPath) ?: 'audio/mpeg';
    $ch = curl_init('https://open.bigmodel.cn/api/paas/v4/audio/transcriptions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $apiKey],
        CURLOPT_POSTFIELDS => [
            'model' => 'glm-asr-2512',
            'stream' => 'false',
            'file' => new CURLFile($audioPath, $mimeType, 'voice-input.mp3')
        ],
    ]);

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        error_log('Voice recognition curl error: ' . curl_error($ch));
    }
    curl_close($ch);

    $decoded = json_decode((string)$response, true);
    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : []
    ];
}
