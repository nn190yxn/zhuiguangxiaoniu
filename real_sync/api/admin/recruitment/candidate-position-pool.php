<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeReviewService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_contact', ['POST']);
    recruitmentAdminRequireIdempotency($context);
    $input = recruitmentAdminInput();
    $routeIds = $input['route_ids'] ?? [];
    if (!is_array($routeIds)) {
        throw new RecruitmentAdminException('route_ids 必须为岗位路由 ID 数组');
    }
    $service = new ResumeReviewService($context['db'], $context['permission_service']);
    $result = $service->addPositionPool(
        (int) ($input['application_id'] ?? $input['id'] ?? 0),
        $routeIds,
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
                'action' => 'resume.position.add_pool',
                'target_type' => 'recruitment_application',
                'target_id' => (string) ($result['source_application_id'] ?? ''),
                'after' => [
                    'route_ids' => array_values(array_map('intval', $routeIds)),
                    'application_ids' => array_values(array_map(
                        static fn(array $application): int => (int) ($application['id'] ?? 0),
                        $result['applications'] ?? []
                    )),
                ],
            ]);
        } catch (Throwable $auditError) {
            error_log('[admin.recruitment.audit] ' . $auditError->getMessage());
        }
    }
    jsonResponse(0, '候选人已加入所选岗位池', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '候选人多岗位入池失败');
}
