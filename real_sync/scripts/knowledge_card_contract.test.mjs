import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, mkdirSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import test from 'node:test';

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..', '..');
const script = path.join(repoRoot, 'real_sync', 'scripts', 'inspect_knowledge_cards.py');

function runInspection(root, report, expected, strict = true) {
  const result = spawnSync('python3', [script, '--source-root', root, '--report', report, '--expected-record-count', String(expected), ...(strict ? ['--strict'] : [])], { encoding: 'utf8' });
  return result;
}

function sourceSize(root) {
  let total = 0;
  for (const entry of readdirSync(root, { withFileTypes: true })) {
    const entryPath = path.join(root, entry.name);
    if (entry.isDirectory()) total += sourceSize(entryPath);
    else if (entry.name.endsWith('.md')) total += statSync(entryPath).size;
  }
  return total;
}

function writeCard(root, directory, filename, id, type, extra = '') {
  const dir = path.join(root, directory);
  mkdirSync(dir, { recursive: true });
  writeFileSync(path.join(dir, filename), `---\ncard_id: ${id}\ncard_type: ${type}\nstatus: 已审核\nrisk_level: 低\nsource_articles: []\nsource_images: []\n${extra}---\n# 标题\n\n正文\n`, 'utf8');
}

test('valid cards satisfy the source contract and use the eight-domain vocabulary', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'knowledge-card-contract-'));
  const report = path.join(root, 'report.json');
  try {
    writeCard(root, '动作卡', 'ACTION-0001_动作.md', 'ACTION-0001', '动作');
    writeCard(root, '游戏卡', 'GAME-0001_游戏.md', 'GAME-0001', '游戏');
    writeCard(root, '安全与禁忌卡', 'SAFE-0001_安全.md', 'SAFE-0001', '安全与禁忌');
    const result = runInspection(root, report, 3);
    assert.equal(result.status, 0, result.stderr || result.stdout);
    const payload = JSON.parse(readFileSync(report, 'utf8'));
    assert.equal(payload.valid, true);
    assert.equal(payload.taxonomy_mapping_version, 'taxonomy-2026-09-04-v1');
    assert.deepEqual(payload.domain_codes, [
      'ace_teaching', 'child_development', 'sensory_integration', 'physical_qualities',
      'course_skills', 'assessment', 'teaching_practice', 'safety_first_aid',
    ]);
    assert.equal(payload.record_count, 3);
    assert.equal(payload.domain_mapping.mapped, 3);
    for (const record of payload.records) {
      assert.match(record.source_sha256, /^[a-f0-9]{64}$/);
      assert.match(record.normalized_hash, /^[a-f0-9]{64}$/);
      assert.equal(record.domain_mapping_status, 'mapped');
      assert.ok(record.metadata.applicable_ages.length > 0);
    }
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('duplicate and malformed IDs fail strict validation without changing source files', () => {
  const root = mkdtempSync(path.join(tmpdir(), 'knowledge-card-contract-invalid-'));
  const report = path.join(root, 'report.json');
  try {
    writeCard(root, '教学组织卡', 'ORG-0001_单排.md', 'ORG-0001', '教学组织');
    writeCard(root, '教学组织卡', 'ORG-0001_另一张.md', 'ORG-0001', '教学组织');
    writeCard(root, '教学组织卡', 'ORG-_方阵.md', 'ORG-', '教学组织');
    const before = sourceSize(root);
    const result = runInspection(root, report, 3);
    assert.equal(result.status, 1);
    const payload = JSON.parse(readFileSync(report, 'utf8'));
    assert.equal(payload.valid, false);
    assert.equal(payload.error_count, 2);
    assert.ok(payload.errors.some((item) => item.errors.includes('invalid_card_id')));
    assert.ok(payload.errors.some((item) => item.errors.some((error) => error.startsWith('duplicate_card_ids:'))));
    const after = sourceSize(root);
    assert.equal(after, before);
  } finally {
    rmSync(root, { recursive: true, force: true });
  }
});

test('the formal source report exposes the current source quality gate when available', { skip: !process.env.KNOWLEDGE_SOURCE_ROOT }, () => {
  const root = process.env.KNOWLEDGE_SOURCE_ROOT;
  const report = path.join(tmpdir(), 'knowledge-card-formal-report.json');
  const result = runInspection(root, report, 1417);
  assert.equal(result.status, 0, result.stderr || result.stdout);
  const payload = JSON.parse(readFileSync(report, 'utf8'));
  assert.equal(payload.record_count, 1417);
  assert.equal(payload.valid, true);
  assert.equal(payload.error_count, 0);
  rmSync(report, { force: true });
});
