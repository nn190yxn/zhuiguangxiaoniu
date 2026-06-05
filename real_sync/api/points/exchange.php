<?php
/**
 * 积分兑换API
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = getDB();
    $userId = getCurrentUserId();

    if (!$userId) {
        jsonResponse(401, '请先登录');
    }

    if ($method === 'GET') {
        // 获取可兑换礼品列表
        $now = date('Y-m-d H:i:s');
        $sql = "SELECT * FROM points_exchange_items
                WHERE status = 1 AND stock > 0
                AND (start_time IS NULL OR start_time <= ?)
                AND (end_time IS NULL OR end_time >= ?)
                ORDER BY points_price ASC";
        $stmt = $db->prepare($sql);
        $stmt->execute([$now, $now]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as &$item) {
            $item['cover_image'] = $item['cover_image'] ? getResourceUrl($item['cover_image']) : null;
        }

        // 获取用户积分
        $pointsSql = "SELECT total_points FROM user_points WHERE user_id = ?";
        $stmt = $db->prepare($pointsSql);
        $stmt->execute([$userId]);
        $totalPoints = $stmt->fetchColumn() ?: 0;

        jsonResponse(0, 'success', [
            'items' => $items,
            'user_points' => $totalPoints
        ]);

    } elseif ($method === 'POST') {
        $action = isset($_GET['action']) ? $_GET['action'] : 'exchange';

        if ($action === 'exchange') {
            $data = json_decode(file_get_contents('php://input'), true);
            $itemId = isset($data['item_id']) ? (int)$data['item_id'] : 0;
            $receiverName = isset($data['receiver_name']) ? trim($data['receiver_name']) : '';
            $receiverPhone = isset($data['receiver_phone']) ? trim($data['receiver_phone']) : '';
            $receiverAddress = isset($data['receiver_address']) ? trim($data['receiver_address']) : '';

            if (!$itemId) {
                jsonResponse(1, '缺少礼品ID');
            }

            if (mb_strlen($receiverName) > 50) {
                jsonResponse(1, '收货人姓名过长');
            }
            if (!preg_match('/^1[3-9]\d{9}$/', $receiverPhone)) {
                jsonResponse(1, '手机号格式错误');
            }
            if (mb_strlen($receiverAddress) > 200) {
                jsonResponse(1, '收货地址过长');
            }

            try {
                $db->beginTransaction();

                // 获取礼品信息（带锁防止并发）
                $itemSql = "SELECT * FROM points_exchange_items WHERE id = ? AND status = 1 FOR UPDATE";
                $stmt = $db->prepare($itemSql);
                $stmt->execute([$itemId]);
                $item = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$item) {
                    $db->rollBack();
                    jsonResponse(1, '礼品不存在或已下架');
                }

                if ($item['stock'] <= 0) {
                    $db->rollBack();
                    jsonResponse(1, '库存不足');
                }

                // 检查用户积分
                $pointsSql = "SELECT total_points FROM user_points WHERE user_id = ? FOR UPDATE";
                $stmt = $db->prepare($pointsSql);
                $stmt->execute([$userId]);
                $userPoints = $stmt->fetchColumn() ?: 0;

                if ($userPoints < $item['points_price']) {
                    $db->rollBack();
                    jsonResponse(1, '积分不足');
                }

                $newBalance = $userPoints - $item['points_price'];

                // 扣除积分
                $deductSql = "UPDATE user_points SET total_points = ? WHERE user_id = ?";
                $stmt = $db->prepare($deductSql);
                $stmt->execute([$newBalance, $userId]);

                // 记录积分消耗
                $recordSql = "INSERT INTO points_records (user_id, points, balance, type, source, source_id, description)
                              VALUES (?, ?, ?, 'spend', 'exchange', ?, ?)";
                $stmt = $db->prepare($recordSql);
                $stmt->execute([$userId, -$item['points_price'], $newBalance, $itemId, '兑换: ' . $item['title']]);

                // 减少库存
                $stockSql = "UPDATE points_exchange_items SET stock = stock - 1, exchange_count = exchange_count + 1 WHERE id = ?";
                $stmt = $db->prepare($stockSql);
                $stmt->execute([$itemId]);

                // 创建兑换记录
                $exchangeSql = "INSERT INTO points_exchange_records (user_id, item_id, points_spent, receiver_name, receiver_phone, receiver_address)
                                 VALUES (?, ?, ?, ?, ?, ?)";
                $stmt = $db->prepare($exchangeSql);
                $stmt->execute([$userId, $itemId, $item['points_price'], $receiverName, $receiverPhone, $receiverAddress]);

                $db->commit();

                jsonResponse(0, '兑换成功', [
                    'exchange_id' => $db->lastInsertId(),
                    'points_spent' => $item['points_price'],
                    'balance' => $newBalance
                ]);
            } catch (Exception $e) {
                $db->rollBack();
                throw $e;
            }

        } elseif ($action === 'records') {
            // 获取兑换记录
            $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
            $pageSize = min(100, max(1, isset($_GET['page_size']) ? (int)$_GET['page_size'] : 10));
            $offset = ($page - 1) * $pageSize;

            $countSql = "SELECT COUNT(*) FROM points_exchange_records WHERE user_id = ?";
            $stmt = $db->prepare($countSql);
            $stmt->execute([$userId]);
            $total = $stmt->fetchColumn();

            $sql = "SELECT er.*, ei.title as item_title, ei.cover_image
                    FROM points_exchange_records er
                    LEFT JOIN points_exchange_items ei ON er.item_id = ei.id
                    WHERE er.user_id = ?
                    ORDER BY er.created_at DESC
                    LIMIT ? OFFSET ?";

            $stmt = $db->prepare($sql);
            $stmt->execute([$userId, $pageSize, $offset]);
            $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($records as &$r) {
                $r['cover_image'] = $r['cover_image'] ? getResourceUrl($r['cover_image']) : null;
            }

            jsonResponse(0, 'success', [
                'records' => $records,
                'total' => $total,
                'page' => $page,
                'page_size' => $pageSize
            ]);

        } else {
            jsonResponse(1, '未知操作');
        }
    } else {
        jsonResponse(1, '不支持的请求方法');
    }
} catch (Exception $e) {
    error_log('points/exchange error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误，请稍后重试');
}
