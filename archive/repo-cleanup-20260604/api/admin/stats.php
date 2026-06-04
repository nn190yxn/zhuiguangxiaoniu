<?php
/**
 * 内网数据统计API
 * 与小程序共享数据库，实现数据同步
 * GET /api/admin/stats.php
 */

require_once __DIR__ . '/common.php';
handleCORS();
adminRequireAuth('adminCanAccessHeadquarter');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

try {
    $db = getDB();

    // ========== 话术知识库统计 ==========
    $scriptStats = [];
    $stmt = $db->query("SELECT dimension_id, COUNT(*) as cnt FROM script_knowledge GROUP BY dimension_id");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $dimNames = [1 => 'qa', 2 => 'knowledge', 3 => 'feedback', 4 => 'deal'];
        $dimLabels = [1 => '问答话术', 2 => '专业知识', 3 => '课后点评', 4 => '独立谈单'];
        $scriptStats[$dimNames[$row['dimension_id']]] = [
            'id' => $row['dimension_id'],
            'name' => $dimLabels[$row['dimension_id']],
            'count' => (int)$row['cnt']
        ];
    }

    // ========== 培训模块统计 ==========
    $moduleStats = [];
    $stmt = $db->query("
        SELECT tm.id, tm.module_code, tm.module_name, tm.role_code, tm.level, tm.category,
               COUNT(tc.id) as card_count
        FROM training_modules tm
        LEFT JOIN training_cards tc ON tm.id = tc.module_id AND tc.status = 1
        WHERE tm.status = 1
        GROUP BY tm.id
        ORDER BY tm.sort_order
    ");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $moduleStats[] = [
            'id' => (int)$row['id'],
            'code' => $row['module_code'],
            'name' => $row['module_name'],
            'role' => $row['role_code'],
            'level' => $row['level'],
            'category' => $row['category'],
            'card_count' => (int)$row['card_count']
        ];
    }

    // ========== 培训卡片类型分布 ==========
    $cardTypeStats = ['K' => 0, 'S' => 0, 'D' => 0, 'C' => 0];
    $stmt = $db->query("SELECT card_type, COUNT(*) as cnt FROM training_cards WHERE status = 1 GROUP BY card_type");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (isset($cardTypeStats[$row['card_type']])) {
            $cardTypeStats[$row['card_type']] = (int)$row['cnt'];
        }
    }

    // ========== 用户统计 ==========
    $userStats = [
        'total' => 0,
        'active' => 0,
        'new_today' => 0,
        'new_this_week' => 0,
        'new_this_month' => 0
    ];

    // WordPress用户总数
    $stmt = $db->query("SELECT COUNT(*) FROM wp_users WHERE user_status = 0");
    $userStats['total'] = (int)$stmt->fetchColumn();

    // 今日新增
    $stmt = $db->query("SELECT COUNT(*) FROM wp_users WHERE user_registered >= CURDATE()");
    $userStats['new_today'] = (int)$stmt->fetchColumn();

    // 本周新增
    $stmt = $db->query("SELECT COUNT(*) FROM wp_users WHERE user_registered >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)");
    $userStats['new_this_week'] = (int)$stmt->fetchColumn();

    // 本月新增
    $stmt = $db->query("SELECT COUNT(*) FROM wp_users WHERE user_registered >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $userStats['new_this_month'] = (int)$stmt->fetchColumn();

    // ========== 用户学习进度 ==========
    $progressStats = [
        'total_records' => 0,
        'completed' => 0,
        'passed' => 0,
        'in_progress' => 0,
        'not_started' => 0
    ];

    try {
        $stmt = $db->query("SELECT COUNT(*) FROM user_progress");
        $progressStats['total_records'] = (int)$stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM user_progress WHERE status = 'completed'");
        $progressStats['completed'] = (int)$stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM user_progress WHERE status = 'passed'");
        $progressStats['passed'] = (int)$stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM user_progress WHERE status = 'in_progress'");
        $progressStats['in_progress'] = (int)$stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM user_progress WHERE status = 'not_started'");
        $progressStats['not_started'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        // 表可能不存在
    }

    // ========== 考试记录统计 ==========
    $examStats = [
        'total' => 0,
        'passed' => 0,
        'failed' => 0,
        'pending' => 0,
        'pass_rate' => 0,
        'avg_score' => 0,
        'total_attempts' => 0
    ];

    try {
        $stmt = $db->query("SELECT COUNT(*) FROM exam_records");
        $examStats['total'] = (int)$stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM exam_records WHERE is_passed = 1");
        $examStats['passed'] = (int)$stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM exam_records WHERE is_passed = 0 AND status = 'completed'");
        $examStats['failed'] = (int)$stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM exam_records WHERE status = 'pending'");
        $examStats['pending'] = (int)$stmt->fetchColumn();

        if ($examStats['total'] > 0) {
            $examStats['pass_rate'] = round($examStats['passed'] / $examStats['total'] * 100, 1);
        }

        $stmt = $db->query("SELECT AVG(total_score) FROM exam_records WHERE total_score > 0");
        $avg = $stmt->fetchColumn();
        $examStats['avg_score'] = $avg ? round((float)$avg, 1) : 0;

        $stmt = $db->query("SELECT SUM(attempts) FROM (SELECT COUNT(*) as attempts FROM exam_records GROUP BY user_id) t");
        $examStats['total_attempts'] = (int)($stmt->fetchColumn() ?: 0);
    } catch (Exception $e) {
        // 表可能不存在
    }

    // ========== 练习记录统计 ==========
    $practiceStats = [
        'total' => 0,
        'completed' => 0,
        'pending' => 0,
        'processing' => 0
    ];

    try {
        $stmt = $db->query("SELECT COUNT(*) FROM practice_records");
        $practiceStats['total'] = (int)$stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM practice_records WHERE status = 'completed'");
        $practiceStats['completed'] = (int)$stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM practice_records WHERE status = 'pending'");
        $practiceStats['pending'] = (int)$stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM practice_records WHERE status = 'processing'");
        $practiceStats['processing'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        // 表可能不存在
    }

    // ========== 话术分析记录 ==========
    $scriptAnalysisStats = ['total' => 0, 'completed' => 0, 'pending' => 0];
    try {
        $stmt = $db->query("SELECT COUNT(*) FROM script_analysis_records");
        $scriptAnalysisStats['total'] = (int)$stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM script_analysis_records WHERE status = 'completed'");
        $scriptAnalysisStats['completed'] = (int)$stmt->fetchColumn();

        $stmt = $db->query("SELECT COUNT(*) FROM script_analysis_records WHERE status = 'pending'");
        $scriptAnalysisStats['pending'] = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        // 表可能不存在
    }

    // ========== 各模块学习进度 ==========
    $moduleProgress = [];
    try {
        $stmt = $db->query("
            SELECT
                tm.id,
                tm.module_name,
                COUNT(up.id) as total_records,
                SUM(CASE WHEN up.status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN up.status = 'passed' THEN 1 ELSE 0 END) as passed,
                AVG(up.best_score) as avg_score
            FROM training_modules tm
            LEFT JOIN user_progress up ON tm.id = up.module_id
            WHERE tm.status = 1
            GROUP BY tm.id
            ORDER BY tm.sort_order
        ");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $moduleProgress[] = [
                'id' => (int)$row['id'],
                'name' => $row['module_name'],
                'records' => (int)$row['total_records'],
                'completed' => (int)$row['completed'],
                'passed' => (int)$row['passed'],
                'avg_score' => $row['avg_score'] ? round((float)$row['avg_score'], 1) : 0
            ];
        }
    } catch (Exception $e) {
        // 表可能不存在
    }

    // ========== 考试类型分布 ==========
    $examTypeStats = [];
    try {
        $stmt = $db->query("SELECT exam_type, COUNT(*) as cnt, AVG(total_score) as avg_score FROM exam_records GROUP BY exam_type");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $examTypeStats[] = [
                'type' => $row['exam_type'],
                'count' => (int)$row['cnt'],
                'avg_score' => $row['avg_score'] ? round((float)$row['avg_score'], 1) : 0
            ];
        }
    } catch (Exception $e) {
        // 表可能不存在
    }

    // ========== AB卷分布统计 ==========
    $examPaperStats = [
        'A' => ['count' => 0, 'passed' => 0, 'avg_score' => 0],
        'B' => ['count' => 0, 'passed' => 0, 'avg_score' => 0],
    ];
    try {
        $stmt = $db->query("SELECT answers, is_passed, total_score FROM exam_records WHERE exam_type = 'course_exam' AND status = 'completed'");
        $scoreSum = ['A' => 0.0, 'B' => 0.0];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $paper = 'A';
            if (!empty($row['answers'])) {
                $decoded = json_decode($row['answers'], true);
                if (is_array($decoded)) {
                    $pc = strtoupper(trim((string)($decoded['__meta']['paper_code'] ?? '')));
                    if (in_array($pc, ['A', 'B'], true)) {
                        $paper = $pc;
                    }
                }
            }
            $examPaperStats[$paper]['count']++;
            $examPaperStats[$paper]['passed'] += ((int)$row['is_passed'] === 1 ? 1 : 0);
            $scoreSum[$paper] += (float)($row['total_score'] ?? 0);
        }

        foreach (['A', 'B'] as $paper) {
            if ($examPaperStats[$paper]['count'] > 0) {
                $examPaperStats[$paper]['avg_score'] = round($scoreSum[$paper] / $examPaperStats[$paper]['count'], 1);
                $examPaperStats[$paper]['pass_rate'] = round($examPaperStats[$paper]['passed'] / $examPaperStats[$paper]['count'] * 100, 1);
            } else {
                $examPaperStats[$paper]['avg_score'] = 0;
                $examPaperStats[$paper]['pass_rate'] = 0;
            }
        }
    } catch (Exception $e) {
        // 表可能不存在
    }

    // ========== 汇总数据 ==========
    $result = [
        'timestamp' => date('Y-m-d H:i:s'),
        'script_knowledge' => [
            'total' => array_sum(array_column($scriptStats, 'count')),
            'dimensions' => $scriptStats
        ],
        'training' => [
            'modules' => $moduleStats,
            'module_count' => count($moduleStats),
            'total_cards' => array_sum($cardTypeStats),
            'card_types' => $cardTypeStats
        ],
        'users' => $userStats,
        'progress' => $progressStats,
        'exams' => $examStats,
        'practice' => $practiceStats,
        'script_analysis' => $scriptAnalysisStats,
        'module_progress' => $moduleProgress,
        'exam_types' => $examTypeStats,
        'exam_papers' => $examPaperStats,
        'summary' => [
            'script_knowledge_total' => array_sum(array_column($scriptStats, 'count')),
            'training_cards_total' => array_sum($cardTypeStats),
            'modules_total' => count($moduleStats),
            'users_total' => $userStats['total'],
            'exams_total' => $examStats['total'],
            'practice_total' => $practiceStats['total']
        ]
    ];

    jsonResponse(0, 'success', $result);

} catch (Exception $e) {
    error_log('Stats API error');
    jsonResponse(1, '服务器错误');
}
