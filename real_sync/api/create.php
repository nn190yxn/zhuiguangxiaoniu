<?php
/**
 * 创建问卷API
 * POST /api/survey/create.php
 * 
 * 请求体:
 * {
 *   "title": "问卷标题",
 *   "description": "问卷说明",
 *   "campus_ids": [1,2,3],
 *   "is_anonymous": 0,
 *   "require_campus": 1,
 *   "target_audience": "parent",
 *   "start_at": "2026-05-03 00:00:00",
 *   "end_at": "2026-05-30 23:59:59",
 *   "questions": [
 *     { "section": "教学服务", "question_type": "radio", "question_text": "您对教练的教学态度是否满意？", "options": ["非常满意","满意","一般","不满意","非常不满意"], "is_required": 1, "sort_order": 1 },
 *     { "section": "教学服务", "question_type": "rating", "question_text": "请为教练专业水平打分", "is_required": 1, "sort_order": 2 },
 *     { "section": "意见建议", "question_type": "text", "question_text": "您有什么建议或意见？", "is_required": 0, "sort_order": 3 }
 *   ]
 * }
 * 
 * 返回:
 * { "code": 0, "message": "success", "data": { "id": 1, "share_code": "abc123", "share_link": "..." } }
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(400, '仅支持POST请求');
}

try {
    $db = getDB();
    $user = getJwtCurrentUser();
    if (!$user) {
        jsonResponse(401, '请先登录');
    }

    $input = getRequestInput();
    $editId = isset($input['id']) ? (int)$input['id'] : 0;
    $title = isset($input['title']) ? trim($input['title']) : '';
    if (empty($title)) {
        jsonResponse(400, '问卷标题不能为空');
    }

    $description = isset($input['description']) ? trim($input['description']) : '';
    $campusIdList = isset($input['campus_ids']) && is_array($input['campus_ids']) ? array_values(array_unique(array_map('intval', $input['campus_ids']))) : [];
    $campusIds = $campusIdList ? json_encode($campusIdList, JSON_UNESCAPED_UNICODE) : null;
    $isAnonymous = isset($input['is_anonymous']) ? (int)$input['is_anonymous'] : 0;
    $requireCampus = isset($input['require_campus']) ? (int)$input['require_campus'] : 1;
    $targetAudience = isset($input['target_audience']) ? trim($input['target_audience']) : 'parent';
    $startAt = isset($input['start_at']) ? trim($input['start_at']) : null;
    $endAt = isset($input['end_at']) ? trim($input['end_at']) : null;
    $questions = isset($input['questions']) && is_array($input['questions']) ? $input['questions'] : [];

    if (empty($questions)) {
        jsonResponse(400, '至少需要添加一个问题');
    }

    if ($requireCampus && !$campusIdList) {
        jsonResponse(400, '要求选择校区时，至少需要配置一个校区');
    }

    $db->beginTransaction();

    if ($editId > 0) {
        $stmt = $db->prepare('SELECT * FROM surveys WHERE id = ? LIMIT 1');
        $stmt->execute([$editId]);
        $existingSurvey = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingSurvey) {
            $db->rollBack();
            jsonResponse(404, '问卷不存在');
        }
        if ($existingSurvey['status'] !== 'draft') {
            $db->rollBack();
            jsonResponse(400, '只有草稿状态的问卷可以编辑');
        }
        if (!canAccessSurvey($user, $existingSurvey)) {
            $db->rollBack();
            jsonResponse(403, '无权限编辑此问卷');
        }
        $shareCode = $existingSurvey['share_code'];
    } else {
        // 生成唯一share_code
        do {
            $shareCode = bin2hex(random_bytes(4));
            $checkStmt = $db->prepare('SELECT COUNT(*) FROM surveys WHERE share_code = ?');
            $checkStmt->execute([$shareCode]);
        } while ((int)$checkStmt->fetchColumn() > 0);
    }

    if ($editId > 0) {
        $sql = "UPDATE surveys SET title = ?, description = ?, campus_ids = ?, is_anonymous = ?, require_campus = ?, target_audience = ?, start_at = ?, end_at = ?, updated_at = NOW() WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$title, $description, $campusIds, $isAnonymous, $requireCampus, $targetAudience, $startAt, $endAt, $editId]);
        $surveyId = $editId;
        $db->prepare('DELETE FROM survey_questions WHERE survey_id = ?')->execute([$surveyId]);
    } else {
        $sql = "INSERT INTO surveys (title, description, campus_ids, creator_id, is_anonymous, require_campus, target_audience, start_at, end_at, share_code)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);
        $stmt->execute([$title, $description, $campusIds, $user['staff_id'], $isAnonymous, $requireCampus, $targetAudience, $startAt, $endAt, $shareCode]);
        $surveyId = (int)$db->lastInsertId();
    }

    // 插入问题
    $qSql = "INSERT INTO survey_questions (survey_id, section, question_type, question_text, options, is_required, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)";
    $qStmt = $db->prepare($qSql);
    foreach ($questions as $q) {
        $section = isset($q['section']) ? trim($q['section']) : '';
        $questionType = isset($q['question_type']) ? trim($q['question_type']) : 'radio';
        $questionText = isset($q['question_text']) ? trim($q['question_text']) : '';
        $questionOptions = isset($q['options']) && is_array($q['options']) ? array_values(array_filter(array_map('trim', $q['options']), static function ($value) {
            return $value !== '';
        })) : [];
        $options = $questionOptions ? json_encode($questionOptions, JSON_UNESCAPED_UNICODE) : null;
        $isRequired = isset($q['is_required']) ? (int)$q['is_required'] : 1;
        $sortOrder = isset($q['sort_order']) ? (int)$q['sort_order'] : 0;

        if (empty($questionText)) {
            continue;
        }

        if (in_array($questionType, ['radio', 'checkbox'], true) && count($questionOptions) < 2) {
            $db->rollBack();
            jsonResponse(400, '单选题和多选题至少需要两个选项');
        }

        $qStmt->execute([$surveyId, $section, $questionType, $questionText, $options, $isRequired, $sortOrder]);
    }

    $db->commit();

    // 生成分享链接
    $shareLink = buildSurveyMiniProgramLink($shareCode);

    jsonResponse(0, $editId > 0 ? '问卷更新成功' : '问卷创建成功', [
        'id' => $surveyId,
        'share_code' => $shareCode,
        'share_link' => $shareLink,
        'question_count' => count($questions)
    ]);

} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    error_log('survey/create error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
