<?php
declare(strict_types=1);

final class PlatformBusinessDomainRegistry
{
    private const DOMAINS = [
        'identity' => [
            'function_ids' => ['IAM-001', 'IAM-004'],
            'endpoint' => 'api/auth/me.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['internal-auth.js', 'mobile/mine.html'],
            'capabilities' => ['identity_context', 'legacy_response_compatible'],
        ],
        'organization' => [
            'function_ids' => ['IAM-009'],
            'endpoint' => 'api/admin/organization/tree.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['admin/staffs.html'],
            'capabilities' => ['organization_tree', 'named_permission'],
        ],
        'workload' => [
            'function_ids' => ['BIZ-001', 'BIZ-002', 'BIZ-003', 'BIZ-004', 'BIZ-005'],
            'endpoint' => 'api/workload/my-report.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['mobile/workload-v2.html', 'mini-program/pages/workload/index.js', 'admin/workload.html'],
            'capabilities' => ['daily_submission', 'state_version', 'sync_level_a', 'temporary_export', 'platform_job_queue'],
        ],
        'recruitment' => [
            'function_ids' => ['BIZ-010', 'BIZ-011', 'BIZ-012', 'BIZ-013'],
            'endpoint' => 'api/admin/recruitment/candidates.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['admin/recruitment-requirements.html', 'admin/recruitment-rules.html', 'admin/recruitment-resumes.html'],
            'capabilities' => ['requirement_rules', 'resume_screening', 'candidate_review', 'state_version', 'private_file', 'existing_ai_runtime', 'platform_job_queue', 'hire_to_employee'],
        ],
        'learning' => [
            'function_ids' => ['BIZ-014'],
            'endpoint' => 'api/learning/lesson.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['mini-program/pages/learning/lesson.js', 'mobile/pages/learning/lesson.js'],
            'capabilities' => ['lesson_progress', 'idempotent_course_reward'],
        ],
        'knowledge' => [
            'function_ids' => ['BIZ-015'],
            'endpoint' => 'api/knowledge/list.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['knowledge.html', 'mobile/knowledge.html'],
            'capabilities' => ['server_derived_visibility'],
        ],
        'exam' => [
            'function_ids' => ['BIZ-016'],
            'endpoint' => 'api/exam/save.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['training/exam-common.js', 'mobile/pages/exam/exam.js'],
            'capabilities' => ['draft_autosave', 'state_version'],
        ],
        'policy' => [
            'function_ids' => ['BIZ-018', 'MSG-004'],
            'endpoint' => 'api/policy/notify.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['mobile/policy.html', 'mobile/policy-detail.html'],
            'capabilities' => ['notification_inbox', 'policy_confirmation', 'named_permission'],
        ],
        'drill' => [
            'function_ids' => ['BIZ-006'],
            'endpoint' => 'api/drill/v2/home.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['mobile/drill.html', 'admin/drill.html'],
            'capabilities' => ['employee_home', 'private_audio', 'existing_ai_runtime'],
        ],
        'skill' => [
            'function_ids' => ['BIZ-009'],
            'endpoint' => 'api/skill/upload-recording.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['skill-review.html', 'api/skill/skill-worker.php'],
            'capabilities' => ['recording_upload', 'private_audio', 'platform_job_queue'],
        ],
        'reminder' => [
            'function_ids' => ['MSG-003'],
            'endpoint' => 'api/reminder/jobs.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['mini-program/pages/reminder/gate.js', 'api/reminder/reminder-worker.php'],
            'capabilities' => ['job_query', 'manual_run', 'platform_job_queue', 'named_permission'],
        ],
        'wecom' => [
            'function_ids' => ['MSG-001'],
            'endpoint' => 'api/wecom/sync-members.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['admin/wecom.html', 'api/wecom/sync-worker.php'],
            'capabilities' => ['sync_query', 'manual_sync', 'platform_job_queue', 'named_permission'],
        ],
        'content' => [
            'function_ids' => ['BIZ-019', 'BIZ-020', 'BIZ-021', 'BIZ-022'],
            'endpoint' => 'api/campaign/list.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['survey-manage.html', '周年庆数据看板-V5.html', 'summer-camp-assessment-app.html', 'fitness-assessment-app.html'],
            'capabilities' => ['survey', 'campaign', 'summer_camp_assessment', 'fitness_assessment', 'existing_ai_runtime'],
        ],
        'lesson_review' => [
            'function_ids' => ['BIZ-023', 'BIZ-024', 'BIZ-025', 'BIZ-026'],
            'endpoint' => 'api/lesson-submissions/create.php',
            'endpoint_version' => '1.0.0',
            'legacy_consumers' => ['smart-lessons.html', 'smart-lessons-api.php', 'lesson-library.html', 'js/lesson-library.js'],
            'capabilities' => ['office_upload', 'structured_editing', 'ace_optimization', 'store_review', 'supervisor_review', 'versioned_export', 'audit_trace', 'named_permission', 'approved_version_publication', 'formal_library_read', 'canonical_lesson_route'],
        ],
    ];

    public static function all(): array
    {
        return self::DOMAINS;
    }

    public static function get(string $domain): array
    {
        if (!isset(self::DOMAINS[$domain])) {
            throw new InvalidArgumentException('Unknown business domain: ' . $domain);
        }
        return self::DOMAINS[$domain];
    }
}
