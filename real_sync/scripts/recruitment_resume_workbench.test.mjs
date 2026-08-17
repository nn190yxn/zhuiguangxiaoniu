import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { Script } from 'node:vm';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

test('resume workbench inline scripts have valid JavaScript syntax', () => {
  const html = read('admin/recruitment-resumes.html');
  const scripts = [...html.matchAll(/<script(?:\s[^>]*)?>([\s\S]*?)<\/script>/g)]
    .map((match) => match[1])
    .filter((source) => source.trim());
  assert.ok(scripts.length > 0);
  scripts.forEach((source) => new Script(source));
});

test('resume workbench connects upload, review and export workflows', () => {
  const html = read('admin/recruitment-resumes.html');
  const endpoints = [
    'requirements.php',
    'batches.php',
    'process.php',
    'upload.php',
    'resume-duplicate.php',
    'candidates.php',
    'candidate-detail.php',
    'candidate-grade.php',
    'candidate-contact.php',
    'candidate-queue.php',
    'candidate-duplicate.php',
    'resume-preview.php',
    'export.php',
  ];
  endpoints.forEach((endpoint) => assert.ok(html.includes(endpoint), `${endpoint} should be connected`));
  assert.match(html, /Idempotency-Key/);
  assert.match(html, /FormData/);
  assert.match(html, /data-duplicate-action="skip"/);
  assert.match(html, /data-candidate-duplicate="confirm"/);
  assert.match(html, /处理当前批次待处理简历/);
  assert.match(html, /batch_resolve/);
  assert.match(html, /duplicateSelectAllBtn/);
  assert.match(html, /resume-preview\.php\?application_id=.*requestAuthHeaders\(\)/);
  assert.match(html, /URL\.createObjectURL\(await response\.blob\(\)\)/);
});

test('batch creation can resolve the latest published rule for an approved requirement', () => {
  const service = read('api/admin/recruitment/services/ResumeUploadService.php');
  assert.match(service, /latestPublishedRule\(\$requirement\)/);
  assert.match(service, /status = 'published'/);
  assert.match(service, /ORDER BY version_no DESC, id DESC/);
  assert.match(service, /assertRuleMatchesRequirement/);
});

test('sensitive workbench actions require explicit confirmation', () => {
  const html = read('admin/recruitment-resumes.html');
  assert.match(html, /确认查看完整手机号/);
  assert.match(html, /确认查看原始简历/);
  assert.match(html, /确认保存人工等级/);
  assert.match(html, /确认变更候选人所在队列/);
});

test('workbench displays readable names and hides internal requirement and batch numbers', () => {
  const html = read('admin/recruitment-resumes.html');
  assert.match(html, /item\.job_title\|\|'未命名岗位'/);
  assert.match(html, /batch\.position_name_snapshot\|\|'未命名岗位'/);
  assert.match(html, /hero\.textContent='应聘岗位：'/);
  assert.doesNotMatch(html, /item\.requirement_no\+' · '\+item\.job_title/);
  assert.doesNotMatch(html, /escapeHtml\(batch\.batch_no\)/);
  assert.doesNotMatch(html, /escapeHtml\(item\.batch_no\|\|'-'\)/);
});

test('workbench counts only processable files and exposes skipped file counts', () => {
  const service = read('api/admin/recruitment/services/ResumeUploadService.php');
  const html = read('admin/recruitment-resumes.html');
  assert.match(service, /AS pending_file_count/);
  assert.match(service, /AS skipped_file_count/);
  assert.match(html, /number\(batch\.pending_file_count\)/);
  assert.match(html, /跳过 /);
});

test('new resume documents use document-scoped extraction idempotency', () => {
  const service = read('api/admin/recruitment/services/ResumeDocumentService.php');
  assert.match(service, /\$documentId \. ':' \. \$digest \. ':extract:v2'/);
});
