<?php
/**
 * 每日签到API
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/DailyCheckinService.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$context = platformApiContext(['domain' => 'points', 'action' => 'points.daily_checkin']);
platformApiInstallExceptionHandler($context);

$db = getDB();
$userId = (int)getCurrentUserId();

if ($method !== 'POST') {
    throw new PlatformApiException(405, 'method_not_allowed', '不支持的请求方法');
}
if ($userId <= 0) {
    throw new PlatformApiException(401, 'authentication_required', '请先登录');
}
$context = $context->withActor($userId, null);

$businessDate = date('Y-m-d');
$idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
$response = (new PlatformIdempotencyService($db))->execute(
    $context,
    'points.daily_checkin',
    'date:' . $businessDate,
    $idempotencyKey,
    ['business_date' => $businessDate],
    static function () use ($db, $context, $userId, $businessDate): PlatformApiResponse {
        $data = (new DailyCheckinService($db))->checkIn($userId, $businessDate);
        return PlatformApiResponse::success($context, $data, '签到成功');
    }
);

$response->send();
