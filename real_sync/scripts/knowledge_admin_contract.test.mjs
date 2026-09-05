import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const service = readFileSync(path.join(root, 'api/admin/services/KnowledgeOperationService.php'), 'utf8');
const endpoint = readFileSync(path.join(root, 'api/admin/knowledge/index.php'), 'utf8');

test('knowledge admin exposes catalog filters, relations, versions, publishing and audit', () => {
  for (const action of ['items', 'relations', 'versions', 'audit', 'create_relation', 'review_relation', 'create_version', 'publish', 'unpublish', 'rollback']) assert.ok(endpoint.includes(`'${action}'`));
  for (const field of ['publication_status', 'content_type', 'domain_code', 'current_version_id', 'source_batch_id']) assert.ok(service.includes(field));
  assert.ok(service.includes('INSERT INTO knowledge_item_relations'));
  assert.ok(service.includes('recordAudit'));
});
