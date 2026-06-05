<?php
/**
 * 制度搜索API
 * GET/POST /api/policy/search.php
 *
 * 参数:
 *   keyword    - 搜索关键词
 *   category   - 按分类筛选（店长/教练/顾问/通用）
 *   workflow   - 按工作流筛选
 *   page       - 页码（默认1）
 *   page_size  - 每页数量（默认20）
 *   need_confirm - 是否需要确认阅读（0/1）
 *
 * 返回: JSON
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../config.php';
handleCORS();
// Auth check
$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(401, '请先登录');
}

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$db->set_charset('utf8mb4');

$keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
$category = isset($_GET['category']) ? trim($_GET['category']) : '';
$workflow = isset($_GET['workflow']) ? trim($_GET['workflow']) : '';
$need_confirm = isset($_GET['need_confirm']) ? (int)$_GET['need_confirm'] : -1;
$page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
$page_size = min(50, max(1, isset($_GET['page_size']) ? (int)$_GET['page_size'] : 20));
$offset = ($page - 1) * $page_size;

$where = ['1=1'];
$params = [];
$types = '';

if ($keyword !== '') {
    $where[] = "MATCH(title, content, keywords) AGAINST(? IN NATURAL LANGUAGE MODE)";
    $params[] = $keyword;
    $types .= 's';
}

if ($category !== '') {
    $where[] = "category = ?";
    $params[] = $category;
    $types .= 's';
}

if ($workflow !== '') {
    $where[] = "workflow = ?";
    $params[] = $workflow;
    $types .= 's';
}

if ($need_confirm >= 0) {
    $where[] = "is_need_confirm = ?";
    $params[] = $need_confirm;
    $types .= 'i';
}

$where_sql = implode(' AND ', $where);

// 获取总数
$count_sql = "SELECT COUNT(*) as total FROM policies WHERE $where_sql";
$stmt = $db->prepare($count_sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$total = $stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// 获取列表
$list_sql = "SELECT id, title, doc_key, category, workflow, keywords, version, is_need_confirm, updated_at
             FROM policies WHERE $where_sql
             ORDER BY updated_at DESC LIMIT ? OFFSET ?";

$params[] = $page_size;
$params[] = $offset;
$types .= 'ii';

$stmt = $db->prepare($list_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$policies = [];
while ($row = $result->fetch_assoc()) {
    $row['updated_at'] = date('Y-m-d H:i', strtotime($row['updated_at']));
    $policies[] = $row;
}
$stmt->close();
$db->close();

echo json_encode([
    'code' => 0,
    'message' => 'success',
    'data' => [
        'list' => $policies,
        'pagination' => [
            'page' => $page,
            'page_size' => $page_size,
            'total' => (int)$total,
            'total_pages' => ceil($total / $page_size)
        ]
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);