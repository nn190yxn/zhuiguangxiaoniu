<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeReviewService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_contact', ['POST']);
    recruitmentAdminRequireIdempotency($context);
    $input = recruitmentAdminInput();
    $service = new ResumeReviewService($context['db'], $context['permission_service']);
    $result = $service->adjustPosition(
        (int) ($input['application_id'] ?? $input['id'] ?? 0),
        (int) ($input['target_route_id'] ?? 0),
        (string) ($input['reason'] ?? ''),
        $context['recruitment_scope'],
        (int) ($context['staff']['id'] ?? 0),
        array_key_exists('state_version', $input) ? (int) $input['state_version'] : null,
        (string) $context['idempotency_key']
    );
    if (empty($result['idempotent'])) {
        try {
            adminRecordOperation($context['db'], $context['user'], $context['staff'], [
                'module' => 'recruitment',
                'action' => 'resume.position.adjust',
                'target_type' => 'recruitment_application',
                'target_id' => (string) ($result['id'] ?? ''),
                'after' => [
                    'position_confirmation_status' => $result['position_confirmation_status'] ?? null,
                    'confirmed_route_id' => $result['confirmed_route_id'] ?? null,
                    'reason' => $input['reason'] ?? '',
                ],
            ]);
        } catch (Throwable $auditError) {
            error_log('[admin.recruitment.audit] ' . $auditError->getMessage());
        }
    }
    jsonResponse(0, '候选岗位已调整并重新评分', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '候选岗位调整失败');
}
