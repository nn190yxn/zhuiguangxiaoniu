<?php
/**
 * 知识库详情API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if ($method === 'GET') {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        if (!$id) {
            jsonResponse(1, '缺少知识ID');
        }

        // 获取知识详情
        $sql = "SELECT k.*, c.name as category_name, c.type as category_type
                FROM knowledge_items k
                LEFT JOIN knowledge_categories c ON k.category_id = c.id
                WHERE k.id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            jsonResponse(1, '知识不存在');
        }

        // 增加浏览次数
        $updateSql = "UPDATE knowledge_items SET view_count = view_count + 1 WHERE id = ?";
        $db->prepare($updateSql)->execute([$id]);

        // 获取用户进度
        $progressSql = "SELECT * FROM user_knowledge_progress WHERE user_id = ? AND knowledge_id = ?";
        $stmt = $db->prepare($progressSql);
        $stmt->execute([$userId, $id]);
        $progress = $stmt->fetch(PDO::FETCH_ASSOC);

        // 获取相关知识
        $relatedSql = "SELECT k.id, k.title, k.summary, k.media_type, c.type as category_type
                       FROM knowledge_items k
                       LEFT JOIN knowledge_categories c ON k.category_id = c.id
                       WHERE k.id != ? AND k.category_id = ? AND k.status = 1
                       ORDER BY k.view_count DESC LIMIT 5";
        $stmt = $db->prepare($relatedSql);
        $stmt->execute([$id, $item['category_id']]);
        $related = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 获取关联的演练任务
        $drillSql = "SELECT dt.id, dt.title, dt.description, dt.role, dt.stage, dt.pass_score,
                     (SELECT status FROM user_drill_tasks WHERE template_id = dt.id AND user_id = ?) as task_status,
                     (SELECT progress FROM user_drill_tasks WHERE template_id = dt.id AND user_id = ?) as task_progress
                     FROM drill_templates dt
                     WHERE dt.knowledge_card_id = ? AND dt.status = 1
                     LIMIT 3";
        $stmt = $db->prepare($drillSql);
        $stmt->execute([$userId, $userId, $id]);
        $drills = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 获取关联的话术
        $scriptsSql = "SELECT ds.id, ds.scene, ds.content, ds.audio_url
                       FROM drill_scripts ds
                       WHERE ds.template_id IN (SELECT id FROM drill_templates WHERE knowledge_card_id = ?)
                       LIMIT 5";
        $stmt = $db->prepare($scriptsSql);
        $stmt->execute([$id]);
        $scripts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($scripts as &$script) {
            $script['audio_url'] = $script['audio_url'] ? getResourceUrl($script['audio_url']) : null;
        }

        // 格式化数据
        $item['cover_image'] = $item['media_url'] && $item['media_type'] === 'image'
            ? getResourceUrl($item['media_url']) : null;
        $item['media_url'] = $item['media_url'] ? getResourceUrl($item['media_url']) : null;
        $item['target_roles'] = $item['target_roles'] ? json_decode($item['target_roles'], true) : [];
        $item['target_stages'] = $item['target_stages'] ? json_decode($item['target_stages'], true) : [];
        $item['tags'] = $item['tags'] ? json_decode($item['tags'], true) : [];

        jsonResponse(0, 'success', [
            'item' => $item,
            'progress' => $progress ? [
                'is_completed' => (bool)$progress['is_completed'],
                'score' => (int)$progress['score'],
                'learning_time' => (int)$progress['learning_time'],
                'completed_at' => $progress['completed_at']
            ] : null,
            'related' => $related,
            'drills' => $drills,
            'scripts' => $scripts
        ]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
