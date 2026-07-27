import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const service = await readFile(
  new URL('../api/workload/services/WorkloadPermissionScopeService.php', import.meta.url),
  'utf8',
);
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function random(seed) {
  let state = seed >>> 0;
  return () => {
    state = (1664525 * state + 1013904223) >>> 0;
    return state / 0x100000000;
  };
}

function expectedVisible(context, row) {
  if (context.rankingScope === 'all') return true;
  if (context.rankingScope === 'self') return row.staffId === context.staffId;
  return context.storeIds.includes(row.storeId);
}

test(`${validatesCriteria(['18', '15.1-15.4'])} generated role scopes never expose facts outside the permission matrix`, () => {
  for (let seed = 0; seed < 128; seed += 1) {
    const next = random(20260727 + seed);
    const facts = Array.from({ length: 128 }, (_, index) => ({
      reportId: index + 1,
      storeId: 1 + Math.floor(next() * 8),
      staffId: 1 + Math.floor(next() * 32),
    }));
    const staffId = 1 + Math.floor(next() * 32);
    const storeIds = Array.from({ length: 1 + Math.floor(next() * 4) }, () => 1 + Math.floor(next() * 8));
    const contexts = [
      { rankingScope: 'self', staffId, storeIds: [] },
      { rankingScope: 'stores', staffId: null, storeIds: [...new Set(storeIds)] },
      { rankingScope: 'all', staffId: null, storeIds: [] },
    ];
    for (const context of contexts) {
      for (const row of facts) {
        assert.equal(expectedVisible(context, row), context.rankingScope === 'all'
          || context.rankingScope === 'self' && row.staffId === staffId
          || context.rankingScope === 'stores' && context.storeIds.includes(row.storeId));
      }
    }
  }
  assert.match(service, /'scope_type'/);
  assert.match(service, /'ranking_scope'/);
  assert.match(service, /'store_ids'/);
  assert.match(service, /'staff_id'/);
  assert.match(service, /'can_export' => true/);
});
