<?php
/**
 * 知识库列表API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if ($method === 'GET') {
        $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
        $type = isset($_GET['type']) ? trim($_GET['type']) : '';
        $role = isset($_GET['role']) ? trim($_GET['role']) : '';
        $stage = isset($_GET['stage']) ? trim($_GET['stage']) : '';
        $subject = isset($_GET['subject']) ? trim($_GET['subject']) : '';
        $ageGroup = isset($_GET['age_group']) ? trim($_GET['age_group']) : '';
        $trainingType = isset($_GET['training_type']) ? trim($_GET['training_type']) : '';
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $pageSize = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 20;
        $offset = ($page - 1) * $pageSize;

        $where = "WHERE k.status = 1";
        $params = [];

        if ($categoryId > 0) {
            $where .= " AND k.category_id = ?";
            $params[] = $categoryId;
        }

        if ($type) {
            $where .= " AND c.type = ?";
            $params[] = $type;
        }

        // 公共知识或按角色筛选
        if ($role) {
            $where .= " AND (k.is_public = 1 OR JSON_CONTAINS(k.target_roles, ?))";
            $params[] = json_encode($role);
        }

        if ($stage) {
            $where .= " AND (k.is_public = 1 OR JSON_CONTAINS(k.target_stages, ?))";
            $params[] = json_encode($stage);
        }

        // 扩展筛选字段
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

        // 获取列表
        $sql = "SELECT k.*, c.name as category_name, c.type as category_type,
                (SELECT is_completed FROM user_knowledge_progress WHERE user_id = ? AND knowledge_id = k.id) as is_completed,
                (SELECT score FROM user_knowledge_progress WHERE user_id = ? AND knowledge_id = k.id) as progress_score
                FROM knowledge_items k
                LEFT JOIN knowledge_categories c ON k.category_id = c.id
                $where
                ORDER BY k.is_public DESC, k.sort_order ASC, k.id DESC
                LIMIT $offset, $pageSize";

        $params = array_merge([$userId, $userId], $params);
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
            'page_size' => $pageSize
        ]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
