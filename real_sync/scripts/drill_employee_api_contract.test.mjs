import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = (path) => readFileSync(new URL(path, import.meta.url), 'utf8');
const endpoints = ['home.php', 'catalog.php', 'assignments.php', 'attempts.php', 'turns.php', 'attempt-status.php', 'results.php', 'progress.php', 'learning.php'];

test('employee v2 endpoints use the common authenticated response contract', () => {
  for (const endpoint of endpoints) {
    const body = source(`../api/drill/v2/${endpoint}`);
    assert.match(body, /drillV2Bootstrap\(/, endpoint);
    assert.match(body, /drillV2Success\(/, endpoint);
  }
});

test('write endpoints use idempotency and expose asynchronous polling resources', () => {
  for (const endpoint of ['attempts.php', 'turns.php', 'learning.php']) {
    assert.match(source(`../api/drill/v2/${endpoint}`), /drillV2RunIdempotent\(/, endpoint);
  }
  for (const endpoint of ['attempts.php', 'turns.php', 'turns/finalize.php']) {
    assert.match(source(`../api/drill/v2/${endpoint}`), /attempt-status\.php/, endpoint);
  }
  assert.match(source('../api/drill/v2/turns/finalize.php'), /202/);
});

test('employee query service scopes assignments, attempts, results and learning to staff identity', () => {
  const body = source('../api/drill/v2/services/DrillEmployeeApiService.php');
  assert.match(body, /assignment\.staff_id = \?/);
  assert.match(body, /attempt\.staff_id = \?/);
  assert.match(body, /WHERE staff_id = \?/);
  assert.match(body, /recommendation\.staff_id = \?/);
});

test('attempt creation captures participants, score subject and recording authorization metadata', () => {
  const body = source('../api/drill/v2/attempts.php');
  assert.match(body, /drill_attempt_participants/);
  assert.match(body, /drill_attempt_score_subjects/);
  assert.match(body, /recording_authorization/);
  assert.match(body, /createFromAssignment/);
  assert.match(body, /createSelfPractice/);
  assert.match(source('../api/drill/v2/services/DrillConversationService.php'), /createSelfPractice/);
});

test('语音最终转写会提交服务端生成的客户回应', () => {
  const body = source('../api/drill/v2/turns/finalize.php');
  assert.match(body, /submitTextTurnWithGeneratedCustomer/);
  assert.match(body, /status_version/);
  assert.match(body, /DrillAiRetryableException/);
});
