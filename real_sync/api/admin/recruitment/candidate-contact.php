<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeReviewService.php';
require_once dirname(__DIR__, 2) . '/kernel/bootstrap.php';

$platformContext = platformApiContext(['domain' => 'recruitment', 'action' => 'recruitment.candidate.contact']);
$platformLogger = new PlatformApiLogger();

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $permission = $method === 'GET' ? 'recruitment.resume_phone_view' : 'recruitment.resume_contact';
    $context = recruitmentAdminBootstrap($permission, ['GET', 'POST']);
    $platformAuth = platformApiAuthContext();
    if ($method === 'GET') {
        $platformAuth->requirePermission('recruitment.resume_phone_view');
    } else {
        $platformAuth->requirePermission('recruitment.resume_contact');
    }
    $service = new ResumeReviewService($context['db'], $context['permission_service']);
    if ($context['method'] === 'GET') {
        $result = $service->revealPhone((int) ($_GET['application_id'] ?? $_GET['id'] ?? 0), $context['recruitment_scope']);
        adminRecordOperation($context['db'], $context['user'], $context['staff'], [
            'module' => 'recruitment',
            'action' => 'resume.phone.reveal',
            'target_type' => 'recruitment_application',
            'target_id' => (string) ($result['application_id'] ?? ''),
            'after' => ['key_version' => $result['key_version'] ?? null],
        ]);
        $domain = PlatformBusinessDomainRegistry::get('recruitment');
        $result = PlatformApiCompatibility::withMetadata($result, $domain['endpoint_version'], $domain['capabilities']);
        $platformLogger->log('info', 'recruitment.candidate.phone.read', $platformContext, ['application_id' => $result['application_id']]);
        jsonResponse(0, 'ok', $result);
    }
    recruitmentAdminRequireIdempotency($context);
    $input = recruitmentAdminInput();
    $applicationId = (int) ($input['application_id'] ?? $input['id'] ?? 0);
    $contactStatus = (string) ($input['contact_status'] ?? $input['status'] ?? '');
    $result = recruitmentAdminIdempotent($context['db'], 'resume.contact.record', $context['idempotency_key'], [
        'application_id' => $applicationId,
        'contact_status' => $contactStatus,
        'note' => (string) ($input['note'] ?? ''),
        'scheduled_at' => $input['scheduled_at'] ?? null,
        'state_version' => $input['state_version'] ?? null,
    ], fn (): array => $service->updateContact(
        $applicationId,
        $contactStatus,
        (string) ($input['note'] ?? ''),
        isset($input['scheduled_at']) ? (string) $input['scheduled_at'] : null,
        $context['recruitment_scope'],
        (int) ($context['staff']['id'] ?? 0),
        (string) $context['idempotency_key'],
        array_key_exists('state_version', $input) ? (int) $input['state_version'] : null
    ));
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'resume.contact.record',
        'target_type' => 'recruitment_application',
        'target_id' => (string) ($result['id'] ?? ''),
        'after' => ['contact_status' => $input['contact_status'] ?? $input['status'] ?? '', 'scheduled_at' => $input['scheduled_at'] ?? null],
    ]);
    $domain = PlatformBusinessDomainRegistry::get('recruitment');
    $result = PlatformApiCompatibility::withMetadata($result, $domain['endpoint_version'], $domain['capabilities']);
    $platformLogger->log('info', 'recruitment.candidate.contact', $platformContext, ['application_id' => $result['id'], 'state_version' => $result['state_version']]);
    jsonResponse(0, '联系记录已保存', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '候选人联系接口处理失败');
}
