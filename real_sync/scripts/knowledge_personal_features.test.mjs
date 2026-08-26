import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const read = (relative) => readFileSync(path.join(repoRoot, relative), 'utf8');

test('knowledge progress keeps history readable but disables writes and rewards', () => {
  const progress = read('real_sync/api/knowledge/progress.php');
  assert.match(progress, /\$userId = \(int\)getCurrentUserId\(\)/);
  assert.match(progress, /if \(\$userId <= 0\)/);
  assert.match(progress, /\$method === 'POST' \|\| \$method === 'PUT' \|\| \$method === 'PATCH'/);
  assert.match(progress, /知识库学习完成与进度写入已停用/);
  assert.match(progress, /LEFT JOIN knowledge_items k/);
  assert.match(progress, /k\.status = 1 AND k\.publication_status = 'published'/);
  assert.doesNotMatch(progress, /awardPoints\(/);
  assert.doesNotMatch(progress, /INSERT INTO user_knowledge_progress/);
});

test('favorite API is authenticated, published-only and idempotent', () => {
  const favorite = read('real_sync/api/knowledge/favorite.php');
  assert.match(favorite, /\$userId = \(int\)getCurrentUserId\(\)/);
  assert.match(favorite, /status = 1 AND publication_status = 'published'/);
  assert.match(favorite, /INSERT INTO knowledge_favorites/);
  assert.match(favorite, /DELETE FROM knowledge_favorites/);
  assert.match(favorite, /23000/);
  assert.match(favorite, /driverCode/);
  assert.match(favorite, /1062/);
  assert.doesNotMatch(favorite, /\$_GET\[['"]user_id['"]\]/);
});

test('recent views use a unique-key upsert and preserve hidden history without metadata', () => {
  const recent = read('real_sync/api/knowledge/recent-views.php');
  const detail = read('real_sync/api/knowledge/detail.php');
  assert.match(recent, /\$userId = \(int\)getCurrentUserId\(\)/);
  assert.match(recent, /LEFT JOIN knowledge_items k/);
  assert.match(recent, /k\.status = 1 AND k\.publication_status = 'published'/);
  assert.match(recent, /array_merge\(\$_GET, getRequestInput\(\)\)/);
  assert.match(recent, /ON DUPLICATE KEY UPDATE/);
  assert.match(recent, /view_count = view_count \+ 1/);
  assert.match(detail, /INSERT INTO knowledge_recent_views/);
  assert.match(detail, /recent_view/);
});

test('knowledge list and detail expose current-user favorite state', () => {
  const service = read('real_sync/api/knowledge/KnowledgeListService.php');
  const detail = read('real_sync/api/knowledge/detail.php');
  assert.match(service, /knowledge_favorites f/);
  assert.match(service, /AS is_favorite/);
  assert.match(service, /\$userId, \$userId, \$userId/);
  assert.match(detail, /knowledge_favorites f/);
  assert.match(detail, /'is_favorite'/);
});
