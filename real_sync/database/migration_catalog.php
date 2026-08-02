<?php
declare(strict_types=1);

$legacyManifest = require __DIR__ . '/migration_manifest.php';
$checksums = [
    '202607240001' => '6b8574dc5f0efdcb9b7d445884f0af4dce872f9562fc0b818c823d8b221b1887',
    '202607240002' => '086dab8fc7430debeedeb43b4a2eac167439152e19f6f8f32bd5f6633c41a8ba',
    '202607240003' => 'fb79cadf66149bcced3d9192e2eaac400e4abaf7b81bad6c606584569767b68a',
    '202607240004' => 'd822ca03dee2757ef0fa9a567b5f93a524a3601c961befdaa2554709e8697cbe',
    '202607240005' => '04d50bd9a8994044b2686a22b7a8ed0d39db041e1e676c5597c54d2ca6c55828',
    '202607240006' => '67e847679f8bca15d8d24cd52897203be320cf8417ff23bc5d607bb83ddcde7d',
    '202607240007' => '1ac7a29765018afd680b629a74c5cac7f1cfad274420fc93d155b32822c0982e',
    '202607240008' => 'c92e688e10becb02bc454ebfa301dc19ed4fc89ae957040ca9d2329ae439e639',
    '202607240009' => '59a8c30d3b6cdbf0da5c0c3215962e48137d78f78714de5b1e72d149697a56b6',
    '202607270001' => 'ac96336aa62db1573524c24640412a2cc8b4d17cffd26f322d1c751bed408336',
    '202607270002' => 'd5d72340b1642d9e1d4d1d9e49f48e3063e40820d622f946acdf60ef5cde08f8',
    '202607270003' => '9151e132bef183715bd86886b44e611d497c54a3b0296f18aaa798d9a4566bd0',
    '202607270004' => 'cc48d98cf2746d22db3ac24363627f1ed6cee4b5741fa470ccfe4e4d788c1955',
    '202607270005' => '483bc7b30aad5e41c75405170a9f649470b2a54900b8535812a27a7e21b41273',
    '202607270006' => '48c616cd4a8aa6f5db51fe3d1d883ca18ba9f3c92bc40f8b711ba75182cbf66e',
    '202607270007' => 'ee5abeef2f7a505b57342e3b7f84b0a04b30867165ae311baf3463ebe9dee33b',
    '202607270008' => 'b5096060e91e7463bfb339c637ec734ac402cb1516a31ac395b3dbaf99b4ffa5',
    '202607280001' => '18c285349a57fa48b12bb5d1530201866813591111090af130c2cd55176e5115',
    '202607280002' => '4d81b1541e70d39357a7a1f985c6c0a77317945fac35e8a55609eea2a37e3589',
    '202607280003' => '5e1fa04fb447dbf3a3487ae2b8be717a7eb1bcd1a6b00632a06ba61a762ac2e3',
    '202607280004' => 'c17422a855393866bc463b7e2a81291b8a051a6f2318ddae6b89585d3ca0b3c5',
    '202607280005' => '85a8611cb853091d49b161c091d6bdcece541a59ca60b65a6e5c9e4052afe9f2',
    '202607280006' => '6d1cece8d7700118a5dd2f08984a8b9bda32ccab4ed0c26929ee35e35ffdeec8',
    '202607280007' => 'e84b4acfe435112ba9768f605c216d36dc1fde52eb6b56b795bc9eb88a8d35ec',
    '202607280008' => '3cdbefab8a40bf4a431f9e10be5a0e5573d4fab207dd96d89626ccb03d3695c1',
    '202607310001' => '590c191544bb3d482fe8501a7f149afb3c3ae69c3769888a69dc8cb232d1ff4c',
    '202607310002' => 'b8249217aa5207a03602a75db935fcd5cd5955daa5734d6d899c4c9bf5d4c07d',
    '202607310003' => '84da925af501169e3ecba9c2ae5105fd3f99509d9b8f1a60ca53b514dd88af41',
    '202607310004' => '8636983ec8bec4f73088c68eda1db52df4bd69195ee76d1210a8b9f5c91d51df',
    '202607310005' => '598b1fac441717c9e4847b2efed208e49936cba3a75445e0bdb8e3e46de5cc16',
    '202607310006' => '8346a8d9958debf1dc662b2ab72bd39038ef2bc3baf45d3bf5d9deb1dbd82b74',
    '202607310007' => 'a83f52e9a8cbcde00df73a2df0e5ae23cb8f647709a6989e988846b9afe480fd',
    '202607310008' => 'b2e6dc5efbadc311da2cbf0811fe6fe97149004034a9e8b2c5bc2c38bcf613fb',
    '202607310009' => 'b7b45f0915a43889db20f0b740a9a600db561fffdcbf45c3140190d70df0eac9',
    '202607310010' => '2635904f46257b988f09590da7bb68c33f25708f7a82e18be1bccba1ce020693',
    '202607310011' => '6b9df99f4286a9eae733941ce0ed82fc5613c4717b7dafbbcfda19675bcb1edf',
    '202607310012' => 'b9e400540136ddb11bd01ef17e17f3ffc0655ddf4653d6289ef89d9716a1131e',
    '202607310013' => 'e9a36be7b10c67717b38e1f43d5087d0ddbf80bafd6e34f1a70675d18a8fc967',
    '202607310014' => 'e8a007fc5bd1a198570c8a736ca8e552144f9e66ca41ccb26aa3d6ee6592f57e',
    '202608020001' => '84840abc445eb436e6221d84130128b87a431cfd489bceb213a3a8452afaa8c4',
    '202608020002' => '2844cc57585f33233e30b180455988811c5f73ef9ac6b09d1e54a28f1aa80657',
    '202608020003' => 'ccf20f436b0e96c8386fb7dc772e5650b8cb84325b5652407e122b6b40eccd6f',
];

$legacyDataChecks = [
    '202607240001' => [[
        'id' => 'staff_identity_unique',
        'type' => 'expected_zero',
        'sql' => 'SELECT COUNT(*) FROM (SELECT employee_no FROM staffs WHERE employee_no IS NOT NULL GROUP BY employee_no HAVING COUNT(*) > 1 UNION ALL SELECT CAST(user_id AS CHAR) FROM staffs WHERE user_id IS NOT NULL GROUP BY user_id HAVING COUNT(*) > 1) duplicate_keys',
    ]],
    '202607240002' => [[
        'id' => 'workload_report_versions_backfilled',
        'type' => 'expected_zero',
        'sql' => 'SELECT COUNT(*) FROM workload_daily_reports WHERE metric_version_id IS NULL OR rule_version_id IS NULL',
    ]],
    '202607240005' => [[
        'id' => 'audit_task_version_initialized',
        'type' => 'expected_zero',
        'sql' => 'SELECT COUNT(*) FROM workload_audit_tasks WHERE task_version IS NULL OR task_version < 1',
    ]],
    '202607240007' => [[
        'id' => 'metric_relation_seed_complete',
        'type' => 'expected_zero',
        'sql' => "SELECT COUNT(*) FROM (SELECT 'sales_invitation_rate' relation_code UNION ALL SELECT 'sales_arrival_rate' UNION ALL SELECT 'sales_deal_rate' UNION ALL SELECT 'coach_lesson_completion_rate' UNION ALL SELECT 'coach_communication_completion_rate') expected LEFT JOIN workload_metric_relation_versions v ON v.version_code = 'workload-relations-v1' LEFT JOIN workload_metric_relations r ON r.relation_version_id = v.id AND r.relation_code = expected.relation_code WHERE r.id IS NULL",
    ]],
    '202607240008' => [[
        'id' => 'metric_snapshots_backfilled',
        'type' => 'expected_zero',
        'sql' => 'SELECT COUNT(*) FROM workload_role_metric_rules WHERE metric_name_snapshot IS NULL OR unit_snapshot IS NULL OR value_type_snapshot IS NULL',
    ]],
    '202607270002' => [[
        'id' => 'drill_domains_seeded',
        'type' => 'expected_zero',
        'sql' => "SELECT COUNT(*) FROM (SELECT 'new_signing' domain_code UNION ALL SELECT 'renewal') expected LEFT JOIN drill_training_domains d ON d.domain_code = expected.domain_code WHERE d.id IS NULL",
    ]],
    '202607270004' => [[
        'id' => 'attempt_rubrics_backfilled',
        'type' => 'expected_zero',
        'sql' => 'SELECT COUNT(*) FROM drill_attempts a LEFT JOIN drill_rubric_versions rv ON rv.id = a.rubric_version_id LEFT JOIN drill_rubrics r ON r.id = a.rubric_id AND r.domain_id = a.domain_id WHERE a.rubric_id IS NULL OR rv.id IS NULL OR a.rubric_id <> rv.rubric_id OR r.id IS NULL',
    ]],
    '202607270006' => [[
        'id' => 'content_gap_fingerprints_backfilled',
        'type' => 'expected_zero',
        'sql' => "SELECT COUNT(*) FROM drill_content_gaps WHERE gap_fingerprint IS NULL OR (status = 'open' AND (open_gap_fingerprint IS NULL OR open_gap_fingerprint <> gap_fingerprint)) OR (status <> 'open' AND open_gap_fingerprint IS NOT NULL)",
    ]],
    '202607270008' => [[
        'id' => 'attempt_snapshots_backfilled',
        'type' => 'expected_zero',
        'sql' => 'SELECT COUNT(*) FROM drill_attempts WHERE process_snapshot_json IS NULL OR process_snapshot_hash IS NULL OR scenario_snapshot_hash IS NULL OR rubric_snapshot_hash IS NULL OR calibration_snapshot_json IS NULL OR calibration_snapshot_hash IS NULL OR session_goal_snapshot_hash IS NULL',
    ]],
    '202607280001' => [[
        'id' => 'offline_metrics_seeded',
        'type' => 'expected_zero',
        'sql' => "SELECT COUNT(*) FROM (SELECT 'sales_store_poi_checkin' metric_code UNION ALL SELECT 'coach_store_poi_checkin' UNION ALL SELECT 'manager_store_poi_checkin' UNION ALL SELECT 'manager_store_favorite' UNION ALL SELECT 'manager_nine_image_review' UNION ALL SELECT 'manager_three_image_review' UNION ALL SELECT 'manager_online_order_count' UNION ALL SELECT 'manager_online_order_amount' UNION ALL SELECT 'manager_video_post') expected LEFT JOIN metric_definitions m ON m.metric_code = expected.metric_code WHERE m.id IS NULL OR m.is_active <> 1",
    ]],
    '202607280002' => [[
        'id' => 'coach_body_test_cap_applied',
        'type' => 'expected_zero',
        'sql' => "SELECT COUNT(*) FROM metric_definitions WHERE role_code = 'coach' AND metric_code = 'coach_body_test' AND (max_value IS NULL OR max_value <> 2)",
    ]],
    '202607280003' => [[
        'id' => 'coach_hours_disabled',
        'type' => 'expected_zero',
        'sql' => "SELECT COUNT(*) FROM metric_definitions WHERE role_code = 'coach' AND metric_code IN ('coach_plan_hours','coach_actual_hours') AND is_active <> 0",
    ]],
    '202607280004' => [[
        'id' => 'coach_hours_rules_neutralized',
        'type' => 'expected_zero',
        'sql' => "SELECT COUNT(*) FROM workload_role_metric_rules r JOIN workload_role_rule_versions v ON v.id = r.rule_version_id WHERE v.role_code = 'coach' AND r.metric_code IN ('coach_plan_hours','coach_actual_hours') AND (r.is_required <> 0 OR r.allow_zero <> 1 OR r.min_value <> 0 OR r.max_value IS NOT NULL OR r.need_evidence <> 0 OR r.min_evidence_count <> 0 OR r.audit_mode <> 'none' OR r.target_value IS NOT NULL)",
    ]],
    '202607310001' => [[
        'id' => 'recruitment_retention_categories_valid',
        'type' => 'expected_zero',
        'sql' => "SELECT COUNT(*) FROM recruitment_retention_policies WHERE CAST(data_category AS CHAR) NOT IN ('raw_resume','ocr_text','structured_profile','archive_record','ai_result','contact_log','export_file','audit_log')",
    ], [
        'id' => 'recruitment_disposal_categories_valid',
        'type' => 'expected_zero',
        'sql' => "SELECT COUNT(*) FROM recruitment_disposal_jobs WHERE CAST(data_category AS CHAR) NOT IN ('raw_resume','ocr_text','structured_profile','archive_record','ai_result','contact_log','export_file','audit_log')",
    ]],
];

$platformExpectations = [
    '202607310002' => [
        'tables' => ['platform_sessions', 'platform_refresh_tokens', 'platform_security_events'],
        'columns' => [
            'platform_sessions' => ['id', 'family_id', 'user_id', 'staff_id', 'username_snapshot', 'role_snapshot', 'client_type', 'device_id', 'session_version', 'status', 'expires_at', 'revoked_at', 'revoke_reason', 'created_at', 'last_refreshed_at'],
            'platform_refresh_tokens' => ['id', 'session_id', 'token_hash', 'status', 'expires_at', 'rotated_at', 'revoked_at', 'replaced_by_token_id', 'created_at'],
            'platform_security_events' => ['id', 'event_type', 'user_id', 'staff_id', 'session_id', 'family_id', 'refresh_token_id', 'client_type', 'event_data_json', 'created_at'],
        ],
        'indexes' => [
            'platform_sessions' => ['idx_platform_sessions_family', 'idx_platform_sessions_user', 'idx_platform_sessions_staff'],
            'platform_refresh_tokens' => ['uq_platform_refresh_tokens_hash', 'idx_platform_refresh_tokens_session', 'idx_platform_refresh_tokens_expiry'],
            'platform_security_events' => ['idx_platform_security_events_type', 'idx_platform_security_events_user', 'idx_platform_security_events_family'],
        ],
        'data_checks' => [[
            'id' => 'platform_refresh_tokens_have_session',
            'type' => 'expected_zero',
            'sql' => 'SELECT COUNT(*) FROM platform_refresh_tokens rt LEFT JOIN platform_sessions s ON s.id = rt.session_id WHERE s.id IS NULL',
        ]],
    ],
    '202607310003' => [
        'tables' => [],
        'columns' => ['platform_sessions' => ['identity_hash']],
        'indexes' => [],
        'data_checks' => [[
            'id' => 'platform_session_identity_hash_format',
            'type' => 'expected_zero',
            'sql' => "SELECT COUNT(*) FROM platform_sessions WHERE identity_hash IS NOT NULL AND identity_hash NOT REGEXP '^[a-f0-9]{64}$'",
        ]],
    ],
    '202607310004' => [
        'tables' => ['platform_sync_drafts', 'platform_sync_changes'],
        'columns' => [
            'platform_sync_drafts' => ['id', 'owner_staff_id', 'domain', 'object_type', 'object_id', 'draft_version', 'base_state_version', 'payload_json', 'source_client', 'source_device_id', 'status', 'expires_at', 'created_at', 'updated_at'],
            'platform_sync_changes' => ['id', 'scope_hash', 'domain', 'object_type', 'object_id', 'state_version', 'sync_level', 'status', 'state_json', 'etag', 'reason', 'occurred_at', 'created_at'],
        ],
        'indexes' => [
            'platform_sync_drafts' => ['uq_platform_sync_draft_owner_object', 'idx_platform_sync_drafts_expiry', 'idx_platform_sync_drafts_owner_updated'],
            'platform_sync_changes' => ['uq_platform_sync_change_version', 'idx_platform_sync_changes_cursor', 'idx_platform_sync_changes_object'],
        ],
        'data_checks' => [[
            'id' => 'platform_sync_draft_payload_is_json',
            'type' => 'expected_zero',
            'sql' => 'SELECT COUNT(*) FROM platform_sync_drafts WHERE JSON_VALID(payload_json) = 0',
        ]],
    ],
    '202607310005' => [
        'tables' => [],
        'columns' => [
            'staffs' => [
                'openid',
                'openid_bound_at',
                'wecom_userid',
                'wecom_name',
                'wecom_mobile',
                'wecom_department_id',
                'wecom_department_path',
                'wecom_status',
                'wecom_bound_at',
            ],
        ],
        'indexes' => [],
        'data_checks' => [],
    ],
    '202607310007' => [
        'tables' => [],
        'columns' => [],
        'indexes' => [],
        'data_checks' => [[
            'id' => 'reminder_default_rules_seeded',
            'type' => 'expected_zero',
            'sql' => "SELECT COUNT(*) FROM (SELECT 'learning_required_daily' rule_code UNION ALL SELECT 'workload_daily_first' UNION ALL SELECT 'workload_daily_second' UNION ALL SELECT 'workload_store_summary' UNION ALL SELECT 'workload_hq_summary') expected LEFT JOIN mini_reminder_rules r ON r.rule_code = expected.rule_code WHERE r.id IS NULL",
        ]],
    ],
    '202607310010' => [
        'tables' => ['platform_jobs', 'platform_job_runs'],
        'columns' => [
            'platform_jobs' => ['id', 'job_type', 'object_type', 'object_id', 'idempotency_key', 'payload_json', 'status', 'priority', 'available_at', 'max_attempts', 'attempt_count', 'worker_id', 'fencing_token', 'locked_at', 'heartbeat_at', 'lease_expires_at', 'result_json', 'error_code', 'error_summary', 'recovery_required', 'completed_at', 'created_at', 'updated_at'],
            'platform_job_runs' => ['id', 'job_id', 'attempt_number', 'worker_id', 'fencing_token', 'status', 'result_json', 'error_code', 'error_summary', 'started_at', 'heartbeat_at', 'finished_at', 'created_at', 'updated_at'],
        ],
        'indexes' => [
            'platform_jobs' => ['uq_platform_jobs_idempotency', 'idx_platform_jobs_claim', 'idx_platform_jobs_lease', 'idx_platform_jobs_object', 'idx_platform_jobs_backlog'],
            'platform_job_runs' => ['uq_platform_job_runs_fence', 'idx_platform_job_runs_worker', 'idx_platform_job_runs_status'],
        ],
        'data_checks' => [[
            'id' => 'platform_job_payloads_are_json',
            'type' => 'expected_zero',
            'sql' => 'SELECT COUNT(*) FROM platform_jobs WHERE JSON_VALID(payload_json) = 0 OR (result_json IS NOT NULL AND JSON_VALID(result_json) = 0)',
        ], [
            'id' => 'platform_job_runs_have_jobs',
            'type' => 'expected_zero',
            'sql' => 'SELECT COUNT(*) FROM platform_job_runs r LEFT JOIN platform_jobs j ON j.id = r.job_id WHERE j.id IS NULL',
        ]],
    ],
    '202607310011' => [
        'tables' => ['platform_outbox_events', 'platform_side_effect_receipts'],
        'columns' => [
            'platform_outbox_events' => ['id', 'event_key', 'source_change_key', 'business_transaction_key', 'idempotency_key', 'event_type', 'payload_json', 'payload_hash', 'status', 'requires_side_effect', 'expected_side_effect_hash', 'failure_class', 'error_code', 'error_summary', 'recovery_required', 'job_id', 'worker_id', 'fencing_token', 'replay_count', 'replay_operator', 'replay_reason', 'last_replayed_at', 'occurred_at', 'dispatched_at', 'created_at', 'updated_at'],
            'platform_side_effect_receipts' => ['id', 'outbox_event_key', 'idempotency_key', 'effect_type', 'payload_hash', 'status', 'job_id', 'worker_id', 'fencing_token', 'result_json', 'failure_class', 'error_code', 'error_summary', 'recovery_required', 'compensation_status', 'compensation_operator', 'compensation_reason', 'compensation_result_json', 'compensation_error_code', 'compensation_error_summary', 'compensation_requested_at', 'compensation_completed_at', 'occurred_at', 'confirmed_at', 'created_at', 'updated_at'],
        ],
        'indexes' => [
            'platform_outbox_events' => ['uq_platform_outbox_event_key', 'uq_platform_outbox_idempotency', 'idx_platform_outbox_dispatch', 'idx_platform_outbox_transaction', 'idx_platform_outbox_source_change', 'idx_platform_outbox_recovery', 'idx_platform_outbox_lease'],
            'platform_side_effect_receipts' => ['uq_platform_side_effect_idempotency', 'idx_platform_side_effect_event', 'idx_platform_side_effect_status', 'idx_platform_side_effect_lease', 'idx_platform_side_effect_compensation'],
        ],
        'data_checks' => [[
            'id' => 'platform_outbox_payloads_are_valid',
            'type' => 'expected_zero',
            'sql' => 'SELECT COUNT(*) FROM platform_outbox_events WHERE JSON_VALID(payload_json) = 0',
        ], [
            'id' => 'platform_side_effect_receipts_have_events',
            'type' => 'expected_zero',
            'sql' => 'SELECT COUNT(*) FROM platform_side_effect_receipts r LEFT JOIN platform_outbox_events e ON e.event_key = r.outbox_event_key WHERE e.id IS NULL',
        ], [
            'id' => 'platform_side_effect_json_is_valid',
            'type' => 'expected_zero',
            'sql' => 'SELECT COUNT(*) FROM platform_side_effect_receipts WHERE (result_json IS NOT NULL AND JSON_VALID(result_json) = 0) OR (compensation_result_json IS NOT NULL AND JSON_VALID(compensation_result_json) = 0)',
        ], [
            'id' => 'platform_confirmed_receipts_are_complete',
            'type' => 'expected_zero',
            'sql' => "SELECT COUNT(*) FROM platform_side_effect_receipts WHERE (status = 'confirmed' AND confirmed_at IS NULL) OR (compensation_status IS NOT NULL AND status <> 'confirmed')",
        ]],
    ],
    '202607310012' => [
        'tables' => [],
        'columns' => [
            'platform_jobs' => ['payload_hash'],
        ],
        'indexes' => [],
        'data_checks' => [[
            'id' => 'platform_job_payload_hashes_are_sha256',
            'type' => 'expected_zero',
            'sql' => "SELECT COUNT(*) FROM platform_jobs WHERE payload_hash IS NULL OR payload_hash NOT REGEXP '^[a-f0-9]{64}$'",
        ]],
    ],
    '202607310013' => [
        'tables' => ['platform_file_assets', 'platform_file_access_grants', 'platform_file_access_events'],
        'columns' => [
            'platform_file_assets' => ['id', 'asset_key', 'asset_class', 'purpose_code', 'owner_type', 'owner_id', 'business_object_type', 'business_object_id', 'original_name', 'mime_type', 'byte_size', 'sha256', 'storage_driver', 'storage_key', 'access_mode', 'retention_policy_code', 'retention_until', 'download_expires_at', 'status', 'created_by_type', 'created_by_id', 'created_at', 'updated_at'],
            'platform_file_access_grants' => ['id', 'asset_id', 'principal_type', 'principal_id', 'permission_code', 'scope_type', 'scope_id', 'reason', 'expires_at', 'revoked_at', 'granted_by_type', 'granted_by_id', 'created_at'],
            'platform_file_access_events' => ['id', 'asset_id', 'actor_type', 'actor_id', 'action_code', 'permission_code', 'decision', 'reason_code', 'scope_type', 'scope_id', 'request_id', 'access_reason', 'occurred_at'],
        ],
        'indexes' => [
            'platform_file_assets' => ['uq_platform_file_asset_key', 'uq_platform_file_storage_location', 'idx_platform_file_owner', 'idx_platform_file_business_object', 'idx_platform_file_retention', 'idx_platform_file_download_expiry', 'idx_platform_file_digest'],
            'platform_file_access_grants' => ['uq_platform_file_grant', 'idx_platform_file_grant_principal', 'idx_platform_file_grant_scope'],
            'platform_file_access_events' => ['idx_platform_file_event_asset', 'idx_platform_file_event_actor', 'idx_platform_file_event_request', 'idx_platform_file_event_decision'],
        ],
        'data_checks' => [[
            'id' => 'platform_file_asset_contract_valid',
            'type' => 'expected_zero',
            'sql' => "SELECT COUNT(*) FROM platform_file_assets WHERE asset_class NOT IN ('public_static','controlled','temporary_export','sensitive_source') OR sha256 NOT REGEXP '^[a-f0-9]{64}$' OR byte_size < 1 OR (asset_class IN ('controlled','temporary_export','sensitive_source') AND retention_until IS NULL) OR (asset_class = 'temporary_export' AND download_expires_at IS NULL) OR (download_expires_at IS NOT NULL AND retention_until IS NOT NULL AND download_expires_at > retention_until)",
        ], [
            'id' => 'platform_file_grants_have_assets',
            'type' => 'expected_zero',
            'sql' => 'SELECT COUNT(*) FROM platform_file_access_grants g LEFT JOIN platform_file_assets a ON a.id = g.asset_id WHERE a.id IS NULL',
        ], [
            'id' => 'platform_file_events_have_assets',
            'type' => 'expected_zero',
            'sql' => 'SELECT COUNT(*) FROM platform_file_access_events e LEFT JOIN platform_file_assets a ON a.id = e.asset_id WHERE a.id IS NULL',
        ]],
    ],
    '202607310014' => [
        'tables' => ['platform_ai_invocations'],
        'columns' => [
            'platform_ai_invocations' => ['id', 'invocation_key', 'request_id', 'idempotency_key', 'capability', 'purpose_code', 'data_classification', 'requested_provider', 'actual_provider', 'model', 'contract_version', 'processing_version', 'status', 'error_code', 'error_summary', 'retryable', 'recovery_required', 'attempt_count', 'fallback_used', 'elapsed_ms', 'input_sha256', 'input_bytes', 'output_sha256', 'output_bytes', 'approval_id', 'retention_policy_code', 'retention_until', 'created_at', 'completed_at'],
        ],
        'indexes' => [
            'platform_ai_invocations' => ['uq_platform_ai_invocation_key', 'idx_platform_ai_request', 'idx_platform_ai_idempotency', 'idx_platform_ai_capability_status', 'idx_platform_ai_provider_status', 'idx_platform_ai_retention'],
        ],
        'data_checks' => [[
            'id' => 'platform_ai_invocation_contract_valid',
            'type' => 'expected_zero',
            'sql' => "SELECT COUNT(*) FROM platform_ai_invocations WHERE capability NOT IN ('text.generate','assessment.score','vision.extract','ocr.extract','speech.transcribe','image.generate') OR status NOT IN ('completed','failed','rejected','unsupported') OR input_sha256 NOT REGEXP '^[a-f0-9]{64}$' OR (output_sha256 IS NOT NULL AND output_sha256 NOT REGEXP '^[a-f0-9]{64}$') OR attempt_count < 0 OR elapsed_ms < 0 OR retention_until <= created_at OR (status = 'completed' AND (actual_provider IS NULL OR model IS NULL OR processing_version IS NULL OR output_sha256 IS NULL)) OR (status IN ('rejected','unsupported') AND attempt_count <> 0)",
        ]],
    ],
    '202608020001' => [
        'tables' => ['workload_alert_worker_runs'],
        'columns' => [
            'workload_alert_worker_runs' => ['id', 'run_key', 'business_date', 'status', 'attempt_count', 'summary_json', 'error_message', 'started_at', 'completed_at', 'updated_at'],
        ],
        'indexes' => [
            'workload_alert_worker_runs' => ['uq_workload_alert_worker_run_key', 'idx_workload_alert_worker_runs_status'],
        ],
        'data_checks' => [],
    ],
    '202608020002' => [
        'tables' => ['recruitment_hire_approvals', 'recruitment_hire_conversions'],
        'columns' => [
            'recruitment_resume_files' => ['platform_asset_id'],
            'recruitment_applications' => ['hiring_status', 'state_version'],
            'recruitment_hire_approvals' => ['id', 'application_id', 'decision', 'approval_reason', 'idempotency_key', 'state_version', 'approved_by', 'approved_at', 'revoked_by', 'revoked_at', 'created_at', 'updated_at'],
            'recruitment_hire_conversions' => ['id', 'application_id', 'approval_id', 'employee_staff_id', 'idempotency_key', 'request_hash', 'response_json', 'status', 'state_version', 'converted_by', 'converted_at', 'created_at', 'updated_at'],
        ],
        'indexes' => [
            'recruitment_resume_files' => ['uq_recruitment_resume_files_asset'],
            'recruitment_applications' => ['idx_recruitment_applications_hiring'],
            'recruitment_hire_approvals' => ['uq_recruitment_hire_approval_application', 'uq_recruitment_hire_approval_idempotency', 'idx_recruitment_hire_approval_decision'],
            'recruitment_hire_conversions' => ['uq_recruitment_hire_conversion_application', 'uq_recruitment_hire_conversion_idempotency', 'idx_recruitment_hire_conversion_staff', 'idx_recruitment_hire_conversion_status'],
        ],
        'data_checks' => [[
            'id' => 'recruitment_hire_conversion_contract_valid',
            'type' => 'expected_zero',
            'sql' => "SELECT COUNT(*) FROM recruitment_hire_conversions WHERE request_hash NOT REGEXP '^[a-f0-9]{64}$' OR (status = 'completed' AND (employee_staff_id IS NULL OR response_json IS NULL OR JSON_VALID(response_json) = 0 OR converted_at IS NULL))",
        ]],
    ],
    '202608020003' => [
        'tables' => [
            'platform_legacy_endpoints',
            'platform_legacy_endpoint_invocations',
            'platform_legacy_endpoint_retirement_approvals',
            'platform_legacy_endpoint_audit_events',
        ],
        'columns' => [
            'platform_legacy_endpoints' => ['id', 'endpoint', 'http_method', 'consumer', 'domain_code', 'invocation_count', 'last_invoked_at', 'migration_status', 'replacement_endpoint', 'replacement_status', 'replacement_checked_at', 'owner', 'observation_window_started_at', 'observation_window_days', 'created_at', 'updated_at'],
            'platform_legacy_endpoint_invocations' => ['id', 'invocation_key', 'legacy_endpoint_id', 'request_id', 'invoked_at', 'created_at'],
            'platform_legacy_endpoint_retirement_approvals' => ['id', 'legacy_endpoint_id', 'idempotency_key', 'request_hash', 'status', 'rollback_plan', 'evidence_json', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'approval_note', 'created_at', 'updated_at'],
            'platform_legacy_endpoint_audit_events' => ['id', 'legacy_endpoint_id', 'action_code', 'actor_staff_id', 'request_id', 'before_json', 'after_json', 'occurred_at'],
        ],
        'indexes' => [
            'platform_legacy_endpoints' => ['uq_platform_legacy_endpoint_identity', 'idx_platform_legacy_endpoint_status', 'idx_platform_legacy_endpoint_last_invoked', 'idx_platform_legacy_endpoint_replacement'],
            'platform_legacy_endpoint_invocations' => ['uq_platform_legacy_invocation_key', 'idx_platform_legacy_invocation_endpoint', 'idx_platform_legacy_invocation_request'],
            'platform_legacy_endpoint_retirement_approvals' => ['uq_platform_legacy_retirement_idempotency', 'idx_platform_legacy_retirement_endpoint'],
            'platform_legacy_endpoint_audit_events' => ['idx_platform_legacy_audit_endpoint', 'idx_platform_legacy_audit_actor', 'idx_platform_legacy_audit_request'],
        ],
        'data_checks' => [[
            'id' => 'platform_legacy_endpoint_identity_complete',
            'type' => 'expected_zero',
            'sql' => "SELECT COUNT(*) FROM platform_legacy_endpoints WHERE endpoint = '' OR http_method = '' OR consumer = '' OR domain_code = '' OR observation_window_days < 1",
        ], [
            'id' => 'platform_legacy_retirement_evidence_valid',
            'type' => 'expected_zero',
            'sql' => 'SELECT COUNT(*) FROM platform_legacy_endpoint_retirement_approvals WHERE JSON_VALID(evidence_json) = 0 OR request_hash NOT REGEXP \'^[a-f0-9]{64}$\'',
        ]],
    ],
];

$migrationPaths = glob(__DIR__ . '/migrations/*.sql') ?: [];
sort($migrationPaths, SORT_STRING);
$catalog = [];
foreach ($migrationPaths as $path) {
    $name = basename($path);
    if (!preg_match('/^(\d{12})_.+\.sql$/', $name, $matches)) {
        continue;
    }
    $version = $matches[1];
    if (!isset($checksums[$version])) {
        throw new RuntimeException('Migration checksum declaration missing: ' . $version);
    }
    $sql = (string)file_get_contents($path);
    if (!hash_equals($checksums[$version], hash('sha256', $sql))) {
        throw new RuntimeException('Migration SQL checksum changed: ' . $version);
    }
    $expectation = $platformExpectations[$version] ?? $legacyManifest[$version] ?? [
        'tables' => [],
        'columns' => [],
        'indexes' => [],
    ];
    if (preg_match_all('/CREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s*\((.*?)\)\s*ENGINE\s*=/is', $sql, $createMatches, PREG_SET_ORDER)) {
        foreach ($createMatches as $createMatch) {
            $table = $createMatch[1];
            $expectation['tables'][] = $table;
            foreach (preg_split('/\R/', $createMatch[2]) ?: [] as $definition) {
                if (preg_match('/^\s*`?([a-zA-Z_][a-zA-Z0-9_]*)`?\s+(?!KEY\b|INDEX\b)(?:[a-zA-Z]+\b)/i', $definition, $columnMatch)
                    && !in_array(strtoupper($columnMatch[1]), ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'CONSTRAINT', 'FOREIGN', 'CHECK'], true)) {
                    $expectation['columns'][$table][] = $columnMatch[1];
                }
                if (preg_match('/^\s*(?:UNIQUE\s+)?(?:KEY|INDEX)\s+`?([a-zA-Z_][a-zA-Z0-9_]*)`?/i', $definition, $indexMatch)) {
                    $expectation['indexes'][$table][] = $indexMatch[1];
                }
            }
        }
    }
    $expectation['tables'] = array_values(array_unique($expectation['tables']));
    foreach ($expectation['columns'] as $table => $columns) {
        $expectation['columns'][$table] = array_values(array_unique($columns));
    }
    foreach ($expectation['indexes'] as $table => $indexes) {
        $deduplicated = [];
        foreach ($indexes as $index) {
            $identity = is_array($index) ? implode('|', $index) : $index;
            $deduplicated[$identity] = $index;
        }
        $expectation['indexes'][$table] = array_values($deduplicated);
    }
    $dataChecks = $expectation['data_checks'] ?? $legacyDataChecks[$version] ?? [];
    $catalog[$version] = [
        ...$expectation,
        'version' => $version,
        'name' => $name,
        'sql_file' => 'migrations/' . $name,
        'sql_checksum' => $checksums[$version],
        'data_check_mode' => $dataChecks === [] ? 'structural_only' : 'queries',
        'data_checks' => $dataChecks,
        'compatibility' => [
            'required_readers' => ['N', 'N-1'],
            'required_writers' => ['N', 'N-1'],
            'phase' => 'expand',
            'write_adapters' => [],
            'state_changes' => [],
            'validation_status' => 'validated_task_5_2',
            'rollback_strategy' => 'preserving',
        ],
    ];
}

if (count($catalog) !== count($checksums)) {
    throw new RuntimeException('Migration catalog contains an orphan checksum declaration');
}

return $catalog;
