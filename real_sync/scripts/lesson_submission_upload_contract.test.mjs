import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import test from 'node:test';

const root = new URL('..', import.meta.url);
const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');

test('教案创建与上传入口使用统一认证、权限和响应契约', () => {
  const create = read('api/lesson-submissions/create.php');
  const upload = read('api/lesson-submissions/upload.php');
  for (const source of [create, upload]) {
    assert.match(source, /kernel\/bootstrap\.php/);
    assert.match(source, /platformApiAuthContext\(\)/);
    assert.match(source, /requirePermission\('lesson_submission\.create'\)/);
    assert.match(source, /PlatformApiCompatibility::withMetadata\(/);
    assert.match(source, /platformApiResponse\(/);
  }
  assert.match(upload, /\$_FILES\['file'\]/);
  assert.match(upload, /submission_id/);
});

test('教案创建按员工作用域接入统一幂等执行器', () => {
  const endpoint = read('api/lesson-submissions/create.php');
  const service = read('api/lesson-submissions/LessonSubmissionService.php');
  assert.match(endpoint, /HTTP_IDEMPOTENCY_KEY/);
  assert.match(endpoint, /new PlatformIdempotencyService\(\$db\)/);
  assert.match(endpoint, /'lesson_submission\.create'/);
  assert.match(endpoint, /'staff:' \. \$staffId/);
  assert.match(endpoint, /createWithinTransaction\(\$input, \$staffId\)/);
  assert.match(endpoint, /return platformApiResponse\(\$context, \$result\)/);
  assert.match(service, /public function create\(array \$input, int \$actorStaffId\): array/);
  assert.match(service, /public function createWithinTransaction\(array \$input, int \$actorStaffId\): array/);
  const neutralBody = service.match(/public function createWithinTransaction[\s\S]*?\n    }\n\n    public function upload/)?.[0] ?? '';
  assert.doesNotMatch(neutralBody, /beginTransaction|commit\(|rollBack\(/);
});

test('教案元数据校验覆盖必填字段、日期和长度边界', () => {
  const php = String.raw`
    require 'api/lesson-submissions/LessonSubmissionService.php';
    $valid = [
      'store_name' => '贵阳门店', 'author_name' => '教练甲', 'course_line' => '体适能',
      'class_level' => 'L2', 'lesson_date' => '2026-09-03', 'title' => '基础跳跃训练'
    ];
    $result = LessonSubmissionService::validateMetadata($valid);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    foreach ([[], array_merge($valid, ['lesson_date' => '2026-02-30']), array_merge($valid, ['title' => str_repeat('x', 256)])] as $case) {
      try { LessonSubmissionService::validateMetadata($case); echo "|unexpected"; }
      catch (Throwable $error) { echo '|rejected'; }
    }
  `;
  const result = spawnSync('php', ['-r', php], { cwd: root, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.match(result.stdout, /贵阳门店/);
  assert.equal((result.stdout.match(/\|rejected/g) || []).length, 3);
  assert.doesNotMatch(result.stdout, /unexpected/);
});

test('教案服务限制 Office 扩展名和 50MB 文件上限', () => {
  const source = read('api/lesson-submissions/LessonSubmissionService.php');
  const storage = read('api/platform/PrivateFileStorage.php');
  assert.match(source, /'xlsx'/);
  assert.match(source, /'xls'/);
  assert.match(source, /'docx'/);
  assert.match(source, /'doc'/);
  assert.match(source, /50 \* 1024 \* 1024/);
  assert.match(source, /allowed_mime_types/);
  assert.match(storage, /file_actual_mime_not_allowed/);
  assert.match(storage, /file_declared_mime_mismatch/);
  assert.match(storage, /bin2hex|random_bytes/);
});
