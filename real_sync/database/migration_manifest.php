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
    '202607270001' => [
        'tables' => ['drill_idempotency_keys'],
        'columns' => [],
        'indexes' => [
            'drill_idempotency_keys' => [
                'uq_drill_idempotency_identity',
                'idx_drill_idempotency_created',
            ],
        ],
    ],
    '202607270002' => [
        'tables' => [
            'drill_training_domains',
            'drill_process_versions',
            'drill_process_stages',
            'drill_persona_dimensions',
            'drill_scenarios',
            'drill_scenario_versions',
            'drill_scenario_personas',
            'drill_rubrics',
            'drill_rubric_versions',
            'drill_legacy_content_mappings',
        ],
        'columns' => [],
        'indexes' => [
            'drill_training_domains' => ['uk_drill_training_domains_code'],
            'drill_process_versions' => ['uk_drill_process_versions_domain_version'],
            'drill_process_stages' => [
                'uk_drill_process_stages_version_code',
                'uk_drill_process_stages_version_order',
            ],
            'drill_persona_dimensions' => [
                'uk_drill_persona_dimensions_domain_code',
                'idx_drill_persona_dimensions_domain_order',
            ],
            'drill_scenarios' => ['uk_drill_scenarios_domain_code'],
            'drill_scenario_versions' => ['uk_drill_scenario_versions_scenario_version'],
            'drill_scenario_personas' => ['uk_drill_scenario_personas_version_dimension'],
            'drill_rubrics' => ['uk_drill_rubrics_domain_code'],
            'drill_rubric_versions' => ['uk_drill_rubric_versions_rubric_version'],
            'drill_legacy_content_mappings' => ['uk_drill_legacy_content_mappings_source'],
        ],
    ],
    '202607270003' => [
        'tables' => [
            'drill_plans',
            'drill_plan_items',
            'drill_plan_target_scopes',
            'drill_plan_publications',
            'drill_publication_reviewers',
            'drill_publication_snapshots',
            'drill_assignments',
            'drill_attempts',
            'drill_attempt_participants',
            'drill_attempt_score_subjects',
            'drill_attempt_reference_bindings',
            'drill_audio_assets',
            'drill_audio_chunks',
            'drill_turns',
            'drill_transcripts',
            'drill_transcript_segments',
            'drill_evaluations',
            'drill_evaluation_evidence',
            'drill_evaluation_reports',
            'drill_report_action_items',
            'drill_review_tasks',
            'drill_coaching_tasks',
            'drill_certifications',
            'drill_notifications',
            'drill_audit_logs',
        ],
        'columns' => [],
        'indexes' => [
            'drill_plans' => ['uk_drill_plans_domain_code'],
            'drill_plan_items' => ['uk_drill_plan_items_order', 'uk_drill_plan_items_scenario'],
            'drill_plan_target_scopes' => ['uk_drill_plan_targets_identity'],
            'drill_plan_publications' => ['uk_drill_plan_publications_version'],
            'drill_publication_reviewers' => ['uk_drill_publication_reviewers_staff'],
            'drill_publication_snapshots' => ['uk_drill_publication_snapshots_key'],
            'drill_assignments' => ['uk_drill_assignments_publication_staff'],
            'drill_attempts' => ['idx_drill_attempts_staff_status', 'idx_drill_attempts_scoring_context'],
            'drill_attempt_participants' => ['uk_drill_attempt_participants_key'],
            'drill_attempt_score_subjects' => ['uk_drill_attempt_score_subjects_type'],
            'drill_attempt_reference_bindings' => ['uk_drill_attempt_reference_binding'],
            'drill_audio_assets' => ['uk_drill_audio_assets_checksum'],
            'drill_audio_chunks' => ['uk_drill_audio_chunks_sequence'],
            'drill_turns' => ['uk_drill_turns_attempt_turn'],
            'drill_transcripts' => ['uk_drill_transcripts_asset_type'],
            'drill_transcript_segments' => ['uk_drill_transcript_segments_order'],
            'drill_evaluations' => ['uk_drill_evaluations_subject_source'],
            'drill_evaluation_evidence' => ['uk_drill_evaluation_evidence_segment'],
            'drill_evaluation_reports' => ['uk_drill_evaluation_reports_evaluation'],
            'drill_report_action_items' => ['idx_drill_report_actions_report_status'],
            'drill_review_tasks' => ['uk_drill_review_tasks_attempt'],
            'drill_coaching_tasks' => ['uk_drill_coaching_tasks_active'],
            'drill_certifications' => ['uk_drill_certifications_assignment', 'uk_drill_certifications_attempt'],
            'drill_notifications' => ['uk_drill_notifications_key'],
            'drill_audit_logs' => ['idx_drill_audit_logs_object'],
        ],
    ],
    '202607270004' => [
        'tables' => [
            'drill_knowledge_points',
            'drill_knowledge_point_versions',
            'drill_learning_resources',
            'drill_learning_resource_versions',
            'drill_knowledge_mapping_versions',
            'drill_rubric_knowledge_links',
            'drill_knowledge_resource_links',
            'drill_content_gaps',
            'drill_reference_materials',
            'drill_reference_material_versions',
            'drill_learning_recommendations',
            'drill_learning_progress',
            'drill_score_calibration_versions',
            'drill_mastery_scores',
            'drill_growth_level_snapshots',
        ],
        'columns' => [
            'drill_attempts' => ['rubric_id'],
        ],
        'indexes' => [
            'drill_knowledge_points' => ['uk_drill_knowledge_points_domain_code'],
            'drill_knowledge_point_versions' => ['uk_drill_knowledge_point_versions_no'],
            'drill_learning_resources' => ['uk_drill_learning_resources_domain_code'],
            'drill_learning_resource_versions' => ['uk_drill_learning_resource_versions_code'],
            'drill_knowledge_mapping_versions' => ['uk_drill_knowledge_mapping_versions_no'],
            'drill_rubric_knowledge_links' => ['uk_drill_rubric_knowledge_links_point'],
            'drill_knowledge_resource_links' => ['uk_drill_knowledge_resource_links_version'],
            'drill_content_gaps' => ['idx_drill_content_gaps_status'],
            'drill_reference_materials' => ['uk_drill_reference_materials_domain_code'],
            'drill_reference_material_versions' => ['uk_drill_reference_material_versions_code'],
            'drill_learning_recommendations' => ['uk_drill_learning_recommendations_resource'],
            'drill_learning_progress' => ['uk_drill_learning_progress_staff_resource'],
            'drill_score_calibration_versions' => ['uk_drill_score_calibrations_no'],
            'drill_mastery_scores' => ['uk_drill_mastery_scores_scope'],
            'drill_growth_level_snapshots' => ['uk_drill_growth_levels_current'],
            'drill_rubrics' => ['uk_drill_rubrics_id_domain'],
            'drill_rubric_versions' => ['uk_drill_rubric_versions_id_rubric'],
            'drill_attempts' => ['uk_drill_attempts_mastery_scope', 'uk_drill_attempts_rubric_version'],
            'drill_evaluations' => ['uk_drill_evaluations_version_scope'],
            'drill_evaluation_evidence' => ['uk_drill_evaluation_evidence_version_scope'],
        ],
    ],
    '202607270005' => [
        'tables' => [
            'drill_rubric_stage_mappings',
            'drill_content_import_batches',
            'drill_content_import_items',
            'drill_content_review_issues',
        ],
        'columns' => [
            'drill_reference_material_versions' => ['content_snapshot_json', 'review_summary_json'],
        ],
        'indexes' => [
            'drill_process_versions' => ['uk_drill_process_versions_id_domain'],
            'drill_process_stages' => ['uk_drill_process_stages_id_version'],
            'drill_rubric_stage_mappings' => ['uk_drill_rubric_stage_mapping'],
            'drill_content_import_batches' => ['uk_drill_content_import_batches_code'],
            'drill_content_import_items' => ['uk_drill_content_import_items_identity'],
            'drill_content_review_issues' => ['uk_drill_content_review_issues_fingerprint'],
        ],
    ],
    '202607270006' => [
        'tables' => [],
        'columns' => [
            'drill_content_gaps' => ['source_attempt_id', 'gap_fingerprint', 'open_gap_fingerprint'],
            'drill_learning_progress' => ['mapping_version_id', 'knowledge_point_id', 'knowledge_point_version_id'],
        ],
        'indexes' => [
            'drill_content_gaps' => ['uk_drill_content_gaps_open', 'idx_drill_content_gaps_attempt'],
            'drill_learning_progress' => ['idx_drill_learning_progress_knowledge'],
        ],
    ],
    '202607270007' => [
        'tables' => [
            'drill_plan_item_reference_bindings',
            'drill_assignment_prerequisite_snapshots',
        ],
        'columns' => [
            'drill_plan_publications' => ['publication_key', 'publication_request_hash'],
        ],
        'indexes' => [
            'drill_plan_item_reference_bindings' => [
                'uk_drill_plan_item_reference',
                'idx_drill_plan_item_reference_material',
            ],
            'drill_assignment_prerequisite_snapshots' => [
                'idx_drill_assignment_prerequisite_history',
                'idx_drill_assignment_prerequisite_status',
            ],
            'drill_plan_publications' => ['uk_drill_plan_publications_key'],
            'drill_attempts' => ['uk_drill_attempts_id_assignment'],
        ],
    ],
    '202607270008' => [
        'tables' => ['drill_attempt_stage_progress'],
        'columns' => [
            'drill_attempts' => [
                'process_snapshot_json',
                'process_snapshot_hash',
                'scenario_snapshot_hash',
                'rubric_snapshot_hash',
                'calibration_snapshot_json',
                'calibration_snapshot_hash',
                'session_goal_snapshot_hash',
            ],
        ],
        'indexes' => [
            'drill_attempt_stage_progress' => [
                'uk_drill_attempt_stage_progress_stage',
                'uk_drill_attempt_stage_progress_order',
                'idx_drill_attempt_stage_progress_status',
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
    '202607280003' => [
        'tables' => [],
        'columns' => [],
        'indexes' => [],
    ],
    '202607280004' => [
        'tables' => [],
        'columns' => [],
        'indexes' => [],
    ],
    '202607280005' => [
        'tables' => [],
        'columns' => [
            'drill_evaluations' => ['provider', 'model', 'prompt_version', 'duration_ms'],
        ],
        'indexes' => [],
    ],
    '202607280006' => [
        'tables' => [],
        'columns' => [
            'drill_review_tasks' => ['review_snapshot_json'],
            'drill_coaching_tasks' => ['coaching_record_json'],
            'drill_certifications' => ['ai_snapshot_json', 'manual_adjustment_json', 'final_snapshot_json'],
        ],
        'indexes' => [],
    ],
    '202607280007' => [
        'tables' => [
            'drill_migration_batches',
            'drill_migration_items',
            'drill_legacy_history_instances',
            'drill_legacy_feedback_mappings',
        ],
        'columns' => [],
        'indexes' => [
            'drill_migration_batches' => ['uk_drill_migration_batches_key', 'idx_drill_migration_batches_status'],
            'drill_migration_items' => ['uk_drill_migration_items_key', 'uk_drill_migration_items_batch_source'],
            'drill_legacy_history_instances' => ['uk_drill_legacy_history_instances_key'],
            'drill_legacy_feedback_mappings' => ['uk_drill_legacy_feedback_mappings_feedback', 'uk_drill_legacy_feedback_mappings_analysis'],
        ],
    ],
    '202607280008' => [
        'tables' => [
            'drill_governance_runs',
            'drill_cutover_batches',
            'drill_cutover_reconciliations',
            'drill_cutover_rollback_drills',
        ],
        'columns' => [],
        'indexes' => [
            'drill_governance_runs' => ['idx_drill_governance_runs_type_status'],
            'drill_cutover_batches' => ['uk_drill_cutover_batches_key'],
            'drill_cutover_reconciliations' => ['uk_drill_cutover_reconciliation_entity'],
            'drill_cutover_rollback_drills' => ['uk_drill_cutover_rollback_batch'],
        ],
    ],
    '202607310001' => [
        'tables' => [
            'recruitment_requirements',
            'recruitment_rule_versions',
            'recruitment_resume_batches',
            'recruitment_requirement_assignments',
            'recruitment_idempotency_keys',
            'recruitment_resume_files',
            'recruitment_resume_file_sources',
            'recruitment_resume_documents',
            'recruitment_resume_document_pages',
            'recruitment_resume_jobs',
            'recruitment_resume_duplicate_events',
            'recruitment_candidates',
            'recruitment_processing_versions',
            'recruitment_applications',
            'recruitment_match_evidence',
            'recruitment_candidate_relations',
            'recruitment_grade_reviews',
            'recruitment_queue_events',
            'recruitment_contact_logs',
            'recruitment_ai_runs',
            'recruitment_extraction_results',
            'recruitment_model_results',
            'recruitment_grade_results',
            'recruitment_export_jobs',
            'recruitment_external_processors',
            'recruitment_retention_policies',
            'recruitment_legal_holds',
            'recruitment_disposal_jobs',
        ],
        'columns' => [
            'admin_operation_logs' => [
                'recruitment_requirement_id',
                'recruitment_batch_id',
                'recruitment_candidate_id',
            ],
        ],
        'indexes' => [
            'recruitment_requirements' => [
                'uq_recruitment_requirements_no',
                'idx_recruitment_requirements_status_store',
                'idx_recruitment_requirements_store_position',
                'idx_recruitment_requirements_creator',
                'idx_recruitment_requirements_target_date',
            ],
            'recruitment_rule_versions' => [
                'uq_recruitment_rule_versions_position_version',
                'idx_recruitment_rule_versions_status',
                'idx_recruitment_rule_versions_source',
                'idx_recruitment_rule_versions_published',
            ],
            'recruitment_resume_batches' => [
                'uq_recruitment_resume_batches_no',
                'idx_recruitment_resume_batches_requirement',
                'idx_recruitment_resume_batches_rule',
                'idx_recruitment_resume_batches_status',
                'idx_recruitment_resume_batches_creator',
            ],
            'recruitment_requirement_assignments' => [
                'uq_recruitment_requirement_assignment_window',
                'idx_recruitment_requirement_assignments_staff',
                'idx_recruitment_requirement_assignments_requirement',
            ],
            'recruitment_idempotency_keys' => [
                'uq_recruitment_idempotency_action',
                'idx_recruitment_idempotency_operator',
            ],
            'recruitment_resume_files' => [
                'uq_recruitment_resume_files_storage',
                'idx_recruitment_resume_files_batch_status',
                'idx_recruitment_resume_files_sha256',
                'idx_recruitment_resume_files_duplicate',
                'idx_recruitment_resume_files_uploader',
            ],
            'recruitment_resume_file_sources' => [
                'idx_recruitment_file_sources_file',
                'idx_recruitment_file_sources_batch',
                'idx_recruitment_file_sources_message',
            ],
            'recruitment_resume_documents' => [
                'uq_recruitment_documents_revision',
                'idx_recruitment_documents_batch_status',
                'idx_recruitment_documents_sha256',
                'idx_recruitment_documents_superseded',
            ],
            'recruitment_resume_document_pages' => [
                'uq_recruitment_document_pages_order',
                'uq_recruitment_document_pages_file_page',
                'idx_recruitment_document_pages_file',
            ],
            'recruitment_resume_jobs' => [
                'uq_recruitment_resume_jobs_idempotency',
                'idx_recruitment_resume_jobs_claim',
                'idx_recruitment_resume_jobs_lease',
                'idx_recruitment_resume_jobs_document',
                'idx_recruitment_resume_jobs_processing_version',
            ],
            'recruitment_resume_duplicate_events' => [
                'idx_recruitment_duplicates_batch_status',
                'idx_recruitment_duplicates_current_file',
                'idx_recruitment_duplicates_current_document',
                'idx_recruitment_duplicates_historical_file',
                'idx_recruitment_duplicates_historical_document',
                'idx_recruitment_duplicates_application',
            ],
            'recruitment_candidates' => [
                'idx_recruitment_candidates_phone_lookup',
                'idx_recruitment_candidates_email_lookup',
                'idx_recruitment_candidates_name',
                'idx_recruitment_candidates_duplicate',
                'idx_recruitment_candidates_canonical',
            ],
            'recruitment_processing_versions' => [
                'uq_recruitment_processing_version_hash',
                'idx_recruitment_processing_versions_document',
                'idx_recruitment_processing_versions_requirement',
            ],
            'recruitment_applications' => [
                'uq_recruitment_applications_document_requirement',
                'idx_recruitment_applications_candidate',
                'idx_recruitment_applications_requirement_grade',
                'idx_recruitment_applications_requirement_queue',
                'idx_recruitment_applications_rule',
                'idx_recruitment_applications_processing',
            ],
            'recruitment_match_evidence' => [
                'idx_recruitment_match_evidence_application',
                'idx_recruitment_match_evidence_rule',
            ],
            'recruitment_candidate_relations' => [
                'uq_recruitment_candidate_relation_pair',
                'idx_recruitment_candidate_relations_related',
                'idx_recruitment_candidate_relations_operator',
            ],
            'recruitment_grade_reviews' => [
                'idx_recruitment_grade_reviews_application',
                'idx_recruitment_grade_reviews_reviewer',
            ],
            'recruitment_queue_events' => [
                'idx_recruitment_queue_events_application',
                'idx_recruitment_queue_events_type',
            ],
            'recruitment_contact_logs' => [
                'idx_recruitment_contact_logs_application',
                'idx_recruitment_contact_logs_schedule',
                'idx_recruitment_contact_logs_operator',
            ],
            'recruitment_ai_runs' => [
                'idx_recruitment_ai_runs_processing',
                'idx_recruitment_ai_runs_document',
                'idx_recruitment_ai_runs_job',
                'idx_recruitment_ai_runs_status',
            ],
            'recruitment_extraction_results' => [
                'uq_recruitment_extraction_processing',
                'idx_recruitment_extraction_application',
            ],
            'recruitment_model_results' => [
                'uq_recruitment_model_processing',
                'idx_recruitment_model_application',
            ],
            'recruitment_grade_results' => [
                'uq_recruitment_grade_processing',
                'idx_recruitment_grade_application',
            ],
            'recruitment_export_jobs' => [
                'uq_recruitment_export_jobs_no',
                'idx_recruitment_export_jobs_requirement',
                'idx_recruitment_export_jobs_batch',
                'idx_recruitment_export_jobs_creator',
                'idx_recruitment_export_jobs_expiry',
            ],
            'recruitment_external_processors' => [
                'uq_recruitment_external_processors_code',
                'idx_recruitment_external_processors_type',
                'idx_recruitment_external_processors_provider',
                'idx_recruitment_external_processors_approval',
            ],
            'recruitment_retention_policies' => [
                'uq_recruitment_retention_policy_version',
                'idx_recruitment_retention_policies_category',
                'idx_recruitment_retention_policies_approval',
            ],
            'recruitment_legal_holds' => [
                'uq_recruitment_legal_holds_no',
                'idx_recruitment_legal_holds_scope',
                'idx_recruitment_legal_holds_status',
                'idx_recruitment_legal_holds_release',
            ],
            'recruitment_disposal_jobs' => [
                'uq_recruitment_disposal_jobs_no',
                'idx_recruitment_disposal_jobs_policy',
                'idx_recruitment_disposal_jobs_scope',
                'idx_recruitment_disposal_jobs_approval',
                'idx_recruitment_disposal_jobs_retry',
            ],
            'admin_operation_logs' => ['idx_admin_operation_logs_recruitment'],
        ],
    ],
    '202608030001' => [
        'tables' => [
            'recruitment_position_route_results',
            'recruitment_position_route_events',
        ],
        'columns' => [
            'recruitment_applications' => [
                'position_confirmation_status',
                'recommended_route_id',
                'confirmed_route_id',
                'position_adjustment_reason',
            ],
        ],
        'indexes' => [
            'recruitment_position_route_results' => [
                'uq_recruitment_route_processing_rank',
                'idx_recruitment_route_document_rank',
                'idx_recruitment_route_candidate',
                'idx_recruitment_route_requirement',
                'idx_recruitment_route_rule',
            ],
            'recruitment_position_route_events' => [
                'idx_recruitment_route_events_application',
                'idx_recruitment_route_events_after_route',
                'idx_recruitment_route_events_operator',
            ],
            'recruitment_applications' => [
                'idx_recruitment_applications_position_confirmation',
                'idx_recruitment_applications_recommended_route',
                'idx_recruitment_applications_confirmed_route',
            ],
        ],
    ],
    '202608030002' => [
        'tables' => [],
        'columns' => [
            'recruitment_resume_batches' => ['batch_mode', 'requirement_id', 'rule_version_id'],
            'recruitment_processing_versions' => [
                'requirement_id',
                'rule_version_id',
                'position_routing_status',
                'position_routing_summary_json',
            ],
        ],
        'indexes' => [],
    ],
    '202608010001' => [
        'tables' => [
            'workload_conversion_rule_versions',
            'workload_conversion_rules',
            'workload_report_conversion_results',
        ],
        'columns' => [],
        'indexes' => [
            'workload_conversion_rule_versions' => [
                'uq_workload_conversion_rule_versions_code',
                'idx_workload_conversion_rule_versions_effective',
                'idx_workload_conversion_rule_versions_source',
            ],
            'workload_conversion_rules' => [
                'uq_workload_conversion_rule',
                'idx_workload_conversion_rules_mode',
                'idx_workload_conversion_rules_version',
            ],
            'workload_report_conversion_results' => [
                'uq_workload_report_conversion_result',
                'idx_workload_report_conversion_results_report',
                'idx_workload_report_conversion_results_rule',
                'idx_workload_report_conversion_results_effective',
            ],
        ],
    ],
    '202608010002' => [
        'tables' => ['workload_management_confirmations'],
        'columns' => [],
        'indexes' => [
            'workload_management_confirmations' => [
                'uq_workload_management_confirmation',
                'idx_workload_management_confirmation_active',
                'idx_workload_management_confirmation_confirmer',
            ],
        ],
    ],
    '202608010003' => [
        'tables' => [],
        'columns' => [],
        'indexes' => [],
    ],
    '202608010004' => [
        'tables' => ['ai_settings'],
        'columns' => [],
        'indexes' => [],
    ],
    '202608030003' => [
        'tables' => [],
        'columns' => [
            'recruitment_resume_file_sources' => [
                'container_original_name',
                'archive_relative_path',
                'archive_entry_sha256',
            ],
        ],
        'indexes' => [
            'recruitment_resume_file_sources' => ['idx_recruitment_file_sources_archive'],
        ],
    ],
    '202608030004' => [
        'tables' => [],
        'columns' => [
            'recruitment_ai_runs' => [
                'preferred_provider',
                'actual_provider',
                'fallback_reason',
                'attempt_summary_json',
            ],
        ],
        'indexes' => [
            'recruitment_ai_runs' => ['idx_recruitment_ai_runs_route'],
        ],
    ],
    '202608040001' => [
        'tables' => [],
        'columns' => [
            'workload_conversion_rule_versions' => [
                'published_by_staff_id',
                'published_at',
            ],
        ],
        'indexes' => [],
    ],
    '202608040002' => [
        'tables' => [],
        'columns' => [],
        'indexes' => [],
    ],
    '202608040003' => [
        'tables' => [],
        'columns' => [],
        'indexes' => [],
    ],
    '202608040004' => [
        'tables' => [],
        'columns' => [],
        'indexes' => [],
    ],
    '202608040005' => [
        'tables' => [],
        'columns' => [],
        'indexes' => [],
    ],
    '202608090001' => [
        'tables' => ['drill_course_need_tags'],
        'columns' => [],
        'indexes' => [
            'drill_course_need_tags' => ['uk_drill_course_need_tag', 'idx_drill_course_need_tags_need'],
        ],
    ],
    '202608100001' => [
        'tables' => [
            'recruitment_resume_batch_requirements',
            'recruitment_resume_classification_versions',
            'recruitment_resume_classification_candidates',
            'recruitment_resume_classification_reviews',
        ],
        'columns' => [
            'recruitment_resume_batches' => ['intake_mode', 'candidate_scope_json', 'candidate_scope_hash', 'classification_status'],
            'recruitment_resume_documents' => ['assigned_requirement_id', 'classification_status', 'classification_version_id'],
        ],
        'indexes' => [
            'recruitment_resume_batches' => ['idx_recruitment_resume_batches_intake'],
            'recruitment_resume_batch_requirements' => ['uq_recruitment_batch_requirement', 'idx_recruitment_batch_requirements_ready'],
            'recruitment_resume_documents' => ['idx_recruitment_documents_classification'],
            'recruitment_resume_classification_versions' => ['uq_recruitment_classification_version', 'idx_recruitment_classification_current', 'idx_recruitment_classification_requirement'],
            'recruitment_resume_classification_candidates' => ['uq_recruitment_classification_candidate', 'uq_recruitment_classification_rank', 'idx_recruitment_classification_candidates_requirement'],
            'recruitment_resume_classification_reviews' => ['idx_recruitment_classification_reviews_document', 'idx_recruitment_classification_reviews_reviewer'],
        ],
    ],
];
