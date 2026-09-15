import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const servicePath = fileURLToPath(new URL('../api/drill/v2/services/DrillConversationService.php', import.meta.url));
const policyPath = fileURLToPath(new URL('../api/drill/v2/services/DrillConversationPolicy.php', import.meta.url));
const statePath = fileURLToPath(new URL('../api/drill/v2/services/DrillAttemptStateMachine.php', import.meta.url));
const migration = readFileSync(new URL('../database/migrations/202607270008_drill_instance_services.sql', import.meta.url), 'utf8');
const manifest = readFileSync(new URL('../database/migration_manifest.php', import.meta.url), 'utf8');
const service = readFileSync(servicePath, 'utf8');

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

test('task 8 migration stores resumable snapshots and stage progress', () => {
  assert.match(manifest, /'202607270008'/);
  for (const column of [
    'process_snapshot_json',
    'process_snapshot_hash',
    'scenario_snapshot_hash',
    'rubric_snapshot_hash',
    'calibration_snapshot_json',
    'calibration_snapshot_hash',
    'session_goal_snapshot_hash',
  ]) {
    assert.match(migration, new RegExp(column));
    assert.match(manifest, new RegExp(column));
  }
  assert.match(migration, /CREATE TABLE IF NOT EXISTS `drill_attempt_stage_progress`/);
  assert.match(migration, /uk_drill_attempt_stage_progress_stage/);
  assert.match(migration, /chk_drill_attempt_stage_progress_times/);
});

test('conversation service exposes all task 8 instance lifecycle methods', () => {
  for (const method of [
    'createFromAssignment',
    'createPractice',
    'resumeAttempt',
    'submitTextTurn',
    'advanceStage',
    'pauseAttempt',
    'resumePausedAttempt',
    'endAttempt',
  ]) {
    assert.match(service, new RegExp(`function ${method}\\(`));
  }
});

test('attempt creation freezes assignment snapshots, stages, references, and session goal hashes', () => {
  assert.match(service, /function createFromAssignment\(/);
  assert.match(service, /function createPractice\(/);
  assert.match(service, /FOR UPDATE/g);
  assert.match(service, /drill_publication_snapshots/);
  assert.match(service, /drill_attempt_stage_progress/);
  assert.match(service, /drill_attempt_reference_bindings/);
  assert.match(service, /DrillPlanPolicy::snapshotHash\(\$process\)/);
  assert.match(service, /DrillPlanPolicy::snapshotHash\(\$sessionGoal\)/);
  assert.match(service, /current_attempt_id = \?/);
  assert.match(service, /status = IF\(status = 'assigned', 'in_progress', status\)/);
});

test('resume returns canonical attempt state, stage progress, and finalized turns', () => {
  assert.match(service, /function resumeAttempt\(int \$attemptId, int \$staffId\)/);
  assert.match(service, /function normalizeAttempt\(/);
  assert.match(service, /function stageProgress\(/);
  assert.match(service, /function completedTurns\(/);
  assert.match(service, /finalized_at IS NOT NULL/);
  assert.match(service, /'last_completed_turn_no' => \(int\) \$attempt\['last_completed_turn_no'\]/);
  assert.match(service, /'snapshots' => \[/);
});

test('text turns, stage advancement, and ending use optimistic version checks', () => {
  assert.match(service, /nextTurnNumbers\(\s*\(int\) \$attempt\['last_completed_turn_no'\]/s);
  assert.match(service, /COALESCE\(MAX\(turn_no\), 0\).*FOR UPDATE/);
  assert.match(service, /status_version = status_version \+ 1/g);
  assert.match(service, /WHERE id = \? AND status_version = \?/g);
  assert.match(service, /DrillConversationPolicy::nextStage\(\$progress\)/);
  assert.match(service, /DrillAttemptStateMachine::isEndReplay/);
  assert.match(service, /'idempotent_replay' => true/);
});

test('conversation policy and attempt state machine guard task 8 transitions', () => {
  const turnNumbers = evaluatePhp('DrillConversationPolicy::nextTurnNumbers(2, 2)');
  assert.deepEqual(turnNumbers, { ok: true, value: [3, 4] });
  const conflict = evaluatePhp('DrillConversationPolicy::nextTurnNumbers(2, 3)');
  assert.equal(conflict.ok, false);

  const lifecycle = evaluatePhp("[DrillAttemptStateMachine::transition('active', 'pause'), DrillAttemptStateMachine::transition('paused', 'resume'), DrillAttemptStateMachine::transition('active', 'end'), DrillAttemptStateMachine::isEndReplay('evaluating')]");
  assert.deepEqual(lifecycle.value, ['paused', 'active', 'evaluating', true]);
  const terminal = evaluatePhp("DrillAttemptStateMachine::transition('completed', 'end')");
  assert.equal(terminal.ok, false);
});
