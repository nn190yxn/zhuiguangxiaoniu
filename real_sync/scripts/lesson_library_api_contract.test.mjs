import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('[validates 7.2] 正式教案列表只发现已发布的批准不可变版本', () => {
  const service = read('api/lesson-library/LessonLibraryQueryService.php');

  assert.match(service, /submission\.status = 'approved'/);
  assert.match(service, /submission\.library_status = 'published'/);
  assert.match(service, /submission\.approved_version_id IS NOT NULL/);
  assert.match(service, /version\.id = submission\.approved_version_id AND version\.submission_id = submission\.id/);
  assert.match(service, /version\.is_submitted = 1 AND version\.is_immutable = 1/);
  assert.doesNotMatch(service, /current_version_id/);
  assert.match(service, /ORDER BY submission\.library_published_at DESC, submission\.id DESC LIMIT \? OFFSET \?/);
  for (const field of ['list', 'total', 'page', 'page_size', 'filters']) {
    assert.match(service, new RegExp(`'${field}'\\s*=>`));
  }
});

test('[validates 7.2, 7.5] 正式教案详情返回唯一批准版本和稳定规范路由', () => {
  const service = read('api/lesson-library/LessonLibraryQueryService.php');

  assert.match(service, /WHERE submission\.id = \? AND ' \. self::VISIBILITY_SQL \. ' LIMIT 1'/);
  assert.match(service, /lesson_library_item_not_found/);
  assert.match(service, /'approved_version' => \$version/);
  assert.match(service, /'id' => \(int\) \$row\['version_id'\]/);
  assert.match(service, /'canonical_route' => '\/lesson-library\.html\?id=' \. \$submissionId/);
  assert.match(service, /lesson_library_version_invalid/);
});

test('正式教案端点使用统一认证、数据库就绪和平台响应契约', () => {
  for (const endpointPath of ['api/lesson-library/list.php', 'api/lesson-library/detail.php']) {
    const endpoint = read(endpointPath);
    assert.match(endpoint, /\$_SERVER\['REQUEST_METHOD'\] !== 'GET'/);
    assert.match(endpoint, /platformApiAuthContext\(\)/);
    assert.match(endpoint, /requireAuthenticated\(\)/);
    assert.match(endpoint, /platformRequireMigrationReadiness\(\$database, \['202609050001'\]\)/);
    assert.match(endpoint, /PlatformBusinessDomainRegistry::get\('lesson_review'\)/);
    assert.match(endpoint, /PlatformApiCompatibility::withMetadata/);
    assert.match(endpoint, /platformApiResponse\(\$context, \$result\)->send\(\)/);
  }
});
