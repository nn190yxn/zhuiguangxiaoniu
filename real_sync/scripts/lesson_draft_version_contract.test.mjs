import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('草稿服务实现版本递增、修改摘要、状态版本冲突和审核锁定', () => {
  const source = read('api/lesson-submissions/LessonDraftService.php');
  assert.match(source, /SELECT COALESCE\(MAX\(version_no\)/);
  assert.match(source, /changed_fields_json/);
  assert.match(source, /status_version = status_version \+ 1/);
  assert.match(source, /lesson_submission_conflict/);
  assert.match(source, /lesson_submission_locked/);
  assert.match(source, /version_type.*draft/);
  assert.match(source, /draft_save/);
});

test('教案详情和草稿接口使用统一认证、权限与响应契约', () => {
  for (const path of ['api/lesson-submissions/detail.php', 'api/lesson-submissions/draft.php']) {
    const source = read(path);
    assert.match(source, /kernel\/bootstrap\.php/);
    assert.match(source, /platformApiAuthContext\(\)/);
    assert.match(source, /requirePermission\('lesson_submission\.create'\)/);
    assert.match(source, /PlatformApiCompatibility::withMetadata\(/);
    assert.match(source, /platformApiResponse\(/);
  }
});
