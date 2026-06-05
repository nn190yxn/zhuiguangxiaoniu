<?php
/**
 * 全局搜索API v4 - 修复：搜索结果直达具体详情
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');
handleCORS();

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

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

    $query = mb_substr($query, 0, 20);
    $likeQuery = '%' . $query . '%';

    $result = [];

    // === 1. 知识库搜索 ===
    if ($type === 'all' || $type === '知识') {
        $sql = "SELECT id, title, summary, category_id, tags
                FROM knowledge_items
                WHERE status = 1 AND (title LIKE ? OR summary LIKE ? OR tags LIKE ?)
                ORDER BY created_at DESC
                LIMIT 8";
        $stmt = $db->prepare($sql);
        $stmt->execute([$likeQuery, $likeQuery, $likeQuery]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
                    // 知识库详情页使用 viewer.html，目前不支持直接按 id 跳转，暂时链接到知识库首页
                    'url' => '/知识库/',
                    'type_label' => '知识',
                ];
            }, $items);
        } else {
            $result['knowledge'] = [];
        }
    }

    // === 2. 制度标准搜索 ===
    if ($type === 'all' || $type === '制度') {
        $sql = "SELECT id, title, doc_key, content
                FROM policies
                WHERE title LIKE ? OR content LIKE ?
                ORDER BY updated_at DESC
                LIMIT 8";
        $stmt = $db->prepare($sql);
        $stmt->execute([$likeQuery, $likeQuery]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result['policies'] = array_map(function ($item) use ($query) {
            $text = preg_replace('/[#\|`*\[\]>-]/', ' ', $item['content'] ?? '');
            $pos = stripos($text, $query);
            if ($pos !== false) {
                $start = max(0, $pos - 50);
                $summary = mb_substr($text, $start, 150);
            } else {
                $summary = mb_substr($text, 0, 100);
            }
            // 制度列表页
            return [
                'id' => (int)$item['id'],
                'title' => $item['title'],
                'summary' => $summary,
                'doc_key' => $item['doc_key'],
                'url' => '/制度标准/',
                'type_label' => '制度',
            ];
        }, $items);
    }

    // === 3. 课程搜索 ===
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
                // 直达课程详情页
                'url' => '/mobile/course.html?id=' . $item['id'],
                'type_label' => '课程',
            ];
        }, $items);
    }

    // === 4. 员工搜索 ===
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

    // === 5. 演练模板搜索 ===
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
                // 演练列表页
                'url' => '/mobile/drill.html',
                'type_label' => '演练',
            ];
        }, $items);
    }

    // === 6. 培训模块/卡片搜索 ===
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
            // 直达模块详情页
            $row['url'] = '/training-module.html?id=' . $row['id'];
            unset($row['role_code']);
            unset($row['category']);
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
            // 直达卡片详情页
            $row['url'] = '/training-card.html?id=' . $row['id'];
            unset($row['module_id']);
            $items[] = $row;
        }

        $result['training'] = $items;
    }

    // === 7. 考试搜索 ===
    if ($type === 'all' || $type === '考试') {
        $sql = "SELECT id, title, description, exam_type
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
                // 直达考试详情页
                'url' => '/mobile/exam.html?id=' . $item['id'],
                'type_label' => '考试',
            ];
        }, $items);
    }

    // === 8. 话术知识搜索 ===
    if ($type === 'all' || $type === '话术') {
        $sql = "SELECT id, scene_code, scene_name, standard_script
                FROM script_knowledge
                WHERE scene_name LIKE ? OR standard_script LIKE ?
                ORDER BY id DESC
                LIMIT 8";
        $stmt = $db->prepare($sql);
        $stmt->execute([$likeQuery, $likeQuery]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result['scripts'] = array_map(function ($item) use ($query) {
            $text = $item['standard_script'] ?? '';
            $pos = mb_stripos($text, $query);
            if ($pos !== false) {
                $start = max(0, $pos - 50);
                $summary = mb_substr($text, $start, 200);
            } else {
                $summary = mb_substr($text, 0, 100);
            }
            return [
                'id' => (int)$item['id'],
                'title' => $item['scene_name'],
                'summary' => $summary,
                'scene_code' => $item['scene_code'],
                // 话术列表页
                'url' => '/mobile/drill.html',
                'type_label' => '话术',
            ];
        }, $items);
    }

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
