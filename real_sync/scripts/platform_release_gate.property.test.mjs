import assert from 'node:assert/strict';
import { test } from 'node:test';

import { evaluateReleaseObservation } from './platform_release_gate.mjs';
import { calculateMonthlyAvailability } from './platform_sli.mjs';

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function observation(metrics = {}, overrides = {}) {
  return {
    batch_type: 'shared_platform',
    observed_minutes: 60,
    metrics: {
      journey_probes: [],
      http_5xx: { requests: 100, errors: 0 },
      latency: { samples: 100, p95_ms: 400, baseline_p95_ms: 400, consecutive_minutes_over_2x: 0 },
      data_differences: 0,
      queue_oldest_minutes: 0,
      tasks: { total: 100, failed: 0 },
      permission_denials: { rate: 0.01, baseline_rate: 0.01 },
      ...metrics,
    },
    ...overrides,
  };
}

test(`${validatesCriteria(['10.8', '13.11', '13.12'])} 最低样本和精确阈值边界保持稳定`, () => {
  assert.equal(evaluateReleaseObservation(observation({ http_5xx: { requests: 19, errors: 19 } })).stop, false);
  assert.equal(evaluateReleaseObservation(observation({ http_5xx: { requests: 20, errors: 0 } })).stop, false);
  assert.equal(evaluateReleaseObservation(observation({ http_5xx: { requests: 20, errors: 1 } })).stop, true);
  assert.equal(evaluateReleaseObservation(observation({
    latency: { samples: 99, p95_ms: 900, baseline_p95_ms: 400, consecutive_minutes_over_2x: 15 },
  })).stop, false);
  assert.equal(evaluateReleaseObservation(observation({
    latency: { samples: 100, p95_ms: 800, baseline_p95_ms: 400, consecutive_minutes_over_2x: 15 },
  })).stop, false);
  assert.equal(evaluateReleaseObservation(observation({
    latency: { samples: 100, p95_ms: 801, baseline_p95_ms: 400, consecutive_minutes_over_2x: 15 },
  })).stop, true);
});

test(`${validatesCriteria(['13.7', '13.8', '13.9'])} 观察窗口在 15、30、60 分钟边界完成`, () => {
  for (const [batchType, required] of [
    ['documentation_or_static', 15],
    ['single_domain', 30],
    ['shared_platform', 60],
  ]) {
    assert.equal(evaluateReleaseObservation(observation({}, { batch_type: batchType, observed_minutes: required - 1 })).decision, 'observe');
    assert.equal(evaluateReleaseObservation(observation({}, { batch_type: batchType, observed_minutes: required })).decision, 'continue');
  }
});

test(`${validatesCriteria(['10.10', '13.10'])} 成功探测会中断同旅程连续失败序列`, () => {
  const probes = [
    { journey: 'core_api', success: false, observed_at: '2026-08-01T00:00:00Z' },
    { journey: 'core_api', success: true, observed_at: '2026-08-01T00:00:10Z' },
    { journey: 'core_api', success: false, observed_at: '2026-08-01T00:00:20Z' },
  ];
  assert.equal(evaluateReleaseObservation(observation({ journey_probes: probes })).stop, false);
  probes.push({ journey: 'core_api', success: false, observed_at: '2026-08-01T00:00:30Z' });
  assert.equal(evaluateReleaseObservation(observation({ journey_probes: probes })).stop, true);
});

test(`${validatesCriteria(['10.7', '10.12'])} 计划维护和低流量保持自然月分母与样本门禁`, () => {
  const minuteResults = Array.from({ length: 100 }, () => ({ available: true }));
  const withMaintenance = calculateMonthlyAvailability('2026-04', minuteResults, ['maintenance-1', 'maintenance-2']);
  const withoutMaintenance = calculateMonthlyAvailability('2026-04', minuteResults, []);
  assert.equal(withMaintenance.total_minutes, 43_200);
  assert.equal(withMaintenance.availability, withoutMaintenance.availability);
  assert.equal(withMaintenance.planned_maintenance_minutes, 2);
  assert.equal(evaluateReleaseObservation(observation({ http_5xx: { requests: 19, errors: 19 } })).stop, false);
});

test(`${validatesCriteria(['10', '13'])} 固定种子输入形成唯一可用率和发布决策`, () => {
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = generator(seed);
    const input = observation({
      http_5xx: { requests: Math.floor(random() * 200), errors: Math.floor(random() * 10) },
      latency: {
        samples: Math.floor(random() * 200),
        p95_ms: Math.floor(random() * 2000),
        baseline_p95_ms: 100 + Math.floor(random() * 900),
        consecutive_minutes_over_2x: Math.floor(random() * 30),
      },
      data_differences: random() > 0.95 ? 1 : 0,
      queue_oldest_minutes: Math.floor(random() * 30),
      tasks: { total: 100, failed: Math.floor(random() * 10) },
      permission_denials: { rate: random() / 10, baseline_rate: random() / 20 },
    });
    assert.deepEqual(evaluateReleaseObservation(input), evaluateReleaseObservation(structuredClone(input)));

    const minutes = Array.from({ length: 1000 }, () => ({ available: random() > 0.01 }));
    const first = calculateMonthlyAvailability('2026-08', minutes, ['maintenance']);
    const second = calculateMonthlyAvailability('2026-08', structuredClone(minutes), ['maintenance']);
    assert.deepEqual(first, second);
  }
});

function generator(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1_664_525) + 1_013_904_223) >>> 0;
    return state / 0x1_0000_0000;
  };
}
