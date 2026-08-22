import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const stateService = readFileSync(
  new URL('../api/workload/services/WorkloadReportStateService.php', import.meta.url),
  'utf8',
);
const migration = readFileSync(
  new URL('../database/migrations/202607240002_workload_governance.sql', import.meta.url),
  'utf8',
);

const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;
const completionStates = new Set([
  'missing',
  'draft',
  'submitted',
  'locked_missing',
  'corrected',
  'exempt',
]);

function seededRandom(seed) {
  let state = seed >>> 0;
  return () => {
    state = (Math.imul(state, 1103515245) + 12345) >>> 0;
    return state / 0x1_0000_0000;
  };
}

class CompletionStateModel {
  obligations = new Map();

  generate(key, exempt = false) {
    if (!this.obligations.has(key)) this.obligations.set(key, exempt ? 'exempt' : 'missing');
  }

  saveDraft(key) {
    this.generate(key);
    if (['missing', 'draft'].includes(this.obligations.get(key))) this.obligations.set(key, 'draft');
  }

  submit(key) {
    this.generate(key);
    if (['missing', 'draft'].includes(this.obligations.get(key))) this.obligations.set(key, 'submitted');
  }

  lockExpired(key) {
    this.generate(key);
    if (['missing', 'draft'].includes(this.obligations.get(key))) {
      this.obligations.set(key, 'locked_missing');
    }
  }

  correct(key) {
    this.generate(key);
    if (['locked_missing', 'submitted'].includes(this.obligations.get(key))) {
      this.obligations.set(key, 'corrected');
    }
  }
}

function assertProperty3(model, seed, step) {
  for (const [key, status] of model.obligations) {
    assert.ok(completionStates.has(status), `seed ${seed}, step ${step}: ${key} has invalid state ${status}`);
    const memberships = [status === 'draft', status === 'submitted', status === 'missing'];
    assert.ok(
      memberships.filter(Boolean).length <= 1,
      `seed ${seed}, step ${step}: ${key} belongs to multiple completion states`,
    );
  }
}

test(`${validatesCriteria(['1.4', '1.5', '1.7', '1.8', '1.9', 'Property 3'])} arbitrary transitions keep draft, submitted, and missing mutually exclusive`, () => {
  const operations = ['generate', 'saveDraft', 'submit', 'lockExpired', 'correct'];
  for (let seed = 1; seed <= 128; seed += 1) {
    const random = seededRandom(seed);
    const model = new CompletionStateModel();

    for (let step = 0; step < 256; step += 1) {
      const key = `obligation-${1 + Math.floor(random() * 32)}`;
      const operation = operations[Math.floor(random() * operations.length)];
      model[operation](key);
      assertProperty3(model, seed, step);
    }
  }
});

test(`${validatesCriteria(['1.4', '1.5', '1.7', '1.8', 'Property 3'])} report synchronization replaces the previous open state`, () => {
  const model = new CompletionStateModel();
  model.generate('sales-1');
  assert.equal(model.obligations.get('sales-1'), 'missing');
  model.saveDraft('sales-1');
  assert.equal(model.obligations.get('sales-1'), 'draft');
  model.submit('sales-1');
  assert.equal(model.obligations.get('sales-1'), 'submitted');
  model.saveDraft('sales-1');
  assert.equal(model.obligations.get('sales-1'), 'submitted');
  assertProperty3(model, 'synchronization', 'complete');
});

test(`${validatesCriteria(['1.9', 'Property 3'])} expiry removes draft and missing from the open-state partition`, () => {
  const model = new CompletionStateModel();
  model.generate('missing');
  model.saveDraft('draft');
  model.submit('submitted');

  for (const key of model.obligations.keys()) model.lockExpired(key);

  assert.deepEqual(Object.fromEntries(model.obligations), {
    missing: 'locked_missing',
    draft: 'locked_missing',
    submitted: 'submitted',
  });
  assertProperty3(model, 'expiry', 'complete');
});

test(`${validatesCriteria(['1.4', '1.9', 'Property 3'])} production contracts store and expose one completion state`, () => {
  assert.match(migration, /completion_status VARCHAR\(24\) NOT NULL DEFAULT 'missing'/);
  assert.match(stateService, /\$completionStatus = \$corrected \? 'corrected' : \$status/);
  assert.match(stateService, /completion_status = \?, deadline_at = \?/);
  assert.match(stateService, /required_status = \? AND completion_status = \? /);
  assert.match(stateService, /AND deadline_at <= \?/);
  assert.equal((stateService.match(/\$lockMissing->execute\(\['required', '(?:missing|draft)'/g) ?? []).length, 2);
  assert.match(stateService, /if \(\$completionStatus === 'missing' && \$isWritable\)/);
  assert.match(stateService, /elseif \(\$completionStatus === 'draft' && \$isWritable\)/);
  assert.match(stateService, /elseif \(\$completionStatus === 'locked_missing' && !\$isMakeupOpen\)/);
  assert.match(stateService, /elseif \(\$completionStatus === 'locked_missing' && \$isMakeupOpen\)/);
});
