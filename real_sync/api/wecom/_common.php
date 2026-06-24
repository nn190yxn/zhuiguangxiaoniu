<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/workload/_common.php';

function wecomDb(): PDO {
    return getDB();
}

function wecomEnsureSchema(PDO $pdo): void {
    static $initialized = false;
    if ($initialized) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS wecom_sync_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        sync_type VARCHAR(32) NOT NULL DEFAULT 'members',
        status VARCHAR(16) NOT NULL DEFAULT 'success',
        operator_user_id BIGINT UNSIGNED DEFAULT NULL,
        operator_staff_id BIGINT UNSIGNED DEFAULT NULL,
        departments_total INT UNSIGNED NOT NULL DEFAULT 0,
        users_total INT UNSIGNED NOT NULL DEFAULT 0,
        matched_total INT UNSIGNED NOT NULL DEFAULT 0,
        updated_total INT UNSIGNED NOT NULL DEFAULT 0,
        unbound_total INT UNSIGNED NOT NULL DEFAULT 0,
        deactivated_total INT UNSIGNED NOT NULL DEFAULT 0,
        payload_json JSON DEFAULT NULL,
        error_message VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_sync_created (sync_type, created_at),
        KEY idx_status_created (status, created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS wecom_message_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        source_type VARCHAR(32) NOT NULL DEFAULT 'reminder',
        source_key VARCHAR(64) NOT NULL DEFAULT '',
        source_job_id BIGINT UNSIGNED DEFAULT NULL,
        message_type VARCHAR(32) NOT NULL DEFAULT 'miniprogram_notice',
        target_user_id BIGINT UNSIGNED DEFAULT NULL,
        target_staff_id BIGINT UNSIGNED DEFAULT NULL,
        target_wecom_userid VARCHAR(128) NOT NULL DEFAULT '',
        page_path VARCHAR(255) NOT NULL DEFAULT '',
        status VARCHAR(16) NOT NULL DEFAULT 'pending',
        request_json JSON DEFAULT NULL,
        response_json JSON DEFAULT NULL,
        error_message VARCHAR(255) NOT NULL DEFAULT '',
        sent_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_source_job (source_type, source_job_id),
        KEY idx_status_created (status, created_at),
        KEY idx_target_wecom_userid (target_wecom_userid)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $columns = [];
    foreach ($pdo->query('DESCRIBE staffs') as $column) {
        $columns[$column['Field']] = true;
    }
    if (!isset($columns['openid'])) {
        $pdo->exec('ALTER TABLE staffs ADD COLUMN openid VARCHAR(128) NULL AFTER status');
    }
    if (!isset($columns['openid_bound_at'])) {
        $pdo->exec('ALTER TABLE staffs ADD COLUMN openid_bound_at DATETIME NULL AFTER openid');
    }
    if (!isset($columns['wecom_userid'])) {
        $pdo->exec('ALTER TABLE staffs ADD COLUMN wecom_userid VARCHAR(128) NULL AFTER openid_bound_at');
    }
    if (!isset($columns['wecom_name'])) {
        $pdo->exec('ALTER TABLE staffs ADD COLUMN wecom_name VARCHAR(100) NULL AFTER wecom_userid');
    }
    if (!isset($columns['wecom_mobile'])) {
        $pdo->exec('ALTER TABLE staffs ADD COLUMN wecom_mobile VARCHAR(32) NULL AFTER wecom_name');
    }
    if (!isset($columns['wecom_department_id'])) {
        $pdo->exec('ALTER TABLE staffs ADD COLUMN wecom_department_id VARCHAR(128) NULL AFTER wecom_mobile');
    }
    if (!isset($columns['wecom_department_path'])) {
        $pdo->exec('ALTER TABLE staffs ADD COLUMN wecom_department_path VARCHAR(255) NULL AFTER wecom_department_id');
    }
    if (!isset($columns['wecom_status'])) {
        $pdo->exec('ALTER TABLE staffs ADD COLUMN wecom_status TINYINT NULL AFTER wecom_department_path');
    }
    if (!isset($columns['wecom_bound_at'])) {
        $pdo->exec('ALTER TABLE staffs ADD COLUMN wecom_bound_at DATETIME NULL AFTER wecom_status');
    }

    $initialized = true;
}

function wecomRootDepartmentId(): int {
    $value = (int)trim((string)WECOM_SYNC_ROOT_DEPARTMENT_ID);
    return $value > 0 ? $value : 1;
}

function wecomNormalizeText($value, int $maxLength = 255): string {
    return mb_substr(trim((string)$value), 0, $maxLength);
}

function wecomGetAccessToken(): string {
    static $cachedToken = null;
    static $cachedAt = 0;

    if ($cachedToken && (time() - $cachedAt) < 7000) {
        return $cachedToken;
    }

    if (!isWecomEnabled()) {
        throw new RuntimeException('企业微信配置未完成');
    }

    $url = 'https://qyapi.weixin.qq.com/cgi-bin/gettoken?corpid=' . rawurlencode(WECOM_CORP_ID)
        . '&corpsecret=' . rawurlencode(WECOM_AGENT_SECRET);
    $response = wecomHttpGetJsonWithTimeout($url, 3, 10);
    if (!$response['ok']) {
        throw new RuntimeException('企业微信 access_token 获取失败');
    }

    $data = json_decode((string)$response['body'], true);
    if (!is_array($data) || (int)($data['errcode'] ?? -1) !== 0 || empty($data['access_token'])) {
        throw new RuntimeException('企业微信 access_token 返回异常');
    }

    $cachedToken = (string)$data['access_token'];
    $cachedAt = time();
    return $cachedToken;
}

function wecomApiGet(string $path, array $query = []): array {
    $query = array_merge(['access_token' => wecomGetAccessToken()], $query);
    $url = 'https://qyapi.weixin.qq.com' . $path . '?' . http_build_query($query);
    $response = wecomHttpGetJsonWithTimeout($url, 3, 20);
    if (!$response['ok']) {
        throw new RuntimeException('企业微信接口请求失败: ' . $path);
    }

    $data = json_decode((string)$response['body'], true);
    if (!is_array($data)) {
        throw new RuntimeException('企业微信接口返回无效 JSON: ' . $path);
    }
    if ((int)($data['errcode'] ?? -1) !== 0) {
        throw new RuntimeException('企业微信接口返回错误: ' . $path . ' errcode=' . (string)($data['errcode'] ?? 'unknown'));
    }
    return $data;
}

function wecomHttpGetJsonWithTimeout(string $url, int $connectTimeoutSeconds, int $timeoutSeconds): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'body' => null, 'status_code' => $httpCode, 'error' => $error ?: 'curl_exec_failed'];
        }

        return ['ok' => true, 'body' => $body, 'status_code' => $httpCode, 'error' => ''];
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $lastError = error_get_last();
        return ['ok' => false, 'body' => null, 'status_code' => 0, 'error' => $lastError['message'] ?? 'stream_request_failed'];
    }

    return ['ok' => true, 'body' => $body, 'status_code' => 200, 'error' => ''];
}

function wecomHttpPostJsonWithTimeout(string $url, array $payload, int $connectTimeoutSeconds, int $timeoutSeconds): array {
    $bodyText = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($bodyText === false) {
        return ['ok' => false, 'body' => null, 'status_code' => 0, 'error' => 'json_encode_failed'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $bodyText,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'body' => null, 'status_code' => $httpCode, 'error' => $error ?: 'curl_exec_failed'];
        }

        return ['ok' => true, 'body' => $body, 'status_code' => $httpCode, 'error' => ''];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => $timeoutSeconds,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nContent-Type: application/json\r\n",
            'content' => $bodyText,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        $lastError = error_get_last();
        return ['ok' => false, 'body' => null, 'status_code' => 0, 'error' => $lastError['message'] ?? 'stream_request_failed'];
    }

    return ['ok' => true, 'body' => $body, 'status_code' => 200, 'error' => ''];
}

function wecomApiPost(string $path, array $payload): array {
    $url = 'https://qyapi.weixin.qq.com' . $path . '?access_token=' . rawurlencode(wecomGetAccessToken());
    $response = wecomHttpPostJsonWithTimeout($url, $payload, 3, 20);
    if (!$response['ok']) {
        throw new RuntimeException('企业微信接口请求失败: ' . $path);
    }

    $data = json_decode((string)$response['body'], true);
    if (!is_array($data)) {
        throw new RuntimeException('企业微信接口返回无效 JSON: ' . $path);
    }
    if ((int)($data['errcode'] ?? -1) !== 0) {
        throw new RuntimeException('企业微信接口返回错误: ' . $path . ' errcode=' . (string)($data['errcode'] ?? 'unknown'));
    }
    return $data;
}

function wecomReminderMessagePage(array $job): string {
    $payload = json_decode((string)($job['payload_json'] ?? '{}'), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $target = wecomBuildReminderMessageTarget($job, $payload);
    return wecomBuildMessagePagePath((string)$target['page'], (array)($target['params'] ?? []));
}

function wecomBuildReminderMessageTarget(array $job, array $payload = []): array {
    $ruleCode = (string)($job['rule_code'] ?? '');
    $targetStaffId = (int)($job['target_staff_id'] ?? 0);
    $params = [
        'entry' => 'wecom_message',
        'source_type' => 'reminder',
        'source_key' => $ruleCode,
        'source_job_id' => (int)($job['id'] ?? 0),
    ];
    if ($targetStaffId > 0) {
        $params['staff_id'] = $targetStaffId;
    }

    if (strpos($ruleCode, 'workload_') === 0) {
        $reportDate = trim((string)($payload['report_date'] ?? $job['reminder_date'] ?? ''));
        $phase = trim((string)($payload['phase'] ?? ''));
        $params['scene'] = 'workload';
        if ($reportDate !== '') {
            $params['date'] = $reportDate;
        }
        if ($phase !== '') {
            $params['phase'] = $phase;
        }
        return [
            'page' => 'pages/workload/index',
            'page_label' => '工作量日报',
            'params' => $params,
        ];
    }

    if ($ruleCode === 'learning_required_daily') {
        $courseId = (int)($payload['course_id'] ?? 0);
        $keyword = trim((string)($payload['course_title'] ?? ''));
        $params['scene'] = 'learning';
        if ($courseId > 0) {
            $params['course_id'] = $courseId;
        }
        if ($keyword !== '') {
            $params['keyword'] = $keyword;
        }
        return [
            'page' => 'pages/learning/list',
            'page_label' => '学习中心',
            'params' => $params,
        ];
    }

    $params['scene'] = 'home';
    return [
        'page' => 'pages/index/index',
        'page_label' => '首页',
        'params' => $params,
    ];
}

function wecomBuildMessagePagePath(string $page, array $params = []): string {
    $page = trim($page);
    if ($page === '') {
        $page = 'pages/index/index';
    }
    $normalized = [];
    foreach ($params as $key => $value) {
        $key = trim((string)$key);
        if ($key === '' || $value === null || $value === '') {
            continue;
        }
        $normalized[$key] = (string)$value;
    }
    if (!$normalized) {
        return $page;
    }
    return $page . '?' . http_build_query($normalized, '', '&', PHP_QUERY_RFC3986);
}

function wecomParseMessagePagePath(string $pagePath): array {
    $pagePath = trim($pagePath);
    if ($pagePath === '') {
        return [
            'page' => '',
            'page_label' => '',
            'params' => [],
        ];
    }
    $parts = explode('?', $pagePath, 2);
    $page = $parts[0] ?? '';
    $query = $parts[1] ?? '';
    $params = [];
    if ($query !== '') {
        parse_str($query, $params);
    }
    $labels = [
        'pages/workload/index' => '工作量日报',
        'pages/index/index' => '首页',
    ];
    return [
        'page' => $page,
        'page_label' => $labels[$page] ?? $page,
        'params' => is_array($params) ? $params : [],
    ];
}

function wecomReminderMessageItems(array $job): array {
    $payload = json_decode((string)($job['payload_json'] ?? '{}'), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $items = [];
    $reportDate = trim((string)($payload['report_date'] ?? $job['reminder_date'] ?? ''));
    if ($reportDate !== '') {
        $items[] = ['key' => '日期', 'value' => $reportDate];
    }
    $reason = trim((string)($payload['reason_text'] ?? ''));
    if ($reason !== '') {
        $items[] = ['key' => '当前状态', 'value' => wecomNormalizeText($reason, 64)];
    }
    $roleCode = trim((string)($job['target_role_code'] ?? ''));
    if ($roleCode !== '') {
        $items[] = ['key' => '对象角色', 'value' => wecomNormalizeText($roleCode, 32)];
    }
    $items[] = ['key' => '处理动作', 'value' => '点击消息进入小程序处理'];

    return array_slice($items, 0, 4);
}

function wecomPolicyMessageTarget(array $notification, array $policy = []): array {
    $policyId = (int)($notification['policy_id'] ?? $policy['id'] ?? 0);
    $notificationId = (int)($notification['notification_id'] ?? $notification['id'] ?? 0);
    $category = trim((string)($policy['category'] ?? ''));
    $params = [
        'entry' => 'wecom_message',
        'source_type' => 'policy',
        'source_key' => trim((string)($notification['type'] ?? 'policy_notice')),
        'scene' => 'policy',
        'policy_id' => $policyId,
        'notification_id' => $notificationId,
    ];
    if ($category !== '') {
        $params['category'] = $category;
    }
    return [
        'page' => $policyId > 0 ? 'pages/policy/detail' : 'pages/policy/list',
        'page_label' => $policyId > 0 ? '制度详情' : '制度中心',
        'params' => $params,
    ];
}

function wecomFindTargetStaff(PDO $pdo, int $targetStaffId = 0, int $targetUserId = 0): ?array {
    if ($targetStaffId > 0) {
        $stmt = $pdo->prepare('SELECT id, user_id, name, wecom_userid, wecom_status FROM staffs WHERE id = ? LIMIT 1');
        $stmt->execute([$targetStaffId]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($staff) {
            return $staff;
        }
    }
    if ($targetUserId > 0) {
        $stmt = $pdo->prepare('SELECT id, user_id, name, wecom_userid, wecom_status FROM staffs WHERE user_id = ? LIMIT 1');
        $stmt->execute([$targetUserId]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($staff) {
            return $staff;
        }
    }
    return null;
}

function wecomDispatchMiniProgramMessage(PDO $pdo, array $payload): array {
    wecomEnsureSchema($pdo);

    if (!isWecomEnabled()) {
        return ['status' => 'skipped', 'note' => '企业微信消息未启用'];
    }

    $targetUserId = (int)($payload['target_user_id'] ?? 0);
    $targetStaffId = (int)($payload['target_staff_id'] ?? 0);
    $staff = wecomFindTargetStaff($pdo, $targetStaffId, $targetUserId);
    $wecomUserId = trim((string)($staff['wecom_userid'] ?? ''));
    if ($wecomUserId === '') {
        return ['status' => 'skipped', 'note' => '员工未绑定企业微信成员'];
    }
    if (isset($staff['wecom_status']) && (int)$staff['wecom_status'] !== 1) {
        return ['status' => 'skipped', 'note' => '企业微信成员状态停用'];
    }

    $pagePath = wecomBuildMessagePagePath((string)($payload['page'] ?? ''), (array)($payload['page_params'] ?? []));
    $requestPayload = [
        'touser' => $wecomUserId,
        'msgtype' => 'miniprogram_notice',
        'agentid' => (int)WECOM_AGENT_ID,
        'miniprogram_notice' => [
            'appid' => WECOM_APPID,
            'page' => $pagePath,
            'title' => wecomNormalizeText($payload['title'] ?? '', 64),
            'description' => wecomNormalizeText($payload['description'] ?? '', 512),
            'emphasis_first_item' => !isset($payload['emphasis_first_item']) || (bool)$payload['emphasis_first_item'],
            'content_item' => array_slice((array)($payload['content_items'] ?? []), 0, 4),
        ],
        'enable_duplicate_check' => 0,
    ];

    $requestJson = json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $logId = wecomCreateMessageLog($pdo, [
        'source_type' => (string)($payload['source_type'] ?? 'reminder'),
        'source_key' => (string)($payload['source_key'] ?? ''),
        'source_job_id' => isset($payload['source_job_id']) ? (int)$payload['source_job_id'] : null,
        'target_user_id' => $targetUserId,
        'target_staff_id' => (int)($staff['id'] ?? 0),
        'target_wecom_userid' => $wecomUserId,
        'page_path' => $pagePath,
        'status' => 'pending',
        'request_json' => $requestJson === false ? null : $requestJson,
    ]);

    try {
        $response = wecomApiPost('/cgi-bin/message/send', $requestPayload);
        $responseJson = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        wecomUpdateMessageLog($pdo, $logId, [
            'status' => 'sent',
            'response_json' => $responseJson === false ? null : $responseJson,
            'error_message' => '',
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
        return ['status' => 'sent', 'note' => '企业微信应用消息已发送', 'log_id' => $logId];
    } catch (Throwable $e) {
        wecomUpdateMessageLog($pdo, $logId, [
            'status' => 'failed',
            'response_json' => null,
            'error_message' => wecomNormalizeText($e->getMessage(), 255),
            'sent_at' => null,
        ]);
        appLogEvent('wecom.message_send_failed', [
            'log_id' => $logId,
            'source_type' => (string)($payload['source_type'] ?? ''),
            'source_key' => (string)($payload['source_key'] ?? ''),
            'staff_id' => (int)($staff['id'] ?? 0),
            'error' => $e->getMessage(),
        ]);
        return ['status' => 'failed', 'note' => wecomNormalizeText($e->getMessage(), 255), 'log_id' => $logId];
    }
}

function wecomDispatchPolicyNotification(PDO $pdo, array $notification, array $policy = []): array {
    $target = wecomPolicyMessageTarget($notification, $policy);
    $items = [];
    $policyTitle = trim((string)($policy['title'] ?? $notification['title'] ?? ''));
    $category = trim((string)($policy['category'] ?? ''));
    if ($policyTitle !== '') {
        $items[] = ['key' => '制度标题', 'value' => wecomNormalizeText($policyTitle, 64)];
    }
    if ($category !== '') {
        $items[] = ['key' => '制度分类', 'value' => wecomNormalizeText($category, 32)];
    }
    $items[] = ['key' => '处理动作', 'value' => ((string)($notification['type'] ?? '') === 'confirm') ? '点击后阅读并确认' : '点击后查看制度详情'];

    return wecomDispatchMiniProgramMessage($pdo, [
        'source_type' => 'policy',
        'source_key' => trim((string)($notification['type'] ?? 'policy_notice')),
        'source_job_id' => isset($notification['notification_id']) ? (int)$notification['notification_id'] : (int)($notification['id'] ?? 0),
        'target_user_id' => (int)($notification['target_user_id'] ?? 0),
        'page' => (string)$target['page'],
        'page_params' => (array)$target['params'],
        'title' => trim((string)($notification['title'] ?? '制度通知')),
        'description' => trim((string)($notification['content'] ?? '请及时查看制度内容')),
        'content_items' => $items,
    ]);
}

function wecomCreateMessageLog(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare("INSERT INTO wecom_message_logs
        (source_type, source_key, source_job_id, message_type, target_user_id, target_staff_id, target_wecom_userid, page_path, status, request_json, response_json, error_message, sent_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        (string)($data['source_type'] ?? 'reminder'),
        (string)($data['source_key'] ?? ''),
        isset($data['source_job_id']) ? (int)$data['source_job_id'] : null,
        (string)($data['message_type'] ?? 'miniprogram_notice'),
        isset($data['target_user_id']) ? (int)$data['target_user_id'] : null,
        isset($data['target_staff_id']) ? (int)$data['target_staff_id'] : null,
        (string)($data['target_wecom_userid'] ?? ''),
        (string)($data['page_path'] ?? ''),
        (string)($data['status'] ?? 'pending'),
        $data['request_json'] ?? null,
        $data['response_json'] ?? null,
        (string)($data['error_message'] ?? ''),
        $data['sent_at'] ?? null,
    ]);
    return (int)$pdo->lastInsertId();
}

function wecomUpdateMessageLog(PDO $pdo, int $logId, array $data): void {
    if ($logId <= 0) {
        return;
    }

    $stmt = $pdo->prepare("UPDATE wecom_message_logs
        SET status = ?, response_json = ?, error_message = ?, sent_at = ?, updated_at = NOW()
        WHERE id = ?");
    $stmt->execute([
        (string)($data['status'] ?? 'pending'),
        $data['response_json'] ?? null,
        (string)($data['error_message'] ?? ''),
        $data['sent_at'] ?? null,
        $logId,
    ]);
}

function wecomDispatchReminderMessage(PDO $pdo, array $job): array {
    $payload = json_decode((string)($job['payload_json'] ?? '{}'), true);
    if (!is_array($payload)) {
        $payload = [];
    }
    $pageTarget = wecomBuildReminderMessageTarget($job, $payload);
    return wecomDispatchMiniProgramMessage($pdo, [
        'source_type' => 'reminder',
        'source_key' => (string)($job['rule_code'] ?? ''),
        'source_job_id' => (int)($job['id'] ?? 0),
        'target_user_id' => (int)($job['target_user_id'] ?? 0),
        'target_staff_id' => (int)($job['target_staff_id'] ?? 0),
        'page' => (string)$pageTarget['page'],
        'page_params' => (array)($pageTarget['params'] ?? []),
        'title' => (string)($job['title'] ?? ''),
        'description' => (string)($job['content'] ?? ''),
        'content_items' => wecomReminderMessageItems($job),
    ]);
}

function wecomListMessageLogs(PDO $pdo, array $filters = []): array {
    wecomEnsureSchema($pdo);

    $page = max(1, (int)($filters['page'] ?? 1));
    $pageSize = max(1, min(100, (int)($filters['page_size'] ?? 20)));
    $offset = ($page - 1) * $pageSize;
    $status = wecomNormalizeText($filters['status'] ?? '', 16);
    $sourceKey = wecomNormalizeText($filters['source_key'] ?? '', 64);

    $where = ['1=1'];
    $params = [];
    if ($status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($sourceKey !== '') {
        $where[] = 'source_key = ?';
        $params[] = $sourceKey;
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM wecom_message_logs ' . $whereSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare('SELECT id, source_type, source_key, source_job_id, message_type, target_user_id, target_staff_id, target_wecom_userid, page_path, status, error_message, sent_at, created_at
        FROM wecom_message_logs '
        . $whereSql . '
        ORDER BY id DESC
        LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset);
    $stmt->execute($params);

    $summary = [
        'failure_reasons' => wecomBuildMessageFailureSummary($pdo, $whereSql, $params),
    ];

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['page_target'] = wecomParseMessagePagePath((string)($row['page_path'] ?? ''));
    }
    unset($row);

    return [
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ],
        'summary' => $summary,
        'list' => $rows,
    ];
}

function wecomBuildMessageFailureSummary(PDO $pdo, string $whereSql, array $params): array {
    $sql = 'SELECT error_message, COUNT(*) AS total
        FROM wecom_message_logs '
        . $whereSql . ' AND status = ?
        GROUP BY error_message
        ORDER BY total DESC, error_message ASC
        LIMIT 6';
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($params, ['failed']));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        $reason = trim((string)($row['error_message'] ?? ''));
        return [
            'reason' => $reason !== '' ? $reason : '未知失败',
            'total' => (int)($row['total'] ?? 0),
        ];
    }, $rows);
}

function wecomFetchDepartments(int $rootDepartmentId): array {
    $data = wecomApiGet('/cgi-bin/department/list', ['id' => $rootDepartmentId]);
    $rows = [];
    foreach (($data['department'] ?? []) as $department) {
        if (!is_array($department)) {
            continue;
        }
        $rows[] = [
            'id' => (int)($department['id'] ?? 0),
            'parentid' => (int)($department['parentid'] ?? 0),
            'name' => wecomNormalizeText($department['name'] ?? '', 100),
            'order' => (int)($department['order'] ?? 0),
        ];
    }
    return $rows;
}

function wecomFetchUsers(int $rootDepartmentId): array {
    $data = wecomApiGet('/cgi-bin/user/list', [
        'department_id' => $rootDepartmentId,
        'fetch_child' => 1,
    ]);
    $rows = [];
    foreach (($data['userlist'] ?? []) as $user) {
        if (!is_array($user)) {
            continue;
        }
        $departments = [];
        foreach (($user['department'] ?? []) as $departmentId) {
            $departmentId = (int)$departmentId;
            if ($departmentId > 0) {
                $departments[] = $departmentId;
            }
        }
        $rows[] = [
            'userid' => wecomNormalizeText($user['userid'] ?? '', 128),
            'name' => wecomNormalizeText($user['name'] ?? '', 100),
            'mobile' => wecomNormalizeText($user['mobile'] ?? '', 32),
            'department' => $departments,
            'status' => isset($user['status']) ? (int)$user['status'] : 0,
        ];
    }
    return $rows;
}

function wecomBuildDepartmentPaths(array $departments): array {
    $map = [];
    foreach ($departments as $department) {
        $id = (int)($department['id'] ?? 0);
        if ($id > 0) {
            $map[$id] = $department;
        }
    }

    $paths = [];
    foreach ($map as $id => $department) {
        $parts = [];
        $currentId = $id;
        $safety = 0;
        while ($currentId > 0 && isset($map[$currentId]) && $safety < 20) {
            $parts[] = (string)$map[$currentId]['name'];
            $parentId = (int)($map[$currentId]['parentid'] ?? 0);
            if ($parentId <= 0 || $parentId === $currentId) {
                break;
            }
            $currentId = $parentId;
            $safety++;
        }
        $paths[$id] = implode('/', array_reverse(array_filter($parts)));
    }
    return $paths;
}

function wecomFindLocalStaffByUser(PDO $pdo, array $user): ?array {
    $userid = wecomNormalizeText($user['userid'] ?? '', 128);
    $mobile = wecomNormalizeText($user['mobile'] ?? '', 32);

    if ($userid !== '') {
        $stmt = $pdo->prepare('SELECT * FROM staffs WHERE wecom_userid = ? LIMIT 1');
        $stmt->execute([$userid]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($staff) {
            return $staff;
        }
    }

    if ($mobile !== '') {
        $stmt = $pdo->prepare('SELECT * FROM staffs WHERE phone = ? LIMIT 1');
        $stmt->execute([$mobile]);
        $staff = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($staff) {
            return $staff;
        }
    }

    return null;
}

function wecomUpdateStaffFromUser(PDO $pdo, array $staff, array $user, array $departmentPaths): bool {
    $departmentIds = array_values(array_filter(array_map('intval', $user['department'] ?? [])));
    $primaryDepartmentId = $departmentIds ? (int)$departmentIds[0] : 0;
    $departmentPath = $primaryDepartmentId > 0 ? wecomNormalizeText($departmentPaths[$primaryDepartmentId] ?? '', 255) : '';
    $wecomUserId = wecomNormalizeText($user['userid'] ?? '', 128);
    $wecomName = wecomNormalizeText($user['name'] ?? '', 100);
    $wecomMobile = wecomNormalizeText($user['mobile'] ?? '', 32);
    $wecomStatus = isset($user['status']) ? (int)$user['status'] : 0;

    $before = [
        'wecom_userid' => (string)($staff['wecom_userid'] ?? ''),
        'wecom_name' => (string)($staff['wecom_name'] ?? ''),
        'wecom_mobile' => (string)($staff['wecom_mobile'] ?? ''),
        'wecom_department_id' => (string)($staff['wecom_department_id'] ?? ''),
        'wecom_department_path' => (string)($staff['wecom_department_path'] ?? ''),
        'wecom_status' => isset($staff['wecom_status']) ? (int)$staff['wecom_status'] : null,
    ];
    $after = [
        'wecom_userid' => $wecomUserId,
        'wecom_name' => $wecomName,
        'wecom_mobile' => $wecomMobile,
        'wecom_department_id' => $primaryDepartmentId > 0 ? (string)$primaryDepartmentId : '',
        'wecom_department_path' => $departmentPath,
        'wecom_status' => $wecomStatus,
    ];

    if ($before === $after) {
        return false;
    }

    $sql = 'UPDATE staffs SET wecom_userid = ?, wecom_name = ?, wecom_mobile = ?, wecom_department_id = ?, wecom_department_path = ?, wecom_status = ?, wecom_bound_at = COALESCE(wecom_bound_at, NOW()), updated_at = NOW() WHERE id = ?';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $after['wecom_userid'],
        $after['wecom_name'],
        $after['wecom_mobile'],
        $after['wecom_department_id'],
        $after['wecom_department_path'],
        $after['wecom_status'],
        (int)$staff['id'],
    ]);
    return true;
}

function wecomDeactivateMissingBindings(PDO $pdo, array $activeUserIds): int {
    $activeUserIds = array_values(array_filter(array_map(static function ($value): string {
        return wecomNormalizeText($value, 128);
    }, $activeUserIds)));

    if (!$activeUserIds) {
        $stmt = $pdo->prepare("UPDATE staffs SET wecom_status = 0, updated_at = NOW() WHERE COALESCE(wecom_userid, '') <> '' AND COALESCE(wecom_status, -1) <> 0");
        $stmt->execute();
        return $stmt->rowCount();
    }

    $placeholders = implode(',', array_fill(0, count($activeUserIds), '?'));
    $sql = "UPDATE staffs SET wecom_status = 0, updated_at = NOW() WHERE COALESCE(wecom_userid, '') <> '' AND wecom_userid NOT IN ({$placeholders}) AND COALESCE(wecom_status, -1) <> 0";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($activeUserIds);
    return $stmt->rowCount();
}

function wecomWriteSyncLog(PDO $pdo, array $payload): int {
    wecomEnsureSchema($pdo);
    $stmt = $pdo->prepare("INSERT INTO wecom_sync_logs
        (sync_type, status, operator_user_id, operator_staff_id, departments_total, users_total, matched_total, updated_total, unbound_total, deactivated_total, payload_json, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        wecomNormalizeText($payload['sync_type'] ?? 'members', 32),
        wecomNormalizeText($payload['status'] ?? 'success', 16),
        isset($payload['operator_user_id']) ? (int)$payload['operator_user_id'] : null,
        isset($payload['operator_staff_id']) ? (int)$payload['operator_staff_id'] : null,
        (int)($payload['departments_total'] ?? 0),
        (int)($payload['users_total'] ?? 0),
        (int)($payload['matched_total'] ?? 0),
        (int)($payload['updated_total'] ?? 0),
        (int)($payload['unbound_total'] ?? 0),
        (int)($payload['deactivated_total'] ?? 0),
        isset($payload['payload']) ? json_encode($payload['payload'], JSON_UNESCAPED_UNICODE) : null,
        wecomNormalizeText($payload['error_message'] ?? '', 255),
    ]);
    return (int)$pdo->lastInsertId();
}

function wecomSyncMembers(PDO $pdo, array $options = []): array {
    if (!isWecomEnabled()) {
        throw new RuntimeException('企业微信配置未完成');
    }

    wecomEnsureSchema($pdo);
    $rootDepartmentId = isset($options['root_department_id']) ? (int)$options['root_department_id'] : wecomRootDepartmentId();
    if ($rootDepartmentId <= 0) {
        $rootDepartmentId = 1;
    }

    $departments = wecomFetchDepartments($rootDepartmentId);
    $departmentPaths = wecomBuildDepartmentPaths($departments);
    $users = wecomFetchUsers($rootDepartmentId);

    $matchedTotal = 0;
    $updatedTotal = 0;
    $unbound = [];
    $activeUserIds = [];

    foreach ($users as $user) {
        $activeUserIds[] = (string)$user['userid'];
        $staff = wecomFindLocalStaffByUser($pdo, $user);
        if (!$staff) {
            $unbound[] = [
                'userid' => (string)$user['userid'],
                'name' => (string)$user['name'],
                'mobile' => (string)$user['mobile'],
            ];
            continue;
        }
        $matchedTotal++;
        if (wecomUpdateStaffFromUser($pdo, $staff, $user, $departmentPaths)) {
            $updatedTotal++;
        }
    }

    $deactivatedTotal = wecomDeactivateMissingBindings($pdo, $activeUserIds);

    return [
        'root_department_id' => $rootDepartmentId,
        'departments_total' => count($departments),
        'users_total' => count($users),
        'matched_total' => $matchedTotal,
        'updated_total' => $updatedTotal,
        'unbound_total' => count($unbound),
        'deactivated_total' => $deactivatedTotal,
        'unbound_users' => array_slice($unbound, 0, 50),
        'department_paths' => $departmentPaths,
    ];
}

function wecomDecodeLogPayload($value): array {
    if (is_array($value)) {
        return $value;
    }
    if (!is_string($value) || trim($value) === '') {
        return [];
    }
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : [];
}

function wecomBuildSyncLogDetail(array $row): array {
    $payload = isset($row['payload']) && is_array($row['payload']) ? $row['payload'] : [];
    $unboundUsers = array_values(array_filter(array_map(static function ($item): ?array {
        if (!is_array($item)) {
            return null;
        }
        return [
            'userid' => wecomNormalizeText($item['userid'] ?? '', 128),
            'name' => wecomNormalizeText($item['name'] ?? '', 100),
            'mobile' => wecomNormalizeText($item['mobile'] ?? '', 32),
        ];
    }, (array)($payload['unbound_users'] ?? []))));

    $departmentPathSamples = [];
    foreach ((array)($payload['department_paths'] ?? []) as $departmentId => $path) {
        $departmentPathSamples[] = [
            'department_id' => (int)$departmentId,
            'path' => wecomNormalizeText($path, 255),
        ];
        if (count($departmentPathSamples) >= 6) {
            break;
        }
    }

    return [
        'root_department_id' => isset($payload['root_department_id']) ? (int)$payload['root_department_id'] : 0,
        'unbound_users_sample' => array_slice($unboundUsers, 0, 5),
        'department_path_samples' => $departmentPathSamples,
        'sample_counts' => [
            'unbound_users' => count($unboundUsers),
            'department_paths' => count((array)($payload['department_paths'] ?? [])),
        ],
    ];
}

function wecomListSyncLogs(PDO $pdo, array $filters = []): array {
    wecomEnsureSchema($pdo);

    $page = max(1, (int)($filters['page'] ?? 1));
    $pageSize = max(1, min(100, (int)($filters['page_size'] ?? 20)));
    $offset = ($page - 1) * $pageSize;
    $status = wecomNormalizeText($filters['status'] ?? '', 16);
    $syncType = wecomNormalizeText($filters['sync_type'] ?? '', 32);

    $where = ['1 = 1'];
    $params = [];
    if ($status !== '') {
        $where[] = 'status = ?';
        $params[] = $status;
    }
    if ($syncType !== '') {
        $where[] = 'sync_type = ?';
        $params[] = $syncType;
    }

    $countSql = 'SELECT COUNT(*) FROM wecom_sync_logs WHERE ' . implode(' AND ', $where);
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = 'SELECT * FROM wecom_sync_logs WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $summaryWhere = ['1 = 1'];
    $summaryParams = [];
    if ($syncType !== '') {
        $summaryWhere[] = 'sync_type = ?';
        $summaryParams[] = $syncType;
    }
    $summarySql = 'SELECT status, COUNT(*) AS total FROM wecom_sync_logs WHERE ' . implode(' AND ', $summaryWhere) . ' GROUP BY status';
    $summaryStmt = $pdo->prepare($summarySql);
    $summaryStmt->execute($summaryParams);
    $statusRows = $summaryStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $latestFailedStmt = $pdo->prepare('SELECT id, status, error_message, created_at FROM wecom_sync_logs WHERE sync_type = ? AND status = ? ORDER BY id DESC LIMIT 1');
    $latestFailedStmt->execute([$syncType !== '' ? $syncType : 'members', 'failed']);
    $latestFailed = $latestFailedStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    foreach ($rows as &$row) {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['operator_user_id'] = isset($row['operator_user_id']) ? (int)$row['operator_user_id'] : null;
        $row['operator_staff_id'] = isset($row['operator_staff_id']) ? (int)$row['operator_staff_id'] : null;
        $row['departments_total'] = (int)($row['departments_total'] ?? 0);
        $row['users_total'] = (int)($row['users_total'] ?? 0);
        $row['matched_total'] = (int)($row['matched_total'] ?? 0);
        $row['updated_total'] = (int)($row['updated_total'] ?? 0);
        $row['unbound_total'] = (int)($row['unbound_total'] ?? 0);
        $row['deactivated_total'] = (int)($row['deactivated_total'] ?? 0);
        $row['payload'] = wecomDecodeLogPayload($row['payload_json'] ?? '');
        $row['detail'] = wecomBuildSyncLogDetail($row);
        unset($row['payload_json']);
    }
    unset($row);

    return [
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ],
        'summary' => [
            'status_totals' => wecomBuildSyncStatusSummary($statusRows),
            'latest_failed' => $latestFailed ? [
                'id' => (int)($latestFailed['id'] ?? 0),
                'status' => (string)($latestFailed['status'] ?? ''),
                'error_message' => (string)($latestFailed['error_message'] ?? ''),
                'created_at' => (string)($latestFailed['created_at'] ?? ''),
            ] : null,
        ],
        'list' => $rows,
    ];
}

function wecomBuildSyncStatusSummary(array $rows): array {
    $summary = [
        'success' => 0,
        'failed' => 0,
        'running' => 0,
        'other' => 0,
    ];
    foreach ($rows as $row) {
        $status = trim((string)($row['status'] ?? ''));
        $total = (int)($row['total'] ?? 0);
        if (array_key_exists($status, $summary)) {
            $summary[$status] += $total;
            continue;
        }
        $summary['other'] += $total;
    }
    return $summary;
}

function wecomGetLatestSyncLog(PDO $pdo, string $syncType = 'members'): ?array {
    wecomEnsureSchema($pdo);
    $stmt = $pdo->prepare('SELECT * FROM wecom_sync_logs WHERE sync_type = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([wecomNormalizeText($syncType, 32)]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $row['id'] = (int)($row['id'] ?? 0);
    $row['payload'] = wecomDecodeLogPayload($row['payload_json'] ?? '');
    unset($row['payload_json']);
    return $row;
}

function wecomBuildOverview(PDO $pdo): array {
    wecomEnsureSchema($pdo);

    $latest = wecomGetLatestSyncLog($pdo, 'members');
    $statsStmt = $pdo->query("SELECT
        SUM(CASE WHEN COALESCE(wecom_userid, '') <> '' THEN 1 ELSE 0 END) AS bound_total,
        SUM(CASE WHEN COALESCE(wecom_status, 0) = 1 THEN 1 ELSE 0 END) AS active_total,
        SUM(CASE WHEN COALESCE(wecom_status, 0) = 0 AND COALESCE(wecom_userid, '') <> '' THEN 1 ELSE 0 END) AS inactive_total,
        SUM(CASE WHEN COALESCE(wecom_userid, '') = '' THEN 1 ELSE 0 END) AS unbound_staff_total
        FROM staffs
        WHERE status = 1");
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $messageStatsStmt = $pdo->query("SELECT
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) AS sent_total,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_total,
        SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped_total
        FROM wecom_message_logs
        WHERE DATE(created_at) = CURDATE()");
    $messageStats = $messageStatsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $latestMessageStmt = $pdo->query('SELECT id, source_type, source_key, target_staff_id, target_wecom_userid, page_path, status, error_message, sent_at, created_at FROM wecom_message_logs ORDER BY id DESC LIMIT 1');
    $latestMessage = $latestMessageStmt->fetch(PDO::FETCH_ASSOC) ?: null;

    $latestPayload = $latest['payload'] ?? [];
    return [
        'enabled' => isWecomEnabled(),
        'root_department_id' => wecomRootDepartmentId(),
        'staff_stats' => [
            'bound_total' => (int)($stats['bound_total'] ?? 0),
            'active_total' => (int)($stats['active_total'] ?? 0),
            'inactive_total' => (int)($stats['inactive_total'] ?? 0),
            'unbound_staff_total' => (int)($stats['unbound_staff_total'] ?? 0),
        ],
        'latest_sync' => $latest ? [
            'id' => (int)($latest['id'] ?? 0),
            'status' => (string)($latest['status'] ?? ''),
            'created_at' => (string)($latest['created_at'] ?? ''),
            'departments_total' => (int)($latest['departments_total'] ?? 0),
            'users_total' => (int)($latest['users_total'] ?? 0),
            'matched_total' => (int)($latest['matched_total'] ?? 0),
            'updated_total' => (int)($latest['updated_total'] ?? 0),
            'unbound_total' => (int)($latest['unbound_total'] ?? 0),
            'deactivated_total' => (int)($latest['deactivated_total'] ?? 0),
            'error_message' => (string)($latest['error_message'] ?? ''),
            'unbound_users' => array_slice((array)($latestPayload['unbound_users'] ?? []), 0, 20),
        ] : null,
        'message_stats' => [
            'sent_today' => (int)($messageStats['sent_total'] ?? 0),
            'failed_today' => (int)($messageStats['failed_total'] ?? 0),
            'skipped_today' => (int)($messageStats['skipped_total'] ?? 0),
        ],
        'latest_message' => $latestMessage ? [
            'id' => (int)($latestMessage['id'] ?? 0),
            'source_type' => (string)($latestMessage['source_type'] ?? ''),
            'source_key' => (string)($latestMessage['source_key'] ?? ''),
            'target_staff_id' => isset($latestMessage['target_staff_id']) ? (int)$latestMessage['target_staff_id'] : null,
            'target_wecom_userid' => (string)($latestMessage['target_wecom_userid'] ?? ''),
            'page_path' => (string)($latestMessage['page_path'] ?? ''),
            'page_target' => wecomParseMessagePagePath((string)($latestMessage['page_path'] ?? '')),
            'status' => (string)($latestMessage['status'] ?? ''),
            'error_message' => (string)($latestMessage['error_message'] ?? ''),
            'sent_at' => (string)($latestMessage['sent_at'] ?? ''),
            'created_at' => (string)($latestMessage['created_at'] ?? ''),
        ] : null,
    ];
}

function wecomFindCandidateStaffs(PDO $pdo, array $filters = []): array {
    $keyword = wecomNormalizeText($filters['keyword'] ?? '', 100);
    $mobile = wecomNormalizeText($filters['mobile'] ?? '', 32);
    $name = wecomNormalizeText($filters['name'] ?? '', 100);
    $employeeNo = wecomNormalizeText($filters['employee_no'] ?? '', 64);
    $limit = max(1, min(50, (int)($filters['limit'] ?? 20)));

    $where = ['s.status = 1'];
    $params = [];
    if ($keyword !== '') {
        $where[] = '(s.name LIKE ? OR s.phone LIKE ? OR s.employee_no LIKE ?)';
        $params[] = '%' . $keyword . '%';
        $params[] = '%' . $keyword . '%';
        $params[] = '%' . $keyword . '%';
    }
    if ($mobile !== '') {
        $where[] = 's.phone = ?';
        $params[] = $mobile;
    }
    if ($name !== '') {
        $where[] = 's.name LIKE ?';
        $params[] = '%' . $name . '%';
    }
    if ($employeeNo !== '') {
        $where[] = 's.employee_no = ?';
        $params[] = $employeeNo;
    }

    $sql = 'SELECT s.id, s.employee_no, s.name, s.phone, s.role, s.store_id, s.status, s.wecom_userid, s.wecom_name, s.wecom_status, st.name AS store_name
        FROM staffs s
        LEFT JOIN stores st ON st.id = s.store_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY
            CASE WHEN COALESCE(s.wecom_userid, \'\') = \'\' THEN 0 ELSE 1 END ASC,
            s.updated_at DESC,
            s.id DESC
        LIMIT ' . (int)$limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['store_id'] = isset($row['store_id']) ? (int)$row['store_id'] : null;
        $row['status'] = isset($row['status']) ? (int)$row['status'] : null;
        $row['wecom_status'] = isset($row['wecom_status']) ? (int)$row['wecom_status'] : null;
        $row['wecom_bound'] = trim((string)($row['wecom_userid'] ?? '')) !== '';
    }
    unset($row);

    return $rows;
}

function wecomBindStaffManually(PDO $pdo, int $staffId, array $user): array {
    wecomEnsureSchema($pdo);

    $wecomUserId = wecomNormalizeText($user['userid'] ?? '', 128);
    $wecomName = wecomNormalizeText($user['name'] ?? '', 100);
    $wecomMobile = wecomNormalizeText($user['mobile'] ?? '', 32);
    $departmentId = wecomNormalizeText($user['department_id'] ?? '', 128);
    $departmentPath = wecomNormalizeText($user['department_path'] ?? '', 255);
    $wecomStatus = isset($user['status']) ? (int)$user['status'] : 1;

    if ($staffId <= 0 || $wecomUserId === '') {
        throw new InvalidArgumentException('缺少绑定参数');
    }

    $stmt = $pdo->prepare('SELECT * FROM staffs WHERE id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) {
        throw new RuntimeException('员工不存在');
    }

    $stmt = $pdo->prepare('SELECT id, employee_no, name FROM staffs WHERE wecom_userid = ? AND id <> ? LIMIT 1');
    $stmt->execute([$wecomUserId, $staffId]);
    $conflict = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($conflict) {
        throw new RuntimeException('该企业微信成员已绑定到其他员工：' . (string)($conflict['name'] ?? $conflict['employee_no'] ?? ''));
    }

    $before = $staff;
    $stmt = $pdo->prepare('UPDATE staffs SET wecom_userid = ?, wecom_name = ?, wecom_mobile = ?, wecom_department_id = ?, wecom_department_path = ?, wecom_status = ?, wecom_bound_at = COALESCE(wecom_bound_at, NOW()), updated_at = NOW() WHERE id = ?');
    $stmt->execute([
        $wecomUserId,
        $wecomName,
        $wecomMobile,
        $departmentId,
        $departmentPath,
        $wecomStatus,
        $staffId,
    ]);

    $stmt = $pdo->prepare('SELECT * FROM staffs WHERE id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $after = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'before' => $before,
        'after' => $after,
    ];
}

function wecomUnbindStaffManually(PDO $pdo, int $staffId): array {
    wecomEnsureSchema($pdo);

    if ($staffId <= 0) {
        throw new InvalidArgumentException('缺少解绑参数');
    }

    $stmt = $pdo->prepare('SELECT * FROM staffs WHERE id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) {
        throw new RuntimeException('员工不存在');
    }

    $before = $staff;
    $stmt = $pdo->prepare('UPDATE staffs SET wecom_userid = NULL, wecom_name = NULL, wecom_mobile = NULL, wecom_department_id = NULL, wecom_department_path = NULL, wecom_status = 0, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$staffId]);

    $stmt = $pdo->prepare('SELECT * FROM staffs WHERE id = ? LIMIT 1');
    $stmt->execute([$staffId]);
    $after = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'before' => $before,
        'after' => $after,
    ];
}

function wecomListBindings(PDO $pdo, array $filters = []): array {
    wecomEnsureSchema($pdo);

    $page = max(1, (int)($filters['page'] ?? 1));
    $pageSize = max(1, min(100, (int)($filters['page_size'] ?? 20)));
    $offset = ($page - 1) * $pageSize;
    $keyword = wecomNormalizeText($filters['keyword'] ?? '', 100);
    $bindingStatus = wecomNormalizeText($filters['binding_status'] ?? '', 32);
    $storeId = max(0, (int)($filters['store_id'] ?? 0));
    $role = wecomNormalizeText($filters['role'] ?? '', 32);

    $where = ['s.status = 1'];
    $params = [];
    if ($keyword !== '') {
        $where[] = '(s.name LIKE ? OR s.phone LIKE ? OR s.employee_no LIKE ? OR s.wecom_userid LIKE ? OR s.wecom_name LIKE ?)';
        for ($i = 0; $i < 5; $i++) {
            $params[] = '%' . $keyword . '%';
        }
    }
    if ($storeId > 0) {
        $where[] = 's.store_id = ?';
        $params[] = $storeId;
    }
    if ($role !== '') {
        $where[] = 's.role = ?';
        $params[] = $role;
    }
    if ($bindingStatus === 'bound') {
        $where[] = "COALESCE(s.wecom_userid, '') <> ''";
    } elseif ($bindingStatus === 'unbound') {
        $where[] = "COALESCE(s.wecom_userid, '') = ''";
    } elseif ($bindingStatus === 'inactive') {
        $where[] = "COALESCE(s.wecom_userid, '') <> '' AND COALESCE(s.wecom_status, 0) = 0";
    } elseif ($bindingStatus === 'active') {
        $where[] = "COALESCE(s.wecom_userid, '') <> '' AND COALESCE(s.wecom_status, 0) = 1";
    }

    $whereSql = 'WHERE ' . implode(' AND ', $where);
    $countSql = 'SELECT COUNT(*) FROM staffs s ' . $whereSql;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $sql = 'SELECT s.id, s.employee_no, s.name, s.phone, s.role, s.store_id, s.status, s.wecom_userid, s.wecom_name, s.wecom_mobile, s.wecom_department_id, s.wecom_department_path, s.wecom_status, s.wecom_bound_at, st.name AS store_name
        FROM staffs s
        LEFT JOIN stores st ON st.id = s.store_id
        ' . $whereSql . '
        ORDER BY
            CASE WHEN COALESCE(s.wecom_userid, \'\') = \'\' THEN 1 ELSE 0 END ASC,
            CASE WHEN COALESCE(s.wecom_status, 0) = 1 THEN 0 ELSE 1 END ASC,
            s.updated_at DESC,
            s.id DESC
        LIMIT ' . (int)$pageSize . ' OFFSET ' . (int)$offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $summarySql = "SELECT
        SUM(CASE WHEN COALESCE(s.wecom_userid, '') <> '' THEN 1 ELSE 0 END) AS bound_total,
        SUM(CASE WHEN COALESCE(s.wecom_userid, '') = '' THEN 1 ELSE 0 END) AS unbound_total,
        SUM(CASE WHEN COALESCE(s.wecom_userid, '') <> '' AND COALESCE(s.wecom_status, 0) = 1 THEN 1 ELSE 0 END) AS active_total,
        SUM(CASE WHEN COALESCE(s.wecom_userid, '') <> '' AND COALESCE(s.wecom_status, 0) = 0 THEN 1 ELSE 0 END) AS inactive_total
        FROM staffs s
        WHERE s.status = 1";
    $summary = $pdo->query($summarySql)->fetch(PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as &$row) {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['store_id'] = isset($row['store_id']) ? (int)$row['store_id'] : null;
        $row['status'] = isset($row['status']) ? (int)$row['status'] : null;
        $row['wecom_status'] = isset($row['wecom_status']) ? (int)$row['wecom_status'] : null;
        $row['wecom_bound'] = trim((string)($row['wecom_userid'] ?? '')) !== '';
        $row['binding_status'] = !$row['wecom_bound'] ? 'unbound' : (($row['wecom_status'] ?? 0) === 1 ? 'active' : 'inactive');
    }
    unset($row);

    return [
        'summary' => [
            'bound_total' => (int)($summary['bound_total'] ?? 0),
            'unbound_total' => (int)($summary['unbound_total'] ?? 0),
            'active_total' => (int)($summary['active_total'] ?? 0),
            'inactive_total' => (int)($summary['inactive_total'] ?? 0),
        ],
        'pagination' => [
            'page' => $page,
            'page_size' => $pageSize,
            'total' => $total,
        ],
        'list' => $rows,
    ];
}

function wecomBuildIdentityAudit(PDO $pdo): array {
    wecomEnsureSchema($pdo);

    $staffUserIdMissingTotal = (int)($pdo->query("SELECT COUNT(*)
        FROM staffs
        WHERE status = 1 AND user_id IS NULL")->fetchColumn() ?: 0);

    $duplicatePhoneGroupsTotal = (int)($pdo->query("SELECT COUNT(*) FROM (
        SELECT phone
        FROM staffs
        WHERE status = 1 AND COALESCE(phone, '') <> ''
        GROUP BY phone
        HAVING COUNT(*) > 1
    ) t")->fetchColumn() ?: 0);

    $duplicateWecomUserIdGroupsTotal = (int)($pdo->query("SELECT COUNT(*) FROM (
        SELECT wecom_userid
        FROM staffs
        WHERE status = 1 AND COALESCE(wecom_userid, '') <> ''
        GROUP BY wecom_userid
        HAVING COUNT(*) > 1
    ) t")->fetchColumn() ?: 0);

    $phoneMismatchTotal = (int)($pdo->query("SELECT COUNT(*)
        FROM staffs s
        INNER JOIN wp_users u ON u.ID = s.user_id
        WHERE s.status = 1
          AND COALESCE(s.phone, '') <> ''
          AND COALESCE(u.user_login, '') <> ''
          AND s.phone <> u.user_login")->fetchColumn() ?: 0);

    $orphanStaffs = $pdo->query("SELECT id, employee_no, name, phone, role, store_id
        FROM staffs
        WHERE status = 1 AND user_id IS NULL
        ORDER BY updated_at DESC, id DESC
        LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $duplicatePhones = $pdo->query("SELECT phone, COUNT(*) AS duplicate_count,
        GROUP_CONCAT(CONCAT('#', id, ':', name, '/', COALESCE(employee_no, '-')) ORDER BY id SEPARATOR ' | ') AS staff_refs
        FROM staffs
        WHERE status = 1 AND COALESCE(phone, '') <> ''
        GROUP BY phone
        HAVING COUNT(*) > 1
        ORDER BY duplicate_count DESC, phone ASC
        LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $duplicateWecomUsers = $pdo->query("SELECT wecom_userid, COUNT(*) AS duplicate_count,
        GROUP_CONCAT(CONCAT('#', id, ':', name, '/', COALESCE(employee_no, '-')) ORDER BY id SEPARATOR ' | ') AS staff_refs
        FROM staffs
        WHERE status = 1 AND COALESCE(wecom_userid, '') <> ''
        GROUP BY wecom_userid
        HAVING COUNT(*) > 1
        ORDER BY duplicate_count DESC, wecom_userid ASC
        LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $phoneMismatchRows = $pdo->query("SELECT s.id, s.employee_no, s.name, s.phone, s.user_id, u.user_login, s.wecom_mobile, s.wecom_userid
        FROM staffs s
        INNER JOIN wp_users u ON u.ID = s.user_id
        WHERE s.status = 1
          AND COALESCE(s.phone, '') <> ''
          AND COALESCE(u.user_login, '') <> ''
          AND s.phone <> u.user_login
        ORDER BY s.updated_at DESC, s.id DESC
        LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $userIdOnlyTables = [];
    $stmt = $pdo->prepare("SELECT c.table_name
        FROM information_schema.columns c
        WHERE c.table_schema = ?
          AND c.column_name = 'user_id'
          AND c.table_name NOT IN ('staffs', 'wp_users', 'wecom_message_logs', 'wecom_sync_logs', 'admin_operation_logs', 'login_audit_logs')
          AND NOT EXISTS (
              SELECT 1 FROM information_schema.columns c2
              WHERE c2.table_schema = c.table_schema
                AND c2.table_name = c.table_name
                AND c2.column_name = 'staff_id'
          )
        ORDER BY c.table_name ASC");
    $stmt->execute([DB_NAME]);
    $tableNames = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

    foreach ($tableNames as $tableName) {
        $safeTable = str_replace('`', '``', (string)$tableName);
        $count = 0;
        try {
            $countStmt = $pdo->query("SELECT COUNT(*) FROM `{$safeTable}` WHERE user_id IS NOT NULL");
            $count = (int)($countStmt ? $countStmt->fetchColumn() : 0);
        } catch (Throwable $e) {
            $count = -1;
        }
        $userIdOnlyTables[] = [
            'table_name' => (string)$tableName,
            'rows_with_user_id' => $count,
        ];
    }

    foreach ($orphanStaffs as &$row) {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['store_id'] = isset($row['store_id']) ? (int)$row['store_id'] : null;
    }
    unset($row);

    foreach ($duplicatePhones as &$row) {
        $row['duplicate_count'] = (int)($row['duplicate_count'] ?? 0);
    }
    unset($row);

    foreach ($duplicateWecomUsers as &$row) {
        $row['duplicate_count'] = (int)($row['duplicate_count'] ?? 0);
    }
    unset($row);

    foreach ($phoneMismatchRows as &$row) {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['user_id'] = (int)($row['user_id'] ?? 0);
    }
    unset($row);

    return [
        'summary' => [
            'staff_user_id_missing' => $staffUserIdMissingTotal,
            'duplicate_phone_groups' => $duplicatePhoneGroupsTotal,
            'duplicate_wecom_userid_groups' => $duplicateWecomUserIdGroupsTotal,
            'phone_mismatch_total' => $phoneMismatchTotal,
            'user_id_only_table_total' => count($userIdOnlyTables),
        ],
        'staff_user_id_missing' => $orphanStaffs,
        'duplicate_phones' => $duplicatePhones,
        'duplicate_wecom_userids' => $duplicateWecomUsers,
        'phone_mismatches' => $phoneMismatchRows,
        'user_id_only_tables' => $userIdOnlyTables,
    ];
}

function wecomConsistencySampleStatus(int $missingJobs, int $missingMessages, int $failedMessages): string {
    if ($missingJobs <= 0 && $missingMessages <= 0 && $failedMessages <= 0) {
        return 'healthy';
    }
    if (($missingJobs + $missingMessages + $failedMessages) <= 3) {
        return 'warning';
    }
    return 'risk';
}

function wecomReminderMessageMapsByDate(PDO $pdo, string $reportDate, string $ruleConditionSql, array $params = []): array {
    $sql = "SELECT DISTINCT l.target_staff_id, l.status
        FROM wecom_message_logs l
        INNER JOIN mini_reminder_jobs j ON j.id = l.source_job_id
        WHERE l.source_type = 'reminder'
          AND j.reminder_date = ?
          AND {$ruleConditionSql}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$reportDate], $params));
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $messageMap = [];
    $failedMap = [];
    foreach ($rows as $row) {
        $staffId = (int)($row['target_staff_id'] ?? 0);
        if ($staffId <= 0) {
            continue;
        }
        $messageMap[$staffId] = true;
        if ((string)($row['status'] ?? '') === 'failed') {
            $failedMap[$staffId] = true;
        }
    }
    return [$messageMap, $failedMap];
}

function wecomBuildConsistencyDiagnostics(array $rows): array {
    $diagnostics = [
        'without_user_id_total' => 0,
        'without_wecom_binding_total' => 0,
        'inactive_wecom_total' => 0,
    ];
    foreach ($rows as $row) {
        if ((int)($row['user_id'] ?? 0) <= 0) {
            $diagnostics['without_user_id_total']++;
        }
        if (trim((string)($row['wecom_userid'] ?? '')) === '') {
            $diagnostics['without_wecom_binding_total']++;
        }
        if (trim((string)($row['wecom_userid'] ?? '')) !== '' && (int)($row['wecom_status'] ?? 0) !== 1) {
            $diagnostics['inactive_wecom_total']++;
        }
    }
    return $diagnostics;
}

function wecomBuildConsistencyReasonDetails(array $row): array {
    $reasons = [];
    $labels = [];
    if ((int)($row['user_id'] ?? 0) <= 0) {
        $reasons[] = 'missing_user_id';
        $labels[] = '缺 user_id';
    }
    if (trim((string)($row['wecom_userid'] ?? '')) === '') {
        $reasons[] = 'missing_wecom_binding';
        $labels[] = '未绑定企业微信';
    }
    if (trim((string)($row['wecom_userid'] ?? '')) !== '' && (int)($row['wecom_status'] ?? 0) !== 1) {
        $reasons[] = 'inactive_wecom';
        $labels[] = '企业微信停用';
    }
    return [
        'diagnostic_reasons' => $reasons,
        'diagnostic_labels' => $labels,
    ];
}

function wecomConsistencyAssigneeKey(array $row): string {
    $staffId = (int)($row['staff_id'] ?? 0);
    if ($staffId > 0) {
        return 'staff:' . $staffId;
    }
    $userId = (int)($row['user_id'] ?? 0);
    if ($userId > 0) {
        return 'user:' . $userId;
    }
    $name = trim((string)($row['staff_name'] ?? ''));
    $title = trim((string)($row['title'] ?? ''));
    return 'fallback:' . md5($name . '|' . $title);
}

function wecomBuildConsistencyAssigneeGroups(array $rows): array {
    $groups = [
        'missing_user_id' => [],
        'missing_wecom_binding' => [],
        'inactive_wecom' => [],
    ];
    foreach ($rows as $row) {
        $item = [
            'staff_id' => (int)($row['staff_id'] ?? 0),
            'user_id' => (int)($row['user_id'] ?? 0),
            'staff_name' => (string)($row['staff_name'] ?? ''),
            'store_id' => (int)($row['store_id'] ?? 0),
            'store_name' => (string)($row['store_name'] ?? ''),
            'role_code' => (string)($row['role_code'] ?? ''),
            'wecom_userid' => (string)($row['wecom_userid'] ?? ''),
            'wecom_status' => isset($row['wecom_status']) ? (int)$row['wecom_status'] : 0,
            'diagnostic_labels' => (array)($row['diagnostic_labels'] ?? []),
        ];
        foreach ((array)($row['diagnostic_reasons'] ?? []) as $reason) {
            if (!isset($groups[$reason])) {
                continue;
            }
            $groups[$reason][wecomConsistencyAssigneeKey($row)] = $item;
        }
    }

    $result = [];
    foreach ($groups as $reason => $items) {
        $result[$reason] = [
            'total' => count($items),
            'items' => array_slice(array_values($items), 0, 20),
        ];
    }
    return $result;
}

function wecomMergeConsistencyAssigneeGroups(array ...$groupSets): array {
    $merged = [
        'missing_user_id' => [],
        'missing_wecom_binding' => [],
        'inactive_wecom' => [],
    ];
    foreach ($groupSets as $groupSet) {
        foreach ($merged as $reason => $items) {
            $list = $groupSet[$reason]['items'] ?? [];
            foreach ($list as $item) {
                $merged[$reason][wecomConsistencyAssigneeKey($item)] = $item;
            }
        }
    }

    $result = [];
    foreach ($merged as $reason => $items) {
        $result[$reason] = [
            'total' => count($items),
            'items' => array_slice(array_values($items), 0, 30),
        ];
    }
    return $result;
}

function wecomBuildWorkloadConsistencyAudit(PDO $pdo, string $reportDate): array {
    $staffRows = $pdo->query("SELECT s.id AS staff_id, s.user_id, s.name AS staff_name, s.role, s.store_id, st.name AS store_name, s.wecom_userid, s.wecom_status
        FROM staffs s
        LEFT JOIN stores st ON st.id = s.store_id
        WHERE s.status = 1
          AND s.store_id IS NOT NULL
          AND s.role IN ('sales', 'coach', 'consultant', 'sale', '销售', '教练', '实习销售', '实习教练')
        ORDER BY s.store_id ASC, s.id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$staffRows) {
        return ['status' => 'healthy', 'summary' => ['expected_total' => 0, 'job_staff_total' => 0, 'station_staff_total' => 0, 'message_staff_total' => 0, 'missing_job_total' => 0, 'missing_message_total' => 0, 'failed_message_total' => 0], 'samples' => []];
    }

    $reportStmt = $pdo->prepare("SELECT * FROM workload_daily_reports WHERE report_date = ? AND store_id = ? AND staff_id = ? AND role_code = ? LIMIT 1");
    $expectedRows = [];
    foreach ($staffRows as $staff) {
        $roleCode = appRoleCode((string)($staff['role'] ?? ''));
        if (!in_array($roleCode, ['sales', 'coach'], true)) {
            continue;
        }
        $reportStmt->execute([$reportDate, (int)($staff['store_id'] ?? 0), (int)($staff['staff_id'] ?? 0), $roleCode]);
        $report = $reportStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $status = $report ? (string)($report['submit_status'] ?? 'draft') : 'missing';
        $gapCount = $report && (int)($report['id'] ?? 0) > 0 ? workloadReportEvidenceGapCount($pdo, (int)$report['id'], $roleCode) : 0;
        if ($status === 'submitted' && $gapCount <= 0) {
            continue;
        }
        $expectedRows[(int)$staff['staff_id']] = [
            'staff_id' => (int)$staff['staff_id'],
            'user_id' => (int)($staff['user_id'] ?? 0),
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'store_name' => (string)($staff['store_name'] ?? ''),
            'role_code' => $roleCode,
            'wecom_userid' => (string)($staff['wecom_userid'] ?? ''),
            'wecom_status' => isset($staff['wecom_status']) ? (int)$staff['wecom_status'] : 0,
            'reason' => $gapCount > 0 ? ('缺少' . $gapCount . '项凭证') : ($status === 'draft' ? '草稿未提交' : '未填写日报'),
        ] + wecomBuildConsistencyReasonDetails($staff);
    }

    $jobStaffIds = $pdo->prepare("SELECT DISTINCT target_staff_id FROM mini_reminder_jobs WHERE reminder_date = ? AND rule_code IN ('workload_daily_first', 'workload_daily_second')");
    $jobStaffIds->execute([$reportDate]);
    $jobStaffMap = array_fill_keys(array_map('intval', $jobStaffIds->fetchAll(PDO::FETCH_COLUMN) ?: []), true);

    $stationStaffIds = $pdo->prepare("SELECT DISTINCT j.target_staff_id
        FROM mini_user_notifications n
        INNER JOIN mini_reminder_jobs j ON j.id = n.source_job_id
        WHERE n.source_type = 'reminder'
          AND n.source_key IN ('workload_daily_first', 'workload_daily_second')
          AND j.reminder_date = ?");
    $stationStaffIds->execute([$reportDate]);
    $stationStaffMap = array_fill_keys(array_map('intval', $stationStaffIds->fetchAll(PDO::FETCH_COLUMN) ?: []), true);

    [$messageStaffMap, $failedMessageMap] = wecomReminderMessageMapsByDate($pdo, $reportDate, "j.rule_code IN ('workload_daily_first', 'workload_daily_second')");

    $missingJobSamples = [];
    $missingMessageSamples = [];
    $failedMessageSamples = [];
    foreach ($expectedRows as $staffId => $row) {
        if (!isset($jobStaffMap[$staffId]) && count($missingJobSamples) < 8) {
            $missingJobSamples[] = $row;
        }
        if (!isset($messageStaffMap[$staffId]) && count($missingMessageSamples) < 8) {
            $missingMessageSamples[] = $row;
        }
        if (isset($failedMessageMap[$staffId]) && count($failedMessageSamples) < 8) {
            $failedMessageSamples[] = $row;
        }
    }

    $summary = [
        'expected_total' => count($expectedRows),
        'job_staff_total' => count($jobStaffMap),
        'station_staff_total' => count($stationStaffMap),
        'message_staff_total' => count($messageStaffMap),
        'missing_job_total' => max(0, count($expectedRows) - count(array_intersect_key($expectedRows, $jobStaffMap))),
        'missing_message_total' => max(0, count($expectedRows) - count(array_intersect_key($expectedRows, $messageStaffMap))),
        'failed_message_total' => count($failedMessageMap),
        'diagnostics' => wecomBuildConsistencyDiagnostics($expectedRows),
        'assignee_groups' => wecomBuildConsistencyAssigneeGroups($expectedRows),
    ];

    return [
        'status' => wecomConsistencySampleStatus($summary['missing_job_total'], $summary['missing_message_total'], $summary['failed_message_total']),
        'summary' => $summary,
        'samples' => [
            'missing_jobs' => $missingJobSamples,
            'missing_messages' => $missingMessageSamples,
            'failed_messages' => $failedMessageSamples,
        ],
    ];
}

function wecomBuildPolicyConsistencyAudit(PDO $pdo, string $reportDate): array {
    $expectedStmt = $pdo->prepare("SELECT n.id, n.user_id, s.id AS staff_id, s.name AS staff_name, st.name AS store_name, n.type, n.title,
            s.wecom_userid, s.wecom_status
        FROM policy_notifications n
        LEFT JOIN staffs s ON s.user_id = n.user_id AND s.status = 1
        LEFT JOIN stores st ON st.id = s.store_id
        WHERE DATE(n.created_at) = ?");
    $expectedStmt->execute([$reportDate]);
    $expectedRows = $expectedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $expectedMap = [];
    foreach ($expectedRows as $row) {
        $expectedMap[(int)($row['id'] ?? 0)] = [
            'notification_id' => (int)($row['id'] ?? 0),
            'staff_id' => (int)($row['staff_id'] ?? 0),
            'staff_name' => (string)($row['staff_name'] ?? ''),
            'store_name' => (string)($row['store_name'] ?? ''),
            'title' => (string)($row['title'] ?? ''),
            'type' => (string)($row['type'] ?? ''),
            'user_id' => (int)($row['user_id'] ?? 0),
            'wecom_userid' => (string)($row['wecom_userid'] ?? ''),
            'wecom_status' => isset($row['wecom_status']) ? (int)$row['wecom_status'] : 0,
        ] + wecomBuildConsistencyReasonDetails($row);
    }

    $pendingTotalStmt = $pdo->query("SELECT COUNT(*) FROM policy_notifications WHERE is_read = 0 OR (type = 'confirm' AND is_confirmed = 0)");
    $pendingTotal = (int)($pendingTotalStmt->fetchColumn() ?: 0);

    $messageStmt = $pdo->prepare("SELECT DISTINCT source_job_id, status FROM wecom_message_logs WHERE DATE(created_at) = ? AND source_type = 'policy'");
    $messageStmt->execute([$reportDate]);
    $messageRows = $messageStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $messageMap = [];
    $failedMap = [];
    foreach ($messageRows as $row) {
        $notificationId = (int)($row['source_job_id'] ?? 0);
        if ($notificationId <= 0) {
            continue;
        }
        $messageMap[$notificationId] = true;
        if ((string)($row['status'] ?? '') === 'failed') {
            $failedMap[$notificationId] = true;
        }
    }

    $missingMessageSamples = [];
    $failedMessageSamples = [];
    foreach ($expectedMap as $notificationId => $row) {
        if (!isset($messageMap[$notificationId]) && count($missingMessageSamples) < 8) {
            $missingMessageSamples[] = $row;
        }
        if (isset($failedMap[$notificationId]) && count($failedMessageSamples) < 8) {
            $failedMessageSamples[] = $row;
        }
    }

    $summary = [
        'today_notification_total' => count($expectedMap),
        'station_pending_total' => $pendingTotal,
        'message_total' => count($messageMap),
        'missing_message_total' => max(0, count($expectedMap) - count(array_intersect_key($expectedMap, $messageMap))),
        'failed_message_total' => count($failedMap),
        'diagnostics' => wecomBuildConsistencyDiagnostics($expectedMap),
        'assignee_groups' => wecomBuildConsistencyAssigneeGroups($expectedMap),
    ];

    return [
        'status' => wecomConsistencySampleStatus(0, $summary['missing_message_total'], $summary['failed_message_total']),
        'summary' => $summary,
        'samples' => [
            'missing_messages' => $missingMessageSamples,
            'failed_messages' => $failedMessageSamples,
        ],
    ];
}

function wecomBuildLearningConsistencyAudit(PDO $pdo, string $reportDate): array {
    $staffRows = $pdo->query("SELECT s.id AS staff_id, s.user_id, s.name AS staff_name, st.name AS store_name, s.wecom_userid, s.wecom_status
        FROM staffs s
        LEFT JOIN stores st ON st.id = s.store_id
        WHERE s.status = 1 AND s.user_id IS NOT NULL AND s.user_id > 0
        ORDER BY s.store_id ASC, s.id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $courseStmt = $pdo->prepare("SELECT c.id, c.title, COALESCE(ucp.progress, 0) AS progress, COALESCE(ucp.status, 0) AS user_status
        FROM courses c
        LEFT JOIN user_course_progress ucp ON ucp.course_id = c.id AND ucp.user_id = ?
        WHERE c.status = 1 AND c.is_required = 1 AND COALESCE(ucp.status, 0) <> 1
        ORDER BY COALESCE(ucp.progress, 0) DESC, c.sort_order ASC, c.id DESC
        LIMIT 1");
    $expectedRows = [];
    foreach ($staffRows as $staff) {
        $courseStmt->execute([(int)($staff['user_id'] ?? 0)]);
        $course = $courseStmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$course) {
            continue;
        }
        $expectedRows[(int)$staff['staff_id']] = [
            'staff_id' => (int)$staff['staff_id'],
            'user_id' => (int)($staff['user_id'] ?? 0),
            'staff_name' => (string)($staff['staff_name'] ?? ''),
            'store_name' => (string)($staff['store_name'] ?? ''),
            'course_id' => (int)($course['id'] ?? 0),
            'course_title' => (string)($course['title'] ?? ''),
            'progress' => (int)($course['progress'] ?? 0),
            'wecom_userid' => (string)($staff['wecom_userid'] ?? ''),
            'wecom_status' => isset($staff['wecom_status']) ? (int)$staff['wecom_status'] : 0,
        ] + wecomBuildConsistencyReasonDetails($staff);
    }

    $jobStmt = $pdo->prepare("SELECT DISTINCT target_staff_id FROM mini_reminder_jobs WHERE reminder_date = ? AND rule_code = 'learning_required_daily'");
    $jobStmt->execute([$reportDate]);
    $jobMap = array_fill_keys(array_map('intval', $jobStmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);

    $stationStmt = $pdo->prepare("SELECT DISTINCT j.target_staff_id
        FROM mini_user_notifications n
        INNER JOIN mini_reminder_jobs j ON j.id = n.source_job_id
        WHERE n.source_type = 'reminder' AND n.source_key = 'learning_required_daily' AND j.reminder_date = ?");
    $stationStmt->execute([$reportDate]);
    $stationMap = array_fill_keys(array_map('intval', $stationStmt->fetchAll(PDO::FETCH_COLUMN) ?: []), true);

    [$messageMap, $failedMap] = wecomReminderMessageMapsByDate($pdo, $reportDate, "j.rule_code = ?", ['learning_required_daily']);

    $missingJobSamples = [];
    $missingMessageSamples = [];
    $failedMessageSamples = [];
    foreach ($expectedRows as $staffId => $row) {
        if (!isset($jobMap[$staffId]) && count($missingJobSamples) < 8) {
            $missingJobSamples[] = $row;
        }
        if (!isset($messageMap[$staffId]) && count($missingMessageSamples) < 8) {
            $missingMessageSamples[] = $row;
        }
        if (isset($failedMap[$staffId]) && count($failedMessageSamples) < 8) {
            $failedMessageSamples[] = $row;
        }
    }

    $summary = [
        'expected_total' => count($expectedRows),
        'job_staff_total' => count($jobMap),
        'station_staff_total' => count($stationMap),
        'message_staff_total' => count($messageMap),
        'missing_job_total' => max(0, count($expectedRows) - count(array_intersect_key($expectedRows, $jobMap))),
        'missing_message_total' => max(0, count($expectedRows) - count(array_intersect_key($expectedRows, $messageMap))),
        'failed_message_total' => count($failedMap),
        'diagnostics' => wecomBuildConsistencyDiagnostics($expectedRows),
        'assignee_groups' => wecomBuildConsistencyAssigneeGroups($expectedRows),
    ];

    return [
        'status' => wecomConsistencySampleStatus($summary['missing_job_total'], $summary['missing_message_total'], $summary['failed_message_total']),
        'summary' => $summary,
        'samples' => [
            'missing_jobs' => $missingJobSamples,
            'missing_messages' => $missingMessageSamples,
            'failed_messages' => $failedMessageSamples,
        ],
    ];
}

function wecomBuildConsistencyAudit(PDO $pdo, string $reportDate = ''): array {
    wecomEnsureSchema($pdo);
    $reportDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $reportDate) ? $reportDate : date('Y-m-d');

    $workload = wecomBuildWorkloadConsistencyAudit($pdo, $reportDate);
    $policy = wecomBuildPolicyConsistencyAudit($pdo, $reportDate);
    $learning = wecomBuildLearningConsistencyAudit($pdo, $reportDate);

    return [
        'audited_date' => $reportDate,
        'overall' => [
            'assignee_groups' => wecomMergeConsistencyAssigneeGroups(
                $workload['summary']['assignee_groups'] ?? [],
                $policy['summary']['assignee_groups'] ?? [],
                $learning['summary']['assignee_groups'] ?? []
            ),
        ],
        'sections' => [
            'workload' => $workload,
            'policy' => $policy,
            'learning' => $learning,
        ],
    ];
}
