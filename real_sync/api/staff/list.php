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
    $stores = $db->query("SELECT id, name FROM stores WHERE status = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
    jsonSuccess(['stores' => $stores]);
}

jsonError(400, '未知类型');
