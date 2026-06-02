<?php
/**
 * 每月统计汇总定时任务脚本
 * Usage: php cron_monthly_stats.php [year] [month]
 * 默认计算当前月份的统计数据
 */
require '/www/wwwroot/122.51.223.46/api/config.php';

$db = getDB();

$year = isset($argv[1]) ? (int)$argv[1] : (int)date('Y');
$month = isset($argv[2]) ? (int)$argv[2] : (int)date('n');

echo "Start calculating statistics for $year-$month\n";

// 1. 获取所有员工列表
$staffStmt = $db->query("SELECT id, user_id, store_id, name, role, stage FROM staffs WHERE status = 1");
$staffs = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

// 获取门店映射
$storeMap = [];
$storeStmt = $db->query("SELECT id, name FROM stores");
while ($row = $storeStmt->fetch(PDO::FETCH_ASSOC)) {
    $storeMap[$row['id']] = $row['name'];
}

$count = 0;
$startTime = microtime(true);

foreach ($staffs as $staff) {
    $staffId = $staff['id'];
    $userId = $staff['user_id'];
    $storeId = $staff['store_id'];
    $storeName = $storeMap[$storeId] ?? '';
    $name = $staff['name'];
    $role = $staff['role'];
    $stage = $staff['stage'];

    // 时间范围：该月第一天到最后一天
    $startDate = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01 00:00:00";
    $endDate = date("Y-m-t 23:59:59", strtotime($startDate));

    // --- 课程完成数 (courses_completed) ---
    // 假设 status=1 表示已完成
    $sqlCourse = "SELECT COUNT(*) FROM user_course_progress WHERE user_id = ? AND status = 1 AND completed_at BETWEEN ? AND ?";
    $stmt = $db->prepare($sqlCourse);
    $stmt->execute([$userId, $startDate, $endDate]);
    $coursesCompleted = (int)$stmt->fetchColumn();

    // --- 知识卡片完成数 (knowledge_cards_completed) ---
    // is_completed = 1
    $sqlKw = "SELECT COUNT(*) FROM user_knowledge_progress WHERE user_id = ? AND is_completed = 1 AND completed_at BETWEEN ? AND ?";
    $stmt = $db->prepare($sqlKw);
    $stmt->execute([$userId, $startDate, $endDate]);
    $kwCompleted = (int)$stmt->fetchColumn();

    // --- 演练完成数 (drills_completed) ---
    // 有记录即视为完成一次演练
    $sqlDrill = "SELECT COUNT(*) FROM drill_records WHERE user_id = ? AND created_at BETWEEN ? AND ?";
    $stmt = $db->prepare($sqlDrill);
    $stmt->execute([$userId, $startDate, $endDate]);
    $drillsCompleted = (int)$stmt->fetchColumn();
    
    // 演练最佳分数
    $sqlDrillScore = "SELECT MAX(score) FROM drill_records WHERE user_id = ? AND created_at BETWEEN ? AND ?";
    $stmt = $db->prepare($sqlDrillScore);
    $stmt->execute([$userId, $startDate, $endDate]);
    $drillBestScore = (float)($stmt->fetchColumn() ?? 0);

    // --- 考试统计 ---
    $sqlExamCount = "SELECT COUNT(*) FROM exam_records WHERE user_id = ? AND status = 'completed' AND completed_at BETWEEN ? AND ?";
    $stmt = $db->prepare($sqlExamCount);
    $stmt->execute([$userId, $startDate, $endDate]);
    $examsTaken = (int)$stmt->fetchColumn();

    $sqlExamPassed = "SELECT COUNT(*) FROM exam_records WHERE user_id = ? AND status = 'completed' AND is_passed = 1 AND completed_at BETWEEN ? AND ?";
    $stmt = $db->prepare($sqlExamPassed);
    $stmt->execute([$userId, $startDate, $endDate]);
    $examsPassed = (int)$stmt->fetchColumn();

    $sqlExamScore = "SELECT AVG(total_score) FROM exam_records WHERE user_id = ? AND status = 'completed' AND completed_at BETWEEN ? AND ?";
    $stmt = $db->prepare($sqlExamScore);
    $stmt->execute([$userId, $startDate, $endDate]);
    $examAvgScore = (float)($stmt->fetchColumn() ?? 0);

    // --- 学习时长 (total_learning_time) ---
    // 假设 knowledge 表有 learning_time 字段（秒）
    $sqlTime = "SELECT COALESCE(SUM(learning_time), 0) FROM user_knowledge_progress WHERE user_id = ? AND completed_at BETWEEN ? AND ?";
    $stmt = $db->prepare($sqlTime);
    $stmt->execute([$userId, $startDate, $endDate]);
    $learningTime = (int)$stmt->fetchColumn();

    // --- 签到天数 (checkin_days) ---
    // 使用各类记录的 distinct 日期来估算活跃天数
    // UNION 所有相关表的日期
    $sqlActiveDays = "
        SELECT COUNT(DISTINCT DATE(date_val)) as days FROM (
            SELECT DATE(completed_at) as date_val FROM user_course_progress WHERE user_id = ? AND completed_at BETWEEN ? AND ?
            UNION
            SELECT DATE(completed_at) as date_val FROM user_knowledge_progress WHERE user_id = ? AND completed_at BETWEEN ? AND ?
            UNION
            SELECT DATE(created_at) as date_val FROM drill_records WHERE user_id = ? AND created_at BETWEEN ? AND ?
            UNION
            SELECT DATE(completed_at) as date_val FROM exam_records WHERE user_id = ? AND completed_at BETWEEN ? AND ?
        ) as t
    ";
    $stmt = $db->prepare($sqlActiveDays);
    $stmt->execute([
        $userId, $startDate, $endDate,
        $userId, $startDate, $endDate,
        $userId, $startDate, $endDate,
        $userId, $startDate, $endDate
    ]);
    $checkinDays = (int)$stmt->fetchColumn();

    // --- 通关率 (pass_rate) ---
    // (已完成 + 通过) / (课程+知识+演练)
    $totalItems = $coursesCompleted + $kwCompleted + $drillsCompleted;
    if ($totalItems > 0 && $examsTaken > 0) {
        $passRate = ($examsPassed / $examsTaken) * 100;
    } elseif ($totalItems > 0) {
        // 如果没有考试，但有完成记录，暂定 100%? 或者 0? 
        // 为了好看，如果有完成但没考试，算 100% 似乎不合理。
        // 暂时按 0 算，或者只看考试通过率
        $passRate = 0; 
    } else {
        $passRate = 0;
    }

    // --- 写入或更新 monthly_statistics 表 ---
    // 检查是否存在
    $checkSql = "SELECT id FROM monthly_statistics WHERE staff_id = ? AND year = ? AND month = ?";
    $stmt = $db->prepare($checkSql);
    $stmt->execute([$staffId, $year, $month]);
    $rowId = $stmt->fetchColumn();

    if ($rowId) {
        $updateSql = "UPDATE monthly_statistics SET 
            courses_started = ?, courses_completed = ?,
            knowledge_cards_learned = ?, knowledge_cards_completed = ?,
            total_learning_time = ?,
            drills_started = ?, drills_completed = ?, drill_best_score = ?,
            exams_taken = ?, exams_passed = ?, exam_avg_score = ?,
            pass_rate = ?,
            checkin_days = ?,
            data_updated_at = NOW(), updated_at = NOW()
            WHERE id = ?";
        $stmt = $db->prepare($updateSql);
        $stmt->execute([
            $coursesCompleted, $coursesCompleted,
            $kwCompleted, $kwCompleted,
            $learningTime,
            $drillsCompleted, $drillsCompleted, $drillBestScore,
            $examsTaken, $examsPassed, $examAvgScore,
            round($passRate, 1),
            $checkinDays,
            $rowId
        ]);
    } else {
        $insertSql = "INSERT INTO monthly_statistics (
            staff_id, year, month, store_id, store_name, staff_name, role, stage,
            courses_started, courses_completed,
            knowledge_cards_learned, knowledge_cards_completed,
            total_learning_time,
            drills_started, drills_completed, drill_best_score,
            exams_taken, exams_passed, exam_avg_score,
            pass_rate,
            checkin_days,
            data_updated_at, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())";
        $stmt = $db->prepare($insertSql);
        $stmt->execute([
            $staffId, $year, $month, $storeId, $storeName, $name, $role, $stage,
            $coursesCompleted, $coursesCompleted,
            $kwCompleted, $kwCompleted,
            $learningTime,
            $drillsCompleted, $drillsCompleted, $drillBestScore,
            $examsTaken, $examsPassed, $examAvgScore,
            round($passRate, 1),
            $checkinDays
        ]);
    }
    $count++;
    if ($count % 10 === 0) {
        echo "Processed $count staffs...\n";
    }
}

$endTime = microtime(true);
echo "Completed. Processed $count staffs. Time: " . round($endTime - $startTime, 2) . "s\n";
