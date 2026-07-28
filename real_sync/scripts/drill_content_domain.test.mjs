import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const migration = source('../database/migrations/202607270002_drill_content_domain.sql');
const manifest = source('../database/migration_manifest.php');

const expectedTables = [
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
];

test('content domain migration creates the complete versioned governance schema', () => {
  for (const table of expectedTables) {
    assert.match(migration, new RegExp('CREATE TABLE IF NOT EXISTS `' + table + '`'));
    assert.match(manifest, new RegExp(`'${table}'`));
  }
  assert.match(manifest, /'202607270002'/);
  assert.match(migration, /ENUM\('draft', 'in_review', 'published', 'archived'\)/);
  assert.match(migration, /`content_hash` CHAR\(64\)/);
  assert.match(migration, /`source_type` VARCHAR\(64\) NOT NULL/);
  assert.match(migration, /`source_ref` VARCHAR\(255\) DEFAULT NULL/);
});

test('content versions and ordered stages have stable identities', () => {
  assert.match(migration, /uk_drill_process_versions_domain_version/);
  assert.match(migration, /uk_drill_process_stages_version_code/);
  assert.match(migration, /uk_drill_process_stages_version_order/);
  assert.match(migration, /uk_drill_scenario_versions_scenario_version/);
  assert.match(migration, /uk_drill_rubric_versions_rubric_version/);
  assert.match(migration, /uk_drill_scenario_personas_version_dimension/);
  assert.match(migration, /`domain_code` VARCHAR\(64\) NOT NULL/);
  assert.match(migration, /`stage_code` VARCHAR\(64\) NOT NULL/);
  assert.match(migration, /`required` TINYINT\(1\) NOT NULL DEFAULT 1/);
  assert.match(migration, /`scenario_code` VARCHAR\(64\) NOT NULL/);
  assert.match(migration, /`difficulty` VARCHAR\(32\) NOT NULL/);
  assert.match(migration, /`mode` ENUM\('capability', 'script_match', 'hybrid'\) NOT NULL/);
  for (const field of [
    'customer_profile_json',
    'objectives_json',
    'key_actions_json',
    'standard_expressions_json',
    'risk_expressions_json',
    'prompt_policy_json',
    'critical_items_json',
    'score_policy_json',
  ]) {
    assert.match(migration, new RegExp('`' + field + '` JSON NOT NULL'));
  }
  assert.match(migration, /ON DELETE RESTRICT/g);
});

test('new signing and renewal are separate domains with only the approved initial skeleton', () => {
  assert.match(migration, /\('new_signing', '新签训练'/);
  assert.match(migration, /\('renewal', '续费训练'/);
  assert.match(migration, /WHERE `domain_code` = 'new_signing'/);

  const expectedStages = [
    ['lead_preparation', '线索准备', 1],
    ['invitation_confirmation', '邀约确认', 2],
    ['arrival_reception', '到店接待', 3],
    ['needs_diagnosis', '需求诊断', 4],
    ['assessment_experience', '体测与体验协同', 5],
    ['solution_value', '方案与价值呈现', 6],
    ['objection_signing_handoff', '异议及签约交接', 7],
    ['followup_referral', '未成交跟进与转介绍', 8],
  ];
  for (const [code, name, order] of expectedStages) {
    assert.match(migration, new RegExp(`'${code}', '${name}', '[^']*', ${order}, 1, 'active'`));
  }
});

test('bootstrap statements are repeatable and preserve existing governed records', () => {
  const seedStatements = migration.match(/INSERT IGNORE INTO/g) ?? [];
  assert.equal(seedStatements.length, 3);
  assert.match(migration, /UNIQUE KEY `uk_drill_training_domains_code`/);
  assert.match(migration, /UNIQUE KEY `uk_drill_process_versions_domain_version`/);
  assert.match(migration, /UNIQUE KEY `uk_drill_process_stages_version_order`/);
});
