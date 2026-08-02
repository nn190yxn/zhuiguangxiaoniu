<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/RecruitmentGovernanceService.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = $method === 'POST' ? recruitmentAdminInput() : $_GET;
    $action = strtolower(trim((string) ($input['action'] ?? 'list')));
    $executeActions = ['approve_disposal', 'reject_disposal', 'execute_disposal'];
    $permission = in_array($action, $executeActions, true) ? 'recruitment.retention_execute' : 'recruitment.retention_manage';
    $context = recruitmentAdminBootstrap($permission, ['GET', 'POST']);
    $service = new RecruitmentGovernanceService($context['db']);
    if ($context['method'] === 'GET') {
        jsonResponse(0, 'ok', $service->listRetention());
    }
    recruitmentAdminRequireIdempotency($context);
    $staffId = (int) ($context['staff']['id'] ?? 0);
    $result = match ($action) {
        'save_policy' => $service->savePolicy($input, $staffId),
        'publish_policy' => $service->publishPolicy((int) ($input['id'] ?? 0), $staffId),
        'create_hold' => $service->createHold($input, $staffId),
        'release_hold' => $service->releaseHold((int) ($input['id'] ?? 0), (string) ($input['reason'] ?? ''), $staffId),
        'create_disposal' => $service->createDisposal($input, $staffId),
        'approve_disposal' => $service->decideDisposal((int) ($input['id'] ?? 0), 'approve', $staffId),
        'reject_disposal' => $service->decideDisposal((int) ($input['id'] ?? 0), 'reject', $staffId),
        'execute_disposal' => $service->executeDisposal((int) ($input['id'] ?? 0)),
        default => throw new RecruitmentAdminException('留存治理动作无效'),
    };
    adminRecordOperation($context['db'], $context['user'], $context['staff'], [
        'module' => 'recruitment',
        'action' => 'resume.retention.' . $action,
        'target_type' => 'recruitment_governance',
        'target_id' => (string) ($result['id'] ?? ''),
        'after' => ['status' => $result['status'] ?? $result['execution_status'] ?? null, 'data_category' => $result['data_category'] ?? null],
    ]);
    jsonResponse(0, '留存治理动作已完成', $result);
} catch (Throwable $error) {
    recruitmentAdminFailure($error, '留存治理接口处理失败');
}
