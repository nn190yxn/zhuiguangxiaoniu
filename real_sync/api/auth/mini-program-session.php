<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once __DIR__ . '/MiniProgramSession.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(200);
    exit;
}
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    json_response(405, '仅支持 POST 请求');
}

try {
    $db = getDB();
    platformRequireMigrationReadiness($db, ['202607310002', '202607310003']);
    $input = getRequestInput();
    $refreshToken = trim((string)($input['refresh_token'] ?? ''));
    $deviceId = mb_substr(trim((string)($input['device_id'] ?? '')), 0, 120);
    $service = platformSessionService($db);
    $session = $service->inspectRefreshToken($refreshToken);
    $staff = platformValidateMiniProgramSession($db, $session, $deviceId);

    if (($_GET['action'] ?? 'refresh') === 'logout') {
        $service->revokeFamily((string)$session['family_id'], 'logout');
        json_response(0, 'success', ['reauthentication_required' => true]);
    }

    $renewed = $service->refresh($refreshToken, (int)($staff['session_version'] ?? 0));
    json_response(0, 'success', platformMiniProgramResponse($renewed));
} catch (PlatformApiException $error) {
    $status = $error->httpStatus();
    $data = ['error_code' => $error->errorCode()];
    if ($status === 401) {
        $data['reauthentication_required'] = true;
    }
    json_response($status, $error->getMessage(), $data);
} catch (Throwable $error) {
    error_log('[mini-program-session] ' . $error->getMessage());
    json_response(500, '会话服务暂时不可用，请稍后重试');
}
