<?php
/**
 * 课程分类API
 * 统一为追光小牛业务岗位口径，避免展示“技术岗位”等通用模板分类。
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

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

try {
    $userId = getCurrentUserId();
    if (!$userId) {
        jsonResponse(401, '请先登录', null, 401);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(1, '不支持的请求方法');
        exit;
    }

    $db = getDB();
    $stmt = $db->query("
        SELECT cc.id, cc.name, COUNT(c.id) AS course_count
        FROM course_categories cc
        INNER JOIN courses c ON c.category_id = cc.id AND c.status = 1
        GROUP BY cc.id, cc.name
        ORDER BY cc.sort_order ASC, cc.id ASC
    ");

    $list = [];
    $seen = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $name = normalizeCourseCategoryName($row['name'] ?? '');
        if ($name === '') {
            continue;
        }
        if (isset($seen[$name])) {
            $list[$seen[$name]]['course_count'] += (int)$row['course_count'];
            continue;
        }
        $seen[$name] = count($list);
        $row['name'] = $name;
        $row['course_count'] = (int)$row['course_count'];
        $list[] = $row;
    }

    jsonResponse(0, 'success', ['list' => $list]);
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
