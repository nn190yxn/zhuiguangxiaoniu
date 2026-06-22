<?php
declare(strict_types=1);

require_once __DIR__ . '/_common.php';

handleCORS();

try {
    $context = appRequireStaffContext();
    if (!appCanEditAll($context)) {
        appJsonError(403, '无权限查看提醒任务');
    }

    $pdo = reminderDb();
    reminderEnsureSchema($pdo);

    $input = $_GET;
    $reportDate = appOptionalString($input, 'date', reminderNow()->format('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate)) {
        appJsonError(400, '日期格式必须为YYYY-MM-DD');
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

    appLogEvent('reminder.jobs_list', ['staff_id' => $context['staff_id'] ?? null, 'date' => $reportDate, 'rule_code' => $ruleCode, 'status' => $status]);
    appJsonSuccess([
        'filters' => [
            'date' => $reportDate,
            'rule_code' => $ruleCode,
            'status' => $status,
        ],
        'summary' => array_values($summary),
        'list' => $rows,
    ]);
} catch (Throwable $e) {
    appLogEvent('reminder.jobs_error', ['error' => $e->getMessage()]);
    appJsonError(500, '获取提醒任务失败');
}
