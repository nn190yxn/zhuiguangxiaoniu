<?php
declare(strict_types=1);

final class PointsExchangeService
{
    public function __construct(private PDO $db)
    {
    }

    public function exchange(
        int $userId,
        int $itemId,
        string $receiverName,
        string $receiverPhone,
        string $receiverAddress
    ): array {
        if (!$this->db->inTransaction()) {
            throw new LogicException('积分兑换必须在平台幂等事务内执行');
        }

        $stmt = $this->db->prepare('SELECT * FROM points_exchange_items WHERE id = ? AND status = 1 FOR UPDATE');
        $stmt->execute([$itemId]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new PlatformApiException(404, 'exchange_item_unavailable', '礼品不存在或已下架');
        }
        if ((int) $item['stock'] <= 0) {
            throw new PlatformApiException(409, 'exchange_stock_insufficient', '库存不足');
        }

        $stmt = $this->db->prepare('SELECT total_points FROM user_points WHERE user_id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $userPoints = (int) ($stmt->fetchColumn() ?: 0);
        $pointsPrice = (int) $item['points_price'];
        if ($userPoints < $pointsPrice) {
            throw new PlatformApiException(409, 'user_points_insufficient', '积分不足');
        }

        $newBalance = $userPoints - $pointsPrice;
        $this->db->prepare('UPDATE user_points SET total_points = ? WHERE user_id = ?')
            ->execute([$newBalance, $userId]);
        $this->db->prepare(
            "INSERT INTO points_records (user_id, points, balance, type, source, source_id, description) "
            . "VALUES (?, ?, ?, 'spend', 'exchange', ?, ?)"
        )->execute([$userId, -$pointsPrice, $newBalance, $itemId, '兑换: ' . $item['title']]);
        $this->db->prepare(
            'UPDATE points_exchange_items SET stock = stock - 1, exchange_count = exchange_count + 1 WHERE id = ?'
        )->execute([$itemId]);
        $this->db->prepare(
            'INSERT INTO points_exchange_records '
            . '(user_id, item_id, points_spent, receiver_name, receiver_phone, receiver_address) '
            . 'VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$userId, $itemId, $pointsPrice, $receiverName, $receiverPhone, $receiverAddress]);

        return [
            'exchange_id' => (int) $this->db->lastInsertId(),
            'points_spent' => $pointsPrice,
            'balance' => $newBalance,
        ];
    }
}
