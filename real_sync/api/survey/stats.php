<?php
/**
 * 问卷统计API
 * GET /api/survey/stats.php?id=1&campus_id=1
 * 
 * 返回：
 * - 各校区提交数
 * - 各题目统计（选项分布、平均分、文字回答列表）
 * - 时间趋势
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

    // 获取问卷信息
    $stmt = $db->prepare("SELECT * FROM surveys WHERE id = ? LIMIT 1");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$survey) {
        jsonResponse(404, '问卷不存在');
    }
    if (!canAccessSurvey($user, $survey)) {
        jsonResponse(403, '无权限查看此问卷');
    }

    $campusWhere = $campusFilter ? ' AND sub.campus_id = ?' : '';
    $campusParams = $campusFilter ? [$campusFilter] : [];

    // 总提交数
    $stmt = $db->prepare("SELECT COUNT(*) FROM survey_submissions sub WHERE sub.survey_id = ?" . $campusWhere);
    $stmt->execute(array_merge([$surveyId], $campusParams));
    $totalSubmissions = (int)$stmt->fetchColumn();

    // 各校区提交数
    $campusStats = [];
    $stmt = $db->prepare("SELECT s.id, s.name, COUNT(sub.id) as count 
        FROM stores s 
        LEFT JOIN survey_submissions sub ON sub.campus_id = s.id AND sub.survey_id = ?
        WHERE s.status = 1
        GROUP BY s.id, s.name
        ORDER BY s.sort_order");
    $stmt->execute([$surveyId]);
    $campusStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 获取问题列表
    $qStmt = $db->prepare("SELECT * FROM survey_questions WHERE survey_id = ? ORDER BY sort_order ASC, id ASC");
    $qStmt->execute([$surveyId]);
    $questions = $qStmt->fetchAll(PDO::FETCH_ASSOC);

    $questionStats = [];
    foreach ($questions as $q) {
        $stat = [
            'id' => (int)$q['id'],
            'section' => $q['section'],
            'type' => $q['question_type'],
            'text' => $q['question_text'],
            'options' => $q['options'] ? json_decode($q['options'], true) : [],
        ];

        // 单选/评分题：统计分布
        if ($q['question_type'] === 'radio' || $q['question_type'] === 'rating') {
            $aSql = "SELECT COALESCE(a.answer_value, CAST(a.rating_score AS CHAR)) as val, COUNT(*) as cnt
                     FROM survey_answers a
                     JOIN survey_submissions sub ON a.submission_id = sub.id
                     WHERE sub.survey_id = ? AND a.question_id = ?" . $campusWhere . "
                     GROUP BY val ORDER BY val";
            $aStmt = $db->prepare($aSql);
            $aStmt->execute(array_merge([$surveyId, $q['id']], $campusParams));
            $distribution = [];
            $avgScore = 0;
            $totalScore = 0;
            $scoreCount = 0;
            while ($row = $aStmt->fetch(PDO::FETCH_ASSOC)) {
                $distribution[$row['val']] = (int)$row['cnt'];
                if (is_numeric($row['val'])) {
                    $totalScore += (float)$row['val'] * (int)$row['cnt'];
                    $scoreCount += (int)$row['cnt'];
                }
            }
            $stat['distribution'] = $distribution;
            $stat['avg_score'] = $scoreCount > 0 ? round($totalScore / $scoreCount, 2) : 0;
        }

        // 多选题
        if ($q['question_type'] === 'checkbox') {
            $aSql = "SELECT a.answer_values FROM survey_answers a
                     JOIN survey_submissions sub ON a.submission_id = sub.id
                     WHERE sub.survey_id = ? AND a.question_id = ?" . $campusWhere;
            $aStmt = $db->prepare($aSql);
            $aStmt->execute(array_merge([$surveyId, $q['id']], $campusParams));
            $optionCounts = [];
            while ($row = $aStmt->fetch(PDO::FETCH_ASSOC)) {
                $vals = json_decode($row['answer_values'], true);
                if (is_array($vals)) {
                    foreach ($vals as $v) {
                        $optionCounts[$v] = ($optionCounts[$v] ?? 0) + 1;
                    }
                }
            }
            $stat['distribution'] = $optionCounts;
        }

        // 文字题：获取回答列表
        if ($q['question_type'] === 'text') {
            $aSql = "SELECT a.answer_value, sub.submitter_name, sub.campus_id, sub.submitted_at, st.name AS campus_name
                     FROM survey_answers a
                     JOIN survey_submissions sub ON a.submission_id = sub.id
                     LEFT JOIN stores st ON sub.campus_id = st.id
                     WHERE sub.survey_id = ? AND a.question_id = ? AND a.answer_value IS NOT NULL AND a.answer_value != ''" . $campusWhere . "
                     ORDER BY sub.submitted_at DESC";
            $aStmt = $db->prepare($aSql);
            $aStmt->execute(array_merge([$surveyId, $q['id']], $campusParams));
            $stat['text_answers'] = $aStmt->fetchAll(PDO::FETCH_ASSOC);
            $stat['text_count'] = count($stat['text_answers']);
        }

        // NPS题
        if ($q['question_type'] === 'nps') {
            $aSql = "SELECT a.rating_score, COUNT(*) as cnt
                     FROM survey_answers a
                     JOIN survey_submissions sub ON a.submission_id = sub.id
                     WHERE sub.survey_id = ? AND a.question_id = ? AND a.rating_score IS NOT NULL" . $campusWhere . "
                     GROUP BY a.rating_score ORDER BY a.rating_score";
            $aStmt = $db->prepare($aSql);
            $aStmt->execute(array_merge([$surveyId, $q['id']], $campusParams));
            $promoters = 0; $passives = 0; $detractors = 0; $npsTotal = 0;
            $distribution = [];
            while ($row = $aStmt->fetch(PDO::FETCH_ASSOC)) {
                $score = (int)$row['rating_score'];
                $cnt = (int)$row['cnt'];
                $distribution[$score] = $cnt;
                $npsTotal += $cnt;
                if ($score >= 9) $promoters += $cnt;
                elseif ($score >= 7) $passives += $cnt;
                else $detractors += $cnt;
            }
            $stat['distribution'] = $distribution;
            $stat['nps_score'] = $npsTotal > 0 ? round(($promoters - $detractors) / $npsTotal * 100, 1) : 0;
        }

        $questionStats[] = $stat;
    }

    // 最近提交记录
    $recentSql = "SELECT sub.id, sub.submitter_name, sub.campus_id, s.name as campus_name, sub.submitted_at
                  FROM survey_submissions sub
                  LEFT JOIN stores s ON sub.campus_id = s.id
                  WHERE sub.survey_id = ?" . $campusWhere . "
                  ORDER BY sub.submitted_at DESC LIMIT 50";
    $recentStmt = $db->prepare($recentSql);
    $recentStmt->execute(array_merge([$surveyId], $campusParams));
    $recentSubmissions = $recentStmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(0, 'success', [
        'survey' => [
            'id' => (int)$survey['id'],
            'title' => $survey['title'],
            'status' => $survey['status'],
            'is_anonymous' => (int)$survey['is_anonymous'],
        ],
        'total_submissions' => $totalSubmissions,
        'campus_stats' => $campusStats,
        'question_stats' => $questionStats,
        'recent_submissions' => $recentSubmissions,
    ]);

} catch (Exception $e) {
    error_log('survey/stats error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}
