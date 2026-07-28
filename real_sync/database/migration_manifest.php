<?php

return [
    '202607240001' => [
        'tables' => [
            'organization_positions',
            'staff_assignments',
            'staff_import_batches',
            'staff_import_rows',
            'staff_profile_correction_requests',
        ],
        'columns' => [
            'staffs' => ['lifecycle_status', 'offboarded_at', 'offboard_reason', 'offboarded_by', 'session_version', 'primary_position_id'],
            'stores' => ['store_code', 'manager_staff_id'],
        ],
        'indexes' => [
            'staffs' => [['uq_staffs_employee_no', 'uk_employee_no'], 'uq_staffs_user_id'],
            'stores' => ['uq_stores_store_code'],
            'organization_positions' => ['uq_organization_positions_code'],
        ],
    ],
    '202607240002' => [
        'tables' => [
            'workload_submission_obligations',
            'workload_source_policies',
            'workload_metric_versions',
            'workload_role_rule_versions',
            'workload_role_metric_rules',
            'workload_alert_rules',
            'workload_alert_events',
            'workload_export_jobs',
            'workload_report_corrections',
        ],
        'columns' => [
            'workload_daily_reports' => ['metric_version_id', 'rule_version_id'],
            'workload_templates' => ['minimum_positive_metrics', 'effective_from', 'effective_to'],
        ],
        'indexes' => [
            'workload_daily_reports' => ['idx_workload_reports_source_stats', 'idx_workload_reports_staff_source', 'idx_workload_reports_versions'],
            'workload_daily_report_values' => ['idx_workload_values_metric_report_value'],
            'workload_audit_tasks' => ['idx_workload_audit_backlog', 'idx_workload_audit_report_status'],
            'workload_submission_obligations' => ['uq_workload_submission_obligation'],
            'workload_alert_events' => ['uq_workload_alert_event_scope'],
            'workload_export_jobs' => ['uq_workload_export_jobs_key'],
        ],
    ],
    '202607240003' => [
        'tables' => ['admin_operation_logs'],
        'columns' => [
            'admin_operation_logs' => [
                'operator_user_id',
                'operator_staff_id',
                'module',
                'action',
                'target_type',
                'target_id',
                'before_json',
                'after_json',
                'ip_address',
                'user_agent',
                'created_at',
            ],
        ],
        'indexes' => [
            'admin_operation_logs' => ['idx_module_created', 'idx_operator_created', 'idx_target_lookup'],
        ],
    ],
    '202607240004' => [
        'tables' => ['staff_employee_number_sequences'],
        'columns' => [
            'staff_employee_number_sequences' => ['sequence_key', 'current_value', 'updated_at'],
        ],
        'indexes' => [],
    ],
    '202607240005' => [
        'tables' => [],
        'columns' => [
            'workload_audit_tasks' => ['task_version', 'previous_task_id', 'superseded_at'],
        ],
        'indexes' => [
            'workload_audit_tasks' => [
                'idx_workload_audit_version_history',
                'idx_workload_audit_previous_task',
                'idx_workload_audit_current_backlog',
            ],
        ],
    ],
    '202607240006' => [
        'tables' => [],
        'columns' => [
            'workload_audit_tasks' => ['evidence_count_at_review'],
        ],
        'indexes' => [],
    ],
    '202607240007' => [
        'tables' => [
            'workload_metric_relation_versions',
            'workload_metric_relations',
        ],
        'columns' => [],
        'indexes' => [
            'workload_metric_relation_versions' => [
                'uq_workload_metric_relation_versions_code',
                'idx_workload_metric_relation_versions_effective',
            ],
            'workload_metric_relations' => [
                'uq_workload_metric_relation',
                'idx_workload_metric_relations_group',
                'idx_workload_metric_relations_metrics',
            ],
        ],
    ],
    '202607240008' => [
        'tables' => ['workload_standard_idempotency_keys'],
        'columns' => [
            'workload_role_rule_versions' => [
                'role_code',
                'requires_daily_report',
                'source_rule_version_id',
                'published_by_staff_id',
                'published_at',
            ],
            'workload_role_metric_rules' => [
                'metric_name_snapshot',
                'unit_snapshot',
                'value_type_snapshot',
            ],
            'metric_definitions' => ['role_code'],
            'workload_templates' => ['role_code'],
            'workload_daily_reports' => ['role_code'],
            'workload_submission_obligations' => ['role_code'],
        ],
        'indexes' => [
            'workload_standard_idempotency_keys' => [
                'uq_workload_standard_idempotency',
                'idx_workload_standard_idempotency_operator',
            ],
        ],
    ],
    '202607240009' => [
        'tables' => [
            'workload_standard_import_batches',
            'workload_standard_import_rows',
        ],
        'columns' => [],
        'indexes' => [
            'workload_standard_import_batches' => [
                'uq_workload_standard_import_batch_key',
                'uq_workload_standard_import_request',
                'idx_workload_standard_import_status',
                'idx_workload_standard_import_operator',
            ],
            'workload_standard_import_rows' => [
                'uq_workload_standard_import_row',
                'idx_workload_standard_import_row_role',
                'idx_workload_standard_import_row_target',
            ],
        ],
    ],
    '202607280001' => [
        'tables' => [],
        'columns' => [],
        'indexes' => [],
    ],
    '202607280002' => [
        'tables' => [],
        'columns' => [],
        'indexes' => [],
    ],
];
