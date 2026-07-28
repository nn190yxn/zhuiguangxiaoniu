import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const migration = source('../database/migrations/202607270003_drill_execution_domain.sql');
const manifest = source('../database/migration_manifest.php');

const expectedTables = [
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
];

test('task 3 migration creates the complete additive execution domain', () => {
  assert.match(manifest, /'202607270003'/);
  for (const table of expectedTables) {
    assert.match(migration, new RegExp('CREATE TABLE IF NOT EXISTS `' + table + '`'));
    assert.match(manifest, new RegExp(`'${table}'`));
  }
  assert.equal(migration.match(/CREATE TABLE IF NOT EXISTS/g)?.length, expectedTables.length);
  assert.doesNotMatch(migration, /\b(?:DROP|TRUNCATE|DELETE\s+FROM)\b/i);
});

test('plan publication locks targeting, reviewers, versions, and employee uniqueness', () => {
  assert.match(migration, /`plan_type` ENUM\('focused_practice', 'comprehensive_certification'\)/);
  assert.match(migration, /`target_type` ENUM\('position', 'store', 'staff', 'growth_stage'\)/);
  assert.match(migration, /uk_drill_plan_items_order/);
  assert.match(migration, /uk_drill_plan_targets_identity/);
  assert.match(migration, /uk_drill_publication_reviewers_staff/);
  assert.match(migration, /uk_drill_publication_snapshots_key/);
  assert.match(migration, /uk_drill_assignments_publication_staff/);
  assert.match(migration, /`content_hash` CHAR\(64\) NOT NULL/);
  assert.match(migration, /chk_drill_plan_publication_window/);
});

test('attempts retain scoring context, participants, references, media, and speaker segments', () => {
  assert.match(migration, /`evaluation_context` ENUM\('ai_roleplay', 'training_demo', 'real_call_review'\)/);
  assert.match(migration, /`persona_snapshot_hash` CHAR\(64\) NOT NULL/);
  assert.match(migration, /`calibration_version_id` BIGINT UNSIGNED DEFAULT NULL/);
  assert.match(migration, /uk_drill_attempt_participants_key/);
  assert.match(migration, /uk_drill_attempt_score_subjects_type/);
  assert.match(migration, /uk_drill_attempt_reference_binding/);
  assert.match(migration, /`consent_basis` VARCHAR\(255\) DEFAULT NULL/);
  assert.match(migration, /`access_scope_json` JSON NOT NULL/);
  assert.match(migration, /`retention_until` DATETIME NOT NULL/);
  assert.match(migration, /uk_drill_audio_chunks_sequence/);
  assert.match(migration, /uk_drill_turns_attempt_turn/);
  assert.match(migration, /`is_coach_supplement` TINYINT\(1\) NOT NULL DEFAULT 0/);
  assert.match(migration, /chk_drill_transcript_segment_range/);
});

test('evaluation evidence and certification use same-attempt composite references', () => {
  assert.match(migration, /fk_drill_evaluations_subject_attempt/);
  assert.match(migration, /fk_drill_evidence_evaluation_attempt/);
  assert.match(migration, /fk_drill_evidence_segment_attempt/);
  assert.match(migration, /`quoted_text` TEXT NOT NULL/);
  assert.match(migration, /`speaker_role` VARCHAR\(64\) NOT NULL/);
  assert.match(migration, /`evaluation_grade` ENUM\('excellent', 'good', 'qualified', 'unqualified'\)/);
  assert.match(migration, /`readiness_rule_version` VARCHAR\(64\) DEFAULT NULL/);
  assert.match(migration, /chk_drill_review_adjustment/);
  assert.match(migration, /fk_drill_certifications_review_attempt/);
  assert.match(migration, /fk_drill_certifications_evaluation_attempt/);
  assert.match(migration, /`result_snapshot_json` JSON NOT NULL/);
});

test('conditional checks and indexes support state transitions and operational queues', () => {
  for (const contract of [
    'chk_drill_assignments_completion',
    'chk_drill_attempts_completion',
    'chk_drill_audio_consent',
    'chk_drill_transcripts_completed',
    'chk_drill_evaluations_completed',
    'chk_drill_review_decision',
    'chk_drill_coaching_trigger',
    'chk_drill_notifications_sent',
  ]) {
    assert.match(migration, new RegExp(contract));
  }
  assert.match(migration, /GENERATED ALWAYS AS \(CASE WHEN `status` IN \('open', 'in_progress'\)/);
  assert.match(migration, /uk_drill_coaching_tasks_active/);
  assert.match(migration, /idx_drill_review_tasks_reviewer_queue/);
  assert.match(migration, /idx_drill_notifications_recipient/);
  assert.match(migration, /idx_drill_audit_logs_object/);
  assert.match(migration, /ON DELETE RESTRICT/g);
});
