<?php
declare(strict_types=1);

require_once __DIR__ . '/common.php';

header('Content-Type: application/json; charset=utf-8');

try {
    [$userId, $user, $staff] = adminRequirePermission('knowledge_audit');
    $db = getDB();
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

    if ($method === 'GET') {
        $status = trim((string)($_GET['status'] ?? 'pending'));
        if (!in_array($status, ['pending', 'confirmed', 'rejected', 'all'], true)) {
            jsonResponse(422, '无效的审核状态');
        }
        $keyword = trim((string)($_GET['keyword'] ?? ''));
        $limit = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $where = ['1 = 1'];
        $params = [];
        if ($status !== 'all') {
            $where[] = 'r.review_status = ?';
            $params[] = $status;
        }
        if ($keyword !== '') {
            $where[] = '(k.item_code LIKE ? OR COALESCE(v.title, k.title) LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }
        $whereSql = implode(' AND ', $where);
        $count = $db->prepare("SELECT COUNT(*) FROM knowledge_item_age_ranges r INNER JOIN knowledge_items k ON k.id = r.knowledge_item_id LEFT JOIN knowledge_item_versions v ON v.version_id = r.version_id WHERE {$whereSql}");
        $count->execute($params);
        $stmt = $db->prepare("SELECT r.id, r.knowledge_item_id, r.version_id, r.age_code, r.source_text, r.confidence, r.review_status, r.decision_source, r.review_note, r.reviewed_by, r.reviewed_at, k.item_code, COALESCE(v.title, k.title) AS title FROM knowledge_item_age_ranges r INNER JOIN knowledge_items k ON k.id = r.knowledge_item_id LEFT JOIN knowledge_item_versions v ON v.version_id = r.version_id WHERE {$whereSql} ORDER BY r.review_status = 'pending' DESC, r.created_at ASC, r.id ASC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        jsonResponse(0, 'ok', [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => (int)$count->fetchColumn(),
            'limit' => $limit,
            'offset' => $offset,
        ]);
    }

    if ($method !== 'POST') {
        jsonResponse(405, '仅支持 GET 和 POST 请求');
    }
    $input = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    $id = (int)($input['id'] ?? 0);
    $action = strtolower(trim((string)($input['action'] ?? '')));
    $note = trim((string)($input['review_note'] ?? ''));
    if ($id < 1 || !in_array($action, ['confirm', 'reject'], true) || $note === '') {
        jsonResponse(422, '请提供审核记录、审核动作和原因');
    }
    $db->beginTransaction();
    $beforeStmt = $db->prepare('SELECT * FROM knowledge_item_age_ranges WHERE id = ? FOR UPDATE');
    $beforeStmt->execute([$id]);
    $before = $beforeStmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) {
        $db->rollBack();
        jsonResponse(404, '年龄审核记录不存在');
    }
    $newStatus = $action === 'confirm' ? 'confirmed' : 'rejected';
    $update = $db->prepare("UPDATE knowledge_item_age_ranges SET review_status = ?, decision_source = 'manual_review', review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
    $update->execute([$newStatus, mb_substr($note, 0, 1000), (int)($staff['id'] ?? 0), $id]);
    $after = $before;
    $after['review_status'] = $newStatus;
    $after['decision_source'] = 'manual_review';
    $after['review_note'] = mb_substr($note, 0, 1000);
    $after['reviewed_by'] = (int)($staff['id'] ?? 0);
    $audit = $db->prepare("INSERT INTO knowledge_audit_logs (actor_user_id, actor_staff_id, action, target_type, target_id, before_json, after_json, metadata_json) VALUES (?, ?, ?, 'knowledge_item_age_range', ?, ?, ?, ?)");
    $audit->execute([(int)$userId, (int)($staff['id'] ?? 0), 'age_range_' . $action, (string)$id, json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode(['reason' => $note], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    $db->commit();
    jsonResponse(0, $newStatus === 'confirmed' ? '年龄范围已确认' : '年龄范围已驳回', ['id' => $id, 'review_status' => $newStatus]);
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(500, '年龄审核接口处理失败');
}
