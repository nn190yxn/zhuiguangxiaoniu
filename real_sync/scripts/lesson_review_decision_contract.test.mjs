import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('review decisions enforce comments, advance stages, and preserve approved version', () => {
  const service = read('api/lesson-reviews/LessonReviewDecisionService.php');
  assert.match(service, /退回审核必须填写原因/);
  assert.match(service, /status = 'completed'/);
  assert.match(service, /supervisor_review/);
  assert.match(service, /approved_version_id/);
  assert.match(service, /\$nextStatus = 'approved'/);
  assert.match(service, /review_\' \. \$decision/);
  assert.match(service, /lesson_review_stage_forbidden/);
});

test('supervisor approval publishes the approved immutable version in the same transaction', () => {
  const service = read('api/lesson-reviews/LessonReviewDecisionService.php');
  const migration = read('database/migrations/202609050001_lesson_library_publication.sql');
  const transactionStart = service.indexOf('$this->pdo->beginTransaction()');
  const publicationWrite = service.indexOf("library_status = 'published'");
  const transactionCommit = service.indexOf('$this->pdo->commit()');
  const transactionRollback = service.indexOf('$this->pdo->rollBack()');

  assert.ok(transactionStart >= 0 && publicationWrite > transactionStart && transactionCommit > publicationWrite);
  assert.ok(transactionRollback > transactionCommit);
  assert.match(service, /catch \(Throwable \$error\)[\s\S]*inTransaction\(\)[\s\S]*rollBack\(\)[\s\S]*throw \$error/);
  assert.match(service, /approved_version_id = \?, library_status = 'published', library_published_at = NOW\(\), library_published_by_staff_id = \?/);
  assert.match(service, /status = 'supervisor_review'/);
  assert.match(service, /'library_status' => \$libraryStatus/);
  assert.match(migration, /ADD COLUMN library_status VARCHAR\(16\) NOT NULL DEFAULT 'hidden'/);
  assert.match(migration, /ADD COLUMN library_published_at DATETIME NULL/);
  assert.match(migration, /ADD COLUMN library_published_by_staff_id INT UNSIGNED NULL/);
  assert.match(migration, /WHERE status = 'approved'[\s\S]*approved_version_id IS NOT NULL/);
});

test('review decision endpoint enforces reviewer decision permissions', () => {
  const endpoint = read('api/lesson-reviews/decision.php');
  assert.match(endpoint, /lesson_review\.store_decide/);
  assert.match(endpoint, /lesson_review\.supervisor_decide/);
  assert.match(endpoint, /\$allowedStages/);
  assert.match(endpoint, /LessonReviewDecisionService/);
  assert.match(endpoint, /method_not_allowed/);
});
