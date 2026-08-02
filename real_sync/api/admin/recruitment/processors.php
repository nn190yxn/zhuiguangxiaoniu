<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/RecruitmentGovernanceService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.retention_manage', ['GET', 'POST']);
    $service = new RecruitmentGovernanceService($context['db']);
    if ($context['method'] === 'GET') {
        jsonResponse(0, 'ok', ['items' => $service->listProcessors()]);
    }
    recruitmentAdminRequireIdempotency($context);
    $input = recruitmentAdminInput();
    $action = strtolower(trim((string) ($input['action'] ?? 'save')));
    $result = $action === 'save'
        ? $service->saveProcessor($input, (int) ($context['staff']['id'] ?? 0))
        : $service->approveProcessor((int) ($input['id'] ?? 0), $action, (int) ($context['staff']['id'] ?? 0));
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'resume.processor.' . $action,
        'target_type' => 'recruitment_external_processor',
        'target_id' => (string) ($result['id'] ?? ''),
        'after' => ['processor_code' => $result['processor_code'] ?? null, 'approval_status' => $result['approval_status'] ?? null],
    ]);
    jsonResponse(0, '外部处理服务配置已更新', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '外部处理服务配置失败');
}
