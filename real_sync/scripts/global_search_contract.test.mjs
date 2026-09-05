import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const service = readFileSync(path.join(root, 'api/search/search-service.php'), 'utf8');
const endpoint = readFileSync(path.join(root, 'api/search/global.php'), 'utf8');
const adminEndpoint = readFileSync(path.join(root, 'api/admin/search/no-results.php'), 'utf8');
const migration = readFileSync(path.join(root, 'database/migrations/202609040004_search_query_logs.sql'), 'utf8');
const manifest = readFileSync(path.join(root, 'database/migration_manifest.php'), 'utf8');

test('global search expands business synonyms and normalizes result contracts', () => {
  for (const term of ['课程', '教案', '动作', '游戏', '安全', '续费']) assert.ok(service.includes(`'${term}'`));
  for (const field of ['center', 'category', 'content_type', 'canonical_url', 'matched_fields', 'version_id', 'source_type', 'source_path']) {
    assert.ok(service.includes(`$item['${field}']`), `missing normalized field ${field}`);
  }
  assert.ok(service.includes('array_merge($results[\'knowledge\'] ?? [], $knowledgeResults)'));
});

test('no-result logging is best effort and migration is registered', () => {
  assert.ok(endpoint.includes('searchRecordNoResult'));
  assert.ok(service.includes('INSERT INTO search_query_logs'));
  assert.ok(migration.includes('CREATE TABLE IF NOT EXISTS search_query_logs'));
  assert.ok(manifest.includes("'202609040004'"));
  assert.ok(adminEndpoint.includes('search_query_logs'));
  assert.ok(adminEndpoint.includes("['admin', 'ceo', 'operation', 'manager']"));
});

test('search result URLs use registered canonical paths', () => {
  for (const route of ['/mobile/knowledge-detail.html', '/doc-viewer.html', '/lessons/', '/training-module.html', '/training-card.html']) {
    assert.ok(service.includes(route), `missing canonical route ${route}`);
  }
});

test('knowledge search returns the shared current knowledge version', () => {
  assert.match(service, /EmployeeKnowledgeVisibilityQuery::fromCurrentVersion\(\)/);
  assert.match(service, /SELECT k\.id, kv\.version_id/);
  assert.match(service, /'version_id' => \(int\)\$item\['version_id'\]/);
});
