<?php
/**
 * 订阅管理API
 * GET /api/policy/subscription.php - 获取订阅状态
 * POST /api/policy/subscription.php - 更新订阅设置
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/config.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
$db->set_charset('utf8mb4');

$user = getJwtCurrentUser();
if (!$user) {
    json_response(401, '未登录');
}

$user_id = $user['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // 获取订阅状态
    $sql = "SELECT id, category, subscribe_update, subscribe_reminder, enabled
            FROM policy_subscriptions WHERE user_id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $subscriptions = [];
    while ($row = $result->fetch_assoc()) {
        $subscriptions[$row['category']] = $row;
    }
    $stmt->close();

    // 获取所有分类
    $categories = ['店长', '教练', '顾问', '督导 / 总经理', '通用'];
    $workflows = ['门店运营链', '会员服务链', '教学与上岗链'];

    $result_data = [];
    foreach ($categories as $cat) {
        if (!isset($subscriptions[$cat])) {
            $subscriptions[$cat] = [
                'category' => $cat,
                'subscribe_update' => 1,
                'subscribe_reminder' => 1,
                'enabled' => 1
            ];
        }
        $result_data[] = [
            'type' => 'category',
            'name' => $cat,
            'subscribe_update' => (int)$subscriptions[$cat]['subscribe_update'],
            'subscribe_reminder' => (int)$subscriptions[$cat]['subscribe_reminder'],
            'enabled' => (int)$subscriptions[$cat]['enabled']
        ];
    }

    foreach ($workflows as $wf) {
        if (!isset($subscriptions[$wf])) {
            $subscriptions[$wf] = [
                'category' => $wf,
                'subscribe_update' => 1,
                'subscribe_reminder' => 1,
                'enabled' => 1
            ];
        }
        $result_data[] = [
            'type' => 'workflow',
            'name' => $wf,
            'subscribe_update' => (int)$subscriptions[$wf]['subscribe_update'],
            'subscribe_reminder' => (int)$subscriptions[$wf]['subscribe_reminder'],
            'enabled' => (int)$subscriptions[$wf]['enabled']
        ];
    }

    json_response(0, 'success', ['subscriptions' => $result_data]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = isset($_POST['category']) ? trim($_POST['category']) : '';
    $subscribe_update = isset($_POST['subscribe_update']) ? (int)$_POST['subscribe_update'] : 1;
    $subscribe_reminder = isset($_POST['subscribe_reminder']) ? (int)$_POST['subscribe_reminder'] : 1;
    $enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : 1;

    if ($category === '') {
        json_response(400, '缺少参数：category');
    }

    // 插入或更新
    $sql = "INSERT INTO policy_subscriptions (user_id, category, subscribe_update, subscribe_reminder, enabled)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
            subscribe_update = VALUES(subscribe_update),
            subscribe_reminder = VALUES(subscribe_reminder),
            enabled = VALUES(enabled)";

    $stmt = $db->prepare($sql);
    $stmt->bind_param('isiii', $user_id, $category, $subscribe_update, $subscribe_reminder, $enabled);
    $stmt->execute();
    $stmt->close();

    json_response(0, 'success');
}

$db->close();