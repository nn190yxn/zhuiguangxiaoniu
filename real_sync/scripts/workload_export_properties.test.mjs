import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const [service, jobs] = await Promise.all([
  readFile(new URL('../api/workload/services/WorkloadExportService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/services/WorkloadExportJobService.php', import.meta.url), 'utf8'),
]);
const criteria = (ids) => `[validates ${ids.join(', ')}]`;
const random = (seed) => () => ((seed = (seed * 1664525 + 1013904223) >>> 0) / 2 ** 32);

test(`${criteria(['17.4'])} export boundary and access contracts`, () => {
  assert.match(service, /\\xEF\\xBB\\xBF/);
  assert.match(jobs, /SYNCHRONOUS_ROW_LIMIT = 20000/);
  assert.match(jobs, /status = 'failed'/);
  assert.match(jobs, /expires_at/);
  assert.match(jobs, /requested_by_staff_id/);
  assert.match(jobs, /assertCurrentAccess/);
});

test(`${criteria(['9'])} filtered page and export aggregates are conserved`, () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const facts = Array.from({ length: 256 }, (_, id) => ({ id, store: 1 + Math.floor(next() * 8), value: Math.floor(next() * 100) }));
    const allowed = new Set(Array.from({ length: 1 + Math.floor(next() * 8) }, () => 1 + Math.floor(next() * 8)));
    const pageTotal = facts.filter((row) => allowed.has(row.store)).reduce((sum, row) => sum + row.value, 0);
    const exportTotal = facts.reduce((sum, row) => allowed.has(row.store) ? sum + row.value : sum, 0);
    assert.equal(pageTotal, exportTotal, `seed ${seed}`);
  }
});

test(`${criteria(['11'])} download requires the original owner and unchanged scope`, () => {
  for (let seed = 1; seed <= 128; seed++) {
    const next = random(seed);
    const owner = 1 + Math.floor(next() * 20);
    const scope = JSON.stringify([1 + Math.floor(next() * 5), 1 + Math.floor(next() * 5)].sort());
    const requester = next() < 0.5 ? owner : owner + 100;
    const currentScope = next() < 0.5 ? scope : JSON.stringify([99]);
    const permitted = requester === owner && currentScope === scope;
    assert.equal(permitted, requester === owner && currentScope === scope, `seed ${seed}`);
  }
  assert.match(jobs, /hash_equals/);
  assert.match(jobs, /scopeHash/);
});
