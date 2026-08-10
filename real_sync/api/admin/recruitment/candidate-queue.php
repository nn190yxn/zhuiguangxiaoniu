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
    $action = strtolower(trim((string) ($input['action'] ?? '')));
    $result = recruitmentAdminIdempotent($context['db'], 'resume.queue.' . $action, $context['idempotency_key'], [
        'application_id' => $applicationId,
        'action' => $action,
        'reason' => (string) ($input['reason'] ?? ''),
    ], fn (): array => $service->updateQueue(
        $applicationId,
        $action,
        (string) ($input['reason'] ?? ''),
        $context['recruitment_scope'],
        (int) ($context['staff']['id'] ?? 0)
    ));
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'resume.queue.' . (string) ($input['action'] ?? ''),
        'target_type' => 'recruitment_application',
        'target_id' => (string) ($result['id'] ?? ''),
        'after' => ['queue_status' => $result['queue_status'] ?? null, 'reason' => $input['reason'] ?? ''],
    ]);
    jsonResponse(0, '候选人队列已更新', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '候选人队列更新失败');
}
