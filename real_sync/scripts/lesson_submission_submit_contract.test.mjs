import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('submission service validates, freezes the current version, and creates store review task', () => {
  const service = read('api/lesson-submissions/LessonSubmissionReviewService.php');
  assert.match(service, /LessonAceRuleChecker/);
  assert.match(service, /lesson_validation_failed/);
  assert.match(service, /lesson_suggestions_pending/);
  assert.match(service, /is_submitted = 1, is_immutable = 1/);
  assert.match(service, /status = 'store_review'/);
  assert.match(service, /lesson_review_tasks/);
  assert.match(service, /reviewer_staff_id/);
  assert.match(service, /submit_for_store_review/);
});

test('submission endpoint uses submit permission and optimistic status version', () => {
  const endpoint = read('api/lesson-submissions/submit.php');
  assert.match(endpoint, /requirePermission\('lesson_submission\.submit'\)/);
  assert.match(endpoint, /LessonSubmissionReviewService/);
  assert.match(endpoint, /status_version/);
  assert.match(endpoint, /method_not_allowed/);
});

test('review task creation is registered in both transport matrices', () => {
  for (const path of ['mini-program/business-domain-matrix.json', 'cloudfunctions/api-proxy/business-domain-matrix.json']) {
    assert.match(read(path), /"method": "POST", "path": "\/lesson-submissions\/submit\.php"/);
  }
});
