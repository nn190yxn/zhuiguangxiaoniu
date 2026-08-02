-- Runtime usage and approval gates for legacy compatibility endpoints.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS platform_legacy_endpoints (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    endpoint VARCHAR(255) NOT NULL,
    http_method VARCHAR(16) NOT NULL,
    consumer VARCHAR(255) NOT NULL,
    domain_code VARCHAR(64) NOT NULL,
    invocation_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    last_invoked_at DATETIME(6) NULL,
    migration_status ENUM('active', 'migrating', 'eligible', 'deprecated') NOT NULL DEFAULT 'migrating',
    replacement_endpoint VARCHAR(255) NULL,
    replacement_status ENUM('unknown', 'available', 'unavailable') NOT NULL DEFAULT 'unknown',
    replacement_checked_at DATETIME(6) NULL,
    owner VARCHAR(128) NULL,
    observation_window_started_at DATETIME(6) NULL,
    observation_window_days SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY `uq_platform_legacy_endpoint_identity` (endpoint, http_method, consumer, domain_code),
    KEY idx_platform_legacy_endpoint_status (migration_status, domain_code),
    KEY idx_platform_legacy_endpoint_last_invoked (last_invoked_at),
    KEY idx_platform_legacy_endpoint_replacement (replacement_status, replacement_checked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO platform_legacy_endpoints (endpoint, http_method, consumer, domain_code, owner)
VALUES
    ('/api/auth/me.php', 'GET', 'internal-auth.js', 'identity', 'identity-team'),
    ('/api/auth/me.php', 'GET', 'mobile/mine.html', 'identity', 'identity-team'),
    ('/api/admin/organization/tree.php', 'GET', 'admin/staffs.html', 'organization', 'identity-team'),
    ('/api/workload/my-report.php', 'GET', 'mobile/workload-v2.html', 'workload', 'workload-team'),
    ('/api/workload/my-report.php', 'GET', 'mini-program/pages/workload/index.js', 'workload', 'workload-team'),
    ('/api/workload/my-report.php', 'GET', 'admin/workload.html', 'workload', 'workload-team'),
    ('/api/admin/recruitment/candidates.php', 'GET', 'admin/recruitment-requirements.html', 'recruitment', 'recruitment-team'),
    ('/api/admin/recruitment/candidates.php', 'GET', 'admin/recruitment-rules.html', 'recruitment', 'recruitment-team'),
    ('/api/admin/recruitment/candidates.php', 'GET', 'admin/recruitment-resumes.html', 'recruitment', 'recruitment-team'),
    ('/api/learning/lesson.php', 'GET', 'mini-program/pages/learning/lesson.js', 'learning', 'learning-team'),
    ('/api/learning/lesson.php', 'GET', 'mobile/pages/learning/lesson.js', 'learning', 'learning-team'),
    ('/api/knowledge/list.php', 'GET', 'knowledge.html', 'knowledge', 'knowledge-team'),
    ('/api/knowledge/list.php', 'GET', 'mobile/knowledge.html', 'knowledge', 'knowledge-team'),
    ('/api/exam/save.php', 'POST', 'training/exam-common.js', 'exam', 'learning-team'),
    ('/api/exam/save.php', 'POST', 'mobile/pages/exam/exam.js', 'exam', 'learning-team'),
    ('/api/policy/notify.php', 'GET', 'mobile/policy.html', 'policy', 'policy-team'),
    ('/api/policy/notify.php', 'POST', 'mobile/policy-detail.html', 'policy', 'policy-team'),
    ('/api/drill/v2/home.php', 'GET', 'mobile/drill.html', 'drill', 'drill-team'),
    ('/api/drill/v2/home.php', 'GET', 'admin/drill.html', 'drill', 'drill-team'),
    ('/api/skill/upload-recording.php', 'POST', 'skill-review.html', 'skill', 'skill-team'),
    ('/api/skill/upload-recording.php', 'POST', 'api/skill/skill-worker.php', 'skill', 'skill-team'),
    ('/api/reminder/jobs.php', 'GET', 'mini-program/pages/reminder/gate.js', 'reminder', 'messaging-team'),
    ('/api/reminder/jobs.php', 'POST', 'api/reminder/reminder-worker.php', 'reminder', 'messaging-team'),
    ('/api/wecom/sync-members.php', 'GET', 'admin/wecom.html', 'wecom', 'messaging-team'),
    ('/api/wecom/sync-members.php', 'POST', 'api/wecom/sync-worker.php', 'wecom', 'messaging-team'),
    ('/api/campaign/list.php', 'GET', 'survey-manage.html', 'content', 'content-team'),
    ('/api/campaign/list.php', 'GET', '周年庆数据看板-V5.html', 'content', 'content-team'),
    ('/api/campaign/list.php', 'GET', 'summer-camp-assessment-app.html', 'content', 'content-team'),
    ('/api/campaign/list.php', 'GET', 'fitness-assessment-app.html', 'content', 'content-team')
ON DUPLICATE KEY UPDATE owner = COALESCE(platform_legacy_endpoints.owner, VALUES(owner));

CREATE TABLE IF NOT EXISTS platform_legacy_endpoint_invocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    invocation_key CHAR(64) NOT NULL,
    legacy_endpoint_id BIGINT UNSIGNED NOT NULL,
    request_id VARCHAR(128) NOT NULL,
    invoked_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY `uq_platform_legacy_invocation_key` (invocation_key),
    KEY idx_platform_legacy_invocation_endpoint (legacy_endpoint_id, invoked_at),
    KEY idx_platform_legacy_invocation_request (request_id),
    CONSTRAINT fk_platform_legacy_invocation_endpoint FOREIGN KEY (legacy_endpoint_id) REFERENCES platform_legacy_endpoints (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_legacy_endpoint_retirement_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    legacy_endpoint_id BIGINT UNSIGNED NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_hash CHAR(64) NOT NULL,
    status ENUM('submitted', 'approved', 'rejected') NOT NULL DEFAULT 'submitted',
    rollback_plan TEXT NOT NULL,
    evidence_json LONGTEXT NOT NULL,
    submitted_by BIGINT UNSIGNED NOT NULL,
    submitted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME(6) NULL,
    approval_note VARCHAR(1000) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY `uq_platform_legacy_retirement_idempotency` (idempotency_key),
    KEY idx_platform_legacy_retirement_endpoint (legacy_endpoint_id, status, submitted_at),
    CONSTRAINT fk_platform_legacy_retirement_endpoint FOREIGN KEY (legacy_endpoint_id) REFERENCES platform_legacy_endpoints (id) ON DELETE RESTRICT,
    CONSTRAINT chk_platform_legacy_retirement_evidence CHECK (JSON_VALID(evidence_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS platform_legacy_endpoint_audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    legacy_endpoint_id BIGINT UNSIGNED NOT NULL,
    action_code VARCHAR(64) NOT NULL,
    actor_staff_id BIGINT UNSIGNED NOT NULL,
    request_id VARCHAR(128) NOT NULL,
    before_json LONGTEXT NULL,
    after_json LONGTEXT NOT NULL,
    occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_platform_legacy_audit_endpoint (legacy_endpoint_id, occurred_at),
    KEY idx_platform_legacy_audit_actor (actor_staff_id, occurred_at),
    KEY idx_platform_legacy_audit_request (request_id),
    CONSTRAINT fk_platform_legacy_audit_endpoint FOREIGN KEY (legacy_endpoint_id) REFERENCES platform_legacy_endpoints (id) ON DELETE RESTRICT,
    CONSTRAINT chk_platform_legacy_audit_before CHECK (before_json IS NULL OR JSON_VALID(before_json)),
    CONSTRAINT chk_platform_legacy_audit_after CHECK (JSON_VALID(after_json))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
