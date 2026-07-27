import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const h5 = readFileSync(new URL('../mobile/workload-v2.html', import.meta.url), 'utf8');
const miniJs = readFileSync(new URL('../mini-program/pages/workload/index.js', import.meta.url), 'utf8');

function createMutex() {
  let current = '';
  let accepted = 0;
  return {
    begin(action) {
      if (current) return false;
      current = action;
      accepted += 1;
      return true;
    },
    end() { current = ''; },
    accepted() { return accepted; }
  };
}

test('property 28: repeated triggers produce at most one active business mutation', () => {
  const actions = ['saving', 'uploading', 'submitting'];
  for (let run = 0; run < 500; run += 1) {
    const mutex = createMutex();
    const repeated = 2 + (run % 20);
    const action = actions[run % actions.length];
    const results = Array.from({ length: repeated }, () => mutex.begin(action));
    assert.equal(results.filter(Boolean).length, 1);
    assert.equal(mutex.accepted(), 1);
    mutex.end();
    assert.equal(mutex.begin(actions[(run + 1) % actions.length]), true);
  }
});

test('property 28: H5 and mini program guard every mutation before transport', () => {
  assert.match(h5, /function beginOperation\(name\)\{if\(operationState\)return false/);
  assert.match(h5, /saveReport[\s\S]*beginOperation\(status==='submitted'\?'submitting':'saving'\)/);
  assert.match(h5, /onEvidenceSelected[\s\S]*beginOperation\('uploading'\)/);
  assert.match(miniJs, /beginOperation\(action\)[\s\S]*if \(this\.data\.busyAction/);
  assert.match(miniJs, /saveReport\(submitStatus[\s\S]*beginOperation\(submitStatus === 'submitted' \? 'submitting' : 'saving'\)/);
  assert.match(miniJs, /chooseEvidence[\s\S]*beginOperation\('uploading'\)/);
});
