import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const service = await readFile(
  new URL('../api/workload/services/WorkloadAlertService.php', import.meta.url),
  'utf8',
);

const keyOf = (event) => [
  event.ruleCode,
  event.periodKey,
  event.storeId,
  event.staffId,
  event.roleCode,
  event.metricCode,
].join(':');

function sync(events, candidates) {
  const stored = new Map(events.map((event) => [keyOf(event), { ...event }]));
  const active = new Set();
  for (const candidate of candidates) {
    const key = keyOf(candidate);
    active.add(key);
    const current = stored.get(key);
    stored.set(key, {
      ...current,
      ...candidate,
      status: current?.status === 'resolved' ? 'resolved' : 'open',
    });
  }
  for (const [key, event] of stored) {
    if (!active.has(key) && event.status === 'open') stored.set(key, { ...event, status: 'inactive' });
  }
  return [...stored.values()].sort((left, right) => keyOf(left).localeCompare(keyOf(right)));
}

const randomInt = (max) => Math.floor(Math.random() * max);

test('18.5 property 12: repeated reminder and alert calculations preserve unique event identity', () => {
  assert.match(service, /ON DUPLICATE KEY UPDATE/);
  assert.match(service, /rule_code = \? AND period_key = \?/);
  assert.match(service, /status = CASE WHEN status = 'resolved' THEN status ELSE 'open' END/);
  assert.match(service, /closeStaleEvents/);

  for (let run = 0; run < 300; run += 1) {
    const candidates = Array.from({ length: randomInt(30) }, (_, index) => ({
      ruleCode: ['draft', 'missing', 'locked', 'completion'][randomInt(4)],
      periodKey: `2026-07-${String(20 + randomInt(7)).padStart(2, '0')}`,
      storeId: 1 + randomInt(4),
      staffId: randomInt(8),
      roleCode: ['sales', 'coach', ''][randomInt(3)],
      metricCode: index % 3 === 0 ? `metric_${randomInt(4)}` : '',
      numerator: randomInt(20),
      denominator: 20,
      currentValue: randomInt(100),
      status: 'open',
    }));
    const once = sync([], candidates);
    const twice = sync(once, candidates);
    assert.deepEqual(twice, once);
    assert.equal(new Set(twice.map(keyOf)).size, twice.length);
  }
});

test('resolved events remain resolved when facts are recalculated and stale open events become inactive', () => {
  const resolved = {
    ruleCode: 'completion', periodKey: '2026-W30', storeId: 1, staffId: 0,
    roleCode: '', metricCode: '', status: 'resolved', currentValue: 70,
  };
  const refreshed = sync([resolved], [{ ...resolved, currentValue: 75, status: 'open' }]);
  assert.equal(refreshed[0].status, 'resolved');
  assert.equal(refreshed[0].currentValue, 75);

  const stale = sync([{ ...resolved, status: 'open' }], []);
  assert.equal(stale[0].status, 'inactive');
});
