import assert from 'node:assert/strict';
import { readFileSync, rmSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const sourceRoot = process.env.KNOWLEDGE_SOURCE_ROOT;
const inspector = path.join(repoRoot, 'real_sync', 'scripts', 'inspect_knowledge_cards.py');
const builder = path.join(repoRoot, 'real_sync', 'scripts', 'build_knowledge_card_package.py');

test('isolated package is deterministic, complete, and unpublished by default', { skip: !sourceRoot }, () => {
  const first = path.join(os.tmpdir(), `knowledge-package-${process.pid}-1.json`);
  const second = path.join(os.tmpdir(), `knowledge-package-${process.pid}-2.json`);
  try {
    execFileSync('python', [inspector, '--source-root', sourceRoot, '--report', path.join(os.tmpdir(), `knowledge-report-${process.pid}.json`), '--strict'], { stdio: 'pipe' });
    execFileSync('python', [builder, '--source-root', sourceRoot, '--output', first, '--strict'], { stdio: 'pipe' });
    execFileSync('python', [builder, '--source-root', sourceRoot, '--output', second, '--strict'], { stdio: 'pipe' });
    assert.deepEqual(readFileSync(second), readFileSync(first));
    const packageData = JSON.parse(readFileSync(first, 'utf8'));
    assert.equal(packageData.record_count, 1417);
    assert.equal(packageData.source_report_valid, true);
    assert.equal(packageData.publication_default, 'isolated');
    assert.equal(packageData.records.length, 1417);
    assert.equal(new Set(packageData.records.map((record) => record.item_code)).size, 1417);
    assert.ok(packageData.records.every((record) => record.publication_status === 'isolated'));
    assert.ok(packageData.records.every((record) => record.domain_mapping_status === 'unmapped'));
    assert.ok(packageData.records.every((record) => /^[a-f0-9]{64}$/.test(record.source_sha256)));
  } finally {
    rmSync(first, { force: true });
    rmSync(second, { force: true });
    rmSync(path.join(os.tmpdir(), `knowledge-report-${process.pid}.json`), { force: true });
  }
});
