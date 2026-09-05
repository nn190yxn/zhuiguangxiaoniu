SET NAMES utf8mb4;

CREATE TABLE stores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(128) NOT NULL,
    status TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE staffs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    employee_no VARCHAR(64) NULL,
    name VARCHAR(100) NOT NULL,
    store_id BIGINT UNSIGNED NULL,
    role VARCHAR(50) NOT NULL DEFAULT 'staff',
    job_title VARCHAR(100) NULL,
    entry_date DATE NULL,
    status TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE metric_definitions (
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
    default_value DECIMAL(18,2) NULL,
    min_value DECIMAL(18,2) NULL,
    max_value DECIMAL(18,2) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    description VARCHAR(255) NOT NULL DEFAULT '',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_metric_code (metric_code),
    KEY idx_role_group (role_code, metric_group, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE workload_templates (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE workload_template_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_id BIGINT UNSIGNED NOT NULL,
    metric_id BIGINT UNSIGNED NOT NULL,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    is_editable TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uk_template_metric (template_id, metric_id),
    KEY idx_template_sort (template_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE workload_daily_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_date DATE NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    role_code VARCHAR(32) NOT NULL,
    template_id BIGINT UNSIGNED NULL,
    submit_status VARCHAR(16) NOT NULL DEFAULT 'draft',
    source VARCHAR(16) NOT NULL DEFAULT 'h5',
    remarks VARCHAR(255) NOT NULL DEFAULT '',
    submitted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_report_unique (report_date, store_id, staff_id, role_code),
    KEY idx_store_date (store_id, report_date),
    KEY idx_staff_date (staff_id, report_date),
    KEY idx_role_date (role_code, report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE workload_daily_report_values (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    metric_id BIGINT UNSIGNED NOT NULL,
    numeric_value DECIMAL(18,2) NULL,
    text_value VARCHAR(255) NULL,
    json_value JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_report_metric (report_id, metric_id),
    KEY idx_metric (metric_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE workload_metric_rules (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE workload_evidences (
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
    deleted_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_report_metric (report_id, metric_code),
    KEY idx_deleted_at (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE workload_audit_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    staff_id BIGINT UNSIGNED NOT NULL,
    store_id BIGINT UNSIGNED NOT NULL,
    role_code VARCHAR(32) NOT NULL,
    metric_code VARCHAR(64) NOT NULL,
    submitted_value DECIMAL(18,2) NOT NULL DEFAULT 0,
    audit_status VARCHAR(20) NOT NULL DEFAULT 'pending',
    auditor_staff_id BIGINT UNSIGNED NULL,
    audit_comment VARCHAR(255) NULL,
    audited_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_status_date (audit_status, created_at),
    KEY idx_report_metric (report_id, metric_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE knowledge_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(100) NOT NULL,
    code VARCHAR(64) NOT NULL,
    type VARCHAR(32) NOT NULL DEFAULT 'knowledge_card',
    description VARCHAR(500) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    status TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_knowledge_categories_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE knowledge_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_code VARCHAR(96) NULL,
    content_type VARCHAR(32) NULL,
    domain_code VARCHAR(64) NULL,
    risk_level VARCHAR(8) NULL,
    publication_status VARCHAR(16) NOT NULL DEFAULT 'published',
    source_batch_id BIGINT UNSIGNED NULL,
    current_version_id BIGINT UNSIGNED NULL,
    category_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    summary TEXT NULL,
    content LONGTEXT NOT NULL,
    status TINYINT NOT NULL DEFAULT 1,
    is_public TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_knowledge_items_item_code (item_code),
    KEY idx_knowledge_items_publication (publication_status, status, is_public),
    KEY idx_knowledge_items_content_type (content_type, publication_status),
    KEY idx_knowledge_items_domain_risk (domain_code, risk_level, publication_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE knowledge_item_versions (
    version_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    knowledge_item_id INT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    summary TEXT NULL,
    content LONGTEXT NOT NULL,
    content_format VARCHAR(16) NOT NULL DEFAULT 'markdown',
    content_type VARCHAR(32) NULL,
    domain_code VARCHAR(64) NULL,
    risk_level VARCHAR(8) NULL,
    subject VARCHAR(255) NULL,
    age_group VARCHAR(255) NULL,
    training_type VARCHAR(255) NULL,
    difficulty TINYINT UNSIGNED NULL,
    tags_json JSON NULL,
    source_snapshot_json JSON NULL,
    raw_markdown LONGTEXT NULL,
    change_reason VARCHAR(500) NOT NULL,
    changed_by INT UNSIGNED NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version_id),
    UNIQUE KEY uk_knowledge_item_versions_number (knowledge_item_id, version_no),
    KEY idx_knowledge_item_versions_current (knowledge_item_id, status, created_at),
    KEY idx_knowledge_item_versions_changed_by (changed_by, created_at),
    CONSTRAINT fk_baseline_knowledge_version_item FOREIGN KEY (knowledge_item_id) REFERENCES knowledge_items (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lesson_submissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id INT UNSIGNED NULL,
    store_name VARCHAR(128) NOT NULL,
    author_staff_id INT UNSIGNED NULL,
    author_name VARCHAR(128) NOT NULL,
    course_line VARCHAR(128) NOT NULL,
    class_level VARCHAR(128) NOT NULL,
    lesson_date DATE NOT NULL,
    title VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    current_version_id BIGINT UNSIGNED NULL,
    approved_version_id BIGINT UNSIGNED NULL,
    status_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lesson_submissions_author_status (author_staff_id, status, updated_at),
    KEY idx_lesson_submissions_store_status (store_id, status, updated_at),
    KEY idx_lesson_submissions_date (lesson_date, course_line)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lesson_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    version_no INT UNSIGNED NOT NULL,
    content_json LONGTEXT NOT NULL,
    source_snapshot_json LONGTEXT NULL,
    changed_fields_json TEXT NULL,
    version_type VARCHAR(32) NOT NULL DEFAULT 'draft',
    is_submitted TINYINT(1) NOT NULL DEFAULT 0,
    is_immutable TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uk_lesson_versions_submission_no (submission_id, version_no),
    KEY idx_lesson_versions_submission_created (submission_id, created_at),
    KEY idx_lesson_versions_submitted (submission_id, is_submitted, is_immutable)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lesson_suggestions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL,
    suggestion_type VARCHAR(64) NOT NULL,
    priority VARCHAR(16) NOT NULL DEFAULT 'medium',
    field_path VARCHAR(255) NULL,
    message TEXT NOT NULL,
    reason TEXT NULL,
    source_type VARCHAR(64) NULL,
    knowledge_item_id BIGINT UNSIGNED NULL,
    decision VARCHAR(16) NOT NULL DEFAULT 'pending',
    decided_by INT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lesson_suggestions_version_decision (version_id, decision),
    KEY idx_lesson_suggestions_knowledge (knowledge_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lesson_review_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL,
    reviewer_staff_id INT UNSIGNED NOT NULL,
    reviewer_role VARCHAR(32) NOT NULL,
    stage VARCHAR(32) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'pending',
    decision VARCHAR(16) NULL,
    comments TEXT NULL,
    decided_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lesson_review_tasks_reviewer_status (reviewer_staff_id, status, created_at),
    KEY idx_lesson_review_tasks_submission_stage (submission_id, stage, created_at),
    KEY idx_lesson_review_tasks_version (version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lesson_exports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    version_id BIGINT UNSIGNED NOT NULL,
    format VARCHAR(16) NOT NULL,
    storage_key VARCHAR(512) NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'queued',
    error_message TEXT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uk_lesson_exports_storage_key (storage_key),
    KEY idx_lesson_exports_submission_version (submission_id, version_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE lesson_audit_logs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    submission_id BIGINT UNSIGNED NOT NULL,
    version_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    actor_staff_id INT UNSIGNED NULL,
    action VARCHAR(64) NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NULL,
    metadata_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_lesson_audit_logs_submission (submission_id, created_at),
    KEY idx_lesson_audit_logs_actor (actor_staff_id, created_at),
    KEY idx_lesson_audit_logs_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE policies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    content LONGTEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE points_rules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    points INT NOT NULL,
    status TINYINT NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_points_rules_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE points_records (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    rule_id BIGINT UNSIGNED NULL,
    points INT NOT NULL,
    balance INT NOT NULL,
    type VARCHAR(16) NOT NULL,
    source VARCHAR(32) NOT NULL,
    source_id BIGINT UNSIGNED NULL,
    description VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE courses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    status TINYINT NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_course_progress (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    lesson_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'in_progress',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_course_lesson (user_id, course_id, lesson_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO stores (id, name, status) VALUES (1, 'migration-baseline-store', 1);
INSERT INTO staffs (id, user_id, employee_no, name, store_id, role, job_title, entry_date, status)
VALUES (1, 1001, 'migration-baseline-staff', 'Migration Staff', 1, 'sales', 'Sales Consultant', '2026-01-01', 1);
INSERT INTO metric_definitions (id, metric_code, metric_name, role_code, metric_group, metric_category, is_required, min_value, sort_order)
VALUES (1, 'migration_calls', 'Migration Calls', 'sales', 'daily_input', 'behavior', 1, 0, 10);
INSERT INTO workload_templates (id, template_code, template_name, role_code, version_no)
VALUES (1, 'migration-sales', 'Migration Sales', 'sales', 1);
INSERT INTO workload_template_items (template_id, metric_id) VALUES (1, 1);
INSERT INTO workload_metric_rules (metric_code, role_code, need_evidence, audit_mode)
VALUES ('migration_calls', 'sales', 1, 'full');
INSERT INTO workload_daily_reports (id, report_date, store_id, staff_id, role_code, template_id, submit_status, source, remarks)
VALUES (1, '2026-09-01', 1, 1, 'sales', 1, 'submitted', 'h5', 'migration-baseline-report');
INSERT INTO workload_daily_report_values (report_id, metric_id, numeric_value) VALUES (1, 1, 12);
INSERT INTO workload_audit_tasks (report_id, staff_id, store_id, role_code, metric_code, submitted_value)
VALUES (1, 1, 1, 'sales', 'migration_calls', 12);
INSERT INTO knowledge_categories (id, name, code, type) VALUES (1, 'Migration', 'migration', 'knowledge_card');
INSERT INTO knowledge_items (id, item_code, content_type, domain_code, publication_status, category_id, title, content)
VALUES (1, 'migration-card', 'guide', 'training', 'published', 1, 'Migration Card', 'Migration content');
INSERT INTO knowledge_item_versions (version_id, knowledge_item_id, version_no, title, content, change_reason)
VALUES (1, 1, 1, 'Migration Card', 'Migration content v1', 'baseline');
UPDATE knowledge_items SET current_version_id = 1 WHERE id = 1;
INSERT INTO lesson_submissions (id, store_id, store_name, author_staff_id, author_name, course_line, class_level, lesson_date, title, current_version_id, approved_version_id, created_by)
VALUES (1, 1, 'migration-baseline-store', 1, 'Migration Staff', 'fitness', 'level-1', '2026-09-01', 'Migration Lesson', 1, 1, 1);
INSERT INTO lesson_versions (id, submission_id, version_no, content_json, version_type, created_by)
VALUES (1, 1, 1, '{"title":"Migration Lesson"}', 'draft', 1);
INSERT INTO lesson_suggestions (submission_id, version_id, suggestion_type, message, source_type, knowledge_item_id)
VALUES (1, 1, 'knowledge', 'Use migration card', 'knowledge_card', 1);
INSERT INTO lesson_review_tasks (submission_id, version_id, reviewer_staff_id, reviewer_role, stage)
VALUES (1, 1, 1, 'manager', 'first_review');
INSERT INTO lesson_exports (submission_id, version_id, format, created_by) VALUES (1, 1, 'docx', 1);
INSERT INTO lesson_audit_logs (submission_id, version_id, actor_staff_id, action) VALUES (1, 1, 1, 'created');
INSERT INTO policies (id, title, content) VALUES (1, 'Migration Policy', 'Migration policy content');
INSERT INTO points_rules (id, code, points) VALUES (1, 'daily_checkin', 5);
INSERT INTO points_records (id, user_id, rule_id, points, balance, type, source, source_id, description, created_at)
VALUES (1, 1001, 1, 5, 5, 'earn', 'daily_checkin', 1, 'migration daily checkin', '2026-09-01 08:00:00');
INSERT INTO courses (id, title, status) VALUES (1, 'Migration Course', 1);
INSERT INTO user_course_progress (id, user_id, course_id, lesson_id, status)
VALUES (1, 1001, 1, 1, 'in_progress');
