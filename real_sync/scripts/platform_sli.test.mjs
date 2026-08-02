import assert from 'node:assert/strict';
import { test } from 'node:test';

import {
  TIER1_JOURNEYS,
  aggregateMinute,
  buildJourneyDefinitions,
  calculateMonthlyAvailability,
  probeJourney,
  runSyntheticJourneys,
} from './platform_sli.mjs';

const response = (status, body) => ({ status, text: async () => body });

test('四条 Tier-1 旅程使用真实入口且合成登录凭据保持在请求体', () => {
  const journeys = buildJourneyDefinitions({
    baseUrl: 'https://example.com/',
    username: 'probe-user',
    password: 'probe-password',
  });
  assert.deepEqual(journeys.map(({ id }) => id), TIER1_JOURNEYS);
  assert.deepEqual(journeys.map(({ url }) => url), [
    'https://example.com/',
    'https://example.com/api/auth-jwt.php',
    'https://example.com/internal.html',
    'https://example.com/api/platform/health.php?check=ready',
  ]);
  assert.match(journeys[1].request.body, /probe-user/);
  assert.doesNotMatch(String(runSyntheticJourneys), /probe-password/);
});

test('每次运行对每条旅程至少执行两次探测', async () => {
  const calls = [];
  const fetchImpl = async (url) => {
    calls.push(url);
    if (url.includes('auth-jwt')) return response(200, JSON.stringify({ code: 0, data: { token: 'opaque' } }));
    if (url.includes('health.php')) return response(200, JSON.stringify({ data: { health: { status: 'healthy' } } }));
    return response(200, '<html></html>');
  };
  const results = await runSyntheticJourneys({
    baseUrl: 'https://example.com',
    username: 'probe-user',
    password: 'probe-password',
    fetchImpl,
    now: () => Date.UTC(2026, 7, 1),
  });
  assert.equal(calls.length, 8);
  assert.equal(results.length, 8);
  assert.ok(TIER1_JOURNEYS.every((id) => results.filter(({ journey }) => journey === id).length === 2));
  assert.ok(results.every(({ success }) => success));
});

test('超时、HTTP 5xx、结构错误和权威状态失败均判定为失败', async () => {
  const [homepage, , , coreApi] = buildJourneyDefinitions({ baseUrl: 'https://example.com' });
  const httpFailure = await probeJourney(homepage, { fetchImpl: async () => response(503, 'down') });
  const structureFailure = await probeJourney(homepage, { fetchImpl: async () => response(200, 'plain text') });
  const authorityFailure = await probeJourney(coreApi, {
    fetchImpl: async () => response(200, JSON.stringify({ data: { health: { status: 'unhealthy' } } })),
  });
  const timeoutFailure = await probeJourney(homepage, {
    timeoutMs: 1,
    fetchImpl: async (_url, { signal }) => new Promise((_resolve, reject) => {
      signal.addEventListener('abort', () => reject(Object.assign(new Error('timeout'), { name: 'AbortError' })));
    }),
  });
  assert.deepEqual(
    [httpFailure.failure, structureFailure.failure, authorityFailure.failure, timeoutFailure.failure],
    ['http_5xx', 'response_structure', 'authority_assertion', 'timeout'],
  );
});

test('可用分钟要求四条旅程各有至少两次成功', () => {
  const probes = TIER1_JOURNEYS.flatMap((journey) => [
    { journey, success: true },
    { journey, success: true },
  ]);
  assert.equal(aggregateMinute(probes).available, true);
  assert.equal(aggregateMinute(probes.slice(1)).available, false);
  assert.equal(aggregateMinute([...probes, { journey: 'core_api', success: false, failure: 'http_5xx' }]).available, true);
});

test('月度可用率使用自然月总分钟且计划维护计入分母', () => {
  const january = calculateMonthlyAvailability(
    '2026-01',
    Array.from({ length: 44_600 }, () => ({ available: true })),
    ['2026-01-10T00:00Z', '2026-01-10T00:01Z'],
  );
  const february = calculateMonthlyAvailability('2028-02', []);
  assert.equal(january.total_minutes, 44_640);
  assert.equal(january.available_minutes, 44_600);
  assert.equal(january.planned_maintenance_minutes, 2);
  assert.equal(january.unavailable_minutes, 40);
  assert.equal(january.objective_met, true);
  assert.equal(february.total_minutes, 41_760);
  assert.equal(february.availability, 0);
});
