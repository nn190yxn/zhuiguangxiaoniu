<?php
/**
 * 通关证书API
 * GET/POST /api/drill/certificates.php
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

    if (!$userId) {
        jsonResponse(401, '请先登录');
    }

    $action = isset($_GET['action']) ? $_GET['action'] : 'list';

    switch ($action) {
        case 'list':
            getMyCertificates($db, $userId);
            break;
        case 'verify':
            verifyCertificate($db);
            break;
        case 'check_eligible':
            checkEligible($db, $userId);
            break;
        case 'issue':
            issueCertificate($db, $userId);
            break;
        default:
            jsonResponse(1, '未知操作');
    }

} catch (Exception $e) {
    error_log('certificates error: ' . $e->getMessage());
    jsonResponse(1, '服务器错误');
}

function getMyCertificates($db, $userId) {
    $stmt = $db->prepare("
        SELECT c.*, u.user_login
        FROM certificates c
        LEFT JOIN wp_users u ON c.user_id = u.ID
        WHERE c.user_id = ?
        ORDER BY c.issue_date DESC
    ");
    $stmt->execute([$userId]);
    $certificates = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(0, 'success', ['certificates' => $certificates]);
}

function verifyCertificate($db) {
    $code = isset($_GET['code']) ? $_GET['code'] : '';

    if (empty($code)) {
        jsonResponse(1, '缺少证书编号');
    }

    $stmt = $db->prepare("
        SELECT c.*, u.user_login, u.display_name
        FROM certificates c
        LEFT JOIN wp_users u ON c.user_id = u.ID
        WHERE c.certificate_code = ? OR c.verify_code = ?
    ");
    $stmt->execute([$code, $code]);
    $cert = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cert) {
        jsonResponse(1, '证书不存在');
    }

    $cert['is_valid'] = $cert['status'] === 'active';
    $cert['is_expired'] = $cert['expiry_date'] && strtotime($cert['expiry_date']) < time();

    jsonResponse(0, 'success', $cert);
}

function checkEligible($db, $userId) {
    // 检查用户是否有资格获得任何证书
    // 获取用户已完成的模块
    $stmt = $db->prepare("
        SELECT tm.id, tm.module_name, tm.module_code,
               COUNT(up.id) as completed_cards,
               tm.total_cards
        FROM training_modules tm
        LEFT JOIN user_progress up ON tm.id = up.module_id AND up.user_id = ? AND up.status IN ('completed', 'passed')
        WHERE tm.status = 1
        GROUP BY tm.id
        HAVING completed_cards >= tm.total_cards AND tm.total_cards > 0
    ");
    $stmt->execute([$userId]);
    $completedModules = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 检查已有证书
    $certStmt = $db->prepare("SELECT module_id, certificate_code FROM certificates WHERE user_id = ?");
    $certStmt->execute([$userId]);
    $existingCerts = $certStmt->fetchAll(PDO::FETCH_ASSOC);
    $certifiedModuleIds = array_column($existingCerts, 'module_id');

    // 可颁证的模块
    $eligible = [];
    foreach ($completedModules as $module) {
        if (!in_array($module['id'], $certifiedModuleIds)) {
            $eligible[] = $module;
        }
    }

    jsonResponse(0, 'success', [
        'eligible_modules' => $eligible,
        'completed_count' => count($completedModules),
        'certified_count' => count($existingCerts)
    ]);
}

function issueCertificate($db, $userId) {
    $moduleId = isset($_GET['module_id']) ? (int)$_GET['module_id'] : 0;

    if ($moduleId <= 0) {
        jsonResponse(1, '缺少模块ID');
    }

    // 获取模块信息
    $moduleStmt = $db->prepare("SELECT * FROM training_modules WHERE id = ?");
    $moduleStmt->execute([$moduleId]);
    $module = $moduleStmt->fetch(PDO::FETCH_ASSOC);

    if (!$module) {
        jsonResponse(1, '模块不存在');
    }

    // 检查是否已完成
    $progressStmt = $db->prepare("
        SELECT COUNT(*) as completed
        FROM user_progress
        WHERE user_id = ? AND module_id = ? AND status IN ('completed', 'passed')
    ");
    $progressStmt->execute([$userId, $moduleId]);
    $completed = $progressStmt->fetchColumn();

    if ($completed < $module['total_cards']) {
        jsonResponse(1, '模块未完成，无法颁证');
    }

    // 检查是否已有证书
    $existCertStmt = $db->prepare("SELECT id FROM certificates WHERE user_id = ? AND module_id = ?");
    $existCertStmt->execute([$userId, $moduleId]);
    if ($existCertStmt->fetch()) {
        jsonResponse(1, '该模块证书已存在');
    }

    // 生成证书
    $certCode = 'ZGN-' . date('Ymd') . '-' . strtoupper(substr(md5($userId . $moduleId . time()), 0, 8));
    $verifyCode = strtoupper(substr(md5(time() . rand()), 0, 10));

    $insertStmt = $db->prepare("
        INSERT INTO certificates (user_id, module_id, certificate_code, certificate_name, certificate_type, role_code, level, issue_date, verify_code)
        VALUES (?, ?, ?, ?, 'training_module', ?, ?, CURDATE(), ?)
    ");

    $certName = $module['module_name'] . '通关证书';
    $level = $module['level'] ?? 'beginner';

    $insertStmt->execute([
        $userId, $moduleId, $certCode, $certName,
        $module['role_code'] ?? null, $level, $verifyCode
    ]);

    jsonResponse(0, 'success', [
        'certificate_id' => $db->lastInsertId(),
        'certificate_code' => $certCode,
        'certificate_name' => $certName
    ]);
}
