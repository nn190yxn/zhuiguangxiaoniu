<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/kernel/bootstrap.php';

$context = platformApiContext([
    'domain' => 'platform.health',
    'action' => 'health.read',
]);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 请求');
}

$check = strtolower(trim((string)($_GET['check'] ?? 'ready')));
if (!in_array($check, ['live', 'ready', 'dependencies'], true)) {
    throw new PlatformApiException(400, 'invalid_health_check', '健康检查类型无效');
}

if ($check === 'live') {
    $result = PlatformHealthService::live();
} else {
    require_once dirname(__DIR__) . '/config.php';
    $catalog = require dirname(__DIR__, 2) . '/database/migration_catalog.php';
    $db = getDB();
    $versions = array_map('strval', array_keys($catalog));
    $result = $check === 'ready'
        ? PlatformHealthService::ready($db, $versions)
        : PlatformHealthService::dependencies($db, $versions, [
            'smart_lessons' => getenv('SMART_LESSONS_BASE_URL'),
            'deepseek' => getenv('DEEPSEEK_API_KEY'),
            'baidu_ocr' => getenv('BAIDU_OCR_API_KEY') && getenv('BAIDU_OCR_SECRET_KEY'),
        ]);
}

$httpStatus = $result['status'] === 'unhealthy' ? 503 : 200;
$logger->log('info', 'platform.health.read', $context, [
    'check' => $check,
    'status' => $result['status'],
]);
platformApiResponse($context, ['check' => $check, 'health' => $result], 'success', $httpStatus)->send();
