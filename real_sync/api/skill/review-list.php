<?php
/**
 * 复盘记录列表/详情 API
 * GET /api/skill/review-list.php?page=1&page_size=20&scene_type=new_sale
 * GET /api/skill/review-list.php?record_id=123
 */

require_once __DIR__ . '/../../api/config.php';
handleCORS();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(405, '只支持 GET 请求');
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(401, '请先登录');
}

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    // 单条详情查询（小程序轮询用）
    $recordId = isset($_GET['record_id']) ? (int)$_GET['record_id'] : 0;
    if ($recordId > 0) {
        $stmt = $pdo->prepare("SELECT id, scene_type, recording_url, transcript_text, ai_report, ai_score, ai_level, status, error_message, created_at
            FROM skill_review_records
            WHERE id = ? AND user_id = ?");
        $stmt->execute([$recordId, $userId]);
        $record = $stmt->fetch();

        if (!$record) {
            jsonResponse(404, '记录不存在');
        }

        jsonResponse(0, 'success', ['record' => $record]);
    }

    // 列表查询
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
    $pageSize = isset($_GET['page_size']) ? min(50, max(1, (int)$_GET['page_size'])) : 20;
    $sceneType = isset($_GET['scene_type']) ? trim($_GET['scene_type']) : '';

    // 白名单校验
    $allowedScenes = ['new_sale', 'renewal', 'assessment'];
    if ($sceneType !== '' && !in_array($sceneType, $allowedScenes, true)) {
        jsonResponse(400, '无效的场景类型');
    }

    $where = "WHERE user_id = ?";
    $params = [$userId];

    if ($sceneType) {
        $where .= " AND scene_type = ?";
        $params[] = $sceneType;
    }

    // 总数
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM skill_review_records $where");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    // 列表（不返回 transcript_text 和 ai_report 大字段）
    $offset = ($page - 1) * $pageSize;
    $sql = "SELECT id, scene_type, recording_url, ai_score, ai_level, status, error_message, created_at
        FROM skill_review_records $where
        ORDER BY created_at DESC
        LIMIT $offset, $pageSize";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll();

    jsonResponse(0, 'success', [
        'records' => $records,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize,
    ]);

} catch (Exception $e) {
    error_log('[skill.list] Error: ' . $e->getMessage());
    jsonResponse(500, '查询失败');
}
