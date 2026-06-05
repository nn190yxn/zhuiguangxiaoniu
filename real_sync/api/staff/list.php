<?php
/**
 * Staff list API - returns stores and staff options
 */
require_once __DIR__ . '/../config.php';
handleCORS();
$type = $_GET['type'] ?? '';

// Auth check for non-store requests
if ($type !== 'stores') {
    $userId = getCurrentUserId();
    if (!$userId) {
        jsonError(401, '请先登录');
    }
}

$db = getDB();

if ($type === 'stores') {
    $stores = $db->query("SELECT id, name FROM stores ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    jsonSuccess(['stores' => $stores]);
}

jsonError(400, '未知类型');
