<?php
/**
 * 问卷列表API
 * GET /api/survey/list.php
 *
 * 参数:
 *   status - 筛选状态 draft/active/closed（可选）
 *   page - 页码（默认1）
 *   page_size - 每页数量（默认20）
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(400, '仅支持GET请求');
}

try {
    $db = getDB();
    $user = getJwtCurrentUser();
    if (!$user) {
        jsonResponse(401, '请先登录');
    }

    $status = isset($_GET['status']) ? trim($_GET['status']) : '';
    $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
    $pageSize = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 20;
    $pageSize = max(1, min($pageSize, 100));
    $offset = ($page - 1) * $pageSize;

    $where = "WHERE 1=1";
    $params = [];

    if (($user['role'] ?? '') !== 'admin') {
        $where .= " AND s.creator_id = ?";
        $params[] = (int)($user['staff_id'] ?? 0);
    }

    if ($status) {
        $where .= " AND s.status = ?";
        $params[] = $status;
    }

    // 获取总数
    $countSql = "SELECT COUNT(*) FROM surveys s $where";
    $stmt = $db->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // 获取列表
    $sql = "SELECT s.*,
            (SELECT COUNT(*) FROM survey_submissions WHERE survey_id = s.id) as submission_count,
            (SELECT COUNT(DISTINCT campus_id) FROM survey_submissions WHERE survey_id = s.id AND campus_id IS NOT NULL) as campus_count,
            (SELECT COUNT(*) FROM survey_questions WHERE survey_id = s.id) as question_count
            FROM surveys s
            $where
            ORDER BY s.created_at DESC
            LIMIT ?, ?";
    $stmt = $db->prepare($sql);
    $stmt->execute(array_merge($params, [$offset, $pageSize]));
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 格式化数据
    foreach ($list as &$item) {
        $item['is_anonymous'] = (int)$item['is_anonymous'];
        $item['campus_ids'] = $item['campus_ids'] ? json_decode($item['campus_ids'], true) : [];
        $item['share_link'] = buildSurveyMiniProgramLink($item['share_code']);
        $item['created_at'] = $item['created_at'] ? date('Y-m-d H:i', strtotime($item['created_at'])) : '';
        $item['updated_at'] = $item['updated_at'] ? date('Y-m-d H:i', strtotime($item['updated_at'])) : '';
    }

    jsonResponse(0, 'success', [
        'list' => $list,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize
    ]);

} catch (Exception $e) {
    error_log('survey/list error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
