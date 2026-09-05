import assert from 'node:assert/strict';
import { readFileSync, rmSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const sourceRoot = process.env.KNOWLEDGE_SOURCE_ROOT;
const inspector = path.join(repoRoot, 'real_sync', 'scripts', 'inspect_knowledge_cards.py');
const builder = path.join(repoRoot, 'real_sync', 'scripts', 'build_knowledge_card_package.py');

const CARD_TYPES = new Set(['action', 'game', 'training_plan', 'teaching_organization', 'teaching_knowledge', 'coach_growth', 'assessment', 'safety']);
const RISK_LEVELS = new Set(['低', '中', '高']);
const SOURCE_STATUSES = new Set(['待整理', '待审核', '已审核', '已纳入课程', '不采用']);
const RECORD_KEYS = [
  'item_code', 'source_card_id', 'source_path', 'source_sha256', 'normalized_hash', 'title', 'content',
  'raw_markdown', 'content_type', 'content_type_label', 'domain_code', 'domain_mapping_status',
  'risk_level', 'source_status', 'publication_status', 'metadata',
];

test('isolated package is deterministic, complete, and unpublished by default', { skip: !sourceRoot }, () => {
  const first = path.join(os.tmpdir(), `knowledge-package-${process.pid}-1.json`);
  const second = path.join(os.tmpdir(), `knowledge-package-${process.pid}-2.json`);
  try {
    execFileSync('python3', [inspector, '--source-root', sourceRoot, '--report', path.join(os.tmpdir(), `knowledge-report-${process.pid}.json`), '--strict'], { stdio: 'pipe' });
    execFileSync('python3', [builder, '--source-root', sourceRoot, '--output', first, '--strict'], { stdio: 'pipe' });
    execFileSync('python3', [builder, '--source-root', sourceRoot, '--output', second, '--strict'], { stdio: 'pipe' });
    assert.deepEqual(readFileSync(second), readFileSync(first));
    const packageData = JSON.parse(readFileSync(first, 'utf8'));
    assert.equal(packageData.schema_version, 'knowledge-card-isolated-package.v2');
    assert.equal(packageData.record_count, 1417);
    assert.equal(packageData.source_report_valid, true);
    assert.equal(packageData.publication_default, 'isolated');
    assert.equal(packageData.default_category_code, 'phase2_import');
    assert.equal(packageData.records.length, 1417);
    assert.equal(new Set(packageData.records.map((record) => record.item_code)).size, 1417);
    assert.ok(packageData.records.every((record) => record.publication_status === 'isolated'));
    assert.ok(packageData.records.every((record) => record.domain_mapping_status === 'mapped'));
    assert.ok(packageData.records.every((record) => /^[a-f0-9]{64}$/.test(record.source_sha256)));
    assert.ok(packageData.records.every((record) => /^[a-f0-9]{64}$/.test(record.normalized_hash)));
    assert.ok(packageData.records.every((record) => /^[A-Z]+-\d{4}$/.test(record.item_code)));
    assert.ok(packageData.records.every((record) => CARD_TYPES.has(record.content_type)));
    assert.ok(packageData.records.every((record) => RISK_LEVELS.has(record.risk_level)));
    assert.ok(packageData.records.every((record) => SOURCE_STATUSES.has(record.source_status)));
    assert.ok(packageData.records.every((record) => Array.isArray(record.metadata) === false && typeof record.metadata === 'object'));
  } finally {
    rmSync(first, { force: true });
    rmSync(second, { force: true });
    rmSync(path.join(os.tmpdir(), `knowledge-report-${process.pid}.json`), { force: true });
  }
});

test('v2 package carries full body and raw markdown per record', () => {
  const packagePath = path.join(repoRoot, 'real_sync', 'database', 'import_data', 'knowledge-cards-phase2.isolated-package.json');
  const packageData = JSON.parse(readFileSync(packagePath, 'utf8'));
  assert.equal(packageData.schema_version, 'knowledge-card-isolated-package.v2');
  assert.equal(packageData.records.length, 1417);
  for (const record of packageData.records) {
    assert.deepEqual(Object.keys(record).sort(), [...RECORD_KEYS].sort(), record.item_code);
    assert.ok(record.title.length > 0, record.item_code);
    assert.ok(record.content.length > 0, record.item_code);
    assert.ok(record.raw_markdown.length > 0, record.item_code);
    assert.ok(record.raw_markdown.startsWith('---\n') || record.raw_markdown.startsWith('---\r\n'), record.item_code);
    const contentHash = createHash('sha256').update(record.content, 'utf8').digest('hex');
    assert.equal(contentHash, record.normalized_hash, `normalized_hash mismatch for ${record.item_code}`);
    const rawHash = createHash('sha256').update(record.raw_markdown, 'utf8').digest('hex');
    assert.equal(rawHash, record.source_sha256, `source_sha256 mismatch for ${record.item_code}`);
  }
});
