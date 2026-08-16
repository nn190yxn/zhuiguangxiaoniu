<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/ResumeUploadService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.resume_upload', ['GET', 'POST']);
    $service = new ResumeUploadService($context['db'], $context['permission_service']);
    if ($context['method'] === 'GET') {
        jsonResponse(0, 'ok', $service->listDuplicateEvents($context['recruitment_scope'], $_GET));
    }
    recruitmentAdminRequireIdempotency($context);
    $input = recruitmentAdminInput();
    $action = strtolower(trim((string) ($input['action'] ?? '')));
    if ($action === 'batch_resolve') {
        $result = recruitmentAdminIdempotent($context['db'], 'resume.duplicate.batch_resolve', $context['idempotency_key'], [
            'event_ids' => $input['event_ids'] ?? [],
            'resolution_action' => (string) ($input['resolution_action'] ?? ''),
            'note' => (string) ($input['note'] ?? ''),
        ], fn (): array => $service->resolveDuplicateBatch(
            (array) ($input['event_ids'] ?? []),
            strtolower(trim((string) ($input['resolution_action'] ?? ''))),
            (string) ($input['note'] ?? ''),
            $context['recruitment_scope'],
            $context['staff']
        ));
        adminRecordOperation($context['db'], $context['user'], $context['staff'], [
            'module' => 'recruitment',
            'action' => 'resume.duplicate.batch_resolve',
            'target_type' => 'recruitment_resume_duplicate_event_batch',
            'target_id' => 'batch',
            'after' => ['action' => $result['action'] ?? null, 'requested_count' => $result['requested_count'] ?? 0, 'success_count' => $result['success_count'] ?? 0, 'failed_count' => $result['failed_count'] ?? 0],
        ]);
        jsonResponse(0, '重复简历批量处理完成', $result);
    }
    $eventId = (int) ($input['event_id'] ?? $input['id'] ?? 0);
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
