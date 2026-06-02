<?php
/**
 * 全局搜索API
 * GET /api/search/global.php?q=关键词&type=全部|知识|课程|员工|演练|培训
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
handleCORS();

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    // 要求登录
    if (!$userId) {
        jsonResponse(401, '请先登录', null, 401);
        exit;
    }

    if ($method !== 'GET') {
        jsonResponse(405, '不支持的请求方法');
        exit;
    }

    $query = trim($_GET['q'] ?? '');
    $type = trim($_GET['type'] ?? 'all');

    if (strlen($query) < 1) {
        jsonResponse(400, '请输入搜索关键词');
        exit;
    }

    // 最多返回 20 个字符的关键词，防止 SQL 注入
    $query = mb_substr($query, 0, 20);
    $likeQuery = '%' . $query . '%';

    $result = [];

    // === 1. 知识库搜索 ===
    if ($type === 'all' || $type === '知识') {
        $sql = "SELECT id, title, summary, category_id, tags, is_public, created_at
                FROM knowledge_items
                WHERE status = 1 AND (title LIKE ? OR summary LIKE ? OR tags LIKE ?)
                ORDER BY created_at DESC
                LIMIT 8";
        $stmt = $db->prepare($sql);
        $stmt->execute([$likeQuery, $likeQuery, $likeQuery]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 获取分类名称
        if ($items) {
            $catIds = array_unique(array_map(fn($i) => (int)$i['category_id'], $items));
            $catMap = [];
            if ($catIds) {
                $placeholders = implode(',', array_fill(0, count($catIds), '?'));
                $catStmt = $db->prepare("SELECT id, name FROM knowledge_categories WHERE id IN ($placeholders)");
                $catStmt->execute($catIds);
                while ($row = $catStmt->fetch(PDO::FETCH_ASSOC)) {
                    $catMap[$row['id']] = $row['name'];
                }
            }

            $result['knowledge'] = array_map(function ($item) use ($catMap) {
                return [
                    'id' => (int)$item['id'],
                    'title' => $item['title'],
                    'summary' => mb_substr($item['summary'] ?? '', 0, 100),
                    'category' => $catMap[$item['category_id']] ?? '',
                    'url' => '/知识库/viewer.html?id=' . $item['id'],
                    'type_label' => '知识',
                ];
            }, $items);
        } else {
            $result['knowledge'] = [];
        }
    }

    // === 2. 课程搜索 ===
    if ($type === 'all' || $type === '课程') {
        $sql = "SELECT c.id, c.title, c.description, cc.name as category_name
                FROM courses c
                LEFT JOIN course_categories cc ON cc.id = c.category_id
                WHERE c.status = 1 AND (c.title LIKE ? OR c.description LIKE ?)
                ORDER BY c.sort_order ASC, c.created_at DESC
                LIMIT 6";
        $stmt = $db->prepare($sql);
        $stmt->execute([$likeQuery, $likeQuery]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result['courses'] = array_map(function ($item) {
            return [
                'id' => (int)$item['id'],
                'title' => $item['title'],
                'description' => mb_substr($item['description'] ?? '', 0, 100),
                'category' => $item['category_name'] ?? '',
                'url' => '/mobile/learning.html',
                'type_label' => '课程',
            ];
        }, $items);
    }

    // === 3. 员工搜索 ===
    if ($type === 'all' || $type === '员工') {
        $sql = "SELECT s.id, s.name, s.phone, s.employee_no, s.role, s.job_title, s.store_id,
                       st.name as store_name
                FROM staffs s
                LEFT JOIN stores st ON st.id = s.store_id
                WHERE s.status = 1 AND (s.name LIKE ? OR s.phone LIKE ? OR s.employee_no LIKE ?)
                ORDER BY s.name ASC
                LIMIT 10";
        $stmt = $db->prepare($sql);
        $stmt->execute([$likeQuery, $likeQuery, $likeQuery]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result['staffs'] = array_map(function ($item) {
            return [
                'id' => (int)$item['id'],
                'name' => $item['name'],
                'phone' => maskPhone($item['phone']),
                'role' => $item['role'],
                'job_title' => $item['job_title'],
                'store' => $item['store_name'] ?? '',
                'url' => null,
                'type_label' => '员工',
            ];
        }, $items);
    }

    // === 4. 演练模板搜索 ===
    if ($type === 'all' || $type === '演练') {
        $sql = "SELECT id, title, description, role, stage
                FROM drill_templates
                WHERE status = 1 AND (title LIKE ? OR description LIKE ?)
                ORDER BY created_at DESC
                LIMIT 5";
        $stmt = $db->prepare($sql);
        $stmt->execute([$likeQuery, $likeQuery]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result['drills'] = array_map(function ($item) {
            return [
                'id' => (int)$item['id'],
                'title' => $item['title'],
                'description' => mb_substr($item['description'] ?? '', 0, 100),
                'role' => $item['role'],
                'url' => '/mobile/drill.html',
                'type_label' => '演练',
            ];
        }, $items);
    }

    // === 5. 培训模块/卡片搜索 ===
    if ($type === 'all' || $type === '培训') {
        $items = [];

        // 培训模块
        $sql = "SELECT id, module_name as title, description, role_code, category
                FROM training_modules
                WHERE status = 1 AND (module_name LIKE ? OR description LIKE ?)
                ORDER BY sort_order ASC
                LIMIT 3";
        $stmt = $db->prepare($sql);
        $stmt->execute([$likeQuery, $likeQuery]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['type_label'] = '培训模块';
            $row['url'] = '/training-pass.html';
            $items[] = $row;
        }

        // 培训卡片
        $sql = "SELECT id, title, content, module_id, card_type
                FROM training_cards
                WHERE status = 1 AND (title LIKE ? OR content LIKE ?)
                ORDER BY sort_order ASC
                LIMIT 3";
        $stmt = $db->prepare($sql);
        $stmt->execute([$likeQuery, $likeQuery]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['description'] = mb_substr($row['content'] ?? '', 0, 100);
            unset($row['content']);
            $row['type_label'] = '培训卡片';
            $row['url'] = '/training-card.html';
            $items[] = $row;
        }

        $result['training'] = $items;
    }

    // === 6. 考试搜索 ===
    if ($type === 'all' || $type === '考试') {
        $sql = "SELECT id, title, description, exam_type, course_id
                FROM exams
                WHERE is_active = 1 AND (title LIKE ? OR description LIKE ?)
                ORDER BY created_at DESC
                LIMIT 5";
        $stmt = $db->prepare($sql);
        $stmt->execute([$likeQuery, $likeQuery]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result['exams'] = array_map(function ($item) {
            return [
                'id' => (int)$item['id'],
                'title' => $item['title'],
                'description' => mb_substr($item['description'] ?? '', 0, 100),
                'url' => '/mobile/exam.html',
                'type_label' => '考试',
            ];
        }, $items);
    }

    // 统计总数
    $totalCount = 0;
    foreach ($result as $cat => $items) {
        $totalCount += count($items);
    }

    jsonResponse(0, 'success', [
        'query' => $query,
        'total' => $totalCount,
        'results' => $result,
    ]);

} catch (Exception $e) {
    error_log('[search.global] ' . $e->getMessage());
    jsonResponse(1, '搜索失败: ' . $e->getMessage());
}

function maskPhone($phone) {
    if (strlen($phone) >= 7) {
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }
    return $phone;
}
