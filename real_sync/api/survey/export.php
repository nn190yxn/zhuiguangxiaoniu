<?php
/**
 * 问卷数据导出Excel
 * GET /api/survey/export.php?id=1&campus_id=1
 *
 * 导出所有提交记录及答案，按"一行一份提交"格式输出CSV
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

    $surveyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $campusFilter = isset($_GET['campus_id']) ? (int)$_GET['campus_id'] : 0;

    if ($surveyId <= 0) {
        jsonResponse(400, '缺少问卷ID');
    }

    // 获取问卷
    $stmt = $db->prepare("SELECT title FROM surveys WHERE id = ? LIMIT 1");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$survey) {
        jsonResponse(404, '问卷不存在');
    }

    $surveyStmt = $db->prepare('SELECT id, creator_id FROM surveys WHERE id = ? LIMIT 1');
    $surveyStmt->execute([$surveyId]);
    $surveyMeta = $surveyStmt->fetch(PDO::FETCH_ASSOC);
    if (!$surveyMeta || !canAccessSurvey($user, $surveyMeta)) {
        jsonResponse(403, '无权限导出此问卷');
    }

    $campusWhere = $campusFilter ? ' AND sub.campus_id = ?' : '';
    $campusParams = $campusFilter ? [$campusFilter] : [];

    // 获取所有问题（列头）
    $qStmt = $db->prepare("SELECT id, question_text, question_type, options FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC");
    $qStmt->execute([$surveyId]);
    $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    // 获取所有提交
    $subSql = "SELECT sub.id, sub.submitter_name, sub.submitter_phone, sub.campus_id, st.name as campus_name, sub.submitted_at
               FROM survey_submissions sub
               LEFT JOIN stores st ON sub.campus_id = st.id
               WHERE sub.survey_id = ?" . $campusWhere . "
               ORDER BY sub.submitted_at ASC";
    $subStmt = $db->prepare($subSql);
    $subStmt->execute(array_merge([$surveyId], $campusParams));
    $submissions = $subStmt->fetchAll(PDO::FETCH_ASSOC);

    // 获取所有答案
    $aSql = "SELECT a.submission_id, a.question_id, a.answer_value, a.answer_values, a.rating_score
             FROM survey_answers a
             JOIN survey_submissions sub ON a.submission_id = sub.id
             WHERE sub.survey_id = ?" . $campusWhere;
    $aStmt = $db->prepare($aSql);
    $aStmt->execute(array_merge([$surveyId], $campusParams));
    $answers = $aStmt->fetchAll(PDO::FETCH_ASSOC);

    // 按submission_id组织答案
    $answerMap = [];
    foreach ($answers as $a) {
        $answerMap[$a['submission_id']][$a['question_id']] = $a;
    }

    // 生成CSV
    $filename = "问卷导出_{$survey['title']}_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename*=UTF-8''" . urlencode($filename));
    // BOM for Excel UTF-8
    echo "\xEF\xBB\xBF";

    $output = fopen('php://output', 'w');

    // 表头
    $headers = ['序号', '提交时间', '填写人', '手机号', '校区'];
    foreach ($questions as $q) {
        $headers[] = $q['question_text'];
    }
    fputcsv($output, $headers);

    // 数据行
    $index = 1;
    foreach ($submissions as $sub) {
        $row = [
            $index++,
            $sub['submitted_at'],
            $sub['submitter_name'] ?: '(匿名)',
            $sub['submitter_phone'] ?: '',
            $sub['campus_name'] ?: '',
        ];

        foreach ($questions as $q) {
            $ans = $answerMap[$sub['id']][$q['id']] ?? null;
            if (!$ans) {
                $row[] = '';
                continue;
            }

            if ($q['question_type'] === 'checkbox') {
                $vals = json_decode($ans['answer_values'] ?? '[]', true);
                $row[] = is_array($vals) ? implode('、', $vals) : '';
            } elseif ($q['question_type'] === 'rating' || $q['question_type'] === 'nps') {
                $row[] = $ans['rating_score'] ?? '';
            } else {
                $row[] = $ans['answer_value'] ?? '';
            }
        }

        fputcsv($output, $row);
    }

    fclose($output);
    exit;

} catch (Exception $e) {
    error_log('survey/export error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
