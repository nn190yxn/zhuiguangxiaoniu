<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/RecruitmentRequirementService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.requirement_manage', ['GET', 'POST']);
    $service = new RecruitmentRequirementService($context['db'], $context['permission_service']);

    if ($context['method'] === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        jsonResponse(0, 'ok', $id > 0
            ? $service->getRequirement($id, $context['recruitment_scope'])
            : $service->listRequirements($context['recruitment_scope'], [
                'status' => $_GET['status'] ?? 'all',
                'store_id' => $_GET['store_id'] ?? 0,
                'keyword' => $_GET['keyword'] ?? '',
            ]));
    }

    $input = recruitmentAdminInput();
    $action = strtolower(trim((string) ($input['action'] ?? '')));
    $id = (int) ($input['id'] ?? 0);

    if (in_array($action, ['submit', 'approve', 'return', 'close', 'reopen'], true)) {
        if (in_array($action, ['approve', 'return', 'close', 'reopen'], true)
            && !adminHasPermission('recruitment.requirement_approve', $context['user'], $context['staff'])) {
            jsonResponse(403, '你没有权限审批或关闭招聘需求', null);
        }
        jsonResponse(0, '招聘需求状态已更新', $service->transition(
            $id,
            $action,
            $input,
            $context['user'],
            $context['staff'],
            $context['recruitment_scope'],
            $context['idempotency_key']
        ));
    }

    $result = $id > 0
        ? $service->updateDraft($id, $input, $context['user'], $context['staff'], $context['recruitment_scope'], $context['idempotency_key'])
        : $service->createDraft($input, $context['user'], $context['staff'], $context['recruitment_scope'], $context['idempotency_key']);
    jsonResponse(0, $id > 0 ? '招聘需求草稿已更新' : '招聘需求草稿已创建', $result);
} catch (Throwable $error) {
    if ($error instanceof RecruitmentRequirementException) {
        jsonResponse($error->statusCode(), $error->getMessage(), $error->details() ?: null);
    }
    recruitmentAdminFailure($error, '招聘需求接口处理失败');
}
