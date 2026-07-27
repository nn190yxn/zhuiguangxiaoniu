import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const stateService = read('../api/workload/services/WorkloadReportStateService.php');
const saveReport = read('../api/workload/save-report.php');

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1664525) + 1013904223) >>> 0;
    return state / 0x1_0000_0000;
  };
}

function applyEmployeeOperation(state, operation, now, deadline) {
  if (now >= deadline || state === 'locked_missing') return 'locked_missing';
  if (operation === 'saveDraft' && ['missing', 'draft'].includes(state)) return 'draft';
  if (operation === 'submit' && ['missing', 'draft'].includes(state)) return 'submitted';
  return state;
}

function lockExpired(state, now, deadline) {
  if (now >= deadline && ['missing', 'draft'].includes(state)) return 'locked_missing';
  return state;
}

test(`${validatesCriteria(['1.9', '1.10', 'Property 15'])} arbitrary employee operations preserve the lock after midnight`, () => {
  const deadline = Date.UTC(2026, 6, 29, 16, 0, 0);
  const employeeOperations = ['saveDraft', 'submit'];

  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    let state = random() < 0.5 ? 'missing' : 'draft';
    state = lockExpired(state, deadline, deadline);

    for (let step = 0; step < 256; step += 1) {
      const now = deadline + Math.floor(random() * 31_536_000_000);
      const operation = employeeOperations[Math.floor(random() * employeeOperations.length)];
      state = applyEmployeeOperation(state, operation, now, deadline);
      if (random() < 0.5) state = lockExpired(state, now, deadline);
      assert.equal(state, 'locked_missing', `seed ${seed}, step ${step}`);
    }
  }
});

test(`${validatesCriteria(['1.9', 'Property 15'])} the exact Shanghai midnight boundary is inclusive`, () => {
  const deadline = Date.UTC(2026, 6, 29, 16, 0, 0);

  assert.equal(applyEmployeeOperation('draft', 'submit', deadline - 1, deadline), 'submitted');
  assert.equal(applyEmployeeOperation('draft', 'submit', deadline, deadline), 'locked_missing');
  assert.equal(applyEmployeeOperation('missing', 'saveDraft', deadline, deadline), 'locked_missing');
  assert.equal(lockExpired('missing', deadline, deadline), 'locked_missing');
  assert.equal(lockExpired('draft', deadline, deadline), 'locked_missing');
});

test(`${validatesCriteria(['1.9', '1.10', 'Property 15'])} management correction remains the only post-lock transition`, () => {
  const deadline = Date.UTC(2026, 6, 29, 16, 0, 0);
  let state = lockExpired('draft', deadline, deadline);

  state = applyEmployeeOperation(state, 'submit', deadline + 1, deadline);
  assert.equal(state, 'locked_missing');
  state = 'corrected';
  assert.equal(state, 'corrected');
});

test(`${validatesCriteria(['1.9', '1.10', 'Property 15'])} production contracts reject and roll back employee writes after the deadline`, () => {
  assert.match(stateService, /if \(\$now >= \$deadline\)/);
  assert.match(stateService, /日报已于次日 00:00 锁定/);
  assert.match(stateService, /completion_status = 'locked_missing'/);
  assert.match(stateService, /completion_status = \?[^;]+deadline_at <= \?/s);
  assert.equal((saveReport.match(/->assertEmployeeWritable\(\$date\)/g) ?? []).length, 2);
  assert.ok(saveReport.indexOf('->assertEmployeeWritable($date)') < saveReport.indexOf('ORDER BY id ASC FOR UPDATE'));
  assert.ok(saveReport.lastIndexOf('->assertEmployeeWritable($date)') > saveReport.indexOf('->synchronizeReport($reportId)'));
  assert.match(saveReport, /catch \(WorkloadReportStateException \$e\)[\s\S]+\$pdo->rollBack\(\)/);
  assert.match(stateService, /if \(\$this->databaseNow\(\) < \$deadline\)[\s\S]+日报尚未锁定/);
  assert.match(stateService, /synchronizeReport\(\$reportId, true\)/);
});
