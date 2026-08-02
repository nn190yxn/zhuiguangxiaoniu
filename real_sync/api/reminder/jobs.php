<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';
require_once dirname(__DIR__) . '/admin/common.php';
require_once dirname(__DIR__) . '/kernel/bootstrap.php';
require_once dirname(__DIR__) . '/platform/JobQueue.php';

handleCORS();

$context = platformApiContext(['domain' => 'reminder', 'action' => 'reminder.jobs']);
$logger = new PlatformApiLogger();
platformApiInstallExceptionHandler($context, $logger);
$allowedMethods = ['GET', 'POST'];
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($method, $allowedMethods, true)) {
    throw new PlatformApiException(405, 'method_not_allowed', '仅支持 GET 或 POST 请求');
}

$auth = platformApiAuthContext();
$auth->requirePermission('reminder.manage');
$context = $context->withActor($auth->userId(), $auth->staffId());
$pdo = reminderDb();
reminderEnsureSchema($pdo);
platformRequireMigrationReadiness($pdo, ['202607310010', '202607310012']);
$migration = PlatformBusinessDomainRegistry::get('reminder');

if ($method === 'POST') {
    $input = getRequestInput();
    $reportDate = appOptionalString($input, 'date', reminderNow()->format('Y-m-d'));
    $phase = appOptionalString($input, 'phase', 'first');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        throw new PlatformApiException(400, 'date_invalid', '日期格式必须为YYYY-MM-DD');
    }
    if (!in_array($phase, ['learning_required', 'first', 'second', 'store_summary', 'hq_summary'], true)) {
        throw new PlatformApiException(400, 'phase_invalid', '提醒阶段无效');
    }

    $idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
    if ($idempotencyKey === '') {
        $idempotencyKey = hash('sha256', 'reminder.schedule.tick:' . $reportDate . ':' . $phase);
    }
    $pdo->beginTransaction();
    try {
        $queue = new PlatformJobQueueService(new PlatformPdoJobQueueStore($pdo));
        $job = $queue->enqueue(
            'reminder.schedule.tick',
            'reminder_schedule',
            $reportDate . ':' . $phase,
            $idempotencyKey,
            ['report_date' => $reportDate, 'phase' => $phase],
            10,
            3
        );
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }

    $result = PlatformApiCompatibility::withMetadata([
        'job_id' => (int)$job['id'],
        'status' => (string)$job['status'],
        'date' => $reportDate,
        'phase' => $phase,
    ], $migration['endpoint_version'], $migration['capabilities']);
    $logger->log('info', 'reminder.jobs.manual_run', $context, [
        'job_id' => (int)$job['id'],
        'date' => $reportDate,
        'phase' => $phase,
    ]);
    platformApiResponse($context, $result, '提醒任务已入队')->send();
}

$input = $method === 'GET' ? $_GET : [];
$reportDate = appOptionalString($input, 'date', reminderNow()->format('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
    throw new PlatformApiException(400, 'date_invalid', '日期格式必须为YYYY-MM-DD');
}
$ruleCode = appOptionalString($input, 'rule_code', '');
$status = appOptionalString($input, 'status', '');

$where = ['j.reminder_date = ?'];
$params = [$reportDate];
if ($ruleCode !== '') {
    $where[] = 'j.rule_code = ?';
    $params[] = $ruleCode;
}
if ($status !== '') {
    $where[] = 'j.status = ?';
    $params[] = $status;
}

$sql = "SELECT j.id, j.reminder_date, j.rule_code, j.target_user_id, j.target_staff_id, j.target_store_id,
            j.target_role_code, j.target_name, j.type, j.title, j.content, j.status,
            j.channel_station_status, j.channel_wechat_status, j.channel_wechat_note,
            j.notification_id, j.sent_at, j.last_error, j.created_at, st.name AS store_name
        FROM mini_reminder_jobs j
        LEFT JOIN stores st ON st.id = j.target_store_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY j.rule_code ASC, j.target_store_id ASC, j.target_staff_id ASC, j.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$summary = [];
foreach ($rows as $row) {
    $code = (string)($row['rule_code'] ?? '');
    if (!isset($summary[$code])) {
        $summary[$code] = [
            'rule_code' => $code,
            'total' => 0,
            'sent' => 0,
            'failed' => 0,
            'pending' => 0,
        ];
    }
    $summary[$code]['total']++;
    $jobStatus = (string)($row['status'] ?? 'pending');
    if (isset($summary[$code][$jobStatus])) {
        $summary[$code][$jobStatus]++;
    }
}

$result = PlatformApiCompatibility::withMetadata([
    'filters' => [
        'date' => $reportDate,
        'rule_code' => $ruleCode,
        'status' => $status,
    ],
    'summary' => array_values($summary),
    'list' => $rows,
], $migration['endpoint_version'], $migration['capabilities']);
$logger->log('info', 'reminder.jobs.list', $context, [
    'date' => $reportDate,
    'rule_code' => $ruleCode,
    'status' => $status,
    'count' => count($rows),
]);
platformApiResponse($context, $result)->send();
