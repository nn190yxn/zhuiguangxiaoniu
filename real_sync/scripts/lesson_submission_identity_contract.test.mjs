import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('[validates 7.4] 教案提交页使用共享身份适配器固定作者显示', () => {
  const html = read('lesson-submission.html');
  const script = read('js/lesson-submission.js');

  assert.match(html, /<label for="createAuthor">作者<\/label><input id="createAuthor" readonly aria-readonly="true"/);
  assert.match(script, /window\.InternalAuth\.adaptUserIdentity\(user\)/);
  assert.match(script, /state\.authenticatedAuthorName = identity\.name/);
  assert.match(script, /\$\('createAuthor'\)\.value = identity\.name/);
  assert.match(script, /author_name: state\.authenticatedAuthorName/);
  assert.doesNotMatch(script, /author_name: \$\('createAuthor'\)\.value/);
});

test('[validates 7.4] 教案 ownership 由服务端认证 staff identity 绑定', () => {
  const endpoint = read('api/lesson-submissions/create.php');
  const service = read('api/lesson-submissions/LessonSubmissionService.php');

  assert.match(endpoint, /\$staffId = \(int\) \$auth->staffId\(\)/);
  assert.match(endpoint, /createWithinTransaction\(\$input, \$staffId\)/);
  assert.match(service, /\(store_id, store_name, author_staff_id, author_name, course_line, class_level, lesson_date, title, status, created_by\)/);
  assert.match(service, /\$metadata\['store_name'\],\s*\$actorStaffId,\s*\$metadata\['author_name'\]/);
  assert.match(service, /\$metadata\['title'\],\s*\$actorStaffId/);
});
