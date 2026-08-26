import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (relative) => readFileSync(path.join(repoRoot, relative), 'utf8');

test('knowledge access boundary covers anonymous, isolated and offline paths', () => {
  const category = read('real_sync/api/knowledge/category.php');
  const categories = read('real_sync/api/knowledge/categories.php');
  const detail = read('real_sync/api/knowledge/detail.php');
  const drillDetail = read('real_sync/api/drill/detail.php');
  const drillList = read('real_sync/api/drill/list.php');
  const stage = read('real_sync/api/stage.php');
  const passStage = read('real_sync/api/pass/stage.php');
  const passMap = read('real_sync/api/pass/map.php');
  const globalSearch = read('real_sync/api/search/search-service.php');

  assert.match(category, /getCurrentUserId\(\)/);
  assert.match(category, /if \(\$userId <= 0\)/);
  assert.match(categories, /k\.status = 1/);
  assert.match(categories, /k\.publication_status = 'published'/);
  assert.doesNotMatch(categories, /\$_GET\[['"](?:role|stage)['"]\]/);

  assert.match(detail, /\$userId = \(int\)getCurrentUserId\(\)/);
  assert.match(detail, /k\.publication_status = 'published'/);
  assert.match(detail, /linked_k\.publication_status = 'published'/);
  assert.match(detail, /dt\.status = 1/);
  assert.match(detail, /dt\.role IS NULL/);
  assert.match(detail, /dt\.stage IS NULL/);

  assert.match(drillDetail, /\$userId = \(int\)getCurrentUserId\(\)/);
  assert.match(drillDetail, /status = 1 AND publication_status = 'published'/);
  assert.match(drillDetail, /\$roleAllowed/);
  assert.match(drillDetail, /\$stageAllowed/);
  assert.match(drillDetail, /if \(!\$template\['knowledge_card_id'\] \|\| \$knowledgeCard\)/);
  assert.match(drillList, /\$userId = \(int\)getCurrentUserId\(\)/);
  assert.doesNotMatch(drillList, /\$_GET\[['"](?:role|stage)['"]\]/);
  assert.match(drillList, /t\.role IS NULL/);
  assert.match(drillList, /t\.stage IS NULL/);
  assert.match(drillList, /status = 1 AND publication_status = 'published'/);
  assert.match(stage, /\$userId = \(int\)getCurrentUserId\(\)/);
  assert.doesNotMatch(stage, /\$_GET\[['"]role['"]\]/);
  assert.match(stage, /status = 1 AND publication_status = 'published'/);
  assert.match(passStage, /\$userId = \(int\)getCurrentUserId\(\)/);
  assert.doesNotMatch(passStage, /\$_GET\[['"]role['"]\]/);
  assert.match(passStage, /status = 1 AND publication_status = 'published'/);
  assert.match(passMap, /\$userId = \(int\)getCurrentUserId\(\)/);
  assert.doesNotMatch(passMap, /\$_GET\[['"]role['"]\]/);
  assert.match(passMap, /publication_status = 'published'/);
  assert.match(globalSearch, /k\.status = 1 AND k\.publication_status = 'published'/);
});

test('knowledge detail preserves legacy response keys while adding published-only related resources', () => {
  const detail = read('real_sync/api/knowledge/detail.php');
  for (const key of ['item', 'progress', 'related', 'drills', 'scripts']) {
    assert.match(detail, new RegExp("'" + key + "'\\s*=>"));
  }
  assert.match(detail, /JOIN drill_templates dt/);
  assert.match(detail, /JOIN knowledge_items linked_k/);
});
