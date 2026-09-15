<?php
/**
 * 演练任务详情API
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
            jsonResponse(1, '缺少演练任务ID');
        }

        // 获取演练模板
        $sql = "SELECT t.*,
                (SELECT title FROM knowledge_items WHERE id = t.knowledge_card_id) as knowledge_card_title
                FROM drill_templates t WHERE t.id = ? AND t.status = 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$id]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$template) {
            jsonResponse(1, '演练任务不存在');
        }

        // 获取用户任务进度
        $taskSql = "SELECT * FROM user_drill_tasks WHERE template_id = ? AND user_id = ?";
        $stmt = $db->prepare($taskSql);
        $stmt->execute([$id, $userId]);
        $task = $stmt->fetch(PDO::FETCH_ASSOC);

        // 获取知识卡内容
        $knowledgeCard = null;
        if ($template['knowledge_card_id']) {
            $knowledgeSql = "SELECT id, title, summary, content, media_url, media_type FROM knowledge_items WHERE id = ?";
            $stmt = $db->prepare($knowledgeSql);
            $stmt->execute([$template['knowledge_card_id']]);
            $knowledgeCard = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($knowledgeCard) {
                $knowledgeCard['media_url'] = $knowledgeCard['media_url'] ? getResourceUrl($knowledgeCard['media_url']) : null;
            }
        }

        // 获取话术
        $scriptsSql = "SELECT * FROM drill_scripts WHERE template_id = ? ORDER BY sort_order ASC";
        $stmt = $db->prepare($scriptsSql);
        $stmt->execute([$id]);
        $scripts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($scripts as &$script) {
            $script['audio_url'] = $script['audio_url'] ? getResourceUrl($script['audio_url']) : null;
            $script['video_url'] = $script['video_url'] ? getResourceUrl($script['video_url']) : null;
        }

        // 构建步骤状态
        $steps = [];
        $stepNames = $template['step_names'] ? json_decode($template['step_names'], true) : [
            1 => '学习知识',
            2 => '背诵话术',
            3 => '模拟演练',
            4 => '通关认证'
        ];

        $stepStatus = $task && $task['step_status'] ? json_decode($task['step_status'], true) : [];
        $stepScores = $task && $task['step_scores'] ? json_decode($task['step_scores'], true) : [];

        for ($i = 1; $i <= 4; $i++) {
            $steps[] = [
                'step' => $i,
                'name' => $stepNames[$i] ?? "步骤$i",
                'status' => $stepStatus[$i] ?? 'pending',
                'score' => $stepScores[$i] ?? null
            ];
        }

        // 如果没有任务，自动创建
        if (!$task) {
            $insertSql = "INSERT INTO user_drill_tasks (template_id, user_id, status, current_step, started_at)
                          VALUES (?, ?, 'pending', 1, NOW())";
            $db->prepare($insertSql)->execute([$id, $userId]);
            $taskId = $db->lastInsertId();
            $task = [
                'id' => $taskId,
                'status' => 'pending',
                'current_step' => 1,
                'progress' => 0,
                'attempts_count' => 0
            ];
        }

        jsonResponse(0, 'success', [
            'template' => [
                'id' => $template['id'],
                'title' => $template['title'],
                'description' => $template['description'],
                'role' => $template['role'],
                'stage' => $template['stage'],
                'pass_score' => $template['pass_score'],
                'points_reward' => $template['points_reward'],
                'is_required' => (bool)$template['is_required'],
                'steps' => $steps
            ],
            'knowledge_card' => $knowledgeCard,
            'scripts' => $scripts,
            'task' => [
                'id' => (int)$task['id'],
                'status' => $task['status'],
                'current_step' => (int)($task['current_step'] ?: 1),
                'progress' => (float)($task['progress'] ?: 0),
                'best_score' => (int)($task['best_score'] ?: 0),
                'attempts_count' => (int)($task['attempts_count'] ?: 0),
                'started_at' => $task['started_at'],
                'completed_at' => $task['completed_at']
            ]
        ]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
