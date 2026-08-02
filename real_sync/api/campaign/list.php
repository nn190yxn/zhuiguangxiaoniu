<?php
declare(strict_types=1);
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../kernel/bootstrap.php';
handleCORS();

$context = platformApiContext(['domain' => 'content', 'action' => 'content.campaign.list']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'GET') {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 请求');
}

$auth = platformApiAuthContext();
$auth->requireAuthenticated();
$context = $context->withActor($auth->userId(), $auth->staffId());
$pdo = getDB();
platformRequireMigrationReadiness($pdo, ['202607310009']);
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

$result = ['entries' => $rows, 'channels' => $chRows];
$migration = PlatformBusinessDomainRegistry::get('content');
$result = PlatformApiCompatibility::withMetadata($result, $migration['endpoint_version'], $migration['capabilities']);
$logger->log('info', 'content.campaign.list', $context, ['date' => $date, 'store' => $store, 'role' => $role]);
platformApiResponse($context, $result)->send();
