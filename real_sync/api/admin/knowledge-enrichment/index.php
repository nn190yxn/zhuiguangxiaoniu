<?php
require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/KnowledgeEnrichmentReviewService.php';
require_once dirname(__DIR__) . '/services/KnowledgeEnrichmentReleaseService.php';

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = $method === 'POST' ? adminJsonInput() : $_GET;
    $action = (string)($input['action'] ?? 'list');
    $permission = $method === 'GET' ? 'knowledge_audit' : 'knowledge_edit';
    [$uid, $user, $staff] = adminRequirePermission($permission);
    $service = new KnowledgeEnrichmentReviewService(getDB());
    $service->requireSchema();
    if ($method === 'GET' && $action === 'list') jsonSuccess($service->list($input), 'ok');
    if ($method === 'GET' && $action === 'item') jsonSuccess($service->item((int)($input['id'] ?? 0)), 'ok');
    if ($method === 'POST' && $action === 'decision') jsonSuccess($service->decide((int)($input['id'] ?? 0), (string)($input['decision'] ?? ''), (string)($input['note'] ?? ''), ['user_id' => $uid, 'staff_id' => $staff['id'] ?? null]), 'ok');
    if ($method === 'POST' && $action === 'save') jsonSuccess($service->saveDraft((int)($input['id'] ?? 0), (string)($input['content'] ?? ''), ['user_id' => $uid, 'staff_id' => $staff['id'] ?? null]), 'ok');
    $release = new KnowledgeEnrichmentReleaseService(getDB());
    if ($method === 'POST' && $action === 'publish') jsonSuccess($release->publish($input, ['user_id' => $uid, 'staff_id' => $staff['id'] ?? null]), 'ok');
    if ($method === 'POST' && $action === 'rollback') jsonSuccess($release->rollback((string)($input['release_batch_id'] ?? ''), ['user_id' => $uid, 'staff_id' => $staff['id'] ?? null]), 'ok');
    if ($method === 'GET' && $action === 'report') jsonSuccess($release->report((string)($input['release_batch_id'] ?? '')), 'ok');
    jsonResponse(404, '不支持的 action 或方法');
} catch (Throwable $e) {
    jsonResponse(400, $e->getMessage());
}
