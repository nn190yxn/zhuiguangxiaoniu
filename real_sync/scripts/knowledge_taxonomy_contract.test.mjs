import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { test } from 'node:test';

const taxonomy = readFileSync(new URL('../api/knowledge/KnowledgeTaxonomy.php', import.meta.url), 'utf8');
const listService = readFileSync(new URL('../api/knowledge/KnowledgeListService.php', import.meta.url), 'utf8');
const categoriesApi = readFileSync(new URL('../api/knowledge/categories.php', import.meta.url), 'utf8');
const page = readFileSync(new URL('../mobile/knowledge.html', import.meta.url), 'utf8');

test('知识中心定义专业与销售双主线及稳定子分类', () => {
  assert.match(taxonomy, /knowledge_taxonomy_mapping\.v1\.json/);
  const result = spawnSync('php', ['-r', `require ${JSON.stringify(new URL('../api/knowledge/KnowledgeTaxonomy.php', import.meta.url).pathname)}; echo json_encode(['version' => KnowledgeTaxonomy::mappingVersion(), 'categories' => KnowledgeTaxonomy::primaryCategories(), 'mapped' => KnowledgeTaxonomy::classify(['domain_code' => 'course_skills', 'content_type' => 'script'])], JSON_UNESCAPED_UNICODE);`], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  const payload = JSON.parse(result.stdout);
  assert.equal(payload.version, 'taxonomy-2026-09-04-v1');
  assert.deepEqual(Object.keys(payload.categories), ['professional', 'sales']);
  assert.equal(payload.categories.professional.label, '专业知识');
  assert.equal(payload.categories.sales.label, '销售知识');
  assert.deepEqual([payload.mapped.primary_category, payload.mapped.subcategory_code], ['professional', 'action_game']);
  assert.equal(payload.mapped.taxonomy_mapping_version, payload.version);
  for (const code of ['child_development', 'fitness', 'sensory', 'action_game', 'teaching', 'assessment', 'safety', 'coach_growth', 'lesson_reference', 'reception', 'needs_analysis', 'fitness_explanation', 'trial_class', 'parent_communication', 'objection_handling', 'conversion', 'renewal', 'sales_script']) {
    assert.ok(Object.values(payload.categories).some((category) => code in category.subcategories));
  }
});

test('知识列表、分类接口和员工页面使用同一分类映射', () => {
  assert.match(listService, /KnowledgeTaxonomy::classify/);
  assert.match(listService, /KnowledgeTaxonomy::classificationSql/);
  assert.match(listService, /taxonomy_mapping_version/);
  assert.match(categoriesApi, /primary_categories/);
  assert.match(categoriesApi, /KnowledgeTaxonomy::primaryCategories/);
  assert.match(categoriesApi, /taxonomy_mapping_version/);
  assert.match(page, /data-primary-category="professional"/);
  assert.match(page, /data-primary-category="sales"/);
  assert.match(page, /primary_category: FILTERS\.primaryCategory/);
});

test('知识列表主线筛选优先采用版本化领域集合并兼容旧销售内容', () => {
  const servicePath = new URL('../api/knowledge/KnowledgeListService.php', import.meta.url).pathname;
  const php = `
    require ${JSON.stringify(servicePath)};
    $db = new PDO('sqlite::memory:');
    $sql = KnowledgeTaxonomy::classificationSql('domain_code', 'content_type', 'title');
    $db->exec("CREATE TABLE sample (domain_code TEXT, content_type TEXT, title TEXT)");
    $db->exec("INSERT INTO sample VALUES ('course_skills', 'script', '销售'), ('sales', 'knowledge_card', '接待'), ('', 'game', '动作')");
    echo json_encode($db->query('SELECT (' . $sql['primary_category'] . ') AS primary_category, (' . $sql['subcategory_code'] . ') AS subcategory_code FROM sample')->fetchAll(PDO::FETCH_ASSOC));
  `;
  const result = spawnSync('php', ['-r', php], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  assert.deepEqual(JSON.parse(result.stdout), [
    { primary_category: 'professional', subcategory_code: 'action_game' },
    { primary_category: 'sales', subcategory_code: 'reception' },
    { primary_category: 'professional', subcategory_code: 'action_game' },
  ]);
});
