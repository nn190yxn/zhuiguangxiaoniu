<?php
/**
 * 知识库收藏 API
 *
 * GET  : 查询单条收藏状态，或返回当前用户的可见收藏列表
 * POST : 收藏已发布知识
 * DELETE: 取消收藏
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
        $knowledgeId = isset($_GET['knowledge_id']) ? (int)$_GET['knowledge_id'] : 0;
        if ($knowledgeId > 0) {
            $stmt = $db->prepare(
                "SELECT COUNT(*) FROM knowledge_favorites f
                 JOIN knowledge_items k ON k.id = f.knowledge_id
                 WHERE f.user_id = ? AND f.knowledge_id = ?
                   AND k.status = 1 AND k.publication_status = 'published'"
            );
            $stmt->execute([$userId, $knowledgeId]);
            jsonResponse(0, 'success', ['knowledge_id' => $knowledgeId, 'is_favorite' => (bool)$stmt->fetchColumn()]);
        }

        $stmt = $db->prepare(
            "SELECT f.favorite_id, f.knowledge_id, f.created_at,
                    k.title, k.summary, k.media_type, k.category_id
             FROM knowledge_favorites f
             JOIN knowledge_items k ON k.id = f.knowledge_id
             WHERE f.user_id = ? AND k.status = 1 AND k.publication_status = 'published'
             ORDER BY f.created_at DESC, f.favorite_id DESC"
        );
        $stmt->execute([$userId]);
        jsonResponse(0, 'success', ['list' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    if ($method === 'POST' || $method === 'DELETE') {
        $input = array_merge($_GET, getRequestInput());
        $knowledgeId = isset($input['knowledge_id']) ? (int)$input['knowledge_id'] : 0;
        if ($knowledgeId <= 0) {
            jsonResponse(1, '缺少知识ID');
        }

        if ($method === 'POST') {
            $stmt = $db->prepare(
                "SELECT id FROM knowledge_items
                 WHERE id = ? AND status = 1 AND publication_status = 'published' LIMIT 1"
            );
            $stmt->execute([$knowledgeId]);
            if (!$stmt->fetchColumn()) {
                jsonResponse(1, '知识不存在');
            }
            $stmt = $db->prepare(
                'INSERT INTO knowledge_favorites (user_id, knowledge_id) VALUES (?, ?)'
            );
            try {
                $stmt->execute([$userId, $knowledgeId]);
            } catch (PDOException $e) {
                $driverCode = (int)($e->errorInfo[1] ?? 0);
                if ((string)$e->getCode() !== '23000' || $driverCode !== 1062) {
                    throw $e;
                }
            }
            jsonResponse(0, '已收藏', ['knowledge_id' => $knowledgeId, 'is_favorite' => true]);
        }

        $stmt = $db->prepare(
            'DELETE FROM knowledge_favorites WHERE user_id = ? AND knowledge_id = ?'
        );
        $stmt->execute([$userId, $knowledgeId]);
        jsonResponse(0, '已取消收藏', ['knowledge_id' => $knowledgeId, 'is_favorite' => false]);
    }

    jsonResponse(1, '不支持的请求方法');
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
