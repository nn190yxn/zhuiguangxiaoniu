<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeUploadService.php';
require_once __DIR__ . '/services/ResumeProcessingService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_upload', ['GET', 'POST']);
    $service = new ResumeUploadService($context['db'], $context['permission_service']);
    if ($context['method'] === 'GET') {
        jsonResponse(0, 'ok', $service->listBatches($context['recruitment_scope'], $_GET));
    }

    recruitmentAdminRequireIdempotency($context);
    $input = recruitmentAdminInput();
    $action = strtolower(trim((string) ($input['action'] ?? 'create')));
    if ($action === 'group_images') {
        $batchId = (int) ($input['batch_id'] ?? 0);
        $service->accessibleBatch($batchId, $context['recruitment_scope']);
        $fileIds = is_array($input['file_ids'] ?? null) ? $input['file_ids'] : [];
        $result = recruitmentAdminIdempotent($context['db'], 'resume.document.group', $context['idempotency_key'], [
            'batch_id' => $batchId,
            'file_ids' => $fileIds,
            'supersede_document_id' => $input['supersede_document_id'] ?? null,
        ], fn (): array => $service->documentService()->groupImages(
            $batchId,
            $fileIds,
            (int) ($context['staff']['id'] ?? 0),
            isset($input['supersede_document_id']) ? (int) $input['supersede_document_id'] : null
        ));
        adminRecordOperation($context['db'], $context['user'], $context['staff'], [
            'module' => 'recruitment',
            'action' => 'resume.document.group',
            'target_type' => 'recruitment_resume_document',
            'target_id' => (string) ($result['id'] ?? ''),
            'after' => ['batch_id' => $batchId, 'file_ids' => $input['file_ids'] ?? []],
        ]);
        jsonResponse(0, '图片简历文档已保存', $result);
    }
    if ($action === 'split_images') {
        $batchId = (int) ($input['batch_id'] ?? 0);
        $service->accessibleBatch($batchId, $context['recruitment_scope']);
        $documentId = (int) ($input['document_id'] ?? 0);
        $result = recruitmentAdminIdempotent($context['db'], 'resume.document.split', $context['idempotency_key'], [
            'batch_id' => $batchId,
            'document_id' => $documentId,
        ], fn (): array => $service->documentService()->splitImages(
            $batchId,
            $documentId,
            (int) ($context['staff']['id'] ?? 0)
        ));
        adminRecordOperation($context['db'], $context['user'], $context['staff'], [
            'module' => 'recruitment',
            'action' => 'resume.document.split',
            'target_type' => 'recruitment_resume_document',
            'target_id' => (string) ($input['document_id'] ?? ''),
            'after' => ['batch_id' => $batchId, 'result' => $result],
        ]);
        jsonResponse(0, '图片简历文档已拆分', $result);
    }
    if (in_array($action, ['retry_document', 'reprocess_document'], true)) {
        $batchId = (int) ($input['batch_id'] ?? 0);
        $documentId = (int) ($input['document_id'] ?? 0);
        $service->accessibleBatch($batchId, $context['recruitment_scope']);
        $document = $context['db']->prepare('SELECT 1 FROM recruitment_resume_documents WHERE id = ? AND batch_id = ? LIMIT 1');
        $document->execute([$documentId, $batchId]);
        if (!$document->fetchColumn()) {
            throw new RecruitmentAdminException('简历文档不存在或不属于当前批次', 404);
        }
        $processing = new ResumeProcessingService($context['db']);
        $result = recruitmentAdminIdempotent($context['db'], 'resume.document.' . $action, $context['idempotency_key'], [
            'batch_id' => $batchId,
            'document_id' => $documentId,
            'action' => $action,
        ], fn (): array => $action === 'retry_document'
            ? $processing->retry($documentId, (int) ($context['staff']['id'] ?? 0))
            : $processing->reprocess($documentId, (int) ($context['staff']['id'] ?? 0)));
        adminRecordOperation($context['db'], $context['user'], $context['staff'], [
            'module' => 'recruitment',
            'action' => 'resume.document.' . $action,
            'target_type' => 'recruitment_resume_document',
            'target_id' => (string) $documentId,
            'after' => $result,
        ]);
        jsonResponse(0, $action === 'retry_document' ? '简历任务已重试' : '简历已进入主动重处理队列', $result);
    }
    if (!in_array($action, ['create', 'create_mixed'], true)) {
        throw new RecruitmentAdminException('批次操作无效');
    }
    $result = $action === 'create_mixed'
        ? $service->createMixedBatch($input, $context['recruitment_scope'], $context['staff'], $context['idempotency_key'])
        : $service->createBatch($input, $context['recruitment_scope'], $context['staff'], $context['idempotency_key']);
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'resume.batch.' . $action,
        'target_type' => 'recruitment_resume_batch',
        'target_id' => (string) ($result['id'] ?? ''),
        'after' => ['intake_mode' => $result['intake_mode'] ?? 'single_requirement', 'requirement_id' => $result['requirement_id'] ?? null, 'rule_version_id' => $result['rule_version_id'] ?? null, 'candidate_scope_hash' => $result['candidate_scope_hash'] ?? null],
    ]);
    jsonResponse(0, $action === 'create_mixed' ? '混合简历批次已创建' : '简历批次已创建', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '简历批次接口处理失败');
}
