import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const policyPath = fileURLToPath(new URL('../api/drill/v2/services/DrillPlanPolicy.php', import.meta.url));
const statePath = fileURLToPath(new URL('../api/drill/v2/services/DrillAssignmentStateMachine.php', import.meta.url));
const migration = readFileSync(new URL('../database/migrations/202607270007_drill_plan_assignment_services.sql', import.meta.url), 'utf8');
const manifest = readFileSync(new URL('../database/migration_manifest.php', import.meta.url), 'utf8');
const planService = readFileSync(new URL('../api/drill/v2/services/DrillPlanService.php', import.meta.url), 'utf8');
const assignmentService = readFileSync(new URL('../api/drill/v2/services/DrillAssignmentService.php', import.meta.url), 'utf8');
const factsResolver = readFileSync(new URL('../api/drill/v2/services/DrillPrerequisiteFactsResolver.php', import.meta.url), 'utf8');
const resolver = readFileSync(new URL('../api/drill/v2/services/DrillPlanTargetResolver.php', import.meta.url), 'utf8');

function evaluatePhp(expression) {
  const php = [
    `require_once ${JSON.stringify(policyPath)};`,
    `require_once ${JSON.stringify(statePath)};`,
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

test('task 7 migration binds plan materials, prerequisite snapshots, and scoped attempts', () => {
  assert.match(migration, /CREATE TABLE IF NOT EXISTS `drill_plan_item_reference_bindings`/);
  assert.match(migration, /CREATE TABLE IF NOT EXISTS `drill_assignment_prerequisite_snapshots`/);
  assert.match(migration, /chk_drill_plans_status/);
  assert.match(migration, /chk_drill_plan_publications_status/);
  assert.match(migration, /chk_drill_assignments_status/);
  assert.match(migration, /uk_drill_attempts_id_assignment \(id, assignment_id\)/);
  assert.match(migration, /fk_drill_assignments_current_attempt_scope/);
  assert.match(migration, /ADD COLUMN publication_key VARCHAR\(64\)/);
  assert.match(migration, /ADD COLUMN publication_request_hash CHAR\(64\)/);
  assert.match(migration, /uk_drill_plan_publications_key \(plan_id, publication_key\)/);
  assert.match(migration, /idx_drill_assignment_prerequisite_history/);
  assert.match(migration, /DROP INDEX uk_drill_assignment_prerequisite/);
  assert.match(migration, /'source', 'migration_backfill'/);
  assert.match(manifest, /'202607270007'/);
  assert.match(manifest, /'drill_plan_publications' => \['publication_key', 'publication_request_hash'\]/);
});

test('plan definitions enforce focused composition, continuous ordering, and pass rules', () => {
  const valid = evaluatePhp(`(function () {
    DrillPlanPolicy::assertDefinition(
      ['plan_code' => 'needs_focus', 'name' => 'Needs', 'plan_type' => 'focused_practice', 'pass_policy' => ['minimum_score' => 80]],
      [['scenario_version_id' => 1, 'rubric_version_id' => 2, 'sort_order' => 1]],
      [['target_type' => 'position', 'target_key' => 'sales', 'include_mode' => 'include']]
    );
    return true;
  })()`);
  assert.deepEqual(valid, { ok: true, value: true });

  const invalid = evaluatePhp("DrillPlanPolicy::assertItems([['scenario_version_id' => 1, 'rubric_version_id' => 2, 'sort_order' => 1], ['scenario_version_id' => 3, 'rubric_version_id' => 4, 'sort_order' => 2]], 'focused_practice')");
  assert.equal(invalid.ok, false);
  assert.match(invalid.message, /只能编排一个场景/);

  const invalidItemPolicy = evaluatePhp("DrillPlanPolicy::assertItems([['scenario_version_id' => 1, 'rubric_version_id' => 2, 'sort_order' => 1, 'pass_policy' => ['minimum_score' => 101]]], 'focused_practice')");
  assert.equal(invalidItemPolicy.ok, false);
  assert.match(invalidItemPolicy.message, /0 到 100/);
});

test('target scopes union includes, apply exclusions, and ignore inactive staff', () => {
  const result = evaluatePhp(`DrillPlanPolicy::resolveTargets(
    [
      ['staff_id' => 3, 'employee_no' => 'E003', 'growth_stage' => 'advanced', 'position_codes' => ['sales'], 'store_codes' => ['S1'], 'active' => true],
      ['staff_id' => 1, 'employee_no' => 'E001', 'growth_stage' => 'probation', 'position_codes' => ['sales'], 'store_codes' => ['S2'], 'active' => true],
      ['staff_id' => 2, 'employee_no' => 'E002', 'growth_stage' => 'intern', 'position_codes' => ['coach'], 'store_codes' => ['S1'], 'active' => false]
    ],
    [
      ['target_type' => 'position', 'target_key' => 'sales', 'include_mode' => 'include'],
      ['target_type' => 'store', 'target_key' => 'S1', 'include_mode' => 'include'],
      ['target_type' => 'staff', 'target_key' => 'E003', 'include_mode' => 'exclude']
    ]
  )`);
  assert.deepEqual(result, { ok: true, value: [1] });
});

test('reviewers must be active, unique, and review-capable', () => {
  const valid = evaluatePhp("(function () { DrillPlanPolicy::assertReviewers([['staff_id' => 1, 'active' => true, 'can_review' => true]]); return true; })()");
  assert.equal(valid.ok, true);
  const missing = evaluatePhp('DrillPlanPolicy::assertReviewers([])');
  assert.equal(missing.ok, false);
  const inactive = evaluatePhp("DrillPlanPolicy::assertReviewers([['staff_id' => 1, 'active' => false, 'can_review' => true]])");
  assert.equal(inactive.ok, false);
});

test('prerequisite evaluation snapshots every condition and blocks unmet tasks', () => {
  const result = evaluatePhp(`DrillPlanPolicy::evaluatePrerequisites(
    ['conditions' => [
      ['type' => 'assignment_passed', 'key' => 'intro'],
      ['type' => 'mastery_score', 'key' => 'needs', 'scope_type' => 'required_section', 'rubric_version_id' => 3, 'minimum_score' => 80],
      ['type' => 'growth_stage', 'key' => 'stage', 'expected' => 'advanced']
    ]],
    ['intro' => true, 'needs' => 79, 'stage' => 'advanced']
  )`);
  assert.equal(result.value.eligible, false);
  assert.deepEqual(result.value.conditions.map((item) => item.passed), [true, false, true]);
});

test('prerequisite policies require unique keys and complete trusted fact identities', () => {
  const missingGrowthStage = evaluatePhp("DrillPlanPolicy::assertPrerequisitePolicy(['conditions' => [['type' => 'growth_stage', 'key' => 'stage']]])");
  assert.equal(missingGrowthStage.ok, false);
  const incompleteMastery = evaluatePhp("DrillPlanPolicy::assertPrerequisitePolicy(['conditions' => [['type' => 'mastery_score', 'key' => 'needs', 'minimum_score' => 80]]])");
  assert.equal(incompleteMastery.ok, false);
  const duplicate = evaluatePhp("DrillPlanPolicy::assertPrerequisitePolicy(['conditions' => [['type' => 'assignment_passed', 'key' => 'intro'], ['type' => 'growth_stage', 'key' => 'intro', 'expected' => 'advanced']]])");
  assert.equal(duplicate.ok, false);
});

test('assignment state machine enforces window, prerequisite, and terminal states', () => {
  const states = evaluatePhp("[DrillAssignmentStateMachine::transition('assigned', 'start'), DrillAssignmentStateMachine::transition('in_progress', 'submit'), DrillAssignmentStateMachine::transition('ai_evaluating', 'ai_pass'), DrillAssignmentStateMachine::transition('awaiting_review', 'approve')]");
  assert.deepEqual(states.value, ['in_progress', 'ai_evaluating', 'awaiting_review', 'passed']);
  const retry = evaluatePhp("[DrillAssignmentStateMachine::transition('ai_evaluating', 'ai_fail'), DrillAssignmentStateMachine::transition('retry_available', 'retry'), DrillAssignmentStateMachine::transition('ai_evaluating', 'require_coaching'), DrillAssignmentStateMachine::transition('coaching_required', 'reopen')]");
  assert.deepEqual(retry.value, ['retry_available', 'in_progress', 'coaching_required', 'in_progress']);
  const terminal = evaluatePhp("DrillAssignmentStateMachine::transition('passed', 'retry')");
  assert.equal(terminal.ok, false);
  const blocked = evaluatePhp("DrillAssignmentStateMachine::assertStartable('assigned', new DateTimeImmutable('2026-07-28 09:00:00'), new DateTimeImmutable('2026-07-29 09:00:00'), new DateTimeImmutable('2026-07-28 10:00:00'), false)");
  assert.equal(blocked.ok, false);
  assert.match(blocked.message, /前置条件/);
});

test('snapshot hashes are canonical across associative key order', () => {
  const hashes = evaluatePhp("[DrillPlanPolicy::snapshotHash(['b' => 2, 'a' => ['d' => 4, 'c' => 3]]), DrillPlanPolicy::snapshotHash(['a' => ['c' => 3, 'd' => 4], 'b' => 2])]");
  assert.match(hashes.value[0], /^[a-f0-9]{64}$/);
  assert.equal(hashes.value[0], hashes.value[1]);
});

test('publication request hashes are stable and reject parameter drift', () => {
  const hashes = evaluatePhp(`[
    DrillPlanPolicy::publicationRequestHash(7, new DateTimeImmutable('2026-08-01T09:00:00+08:00'), new DateTimeImmutable('2026-08-08T18:00:00+08:00'), [9, 3, 9], [
      ['target_type' => 'store', 'target_key' => 'S1', 'include_mode' => 'include'],
      ['target_type' => 'staff', 'target_key' => 'E3', 'include_mode' => 'exclude']
    ], str_repeat('a', 64)),
    DrillPlanPolicy::publicationRequestHash(7, new DateTimeImmutable('2026-08-01T09:00:00+08:00'), new DateTimeImmutable('2026-08-08T18:00:00+08:00'), [3, 9], [
      ['target_type' => 'staff', 'target_key' => 'E3', 'include_mode' => 'exclude'],
      ['target_type' => 'store', 'target_key' => 'S1', 'include_mode' => 'include']
    ], str_repeat('a', 64)),
    DrillPlanPolicy::publicationRequestHash(7, new DateTimeImmutable('2026-08-01T09:00:00+08:00'), new DateTimeImmutable('2026-08-09T18:00:00+08:00'), [3, 9], [
      ['target_type' => 'store', 'target_key' => 'S1', 'include_mode' => 'include'],
      ['target_type' => 'staff', 'target_key' => 'E3', 'include_mode' => 'exclude']
    ], str_repeat('b', 64))
  ]`);
  assert.equal(hashes.value[0], hashes.value[1]);
  assert.notEqual(hashes.value[0], hashes.value[2]);
});

test('services cover transactional publication, idempotent assignments, notifications, and optimistic status updates', () => {
  for (const method of ['createDraft', 'publish', 'contentSnapshots', 'createAssignments']) {
    assert.match(planService, new RegExp(`function ${method}\\(`));
  }
  assert.match(planService, /FOR UPDATE/);
  assert.match(planService, /INSERT IGNORE INTO drill_assignments/);
  assert.match(planService, /drill_publication_snapshots/);
  assert.match(planService, /publication_key/);
  assert.match(planService, /publication_request_hash/);
  assert.match(planService, /hash_equals/);
  assert.match(planService, /function existingPublication\(/);
  assert.match(planService, /function publicationDefinitionHash\(/);
  assert.match(planService, /plan_composition/);
  assert.match(planService, /function scenarioPersonas\(/);
  assert.match(planService, /function mappingSnapshot\(/);
  assert.match(planService, /drill_assignment_prerequisite_snapshots/);
  assert.match(planService, /drill_notifications/);
  assert.match(planService, /drill_audit_logs/);
  assert.match(assignmentService, /status_version = status_version \+ 1/);
  assert.match(assignmentService, /function enqueueDueReminders\(/);
  assert.match(assignmentService, /function refreshPrerequisites\(/);
  assert.doesNotMatch(assignmentService, /refreshPrerequisites\(int \$assignmentId, array \$facts/);
  assert.match(assignmentService, /policy_snapshot_json/);
  assert.match(assignmentService, /function maximumFailedAttempts\(/);
  assert.match(assignmentService, /ORDER BY latest\.id DESC LIMIT 1/);
  assert.match(factsResolver, /drill_mastery_scores/);
  assert.match(factsResolver, /scope_type = \?.*scope_key = \?.*rubric_version_id = \?/s);
  assert.match(factsResolver, /assignment\.status = 'passed'/);
  assert.match(factsResolver, /SELECT stage FROM staffs/);
  assert.match(resolver, /staff\.lifecycle_status = 'active'/);
  assert.match(resolver, /assignment\.start_date <= \?/);
});
