<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeClassificationReviewService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_upload', ['GET', 'POST']);
    $service = new ResumeClassificationReviewService($context['db'], $context['permission_service']);
    if ($context['method'] === 'GET') {
        jsonResponse(0, 'ok', $service->list($context['recruitment_scope']));
    }
    recruitmentAdminRequireIdempotency($context);
    $input = recruitmentAdminInput();
    $action = strtolower(trim((string) ($input['action'] ?? '')));
    $documentId = (int) ($input['document_id'] ?? 0);
    $versionId = (int) ($input['classification_version_id'] ?? 0);
    if (!in_array($action, ['confirm', 'reclassify'], true)) {
        throw new RecruitmentAdminException('分类操作无效');
    }
    $result = recruitmentAdminIdempotent($context['db'], 'resume.classification.' . $action, $context['idempotency_key'], $input, fn (): array => $action === 'confirm'
        ? $service->confirm($documentId, (int) ($input['requirement_id'] ?? 0), $versionId, (string) ($input['reason'] ?? ''), $context['recruitment_scope'], (int) ($context['staff']['id'] ?? 0))
        : $service->reclassify($documentId, $versionId, $context['recruitment_scope'], (int) ($context['staff']['id'] ?? 0)));
    jsonResponse(0, $action === 'confirm' ? '简历岗位确认完成' : '简历已进入重新分类队列', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '简历分类接口处理失败');
}
