import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const source = (path) => readFileSync(new URL(path, root), 'utf8');

test('制度列表、详情和全局搜索同时执行发布与角色边界', () => {
  for (const path of [
    'api/policy/search.php',
    'api/policy/detail.php',
    'api/search/search-service.php',
  ]) {
    const php = source(path);
    assert.match(php, /publication_status\s*=\s*'published'/);
    assert.match(php, /status\s*=\s*1/);
    assert.match(php, /JSON_CONTAINS\(target_roles, JSON_QUOTE\(\?\)\)/);
  }
});

test('员工知识读取统一复用可见性组件', () => {
  for (const path of [
    'api/knowledge/KnowledgeListService.php',
    'api/knowledge/detail.php',
    'api/search/search-service.php',
    'api/lesson-submissions/LessonKnowledgeMatcher.php',
    'smart-lessons-api.php',
  ]) {
    const php = source(path);
    assert.match(php, /require_once[^;]+EmployeeKnowledgeVisibilityQuery\.php/);
    assert.match(php, /EmployeeKnowledgeVisibilityQuery::fromCurrentVersion\(\)/);
    assert.doesNotMatch(php, /JOIN knowledge_item_versions kv ON/);
  }
});

test('知识列表计数与详情相关内容使用同一可见数据源', () => {
  const list = source('api/knowledge/KnowledgeListService.php');
  const detail = source('api/knowledge/detail.php');
  assert.equal((list.match(/FROM ['"]?\s*\.\s*\$knowledgeSource/g) ?? []).length, 2);
  assert.equal((detail.match(/FROM ["']?\s*\.\s*\$knowledgeSource/g) ?? []).length, 2);
});

test('知识详情相关内容返回当前版本标识和当前版本字段', () => {
  const detail = source('api/knowledge/detail.php');
  const relatedSql = detail.match(/\$relatedSql\s*=([\s\S]*?);\s*\$stmt = \$db->prepare\(\$relatedSql\)/)?.[1] ?? '';

  assert.match(relatedSql, /kv\.version_id AS version_id/);
  assert.match(relatedSql, /COALESCE\(NULLIF\(kv\.title, ''\), k\.title\) AS title/);
  assert.match(relatedSql, /COALESCE\(NULLIF\(kv\.summary, ''\), k\.summary\) AS summary/);
  assert.match(relatedSql, /COALESCE\(NULLIF\(kv\.content_type, ''\), k\.content_type\) AS content_type/);
  assert.match(relatedSql, /COALESCE\(NULLIF\(kv\.domain_code, ''\), k\.domain_code\) AS domain_code/);
  assert.match(relatedSql, /FROM ["']?\s*\.\s*\$knowledgeSource/);
});

test('知识版本创建在事务内切换当前版本并收回已发布状态', () => {
  const php = source('api/admin/services/KnowledgeOperationService.php');
  assert.match(php, /beginTransaction\(\)/);
  assert.match(php, /SET status='superseded'/);
  assert.match(php, /UPDATE knowledge_items SET current_version_id=\?, publication_status=\?/);
  assert.match(php, /if\(\$publicationStatus==='published'\) \$publicationStatus='reviewing'/);
});
