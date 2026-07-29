import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const policyPath = fileURLToPath(new URL('../api/drill/v2/services/DrillLearningPolicy.php', import.meta.url));
const migration = readFileSync(new URL('../database/migrations/202607270006_drill_learning_services.sql', import.meta.url), 'utf8');
const manifest = readFileSync(new URL('../database/migration_manifest.php', import.meta.url), 'utf8');
const service = readFileSync(new URL('../api/drill/v2/services/DrillLearningService.php', import.meta.url), 'utf8');
const rubricService = readFileSync(new URL('../api/drill/v2/services/DrillRubricService.php', import.meta.url), 'utf8');

function evaluatePhp(expression) {
  const php = [
    `require_once ${JSON.stringify(policyPath)};`,
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

test('task 6 migration adds unique open gaps and knowledge-owned progress', () => {
  assert.match(migration, /ADD COLUMN gap_fingerprint CHAR\(64\)/);
  assert.match(migration, /open_gap_fingerprint CHAR\(64\) NULL/);
  assert.match(migration, /ADD UNIQUE KEY uk_drill_content_gaps_open/);
  assert.match(migration, /trg_drill_content_gaps_open_insert/);
  assert.match(migration, /trg_drill_content_gaps_open_update/);
  assert.match(migration, /SET duplicate_gap\.status = 'waived'/);
  assert.match(migration, /ADD COLUMN knowledge_point_id BIGINT UNSIGNED/);
  assert.match(migration, /ADD COLUMN knowledge_point_version_id BIGINT UNSIGNED/);
  assert.match(migration, /fk_drill_learning_progress_point/);
  assert.match(migration, /fk_drill_content_gaps_attempt/);
  assert.match(manifest, /'202607270006'/);
  assert.match(manifest, /'open_gap_fingerprint'/);
  assert.match(manifest, /'idx_drill_learning_progress_knowledge'/);
});

test('learning content versions follow review, publication, and retirement states', () => {
  const states = evaluatePhp("[DrillLearningPolicy::transition('draft', 'submit_review'), DrillLearningPolicy::transition('review_pending', 'approve'), DrillLearningPolicy::transition('published', 'retire')]");
  assert.deepEqual(states, { ok: true, value: ['review_pending', 'published', 'retired'] });

  const rejected = evaluatePhp("DrillLearningPolicy::transition('draft', 'approve')");
  assert.equal(rejected.ok, false);
  assert.equal(rejected.error, 'DomainException');
});

test('knowledge and resource drafts require structured mobile-ready content', () => {
  const valid = evaluatePhp("(function () { DrillLearningPolicy::assertKnowledgePayload(['knowledge_code' => 'needs', 'title' => 'Needs', 'content' => ['steps' => ['ask']]]); DrillLearningPolicy::assertResourcePayload(['resource_code' => 'needs-card', 'title' => 'Needs card', 'resource_type' => 'card', 'mobile_locator' => '/learn/needs', 'content' => ['body' => 'x'], 'estimated_minutes' => 3]); return true; })()");
  assert.deepEqual(valid, { ok: true, value: true });

  const blankMobile = evaluatePhp("DrillLearningPolicy::assertResourcePayload(['resource_code' => 'needs-card', 'title' => 'Needs card', 'resource_type' => 'card', 'mobile_locator' => '  ', 'content' => ['body' => 'x'], 'estimated_minutes' => 3])");
  assert.equal(blankMobile.ok, false);
  assert.match(blankMobile.message, /移动端入口/);
});

test('mapping links accept only reinforceable rubric criteria and unique point versions', () => {
  const criteria = evaluatePhp("DrillLearningPolicy::reinforceableCriteria(['traceable', ['code' => 'coach_only', 'reinforceable' => false], ['criterion_code' => 'ask_needs', 'reinforceable' => true]])");
  assert.deepEqual(criteria.value, ['traceable', 'ask_needs']);

  const valid = evaluatePhp("(function () { DrillLearningPolicy::assertMappingLinks([['dimension_code' => 'needs', 'criterion_code' => 'ask_needs', 'knowledge_point_version_id' => 3, 'learning_resource_version_ids' => [8]]], ['ask_needs']); return true; })()");
  assert.equal(valid.ok, true);

  const outside = evaluatePhp("DrillLearningPolicy::assertMappingLinks([['dimension_code' => 'needs', 'criterion_code' => 'renewal_only', 'knowledge_point_version_id' => 3, 'learning_resource_version_ids' => [8]]], ['ask_needs'])");
  assert.equal(outside.ok, false);
  assert.match(outside.message, /规则外/);
});

test('failed critical results support keyed and structured scoring snapshots', () => {
  const keyed = evaluatePhp("DrillLearningPolicy::failedCriteria(['ask_needs' => false, 'traceable' => true])");
  assert.deepEqual(keyed.value, ['ask_needs']);

  const structured = evaluatePhp("DrillLearningPolicy::failedCriteria([['criterion_code' => 'ask_needs', 'passed' => false], ['code' => 'traceable', 'met' => true]])");
  assert.deepEqual(structured.value, ['ask_needs']);

  const keyedObjects = evaluatePhp("DrillLearningPolicy::failedCriteria(['ask_needs' => ['passed' => false], 'traceable' => ['met' => true]])");
  assert.deepEqual(keyedObjects.value, ['ask_needs']);
});

test('recommendation candidates stay in the locked domain and published mapping', () => {
  const result = evaluatePhp("DrillLearningPolicy::publishedRecommendations([['id' => 1, 'mapping_version_id' => 7, 'domain_id' => 2, 'mapping_status' => 'published', 'knowledge_status' => 'published', 'resource_status' => 'published', 'mobile_locator' => '/m/1'], ['id' => 2, 'mapping_version_id' => 8, 'domain_id' => 2, 'mapping_status' => 'published', 'knowledge_status' => 'published', 'resource_status' => 'published', 'mobile_locator' => '/m/2'], ['id' => 3, 'mapping_version_id' => 7, 'domain_id' => 3, 'mapping_status' => 'published', 'knowledge_status' => 'published', 'resource_status' => 'published', 'mobile_locator' => '/m/3'], ['id' => 4, 'mapping_version_id' => 7, 'domain_id' => 2, 'mapping_status' => 'published', 'knowledge_status' => 'published', 'resource_status' => 'retired', 'mobile_locator' => '/m/4'], ['id' => 5, 'mapping_version_id' => 7, 'domain_id' => 2, 'mapping_status' => 'published', 'knowledge_status' => 'published', 'resource_status' => 'published', 'mobile_locator' => '']], 7, 2)");
  assert.deepEqual(result.value.map((item) => item.id), [1]);
});

test('service covers mapping publication, preparation, evidence recommendations, progress, and retry', () => {
  for (const method of [
    'createKnowledgePointDraft',
    'createLearningResourceDraft',
    'createMappingDraft',
    'transitionMappingVersion',
    'assertRubricPublishable',
    'preparationLearning',
    'generateRecommendations',
    'recordProgress',
  ]) {
    assert.match(service, new RegExp(`function ${method}\\(`));
  }
  assert.match(service, /drill_evaluation_evidence/);
  assert.match(service, /reason_snapshot_json/);
  assert.match(service, /mapping_hash/);
  assert.match(service, /missing_mobile_resource/);
  assert.match(service, /'in_progress' => 'started'/);
  assert.match(service, /INSERT IGNORE INTO drill_knowledge_resource_links/);
  assert.match(service, /function createRetirementGaps/);
  assert.match(service, /retry.*allowed/s);
  assert.match(rubricService, /assertRubricPublishable\(\$versionId\)/);
});

test('content gap fingerprints are stable and distinguish mapping identities', () => {
  const fingerprints = evaluatePhp("[DrillLearningPolicy::gapFingerprint(1, 2, 3, 'needs', 'ask', 4, 'missing_mobile_resource'), DrillLearningPolicy::gapFingerprint(1, 2, 3, 'needs', 'ask', 4, 'missing_mobile_resource'), DrillLearningPolicy::gapFingerprint(1, 5, 3, 'needs', 'ask', 4, 'missing_mobile_resource')]");
  assert.match(fingerprints.value[0], /^[a-f0-9]{64}$/);
  assert.equal(fingerprints.value[0], fingerprints.value[1]);
  assert.notEqual(fingerprints.value[0], fingerprints.value[2]);
});
