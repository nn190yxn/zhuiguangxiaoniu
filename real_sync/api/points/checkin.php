<?php
/**
 * 每日签到API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if ($method === 'POST') {
        if ($userId <= 0) {
            jsonResponse(401, '请先登录');
        }

        $lockKey = sprintf('daily_checkin_%d_%s', $userId, date('Ymd'));
        $lockStmt = $db->prepare('SELECT GET_LOCK(?, 5)');
        $lockStmt->execute([$lockKey]);
        $locked = (int)$lockStmt->fetchColumn() === 1;
        if (!$locked) {
            jsonResponse(1, '签到繁忙，请稍后重试');
        }

        $db->beginTransaction();

        // 检查今日是否已签到
        $todayStart = date('Y-m-d 00:00:00');
        $checkinSql = "SELECT COUNT(*) FROM points_records WHERE user_id = ? AND rule_id = (SELECT id FROM points_rules WHERE code = 'daily_checkin') AND created_at >= ?";
        $stmt = $db->prepare($checkinSql);
        $stmt->execute([$userId, $todayStart]);
        $todayChecked = $stmt->fetchColumn() > 0;

        if ($todayChecked) {
            $db->rollBack();
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockKey]);
            jsonResponse(1, '今日已签到');
        }

        // 获取签到规则
        $ruleSql = "SELECT * FROM points_rules WHERE code = 'daily_checkin' AND status = 1";
        $stmt = $db->prepare($ruleSql);
        $stmt->execute();
        $rule = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$rule) {
            $db->rollBack();
            $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockKey]);
            jsonResponse(1, '签到规则未启用');
        }

        // 更新用户积分
        $points = $rule['points'];
        $userPointsSql = "INSERT INTO user_points (user_id, total_points, accumulated_points) VALUES (?, ?, ?)
                          ON DUPLICATE KEY UPDATE total_points = total_points + ?, accumulated_points = accumulated_points + ?";
        $stmt = $db->prepare($userPointsSql);
        $stmt->execute([$userId, $points, $points, $points, $points]);

        // 获取最新余额
        $balanceSql = "SELECT total_points FROM user_points WHERE user_id = ?";
        $stmt = $db->prepare($balanceSql);
        $stmt->execute([$userId]);
        $balance = $stmt->fetchColumn();

        // 记录积分变动
        $recordSql = "INSERT INTO points_records (user_id, rule_id, points, balance, type, source, description)
                      VALUES (?, ?, ?, ?, 'earn', 'checkin', ?)";
        $stmt = $db->prepare($recordSql);
        $stmt->execute([$userId, $rule['id'], $points, $balance, '每日签到']);

        // 获取本周连续签到天数
        $weekStart = date('Y-m-d 00:00:00', strtotime('monday this week'));
        $continuousSql = "SELECT COUNT(DISTINCT DATE(created_at)) FROM points_records WHERE user_id = ? AND rule_id = ? AND created_at >= ?";
        $stmt = $db->prepare($continuousSql);
        $stmt->execute([$userId, $rule['id'], $weekStart]);
        $continuousDays = $stmt->fetchColumn();

        $db->commit();
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockKey]);

        jsonResponse(0, '签到成功', [
            'points' => $points,
            'balance' => $balance,
            'continuous_days' => $continuousDays
        ]);
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(1, '服务器错误: ' . $e->getMessage());
}
