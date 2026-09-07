<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeUploadService.php';
require_once __DIR__ . '/platform/RecruitmentPlatformJobAdapter.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_upload', ['POST']);
    recruitmentAdminRequireIdempotency($context);
    $input = recruitmentAdminInput();
    $batchId = (int) ($input['batch_id'] ?? 0);
    $limit = max(1, min(100, (int) ($input['limit'] ?? 100)));
    if ($batchId <= 0) {
        throw new RecruitmentAdminException('请先选择需要处理的简历批次');
    }
    (new ResumeUploadService($context['db'], $context['permission_service']))
        ->accessibleBatch($batchId, $context['recruitment_scope']);
    $result = recruitmentAdminIdempotent($context['db'], 'resume.process.dispatch', $context['idempotency_key'], [
        'batch_id' => $batchId,
        'limit' => $limit,
    ], fn (): array => (new RecruitmentPlatformJobAdapter($context['db']))->dispatchBatch($batchId, $limit));
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'resume.process.dispatch',
        'target_type' => 'recruitment_resume_batch',
        'target_id' => (string) $batchId,
        'after' => $result,
    ]);
    jsonResponse(0, $result['message'], $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '简历处理触发失败');
}
