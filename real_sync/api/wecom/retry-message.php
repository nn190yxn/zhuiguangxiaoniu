<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/admin/common.php';
require_once dirname(__DIR__) . '/reminder/_common.php';
require_once __DIR__ . '/_common.php';

header('Content-Type: application/json; charset=utf-8');
handleCORS();
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    jsonResponse(1, '不支持的请求方法');
}

try {
    [, $user, $staff] = adminRequireAuth('adminCanAccessHeadquarter');
    $input = adminJsonInput();
    $logId = isset($input['log_id']) ? (int)$input['log_id'] : 0;
    if ($logId <= 0) {
        jsonResponse(1, '缺少 log_id');
    }

    $pdo = wecomDb();
    wecomEnsureSchema($pdo);
    reminderEnsureSchema($pdo);

    $stmt = $pdo->prepare('SELECT * FROM wecom_message_logs WHERE id = ? LIMIT 1');
    $stmt->execute([$logId]);
    $messageLog = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$messageLog) {
        jsonResponse(1, '消息日志不存在');
    }
    if ((string)($messageLog['source_type'] ?? '') !== 'reminder') {
        jsonResponse(1, '当前只支持重发提醒消息');
    }
    if ((string)($messageLog['status'] ?? '') === 'sent') {
        jsonResponse(1, '该消息已经发送成功');
    }
    $sourceJobId = (int)($messageLog['source_job_id'] ?? 0);
    if ($sourceJobId <= 0) {
        jsonResponse(1, '该消息缺少 source_job_id，当前无法重发');
    }

    $result = reminderRetryWecomDispatch($pdo, $sourceJobId);

    adminRecordOperation($pdo, $user, $staff, [
        'module' => 'wecom',
        'action' => 'retry_message',
        'target_type' => 'wecom_message_log',
        'target_id' => (string)$logId,
        'before' => [
            'status' => (string)($messageLog['status'] ?? ''),
            'source_job_id' => $sourceJobId,
        ],
        'after' => $result,
    ]);

    jsonResponse(0, '重发已执行', $result);
} catch (Throwable $e) {
    jsonResponse(1, $e->getMessage() !== '' ? $e->getMessage() : '服务器错误');
}
