<?php

declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once __DIR__ . '/services/RecruitmentRuleService.php';

try {
    $context = recruitmentAdminBootstrap('recruitment.rule_manage', ['GET', 'POST']);
    $service = new RecruitmentRuleService($context['db']);

    if ($context['method'] === 'GET') {
        $id = (int) ($_GET['id'] ?? 0);
        jsonResponse(0, 'ok', $id > 0
            ? $service->getRule($id)
            : $service->listRules([
                'status' => $_GET['status'] ?? 'all',
                'position_id' => $_GET['position_id'] ?? 0,
                'keyword' => $_GET['keyword'] ?? '',
            ]));
    }

    $input = recruitmentAdminInput();
    $action = strtolower(trim((string) ($input['action'] ?? '')));
    $id = (int) ($input['id'] ?? 0);

    if (in_array($action, ['submit', 'publish', 'archive', 'copy'], true)) {
        if ($action === 'publish' && !adminHasPermission('recruitment.rule_publish', $context['user'], $context['staff'])) {
            jsonResponse(403, '你没有权限发布岗位规则', null);
        }
        jsonResponse(0, '岗位规则状态已更新', $service->transition(
            $id,
            $action,
            $input,
            $context['user'],
            $context['staff'],
            $context['idempotency_key']
        ));
    }

    $result = $id > 0
        ? $service->updateDraft($id, $input, $context['user'], $context['staff'], $context['idempotency_key'])
        : $service->createDraft($input, $context['user'], $context['staff'], $context['idempotency_key']);
    jsonResponse(0, $id > 0 ? '岗位规则草稿已更新' : '岗位规则草稿已创建', $result);
} catch (Throwable $error) {
    if ($error instanceof RecruitmentRuleException) {
        jsonResponse($error->statusCode(), $error->getMessage(), $error->details() ?: null);
    }
    recruitmentAdminFailure($error, '岗位规则接口处理失败');
}
