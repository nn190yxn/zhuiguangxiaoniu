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
