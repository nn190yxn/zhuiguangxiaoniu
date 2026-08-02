import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

test('candidate queries apply requirement scope and stable grade ordering', () => {
  const service = read('api/admin/recruitment/services/ResumeReviewService.php');
  assert.match(service, /requirementWhereClause\(\$scope, 'requirement'\)/);
  assert.match(service, /CASE application\.effective_grade WHEN 'A' THEN 1 WHEN 'B' THEN 2 WHEN 'C' THEN 3 ELSE 4 END/);
  assert.match(service, /page_size/);
  assert.match(service, /unset\(\$row\['profile'\]\[\$field\]\['protected'\]\)/);
});

test('system and manual grades remain separate with review history', () => {
  const service = read('api/admin/recruitment/services/ResumeReviewService.php');
  assert.match(service, /INSERT INTO recruitment_grade_reviews/);
  assert.match(service, /system_grade, manual_grade, review_reason/);
  assert.match(service, /SET manual_grade = \?, effective_grade = \?/);
  assert.match(service, /C 级候选人需先完成等级复核/);
});

test('phone, original resume, contact and queue actions are independently audited', () => {
  const contact = read('api/admin/recruitment/candidate-contact.php');
  const preview = read('api/admin/recruitment/resume-preview.php');
  const privateStorage = read('api/platform/PrivateFileStorage.php');
  const queue = read('api/admin/recruitment/candidate-queue.php');
  assert.match(contact, /recruitment\.resume_phone_view/);
  assert.match(contact, /resume\.phone\.reveal/);
  assert.match(contact, /resume\.contact\.record/);
  assert.match(preview, /recruitment\.resume_original_view/);
  assert.match(preview, /resume\.original\.view/);
  assert.match(preview, /RecruitmentPlatformFileAdapter/);
  assert.match(privateStorage, /Cache-Control: private, no-store/);
  assert.match(queue, /resume\.queue\./);
});

test('duplicate decisions preserve immutable relation evidence', () => {
  const service = read('api/admin/recruitment/services/ResumeReviewService.php');
  assert.match(service, /confirmed_duplicate/);
  assert.match(service, /released/);
  assert.match(service, /before_snapshot_json/);
  assert.match(service, /record_status = 'merged'/);
});

test('manual retry resumes a version while reprocess supersedes it', () => {
  const processing = read('api/admin/recruitment/services/ResumeProcessingService.php');
  const endpoint = read('api/admin/recruitment/batches.php');
  assert.match(processing, /public function retry/);
  assert.match(processing, /max_attempts = \?/);
  assert.match(processing, /status = 'superseded'/);
  assert.match(processing, /status = 'cancelled'/);
  assert.match(endpoint, /retry_document/);
  assert.match(endpoint, /reprocess_document/);
});
