<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/OrganizationService.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, '请求方法不被支持', null);
}

try {
    adminRequirePermission('organization.manage');

    $service = new OrganizationService(getDB());
    jsonResponse(0, 'ok', $service->getOrganizationTree());
} catch (Throwable $error) {
    error_log('Organization tree API failed: ' . $error->getMessage());
    jsonResponse(500, '组织架构查询失败', null);
}
