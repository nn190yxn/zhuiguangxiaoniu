<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeReviewService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_contact', ['POST']);
    recruitmentAdminRequireIdempotency($context);
    $input = recruitmentAdminInput();
    $service = new ResumeReviewService($context['db'], $context['permission_service']);
    $result = $service->confirmRecommendedPosition(
        (int) ($input['application_id'] ?? $input['id'] ?? 0),
        $context['recruitment_scope'],
        (int) ($context['staff']['id'] ?? 0),
        array_key_exists('state_version', $input) ? (int) $input['state_version'] : null
    );
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'resume.position.confirm',
        'target_type' => 'recruitment_application',
        'target_id' => (string) ($result['id'] ?? ''),
        'after' => [
            'position_confirmation_status' => $result['position_confirmation_status'] ?? null,
            'confirmed_route_id' => $result['confirmed_route_id'] ?? null,
        ],
    ]);
    jsonResponse(0, '推荐岗位已确认', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '推荐岗位确认失败');
}
