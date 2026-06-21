<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/common/context.php';

function workloadDb(): PDO {
    return getDB();
}

function workloadEnsureSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS metric_definitions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        metric_code VARCHAR(64) NOT NULL,
        metric_name VARCHAR(128) NOT NULL,
        role_code VARCHAR(32) NOT NULL,
        metric_group VARCHAR(32) NOT NULL,
        metric_category VARCHAR(32) NOT NULL,
        unit VARCHAR(32) NOT NULL DEFAULT 'count',
        value_type VARCHAR(16) NOT NULL DEFAULT 'number',
        is_required TINYINT(1) NOT NULL DEFAULT 0,
        is_system_calculated TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        default_value DECIMAL(18,2) DEFAULT NULL,
        min_value DECIMAL(18,2) DEFAULT NULL,
        max_value DECIMAL(18,2) DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        description VARCHAR(255) NOT NULL DEFAULT '',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_metric_code (metric_code),
        KEY idx_role_group (role_code, metric_group, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS workload_templates (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        template_code VARCHAR(64) NOT NULL,
        template_name VARCHAR(128) NOT NULL,
        role_code VARCHAR(32) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        version_no INT NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_template_code (template_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS workload_template_items (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        template_id BIGINT UNSIGNED NOT NULL,
        metric_id BIGINT UNSIGNED NOT NULL,
        is_visible TINYINT(1) NOT NULL DEFAULT 1,
        is_editable TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uk_template_metric (template_id, metric_id),
        KEY idx_template_sort (template_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS workload_daily_reports (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        report_date DATE NOT NULL,
        store_id BIGINT UNSIGNED NOT NULL,
        staff_id BIGINT UNSIGNED NOT NULL,
        role_code VARCHAR(32) NOT NULL,
        template_id BIGINT UNSIGNED DEFAULT NULL,
        submit_status VARCHAR(16) NOT NULL DEFAULT 'draft',
        source VARCHAR(16) NOT NULL DEFAULT 'h5',
        remarks VARCHAR(255) NOT NULL DEFAULT '',
        submitted_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_report_unique (report_date, store_id, staff_id, role_code),
        KEY idx_store_date (store_id, report_date),
        KEY idx_staff_date (staff_id, report_date),
        KEY idx_role_date (role_code, report_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS workload_daily_report_values (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        report_id BIGINT UNSIGNED NOT NULL,
        metric_id BIGINT UNSIGNED NOT NULL,
        numeric_value DECIMAL(18,2) DEFAULT NULL,
        text_value VARCHAR(255) DEFAULT NULL,
        json_value JSON DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_report_metric (report_id, metric_id),
        KEY idx_metric (metric_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    workloadSeedDefaults($pdo);
    workloadEnsureAuditSchema($pdo);
    workloadEnsureAuditRules($pdo);
}

function workloadSeedDefaults(PDO $pdo): void {
    $metrics = [
        ['sales_resources','新增资源数','sales','daily_input','behavior','count',1,10],
        ['sales_calls','外呼数','sales','daily_input','behavior','count',0,20],
        ['sales_wechat_reach','微信触达数','sales','daily_input','behavior','count',0,30],
        ['sales_plan_visit','计划邀约数','sales','daily_input','process','count',1,40],
        ['sales_actual_visit','实际邀约数','sales','daily_input','process','count',1,50],
        ['sales_actual_arrive','实际到店数','sales','daily_input','process','count',1,60],
        ['sales_deal_count','成交人数','sales','daily_input','result','count',1,70],
        ['sales_new_revenue','新签金额','sales','daily_input','result','yuan',0,80],
        ['coach_plan_hours','计划耗课节数','coach','daily_input','process','class',1,10],
        ['coach_actual_hours','实际耗课节数','coach','daily_input','process','class',1,20],
        ['coach_plan_comm','计划沟通人数','coach','daily_input','process','count',0,30],
        ['coach_actual_comm','实际沟通人数','coach','daily_input','behavior','count',1,40],
        ['coach_body_test','体测人数','coach','daily_input','behavior','count',0,50],
        ['coach_camp_recommend','暑期营推荐人数','coach','daily_input','result','count',0,60],
        ['coach_renew_count','续费单数','coach','daily_input','result','count',0,70],
        ['sales_moments','朋友圈','sales','daily_input','process','count',0,85],
        ['sales_douyin_review','抖音好评','sales','daily_input','result','count',0,90],
        ['sales_meituan_review','美团好评','sales','daily_input','result','count',0,95],
        ['sales_small_package','小课包','sales','daily_input','result','count',0,96],
        ['sales_social_video','拍摄视频上传社交媒体','sales','daily_input','process','count',0,97],
        ['coach_motion_plan','运动规划发送家长','coach','daily_input','process','count',0,75],
        ['coach_parent_comm','家长重点沟通','coach','daily_input','process','count',0,85],
        ['coach_moments','朋友圈','coach','daily_input','process','count',0,90],
        ['coach_douyin_review','抖音好评','coach','daily_input','result','count',0,95],
        ['coach_meituan_review','美团好评','coach','daily_input','result','count',0,100],
        ['coach_small_package','小课包','coach','daily_input','result','count',0,105],
        ['coach_social_video','拍摄视频上传社交媒体','coach','daily_input','process','count',0,110],
    ];
    $stmt = $pdo->prepare("INSERT INTO metric_definitions (metric_code, metric_name, role_code, metric_group, metric_category, unit, value_type, is_required, is_system_calculated, default_value, min_value, sort_order, description)
        VALUES (?, ?, ?, ?, ?, ?, 'number', ?, 0, 0, 0, ?, '')
        ON DUPLICATE KEY UPDATE metric_name=VALUES(metric_name), role_code=VALUES(role_code), metric_group=VALUES(metric_group), metric_category=VALUES(metric_category), unit=VALUES(unit), is_required=VALUES(is_required), sort_order=VALUES(sort_order), is_active=1");
    foreach ($metrics as $m) {
        $stmt->execute($m);
    }

    $templates = [
        ['sales_daily_v1','销售日报模板 V1','sales'],
        ['coach_daily_v1','教练日报模板 V1','coach'],
    ];
    $tplStmt = $pdo->prepare("INSERT INTO workload_templates (template_code, template_name, role_code, is_active, version_no) VALUES (?, ?, ?, 1, 1) ON DUPLICATE KEY UPDATE template_name=VALUES(template_name), role_code=VALUES(role_code), is_active=1");
    foreach ($templates as $tpl) {
        $tplStmt->execute($tpl);
    }

    $pdo->exec("INSERT IGNORE INTO workload_template_items (template_id, metric_id, is_visible, is_editable, sort_order)
        SELECT t.id, m.id, 1, CASE WHEN m.is_system_calculated=1 THEN 0 ELSE 1 END, m.sort_order
        FROM workload_templates t
        JOIN metric_definitions m ON m.role_code=t.role_code AND m.metric_group='daily_input'");
}

function workloadAllowedRoleForContext(array $context, string $role): bool {
    $role = appRoleCode($role);
    if (appCanEditAll($context)) return true;
    return (string)($context['role'] ?? '') === $role;
}

function workloadTemplate(PDO $pdo, string $role): ?array {
    $stmt = $pdo->prepare("SELECT * FROM workload_templates WHERE role_code=? AND is_active=1 ORDER BY version_no DESC, id DESC LIMIT 1");
    $stmt->execute([$role]);
    $template = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$template) return null;

    $itemStmt = $pdo->prepare("SELECT m.*, r.need_evidence, r.min_evidence_count, r.max_evidence_count, i.is_visible, i.is_editable, i.sort_order AS item_sort_order
        FROM workload_template_items i
        JOIN metric_definitions m ON m.id=i.metric_id
        LEFT JOIN workload_metric_rules r ON r.role_code = m.role_code AND r.metric_code = m.metric_code
        WHERE i.template_id=? AND m.is_active=1 AND i.is_visible=1
        ORDER BY i.sort_order, m.sort_order, m.id");
    $itemStmt->execute([(int)$template['id']]);
    $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

    return ['template' => $template, 'items' => $items];
}

function workloadMetricMap(PDO $pdo, string $role): array {
    $stmt = $pdo->prepare("SELECT * FROM metric_definitions WHERE role_code=? AND metric_group='daily_input' AND is_active=1");
    $stmt->execute([$role]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $map[$row['metric_code']] = $row;
    }
    return $map;
}

function workloadGetMetricRules(PDO $pdo, string $role): array {
    $stmt = $pdo->prepare("SELECT * FROM workload_metric_rules WHERE role_code=? AND enabled=1");
    $stmt->execute([$role]);
    $rules = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rules[$row['metric_code']] = $row;
    }
    return $rules;
}

function workloadEvidenceMaxLimit(array $rule): int {
    return min(10, max(1, (int)($rule['max_evidence_count'] ?? 3)));
}

function workloadEvidenceMinLimit(array $rule): int {
    $min = max(1, (int)($rule['min_evidence_count'] ?? 1));
    return min($min, workloadEvidenceMaxLimit($rule));
}

function workloadPublicBaseUrl(): string {
    $https = (string)($_SERVER['HTTPS'] ?? '');
    $forwardedProto = trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $scheme = $forwardedProto !== '' ? $forwardedProto : (($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http');
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') {
        return '';
    }
    return $scheme . '://' . $host;
}

function workloadPublicUrl(string $path): string {
    $path = trim($path);
    if ($path === '' || preg_match('#^https?://#i', $path)) {
        return $path;
    }
    $baseUrl = workloadPublicBaseUrl();
    if ($baseUrl === '') {
        return $path;
    }
    return $baseUrl . '/' . ltrim($path, '/');
}

function workloadNormalizeEvidenceRow(array $row): array {
    if (isset($row['file_url'])) {
        $row['file_url'] = workloadPublicUrl((string)$row['file_url']);
    }
    return $row;
}

function workloadNormalizeEvidenceRows(array $rows): array {
    return array_map(static function(array $row): array {
        return workloadNormalizeEvidenceRow($row);
    }, $rows);
}

function workloadEvidenceStorageDir(): string {
    return dirname(__DIR__, 2) . '/uploads/workload/evidence/';
}

function workloadResolveEvidenceFilePath(string $fileUrl): string {
    $relativePath = parse_url($fileUrl, PHP_URL_PATH);
    $relativePath = is_string($relativePath) ? $relativePath : $fileUrl;
    if (strncmp($relativePath, '/uploads/workload/evidence/', 27) !== 0) {
        return '';
    }
    return workloadEvidenceStorageDir() . basename($relativePath);
}

function workloadEnsureAuditSchema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS workload_metric_rules (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        metric_code VARCHAR(64) NOT NULL,
        role_code VARCHAR(32) NOT NULL,
        need_evidence TINYINT(1) NOT NULL DEFAULT 0,
        min_evidence_count TINYINT NOT NULL DEFAULT 1,
        max_evidence_count TINYINT NOT NULL DEFAULT 10,
        audit_mode VARCHAR(16) NOT NULL DEFAULT 'none',
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uk_role_metric (role_code, metric_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS workload_evidences (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        report_id BIGINT UNSIGNED NOT NULL,
        staff_id BIGINT UNSIGNED NOT NULL,
        store_id BIGINT UNSIGNED NOT NULL,
        role_code VARCHAR(32) NOT NULL,
        metric_code VARCHAR(64) NOT NULL,
        file_url VARCHAR(512) NOT NULL,
        file_name VARCHAR(255) NOT NULL DEFAULT '',
        file_size INT UNSIGNED NOT NULL DEFAULT 0,
        mime_type VARCHAR(64) NOT NULL DEFAULT 'image/jpeg',
        sort_order INT NOT NULL DEFAULT 0,
        remark VARCHAR(255) NOT NULL DEFAULT '',
        deleted_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_report_metric (report_id, metric_code),
        KEY idx_deleted_at (deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ([
        'deleted_at' => "ALTER TABLE workload_evidences ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER remark",
    ] as $column => $sql) {
        if (!workloadColumnExists($pdo, 'workload_evidences', $column)) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS workload_audit_tasks (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        report_id BIGINT UNSIGNED NOT NULL,
        staff_id BIGINT UNSIGNED NOT NULL,
        store_id BIGINT UNSIGNED NOT NULL,
        role_code VARCHAR(32) NOT NULL,
        metric_code VARCHAR(64) NOT NULL,
        submitted_value DECIMAL(18,2) NOT NULL DEFAULT 0,
        audit_status VARCHAR(20) NOT NULL DEFAULT 'pending',
        auditor_staff_id BIGINT UNSIGNED DEFAULT NULL,
        audit_comment VARCHAR(255) DEFAULT NULL,
        audited_at DATETIME DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_status_date (audit_status, created_at),
        KEY idx_report_metric (report_id, metric_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS workload_audit_logs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        task_id BIGINT UNSIGNED NOT NULL,
        operator_staff_id BIGINT UNSIGNED NOT NULL,
        before_status VARCHAR(20) NOT NULL DEFAULT '',
        after_status VARCHAR(20) NOT NULL DEFAULT '',
        comment VARCHAR(255) DEFAULT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_task_id (task_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function workloadColumnExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '` LIKE ' . $pdo->quote($column));
    return (bool)($stmt ? $stmt->fetchColumn() : false);
}

function workloadEnsureAuditRules(PDO $pdo): void {
    $stmt = $pdo->prepare("INSERT INTO workload_metric_rules (metric_code, role_code, need_evidence, min_evidence_count, max_evidence_count, audit_mode)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE need_evidence=VALUES(need_evidence), min_evidence_count=VALUES(min_evidence_count), max_evidence_count=VALUES(max_evidence_count), audit_mode=VALUES(audit_mode), enabled=1");
    $stmt->execute(['sales_calls', 'sales', 1, 1, 10, 'full']);
    $stmt->execute(['sales_moments', 'sales', 1, 1, 10, 'full']);
    $stmt->execute(['sales_douyin_review', 'sales', 1, 1, 10, 'full']);
    $stmt->execute(['sales_meituan_review', 'sales', 1, 1, 10, 'full']);
    $stmt->execute(['sales_small_package', 'sales', 1, 1, 10, 'full']);
    $stmt->execute(['sales_social_video', 'sales', 1, 1, 10, 'full']);
    $stmt->execute(['coach_body_test', 'coach', 1, 1, 10, 'full']);
    $stmt->execute(['coach_motion_plan', 'coach', 1, 1, 10, 'full']);
    $stmt->execute(['coach_parent_comm', 'coach', 1, 1, 10, 'full']);
    $stmt->execute(['coach_moments', 'coach', 1, 1, 10, 'full']);
    $stmt->execute(['coach_douyin_review', 'coach', 1, 1, 10, 'full']);
    $stmt->execute(['coach_meituan_review', 'coach', 1, 1, 10, 'full']);
    $stmt->execute(['coach_small_package', 'coach', 1, 1, 10, 'full']);
    $stmt->execute(['coach_social_video', 'coach', 1, 1, 10, 'full']);
    $pdo->exec("UPDATE workload_metric_rules SET max_evidence_count = 10 WHERE need_evidence = 1 AND max_evidence_count < 10");
}
