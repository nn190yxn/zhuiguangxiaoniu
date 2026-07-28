import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const migration = source('../database/migrations/202607270004_drill_knowledge_growth_domain.sql');
const manifest = source('../database/migration_manifest.php');

const expectedTables = [
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
];

test('task 4 migration creates the complete additive knowledge and growth domain', () => {
  assert.match(manifest, /'202607270004'/);
  for (const table of expectedTables) {
    assert.match(migration, new RegExp('CREATE TABLE IF NOT EXISTS `' + table + '`'));
    assert.match(manifest, new RegExp(`'${table}'`));
  }
  assert.equal(migration.match(/CREATE TABLE IF NOT EXISTS/g)?.length, expectedTables.length);
  assert.doesNotMatch(migration, /\b(?:DROP|TRUNCATE|DELETE\s+FROM)\b/i);
});

test('stable entities and immutable versions retain domain and content identities', () => {
  for (const contract of [
    'uk_drill_knowledge_points_domain_code',
    'uk_drill_knowledge_point_versions_no',
    'uk_drill_learning_resources_domain_code',
    'uk_drill_learning_resource_versions_code',
    'uk_drill_reference_materials_domain_code',
    'uk_drill_reference_material_versions_code',
  ]) {
    assert.match(migration, new RegExp(contract));
  }
  assert.match(migration, /`content_hash` CHAR\(64\) NOT NULL/g);
  assert.match(migration, /fk_drill_knowledge_point_versions_point/);
  assert.match(migration, /fk_drill_learning_resource_versions_resource/);
  assert.match(migration, /fk_drill_reference_material_versions_material/);
});

test('published mappings require complete criteria and mobile resource coverage', () => {
  assert.match(migration, /`expected_reinforceable_criteria` INT UNSIGNED NOT NULL/);
  assert.match(migration, /`mapped_reinforceable_criteria` INT UNSIGNED NOT NULL DEFAULT 0/);
  assert.match(migration, /`mobile_resource_ready_points` INT UNSIGNED NOT NULL DEFAULT 0/);
  assert.match(migration, /`mapping_hash` CHAR\(64\) NOT NULL/);
  assert.match(migration, /`mapped_reinforceable_criteria` = `expected_reinforceable_criteria`/);
  assert.match(migration, /`mobile_resource_ready_points` = `mapped_knowledge_points`/);
  assert.match(migration, /fk_drill_rubric_knowledge_links_mapping/);
  assert.match(migration, /fk_drill_knowledge_resource_links_mapping/);
});

test('recommendations lock attempt evidence, mapping, knowledge, and resource versions', () => {
  for (const contract of [
    'fk_drill_learning_recommendations_attempt',
    'fk_drill_learning_recommendations_evaluation',
    'fk_drill_learning_recommendations_evidence',
    'fk_drill_learning_recommendations_mapping',
    'fk_drill_learning_recommendations_criterion_point',
    'fk_drill_learning_recommendations_mapped_resource',
    'fk_drill_learning_recommendations_resource',
  ]) {
    assert.match(migration, new RegExp(contract));
  }
  assert.match(migration, /FOREIGN KEY \(`evidence_id`, `evaluation_id`, `attempt_id`, `rubric_version_id`\)/);
  assert.match(migration, /`reason_snapshot_json` JSON NOT NULL/);
  assert.match(migration, /uk_drill_learning_recommendations_resource/);
  assert.match(migration, /uk_drill_learning_progress_staff_resource/);
});

test('reference materials and calibration versions enforce publication evidence', () => {
  assert.match(migration, /`authorization_status` ENUM\('pending', 'authorized', 'rejected', 'expired'\)/);
  assert.match(migration, /`effective_from` DATETIME DEFAULT NULL/);
  assert.match(migration, /`effective_until` DATETIME DEFAULT NULL/);
  assert.match(migration, /chk_drill_reference_material_validity/);
  assert.match(migration, /chk_drill_reference_material_published/);
  assert.match(migration, /`test_sample_snapshot_json` JSON NOT NULL/);
  assert.match(migration, /`human_benchmark_snapshot_json` JSON NOT NULL/);
  assert.match(migration, /`weight_changes_json` JSON NOT NULL/);
  assert.match(migration, /`threshold_changes_json` JSON NOT NULL/);
  assert.match(migration, /fk_drill_attempts_calibration/);
  assert.match(migration, /fk_drill_evaluations_calibration/);
});

test('mastery and growth records bind current rubric versions and dual qualification', () => {
  assert.match(migration, /uk_drill_mastery_scores_scope/);
  assert.match(migration, /`latest_attempt_id` BIGINT UNSIGNED NOT NULL/);
  assert.match(migration, /`best_attempt_id` BIGINT UNSIGNED NOT NULL/);
  assert.match(migration, /`effective_best_score` DECIMAL\(5, 2\) NOT NULL/);
  assert.match(migration, /`level_floor_score` TINYINT UNSIGNED DEFAULT NULL/);
  assert.match(migration, /`level_code` = 'foundation' AND `level_floor_score` = 0/);
  assert.match(migration, /`level_code` = 'developing' AND `level_floor_score` = 60/);
  assert.match(migration, /`level_code` = 'proficient' AND `level_floor_score` = 70/);
  assert.match(migration, /`level_code` = 'advanced' AND `level_floor_score` = 80/);
  assert.match(migration, /`level_code` = 'expert' AND `level_floor_score` = 90/);
  assert.match(migration, /`level_score` = LEAST\(`required_section_min_score`, `full_process_score`\)/);
  assert.match(migration, /`required_sections_passed` = `required_sections_total`/);
  assert.match(migration, /`required_section_min_score` >= `level_floor_score`/);
  assert.match(migration, /`full_process_score` >= `level_floor_score`/);
  assert.match(migration, /uk_drill_growth_levels_current/);
  assert.match(migration, /`status` ENUM\('current', 'reassessment_pending', 'historical'\)/);
  assert.match(migration, /chk_drill_growth_levels_reassessment/);
  assert.match(migration, /`status` = 'reassessment_pending' AND `level_code` IS NULL AND `level_floor_score` IS NULL/);
  assert.match(migration, /FOREIGN KEY \(`historical_reference_id`, `staff_id`, `domain_id`\)/);
});

test('execution domain placeholders receive physical version foreign keys', () => {
  assert.match(migration, /fk_drill_attempt_reference_material/);
  assert.match(migration, /fk_drill_report_actions_resource/);
  assert.match(migration, /fk_drill_attempts_rubric_domain/);
  assert.match(migration, /fk_drill_attempts_rubric_identity/);
  assert.match(migration, /fk_drill_evaluations_attempt_rubric/);
  assert.match(migration, /ON DELETE RESTRICT/g);
});
