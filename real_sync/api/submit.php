<?php
/**
 * 提交问卷答案API
 * POST /api/survey/submit.php
 * 
 * 请求体:
 * {
 *   "code": "问卷share_code",
 *   "campus_id": 1,
 *   "submitter_name": "张三",
 *   "submitter_phone": "13800138000",
 *   "answers": [
 *     { "question_id": 1, "answer_value": "非常满意" },
 *     { "question_id": 2, "rating_score": 5 },
 *     { "question_id": 3, "answer_value": "希望能增加周末课程" }
 *   ]
 * }
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
// Auth check
$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(401, '请先登录');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(400, '仅支持POST请求');
}

try {
    $db = getDB();
    $input = getRequestInput();

    $shareCode = isset($input['code']) ? trim($input['code']) : '';
    $campusId = isset($input['campus_id']) ? (int)$input['campus_id'] : 0;
    $submitterName = isset($input['submitter_name']) ? trim($input['submitter_name']) : '';
    $submitterPhone = isset($input['submitter_phone']) ? trim($input['submitter_phone']) : '';
    $answers = isset($input['answers']) && is_array($input['answers']) ? $input['answers'] : [];

    if (empty($shareCode)) {
        jsonResponse(400, '缺少问卷code');
    }
    if (empty($answers)) {
        jsonResponse(400, '请至少回答一个问题');
    }

    // 获取问卷
    $stmt = $db->prepare("SELECT * FROM surveys WHERE share_code = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$shareCode]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$survey) {
        jsonResponse(404, '问卷不存在或未发布');
    }

    // 检查截止时间
    if ($survey['end_at'] && strtotime($survey['end_at']) < time()) {
        jsonResponse(400, '问卷已截止');
    }

    // 检查校区是否匹配
    if ($survey['campus_ids']) {
        $campusIds = json_decode($survey['campus_ids'], true);
        if ($campusId > 0 && !in_array($campusId, $campusIds)) {
            jsonResponse(400, '您选择的校区不在本问卷范围内');
        }
    }

    // 检查是否必须选校区
    if ($survey['require_campus'] && $campusId <= 0) {
        jsonResponse(400, '请选择所属校区');
    }

    // 获取openid（防重复）
    $openid = null;
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(.+)/i', $authHeader, $matches)) {
        $payload = jwtDecode($matches[1]);
        if ($payload && isset($payload['user_id'])) {
            $stmt2 = $db->prepare("SELECT openid FROM staffs WHERE user_id = ? LIMIT 1");
            $stmt2->execute([(int)$payload['user_id']]);
            $row = $stmt2->fetch(PDO::FETCH_ASSOC);
            if ($row) $openid = $row['openid'];
        }
    }

    $qStmt = $db->prepare("SELECT id, question_type, is_required FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC");
    $qStmt->execute([$survey['id']]);
    $allQuestions = $qStmt->fetchAll(PDO::FETCH_ASSOC);
    $questionMap = [];
    foreach ($allQuestions as $q) {
        $questionMap[(int)$q['id']] = [
            'type' => $q['question_type'],
            'is_required' => (int)$q['is_required'] === 1,
        ];
    }

    $answerMap = [];
    foreach ($answers as $a) {
        $questionId = isset($a['question_id']) ? (int)$a['question_id'] : 0;
        if ($questionId <= 0 || !isset($questionMap[$questionId])) {
            jsonResponse(400, '存在无效的问题答案');
        }
        $answerMap[$questionId] = $a;
    }

    foreach ($questionMap as $questionId => $questionMeta) {
        if (!$questionMeta['is_required']) {
            continue;
        }
        if (!isset($answerMap[$questionId])) {
            jsonResponse(400, '存在必答题未填写');
        }
        $item = $answerMap[$questionId];
        $questionType = $questionMeta['type'];
        if ($questionType === 'checkbox') {
            $values = isset($item['answer_values']) && is_array($item['answer_values']) ? array_values(array_filter(array_map('trim', $item['answer_values']), static function ($value) {
                return $value !== '';
            })) : [];
            if (!$values) {
                jsonResponse(400, '存在必答题未填写');
            }
        } elseif ($questionType === 'rating' || $questionType === 'nps') {
            if (!isset($item['rating_score']) || $item['rating_score'] === '') {
                jsonResponse(400, '存在必答题未填写');
            }
        } else {
            $value = isset($item['answer_value']) ? trim((string)$item['answer_value']) : '';
            if ($value === '') {
                jsonResponse(400, '存在必答题未填写');
            }
        }
    }

    $lockKey = '';
    if (!$survey['is_anonymous'] && $openid) {
        $lockKey = sprintf('survey_submit_%d_%s', (int)$survey['id'], md5($openid));
        $lockStmt = $db->prepare('SELECT GET_LOCK(?, 5)');
        $lockStmt->execute([$lockKey]);
        if ((int)$lockStmt->fetchColumn() !== 1) {
            jsonResponse(1, '提交繁忙，请稍后重试');
        }
    }

    $db->beginTransaction();

    // 防重复提交（实名模式）
    if (!$survey['is_anonymous'] && $openid) {
        $checkSql = "SELECT COUNT(*) FROM survey_submissions WHERE survey_id = ? AND openid = ?";
        $checkStmt = $db->prepare($checkSql);
        $checkStmt->execute([$survey['id'], $openid]);
        if ((int)$checkStmt->fetchColumn() > 0) {
            $db->rollBack();
            if ($lockKey !== '') {
                $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockKey]);
            }
            jsonResponse(400, '您已经提交过了');
        }
    }

    // IP哈希（支持代理，使用SHA-256）
    $realIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (strpos($realIp, ',') !== false) {
        $realIp = trim(explode(',', $realIp)[0]);
    }
    $ipHash = hash('sha256', $realIp);

    // 插入提交记录
    $subSql = "INSERT INTO survey_submissions (survey_id, submitter_name, submitter_phone, campus_id, submit_source, openid, ip_hash)
               VALUES (?, ?, ?, ?, 'miniprogram', ?, ?)";
    $subStmt = $db->prepare($subSql);
    $subStmt->execute([$survey['id'], $submitterName, $submitterPhone, $campusId, $openid, $ipHash]);
    $submissionId = (int)$db->lastInsertId();

    // 插入答案
    $aSql = "INSERT INTO survey_answers (submission_id, question_id, answer_value, answer_values, rating_score)
             VALUES (?, ?, ?, ?, ?)";
    $aStmt = $db->prepare($aSql);

    foreach ($answerMap as $questionId => $a) {
        $answerValue = isset($a['answer_value']) ? trim((string)$a['answer_value']) : '';
        $ratingScore = isset($a['rating_score']) && $a['rating_score'] !== '' ? (int)$a['rating_score'] : null;
        $questionType = $questionMap[$questionId]['type'];
        $answerValues = null;

        // 多选题处理
        if ($questionType === 'checkbox' && isset($a['answer_values']) && is_array($a['answer_values'])) {
            $answerValues = json_encode(array_values(array_filter(array_map('trim', $a['answer_values']), static function ($value) {
                return $value !== '';
            })), JSON_UNESCAPED_UNICODE);
        }

        if ($questionType === 'rating') {
            if ($ratingScore === null || $ratingScore < 1 || $ratingScore > 5) {
                $db->rollBack();
                if ($lockKey !== '') {
                    $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockKey]);
                }
                jsonResponse(400, '评分题答案无效');
            }
        }

        if ($questionType === 'nps') {
            if ($ratingScore === null || $ratingScore < 0 || $ratingScore > 10) {
                $db->rollBack();
                if ($lockKey !== '') {
                    $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockKey]);
                }
                jsonResponse(400, 'NPS题答案无效');
            }
        }

        $aStmt->execute([$submissionId, $questionId, $answerValue, $answerValues, $ratingScore]);
    }

    $db->commit();
    if ($lockKey !== '') {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockKey]);
    }

    jsonResponse(0, '提交成功', [
        'submission_id' => $submissionId
    ]);

} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('survey/submit error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
