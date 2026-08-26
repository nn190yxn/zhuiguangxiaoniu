<?php
/**
 * 知识库最近浏览 API
 *
 * GET  : 返回当前用户最近浏览记录，保留下架记录但不返回其知识元数据
 * POST : 为已发布知识记录一次浏览
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = (int)getCurrentUserId();
    if ($userId <= 0) {
        jsonResponse(401, '请先登录', null, 401);
        exit;
    }

    if ($method === 'GET') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min((int)($_GET['page_size'] ?? 20), 50));
        $offset = ($page - 1) * $pageSize;

        $countStmt = $db->prepare('SELECT COUNT(*) FROM knowledge_recent_views WHERE user_id = ?');
        $countStmt->execute([$userId]);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT rv.recent_view_id, rv.knowledge_id, rv.view_count,
                    rv.first_viewed_at, rv.last_viewed_at,
                    k.title, k.summary, k.media_type, k.category_id
             FROM knowledge_recent_views rv
             LEFT JOIN knowledge_items k
               ON k.id = rv.knowledge_id
              AND k.status = 1 AND k.publication_status = 'published'
             WHERE rv.user_id = ?
             ORDER BY rv.last_viewed_at DESC, rv.recent_view_id DESC
             LIMIT ?, ?"
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $offset, PDO::PARAM_INT);
        $stmt->bindValue(3, $pageSize, PDO::PARAM_INT);
        $stmt->execute();
        jsonResponse(0, 'success', [
            'list' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    if ($method === 'POST') {
        $input = array_merge($_GET, getRequestInput());
        $knowledgeId = isset($input['knowledge_id']) ? (int)$input['knowledge_id'] : 0;
        if ($knowledgeId <= 0) {
            jsonResponse(1, '缺少知识ID');
        }

        $stmt = $db->prepare(
            "SELECT id FROM knowledge_items
             WHERE id = ? AND status = 1 AND publication_status = 'published' LIMIT 1"
        );
        $stmt->execute([$knowledgeId]);
        if (!$stmt->fetchColumn()) {
            jsonResponse(1, '知识不存在');
        }

        $stmt = $db->prepare(
            "INSERT INTO knowledge_recent_views
                (user_id, knowledge_id, view_count, first_viewed_at, last_viewed_at)
             VALUES (?, ?, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                view_count = view_count + 1,
                last_viewed_at = NOW()"
        );
        $stmt->execute([$userId, $knowledgeId]);
        jsonResponse(0, '浏览记录已保存', ['knowledge_id' => $knowledgeId]);
    }

    jsonResponse(1, '不支持的请求方法');
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
