<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/SessionFactory.php';
require_once __DIR__ . '/PwaSessionHttp.php';

$context = platformApiContext(['client' => 'pwa', 'version' => 'v1', 'domain' => 'auth.session']);
platformApiInstallExceptionHandler($context, new PlatformApiLogger());
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Credentials: true');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 POST 请求');
}
platformPwaRequireTrustedOrigin();

$refreshToken = (string)($_COOKIE[PLATFORM_PWA_REFRESH_COOKIE] ?? '');
$csrfCookie = (string)($_COOKIE[PLATFORM_PWA_CSRF_COOKIE] ?? '');
$csrfHeader = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
if ($csrfCookie === '' || $csrfHeader === '' || !hash_equals($csrfCookie, $csrfHeader)) {
    throw new PlatformApiException(403, 'csrf_validation_failed', '刷新请求校验失败');
}

$db = getDB();
platformRequireMigrationReadiness($db, ['202607310002']);
$service = platformSessionService($db);
$session = $service->inspectRefreshToken($refreshToken);
if (!platformPwaValidateCsrf($csrfHeader, (string)$session['id'])) {
    throw new PlatformApiException(403, 'csrf_validation_failed', '刷新请求校验失败');
}

if (($_GET['action'] ?? '') === 'logout') {
    $service->revokeFamily((string)$session['family_id'], 'logout');
    platformPwaClearSessionCookies();
    platformApiResponse($context, ['revoked' => true])->send();
}

$userStmt = $db->prepare('SELECT ID, user_login, user_status FROM wp_users WHERE ID = ? LIMIT 1');
$userStmt->execute([(int)$session['user_id']]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
$staff = getStaffByUserId((int)$session['user_id']);
$staffActive = $staff
    && (int)($staff['status'] ?? 0) === 1
    && (string)($staff['lifecycle_status'] ?? 'active') === 'active';
$adminActive = !$staff && (string)$session['role'] === 'admin';
if (!$user || (int)$user['user_status'] !== 0 || (!$staffActive && !$adminActive)) {
    $service->revokeFamily((string)$session['family_id'], 'account_inactive');
    platformPwaClearSessionCookies();
    throw new PlatformApiException(401, 'session_revoked', '账号状态已变化，请重新登录');
}

$currentVersion = $staff ? (int)($staff['session_version'] ?? 0) : (int)$session['session_version'];
$tokens = $service->refresh($refreshToken, $currentVersion);
platformPwaSetSessionCookies($tokens['refresh_token'], $tokens['session_id'], $tokens['refresh_expires_in']);
unset($tokens['refresh_token']);

platformApiResponse($context, $tokens)->send();
