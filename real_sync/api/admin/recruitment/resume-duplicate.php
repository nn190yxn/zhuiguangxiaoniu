<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeUploadService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_upload', ['POST']);
    recruitmentAdminRequireIdempotency($context);
    $input = recruitmentAdminInput();
    $service = new ResumeUploadService($context['db'], $context['permission_service']);
    $result = $service->resolveDuplicate(
        (int) ($input['event_id'] ?? $input['id'] ?? 0),
        (string) ($input['action'] ?? ''),
        (string) ($input['note'] ?? ''),
        $context['recruitment_scope'],
        $context['staff']
    );
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
