<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeUploadService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_upload', ['POST']);
    recruitmentAdminRequireIdempotency($context);
    $batchId = (int) ($_POST['batch_id'] ?? $_GET['batch_id'] ?? 0);
    $files = $_FILES['files'] ?? $_FILES['file'] ?? [];
    $service = new ResumeUploadService($context['db'], $context['permission_service']);
    $result = $service->upload(
        $batchId,
        is_array($files) ? $files : [],
        $context['recruitment_scope'],
        $context['staff']
    );
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'resume.upload',
        'target_type' => 'recruitment_resume_batch',
        'target_id' => (string) $batchId,
        'after' => ['accepted_count' => $result['accepted_count'], 'rejected_count' => $result['rejected_count']],
    ]);
    jsonResponse(0, '简历上传已完成', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '简历上传失败');
}
