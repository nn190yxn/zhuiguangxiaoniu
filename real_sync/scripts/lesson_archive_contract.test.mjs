import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('[validates 7.3] 归档事务移出活跃发现并保留历史引用', () => {
  const service = read('api/lesson-library/LessonArchiveService.php');

  assert.match(service, /beginTransaction\(\)/);
  assert.match(service, /status = 'archived', library_status = 'archived', status_version = status_version \+ 1/);
  assert.match(service, /approved_version_id = \?/);
  assert.match(service, /status = 'approved' AND library_status = 'published'/);
  assert.match(service, /is_submitted = 1 AND is_immutable = 1/);
  assert.match(service, /lesson_archived/);
  assert.match(service, /'approved',\s*'archived'/);
  assert.match(service, /\$this->pdo->commit\(\)/);
  assert.match(service, /inTransaction\(\)[\s\S]*rollBack\(\)/);
  assert.doesNotMatch(service, /DELETE\s+FROM\s+lesson_(?:versions|review_tasks|exports|audit_logs)/i);
  assert.doesNotMatch(service, /approved_version_id\s*=\s*NULL/i);
  assert.doesNotMatch(service, /library_published_at\s*=\s*NULL/i);
});

test('归档接口固定主管权限、状态版本和平台响应契约', () => {
  const endpoint = read('api/lesson-library/archive.php');

  assert.match(endpoint, /\$_SERVER\['REQUEST_METHOD'\] !== 'POST'/);
  assert.match(endpoint, /requirePermission\('lesson_review\.supervisor_decide'\)/);
  assert.match(endpoint, /\$input\['status_version'\]/);
  assert.match(endpoint, /platformRequireMigrationReadiness\(\$database, \['202609050001'\]\)/);
  assert.match(endpoint, /LessonArchiveService/);
  assert.match(endpoint, /PlatformBusinessDomainRegistry::get\('lesson_review'\)/);
  assert.match(endpoint, /PlatformApiCompatibility::withMetadata/);
  assert.match(endpoint, /platformApiResponse\(\$context, \$result, '教案已归档'\)->send\(\)/);
});
