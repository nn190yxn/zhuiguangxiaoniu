import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const endpoint = fs.readFileSync('api/admin/knowledge-enrichment/index.php', 'utf8');
const service = fs.readFileSync('api/admin/services/KnowledgeEnrichmentReviewService.php', 'utf8');

test('knowledge enrichment admin endpoint protects reads and writes', () => {
  assert.match(endpoint, /adminRequirePermission\(\$permission\)/);
  assert.match(endpoint, /knowledge_audit/);
  assert.match(endpoint, /knowledge_edit/);
  assert.match(endpoint, /action === 'decision'/);
});

test('review service binds source version and records audit decisions', () => {
  assert.match(service, /source_content_sha256/);
  assert.match(service, /source_content/);
  assert.ok(service.includes("review_status = \\'pending\\'"));
  assert.match(service, /knowledge_audit_logs/);
  assert.match(service, /saveDraft/);
  assert.match(service, /enriched_content_sha256/);
  assert.match(service, /enrichment_save_draft/);
});

test('release service validates hashes and supports publish rollback report', () => {
  const release = fs.readFileSync('api/admin/services/KnowledgeEnrichmentReleaseService.php', 'utf8');
  const endpoint = fs.readFileSync('api/admin/knowledge-enrichment/index.php', 'utf8');
  assert.match(release, /enriched_content_sha256/);
  assert.match(release, /enrichment_publish/);
  assert.match(release, /enrichment_rollback/);
  assert.match(release, /GROUP BY enrichment_status/);
  assert.match(endpoint, /action === 'publish'/);
  assert.match(endpoint, /action === 'rollback'/);
  assert.match(endpoint, /action === 'report'/);
});

test('review workbench supports filtering and explicit decisions', () => {
  const page = fs.readFileSync('admin/knowledge-enrichment.html', 'utf8');
  assert.match(page, /enrichment_status/);
  assert.match(page, /review_status/);
  assert.match(page, /risk_flag/);
  assert.match(page, /decision/);
  assert.match(page, /source_content/);
  assert.match(page, /source_length/);
  assert.match(page, /发布选中/);
  assert.match(page, /回退批次/);
  assert.match(page, /action:'save'/);
});

test('employee detail exposes only hash-bound published enrichment', () => {
  const endpoint = fs.readFileSync('api/knowledge/detail.php', 'utf8');
  const page = fs.readFileSync('knowledge/detail.js', 'utf8');
  assert.match(endpoint, /er\.source_content_sha256/);
  assert.match(endpoint, /er\.enrichment_status = 'published'/);
  assert.match(endpoint, /published_enrichment/);
  assert.match(endpoint, /enrichment_state/);
  assert.match(page, /查看原文/);
});

test('release UI exposes bounded batch operations', () => {
  const page = fs.readFileSync('admin/knowledge-enrichment.html', 'utf8');
  assert.match(page, /data-select/);
  assert.match(page, /action,ids,release_batch_id/);
  assert.match(page, /action:'report'/);
  assert.match(page, /release\('rollback'\)/);
});

test('pilot gate covers the six enrichment task types and 100 cards', () => {
  const pilot = fs.readFileSync('scripts/knowledge_enrichment_pilot_test.py', 'utf8');
  assert.match(pilot, /range\(100\)/);
  assert.match(pilot, /TASK_TYPES/);
  assert.match(pilot, /source_content_sha256/);
  assert.match(pilot, /citations/);
});
