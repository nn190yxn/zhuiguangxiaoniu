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
    $normalizedFiles = is_array($files) ? $files : [];
    $fileFingerprint = [];
    $fileNames = is_array($normalizedFiles['name'] ?? null) ? $normalizedFiles['name'] : [$normalizedFiles['name'] ?? ''];
    $fileSizes = is_array($normalizedFiles['size'] ?? null) ? $normalizedFiles['size'] : [$normalizedFiles['size'] ?? 0];
    foreach ($fileNames as $index => $name) {
        $fileFingerprint[] = [(string) $name, (int) ($fileSizes[$index] ?? 0)];
    }
    $result = recruitmentAdminIdempotent($context['db'], 'resume.upload', $context['idempotency_key'], [
        'batch_id' => $batchId,
        'files' => $fileFingerprint,
    ], fn (): array => $service->upload($batchId, $normalizedFiles, $context['recruitment_scope'], $context['staff']));
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
