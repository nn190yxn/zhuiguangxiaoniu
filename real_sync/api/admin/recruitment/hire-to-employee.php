<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/HireToEmployeeService.php';
require_once dirname(__DIR__, 2) . '/kernel/bootstrap.php';

$platformContext = platformApiContext(['domain' => 'recruitment', 'action' => 'recruitment.hire.convert']);
$platformLogger = new PlatformApiLogger();

try {
    $context = recruitmentAdminBootstrap('recruitment.hire_convert', ['POST']);
    recruitmentAdminRequireIdempotency($context);
    $platformAuth = platformApiAuthContext();
    $platformAuth->requirePermission('recruitment.hire_convert');
    $input = recruitmentAdminInput();
    $result = (new HireToEmployeeService($context['db'], $context['permission_service']))->convert(
        (int) ($input['application_id'] ?? 0),
        $input,
        (int) ($input['state_version'] ?? -1),
        (string) $context['idempotency_key'],
        $context['recruitment_scope'],
        $context['user'],
        $context['staff']
    );
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'hire.convert',
        'target_type' => 'recruitment_application',
        'target_id' => (string) $result['application_id'],
        'after' => ['employee_staff_id' => $result['employee']['id'], 'state_version' => $result['state_version']],
    ]);
    $domain = PlatformBusinessDomainRegistry::get('recruitment');
    $result = PlatformApiCompatibility::withMetadata($result, $domain['endpoint_version'], $domain['capabilities']);
    $platformLogger->log('info', 'recruitment.hire.convert', $platformContext, ['application_id' => $result['application_id'], 'employee_staff_id' => $result['employee']['id']]);
    jsonResponse(0, '候选人已转为员工', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '录用转员工失败');
}
