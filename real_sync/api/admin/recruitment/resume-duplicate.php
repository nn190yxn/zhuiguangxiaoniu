<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeUploadService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_upload', ['POST']);
    recruitmentAdminRequireIdempotency($context);
    $input = recruitmentAdminInput();
    $service = new ResumeUploadService($context['db'], $context['permission_service']);
    $eventId = (int) ($input['event_id'] ?? $input['id'] ?? 0);
    $action = strtolower(trim((string) ($input['action'] ?? '')));
    $result = recruitmentAdminIdempotent($context['db'], 'resume.duplicate.' . $action, $context['idempotency_key'], [
        'event_id' => $eventId,
        'action' => $action,
        'note' => (string) ($input['note'] ?? ''),
    ], fn (): array => $service->resolveDuplicate(
        $eventId,
        $action,
        (string) ($input['note'] ?? ''),
        $context['recruitment_scope'],
        $context['staff']
    ));
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'resume.duplicate.' . (string) ($input['action'] ?? ''),
        'target_type' => 'recruitment_resume_duplicate_event',
        'target_id' => (string) ($result['id'] ?? ''),
        'after' => $result,
    ]);
    jsonResponse(0, '重复简历处理完成', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '重复简历处理失败');
}
