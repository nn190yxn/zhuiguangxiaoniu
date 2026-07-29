import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const reviewPolicyPath = fileURLToPath(new URL('../api/drill/v2/services/DrillReviewPolicy.php', import.meta.url));
const growthPolicyPath = fileURLToPath(new URL('../api/drill/v2/services/DrillGrowthPolicy.php', import.meta.url));
const reviewService = readFileSync(new URL('../api/drill/v2/services/DrillReviewService.php', import.meta.url), 'utf8');
const coachingService = readFileSync(new URL('../api/drill/v2/services/DrillCoachingService.php', import.meta.url), 'utf8');
const growthService = readFileSync(new URL('../api/drill/v2/services/DrillGrowthService.php', import.meta.url), 'utf8');
const evaluationService = readFileSync(new URL('../api/drill/v2/services/DrillEvaluationService.php', import.meta.url), 'utf8');
const reportService = readFileSync(new URL('../api/drill/v2/services/DrillEvaluationReportService.php', import.meta.url), 'utf8');
const migration = readFileSync(new URL('../database/migrations/202607280006_drill_review_growth_services.sql', import.meta.url), 'utf8');
const manifest = readFileSync(new URL('../database/migration_manifest.php', import.meta.url), 'utf8');

function php(expression) {
  const code = [`require_once ${JSON.stringify(reviewPolicyPath)};`, `require_once ${JSON.stringify(growthPolicyPath)};`, 'try {', `echo json_encode(['ok' => true, 'value' => ${expression}], JSON_THROW_ON_ERROR);`, '} catch (Throwable $error) {', "echo json_encode(['ok' => false, 'message' => $error->getMessage()], JSON_THROW_ON_ERROR);", '}'].join('\n');
  const result = spawnSync('php', ['-r', code], { encoding: 'utf8', timeout: 10_000 });
  assert.equal(result.status, 0, result.stderr);
  return JSON.parse(result.stdout);
}

test('three consecutive required-task failures route exactly one active coaching task', () => {
  assert.match(reviewService, /\$nextFailures >= 3/);
  assert.match(reviewService, /INSERT INTO drill_coaching_tasks/);
  assert.match(reviewService, /ON DUPLICATE KEY UPDATE trigger_attempt_id/);
  assert.match(migration, /coaching_record_json/);
  assert.match(coachingService, /failed_attempts = 0/);
  assert.match(coachingService, /status = 'retry_available'/);
});

test('certification requires total score and every critical item', () => {
  const failedCritical = php("DrillReviewPolicy::passResult(90, ['needs' => ['passed' => false]], ['minimum_score' => 80])");
  assert.equal(failedCritical.value.passed, false);
  const passed = php("DrillReviewPolicy::passResult(80, ['needs' => ['passed' => true], 'close' => ['passed' => true]], ['minimum_score' => 80])");
  assert.equal(passed.value.passed, true);
  const invalidApproval = php("DrillReviewPolicy::assertReviewDecision('passed', 70, 85, ['needs' => ['passed' => false]], ['minimum_score' => 80], '人工复核')");
  assert.equal(invalidApproval.ok, false);
});

test('content-package grade and growth level remain isolated', () => {
  assert.match(reportService, /evaluation_grade/);
  assert.match(growthService, /drill_growth_level_snapshots/);
  const level = php("DrillGrowthPolicy::level([90.0, 80.0], 95.0)");
  assert.deepEqual(level.value.level_code, 'advanced');
  assert.equal(level.value.level_score, 80);
});

test('readiness policy retains every core dimension gate', () => {
  const evaluationPolicy = readFileSync(new URL('../api/drill/v2/services/DrillEvaluationPolicy.php', import.meta.url), 'utf8');
  for (const dimension of ['needs_discovery', 'fab_value', 'trial_close', 'objection_handling', 'pricing_negotiation']) assert.match(evaluationPolicy, new RegExp(`'${dimension}'`));
  assert.match(evaluationPolicy, /\$score < \$minimum \|\| \$score < \$max \* 0\.5/);
  assert.match(evaluationPolicy, /\$total >= 70/);
});

test('rule upgrades preserve history and create current-version growth calculations', () => {
  assert.match(growthService, /markRuleUpgraded/);
  assert.match(growthService, /rubric_version_id <> \?/);
  assert.match(growthService, /status = 'historical'/);
  assert.match(growthService, /scope_type = 'required_section'/);
  assert.match(growthService, /scope_type = 'full_process'/);
});

test('manual score changes require reasons and certification snapshots retain three views', () => {
  const missingReason = php("DrillReviewPolicy::assertReviewDecision('passed', 80, 85, ['needs' => ['passed' => true]], ['minimum_score' => 80], '')");
  assert.equal(missingReason.ok, false);
  assert.match(reviewService, /ai_snapshot_json/);
  assert.match(reviewService, /manual_adjustment_json/);
  assert.match(reviewService, /final_snapshot_json/);
  assert.match(reviewService, /function reassign/);
  assert.match(reviewService, /function evidence/);
});

test('review and certification snapshots are additive conditional migration fields', () => {
  for (const column of ['review_snapshot_json', 'coaching_record_json', 'ai_snapshot_json', 'manual_adjustment_json', 'final_snapshot_json']) {
    assert.match(migration, new RegExp(`COLUMN_NAME = '${column}'`));
    assert.match(manifest, new RegExp(`'${column}'`));
  }
  assert.match(manifest, /'202607280006'/);
  assert.doesNotMatch(migration, /\b(?:DROP|TRUNCATE|DELETE\s+FROM)\b/i);
});
