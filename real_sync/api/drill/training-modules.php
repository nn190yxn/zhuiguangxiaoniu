<?php
/** Training module API. */
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

try {
    $userId = getCurrentUserId();
    $context = requireTrainingAccessContext();
    if ($userId !== (int)$context['user_id']) { jsonResponse(401, '请先登录'); }
    $db = getDB();
    $action = $_GET['action'] ?? 'list';
    switch ($action) {
        case 'list': getModules($db, $context); break;
        case 'detail': getModuleDetail($db, $context); break;
        case 'cards': getModuleCards($db, $context); break;
        case 'my_progress': getMyProgress($db, $context); break;
        default: jsonResponse(1, '未知操作');
    }
} catch (Exception $e) {
    error_log('training-modules error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}

function getModules($db, array $context) {
    $access = getTrainingModuleAccessSql($context, 'tm');
    $requestedRole = trim((string)($_GET['role'] ?? ''));
    $params = $access['params'];
    $sql = "SELECT tm.* FROM training_modules tm WHERE tm.status = 1 AND {$access['sql']}";
    if ($requestedRole !== '' && !empty($context['is_management'])) {
        $sql .= " AND (tm.role_code IS NULL OR tm.role_code = '' OR tm.role_code = ?)";
        $params[] = TrainingAccessPolicy::moduleRoleForStaff($requestedRole);
    }
    $sql .= ' ORDER BY tm.sort_order, tm.id';
    $stmt = $db->prepare($sql); $stmt->execute($params);
    $modules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($modules as &$module) {
        $cardStmt = $db->prepare('SELECT COUNT(*) FROM training_cards WHERE module_id = ? AND status = 1');
        $cardStmt->execute([$module['id']]);
        $module['card_count'] = (int)$cardStmt->fetchColumn();
        $progressStmt = $db->prepare("SELECT COUNT(*) completed, SUM(CASE WHEN status = 'passed' THEN 1 ELSE 0 END) passed FROM user_progress WHERE user_id = ? AND module_id = ?");
        $progressStmt->execute([$context['user_id'], $module['id']]);
        $progress = $progressStmt->fetch(PDO::FETCH_ASSOC);
        $module['completed_count'] = (int)($progress['completed'] ?? 0);
        $module['passed_count'] = (int)($progress['passed'] ?? 0);
        $module['progress_percent'] = $module['card_count'] > 0 ? round($module['completed_count'] / $module['card_count'] * 100) : 0;
    }
    jsonResponse(0, 'success', ['modules' => $modules]);
}

function loadAuthorizedTrainingModule($db, array $context, $moduleId) {
    $stmt = $db->prepare('SELECT * FROM training_modules WHERE id = ? AND status = 1');
    $stmt->execute([(int)$moduleId]);
    $module = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$module) { jsonResponse(404, '培训资源不存在'); }
    requireTrainingModuleAccess($context, $module);
    return $module;
}

function getModuleDetail($db, array $context) {
    $moduleId = (int)($_GET['id'] ?? 0);
    if ($moduleId <= 0) { jsonResponse(1, '缺少模块ID'); }
    $module = loadAuthorizedTrainingModule($db, $context, $moduleId);
    $stmt = $db->prepare('SELECT card_type, COUNT(*) cnt FROM training_cards WHERE module_id = ? AND status = 1 GROUP BY card_type');
    $stmt->execute([$moduleId]);
    $module['type_counts'] = ['K'=>0,'S'=>0,'D'=>0,'C'=>0];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) { $module['type_counts'][$row['card_type']] = (int)$row['cnt']; }
    $stmt = $db->prepare("SELECT COUNT(*) completed, SUM(best_score) total_score, MAX(best_score) best_score FROM user_progress WHERE user_id = ? AND module_id = ? AND status IN ('completed','passed')");
    $stmt->execute([$context['user_id'], $moduleId]);
    $module['my_progress'] = $stmt->fetch(PDO::FETCH_ASSOC);
    jsonResponse(0, 'success', $module);
}

function getModuleCards($db, array $context) {
    $moduleId = (int)($_GET['id'] ?? 0);
    if ($moduleId <= 0) { jsonResponse(1, '缺少模块ID'); }
    loadAuthorizedTrainingModule($db, $context, $moduleId);
    $type = $_GET['type'] ?? null;
    $sql = 'SELECT tc.*, up.status my_status, up.best_score my_score, up.attempts my_attempts FROM training_cards tc LEFT JOIN user_progress up ON tc.id = up.card_id AND up.user_id = ? WHERE tc.module_id = ? AND tc.status = 1';
    $params = [$context['user_id'], $moduleId];
    if ($type) { $sql .= ' AND tc.card_type = ?'; $params[] = $type; }
    $sql .= ' ORDER BY tc.sort_order, tc.id';
    $stmt = $db->prepare($sql); $stmt->execute($params); $cards = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($cards as &$card) { $card['options'] = $card['options'] ? json_decode($card['options'], true) : []; }
    jsonResponse(0, 'success', ['cards' => $cards]);
}

function getMyProgress($db, array $context) {
    $access = getTrainingModuleAccessSql($context, 'tm');
    $params = array_merge([$context['user_id']], $access['params']);
    $stmt = $db->prepare("SELECT up.*, tc.title, tc.card_type, tm.module_name FROM user_progress up JOIN training_cards tc ON up.card_id = tc.id JOIN training_modules tm ON up.module_id = tm.id WHERE up.user_id = ? AND tc.status = 1 AND tm.status = 1 AND {$access['sql']} ORDER BY up.updated_at DESC LIMIT 50");
    $stmt->execute($params);
    jsonResponse(0, 'success', ['progress' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}
