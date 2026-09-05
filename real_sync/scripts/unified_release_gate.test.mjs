import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

import {
  REQUIRED_RELEASE_CHECKS,
  REQUIRED_ROLE_FLOWS,
  aggregateReleaseChecks,
  calculateCodeDigest,
  collectMigrationVersions,
  createReleaseArtifact,
  evaluateReleaseEvidence,
  isIntegrationVerified,
} from './unified_release_gate.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const verifiedFiles = ['scripts/unified_release_gate.mjs'];

function validEvidence(overrides = {}) {
  return {
    schema_version: '1.0',
    generated_at: '2026-09-04T08:00:00.000Z',
    expires_at: '2026-09-04T10:00:00.000Z',
    verified_files: verifiedFiles,
    code_digest: calculateCodeDigest(root, verifiedFiles),
    migration_versions: collectMigrationVersions(root),
    tests: { total: 120, passed: 120, failed: 0 },
    database_integration: {
      status: 'passed',
      verified_at: '2026-09-04T08:15:00.000Z',
      migration_verified: true,
    },
    browser_integration: {
      status: 'passed',
      verified_at: '2026-09-04T08:30:00.000Z',
      role_flows: [...REQUIRED_ROLE_FLOWS],
    },
    knowledge_database: {
      status: 'passed',
      record_count: 1417,
      visible_count: 1417,
      transitional_count: 0,
      unmapped_count: 0,
      current_version_count: 1417,
      review_record_count: 1417,
      taxonomy_mapping_version: 'taxonomy-2026-09-04-v1',
    },
    asset_release_id: 'internal-assets-20260904.1',
    ...overrides,
  };
}

test('统一门禁具有独立、稳定的必需检查名称', () => {
  assert.deepEqual(REQUIRED_RELEASE_CHECKS, [
    'page-contract',
    'database-integration',
    'browser-integration',
    'knowledge-package-state',
    'knowledge-database-state',
    'release-evidence-current',
  ]);
});

test('release evidence JSON Schema 固定必需证据字段', () => {
  const schema = JSON.parse(readFileSync(path.join(root, 'scripts/release-evidence.schema.json'), 'utf8'));
  assert.equal(schema.$schema, 'https://json-schema.org/draft/2020-12/schema');
  for (const field of [
    'schema_version', 'generated_at', 'expires_at', 'verified_files', 'code_digest',
    'migration_versions', 'tests', 'database_integration', 'browser_integration',
    'knowledge_database', 'asset_release_id',
  ]) {
    assert.ok(schema.required.includes(field), `missing schema field ${field}`);
  }
  for (const field of ['current_version_count', 'review_record_count', 'taxonomy_mapping_version']) {
    assert.ok(schema.properties.knowledge_database.required.includes(field), `missing knowledge evidence field ${field}`);
  }
});

test('有效证据汇总测试、migration、知识、角色和资源发布号', () => {
  const result = evaluateReleaseEvidence(validEvidence(), {
    root,
    now: new Date('2026-09-04T09:00:00.000Z'),
  });

  assert.equal(result.current, true);
  assert.equal(result.database.passed, true);
  assert.equal(result.browser.passed, true);
  assert.equal(result.knowledge_database.passed, true);
  assert.deepEqual(result.summary, {
    test_totals: { total: 120, passed: 120, failed: 0 },
    migration_verified: true,
    migration_count: collectMigrationVersions(root).length,
    knowledge_counts: {
      record_count: 1417,
      visible_count: 1417,
      transitional_count: 0,
      unmapped_count: 0,
      current_version_count: 1417,
      review_record_count: 1417,
      taxonomy_mapping_version: 'taxonomy-2026-09-04-v1',
    },
    role_coverage: [...REQUIRED_ROLE_FLOWS],
    asset_release_id: 'internal-assets-20260904.1',
  });
});

test('过期证据和代码摘要变化产生稳定失败原因', () => {
  const expired = evaluateReleaseEvidence(validEvidence(), {
    root,
    now: new Date('2026-09-04T10:00:00.000Z'),
  });
  const changed = evaluateReleaseEvidence(validEvidence({ code_digest: '0'.repeat(64) }), {
    root,
    now: new Date('2026-09-04T09:00:00.000Z'),
  });

  assert.ok(expired.reasons.includes('release_evidence_expired'));
  assert.ok(changed.reasons.includes('code_digest_mismatch'));
  assert.equal(expired.current, false);
  assert.equal(changed.current, false);
});

test('数据库和浏览器证据缺失时分别阻断放行', () => {
  const evidence = validEvidence();
  delete evidence.database_integration;
  delete evidence.browser_integration;
  const result = evaluateReleaseEvidence(evidence, {
    root,
    now: new Date('2026-09-04T09:00:00.000Z'),
  });

  assert.equal(result.database.passed, false);
  assert.equal(result.browser.passed, false);
  assert.ok(result.database.reasons.includes('database_integration_missing'));
  assert.ok(result.browser.reasons.includes('browser_integration_missing'));
  assert.equal(isIntegrationVerified(true, result), false);
});

test('知识数据库证据分别校验当前版本、审核记录和 taxonomy 版本', () => {
  const evidence = validEvidence();
  evidence.knowledge_database.visible_count = 1414;
  evidence.knowledge_database.current_version_count = 1416;
  evidence.knowledge_database.review_record_count = 1415;
  evidence.knowledge_database.taxonomy_mapping_version = '';
  const result = evaluateReleaseEvidence(evidence, {
    root,
    now: new Date('2026-09-04T09:00:00.000Z'),
  });

  assert.equal(result.knowledge_database.passed, false);
  assert.ok(result.knowledge_database.reasons.includes('knowledge_visible_records_incomplete'));
  assert.ok(result.knowledge_database.reasons.includes('knowledge_current_versions_incomplete'));
  assert.ok(result.knowledge_database.reasons.includes('knowledge_review_records_incomplete'));
  assert.ok(result.knowledge_database.reasons.includes('knowledge_taxonomy_mapping_version_missing'));
});

test('调用方标志和当前数据库、浏览器证据共同决定集成验证状态', () => {
  const result = evaluateReleaseEvidence(validEvidence(), {
    root,
    now: new Date('2026-09-04T09:00:00.000Z'),
  });
  assert.equal(isIntegrationVerified(false, result), false);
  assert.equal(isIntegrationVerified(true, result), true);
});

test('仓库知识包状态和集成数据库状态使用两个检查项', () => {
  const report = aggregateReleaseChecks([
    { name: 'page-contract', passed: true, detail: {} },
    { name: 'database-integration', passed: true, detail: {} },
    { name: 'browser-integration', passed: true, detail: {} },
    { name: 'knowledge-package-state', passed: false, detail: { publication_status: 'isolated' } },
    { name: 'knowledge-database-state', passed: true, detail: { visible_count: 1417 } },
    { name: 'release-evidence-current', passed: true, detail: {} },
  ]);

  assert.equal(report.ready_for_release, false);
  assert.equal(report.checks.find(({ name }) => name === 'knowledge-package-state').passed, false);
  assert.equal(report.checks.find(({ name }) => name === 'knowledge-database-state').passed, true);
});

test('仓库知识包状态在任意数据库证据变化时保持独立', () => {
  const repositoryDetail = Object.freeze({
    source: 'database/import_data/knowledge-cards-phase2.isolated-package.json',
    record_count: 1417,
    publication_status_counts: Object.freeze({ isolated: 1417 }),
    versioned_count: 0,
  });
  const cases = [
    [true, {}],
    [false, { status: 'failed' }],
    [false, { visible_count: 1416 }],
    [false, { transitional_count: 1 }],
    [false, { unmapped_count: 1 }],
    [false, { current_version_count: 1416 }],
    [false, { review_record_count: 1416 }],
    [false, { taxonomy_mapping_version: '' }],
  ];

  for (const [expectedDatabasePass, knowledgeOverride] of cases) {
    const evidenceResult = evaluateReleaseEvidence(validEvidence({
      knowledge_database: {
        ...validEvidence().knowledge_database,
        ...knowledgeOverride,
      },
    }), {
      root,
      now: new Date('2026-09-04T09:00:00.000Z'),
    });
    const report = aggregateReleaseChecks([
      { name: 'page-contract', passed: true, detail: {} },
      { name: 'database-integration', passed: true, detail: {} },
      { name: 'browser-integration', passed: true, detail: {} },
      { name: 'knowledge-package-state', passed: true, detail: repositoryDetail },
      { name: 'knowledge-database-state', passed: evidenceResult.knowledge_database.passed, detail: evidenceResult.knowledge_database },
      { name: 'release-evidence-current', passed: true, detail: {} },
    ]);

    assert.equal(report.checks.find(({ name }) => name === 'knowledge-package-state').passed, true);
    assert.strictEqual(report.checks.find(({ name }) => name === 'knowledge-package-state').detail, repositoryDetail);
    assert.equal(report.checks.find(({ name }) => name === 'knowledge-database-state').passed, expectedDatabasePass);
    assert.equal(report.ready_for_release, expectedDatabasePass);
  }
});

test('全部必需检查通过且没有其他失败项时允许最终放行', () => {
  const checks = REQUIRED_RELEASE_CHECKS.map((name) => ({ name, passed: true, detail: {} }));
  assert.equal(aggregateReleaseChecks(checks).ready_for_release, true);

  checks.push({ name: 'contract-tests', passed: false, detail: { reason: 'failed' } });
  assert.equal(aggregateReleaseChecks(checks).ready_for_release, false);
});

test('机器可读 release artifact 汇总证据和每项检查明细', () => {
  const artifact = createReleaseArtifact({
    ready_for_release: true,
    integration_verified: true,
    evidence_summary: {
      test_totals: { total: 120, passed: 120, failed: 0 },
      migration_verified: true,
      migration_count: 8,
      knowledge_counts: { record_count: 1417, visible_count: 1417 },
      role_coverage: [...REQUIRED_ROLE_FLOWS],
      asset_release_id: 'internal-assets-20260904.1',
    },
    checks: [{ name: 'page-contract', passed: true, detail: { routes: 7 } }],
  }, { generatedAt: '2026-09-04T09:00:00.000Z' });

  assert.deepEqual(artifact, {
    schema_version: '1.0',
    generated_at: '2026-09-04T09:00:00.000Z',
    ready_for_release: true,
    integration_verified: true,
    test_totals: { total: 120, passed: 120, failed: 0 },
    migration: { verified: true, count: 8 },
    knowledge_counts: { record_count: 1417, visible_count: 1417 },
    role_coverage: [...REQUIRED_ROLE_FLOWS],
    asset_release_id: 'internal-assets-20260904.1',
    checks: [{ name: 'page-contract', passed: true, detail: { routes: 7 } }],
  });
});

test('页面契约使用当前知识中心和学习中心的真实文案', () => {
  const knowledgePage = readFileSync(path.join(root, 'knowledge/index.html'), 'utf8');
  const learningPage = readFileSync(path.join(root, 'learning/index.html'), 'utf8');
  for (const text of ['知识中心', '专业知识', '销售知识']) assert.match(knowledgePage, new RegExp(text));
  for (const text of ['学习中心', '正式教案库', '审核任务', '继续学习']) assert.match(learningPage, new RegExp(text));
});
