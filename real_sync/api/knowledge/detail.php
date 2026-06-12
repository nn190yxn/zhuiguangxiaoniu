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

        // 获取当前用户角色和阶段（用于权限过滤）
        $role = '';
        $stage = '';
        if ($userId > 0) {
            $stmt = $db->prepare("SELECT role, stage FROM staffs WHERE user_id = ? AND status = 1 LIMIT 1");
            $stmt->execute([$userId]);
            $staff = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($staff) {
                $role = normalizeKnowledgeRole((string)($staff['role'] ?? ''));
                $stage = (string)($staff['stage'] ?? '');
            }
        }

        // 获取知识详情
        $sql = "SELECT k.*, c.name as category_name, c.type as category_type
                FROM knowledge_items k
                LEFT JOIN knowledge_categories c ON k.category_id = c.id
                WHERE k.id = ? AND k.status = 1";
        $stmt = $db->prepare($sql);
        $stmt->execute([$id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            jsonResponse(1, '知识不存在');
        }

        // 权限控制：非公共内容必须命中目标角色/阶段
        $isPublic = (int)($item['is_public'] ?? 0) === 1;
        if (!$isPublic) {
            $targetRoles = $item['target_roles'] ? json_decode($item['target_roles'], true) : [];
            $targetStages = $item['target_stages'] ? json_decode($item['target_stages'], true) : [];
            $roleAllowed = $role && is_array($targetRoles) && in_array($role, $targetRoles, true);
            $stageAllowed = !is_array($targetStages) || count($targetStages) === 0 || ($stage && in_array($stage, $targetStages, true));
            if (!$roleAllowed || !$stageAllowed) {
                jsonResponse(1, '无权访问该知识内容');
            }
        }

        if ($userId > 0) {
            $updateSql = "UPDATE knowledge_items SET view_count = view_count + 1 WHERE id = ?";
            $db->prepare($updateSql)->execute([$id]);
        }

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

function normalizeKnowledgeRole(string $role): string {
    $role = trim($role);
    if ($role === '') {
        return '';
    }
    if (function_exists('normalizeStaffRoleCode')) {
        $normalized = normalizeStaffRoleCode($role);
        if (is_string($normalized) && $normalized !== '') {
            return $normalized;
        }
    }
    $map = [
        'consultant' => 'sales',
        'sale' => 'sales',
        '销售' => 'sales',
        '实习销售' => 'sales',
        '教练' => 'coach',
        '实习教练' => 'coach',
        '店长' => 'manager',
        '总部运营' => 'operation',
        '运营' => 'operation',
        '财务' => 'finance',
        '总经理' => 'ceo',
    ];
    return $map[$role] ?? strtolower($role);
}
