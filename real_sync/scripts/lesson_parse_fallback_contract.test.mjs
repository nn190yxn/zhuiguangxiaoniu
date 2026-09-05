import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('解析失败回退保留原文件、失败运行、手工模板和审计链路', () => {
  const service = read('api/lesson-submissions/LessonSubmissionService.php');
  assert.match(service, /INSERT INTO lesson_parse_runs/);
  assert.match(service, /failed/);
  assert.match(service, /version_type.*manual_template/);
  assert.match(service, /status = 'parse_failed'/);
  assert.match(service, /manual_entry_available/);
  assert.match(service, /status = 'editable'/);
  assert.match(service, /'manual_entry'/);
  assert.match(service, /mb_substr\(\$message, 0, 2000/);
});

test('手工录入接口复用认证、权限和统一响应契约', () => {
  const endpoint = read('api/lesson-submissions/manual-entry.php');
  assert.match(endpoint, /kernel\/bootstrap\.php/);
  assert.match(endpoint, /platformApiAuthContext\(\)/);
  assert.match(endpoint, /requirePermission\('lesson_submission\.create'\)/);
  assert.match(endpoint, /beginManualEntry/);
  assert.match(endpoint, /PlatformApiCompatibility::withMetadata\(/);
  assert.match(endpoint, /platformApiResponse\(/);
});

test('空模板结构保持 ACE 教案统一字段', () => {
  const php = String.raw`require 'api/lesson-submissions/LessonSubmissionService.php'; echo json_encode(LessonSubmissionService::emptyLessonContent(['title' => '手工教案']), JSON_UNESCAPED_UNICODE);`;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const content = JSON.parse(result.stdout);
  assert.deepEqual(Object.keys(content.objectives), ['athletic', 'cognitive', 'engagement']);
  assert.deepEqual(Object.keys(content.safety), ['physical', 'psychological']);
  assert.deepEqual(Object.keys(content.reflection), ['athletic', 'cognitive', 'engagement']);
  assert.deepEqual(content.equipment, []);
  assert.deepEqual(content.phases, []);
});
