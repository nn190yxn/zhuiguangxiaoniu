<?php
/**
 * 推送通知API
 * GET /api/policy/notify.php - 获取用户通知列表
 * POST /api/policy/notify.php - 发送通知（管理员）
 * POST /api/policy/notify.php?action=read - 标记已读
 * POST /api/policy/notify.php?action=confirm - 确认阅读
 */

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$db = getDB();
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
        $user = getJwtCurrentUser();
        if (!$user) {
            json_response(401, '未登录');
        }

        $user_id = $user['user_id'];
        $page = max(1, isset($_GET['page']) ? (int)$_GET['page'] : 1);
        $page_size = min(50, max(1, isset($_GET['page_size']) ? (int)$_GET['page_size'] : 20));
        $offset = ($page - 1) * $page_size;
        $unread_only = isset($_GET['unread']) && $_GET['unread'] == 1;

        $where = "n.user_id = ?";
        $params = [$user_id];

        if ($unread_only) {
            $where .= " AND n.is_read = 0";
        }

        $count_sql = "SELECT COUNT(*) as total FROM policy_notifications n WHERE $where";
        $stmt = $db->prepare($count_sql);
        $stmt->execute([$user_id]);
        $total = $stmt->fetchColumn();

        $unread_sql = "SELECT COUNT(*) as unread FROM policy_notifications WHERE user_id = ? AND is_read = 0";
        $stmt = $db->prepare($unread_sql);
        $stmt->execute([$user_id]);
        $unread = $stmt->fetchColumn();

        $list_sql = "SELECT n.id, n.type, n.title, n.content, n.is_read, n.is_confirmed, n.created_at,
                            p.id as policy_id, p.doc_key, p.title as policy_title
                     FROM policy_notifications n
                     LEFT JOIN policies p ON n.policy_id = p.id
                     WHERE $where
                     ORDER BY n.created_at DESC LIMIT ? OFFSET ?";

        $params[] = $page_size;
        $params[] = $offset;

        $stmt = $db->prepare($list_sql);
        $stmt->execute($params);

        $list = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['created_at'] = date('Y-m-d H:i', strtotime($row['created_at']));
            $list[] = $row;
        }

        json_response(0, 'success', [
            'list' => $list,
            'unread_count' => (int)$unread,
            'pagination' => [
                'page' => $page,
                'page_size' => $page_size,
                'total' => (int)$total,
                'total_pages' => ceil($total / $page_size)
            ]
        ]);
        break;

    case 'read':
        $user = getJwtCurrentUser();
        if (!$user) {
            json_response(401, '未登录');
        }

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) {
            json_response(400, '缺少参数：id');
        }

        $user_id = $user['user_id'];
        $sql = "UPDATE policy_notifications SET is_read = 1, read_at = NOW() WHERE id = ? AND user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$id, $user_id]);
        $affected = $stmt->rowCount();

        json_response(0, 'success', ['affected' => $affected]);
        break;

    case 'confirm':
        $user = getJwtCurrentUser();
        if (!$user) {
            json_response(401, '未登录');
        }

        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        if ($id <= 0) {
            json_response(400, '缺少参数：id');
        }

        $user_id = $user['user_id'];

        $notify_sql = "SELECT policy_id FROM policy_notifications WHERE id = ? AND user_id = ?";
        $stmt = $db->prepare($notify_sql);
        $stmt->execute([$id, $user_id]);
        $notify = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$notify) {
            json_response(404, '通知不存在');
        }

        $update_sql = "UPDATE policy_notifications SET is_confirmed = 1, confirmed_at = NOW() WHERE id = ? AND user_id = ?";
        $stmt = $db->prepare($update_sql);
        $stmt->execute([$id, $user_id]);
        $affected = $stmt->rowCount();

        if ($affected === 0) {
            json_response(404, '通知不存在或无权确认');
        }

        $insert_sql = "INSERT INTO policy_read_history (policy_id, user_id) VALUES (?, ?)";
        $stmt = $db->prepare($insert_sql);
        $stmt->execute([$notify['policy_id'], $user_id]);

        json_response(0, 'success');
        break;

    case 'send':
        $user = getJwtCurrentUser();
        if (!$user || $user['role'] !== 'admin') {
            json_response(403, '无权限');
        }

        $policy_id = isset($_POST['policy_id']) ? (int)$_POST['policy_id'] : 0;
        $user_ids = isset($_POST['user_ids']) ? $_POST['user_ids'] : [];
        $type = isset($_POST['type']) ? trim($_POST['type']) : 'update';
        $title = isset($_POST['title']) ? trim($_POST['title']) : '';
        $content = isset($_POST['content']) ? trim($_POST['content']) : '';

        if ($policy_id <= 0) {
            json_response(400, '缺少参数：policy_id');
        }

        $policy_sql = "SELECT title FROM policies WHERE id = ?";
        $stmt = $db->prepare($policy_sql);
        $stmt->execute([$policy_id]);
        $policy = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$policy) {
            json_response(404, '制度不存在');
        }

        if (empty($title)) {
            $title = '制度更新通知';
        }

        $insert_sql = "INSERT INTO policy_notifications (policy_id, user_id, type, title, content) VALUES (?, ?, ?, ?, ?)";
        $stmt = $db->prepare($insert_sql);

        $count = 0;
        foreach ($user_ids as $uid) {
            $stmt->execute([$policy_id, $uid, $type, $title, $content]);
            $count++;
        }

        json_response(0, 'success', ['sent_count' => $count]);
        break;

    default:
        json_response(400, '未知操作');
}
