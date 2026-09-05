<?php
/**
 * 知识库详情API
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/KnowledgeTaxonomy.php';
require_once __DIR__ . '/EmployeeKnowledgeVisibilityQuery.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = (int)getCurrentUserId();
    if ($userId <= 0) {
        jsonResponse(401, '请先登录', null, 401);
        exit;
    }

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
        $knowledgeSource = EmployeeKnowledgeVisibilityQuery::fromCurrentVersion();
        $sql = "SELECT k.*,
                       COALESCE(NULLIF(kv.title, ''), k.title) AS title,
                       COALESCE(NULLIF(kv.summary, ''), k.summary) AS summary,
                       COALESCE(NULLIF(kv.content, ''), k.content) AS content,
                       COALESCE(NULLIF(kv.content_type, ''), k.content_type) AS content_type,
                       COALESCE(NULLIF(kv.domain_code, ''), k.domain_code) AS domain_code,
                       COALESCE(NULLIF(kv.risk_level, ''), k.risk_level) AS risk_level,
                       COALESCE(NULLIF(kv.subject, ''), k.subject) AS subject,
                       COALESCE(NULLIF(kv.age_group, ''), k.age_group) AS age_group,
                       COALESCE(NULLIF(kv.training_type, ''), k.training_type) AS training_type,
                       COALESCE(kv.difficulty, k.difficulty) AS difficulty,
                       COALESCE(NULLIF(kv.tags_json, ''), k.tags) AS tags,
                       c.name as category_name, c.type as category_type,
                        kv.version_id AS version_id, kv.version_no, kv.created_at AS version_updated_at, kv.source_snapshot_json,
                       EXISTS (SELECT 1 FROM knowledge_favorites f
                               WHERE f.user_id = ? AND f.knowledge_id = k.id) AS is_favorite
                FROM " . $knowledgeSource . "
                LEFT JOIN knowledge_categories c ON k.category_id = c.id
                WHERE k.id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$userId, $id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$item) {
            jsonResponse(1, '知识不存在');
        }

        $item = array_merge($item, KnowledgeTaxonomy::classify($item));

        /* 员工端可见性已由 SQL 严格限制为 status=1 且 publication_status=published。 */

        if ($userId > 0) {
            $updateSql = "UPDATE knowledge_items SET view_count = view_count + 1 WHERE id = ?";
            $db->prepare($updateSql)->execute([$id]);

            $recentViewSql = "INSERT INTO knowledge_recent_views
                                (user_id, knowledge_id, view_count, first_viewed_at, last_viewed_at)
                              VALUES (?, ?, 1, NOW(), NOW())
                              ON DUPLICATE KEY UPDATE
                                view_count = view_count + 1,
                                last_viewed_at = NOW()";
            $db->prepare($recentViewSql)->execute([$userId, $id]);
        }

        // 获取用户进度
        $progressSql = "SELECT * FROM user_knowledge_progress WHERE user_id = ? AND knowledge_id = ?";
        $stmt = $db->prepare($progressSql);
        $stmt->execute([$userId, $id]);
        $progress = $stmt->fetch(PDO::FETCH_ASSOC);

        $recentViewStmt = $db->prepare(
            'SELECT view_count, first_viewed_at, last_viewed_at FROM knowledge_recent_views WHERE user_id = ? AND knowledge_id = ?'
        );
        $recentViewStmt->execute([$userId, $id]);
        $recentView = $recentViewStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        // 获取相关知识
        $relatedSql = "SELECT k.id, kv.version_id AS version_id,
                              COALESCE(NULLIF(kv.title, ''), k.title) AS title,
                              COALESCE(NULLIF(kv.summary, ''), k.summary) AS summary,
                              k.media_type,
                              COALESCE(NULLIF(kv.content_type, ''), k.content_type) AS content_type,
                              COALESCE(NULLIF(kv.domain_code, ''), k.domain_code) AS domain_code,
                              c.type as category_type
                       FROM " . $knowledgeSource . "
                       LEFT JOIN knowledge_categories c ON k.category_id = c.id
                       WHERE k.id != ? AND k.category_id = ?
                       ORDER BY k.view_count DESC LIMIT 5";
        $stmt = $db->prepare($relatedSql);
        $stmt->execute([$id, $item['category_id']]);
        $related = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 获取关联的演练任务
        $drillSql = "SELECT dt.id, dt.title, dt.description, dt.role, dt.stage, dt.pass_score,
                     (SELECT status FROM user_drill_tasks WHERE template_id = dt.id AND user_id = ?) as task_status,
                     (SELECT progress FROM user_drill_tasks WHERE template_id = dt.id AND user_id = ?) as task_progress
                     FROM drill_templates dt
                     JOIN knowledge_items linked_k ON linked_k.id = dt.knowledge_card_id
                     WHERE dt.knowledge_card_id = ? AND dt.status = 1
                       AND linked_k.status = 1 AND linked_k.publication_status = 'published'
                       AND (dt.role IS NULL OR dt.role = '' OR dt.role = ?)
                       AND (dt.stage IS NULL OR dt.stage = '' OR dt.stage = ?)
                     LIMIT 3";
        $stmt = $db->prepare($drillSql);
        $stmt->execute([$userId, $userId, $id, $role, $stage]);
        $drills = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 获取关联的话术
        $scriptsSql = "SELECT ds.id, ds.scene, ds.content, ds.audio_url
                       FROM drill_scripts ds
                       JOIN drill_templates dt ON dt.id = ds.template_id
                       JOIN knowledge_items linked_k ON linked_k.id = dt.knowledge_card_id
                       WHERE dt.knowledge_card_id = ? AND dt.status = 1
                         AND linked_k.status = 1 AND linked_k.publication_status = 'published'
                         AND (dt.role IS NULL OR dt.role = '' OR dt.role = ?)
                         AND (dt.stage IS NULL OR dt.stage = '' OR dt.stage = ?)
                       LIMIT 5";
        $stmt = $db->prepare($scriptsSql);
        $stmt->execute([$id, $role, $stage]);
        $scripts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($scripts as &$script) {
            $script['audio_url'] = $script['audio_url'] ? getKnowledgeResourceUrl($script['audio_url']) : null;
        }

        // 格式化数据
        $item['cover_image'] = $item['media_url'] && $item['media_type'] === 'image'
            ? getKnowledgeResourceUrl($item['media_url']) : null;
        $item['media_url'] = $item['media_url'] ? getKnowledgeResourceUrl($item['media_url']) : null;
        $item['target_roles'] = $item['target_roles'] ? json_decode($item['target_roles'], true) : [];
        $item['target_stages'] = $item['target_stages'] ? json_decode($item['target_stages'], true) : [];
        $item['tags'] = $item['tags'] ? json_decode($item['tags'], true) : [];
        $sourceSnapshot = !empty($item['source_snapshot_json'])
            ? (json_decode((string)$item['source_snapshot_json'], true) ?: [])
            : [];
        unset($item['source_snapshot_json']);
        $item['version_updated_at'] = $item['version_updated_at'] ?: $item['updated_at'];
        $item['display_meta'] = buildKnowledgeDisplayMeta($item);
        $item['source_summary'] = buildKnowledgeSourceSummary($sourceSnapshot);

        jsonResponse(0, 'success', [
            'item' => $item,
            'is_favorite' => (bool)$item['is_favorite'],
            'recent_view' => $recentView,
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


function buildKnowledgeDisplayMeta(array $item): array {
    $labels = [
        'content_type' => ['method' => '方法', 'principle' => '原理', 'case' => '案例', 'checklist' => '清单', 'coach_growth' => '教练成长'],
        'domain_code' => ['fitness' => '体能', 'sensory' => '感统', 'sales' => '销售', 'coach' => '教练', 'operation' => '运营', 'G01' => '儿童发展', 'G02' => '运动与大脑', 'G03' => '心理与情绪', 'G04' => '行为与课堂', 'G05' => '教学法', 'G06' => '家长协同', 'G07' => '健康边界', 'G08' => '教练成长'],
        'subject' => ['fitness' => '体能', 'sensory' => '感统', 'skill' => '技能'],
        'training_type' => ['strength' => '力量', 'cardio' => '心肺', 'flexibility' => '柔韧', 'balance' => '平衡', 'coordination' => '协调'],
        'risk_level' => ['low' => '低风险', 'medium' => '中风险', 'high' => '高风险'],
    ];
    $meta = [];
    foreach (['content_type', 'domain_code', 'subject', 'age_group', 'training_type', 'difficulty', 'risk_level'] as $field) {
        $value = $item[$field] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        $meta[$field] = [
            'value' => $value,
            'label' => $field === 'difficulty'
                ? ((int)$value . '级难度')
                : ($labels[$field][$value] ?? (string)$value),
        ];
    }
    return $meta;
}

function buildKnowledgeSourceSummary(array $snapshot): array {
    $articles = $snapshot['source_articles'] ?? [];
    $images = $snapshot['source_images'] ?? [];
    return [
        'article_count' => is_array($articles) ? count($articles) : 0,
        'image_count' => is_array($images) ? count($images) : 0,
        'card_type' => isset($snapshot['card_type']) ? (string)$snapshot['card_type'] : '',
        'source_status' => isset($snapshot['source_status']) ? (string)$snapshot['source_status'] : '',
    ];
}
