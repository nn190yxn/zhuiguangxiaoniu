import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const policyPath = fileURLToPath(new URL('../api/drill/v2/services/DrillContentPolicy.php', import.meta.url));
const packagePath = fileURLToPath(new URL('../api/drill/v2/services/DrillNewSignContentPackage.php', import.meta.url));
const migration = readFileSync(new URL('../database/migrations/202607270005_drill_content_governance_services.sql', import.meta.url), 'utf8');
const manifest = readFileSync(new URL('../database/migration_manifest.php', import.meta.url), 'utf8');
const importer = readFileSync(new URL('../api/drill/v2/services/DrillNewSignContentImporter.php', import.meta.url), 'utf8');
const contentService = readFileSync(new URL('../api/drill/v2/services/DrillContentService.php', import.meta.url), 'utf8');
const rubricService = readFileSync(new URL('../api/drill/v2/services/DrillRubricService.php', import.meta.url), 'utf8');

function evaluatePhp(expression) {
  const php = [
    `require_once ${JSON.stringify(policyPath)};`,
    `require_once ${JSON.stringify(packagePath)};`,
    'try {',
    `  $value = ${expression};`,
    "  echo json_encode(['ok' => true, 'value' => $value], JSON_THROW_ON_ERROR);",
    '} catch (Throwable $error) {',
    "  echo json_encode(['ok' => false, 'error' => get_class($error), 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);",
    '}',
  ].join('\n');
  const result = spawnSync('php', ['-r', php], { encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('governance migration adds versioned mappings, imports, review issues, and material snapshots', () => {
  for (const table of [
    'drill_rubric_stage_mappings',
    'drill_content_import_batches',
    'drill_content_import_items',
    'drill_content_review_issues',
  ]) {
    assert.match(migration, new RegExp('CREATE TABLE IF NOT EXISTS `' + table + '`'));
    assert.match(manifest, new RegExp(`'${table}'`));
  }
  assert.match(manifest, /'202607270005'/);
  assert.match(migration, /ADD COLUMN content_snapshot_json JSON/);
  assert.match(migration, /ADD COLUMN review_summary_json JSON/);
  assert.match(migration, /fk_drill_rubric_stage_mapping_process/);
  assert.match(migration, /fk_drill_rubric_stage_mapping_stage/);
  assert.match(migration, /ON DELETE RESTRICT/g);
});

test('content management permission and process ordering fail closed', () => {
  const allowed = evaluatePhp("(function () { DrillContentPolicy::assertPermission(['drill.content_manage']); DrillContentPolicy::assertOrderedStages([['stage_code' => 'one_stage', 'sort_order' => 1], ['stage_code' => 'two_stage', 'sort_order' => 2]]); return true; })()");
  assert.deepEqual(allowed, { ok: true, value: true });

  const denied = evaluatePhp("(function () { DrillContentPolicy::assertPermission(['drill.review']); return true; })()");
  assert.equal(denied.ok, false);
  assert.equal(denied.error, 'DomainException');

  const unordered = evaluatePhp("(function () { DrillContentPolicy::assertOrderedStages([['stage_code' => 'one_stage', 'sort_order' => 1], ['stage_code' => 'two_stage', 'sort_order' => 3]]); return true; })()");
  assert.equal(unordered.ok, false);
});

test('controlled personas reject unknown values and canonicalize approved combinations', () => {
  const normalized = evaluatePhp("DrillContentPolicy::normalizePersona(['goal' => ['fitness'], 'difficulty' => ['basic']], ['difficulty' => 'basic', 'goal' => 'fitness'])");
  assert.deepEqual(normalized, { ok: true, value: { difficulty: 'basic', goal: 'fitness' } });

  const rejected = evaluatePhp("DrillContentPolicy::normalizePersona(['goal' => ['fitness']], ['goal' => 'renewal'])");
  assert.equal(rejected.ok, false);
  assert.match(rejected.message, /白名单/);
});

test('rubric structure requires complete dimensions, exact totals, mappings, and hybrid weights', () => {
  const valid = evaluatePhp("(function () { $dimensions = [['code' => 'needs', 'weight' => 60, 'key_actions' => ['ask'], 'standard_expressions' => ['question'], 'evidence_requirements' => ['quote'], 'calibration_anchors' => ['anchor']], ['code' => 'close', 'weight' => 40, 'key_actions' => ['close'], 'standard_expressions' => ['confirm'], 'evidence_requirements' => ['quote'], 'calibration_anchors' => ['anchor']]]; DrillContentPolicy::assertRubricConfig(['dimensions' => $dimensions, 'max_score' => 100, 'mode' => 'hybrid', 'score_policy' => ['capability_weight' => .8, 'script_match_weight' => .2]]); DrillContentPolicy::assertDimensionMappings($dimensions, [['dimension_code' => 'needs', 'stage_code' => 'needs'], ['dimension_code' => 'close', 'stage_code' => 'close']], ['needs', 'close']); return true; })()");
  assert.deepEqual(valid, { ok: true, value: true });

  const invalid = evaluatePhp("DrillContentPolicy::assertRubricConfig(['dimensions' => [['code' => 'needs', 'weight' => 90, 'key_actions' => ['ask'], 'standard_expressions' => ['question'], 'evidence_requirements' => ['quote'], 'calibration_anchors' => ['anchor']]], 'max_score' => 100, 'mode' => 'hybrid', 'score_policy' => ['capability_weight' => .5, 'script_match_weight' => .4]])");
  assert.equal(invalid.ok, false);
});

test('AI candidates require human review and archived content stays outside published catalog', () => {
  const blocked = evaluatePhp("(function () { DrillContentPolicy::assertHumanReviewedCandidate('ai_candidate', null, null); return true; })()");
  assert.equal(blocked.ok, false);

  const catalog = evaluatePhp("DrillContentPolicy::publishedCatalog([['id' => 1, 'scenario_status' => 'active', 'version_status' => 'published'], ['id' => 2, 'scenario_status' => 'archived', 'version_status' => 'published'], ['id' => 3, 'scenario_status' => 'active', 'version_status' => 'draft']])");
  assert.deepEqual(catalog.value.map((item) => item.id), [1]);
  assert.match(contentService, /status = 'active' AND version\.status = 'published'/);
  assert.match(contentService, /function transitionProcessVersion/);
  assert.match(contentService, /function updateScenarioDraft/);
  assert.match(contentService, /assertContentMutable/);
  assert.match(contentService, /function upsertPersonaValue/);
});

test('new signing package imports two draft rubrics, seven persona dimensions, and blocking reviews', () => {
  const summary = evaluatePhp("(function () { $p = DrillNewSignContentPackage::payload(); return ['rubrics' => array_column($p['rubrics'], 'rubric_code'), 'statuses' => array_column($p['rubrics'], 'status'), 'personas' => count($p['personas']), 'dimensions' => count($p['rubrics'][0]['dimensions']), 'materials' => count($p['reference_materials']), 'issues' => array_column($p['review_issues'], 'code'), 'hash' => DrillNewSignContentPackage::hash()]; })()");
  assert.equal(summary.ok, true);
  assert.deepEqual(summary.value.rubrics, ['new_sign_real_call_v1', 'new_sign_training_demo_v1']);
  assert.deepEqual(summary.value.statuses, ['draft', 'draft']);
  assert.equal(summary.value.personas, 7);
  assert.equal(summary.value.dimensions, 8);
  assert.equal(summary.value.materials, 4);
  assert.match(summary.value.hash, /^[a-f0-9]{64}$/);
  assert.deepEqual(summary.value.issues, [
    'package_lessons_conflict',
    'brand_numbers_unverified',
    'effect_claims_unverified',
    'case_authorization_missing',
    'material_validity_missing',
  ]);
  assert.match(importer, /status = 'review_pending'/);
  assert.match(importer, /authorization_status.*'pending'.*'review_pending'/s);
  assert.match(rubricService, /drill_rubric_stage_mappings/);
});

test('new signing scoring contexts route to isolated rubric identities', () => {
  const routes = evaluatePhp("[DrillContentPolicy::rubricCodeForContext('new_signing', 'real_call_review'), DrillContentPolicy::rubricCodeForContext('new_signing', 'training_demo'), DrillContentPolicy::rubricCodeForContext('new_signing', 'ai_roleplay')]");
  assert.deepEqual(routes.value, ['new_sign_real_call_v1', 'new_sign_training_demo_v1', 'new_sign_training_demo_v1']);

  const renewal = evaluatePhp("DrillContentPolicy::rubricCodeForContext('renewal', 'real_call_review')");
  assert.equal(renewal.ok, false);
  assert.match(renewal.message, /新签训练域/);
});
