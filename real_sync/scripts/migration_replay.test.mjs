import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const root = new URL('..', import.meta.url);
const hasPhp = spawnSync('php', ['-v'], { encoding: 'utf8' }).status === 0;
const source = readFileSync(new URL('../database/MigrationReplayVerifier.php', import.meta.url), 'utf8');
const hash = (character) => character.repeat(64);

function run(mode, evidence) {
  const result = spawnSync('php', ['scripts/migration-replay.php', mode, '--stdin'], {
    cwd: root,
    encoding: 'utf8',
    input: JSON.stringify(evidence),
    timeout: 10_000,
  });
  return {
    status: result.status,
    stdout: result.stdout ? JSON.parse(result.stdout) : null,
    stderr: result.stderr,
  };
}

function evidence(overrides = {}) {
  return {
    window: { batch_id: 'release-42', since: '2026-07-31 09:00:00', until: '2026-07-31 10:00:00' },
    source_status: {
      business_changes: { available: true, required: true, truncated: false },
      outbox_events: { available: true, required: true, truncated: false },
      side_effects: { available: true, required: true, truncated: false },
    },
    business_changes: [{ change_key: 'change-1', state_hash: hash('a'), requires_outbox: true }],
    outbox_events: [{
      event_key: 'event-1',
      change_key: 'change-1',
      change_in_window: true,
      idempotency_key: 'outbox-1',
      payload_hash: hash('b'),
      expected_side_effect_hash: hash('c'),
      requires_side_effect: true,
      status: 'dispatched',
    }],
    side_effects: [{
      event_key: 'event-1',
      idempotency_key: 'effect-1',
      effect_type: 'message',
      payload_hash: hash('c'),
      status: 'confirmed',
    }],
    ...overrides,
  };
}

test('dry-run emits stable machine evidence without mutations', { skip: !hasPhp }, () => {
  const first = run('dry-run', evidence());
  const second = run('dry-run', evidence());
  assert.equal(first.status, 0, first.stderr);
  assert.equal(first.stdout.schema_version, 'migration-replay-evidence/v1');
  assert.equal(first.stdout.mode, 'dry-run');
  assert.equal(first.stdout.ok, true);
  assert.equal(first.stdout.mutations_applied, false);
  assert.equal(first.stdout.evidence_id, second.stdout.evidence_id);
  assert.deepEqual(first.stdout.summary, {
    business_changes: 1,
    outbox_events: 1,
    side_effects: 1,
    blocking_issues: 0,
    planned_replays: 0,
  });
});

test('verify reports missing events, receipts, and replayable failures', { skip: !hasPhp }, () => {
  const result = run('verify', evidence({
    business_changes: [
      { change_key: 'change-1', state_hash: hash('a'), requires_outbox: true },
      { change_key: 'change-2', state_hash: hash('d'), requires_outbox: true },
    ],
    outbox_events: [{
      event_key: 'event-1',
      change_key: 'change-1',
      change_in_window: true,
      idempotency_key: 'outbox-1',
      payload_hash: hash('b'),
      expected_side_effect_hash: hash('c'),
      requires_side_effect: true,
      status: 'failed',
    }],
    side_effects: [],
  }));
  assert.equal(result.status, 1);
  assert.equal(result.stdout.ok, false);
  assert.deepEqual(result.stdout.issues, [
    { change_key: 'change-2', type: 'missing_outbox_event' },
    { event_key: 'event-1', type: 'missing_side_effect_receipt' },
  ]);
  assert.deepEqual(result.stdout.replay_actions, [
    { action: 'rebuild_outbox_event', change_key: 'change-2' },
    { action: 'reconcile_side_effect', event_key: 'event-1' },
    { action: 'replay_outbox_event', current_status: 'failed', event_key: 'event-1' },
  ]);
});

test('verify detects conflicting hashes, orphan evidence, and unavailable sources', { skip: !hasPhp }, () => {
  const result = run('verify', evidence({
    source_status: {
      business_changes: { available: true, required: true, truncated: true },
      outbox_events: { available: true, required: true, truncated: false },
      side_effects: { available: false, required: true, truncated: false },
    },
    business_changes: [
      { change_key: 'change-1', state_hash: hash('a') },
      { change_key: 'change-1', state_hash: hash('b') },
    ],
    outbox_events: [{
      event_key: 'event-orphan',
      change_key: 'change-missing',
      change_in_window: true,
      payload_hash: hash('c'),
      status: 'confirmed',
    }],
    side_effects: [{
      event_key: 'event-missing',
      idempotency_key: 'effect-1',
      payload_hash: hash('d'),
      status: 'confirmed',
    }],
  }));
  const types = new Set(result.stdout.issues.map((issue) => issue.type));
  assert.equal(result.status, 1);
  for (const type of [
    'business_change_conflict',
    'evidence_source_truncated',
    'evidence_source_unavailable',
    'orphan_outbox_event',
    'orphan_side_effect',
  ]) assert.equal(types.has(type), true, type);
});

test('rollback plan is preserving and orders replay before closure', { skip: !hasPhp }, () => {
  const result = run('rollback-plan', evidence());
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.stdout.strategy, 'preserving');
  assert.deepEqual(result.stdout.steps.map((step) => step.action), [
    'freeze_new_writes',
    'restore_n_minus_one_application',
    'replay_business_writes',
    'replay_outbox_events',
    'reconcile_external_side_effects',
    'require_zero_blocking_issues',
  ]);
  assert.equal(result.stdout.mutations_applied, false);
});

test('database collector remains read-only and bounded', () => {
  assert.match(source, /information_schema\.TABLES/);
  assert.match(source, /platform_sync_changes/);
  assert.match(source, /platform_outbox_events/);
  assert.match(source, /platform_side_effect_receipts/);
  assert.match(source, /LIMIT ' \. \(\$window\['limit'\] \+ 1\)/);
  assert.doesNotMatch(source, /\b(?:INSERT|UPDATE|DELETE|CREATE|ALTER|DROP|TRUNCATE)\b/i);
});
