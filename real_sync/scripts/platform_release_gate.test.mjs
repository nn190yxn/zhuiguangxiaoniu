import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
  OBSERVATION_WINDOWS,
  createReleaseEvidence,
  evaluateReleaseObservation,
  maximumConsecutiveJourneyFailures,
} from './platform_release_gate.mjs';

function healthyObservation(overrides = {}) {
  return {
    batch_type: 'single_domain',
    observed_minutes: 30,
    metrics: {
      journey_probes: [],
      http_5xx: { requests: 100, errors: 0 },
      latency: { samples: 100, p95_ms: 500, baseline_p95_ms: 400, consecutive_minutes_over_2x: 0 },
      data_differences: 0,
      queue_oldest_minutes: 1,
      tasks: { total: 100, failed: 0 },
      permission_denials: { rate: 0.01, baseline_rate: 0.01 },
    },
    ...overrides,
  };
}

test('批次类型映射 15、30 和 60 分钟观察窗口', () => {
  assert.deepEqual(OBSERVATION_WINDOWS, {
    documentation_or_static: 15,
    single_domain: 30,
    shared_platform: 60,
  });
  assert.equal(evaluateReleaseObservation(healthyObservation()).decision, 'continue');
  assert.equal(evaluateReleaseObservation(healthyObservation({ observed_minutes: 29 })).decision, 'observe');
});

test('同一核心旅程连续两次失败立即停止发布', () => {
  const probes = [
    { journey: 'core_api', success: false, observed_at: '2026-08-01T00:00:00Z' },
    { journey: 'public_homepage', success: false, observed_at: '2026-08-01T00:00:10Z' },
    { journey: 'core_api', success: false, observed_at: '2026-08-01T00:00:20Z' },
  ];
  assert.deepEqual(maximumConsecutiveJourneyFailures(probes), { journey: 'core_api', count: 2 });
  const result = evaluateReleaseObservation(healthyObservation({
    metrics: { ...healthyObservation().metrics, journey_probes: probes },
  }));
  assert.equal(result.stop, true);
  assert.equal(result.triggers[0].code, 'core_journey_consecutive_failures');
});

test('全部发布阈值生成稳定停止原因', () => {
  const result = evaluateReleaseObservation(healthyObservation({
    metrics: {
      journey_probes: [],
      http_5xx: { requests: 20, errors: 1 },
      latency: { samples: 100, p95_ms: 801, baseline_p95_ms: 400, consecutive_minutes_over_2x: 15 },
      data_differences: 1,
      queue_oldest_minutes: 16,
      tasks: { total: 20, failed: 1 },
      permission_denials: { rate: 0.031, baseline_rate: 0.01 },
    },
  }));
  assert.deepEqual(result.triggers.map(({ code }) => code), [
    'http_5xx_rate',
    'p95_latency_regression',
    'data_reconciliation_difference',
    'queue_backlog',
    'task_failure_rate',
    'permission_denial_regression',
  ]);
  assert.equal(result.decision, 'stop_and_evaluate_rollback');
});

test('发布证据保存范围、备份、验证、观察、决策和恢复', () => {
  const evidence = createReleaseEvidence({
    batch_id: 'release-20260801-01',
    generated_at: '2026-08-01T00:30:00.000Z',
    scope: { files: ['api/platform/health.php'] },
    backup: { id: 'backup-01', verified: true },
    validation: { status: 'passed', checks: ['php-syntax', 'node-contract'] },
    observation: healthyObservation(),
    recovery: { status: 'not_required', actions: [] },
  });
  assert.equal(evidence.schema_version, '1.0');
  assert.equal(evidence.decision.decision, 'continue');
  for (const field of ['scope', 'backup', 'validation', 'observation', 'decision', 'recovery']) {
    assert.ok(field in evidence);
  }
});
