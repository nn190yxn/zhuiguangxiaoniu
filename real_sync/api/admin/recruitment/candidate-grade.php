<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeReviewService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_contact', ['POST']);
    recruitmentAdminRequireIdempotency($context);
    $input = recruitmentAdminInput();
    $service = new ResumeReviewService($context['db'], $context['permission_service']);
    $applicationId = (int) ($input['application_id'] ?? $input['id'] ?? 0);
    $result = recruitmentAdminIdempotent($context['db'], 'resume.grade.review', $context['idempotency_key'], [
        'application_id' => $applicationId,
        'manual_grade' => strtoupper(trim((string) ($input['manual_grade'] ?? ''))),
        'reason' => (string) ($input['reason'] ?? ''),
    ], fn (): array => $service->reviewGrade(
        $applicationId,
        (string) ($input['manual_grade'] ?? ''),
        (string) ($input['reason'] ?? ''),
        $context['recruitment_scope'],
        (int) ($context['staff']['id'] ?? 0)
    ));
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'resume.grade.review',
        'target_type' => 'recruitment_application',
        'target_id' => (string) ($result['id'] ?? ''),
        'after' => ['manual_grade' => $result['manual_grade'] ?? null, 'reason' => $input['reason'] ?? ''],
    ]);
    jsonResponse(0, '候选人等级复核完成', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '候选人等级复核失败');
}
