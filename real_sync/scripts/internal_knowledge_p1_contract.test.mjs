import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

test('学习章节 GET 纯读取，POST 才执行完成写入', () => {
  const controller = read('api/learning/lesson.php');
  const service = read('api/learning/LearningLessonService.php');
  assert.match(controller, /\$service->read\(/);
  assert.match(controller, /\$service->complete\(/);
  assert.doesNotMatch(controller, /readAndComplete/);
  assert.match(service, /public function read\(/);
  assert.match(service, /public function complete\(/);
  assert.match(service, /Idempotency-Key|idempotency_key_hash/);
  assert.match(service, /PlatformStateVersion::assertExpected/);
});

test('规范中心提供业务入口且旧入口保留查询参数', () => {
  const knowledgeCenter = read('knowledge/index.html');
  const learningCenter = read('learning/index.html');
  assert.match(knowledgeCenter, /id="knowledgeList"/);
  assert.match(knowledgeCenter, /\/knowledge\/knowledge\.js/);
  assert.match(learningCenter, /href="\/mobile\/learning\.html"/);
  assert.match(learningCenter, /href="\/lesson-submission\.html"/);
  assert.match(learningCenter, /href="\/lesson-review\.html"/);
  assert.match(read('知识库/index.html'), /\/knowledge\/.*window\.location\.search/);
  assert.match(read('新员工学习/index.html'), /\/learning\/.*window\.location\.search/);
});

test('统一搜索结果具有来源、规范路径和版本字段', () => {
  const search = read('api/search/search-service.php');
  assert.match(search, /unified_content_index/);
  assert.match(search, /searchStaticLessons/);
  assert.match(search, /source_type/);
  assert.match(search, /canonical_url/);
  assert.match(search, /version_id/);
});
