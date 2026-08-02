<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/HireToEmployeeService.php';
require_once dirname(__DIR__, 2) . '/kernel/bootstrap.php';

$platformContext = platformApiContext(['domain' => 'recruitment', 'action' => 'recruitment.hire.approve']);
$platformLogger = new PlatformApiLogger();

try {
    $context = recruitmentAdminBootstrap('recruitment.hire_approve', ['POST']);
    recruitmentAdminRequireIdempotency($context);
    $platformAuth = platformApiAuthContext();
    $platformAuth->requirePermission('recruitment.hire_approve');
    $input = recruitmentAdminInput();
    $result = (new HireToEmployeeService($context['db'], $context['permission_service']))->approve(
        (int) ($input['application_id'] ?? 0),
        (string) ($input['approval_reason'] ?? ''),
        (int) ($input['state_version'] ?? -1),
        (string) $context['idempotency_key'],
        $context['recruitment_scope'],
        (int) ($context['staff']['id'] ?? 0)
    );
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'hire.approve',
        'target_type' => 'recruitment_application',
        'target_id' => (string) $result['application_id'],
        'after' => $result,
    ]);
    $domain = PlatformBusinessDomainRegistry::get('recruitment');
    $result = PlatformApiCompatibility::withMetadata($result, $domain['endpoint_version'], $domain['capabilities']);
    $platformLogger->log('info', 'recruitment.hire.approve', $platformContext, ['application_id' => $result['application_id']]);
    jsonResponse(0, '录用审批已完成', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '录用审批失败');
}
