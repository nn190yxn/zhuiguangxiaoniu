import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { execFileSync, spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync } from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const builder = path.join(root, 'scripts', 'build_knowledge_card_classification_report.py');
const packagePath = path.join(root, 'database', 'import_data', 'knowledge-cards-phase2.isolated-package.json');
const taxonomyPath = path.join(root, 'database', 'knowledge_taxonomy_mapping.v1.json');
const formalReportPath = path.join(root, 'database', 'import_data', 'knowledge-cards-phase2.taxonomy-review-report.json');

function digest(value) {
  return createHash('sha256').update(value).digest('hex');
}

function runBuilder(packageFile, taxonomyFile, outputFile, expectedCount) {
  return spawnSync('python3', [
    builder,
    '--package', packageFile,
    '--taxonomy', taxonomyFile,
    '--output', outputFile,
    '--expected-record-count', String(expectedCount),
  ], { encoding: 'utf8' });
}

test('正式报告绑定输入并完整列出 1417 张卡的分类审核状态', () => {
  const report = JSON.parse(readFileSync(formalReportPath, 'utf8'));
  const withoutDigest = { ...report };
  delete withoutDigest.report_sha256;

  assert.equal(report.schema_version, 'knowledge-card-classification-review-report.v1');
  assert.equal(report.inputs.taxonomy_mapping_version, 'taxonomy-2026-09-04-v1');
  assert.equal(report.inputs.package_file_sha256, digest(readFileSync(packagePath)));
  assert.equal(report.inputs.taxonomy_file_sha256, digest(readFileSync(taxonomyPath)));
  assert.equal(report.report_sha256, digest(Buffer.from(`${JSON.stringify(withoutDigest, null, 2)}\n`)));
  assert.deepEqual(report.scope, {
    production_database: {
      reason: 'repository classification report does not connect to a database',
      status: 'not_evaluated',
    },
    repository_package: { publication_state: 'isolated', status: 'evaluated' },
  });
  assert.deepEqual(report.summary, {
    classification_difference_count: 1404,
    classification_match_count: 13,
    manual_review_count: 1417,
    mapped_count: 1417,
    mapping_gap_count: 0,
    record_count: 1417,
    review_status_counts: { confirmed: 0, pending: 1417 },
    transitional_category_code: 'phase2_import',
    transitional_count: 1417,
  });
  assert.equal(report.review_items.length, 1417);
  assert.equal(new Set(report.review_items.map((item) => item.item_code)).size, 1417);
  assert.ok(report.review_items.every((item) => item.assigned_category_code === 'phase2_import'));
  assert.ok(report.review_items.every((item) => item.review_status === 'pending'));
  assert.ok(report.review_items.every((item) => item.review_reasons.includes('classification_review_missing')));
  assert.deepEqual(report.mapping_gaps, []);
});

test('正式报告明确分类差异和逐类人工确认原因', () => {
  const report = JSON.parse(readFileSync(formalReportPath, 'utf8'));
  const reasons = Object.fromEntries(report.statistics.manual_review_reasons.map((row) => [row.reason, row.count]));
  const differences = Object.fromEntries(report.statistics.classification_differences.map((row) => [
    `${row.content_type}:${row.source_domain_code}`,
    row.count,
  ]));

  assert.equal(reasons.transitional_category, 1417);
  assert.equal(reasons.classification_review_missing, 1417);
  assert.equal(reasons.content_type_taxonomy_difference, 1404);
  assert.equal(reasons.applicable_age_confirmation_required, 1136);
  assert.equal(reasons.setting_confirmation_required, 1137);
  assert.equal(reasons.related_content_confirmation_required, 1417);
  assert.equal(differences['action:safety_first_aid'], 610);
  assert.equal(differences['game:safety_first_aid'], 564);
  assert.equal(differences['training_plan:safety_first_aid'], 159);
});

test('生成器对同一输入产生逐字节相同的报告', () => {
  const directory = mkdtempSync(path.join(os.tmpdir(), 'knowledge-taxonomy-report-deterministic-'));
  const first = path.join(directory, 'first.json');
  const second = path.join(directory, 'second.json');
  execFileSync('python3', [builder, '--package', packagePath, '--taxonomy', taxonomyPath, '--output', first], { stdio: 'pipe' });
  execFileSync('python3', [builder, '--package', packagePath, '--taxonomy', taxonomyPath, '--output', second], { stdio: 'pipe' });
  assert.deepEqual(readFileSync(second), readFileSync(first));
});

test('映射缺口保持记录在待审核报告中', () => {
  const directory = mkdtempSync(path.join(os.tmpdir(), 'knowledge-taxonomy-report-gap-'));
  const fixturePackagePath = path.join(directory, 'package.json');
  const fixtureTaxonomyPath = path.join(directory, 'taxonomy.json');
  const outputPath = path.join(directory, 'report.json');
  const fixturePackage = JSON.parse(readFileSync(packagePath, 'utf8'));
  const fixtureTaxonomy = JSON.parse(readFileSync(taxonomyPath, 'utf8'));
  fixturePackage.records = [
    {
      ...fixturePackage.records[0],
      domain_code: 'unknown_domain',
      domain_mapping_status: 'unmapped',
      item_code: 'ACTION-TEST-0001',
      source_card_id: 'ACTION-TEST-0001',
    },
  ];
  fixturePackage.record_count = 1;
  writeFileSync(fixturePackagePath, JSON.stringify(fixturePackage), 'utf8');
  writeFileSync(fixtureTaxonomyPath, JSON.stringify(fixtureTaxonomy), 'utf8');

  const result = runBuilder(fixturePackagePath, fixtureTaxonomyPath, outputPath, 1);
  assert.equal(result.status, 0, result.stderr || result.stdout);
  const report = JSON.parse(readFileSync(outputPath, 'utf8'));
  assert.equal(report.summary.mapped_count, 0);
  assert.equal(report.summary.mapping_gap_count, 1);
  assert.equal(report.review_items[0].mapped_taxonomy_target, null);
  assert.ok(report.review_items[0].review_reasons.includes('taxonomy_mapping_missing'));
  assert.equal(report.mapping_gaps[0].source_domain_code, 'unknown_domain');
});

test('重复卡号和无唯一激活版本会被稳定拒绝', () => {
  const directory = mkdtempSync(path.join(os.tmpdir(), 'knowledge-taxonomy-report-invalid-'));
  const fixturePackagePath = path.join(directory, 'package.json');
  const fixtureTaxonomyPath = path.join(directory, 'taxonomy.json');
  const outputPath = path.join(directory, 'report.json');
  const fixturePackage = JSON.parse(readFileSync(packagePath, 'utf8'));
  const fixtureTaxonomy = JSON.parse(readFileSync(taxonomyPath, 'utf8'));

  fixturePackage.records = [fixturePackage.records[0], fixturePackage.records[0]];
  fixturePackage.record_count = 2;
  writeFileSync(fixturePackagePath, JSON.stringify(fixturePackage), 'utf8');
  writeFileSync(fixtureTaxonomyPath, JSON.stringify(fixtureTaxonomy), 'utf8');
  let result = runBuilder(fixturePackagePath, fixtureTaxonomyPath, outputPath, 2);
  assert.equal(result.status, 1);
  assert.match(result.stderr, /duplicate item_code/);

  fixturePackage.records = [fixturePackage.records[0]];
  fixturePackage.record_count = 1;
  fixtureTaxonomy.versions[0].status = 'inactive';
  writeFileSync(fixturePackagePath, JSON.stringify(fixturePackage), 'utf8');
  writeFileSync(fixtureTaxonomyPath, JSON.stringify(fixtureTaxonomy), 'utf8');
  result = runBuilder(fixturePackagePath, fixtureTaxonomyPath, outputPath, 1);
  assert.equal(result.status, 1);
  assert.match(result.stderr, /one valid active mapping version/);
});
