<?php
/**
 * 课程列表API
 * 修复：要求登录
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

function normalizeCourseCategoryName($name) {
    $name = trim((string)$name);
    $map = [
        '技术岗位' => '教练',
        '技术培训' => '教练',
        '教练岗位' => '教练',
        '教学岗位' => '教练',
        '销售岗位' => '顾问',
        '销售培训' => '顾问',
        '顾问岗位' => '顾问',
        '课程顾问' => '顾问',
        '管理岗位' => '店长',
        '管理培训' => '店长',
        '店长岗位' => '店长',
        '运营岗位' => '店长',
        '产品岗位' => '通用',
        '产品培训' => '通用',
        '品牌产品' => '通用',
        '新人培训' => '新员工',
        '新员工培训' => '新员工',
    ];
    return $map[$name] ?? $name;
}

function courseCategoryAliasesForName($name) {
    $normalized = normalizeCourseCategoryName($name);
    $groups = [
        '教练' => ['教练', '技术岗位', '技术培训', '教练岗位', '教学岗位'],
        '顾问' => ['顾问', '销售', '销售岗位', '销售培训', '顾问岗位', '课程顾问'],
        '店长' => ['店长', '管理岗位', '管理培训', '店长岗位', '运营岗位'],
        '新员工' => ['新员工', '新人培训', '新员工培训'],
        '通用' => ['通用', '公共', '基础培训', '产品岗位', '产品培训', '品牌产品'],
    ];
    return $groups[$normalized] ?? [$normalized];
}

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
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $pageSize = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10;
        $offset = ($page - 1) * $pageSize;

        $where = "WHERE c.status = 1";
        $params = [];

        if ($categoryId > 0) {
            $categoryStmt = $db->prepare("SELECT name FROM course_categories WHERE id = ? LIMIT 1");
            $categoryStmt->execute([$categoryId]);
            $categoryName = $categoryStmt->fetchColumn();
            $aliases = courseCategoryAliasesForName($categoryName ?: '');
            $placeholders = implode(',', array_fill(0, count($aliases), '?'));
            $where .= " AND c.category_id IN (SELECT id FROM course_categories WHERE name IN ($placeholders))";
            $params = array_merge($params, $aliases);
        }

        // 获取总数
        $countSql = "SELECT COUNT(*) FROM courses c $where";
        $stmt = $db->prepare($countSql);
        $stmt->execute($params);
        $total = $stmt->fetchColumn();

        // 获取列表
        $sql = "SELECT c.*, cc.name as category_name,
                (SELECT progress FROM user_course_progress WHERE user_id = ? AND course_id = c.id) as user_progress,
                (SELECT status FROM user_course_progress WHERE user_id = ? AND course_id = c.id) as user_status
                FROM courses c
                LEFT JOIN course_categories cc ON c.category_id = cc.id
                $where
                ORDER BY c.is_required DESC, c.sort_order ASC
                LIMIT $offset, $pageSize";

        $params = array_merge([$userId, $userId], $params);
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 格式化数据
        foreach ($list as &$item) {
            $item['cover_image'] = $item['cover_image'] ? getResourceUrl($item['cover_image']) : null;
            $item['category_name'] = normalizeCourseCategoryName($item['category_name'] ?? '');
            $item['is_completed'] = $item['user_status'] == 1;
            $item['is_started'] = $item['user_progress'] > 0;
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
