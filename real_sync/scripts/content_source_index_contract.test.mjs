import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const index = JSON.parse(readFileSync(new URL('../content-index.json', import.meta.url), 'utf8'));
const searchService = readFileSync(new URL('../api/search/search-service.php', import.meta.url), 'utf8');
const matcher = readFileSync(new URL('../api/lesson-submissions/LessonKnowledgeMatcher.php', import.meta.url), 'utf8');
const listService = readFileSync(new URL('../api/knowledge/KnowledgeListService.php', import.meta.url), 'utf8');
const detail = readFileSync(new URL('../api/knowledge/detail.php', import.meta.url), 'utf8');

test('静态来源索引覆盖动作、培训卡片、培训资料、教案和体测', () => {
  const types = new Set(index.map((item) => item.content_type));
  for (const type of ['action', 'knowledge_card', 'training', 'lesson', 'fitness_guidance']) assert.ok(types.has(type), type);
  for (const item of index) {
    assert.match(item.stable_key, /^[a-z0-9-]+$/);
    assert.ok(item.source_type && item.source_path && item.canonical_url);
    assert.ok(['professional', 'sales'].includes(item.primary_category));
    assert.equal(item.publication_status, 'published');
  }
});

test('搜索消费静态来源索引，教案建议绑定 active 知识卡版本', () => {
  assert.match(searchService, /searchStaticContentIndex/);
  assert.match(matcher, /EmployeeKnowledgeVisibilityQuery::fromCurrentVersion\(\)/);
  assert.match(matcher, /knowledge_version_id/);
  assert.match(listService, /kv\.version_id AS version_id/);
  assert.match(detail, /kv\.version_id AS version_id/);
});
