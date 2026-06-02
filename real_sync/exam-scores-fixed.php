<?php
/**
 * Admin exam scores summary API
 */
require_once __DIR__ . '/../config.php';
handleCORS();
$user = getJwtCurrentUser();
if (!$user || !in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    jsonError(403, '无权限访问');
}
$db = getDB();

$page = (int)($_GET['page'] ?? 1);
$perPage = 20;
$offset = ($page - 1) * $perPage;
$keyword = trim($_GET['keyword'] ?? '');
$examType = $_GET['exam_type'] ?? '';
$paperCode = strtoupper(trim((string)($_GET['paper_code'] ?? '')));

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(s.name LIKE ? OR s.phone LIKE ?)';
    $params[] = "%$keyword%";
    $params[] = "%$keyword%";
}
if ($examType !== '') {
    $where[] = 'e.exam_type = ?';
    $params[] = $examType;
}
// 这里的 paperCode 筛选保留，但实际可能没有存入 metadata，所以可能无效，暂留
if (in_array($paperCode, ['A', 'B'], true)) {
    // $where[] = "r.answers LIKE ?";
    // $params[] = '%"paper_code":"' . $paperCode . '"%';
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $db->prepare("SELECT COUNT(*) FROM exam_records r LEFT JOIN staffs s ON r.user_id = s.user_id LEFT JOIN exams e ON r.exam_type = e.exam_type $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$stmt = $db->prepare("SELECT r.*, s.name, s.phone, s.role, e.title as exam_title 
    FROM exam_records r 
    LEFT JOIN staffs s ON r.user_id = s.user_id 
    LEFT JOIN exams e ON r.exam_type = e.exam_type 
    $whereSql 
    ORDER BY r.created_at DESC 
    LIMIT $offset, $perPage");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// === B 卷题目定义 (用于后台重算分数) ===
$examDataB = [
    'questions' => [
        ['id' => 101, 'type' => 'choice', 'score' => 4, 'answer' => 'B', 'keywords' => []],
        ['id' => 102, 'type' => 'choice', 'score' => 4, 'answer' => 'B', 'keywords' => []],
        ['id' => 103, 'type' => 'choice', 'score' => 4, 'answer' => 'C', 'keywords' => []],
        ['id' => 104, 'type' => 'choice', 'score' => 4, 'answer' => 'B', 'keywords' => []],
        ['id' => 105, 'type' => 'choice', 'score' => 4, 'answer' => 'B', 'keywords' => []],
        ['id' => 106, 'type' => 'judge', 'score' => 2, 'answer' => 'X', 'keywords' => []],
        ['id' => 107, 'type' => 'judge', 'score' => 2, 'answer' => 'V', 'keywords' => []],
        ['id' => 108, 'type' => 'judge', 'score' => 2, 'answer' => 'X', 'keywords' => []],
        ['id' => 109, 'type' => 'judge', 'score' => 2, 'answer' => 'V', 'keywords' => []],
        ['id' => 110, 'type' => 'judge', 'score' => 2, 'answer' => 'V', 'keywords' => []],
        ['id' => 111, 'type' => 'text', 'score' => 8, 'answer' => '', 'keywords' => ['不做保证', '专业指导', '持续反馈', '体测', '阶段目标', '教练评估']],
        ['id' => 112, 'type' => 'text', 'score' => 8, 'answer' => '', 'keywords' => ['下肢力量', '协调', '基础建立', '训练方向', '鼓励', '发展']],
        ['id' => 113, 'type' => 'text', 'score' => 8, 'answer' => '', 'keywords' => ['理解', '认同', '系统训练', '动作标准', '收益', '效果']],
        ['id' => 114, 'type' => 'text', 'score' => 22, 'answer' => '', 'keywords' => ['判断', '翻译', '给路径', '跳绳', '折返跑', '体能训练', '体能跳绳', '边界', '阶段目标', '复测']],
    ],
    'totalScoreConfig' => 76 // B卷原始满分
];

foreach ($rows as &$row) {
    $paperCode = 'A'; // Default
    $adjustedScore = $row['total_score'];

    if (!empty($row['answers'])) {
        $decoded = json_decode($row['answers'], true);
        if (is_array($decoded)) {
            // 1. 尝试从 metadata 获取 paper_code
            $paper = strtoupper(trim((string)($decoded['__meta']['paper_code'] ?? '')));
            if (in_array($paper, ['A', 'B'], true)) {
                $paperCode = $paper;
            } else {
                // 2. 如果没存 metadata，根据题号判断 (B 卷题号从 101 开始)
                foreach ($decoded as $qid => $val) {
                    if (is_numeric($qid) && (int)$qid >= 100) {
                        $paperCode = 'B';
                        break;
                    }
                }
            }
        }
    }

    // 如果是 B 卷，执行分数修正
    if ($paperCode === 'B') {
        $rawScore = (int)$row['total_score'];
        // 重新按放宽标准算一次 rawScore
        // 逻辑：如果原文本长度>10字，且关键词命中率极低（导致0分），给40%基础分
        $newRawScore = 0;
        $answers = json_decode($row['answers'], true);
        
        foreach ($examDataB['questions'] as $q) {
            $userAns = (string)($answers[$q['id']] ?? '');
            $score = 0;
            
            if ($q['type'] === 'choice' || $q['type'] === 'judge') {
                if ($userAns === $q['answer']) $score = $q['score'];
            } else if ($q['type'] === 'text') {
                // 放宽的关键词匹配逻辑
                if (strlen(trim($userAns)) > 5) { // 只要写了就可能有分
                    $matched = 0;
                    $ansLower = mb_strtolower($userAns, 'UTF-8');
                    foreach ($q['keywords'] as $kw) {
                        if (mb_strpos($ansLower, mb_strtolower($kw, 'UTF-8')) !== false) {
                            $matched++;
                        }
                    }
                    $ratio = $q['keywords'] ? ($matched / count($q['keywords'])) : 0;
                    
                    // 放宽规则：
                    // 1. 只要写了 (>5字)，保底给 20%
                    $score = $q['score'] * max($ratio * 1.3, 0.2); 
                    $score = round($score);
                }
            }
            $newRawScore += $score;
        }

        // 2. 将 rawScore 按比例换算为 100 分制
        // 比例 = 100 / 76 ≈ 1.315
        $adjustedScore = round($newRawScore * (100 / 76));
    }

    $row['paper_code'] = $paperCode;
    $row['total_score'] = $adjustedScore; // 覆盖显示的分数
}
unset($row);

$exams = $db->query("SELECT id, title, exam_type FROM exams ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

jsonSuccess([
    'list' => $rows,
    'total' => $total,
    'page' => $page,
    'per_page' => $perPage,
    'filters' => [
        'keyword' => $keyword,
        'exam_type' => $examType,
        'paper_code' => $paperCode,
    ],
    'exams' => $exams,
]);
