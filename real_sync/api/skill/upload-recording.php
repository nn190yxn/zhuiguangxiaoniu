<?php
declare(strict_types=1);
/**
 * 销售录音复盘上传与分析 API
 * POST /api/skill/upload-recording.php
 * 
 * 上传完成后在同一数据库事务创建复盘记录和平台任务。
 */

require_once __DIR__ . '/../../api/config.php';
require_once __DIR__ . '/../../api/kernel/bootstrap.php';
require_once __DIR__ . '/../../api/platform/JobQueue.php';
require_once __DIR__ . '/../../api/platform/PrivateFileStorage.php';
handleCORS();

$context = platformApiContext(['domain' => 'skill', 'action' => 'skill.recording.upload']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    throw new PlatformApiException(405, 'method_not_allowed', '只支持 POST 请求');
}

$auth = platformApiAuthContext();
$auth->requireAuthenticated();
$context = $context->withActor($auth->userId(), $auth->staffId());
$userId = (int)$auth->userId();
$staff = getStaffByUserId($userId);
if (!$staff || (int)($staff['id'] ?? 0) !== (int)$auth->staffId()) {
    throw new PlatformApiException(403, 'staff_context_missing', '未找到员工资料');
}

try {
    $pdo = getDB();
    platformRequireMigrationReadiness($pdo, ['202607310008', '202607310010', '202607310012']);
} catch (PlatformApiException $exception) {
    throw $exception;
}

$sceneType = isset($_POST['scene_type']) ? trim($_POST['scene_type']) : '';
if (!in_array($sceneType, ['new_sale', 'renewal', 'assessment'])) {
    throw new PlatformApiException(400, 'scene_type_invalid', '请选择复盘场景：新签/续费/体测解读');
}

$sceneNames = [
    'new_sale' => '新签复盘',
    'renewal' => '续费复盘',
    'assessment' => '体测解读复盘',
];

if (!isset($_FILES['recording']) || $_FILES['recording']['error'] !== UPLOAD_ERR_OK) {
    $error = $_FILES['recording']['error'] ?? UPLOAD_ERR_NO_FILE;
    throw new PlatformApiException(400, 'recording_upload_failed', '录音文件上传失败，错误码: ' . $error);
}

$recording = $_FILES['recording'];
$maxSize = 50 * 1024 * 1024;
if ($recording['size'] > $maxSize) {
    throw new PlatformApiException(400, 'recording_too_large', '录音文件超过 50MB 限制');
}

$storage = new PlatformPrivateFileStorage();
try {
    $stored = $storage->storeUploadedFile($recording, [
        'allowed_mime_types' => [
            'audio/aac',
            'audio/mp4',
            'audio/x-m4a',
            'audio/mpeg',
            'audio/ogg',
            'audio/wav',
            'audio/x-wav',
            'audio/webm',
            'video/webm',
        ],
        'max_bytes' => $maxSize,
        'namespace' => 'skill/review-recordings',
    ]);
} catch (InvalidArgumentException $exception) {
    throw new PlatformApiException(400, 'recording_invalid', '录音文件格式或内容无效', [], $exception);
} catch (RuntimeException $exception) {
    throw new PlatformApiException(500, 'recording_storage_failed', '录音文件保存失败', [], $exception);
}
$recordingUrl = 'local_private:' . $stored['storage_key'];

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("INSERT INTO skill_review_records 
        (user_id, staff_id, scene_type, recording_url, status) 
        VALUES (?, ?, ?, ?, 'pending')");
    $stmt->execute([$userId, (int)$staff['id'], $sceneType, $recordingUrl]);
    $recordId = (int)$pdo->lastInsertId();
    $queue = new PlatformJobQueueService(new PlatformPdoJobQueueStore($pdo));
    $queue->enqueue(
        'skill.review.process',
        'skill_review_record',
        (string)$recordId,
        hash('sha256', 'skill.review.process:' . $recordId),
        ['record_id' => $recordId],
        20,
        3
    );
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[skill.review] DB error: ' . $e->getMessage());
    $storage->delete((string)$stored['storage_key']);
    throw new PlatformApiException(500, 'skill_review_create_failed', '创建复盘记录失败', [], $e);
}

$migration = PlatformBusinessDomainRegistry::get('skill');
$result = PlatformApiCompatibility::withMetadata([
    'record_id' => $recordId,
    'scene_name' => $sceneNames[$sceneType],
    'recording_url' => $recordingUrl,
], $migration['endpoint_version'], $migration['capabilities']);
$logger->log('info', 'skill.recording.upload', $context, [
    'record_id' => $recordId,
    'scene_type' => $sceneType,
    'storage_driver' => 'local_private',
    'byte_size' => $stored['byte_size'],
    'sha256' => $stored['sha256'],
]);
platformApiResponse($context, $result, '录音已上传，将由后台自动分析')->send();
