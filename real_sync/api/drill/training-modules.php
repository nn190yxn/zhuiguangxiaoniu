<?php
/**
 * 训练模块API
 * GET /api/drill/training-modules.php
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

try {
    $db = getDB();
    $userId = getCurrentUserId();

    $action = isset($_GET['action']) ? $_GET['action'] : 'list';

    switch ($action) {
        case 'list':
            getModules($db, $userId);
            break;
        case 'detail':
            getModuleDetail($db, $userId);
            break;
        case 'cards':
            getModuleCards($db, $userId);
            break;
        case 'my_progress':
            getMyProgress($db, $userId);
            break;
        default:
            jsonResponse(1, '未知操作');
    }

} catch (Exception $e) {
    error_log('training-modules error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}

function getModules($db, $userId) {
    $user = getJwtCurrentUser();
    $effectiveRole = getTrainingModuleRoleCode(getEffectiveStaffRole($user));
    $requestedRole = isset($_GET['role']) ? trim((string)$_GET['role']) : '';
    if ($requestedRole !== '' && isJwtManager($user)) {
        $roleCode = getTrainingModuleRoleCode($requestedRole);
    } else {
        $roleCode = $effectiveRole;
    }

    $sql = "SELECT * FROM training_modules WHERE status = 1";
    if ($roleCode) {
        $sql .= " AND (role_code IS NULL OR role_code = '' OR role_code = ?)";
    }
    $sql .= " ORDER BY sort_order, id";

    if ($roleCode) {
        $stmt = $db->prepare($sql);
        $stmt->execute([$roleCode]);
    } else {
        $stmt = $db->query($sql);
    }
    $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($modules as &$module) {
        // 获取卡片数量
        $cardStmt = $db->prepare("SELECT COUNT(*) FROM training_cards WHERE module_id = ? AND status = 1");
        $cardStmt->execute([$module['id']]);
        $module['card_count'] = (int)$cardStmt->fetchColumn();

        // 获取用户进度
        $progressStmt = $db->prepare("
            SELECT COUNT(*) as completed,
                   SUM(CASE WHEN status = 'passed' THEN 1 ELSE 0 END) as passed
            FROM user_progress
            WHERE user_id = ? AND module_id = ?
        ");
        $progressStmt->execute([$userId, $module['id']]);
        $progress = $progressStmt->fetch(PDO::FETCH_ASSOC);
        $module['completed_count'] = (int)($progress['completed'] ?? 0);
        $module['passed_count'] = (int)($progress['passed'] ?? 0);
        $module['progress_percent'] = $module['card_count'] > 0
            ? round($module['completed_count'] / $module['card_count'] * 100)
            : 0;
    }

    jsonResponse(0, 'success', ['modules' => $modules]);
}

function getModuleDetail($db, $userId) {
    $moduleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($moduleId <= 0) {
        jsonResponse(1, '缺少模块ID');
    }

    $stmt = $db->prepare("SELECT * FROM training_modules WHERE id = ? AND status = 1");
    $stmt->execute([$moduleId]);
    $module = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$module) {
        jsonResponse(1, '模块不存在');
    }

    // 获取卡片统计
    $cardStmt = $db->prepare("
        SELECT card_type, COUNT(*) as cnt
        FROM training_cards
        WHERE module_id = ? AND status = 1
        GROUP BY card_type
    ");
    $cardStmt->execute([$moduleId]);
    $typeStats = $cardStmt->fetchAll(PDO::FETCH_ASSOC);

    $typeCount = ['K' => 0, 'S' => 0, 'D' => 0, 'C' => 0];
    foreach ($typeStats as $stat) {
        $typeCount[$stat['card_type']] = (int)$stat['cnt'];
    }
    $module['type_counts'] = $typeCount;

    // 获取用户总体进度
    $progressStmt = $db->prepare("
        SELECT COUNT(*) as completed,
               SUM(best_score) as total_score,
               MAX(best_score) as best_score
        FROM user_progress
        WHERE user_id = ? AND module_id = ? AND status IN ('completed', 'passed')
    ");
    $progressStmt->execute([$userId, $moduleId]);
    $module['my_progress'] = $progressStmt->fetch(PDO::FETCH_ASSOC);

    jsonResponse(0, 'success', $module);
}

function getModuleCards($db, $userId) {
    $moduleId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $type = isset($_GET['type']) ? $_GET['type'] : null;

    if ($moduleId <= 0) {
        jsonResponse(1, '缺少模块ID');
    }

    $sql = "SELECT tc.*, up.status as my_status, up.best_score as my_score, up.attempts as my_attempts
            FROM training_cards tc
            LEFT JOIN user_progress up ON tc.id = up.card_id AND up.user_id = ?
            WHERE tc.module_id = ? AND tc.status = 1";
    $params = [$userId, $moduleId];
    if ($type) {
        $sql .= " AND tc.card_type = ?";
        $params[] = $type;
    }
    $sql .= " ORDER BY tc.sort_order, tc.id";

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 处理选项JSON
    foreach ($cards as &$card) {
        if ($card['options']) {
            $card['options'] = json_decode($card['options'], true);
        } else {
            $card['options'] = [];
        }
    }

    jsonResponse(0, 'success', ['cards' => $cards]);
}

function getMyProgress($db, $userId) {
    $stmt = $db->prepare("
        SELECT up.*, tc.title, tc.card_type, tm.module_name
        FROM user_progress up
        JOIN training_cards tc ON up.card_id = tc.id
        JOIN training_modules tm ON up.module_id = tm.id
        WHERE up.user_id = ?
        ORDER BY up.updated_at DESC
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $progress = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(0, 'success', ['progress' => $progress]);
}
