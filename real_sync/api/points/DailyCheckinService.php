<?php
declare(strict_types=1);

final class DailyCheckinService
{
    public function __construct(private PDO $db)
    {
    }

    public function checkIn(int $userId, string $businessDate): array
    {
        if (!$this->db->inTransaction()) {
            throw new LogicException('每日签到必须在平台幂等事务内执行');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $businessDate);
        if (!$date || $date->format('Y-m-d') !== $businessDate) {
            throw new InvalidArgumentException('签到业务日期无效');
        }

        $lockKey = sprintf('daily_checkin_%d_%s', $userId, $date->format('Ymd'));
        $lockStmt = $this->db->prepare('SELECT GET_LOCK(?, 5)');
        $lockStmt->execute([$lockKey]);
        $locked = (int) $lockStmt->fetchColumn() === 1;
        if (!$locked) {
            throw new PlatformApiException(409, 'daily_checkin_busy', '签到繁忙，请稍后重试');
        }

        try {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) FROM points_records WHERE user_id = ? "
                . "AND rule_id = (SELECT id FROM points_rules WHERE code = 'daily_checkin') AND created_at >= ?"
            );
            $stmt->execute([$userId, $businessDate . ' 00:00:00']);
            if ((int) $stmt->fetchColumn() > 0) {
                throw new PlatformApiException(409, 'daily_checkin_already_completed', '今日已签到');
            }

            $stmt = $this->db->query("SELECT * FROM points_rules WHERE code = 'daily_checkin' AND status = 1");
            $rule = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$rule) {
                throw new PlatformApiException(409, 'daily_checkin_rule_disabled', '签到规则未启用');
            }

            $points = (int) $rule['points'];
            $this->db->prepare(
                'INSERT INTO user_points (user_id, total_points, accumulated_points) VALUES (?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE total_points = total_points + ?, accumulated_points = accumulated_points + ?'
            )->execute([$userId, $points, $points, $points, $points]);
            $stmt = $this->db->prepare('SELECT total_points FROM user_points WHERE user_id = ?');
            $stmt->execute([$userId]);
            $balance = (int) $stmt->fetchColumn();

            try {
                $this->db->prepare(
                    "INSERT INTO points_records (user_id, rule_id, points, balance, type, source, business_date, description) "
                    . "VALUES (?, ?, ?, ?, 'earn', 'checkin', ?, ?)"
                )->execute([$userId, $rule['id'], $points, $balance, $businessDate, '每日签到']);
            } catch (PDOException $error) {
                if ($error->getCode() === '23000') {
                    throw new PlatformApiException(409, 'daily_checkin_already_completed', '今日已签到', [], $error);
                }
                throw $error;
            }

            $weekStart = $date->modify('monday this week')->format('Y-m-d 00:00:00');
            $stmt = $this->db->prepare(
                'SELECT COUNT(DISTINCT DATE(created_at)) FROM points_records '
                . 'WHERE user_id = ? AND rule_id = ? AND created_at >= ?'
            );
            $stmt->execute([$userId, $rule['id'], $weekStart]);

            return [
                'points' => $points,
                'balance' => $balance,
                'continuous_days' => (int) $stmt->fetchColumn(),
            ];
        } finally {
            $this->db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockKey]);
        }
    }
}
