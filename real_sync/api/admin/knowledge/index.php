<?php
require_once dirname(__DIR__) . '/common.php';
require_once dirname(__DIR__) . '/services/KnowledgeOperationService.php';

function positiveId($v): int
{
    $n = filter_var($v, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$n) {
        jsonResponse(422, '必须提供正整数 ID');
    }
    return (int)$n;
}

try {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $input = $method === 'POST' ? adminJsonInput() : $_GET;
    $action = (string)($input['action'] ?? '');
    $read = [
        'list_batches' => 'knowledge_view',
        'items' => 'knowledge_view',
        'quality' => 'knowledge_view',
        'item' => 'knowledge_view',
        'relations' => 'knowledge_view',
        'versions' => 'knowledge_view',
        'audit' => 'knowledge_audit',
    ];
    $write = [
        'review_relation' => 'knowledge_edit',
        'create_relation' => 'knowledge_edit',
        'create_version' => 'knowledge_edit',
        'publish' => 'knowledge_publish',
        'unpublish' => 'knowledge_publish',
        'rollback' => 'knowledge_rollback',
    ];
    $perm = $method === 'GET' ? ($read[$action] ?? null) : ($write[$action] ?? null);
    if (!$perm) {
        jsonResponse(404, '不支持的 action 或方法');
    }

    [$uid, $user, $staff] = adminRequirePermission($perm);
    $db = getDB();
    $svc = new KnowledgeOperationService($db);
    $svc->requireSchema();
    $actor = ['user_id' => $uid, 'staff_id' => $staff['id'] ?? null];

    if ($method === 'GET') {
        if ($action === 'list_batches') {
            $data = $svc->listBatches();
        } elseif ($action === 'items') {
            $data = $svc->listItems($input);
        } elseif ($action === 'quality') {
            $data = $svc->quality(isset($input['batch_id']) ? positiveId($input['batch_id']) : null);
        } elseif ($action === 'item') {
            $data = $svc->item(positiveId($input['item_id'] ?? null));
        } elseif ($action === 'relations') {
            $data = $svc->relations(isset($input['item_id']) ? positiveId($input['item_id']) : null);
        } elseif ($action === 'versions') {
            $data = $svc->versions(positiveId($input['item_id'] ?? null));
        } else {
            $data = $svc->auditLogs(isset($input['item_id']) ? positiveId($input['item_id']) : null);
        }
        jsonSuccess($data, 'ok');
    }

    if ($action === 'create_relation') {
        $svc->createRelation(positiveId($input['source_item_id'] ?? null), positiveId($input['target_item_id'] ?? null), (string)($input['relation_type'] ?? 'candidate'), (string)($input['note'] ?? ''), $actor);
    } elseif ($action === 'review_relation') {
        $svc->reviewRelation(positiveId($input['relation_id'] ?? null), (string)($input['relation_type'] ?? ''), (string)($input['note'] ?? ''), $actor);
    } elseif ($action === 'create_version') {
        $svc->createVersion(positiveId($input['item_id'] ?? null), $input, $actor);
    } elseif ($action === 'publish') {
        if (isset($input['batch_id'])) {
            $svc->publishBatch(positiveId($input['batch_id']), $actor, (string)($input['reason'] ?? ''));
        } else {
            $svc->publish(positiveId($input['item_id'] ?? null), $actor, (string)($input['reason'] ?? ''));
        }
    } elseif ($action === 'unpublish') {
        $svc->unpublish(positiveId($input['item_id'] ?? null), $actor, (string)($input['reason'] ?? ''));
    } else {
        $svc->rollback(positiveId($input['item_id'] ?? null), positiveId($input['version_id'] ?? null), $actor, (string)($input['reason'] ?? ''));
    }
    jsonSuccess([], 'ok');
} catch (Throwable $e) {
    jsonResponse(400, $e->getMessage());
}
