import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const gatePath = path.join(root, 'scripts', 'knowledge_card_release_gate.php');
const formalPackagePath = path.join(root, 'database', 'import_data', 'knowledge-cards-phase2.isolated-package.json');
const formalReviewPath = path.join(root, 'database', 'import_data', 'knowledge-cards-phase2.taxonomy-review-report.json');
const taxonomyVersion = 'taxonomy-2026-09-04-v1';
const requiredChecks = [
  'record_count',
  'transitional_classification',
  'mapping_integrity',
  'current_versions',
  'review_records',
  'target_visibility',
];

function validRecord(index) {
  return {
    item_code: `FIXTURE-${index}`,
    title: `Fixture ${index}`,
    content: 'Fixture content',
    content_type: 'action',
    domain_code: 'course_skills',
    domain_mapping_status: 'mapped',
    risk_level: 'low',
    source_path: `fixture-${index}.md`,
    source_sha256: 'a'.repeat(64),
    normalized_hash: 'b'.repeat(64),
    publication_status: 'isolated',
    version_id: null,
    metadata: {
      applicable_ages: ['all'],
      setting: ['classroom'],
      subjects: ['movement'],
      source_articles: ['fixture'],
      target_roles: ['coach'],
      target_stages: ['delivery'],
      difficulty: 1,
      related_content: [],
    },
  };
}

function validInputs() {
  return {
    package: { record_count: 2, records: [validRecord(1), validRecord(2)] },
    review: {
      inputs: { taxonomy_mapping_version: taxonomyVersion },
      summary: {
        record_count: 2,
        transitional_count: 0,
        transitional_category_code: 'phase2_import',
        mapped_count: 2,
        mapping_gap_count: 0,
        manual_review_count: 0,
        review_status_counts: { confirmed: 2, pending: 0 },
      },
    },
    evidence: {
      knowledge_database: {
        status: 'passed',
        record_count: 2,
        visible_count: 2,
        transitional_count: 0,
        unmapped_count: 0,
        current_version_count: 2,
        review_record_count: 2,
        taxonomy_mapping_version: taxonomyVersion,
      },
    },
  };
}

function runGate(inputs, options = {}) {
  const directory = mkdtempSync(path.join(os.tmpdir(), 'knowledge-release-gate-'));
  const packagePath = path.join(directory, 'package.json');
  const reviewPath = path.join(directory, 'review.json');
  const evidencePath = path.join(directory, 'evidence.json');
  writeFileSync(packagePath, JSON.stringify(inputs.package), 'utf8');
  writeFileSync(reviewPath, JSON.stringify(inputs.review), 'utf8');
  writeFileSync(evidencePath, JSON.stringify(inputs.evidence), 'utf8');
  const evidenceArgument = options.stdin ? '-' : evidencePath;
  const result = spawnSync('php', [gatePath, packagePath, '2', reviewPath, evidenceArgument], {
    cwd: root,
    encoding: 'utf8',
    input: options.stdin ? JSON.stringify(inputs.evidence) : undefined,
  });
  return { ...result, report: JSON.parse(result.stdout) };
}

test('知识卡 release gate 分别输出六项发布检查', () => {
  const result = runGate(validInputs());
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.report.schema_version, 'knowledge-card-release-gate.v2');
  assert.equal(result.report.ready_for_unified_release, true);
  assert.deepEqual(
    result.report.checks.filter(({ name }) => requiredChecks.includes(name)).map(({ name }) => name),
    requiredChecks,
  );
  assert.ok(result.report.checks.every(({ passed }) => passed));
});

test('统一门禁可通过标准输入复用完整 release evidence', () => {
  const result = runGate(validInputs(), { stdin: true });
  assert.equal(result.status, 0, result.stderr);
  assert.equal(result.report.database_evidence, 'stdin');
  assert.equal(result.report.ready_for_unified_release, true);
});

test('六项发布条件分别返回稳定失败原因', () => {
  const cases = [
    ['record_count', 'record_count_mismatch', (value) => { value.evidence.knowledge_database.record_count = 1; }],
    ['transitional_classification', 'transitional_classification_present', (value) => { value.review.summary.transitional_count = 1; }],
    ['mapping_integrity', 'domain_mapping_incomplete', (value) => { value.review.summary.mapping_gap_count = 1; }],
    ['current_versions', 'current_version_count_mismatch', (value) => { value.evidence.knowledge_database.current_version_count = 1; }],
    ['review_records', 'review_records_incomplete', (value) => { value.evidence.knowledge_database.review_record_count = 1; }],
    ['target_visibility', 'target_visible_count_mismatch', (value) => { value.evidence.knowledge_database.visible_count = 1; }],
  ];

  for (const [checkName, reason, mutate] of cases) {
    const inputs = validInputs();
    mutate(inputs);
    const result = runGate(inputs);
    const check = result.report.checks.find(({ name }) => name === checkName);
    assert.equal(result.status, 1, checkName);
    assert.equal(check.passed, false, checkName);
    assert.ok(check.reasons.includes(reason), `${checkName}: ${check.reasons.join(',')}`);
  }
});

test('缺少目标环境证据时版本、审核与可见数量保持阻断', () => {
  const inputs = validInputs();
  inputs.evidence = null;
  const result = runGate(inputs);
  assert.equal(result.status, 1);
  for (const name of ['record_count', 'mapping_integrity', 'current_versions', 'review_records', 'target_visibility']) {
    assert.equal(result.report.checks.find((check) => check.name === name).passed, false, name);
  }
});

test('正式 1417 张卡审核报告因过渡分类和待审核记录阻断', () => {
  const result = spawnSync('php', [gatePath, formalPackagePath, '1417', formalReviewPath], {
    cwd: root,
    encoding: 'utf8',
  });
  const report = JSON.parse(result.stdout);
  assert.equal(result.status, 1);
  assert.equal(report.ready_for_unified_release, false);
  assert.equal(report.manual_review_count, 1417);
  assert.equal(report.checks.find((check) => check.name === 'transitional_classification').detail.actual, 1417);
  assert.equal(report.checks.find((check) => check.name === 'review_records').detail.repository_pending, 1417);
});

test('gate 与审核报告共同使用唯一激活 taxonomy 版本', () => {
  const source = readFileSync(gatePath, 'utf8');
  assert.ok(source.includes('KnowledgeTaxonomy::mappingVersion()'));
  assert.ok(source.includes("['taxonomy_mapping_version']"));
});
