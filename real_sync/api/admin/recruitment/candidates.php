<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeReviewService.php';
require_once dirname(__DIR__, 2) . '/kernel/bootstrap.php';

$platformContext = platformApiContext(['domain' => 'recruitment', 'action' => 'recruitment.candidates.read', 'state_version' => 'recruitment_applications.state_version']);
$platformLogger = new PlatformApiLogger();

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_view', ['GET']);
    $platformAuth = platformApiAuthContext();
    $platformAuth->requirePermission('recruitment.resume_view');
    $service = new ResumeReviewService($context['db'], $context['permission_service']);
    $result = $service->list($context['recruitment_scope'], $_GET);
    $domain = PlatformBusinessDomainRegistry::get('recruitment');
    $result = PlatformApiCompatibility::withMetadata($result, $domain['endpoint_version'], $domain['capabilities']);
    $platformLogger->log('info', 'recruitment.candidates.read', $platformContext, ['total' => $result['total']]);
    jsonResponse(0, 'ok', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '候选人列表查询失败');
}
