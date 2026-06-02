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

    $itemStmt = $pdo->prepare("SELECT m.* , i.is_visible, i.is_editable, i.sort_order AS item_sort_order
        FROM workload_template_items i
        JOIN metric_definitions m ON m.id=i.metric_id
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
