<?php
/**
 * 积分排行榜 API。
 */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

try {
    $db = getDB();
    $userId = getCurrentUserId();
    if ($userId <= 0) {
        jsonResponse(401, '请先登录', null, 401);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        jsonResponse(1, '不支持的请求方法');
        exit;
    }

    $limit = min(100, max(1, isset($_GET['limit']) ? (int)$_GET['limit'] : 20));
    $stmt = $db->prepare(
        'SELECT up.user_id, COALESCE(NULLIF(u.display_name, \'\'), u.user_login, CONCAT(\'用户\', up.user_id)) AS display_name, '
        . 'up.accumulated_points, up.total_points '
        . 'FROM user_points up LEFT JOIN wp_users u ON u.ID = up.user_id '
        . 'ORDER BY up.accumulated_points DESC, up.user_id ASC LIMIT ?'
    );
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $ranking = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ranking as $index => &$item) {
        $item['rank'] = $index + 1;
        $item['accumulated_points'] = (int)$item['accumulated_points'];
        $item['total_points'] = (int)$item['total_points'];
    }
    unset($item);

    $meStmt = $db->prepare(
        'SELECT up.user_id, up.accumulated_points, up.total_points, '
        . '(SELECT COUNT(*) + 1 FROM user_points higher WHERE higher.accumulated_points > up.accumulated_points) AS `rank` '
        . 'FROM user_points up WHERE up.user_id = ?'
    );
    $meStmt->execute([$userId]);
    $me = $meStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($me) {
        $me['rank'] = (int)$me['rank'];
        $me['accumulated_points'] = (int)$me['accumulated_points'];
        $me['total_points'] = (int)$me['total_points'];
    }

    jsonResponse(0, 'success', ['ranking' => $ranking, 'me' => $me]);
} catch (Exception $e) {
    error_log('points/ranking error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误，请稍后重试');
}
