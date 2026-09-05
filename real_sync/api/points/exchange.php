<?php
/**
 * 积分兑换API
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
require_once __DIR__ . '/PointsExchangeService.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$action = $method === 'POST' ? (string)($_GET['action'] ?? 'exchange') : 'list';
$context = platformApiContext(['domain' => 'points', 'action' => 'points.exchange.' . $action]);
platformApiInstallExceptionHandler($context);

$db = getDB();
$userId = (int)getCurrentUserId();

if ($userId <= 0) {
    throw new PlatformApiException(401, 'authentication_required', '请先登录');
}
$context = $context->withActor($userId, null);

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
}

if ($method !== 'POST') {
    throw new PlatformApiException(405, 'method_not_allowed', '不支持的请求方法');
}

if ($action === 'records') {
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

    foreach ($records as &$record) {
        $record['cover_image'] = $record['cover_image'] ? getResourceUrl($record['cover_image']) : null;
    }

    jsonResponse(0, 'success', [
        'records' => $records,
        'total' => $total,
        'page' => $page,
        'page_size' => $pageSize
    ]);
}

if ($action !== 'exchange') {
    throw new PlatformApiException(400, 'unknown_action', '未知操作');
}

$data = json_decode((string)file_get_contents('php://input'), true);
$data = is_array($data) ? $data : [];
$itemId = (int)($data['item_id'] ?? 0);
$receiverName = trim((string)($data['receiver_name'] ?? ''));
$receiverPhone = trim((string)($data['receiver_phone'] ?? ''));
$receiverAddress = trim((string)($data['receiver_address'] ?? ''));

if ($itemId <= 0) {
    throw new PlatformApiException(422, 'item_id_required', '缺少礼品ID');
}
if (mb_strlen($receiverName) > 50) {
    throw new PlatformApiException(422, 'receiver_name_too_long', '收货人姓名过长');
}
if (!preg_match('/^1[3-9]\d{9}$/', $receiverPhone)) {
    throw new PlatformApiException(422, 'receiver_phone_invalid', '手机号格式错误');
}
if (mb_strlen($receiverAddress) > 200) {
    throw new PlatformApiException(422, 'receiver_address_too_long', '收货地址过长');
}

$request = [
    'item_id' => $itemId,
    'receiver_name' => $receiverName,
    'receiver_phone' => $receiverPhone,
    'receiver_address' => $receiverAddress,
];
$idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
$result = (new PlatformIdempotencyService($db))->execute(
    $context,
    'points.exchange',
    'item:' . $itemId,
    $idempotencyKey,
    $request,
    static function () use ($db, $context, $userId, $itemId, $receiverName, $receiverPhone, $receiverAddress): PlatformApiResponse {
        $data = (new PointsExchangeService($db))->exchange(
            $userId,
            $itemId,
            $receiverName,
            $receiverPhone,
            $receiverAddress
        );
        return PlatformApiResponse::success($context, $data, '兑换成功');
    }
);
$result->send();
