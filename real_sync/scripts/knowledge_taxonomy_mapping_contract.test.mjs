import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const mappingSourceUrl = new URL('../database/knowledge_taxonomy_mapping.v1.json', import.meta.url);
const packageUrl = new URL('../database/import_data/knowledge-cards-phase2.isolated-package.json', import.meta.url);

const expectedDomainMappings = {
  ace_teaching: ['professional', 'teaching'],
  child_development: ['professional', 'child_development'],
  sensory_integration: ['professional', 'sensory'],
  physical_qualities: ['professional', 'fitness'],
  course_skills: ['professional', 'action_game'],
  assessment: ['professional', 'assessment'],
  teaching_practice: ['professional', 'teaching'],
  safety_first_aid: ['professional', 'safety'],
};

function readJson(url) {
  return JSON.parse(readFileSync(url, 'utf8'));
}

test('版本化 taxonomy 数据源定义唯一激活版本和员工端双主线', () => {
  const source = readJson(mappingSourceUrl);

  assert.equal(source.schema_version, 'knowledge-taxonomy-mapping.v1');
  assert.match(source.active_mapping_version, /^taxonomy-\d{4}-\d{2}-\d{2}-v\d+$/);
  assert.ok(Array.isArray(source.versions));

  const activeVersions = source.versions.filter((version) => version.status === 'active');
  assert.equal(activeVersions.length, 1);
  assert.equal(activeVersions[0].mapping_version, source.active_mapping_version);

  const primaryCategories = activeVersions[0].primary_categories;
  assert.deepEqual(Object.keys(primaryCategories).sort(), ['professional', 'sales']);
  assert.equal(primaryCategories.professional.label, '专业知识');
  assert.equal(primaryCategories.sales.label, '销售知识');

  for (const code of ['child_development', 'fitness', 'sensory', 'action_game', 'teaching', 'assessment', 'safety', 'coach_growth', 'lesson_reference']) {
    assert.equal(typeof primaryCategories.professional.subcategories[code], 'string');
  }
  for (const code of ['reception', 'needs_analysis', 'fitness_explanation', 'trial_class', 'parent_communication', 'objection_handling', 'conversion', 'renewal', 'sales_script']) {
    assert.equal(typeof primaryCategories.sales.subcategories[code], 'string');
  }
});

test('激活版本精确覆盖导入包八个 domain code 且目标分类有效', () => {
  const source = readJson(mappingSourceUrl);
  const knowledgePackage = readJson(packageUrl);
  const activeVersion = source.versions.find((version) => version.mapping_version === source.active_mapping_version);

  assert.ok(activeVersion);
  assert.deepEqual(Object.keys(activeVersion.domain_mappings).sort(), [...knowledgePackage.domain_codes].sort());
  assert.deepEqual(Object.keys(activeVersion.domain_mappings).sort(), Object.keys(expectedDomainMappings).sort());

  for (const [domainCode, [primaryCategory, subcategoryCode]] of Object.entries(expectedDomainMappings)) {
    const mapping = activeVersion.domain_mappings[domainCode];
    assert.deepEqual(
      [mapping.primary_category, mapping.subcategory_code],
      [primaryCategory, subcategoryCode],
    );
    assert.equal(mapping.status, 'active');
    assert.equal(
      typeof activeVersion.primary_categories[primaryCategory].subcategories[subcategoryCode],
      'string',
    );
  }
});

test('激活版本定义全部导入内容类型的分类复核基线', () => {
  const source = readJson(mappingSourceUrl);
  const knowledgePackage = readJson(packageUrl);
  const activeVersion = source.versions.find((version) => version.mapping_version === source.active_mapping_version);
  const baselines = activeVersion.content_type_review_baselines;

  assert.deepEqual(Object.keys(baselines).sort(), [...knowledgePackage.card_types].sort());
  for (const [contentType, baseline] of Object.entries(baselines)) {
    assert.equal(typeof baseline.primary_category, 'string', contentType);
    assert.equal(typeof baseline.subcategory_code, 'string', contentType);
    assert.equal(
      typeof activeVersion.primary_categories[baseline.primary_category].subcategories[baseline.subcategory_code],
      'string',
      contentType,
    );
  }
});
