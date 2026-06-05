<?php
/**
 * 问卷详情API（小程序填写 + 内网查看）
 * GET /api/survey/detail.php?code=xxx
 *
 * 小程序端：通过 share_code 获取问卷详情
 * 内网端：通过 id 获取问卷详情（含统计数据概览）
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(400, '仅支持GET请求');
}

try {
    $db = getDB();

    $shareCode = isset($_GET['code']) ? trim($_GET['code']) : '';
    $surveyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if (empty($shareCode) && $surveyId <= 0) {
        jsonResponse(400, '缺少问卷code或id');
    }

    // 获取问卷基本信息
    $sql = "SELECT * FROM surveys WHERE 1=1";
    $params = [];
    if ($shareCode) {
        $sql .= " AND share_code = ?";
        $params[] = $shareCode;
    } else {
        $sql .= " AND id = ?";
        $params[] = $surveyId;
    }
    $sql .= " LIMIT 1";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$survey) {
        jsonResponse(404, '问卷不存在或已删除');
    }

    if ($survey['status'] === 'draft') {
        // 草稿状态，只有创建者/管理员可查看
        $user = getJwtCurrentUser();
        if (!canAccessSurvey($user, $survey)) {
            jsonResponse(403, '问卷尚未发布');
        }
    }

    // 获取校区列表
    $campusIds = $survey['campus_ids'] ? json_decode($survey['campus_ids'], true) : [];
    $campuses = [];
    if (!empty($campusIds)) {
        $placeholders = implode(',', array_fill(0, count($campusIds), '?'));
        $campusSql = "SELECT id, name FROM stores WHERE id IN ($placeholders) ORDER BY sort_order";
        $campusStmt = $db->prepare($campusSql);
        $campusStmt->execute($campusIds);
        $campuses = $campusStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 获取问题列表
    $qSql = "SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC";
    $qStmt = $db->prepare($qSql);
    $qStmt->execute([$survey['id']]);
    $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($questions as &$q) {
        $q['options'] = $q['options'] ? json_decode($q['options'], true) : [];
        $q['is_required'] = (int)$q['is_required'];
    }

    // 检查是否已提交过（小程序端）
    $alreadySubmitted = false;
    if ($shareCode) {
        // 尝试获取openid
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
            $payload = jwtDecode($matches[1]);
            if ($payload && isset($payload['user_id'])) {
                $stmt2 = $db->prepare("SELECT openid FROM staffs WHERE user_id = ? LIMIT 1");
                $stmt2->execute([(int)$payload['user_id']]);
                $row = $stmt2->fetch(PDO::FETCH_ASSOC);
                if ($row && $row['openid']) {
                    $checkSql = "SELECT COUNT(*) FROM survey_submissions WHERE survey_id = ? AND openid = ?";
                    $checkStmt = $db->prepare($checkSql);
                    $checkStmt->execute([$survey['id'], $row['openid']]);
                    $alreadySubmitted = (int)$checkStmt->fetchColumn() > 0;
                }
            }
        }
    }

    $result = [
        'survey' => [
            'id' => (int)$survey['id'],
            'title' => $survey['title'],
            'description' => $survey['description'],
            'is_anonymous' => (int)$survey['is_anonymous'],
            'require_campus' => (int)$survey['require_campus'],
            'target_audience' => $survey['target_audience'],
            'start_at' => $survey['start_at'],
            'end_at' => $survey['end_at'],
            'status' => $survey['status'],
            'campus_ids' => $campusIds,
            'campuses' => $campuses,
        ],
        'questions' => $questions,
        'already_submitted' => $alreadySubmitted
    ];

    jsonResponse(0, 'success', $result);

} catch (Exception $e) {
    error_log('survey/detail error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
