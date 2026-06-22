<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/workload/_common.php';

function reminderDb(): PDO {
    return workloadDb();
}

function reminderNow(): DateTimeImmutable {
    return new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai'));
}

function reminderEnsureSchema(PDO $pdo): void {
    workloadEnsureSchema($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS mini_reminder_rules (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        rule_code VARCHAR(64) NOT NULL,
        rule_name VARCHAR(128) NOT NULL,
        scene_code VARCHAR(32) NOT NULL,
        channel_code VARCHAR(32) NOT NULL DEFAULT 'station+wechat',
        recipient_scope VARCHAR(32) NOT NULL,
        target_roles VARCHAR(255) NOT NULL DEFAULT '',
        schedule_time CHAR(5) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        config_json JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_rule_code (rule_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mini_reminder_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        reminder_date DATE NOT NULL,
        rule_code VARCHAR(64) NOT NULL,
        target_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        target_staff_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        target_store_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        target_role_code VARCHAR(32) NOT NULL DEFAULT '',
        target_name VARCHAR(128) NOT NULL DEFAULT '',
        type VARCHAR(32) NOT NULL DEFAULT 'reminder',
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        payload_json JSON DEFAULT NULL,
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        channel_station_status VARCHAR(16) NOT NULL DEFAULT 'pending',
        channel_wechat_status VARCHAR(16) NOT NULL DEFAULT 'pending',
        channel_wechat_note VARCHAR(255) NOT NULL DEFAULT '',
        notification_id BIGINT UNSIGNED DEFAULT NULL,
        sent_at DATETIME DEFAULT NULL,
        last_error VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_target_job (reminder_date, rule_code, target_user_id, target_staff_id, target_store_id),
        KEY idx_status_date (status, reminder_date),
        KEY idx_rule_date (rule_code, reminder_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mini_user_subscriptions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        staff_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
        scene_code VARCHAR(32) NOT NULL,
        template_key VARCHAR(64) NOT NULL,
        openid VARCHAR(128) NOT NULL DEFAULT '',
        accept_status VARCHAR(16) NOT NULL DEFAULT 'unknown',
        extra_json JSON DEFAULT NULL,
        granted_at DATETIME DEFAULT NULL,
        last_seen_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_user_template (user_id, template_key),
        KEY idx_scene_user (scene_code, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS mini_user_notifications (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT UNSIGNED NOT NULL,
        type VARCHAR(32) NOT NULL DEFAULT 'reminder',
        title VARCHAR(255) NOT NULL,
        content TEXT NOT NULL,
        policy_id BIGINT UNSIGNED DEFAULT NULL,
        source_type VARCHAR(32) NOT NULL DEFAULT 'reminder',
        source_key VARCHAR(64) NOT NULL DEFAULT '',
        source_job_id BIGINT UNSIGNED DEFAULT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        is_confirmed TINYINT(1) NOT NULL DEFAULT 0,
        read_at DATETIME DEFAULT NULL,
        confirmed_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_source_job (source_type, source_job_id),
        KEY idx_user_created (user_id, created_at),
        KEY idx_user_unread (user_id, is_read)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    reminderSeedRules($pdo);
}

function reminderSeedRules(PDO $pdo): void {
    $rules = [
        ['workload_daily_first', '工作量首次提醒', 'workload', 'station+wechat', 'staff', 'sales,coach', '20:00', 1, json_encode(['phase' => 'first'], JSON_UNESCAPED_UNICODE)],
        ['workload_daily_second', '工作量二次提醒', 'workload', 'station+wechat', 'staff', 'sales,coach', '23:00', 1, json_encode(['phase' => 'second'], JSON_UNESCAPED_UNICODE)],
        ['workload_store_summary', '门店工作量汇总提醒', 'workload', 'station+wechat', 'manager', 'manager', '23:05', 1, json_encode(['phase' => 'store_summary'], JSON_UNESCAPED_UNICODE)],
        ['workload_hq_summary', '总部工作量汇总提醒', 'workload', 'station+wechat', 'headquarter', 'operation,finance,admin,ceo', '23:10', 1, json_encode(['phase' => 'hq_summary'], JSON_UNESCAPED_UNICODE)],
    ];
    $stmt = $pdo->prepare("INSERT INTO mini_reminder_rules (rule_code, rule_name, scene_code, channel_code, recipient_scope, target_roles, schedule_time, enabled, config_json)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE rule_name=VALUES(rule_name), scene_code=VALUES(scene_code), channel_code=VALUES(channel_code), recipient_scope=VALUES(recipient_scope), target_roles=VALUES(target_roles), schedule_time=VALUES(schedule_time), enabled=VALUES(enabled), config_json=VALUES(config_json)");
    foreach ($rules as $rule) {
        $stmt->execute($rule);
    }
}

function reminderParseNotificationId(string $rawId): array {
    $rawId = trim($rawId);
    if ($rawId === '') {
        return ['source' => 'policy', 'id' => 0];
    }
    if (strpos($rawId, ':') === false) {
        return ['source' => 'policy', 'id' => (int)$rawId];
    }
    [$source, $id] = explode(':', $rawId, 2);
    return ['source' => $source ?: 'policy', 'id' => (int)$id];
}

function reminderFormatNotificationId(string $source, int $id): string {
    return $source . ':' . $id;
}

function reminderNotificationSourceTable(string $source): ?string {
    if ($source === 'policy') {
        return 'policy_notifications';
    }
    if ($source === 'reminder') {
        return 'mini_user_notifications';
    }
    return null;
}

function reminderWorkloadRoleAliases(string $roleCode): array {
    if ($roleCode === 'sales') {
        return ['sales', 'consultant', 'sale', '销售', '实习销售'];
    }
    if ($roleCode === 'coach') {
        return ['coach', '教练', '实习教练'];
    }
    return [$roleCode];
}

function reminderFetchActiveWorkloadStaffs(PDO $pdo): array {
    $stmt = $pdo->query("SELECT s.id AS staff_id, s.user_id, s.name AS staff_name, s.role, s.store_id, s.openid, st.name AS store_name
        FROM staffs s
        LEFT JOIN stores st ON st.id = s.store_id
        WHERE s.status = 1
          AND s.store_id IS NOT NULL
          AND s.role IN ('sales', 'coach', 'consultant', 'sale', '销售', '教练', '实习销售', '实习教练')
        ORDER BY s.store_id ASC, s.id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function reminderFetchManagersByStore(PDO $pdo): array {
    $stmt = $pdo->query("SELECT s.id AS staff_id, s.user_id, s.name AS staff_name, s.store_id, st.name AS store_name, s.openid
        FROM staffs s
        LEFT JOIN stores st ON st.id = s.store_id
        WHERE s.status = 1 AND s.user_id IS NOT NULL AND s.user_id > 0 AND s.role IN ('manager', 'store_manager', 'shop_manager', '店长')
        ORDER BY s.store_id ASC, s.id ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $map = [];
    foreach ($rows as $row) {
        $storeId = (int)($row['store_id'] ?? 0);
        if ($storeId <= 0) {
            continue;
        }
        $map[$storeId][] = $row;
    }
    return $map;
}

function reminderFetchHeadquarterRecipients(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id AS staff_id, user_id, name AS staff_name, role, store_id, openid
        FROM staffs
        WHERE status = 1 AND user_id IS NOT NULL AND user_id > 0");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $result = [];
    foreach ($rows as $row) {
        $roleCode = appRoleCode((string)($row['role'] ?? ''));
        if (!in_array($roleCode, ['operation', 'finance', 'admin', 'ceo'], true)) {
            continue;
        }
        $row['role_code'] = $roleCode;
        $result[] = $row;
    }
    return $result;
}

function reminderWorkloadIncompleteReason(array $report = null, int $gapCount = 0): string {
    if (!$report) {
        return '未填写日报';
    }
    $status = (string)($report['submit_status'] ?? 'draft');
    if ($gapCount > 0) {
        return '缺少' . $gapCount . '项凭证';
    }
    if ($status === 'draft') {
        return '草稿未提交';
    }
    return '未完成日报';
}

function reminderFetchWorkloadIncompleteRows(PDO $pdo, string $reportDate): array {
    $staffRows = reminderFetchActiveWorkloadStaffs($pdo);
    if (!$staffRows) {
        return [];
    }

    $reportStmt = $pdo->prepare("SELECT id, report_date, store_id, staff_id, role_code, submit_status, remarks, submitted_at, updated_at
        FROM workload_daily_reports
        WHERE report_date = ? AND role_code IN ('sales', 'coach')");
    $reportStmt->execute([$reportDate]);
    $reportMap = [];
    foreach ($reportStmt->fetchAll(PDO::FETCH_ASSOC) as $report) {
        $key = (int)$report['staff_id'] . ':' . appRoleCode((string)($report['role_code'] ?? ''));
        $reportMap[$key] = $report;
    }

    $rows = [];
    foreach ($staffRows as $staff) {
        $roleCode = appRoleCode((string)($staff['role'] ?? ''));
        if (!in_array($roleCode, ['sales', 'coach'], true)) {
            continue;
        }
        $key = (int)$staff['staff_id'] . ':' . $roleCode;
        $report = $reportMap[$key] ?? null;
        $status = $report ? (string)($report['submit_status'] ?? 'draft') : 'missing';
        $gapCount = 0;
        if ($report && (int)($report['id'] ?? 0) > 0) {
            $gapCount = workloadReportEvidenceGapCount($pdo, (int)$report['id'], $roleCode);
        }
        if ($status === 'submitted' && $gapCount <= 0) {
            continue;
        }
        $rows[] = [
            'report_date' => $reportDate,
            'staff_id' => (int)$staff['staff_id'],
            'user_id' => (int)($staff['user_id'] ?? 0),
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'role_code' => $roleCode,
            'store_id' => (int)($staff['store_id'] ?? 0),
            'store_name' => (string)($staff['store_name'] ?? ''),
            'openid' => (string)($staff['openid'] ?? ''),
            'report_id' => $report ? (int)$report['id'] : 0,
            'submit_status' => $status,
            'evidence_gap_count' => $gapCount,
            'reason_text' => reminderWorkloadIncompleteReason($report, $gapCount),
        ];
    }

    return $rows;
}

function reminderBuildWorkloadJobs(PDO $pdo, string $reportDate, string $phase = 'all'): array {
    $phaseRules = [
        'first' => ['workload_daily_first'],
        'second' => ['workload_daily_second'],
        'store_summary' => ['workload_store_summary'],
        'hq_summary' => ['workload_hq_summary'],
        'all' => ['workload_daily_first', 'workload_daily_second', 'workload_store_summary', 'workload_hq_summary'],
    ];
    $selected = $phaseRules[$phase] ?? $phaseRules['all'];
    $incompleteRows = reminderFetchWorkloadIncompleteRows($pdo, $reportDate);
    $jobs = [];
    $skipped = [];

    foreach ($incompleteRows as $row) {
        if (in_array('workload_daily_first', $selected, true)) {
            if ($row['user_id'] > 0) {
                $jobs[] = [
                    'reminder_date' => $reportDate,
                    'rule_code' => 'workload_daily_first',
                    'target_user_id' => $row['user_id'],
                    'target_staff_id' => $row['staff_id'],
                    'target_store_id' => $row['store_id'],
                    'target_role_code' => $row['role_code'],
                    'target_name' => $row['staff_name'],
                    'type' => 'reminder',
                    'title' => '今晚 20:00 前请完成工作量日报',
                    'content' => $row['staff_name'] . '，你今天的工作量状态是“' . $row['reason_text'] . '”，请在今晚 20:00 前先处理，最晚当天 24:00 前完成。',
                    'payload' => $row,
                ];
            } else {
                $skipped[] = ['rule_code' => 'workload_daily_first', 'staff_id' => $row['staff_id'], 'staff_name' => $row['staff_name'], 'reason' => '未绑定 user_id'];
            }
        }
        if (in_array('workload_daily_second', $selected, true)) {
            if ($row['user_id'] > 0) {
                $jobs[] = [
                    'reminder_date' => $reportDate,
                    'rule_code' => 'workload_daily_second',
                    'target_user_id' => $row['user_id'],
                    'target_staff_id' => $row['staff_id'],
                    'target_store_id' => $row['store_id'],
                    'target_role_code' => $row['role_code'],
                    'target_name' => $row['staff_name'],
                    'type' => 'reminder',
                    'title' => '今晚 23:00 再提醒一次，请把工作量补齐',
                    'content' => $row['staff_name'] . '，你今天的工作量状态仍然是“' . $row['reason_text'] . '”，请尽快补齐，系统会按当天未完成记录汇总。',
                    'payload' => $row,
                ];
            } else {
                $skipped[] = ['rule_code' => 'workload_daily_second', 'staff_id' => $row['staff_id'], 'staff_name' => $row['staff_name'], 'reason' => '未绑定 user_id'];
            }
        }
    }

    $byStore = [];
    foreach ($incompleteRows as $row) {
        $storeId = (int)$row['store_id'];
        if ($storeId <= 0) {
            continue;
        }
        $byStore[$storeId][] = $row;
    }

    if (in_array('workload_store_summary', $selected, true)) {
        $managersByStore = reminderFetchManagersByStore($pdo);
        foreach ($byStore as $storeId => $rows) {
            $managerRows = $managersByStore[$storeId] ?? [];
            $summaryPieces = array_map(static function (array $row): string {
                return $row['staff_name'] . '（' . $row['reason_text'] . '）';
            }, $rows);
            $content = '你门店今天还有 ' . count($rows) . ' 人工作量未完成：' . implode('、', $summaryPieces) . '。请及时跟进，今天 24:00 前补齐。';
            foreach ($managerRows as $manager) {
                $jobs[] = [
                    'reminder_date' => $reportDate,
                    'rule_code' => 'workload_store_summary',
                    'target_user_id' => (int)($manager['user_id'] ?? 0),
                    'target_staff_id' => (int)($manager['staff_id'] ?? 0),
                    'target_store_id' => $storeId,
                    'target_role_code' => 'manager',
                    'target_name' => (string)($manager['staff_name'] ?? ''),
                    'type' => 'reminder',
                    'title' => ((string)($manager['store_name'] ?? '门店')) . '今日工作量待补齐汇总',
                    'content' => $content,
                    'payload' => ['store_id' => $storeId, 'store_name' => (string)($manager['store_name'] ?? ''), 'incomplete_rows' => $rows],
                ];
            }
            if (!$managerRows) {
                $skipped[] = ['rule_code' => 'workload_store_summary', 'store_id' => $storeId, 'store_name' => (string)($rows[0]['store_name'] ?? ''), 'reason' => '门店无可接收提醒的店长账号'];
            }
        }
    }

    if (in_array('workload_hq_summary', $selected, true)) {
        $storePieces = [];
        foreach ($byStore as $storeId => $rows) {
            $storeName = (string)($rows[0]['store_name'] ?? ('门店' . $storeId));
            $storePieces[] = $storeName . count($rows) . '人';
        }
        $hqRecipients = reminderFetchHeadquarterRecipients($pdo);
        $content = $storePieces
            ? '全门店今日工作量未完成汇总：' . implode('，', $storePieces) . '。请按门店名单继续跟进。'
            : '今天所有门店的工作量都已完成。';
        foreach ($hqRecipients as $recipient) {
            $jobs[] = [
                'reminder_date' => $reportDate,
                'rule_code' => 'workload_hq_summary',
                'target_user_id' => (int)($recipient['user_id'] ?? 0),
                'target_staff_id' => (int)($recipient['staff_id'] ?? 0),
                'target_store_id' => (int)($recipient['store_id'] ?? 0),
                'target_role_code' => (string)($recipient['role_code'] ?? ''),
                'target_name' => (string)($recipient['staff_name'] ?? ''),
                'type' => 'reminder',
                'title' => '全门店今日工作量汇总提醒',
                'content' => $content,
                'payload' => ['store_summary' => $byStore],
            ];
        }
    }

    return ['jobs' => $jobs, 'skipped' => $skipped, 'incomplete_rows' => $incompleteRows];
}

function reminderUpsertJob(PDO $pdo, array $job): int {
    $stmt = $pdo->prepare("INSERT INTO mini_reminder_jobs
        (reminder_date, rule_code, target_user_id, target_staff_id, target_store_id, target_role_code, target_name, type, title, content, payload_json, status, channel_station_status, channel_wechat_status, channel_wechat_note, last_error)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending', 'pending', '', '')
        ON DUPLICATE KEY UPDATE
            target_role_code = VALUES(target_role_code),
            target_name = VALUES(target_name),
            title = VALUES(title),
            content = VALUES(content),
            payload_json = VALUES(payload_json),
            status = IF(status = 'sent', status, 'pending'),
            channel_station_status = IF(status = 'sent', channel_station_status, 'pending'),
            channel_wechat_status = IF(status = 'sent', channel_wechat_status, 'pending'),
            channel_wechat_note = '',
            last_error = ''");
    $payloadJson = json_encode($job['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt->execute([
        $job['reminder_date'],
        $job['rule_code'],
        (int)($job['target_user_id'] ?? 0),
        (int)($job['target_staff_id'] ?? 0),
        (int)($job['target_store_id'] ?? 0),
        (string)($job['target_role_code'] ?? ''),
        (string)($job['target_name'] ?? ''),
        (string)($job['type'] ?? 'reminder'),
        (string)($job['title'] ?? ''),
        (string)($job['content'] ?? ''),
        $payloadJson === false ? '{}' : $payloadJson,
    ]);

    $idStmt = $pdo->prepare("SELECT id FROM mini_reminder_jobs WHERE reminder_date = ? AND rule_code = ? AND target_user_id = ? AND target_staff_id = ? AND target_store_id = ? LIMIT 1");
    $idStmt->execute([
        $job['reminder_date'],
        $job['rule_code'],
        (int)($job['target_user_id'] ?? 0),
        (int)($job['target_staff_id'] ?? 0),
        (int)($job['target_store_id'] ?? 0),
    ]);
    return (int)$idStmt->fetchColumn();
}

function reminderWechatTemplateKey(string $ruleCode): string {
    $map = [
        'workload_daily_first' => 'workload_daily_first',
        'workload_daily_second' => 'workload_daily_second',
        'workload_store_summary' => 'workload_store_summary',
        'workload_hq_summary' => 'workload_hq_summary',
    ];
    return $map[$ruleCode] ?? $ruleCode;
}

function reminderDuePhases(DateTimeImmutable $now): array {
    $time = $now->format('H:i');
    $phases = [];
    if ($time >= '20:00') {
        $phases[] = 'first';
    }
    if ($time >= '23:00') {
        $phases[] = 'second';
    }
    if ($time >= '23:05') {
        $phases[] = 'store_summary';
    }
    if ($time >= '23:10') {
        $phases[] = 'hq_summary';
    }
    return $phases;
}

function reminderDispatchJob(PDO $pdo, int $jobId): array {
    $stmt = $pdo->prepare("SELECT * FROM mini_reminder_jobs WHERE id = ? LIMIT 1");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        return ['job_id' => $jobId, 'status' => 'missing'];
    }
    if ((string)($job['status'] ?? '') === 'sent' && (int)($job['notification_id'] ?? 0) > 0) {
        return ['job_id' => $jobId, 'status' => 'already_sent', 'notification_id' => (int)$job['notification_id']];
    }
    if ((int)($job['target_user_id'] ?? 0) <= 0) {
        $pdo->prepare("UPDATE mini_reminder_jobs SET status = 'failed', channel_station_status = 'failed', channel_wechat_status = 'skipped', last_error = 'missing_user_id' WHERE id = ?")->execute([$jobId]);
        return ['job_id' => $jobId, 'status' => 'failed', 'reason' => 'missing_user_id'];
    }

    $insert = $pdo->prepare("INSERT INTO mini_user_notifications (user_id, type, title, content, source_type, source_key, source_job_id)
        VALUES (?, ?, ?, ?, 'reminder', ?, ?)
        ON DUPLICATE KEY UPDATE title = VALUES(title), content = VALUES(content)");
    $insert->execute([
        (int)$job['target_user_id'],
        (string)($job['type'] ?? 'reminder'),
        (string)($job['title'] ?? ''),
        (string)($job['content'] ?? ''),
        (string)($job['rule_code'] ?? ''),
        $jobId,
    ]);

    $notificationIdStmt = $pdo->prepare("SELECT id FROM mini_user_notifications WHERE source_type = 'reminder' AND source_job_id = ? LIMIT 1");
    $notificationIdStmt->execute([$jobId]);
    $notificationId = (int)$notificationIdStmt->fetchColumn();

    $templateKey = reminderWechatTemplateKey((string)($job['rule_code'] ?? ''));
    $subscriptionStmt = $pdo->prepare("SELECT id, accept_status FROM mini_user_subscriptions WHERE user_id = ? AND template_key = ? LIMIT 1");
    $subscriptionStmt->execute([(int)$job['target_user_id'], $templateKey]);
    $subscription = $subscriptionStmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $wechatNote = '未配置订阅消息模板';
    $wechatStatus = 'skipped';
    if ($subscription && (string)($subscription['accept_status'] ?? '') === 'accept') {
        $wechatNote = '已记录授权，待接入真实模板发送';
    }

    $update = $pdo->prepare("UPDATE mini_reminder_jobs
        SET notification_id = ?, status = 'sent', channel_station_status = 'sent', channel_wechat_status = ?, channel_wechat_note = ?, sent_at = NOW(), last_error = ''
        WHERE id = ?");
    $update->execute([$notificationId, $wechatStatus, $wechatNote, $jobId]);

    return [
        'job_id' => $jobId,
        'status' => 'sent',
        'notification_id' => $notificationId,
        'wechat_status' => $wechatStatus,
        'wechat_note' => $wechatNote,
    ];
}
