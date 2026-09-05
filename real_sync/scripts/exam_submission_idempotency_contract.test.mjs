import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const submit = read('api/exam/submit.php');
const service = read('api/exam/ExamSubmissionService.php');

test('考试提交使用平台身份、幂等键和固定请求指纹字段', () => {
  assert.match(submit, /require_once .*kernel\/bootstrap\.php/);
  assert.match(submit, /\$context = \$context->withActor\(\$auth->userId\(\), null\)/);
  assert.match(submit, /HTTP_IDEMPOTENCY_KEY/);
  assert.match(submit, /'source_exam_id'\s*=> \$sourceExamId/);
  assert.match(submit, /'selected_exam_id'\s*=> \$selectedExamId/);
  assert.match(submit, /'paper_code'\s*=> \$paperCode/);
  assert.match(submit, /'answers'\s*=> \$answers/);
  assert.match(submit, /'time_spent'\s*=> \$timeSpent/);
  assert.match(submit, /\(new PlatformIdempotencyService\(\$db\)\)->execute/);
  assert.match(submit, /'exam\.submit'/);
});

test('评分、删除草稿和插入完成记录位于幂等回调调用的服务内', () => {
  assert.match(submit, /static function \(\) use \(\$auth, \$context, \$db, \$request\)/);
  assert.match(submit, /\(new ExamSubmissionService\(\$db\)\)->submit/);
  assert.match(service, /DELETE FROM exam_records/);
  assert.match(service, /INSERT INTO exam_records/);
  assert.match(service, /question_results/);
  assert.match(service, /exam_record_id/);
  assert.doesNotMatch(submit, /jsonResponse\(/);
});

test('考试提交接口及服务通过 PHP 语法检查', () => {
  for (const file of ['api/exam/submit.php', 'api/exam/ExamSubmissionService.php']) {
    const result = spawnSync('php', ['-l', file], { encoding: 'utf8' });
    assert.equal(result.status, 0, `${file}: ${result.stdout}${result.stderr}`);
  }
});
