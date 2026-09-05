import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('review query lists assigned tasks and exposes review evidence', () => {
  const service = read('api/lesson-reviews/LessonReviewQueryService.php');
  assert.match(service, /reviewer_staff_id = \?/);
  assert.match(service, /source_files/);
  assert.match(service, /suggestions/);
  assert.match(service, /versions/);
  assert.match(service, /exports/);
  assert.match(service, /is_immutable/);
});

test('review list endpoint enforces reviewer scope permissions', () => {
  const endpoint = read('api/lesson-reviews/list.php');
  assert.match(endpoint, /lesson_submission\.view_store/);
  assert.match(endpoint, /lesson_submission\.view_review_scope/);
  assert.match(endpoint, /LessonReviewQueryService/);
  assert.match(endpoint, /method_not_allowed/);
});
