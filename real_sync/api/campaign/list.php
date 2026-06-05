<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
handleCORS();

// Auth check
$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(401, '请先登录');
}

handleCORS();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = getDB();
    $date = $_GET['date'] ?? '';
    $store = $_GET['store'] ?? '';
    $role = $_GET['role'] ?? '';

    $sql = "SELECT * FROM campaign_daily_entries WHERE 1=1";
    $params = [];

    if ($date !== '') {
        $sql .= " AND entry_date = ?";
        $params[] = $date;
    }
    if ($store !== '') {
        $sql .= " AND store = ?";
        $params[] = $store;
    }
    if ($role !== '') {
        $sql .= " AND role_type = ?";
        $params[] = $role;
    }

    $sql .= " ORDER BY entry_date DESC, store, role_type";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also fetch channel entries
    $chSql = "SELECT * FROM campaign_channel_entries WHERE 1=1";
    $chParams = [];
    if ($date !== '') {
        $chSql .= " AND entry_date = ?";
        $chParams[] = $date;
    }
    if ($store !== '') {
        $chSql .= " AND store = ?";
        $chParams[] = $store;
    }
    $chSql .= " ORDER BY entry_date DESC, store, channel";
    $chStmt = $pdo->prepare($chSql);
    $chStmt->execute($chParams);
    $chRows = $chStmt->fetchAll(PDO::FETCH_ASSOC);

    jsonSuccess([
        'entries' => $rows,
        'channels' => $chRows,
    ]);
} catch (Throwable $e) {
    error_log('Campaign list error: ' . $e->getMessage());
    jsonError(500, '获取数据失败：' . $e->getMessage());
}
