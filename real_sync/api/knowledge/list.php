<?php
/**
 * 知识库列表API
 * 修复：1. 要求登录 2. 不返回完整 content 字段（摘要替代）
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    // 要求登录
    if (!$userId) {
        jsonResponse(401, '请先登录', null, 401);
        exit;
    }

    if ($method === 'GET') {
        $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
        $type = isset($_GET['type']) ? trim($_GET['type']) : '';
        $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
        $role = isset($_GET['role']) ? trim($_GET['role']) : '';
        $stage = isset($_GET['stage']) ? trim($_GET['stage']) : '';
        $subject = isset($_GET['subject']) ? trim($_GET['subject']) : '';
        $ageGroup = isset($_GET['age_group']) ? trim($_GET['age_group']) : '';
        $trainingType = isset($_GET['training_type']) ? trim($_GET['training_type']) : '';
        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $pageSize = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 20;
        $pageSize = max(1, min($pageSize, 50));
        $offset = ($page - 1) * $pageSize;

        if (!$role || !$stage) {
            $stmt = $db->prepare("SELECT role, stage FROM staffs WHERE user_id = ? AND status = 1 LIMIT 1");
            $stmt->execute([$userId]);
            $staff = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($staff) {
                if (!$role && !empty($staff['role'])) {
                    $role = $staff['role'];
                }
                if (!$stage && !empty($staff['stage'])) {
                    $stage = $staff['stage'];
                }
            }
        }
        $role = normalizeKnowledgeRole($role);

        $where = "WHERE k.status = 1";
        $params = [];

        if ($role && $stage) {
            $where .= " AND (k.is_public = 1 OR (JSON_CONTAINS(k.target_roles, ?) AND (k.target_stages IS NULL OR k.target_stages = '' OR (JSON_VALID(k.target_stages) AND (JSON_LENGTH(k.target_stages) = 0 OR JSON_CONTAINS(k.target_stages, ?))))))";
            $params[] = json_encode($role, JSON_UNESCAPED_UNICODE);
            $params[] = json_encode($stage, JSON_UNESCAPED_UNICODE);
        } elseif ($role) {
            $where .= " AND (k.is_public = 1 OR (JSON_CONTAINS(k.target_roles, ?) AND (k.target_stages IS NULL OR k.target_stages = '' OR (JSON_VALID(k.target_stages) AND JSON_LENGTH(k.target_stages) = 0))))";
            $params[] = json_encode($role, JSON_UNESCAPED_UNICODE);
        } elseif ($stage) {
            $where .= " AND (k.is_public = 1 OR JSON_CONTAINS(k.target_stages, ?))";
            $params[] = json_encode($stage, JSON_UNESCAPED_UNICODE);
        } else {
            $where .= " AND k.is_public = 1";
        }

        if ($categoryId > 0) {
            $where .= " AND k.category_id = ?";
            $params[] = $categoryId;
        }

        if ($type) {
            $where .= " AND c.type = ?";
            $params[] = $type;
        }

        if ($keyword) {
            $where .= " AND (k.title LIKE ? OR k.summary LIKE ? OR k.content LIKE ? OR k.tags LIKE ? OR c.name LIKE ? OR k.subject LIKE ? OR k.training_type LIKE ?)";
            $likeKeyword = '%' . $keyword . '%';
            for ($i = 0; $i < 7; $i++) {
                $params[] = $likeKeyword;
            }
        }

        if ($subject) {
            $where .= " AND k.subject = ?";
            $params[] = $subject;
        }

        if ($ageGroup) {
            $where .= " AND k.age_group = ?";
            $params[] = $ageGroup;
        }

        if ($trainingType) {
            $where .= " AND k.training_type = ?";
            $params[] = $trainingType;
        }

        // 获取总数
        $countSql = "SELECT COUNT(*) FROM knowledge_items k
                      LEFT JOIN knowledge_categories c ON k.category_id = c.id
                      $where";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // 获取列表：排除大字段 content，改用 LEFT(content, 500) 作为摘要
        $sql = "SELECT k.id, k.title, k.summary, k.media_url, k.media_type, k.category_id,
                k.is_public, k.target_roles, k.target_stages, k.tags, k.sort_order,
                k.subject, k.age_group, k.training_type, k.created_at, k.updated_at,
                k.status, LEFT(k.content, 500) as content,
                c.name as category_name, c.code as category_code, c.type as category_type,
                c.icon as category_icon, c.description as category_description,
                (SELECT is_completed FROM user_knowledge_progress WHERE user_id = ? AND knowledge_id = k.id) as is_completed,
                (SELECT score FROM user_knowledge_progress WHERE user_id = ? AND knowledge_id = k.id) as progress_score
                FROM knowledge_items k
                LEFT JOIN knowledge_categories c ON k.category_id = c.id
                $where
                ORDER BY k.is_public DESC, c.sort_order ASC, k.sort_order ASC, k.id DESC
                LIMIT ?, ?";

        $params = array_merge([$userId, $userId], $params, [$offset, $pageSize]);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($list as &$item) {
            $item['cover_image'] = $item['media_url'] && $item['media_type'] === 'image'
                ? getResourceUrl($item['media_url']) : null;
            $item['target_roles'] = $item['target_roles'] ? json_decode($item['target_roles'], true) : [];
            $item['target_stages'] = $item['target_stages'] ? json_decode($item['target_stages'], true) : [];
            $item['tags'] = $item['tags'] ? json_decode($item['tags'], true) : [];
        }

        jsonResponse(0, 'success', [
            'list' => $list,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'keyword' => $keyword
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
