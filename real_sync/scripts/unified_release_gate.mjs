#!/usr/bin/env node
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const defaultRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

export const REQUIRED_RELEASE_CHECKS = Object.freeze([
  'page-contract',
  'database-integration',
  'browser-integration',
  'knowledge-package-state',
  'knowledge-database-state',
  'release-evidence-current',
]);

export const REQUIRED_ROLE_FLOWS = Object.freeze([
  'staff',
  'store_manager',
  'teaching_supervisor',
  'headquarters_admin',
]);

export function calculateCodeDigest(root, verifiedFiles) {
  if (!Array.isArray(verifiedFiles) || verifiedFiles.length === 0) {
    throw new TypeError('verified_files must contain at least one file');
  }

  const normalizedFiles = [...new Set(verifiedFiles.map((file) => String(file).replaceAll('\\', '/')))].sort();
  const digest = createHash('sha256');
  for (const relativePath of normalizedFiles) {
    const absolutePath = path.resolve(root, relativePath);
    if (!absolutePath.startsWith(`${path.resolve(root)}${path.sep}`) || !existsSync(absolutePath)) {
      throw new TypeError(`verified file is unavailable: ${relativePath}`);
    }
    digest.update(relativePath);
    digest.update('\0');
    digest.update(readFileSync(absolutePath));
    digest.update('\0');
  }
  return digest.digest('hex');
}

export function collectMigrationVersions(root) {
  const migrationDirectory = path.join(root, 'database/migrations');
  if (!existsSync(migrationDirectory)) return [];
  return readdirSync(migrationDirectory)
    .filter((file) => file.endsWith('.sql'))
    .sort();
}

export function evaluateReleaseEvidence(evidence, options = {}) {
  const root = options.root || defaultRoot;
  const now = options.now instanceof Date ? options.now : new Date(options.now || Date.now());
  const reasons = [];
  const databaseReasons = [];
  const browserReasons = [];
  const knowledgeReasons = [];
  const source = evidence && typeof evidence === 'object' ? evidence : {};

  if (!evidence || typeof evidence !== 'object') reasons.push('release_evidence_missing');
  if (source.schema_version !== '1.0') reasons.push('release_evidence_schema_invalid');

  const generatedAt = Date.parse(source.generated_at);
  const expiresAt = Date.parse(source.expires_at);
  if (!Number.isFinite(generatedAt)) reasons.push('release_evidence_generated_at_invalid');
  if (!Number.isFinite(expiresAt)) reasons.push('release_evidence_expires_at_invalid');
  if (Number.isFinite(generatedAt) && generatedAt > now.getTime()) reasons.push('release_evidence_generated_in_future');
  if (Number.isFinite(expiresAt) && expiresAt <= now.getTime()) reasons.push('release_evidence_expired');

  try {
    const currentDigest = calculateCodeDigest(root, source.verified_files);
    if (source.code_digest !== currentDigest) reasons.push('code_digest_mismatch');
  } catch {
    reasons.push('verified_files_invalid');
  }

  const currentMigrations = collectMigrationVersions(root);
  if (!sameStringArray(source.migration_versions, currentMigrations)) {
    reasons.push('migration_versions_mismatch');
  }

  const tests = source.tests || {};
  const testTotalsValid = Number.isInteger(tests.total) && tests.total > 0
    && Number.isInteger(tests.passed) && Number.isInteger(tests.failed)
    && tests.passed === tests.total && tests.failed === 0;
  if (!testTotalsValid) reasons.push('test_totals_invalid');
  if (typeof source.asset_release_id !== 'string' || source.asset_release_id.trim() === '') {
    reasons.push('asset_release_id_missing');
  }

  const database = source.database_integration;
  if (!database || typeof database !== 'object') {
    databaseReasons.push('database_integration_missing');
  } else {
    if (database.status !== 'passed') databaseReasons.push('database_integration_failed');
    if (database.migration_verified !== true) databaseReasons.push('migration_verification_missing');
    if (!Number.isFinite(Date.parse(database.verified_at))) databaseReasons.push('database_verified_at_invalid');
  }

  const browser = source.browser_integration;
  if (!browser || typeof browser !== 'object') {
    browserReasons.push('browser_integration_missing');
  } else {
    if (browser.status !== 'passed') browserReasons.push('browser_integration_failed');
    if (!Number.isFinite(Date.parse(browser.verified_at))) browserReasons.push('browser_verified_at_invalid');
    const roleFlows = Array.isArray(browser.role_flows) ? browser.role_flows : [];
    const missingRoles = REQUIRED_ROLE_FLOWS.filter((role) => !roleFlows.includes(role));
    if (missingRoles.length > 0) browserReasons.push(`role_flows_missing:${missingRoles.join(',')}`);
  }

  const knowledge = source.knowledge_database;
  if (!knowledge || typeof knowledge !== 'object') {
    knowledgeReasons.push('knowledge_database_missing');
  } else {
    if (knowledge.status !== 'passed') knowledgeReasons.push('knowledge_database_failed');
    if (!nonNegativeInteger(knowledge.record_count) || !nonNegativeInteger(knowledge.visible_count)
      || !nonNegativeInteger(knowledge.transitional_count) || !nonNegativeInteger(knowledge.unmapped_count)
      || !nonNegativeInteger(knowledge.current_version_count) || !nonNegativeInteger(knowledge.review_record_count)) {
      knowledgeReasons.push('knowledge_counts_invalid');
    }
    if (knowledge.transitional_count !== 0) knowledgeReasons.push('knowledge_transitional_records_present');
    if (knowledge.unmapped_count !== 0) knowledgeReasons.push('knowledge_unmapped_records_present');
    if (knowledge.visible_count !== knowledge.record_count) knowledgeReasons.push('knowledge_visible_records_incomplete');
    if (knowledge.current_version_count !== knowledge.record_count) knowledgeReasons.push('knowledge_current_versions_incomplete');
    if (knowledge.review_record_count !== knowledge.record_count) knowledgeReasons.push('knowledge_review_records_incomplete');
    if (typeof knowledge.taxonomy_mapping_version !== 'string' || knowledge.taxonomy_mapping_version.trim() === '') {
      knowledgeReasons.push('knowledge_taxonomy_mapping_version_missing');
    }
  }

  return {
    current: reasons.length === 0,
    reasons,
    database: { passed: databaseReasons.length === 0, reasons: databaseReasons },
    browser: { passed: browserReasons.length === 0, reasons: browserReasons },
    knowledge_database: { passed: knowledgeReasons.length === 0, reasons: knowledgeReasons },
    summary: {
      test_totals: source.tests || null,
      migration_verified: source.database_integration?.migration_verified === true,
      migration_count: Array.isArray(source.migration_versions) ? source.migration_versions.length : 0,
      knowledge_counts: source.knowledge_database ? {
        record_count: source.knowledge_database.record_count,
        visible_count: source.knowledge_database.visible_count,
        transitional_count: source.knowledge_database.transitional_count,
        unmapped_count: source.knowledge_database.unmapped_count,
        current_version_count: source.knowledge_database.current_version_count,
        review_record_count: source.knowledge_database.review_record_count,
        taxonomy_mapping_version: source.knowledge_database.taxonomy_mapping_version,
      } : null,
      role_coverage: Array.isArray(source.browser_integration?.role_flows) ? source.browser_integration.role_flows : [],
      asset_release_id: source.asset_release_id || null,
    },
  };
}

export function aggregateReleaseChecks(checks) {
  const normalizedChecks = [...checks];
  for (const name of REQUIRED_RELEASE_CHECKS) {
    if (!normalizedChecks.some((item) => item.name === name)) {
      normalizedChecks.push({ name, passed: false, detail: { reasons: ['required_check_missing'] } });
    }
  }
  return {
    ready_for_release: normalizedChecks.length > 0 && normalizedChecks.every((item) => item.passed === true),
    checks: normalizedChecks,
  };
}

export function isIntegrationVerified(requested, evidenceResult) {
  return requested === true && evidenceResult.current === true
    && evidenceResult.database.passed === true && evidenceResult.browser.passed === true;
}

export function createReleaseArtifact(report, options = {}) {
  const summary = report?.evidence_summary || {};
  return {
    schema_version: '1.0',
    generated_at: options.generatedAt || new Date().toISOString(),
    ready_for_release: report?.ready_for_release === true,
    integration_verified: report?.integration_verified === true,
    test_totals: summary.test_totals || null,
    migration: {
      verified: summary.migration_verified === true,
      count: Number.isInteger(summary.migration_count) ? summary.migration_count : 0,
    },
    knowledge_counts: summary.knowledge_counts || null,
    role_coverage: Array.isArray(summary.role_coverage) ? summary.role_coverage : [],
    asset_release_id: summary.asset_release_id || null,
    checks: Array.isArray(report?.checks) ? report.checks : [],
  };
}

function sameStringArray(actual, expected) {
  return Array.isArray(actual)
    && actual.every((value) => typeof value === 'string')
    && actual.length === expected.length
    && [...actual].sort().every((value, index) => value === expected[index]);
}

function nonNegativeInteger(value) {
  return Number.isInteger(value) && value >= 0;
}

function addCheck(checks, name, passed, detail) {
  checks.push({ name, passed, detail });
}

function evidencePathFromArgs(args) {
  const inline = args.find((argument) => argument.startsWith('--evidence='));
  if (inline) return inline.slice('--evidence='.length);
  const index = args.indexOf('--evidence');
  if (index !== -1) return args[index + 1] || '';
  return process.env.RELEASE_EVIDENCE_PATH || '';
}

function readEvidence(root, args) {
  const evidencePath = evidencePathFromArgs(args);
  if (!evidencePath) return { evidence: null, source: null, error: null };
  try {
    const absolutePath = path.resolve(root, evidencePath);
    return {
      evidence: JSON.parse(readFileSync(absolutePath, 'utf8')),
      source: path.relative(root, absolutePath),
      error: null,
    };
  } catch (error) {
    return {
      evidence: null,
      source: evidencePath,
      error: error instanceof Error ? error.message : String(error),
    };
  }
}

export function runUnifiedReleaseGate(options = {}) {
  const root = options.root || defaultRoot;
  const args = options.args || [];
  const integrationRequested = args.includes('--integration-verified');
  const checks = [];
  const packagePath = path.join(root, 'database/import_data/knowledge-cards-phase2.isolated-package.json');
  const packageData = JSON.parse(readFileSync(packagePath, 'utf8'));
  const knowledgePage = readFileSync(path.join(root, 'knowledge/index.html'), 'utf8');
  const learningPage = readFileSync(path.join(root, 'learning/index.html'), 'utf8');

  const routeResults = [
    'internal.html', 'knowledge/index.html', 'learning/index.html', 'search.html',
    'api/search/global.php', 'api/admin/knowledge/index.php', 'lesson-review.html',
  ].map((route) => ({ route, exists: existsSync(path.join(root, route)) }));
  for (const result of routeResults) addCheck(checks, `route:${result.route}`, result.exists, result);

  const knowledgePageVisible = ['知识中心', '专业知识', '销售知识'].every((text) => knowledgePage.includes(text));
  const learningPageVisible = ['学习中心', '正式教案库', '审核任务', '继续学习'].every((text) => learningPage.includes(text));
  addCheck(checks, 'knowledge-page-visible', knowledgePageVisible, { page: 'knowledge/index.html' });
  addCheck(checks, 'learning-page-visible', learningPageVisible, { page: 'learning/index.html' });
  addCheck(checks, 'page-contract', routeResults.every(({ exists }) => exists) && knowledgePageVisible && learningPageVisible, {
    routes: routeResults,
    knowledge_page_visible: knowledgePageVisible,
    learning_page_visible: learningPageVisible,
  });

  const records = Array.isArray(packageData.records) ? packageData.records : [];
  const countValid = packageData.record_count === 1417 && records.length === 1417;
  const metadataValid = records.every((record) => {
    const metadata = record.metadata || {};
    return Array.isArray(metadata.target_roles) && Array.isArray(metadata.target_stages)
      && Number.isInteger(metadata.difficulty) && Array.isArray(metadata.related_content);
  });
  const mappingValid = records.every((record) => record.domain_mapping_status === 'mapped');
  const statusCounts = Object.create(null);
  for (const record of records) {
    const status = String(record.publication_status || 'unknown');
    statusCounts[status] = (statusCounts[status] || 0) + 1;
  }
  addCheck(checks, 'knowledge-card-count', countValid, { expected: 1417, actual: records.length });
  addCheck(checks, 'knowledge-card-metadata', metadataValid, { valid: metadataValid });
  addCheck(checks, 'domain-mapping', mappingValid, { valid: mappingValid });
  addCheck(checks, 'knowledge-package-state', countValid && metadataValid && mappingValid, {
    source: path.relative(root, packagePath),
    record_count: records.length,
    publication_status_counts: statusCounts,
    versioned_count: records.filter((record) => record.version_id != null).length,
  });

  const tests = [
    'scripts/internal_entry_contract.test.mjs',
    'scripts/internal_knowledge_visibility.test.mjs',
    'scripts/knowledge_taxonomy_contract.test.mjs',
    'scripts/content_source_index_contract.test.mjs',
    'scripts/global_search_contract.test.mjs',
    'scripts/knowledge_admin_contract.test.mjs',
    'scripts/knowledge_card_release_gate.test.mjs',
  ];
  const testResult = spawnSync(process.execPath, ['--test', ...tests], { cwd: root, encoding: 'utf8' });
  addCheck(checks, 'contract-tests', testResult.status === 0, {
    status: testResult.status,
    error: testResult.status === 0 ? null : (testResult.stderr || 'failed').trim(),
  });

  const loadedEvidence = options.evidence === undefined ? readEvidence(root, args) : {
    evidence: options.evidence,
    source: options.evidenceSource || 'provided',
    error: null,
  };
  const reviewReportPath = path.join(root, 'database/import_data/knowledge-cards-phase2.taxonomy-review-report.json');
  const phpResult = spawnSync('php', [
    'scripts/knowledge_card_release_gate.php', packagePath, '1417', reviewReportPath, '-',
  ], {
    cwd: root,
    encoding: 'utf8',
    input: loadedEvidence.evidence ? JSON.stringify(loadedEvidence.evidence) : '',
  });
  addCheck(checks, 'knowledge-release-gate', phpResult.status === 0, {
    status: phpResult.status,
    report: phpResult.stdout.trim() === '' ? null : JSON.parse(phpResult.stdout),
    error: phpResult.stderr.trim() || null,
  });

  const evidenceResult = evaluateReleaseEvidence(loadedEvidence.evidence, { root, now: options.now });
  if (loadedEvidence.error) evidenceResult.reasons.push('release_evidence_unreadable');
  evidenceResult.current = evidenceResult.reasons.length === 0;

  addCheck(checks, 'release-evidence-current', evidenceResult.current, {
    source: loadedEvidence.source,
    reasons: evidenceResult.reasons,
  });
  addCheck(checks, 'database-integration', evidenceResult.database.passed, evidenceResult.database);
  addCheck(checks, 'browser-integration', evidenceResult.browser.passed, evidenceResult.browser);
  addCheck(checks, 'knowledge-database-state', evidenceResult.knowledge_database.passed, evidenceResult.knowledge_database);

  const integrationVerified = isIntegrationVerified(integrationRequested, evidenceResult);
  addCheck(checks, 'integration-verification', integrationVerified, {
    requested: integrationRequested,
    evidence_current: evidenceResult.current,
    database_verified: evidenceResult.database.passed,
    browser_verified: evidenceResult.browser.passed,
  });

  return {
    ...aggregateReleaseChecks(checks),
    integration_verified: integrationVerified,
    evidence_source: loadedEvidence.source,
    evidence_summary: evidenceResult.summary,
    release_artifact: createReleaseArtifact({
      ready_for_release: checks.every(({ passed }) => passed === true),
      integration_verified: integrationVerified,
      evidence_summary: evidenceResult.summary,
      checks,
    }),
    knowledge_state: {
      repository_package: checks.find(({ name }) => name === 'knowledge-package-state').detail,
      integration_database: evidenceResult.summary.knowledge_counts,
    },
  };
}

function main() {
  const report = runUnifiedReleaseGate({ args: process.argv.slice(2) });
  console.log(JSON.stringify(report, null, 2));
  process.exitCode = report.ready_for_release ? 0 : 1;
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  try {
    main();
  } catch (error) {
    process.stderr.write(`${error instanceof Error ? error.message : String(error)}\n`);
    process.exitCode = 1;
  }
}
