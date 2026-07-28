import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { test } from 'node:test';

const stateMachinePath = fileURLToPath(new URL('../api/drill/v2/services/DrillContentVersionStateMachine.php', import.meta.url));
const bindingPath = fileURLToPath(new URL('../api/drill/v2/services/DrillContentVersionBinding.php', import.meta.url));

function evaluatePhp(expression) {
  const php = [
    `require ${JSON.stringify(stateMachinePath)};`,
    `require_once ${JSON.stringify(bindingPath)};`,
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

test('content versions follow draft, review, publication, and archive states', () => {
  const result = evaluatePhp("(function () { $status = 'draft'; foreach (['submit_review', 'approve', 'archive'] as $event) { $status = DrillContentVersionStateMachine::transition($status, $event); } return $status; })()");
  assert.deepEqual(result, { ok: true, value: 'archived' });
});

test('review rejection returns a version to draft and invalid transitions fail closed', () => {
  const rejected = evaluatePhp("DrillContentVersionStateMachine::transition('in_review', 'reject')");
  assert.deepEqual(rejected, { ok: true, value: 'draft' });

  const invalid = evaluatePhp("DrillContentVersionStateMachine::transition('published', 'publish')");
  assert.equal(invalid.ok, false);
  assert.equal(invalid.error, 'DomainException');
});

test('published snapshots are immutable and revisions receive the next version number', () => {
  const immutable = evaluatePhp("(function () { DrillContentVersionStateMachine::assertContentMutable('published'); return true; })()");
  assert.equal(immutable.ok, false);
  assert.equal(immutable.error, 'DomainException');

  const nextVersion = evaluatePhp('DrillContentVersionStateMachine::nextVersionNo([3, 1, 2])');
  assert.deepEqual(nextVersion, { ok: true, value: 4 });
});

test('snapshot hashes are canonical and locked references include exact version ids', () => {
  const hashes = evaluatePhp("[DrillContentVersionStateMachine::snapshotHash(['b' => 2, 'a' => ['y' => 2, 'x' => 1]]), DrillContentVersionStateMachine::snapshotHash(['a' => ['x' => 1, 'y' => 2], 'b' => 2])] ");
  assert.equal(hashes.ok, true);
  assert.equal(hashes.value[0], hashes.value[1]);

  const binding = evaluatePhp("DrillContentVersionBinding::lock(12, ['age_band' => '30-39', 'goal' => 'fat_loss'], 27)");
  assert.equal(binding.ok, true);
  assert.equal(binding.value.scenario_version_id, 12);
  assert.equal(binding.value.rubric_version_id, 27);
  assert.equal(binding.value.persona_snapshot.goal, 'fat_loss');
  assert.match(binding.value.persona_snapshot_hash, /^[a-f0-9]{64}$/);
});
