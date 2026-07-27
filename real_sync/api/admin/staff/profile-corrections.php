<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/StaffProfileService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    [, $user, $staff] = adminRequirePermission('staff.edit');
    $service = new StaffProfileService(getDB());
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        jsonResponse(0, 'success', $service->listCorrections($_GET));
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(405, '仅支持 GET 或 POST 请求');
    }
    $input = adminJsonInput();
    $item = $service->handle(
        (int)($input['request_id'] ?? $input['id'] ?? 0),
        (string)($input['status'] ?? ''),
        (string)($input['handler_comment'] ?? $input['comment'] ?? ''),
        $user,
        $staff
    );
    jsonResponse(0, 'success', ['item' => $item]);
} catch (InvalidArgumentException $error) {
    jsonResponse(400, $error->getMessage());
} catch (DomainException $error) {
    jsonResponse(409, $error->getMessage());
} catch (Throwable $error) {
    error_log('[admin.staff.profile-corrections] ' . $error->getMessage());
    jsonResponse(1, '更正申请处理失败');
}
