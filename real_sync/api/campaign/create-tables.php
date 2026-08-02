<?php
/**
 * 7周年庆数据看板 - 创建数据库表
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
handleCORS();

// Auth + admin check
$user = getJwtCurrentUser();
if (!$user || !in_array($user['role'] ?? '', ['admin', 'manager'], true)) {
    jsonResponse(403, '无权限访问');
}

try {
    $pdo = getDB();
    platformRequireMigrationReadiness($pdo, ['202607310009']);
    echo json_encode(['code' => 0, 'message' => '数据库结构已就绪'], JSON_UNESCAPED_UNICODE);
} catch (PlatformApiException $e) {
    jsonResponse($e->httpStatus(), $e->getMessage(), $e->errorData());
} catch (Exception $e) {
    jsonResponse(500, '结构检查失败');
}
