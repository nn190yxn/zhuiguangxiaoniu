<?php
/**
 * 积分概览API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if ($method === 'GET') {
        // 获取用户积分
        $pointsSql = "SELECT * FROM user_points WHERE user_id = ?";
        $stmt = $db->prepare($pointsSql);
        $stmt->execute([$userId]);
        $points = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$points) {
            // 初始化用户积分
            $initSql = "INSERT INTO user_points (user_id, total_points, accumulated_points) VALUES (?, 0, 0)";
            $stmt = $db->prepare($initSql);
            $stmt->execute([$userId]);

            $points = [
                'total_points' => 0,
                'accumulated_points' => 0
            ];
        }

        // 获取积分规则列表
        $rulesSql = "SELECT * FROM points_rules WHERE status = 1 ORDER BY id ASC";
        $stmt = $db->prepare($rulesSql);
        $stmt->execute();
        $rules = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 获取今日签到状态
        $todayStart = date('Y-m-d 00:00:00');
        $checkinSql = "SELECT COUNT(*) FROM points_records WHERE user_id = ? AND rule_id = (SELECT id FROM points_rules WHERE code = 'daily_checkin') AND created_at >= ?";
        $stmt = $db->prepare($checkinSql);
        $stmt->execute([$userId, $todayStart]);
        $todayChecked = $stmt->fetchColumn() > 0;

        // 获取本周连续签到天数
        $weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $continuousSql = "SELECT created_at FROM points_records WHERE user_id = ? AND rule_id = (SELECT id FROM points_rules WHERE code = 'daily_checkin') AND created_at >= ? ORDER BY created_at ASC";
        $stmt = $db->prepare($continuousSql);
        $stmt->execute([$userId, $weekStart]);
        $checkinDays = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $continuousDays = 0;
        $lastDate = null;
        foreach ($checkinDays as $day) {
            $date = date('Y-m-d', strtotime($day['created_at']));
            if ($lastDate === null || date('Y-m-d', strtotime($lastDate . ' +1 day')) === $date) {
                $continuousDays++;
                $lastDate = $date;
            } else {
                break;
            }
        }

        jsonResponse(0, 'success', [
            'total_points' => $points['total_points'],
            'accumulated_points' => $points['accumulated_points'],
            'today_checked' => $todayChecked,
            'continuous_days' => $continuousDays,
            'rules' => $rules
        ]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
