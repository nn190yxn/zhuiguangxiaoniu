#!/usr/bin/env node

import { existsSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

import { buildPlatformInventory } from './platform_inventory.mjs';

const currentFile = fileURLToPath(import.meta.url);
const defaultProjectRoot = resolve(dirname(currentFile), '..');
const lifecycleValues = new Set(['planned', 'in_development', 'implemented', 'deployed', 'verified', 'deprecated']);
const releaseVerificationValues = new Set(['approval_required', 'blocked_external', 'verified']);

function item(id, lifecycle, targetLifecycle, surfaces, test, evidence, productionPath, releaseVerification = 'approval_required') {
  return Object.freeze({
    id,
    lifecycle,
    target_lifecycle: targetLifecycle,
    surfaces: Object.freeze(surfaces.split(',')),
    executable_items: Object.freeze([evidence]),
    automated_tests: Object.freeze([test]),
    static_evidence: Object.freeze([evidence]),
    production_paths: Object.freeze([productionPath]),
    release_verification: releaseVerification,
  });
}

export const functionCoverage = Object.freeze([
  item('WEB-001', 'deployed', 'verified', 'public_web', 'scripts/platform_inventory.test.mjs', 'index.html', '/'),
  item('WEB-002', 'deployed', 'verified', 'public_web', 'scripts/platform_inventory.test.mjs', 'stores', '/stores/'),
  item('WEB-003', 'deployed', 'verified', 'public_web', 'scripts/platform_inventory.test.mjs', 'courses', '/courses/'),
  item('WEB-004', 'deployed', 'verified', 'public_web', 'scripts/platform_inventory.test.mjs', 'news', '/news/'),
  item('WEB-005', 'deployed', 'verified', 'public_web,employee_web', 'scripts/platform_contract_snapshot.test.mjs', 'training', '/training/'),
  item('WEB-006', 'deployed', 'verified', 'public_web,employee_web', 'scripts/platform_contract_snapshot.test.mjs', 'docs', '/docs/'),
  item('WEB-007', 'deployed', 'verified', 'employee_web,pwa', 'scripts/mobile_pwa_shell.test.mjs', 'internal.html', '/internal.html'),
  item('WEB-008', 'deployed', 'verified', 'public_web,employee_web,php_api', 'scripts/platform_contract_snapshot.test.mjs', 'api/search/global.php', '/api/search/global.php'),
  item('WEB-009', 'deployed', 'verified', 'admin', 'scripts/platform_health.test.mjs', 'admin/dashboard.html', '/admin/dashboard.html'),
  item('WEB-010', 'deployed', 'verified', 'admin', 'scripts/platform_health.test.mjs', 'admin/system-dashboard.html', '/admin/system-dashboard.html'),
  item('WEB-011', 'deployed', 'verified', 'public_web,employee_web', 'scripts/platform_api_compatibility.test.mjs', 'profile.html', '/profile.html'),
  item('WEB-012', 'deployed', 'verified', 'public_web', 'scripts/platform_inventory.test.mjs', 'showcase/index.html', '/showcase/'),

  item('IAM-001', 'deployed', 'verified', 'employee_web,pwa,mini_program,php_api', 'scripts/platform_auth_context.test.mjs', 'api/auth-jwt.php', '/api/auth-jwt.php'),
  item('IAM-002', 'deployed', 'verified', 'pwa,mini_program,php_api', 'scripts/platform_session_service.test.mjs', 'api/auth/mini-program-session.php', '/api/auth/mini-program-session.php'),
  item('IAM-003', 'deployed', 'verified', 'employee_web,mini_program,php_api', 'scripts/miniprogram_wecom_release.test.mjs', 'api/wecom/status.php', '/api/wecom/status.php', 'blocked_external'),
  item('IAM-004', 'implemented', 'verified', 'employee_web,pwa,mini_program,php_api', 'scripts/platform_auth_context.test.mjs', 'api/auth/me.php', '/api/auth/me.php'),
  item('IAM-005', 'implemented', 'verified', 'admin,php_api', 'scripts/admin_permission_service.test.mjs', 'api/admin/common.php', '/api/admin/'),
  item('IAM-006', 'deployed', 'verified', 'admin,pwa,php_api', 'scripts/staff_directory_service.test.mjs', 'api/admin/staff/list.php', '/api/admin/staff/'),
  item('IAM-007', 'deployed', 'verified', 'admin,php_api', 'scripts/staff_lifecycle_service.test.mjs', 'api/admin/services/StaffLifecycleService.php', '/api/admin/staff/'),
  item('IAM-008', 'deployed', 'verified', 'admin,php_api', 'scripts/staff_offboard_service.test.mjs', 'api/admin/services/StaffLifecycleService.php', '/api/admin/staff/'),
  item('IAM-009', 'deployed', 'verified', 'admin,php_api', 'scripts/organization_integration.test.mjs', 'api/admin/organization/tree.php', '/api/admin/organization/'),
  item('IAM-010', 'deployed', 'verified', 'admin,php_api', 'scripts/staff_data_health_service.test.mjs', 'api/admin/staff/data-health.php', '/api/admin/staff/data-health.php'),

  item('BIZ-001', 'deployed', 'verified', 'pwa,mini_program,php_api', 'scripts/workload_platform_adapter.test.mjs', 'api/workload/save-report.php', '/api/workload/'),
  item('BIZ-002', 'deployed', 'verified', 'pwa,mini_program,admin,php_api', 'scripts/platform_file_assets.test.mjs', 'api/workload/evidence-upload.php', '/api/workload/'),
  item('BIZ-003', 'deployed', 'verified', 'admin,php_api', 'scripts/workload_admin_ui.test.mjs', 'admin/workload.html', '/admin/workload.html'),
  item('BIZ-004', 'deployed', 'verified', 'admin,php_api,worker', 'scripts/workload_export_jobs.test.mjs', 'api/workload/export-worker.php', '/api/workload/'),
  item('BIZ-005', 'deployed', 'verified', 'admin,php_api', 'scripts/workload_standard_management.test.mjs', 'api/workload/services/WorkloadRoleRuleVersionService.php', '/api/workload/'),
  item('BIZ-006', 'deployed', 'verified', 'pwa,mini_program,admin,php_api,worker', 'scripts/drill_employee_api_contract.test.mjs', 'api/drill/v2/home.php', '/api/drill/v2/'),
  item('BIZ-007', 'deployed', 'verified', 'employee_web,php_api', 'scripts/drill_legacy_baseline.test.mjs', 'api/drill', '/api/drill/'),
  item('BIZ-008', 'deployed', 'verified', 'pwa,mini_program,admin,php_api', 'scripts/drill_media_services.test.mjs', 'api/drill/v2/audio-access.php', '/api/drill/v2/audio-access.php'),
  item('BIZ-009', 'deployed', 'verified', 'employee_web,mini_program,php_api,worker', 'scripts/platform_operational_domain_migration.test.mjs', 'api/skill/upload-recording.php', '/api/skill/'),
  item('BIZ-010', 'implemented', 'verified', 'admin,php_api', 'scripts/recruitment_platform_adapter.test.mjs', 'api/admin/recruitment/requirements.php', '/api/admin/recruitment/'),
  item('BIZ-011', 'implemented', 'verified', 'admin,php_api,worker', 'scripts/recruitment_resume_pipeline.test.mjs', 'api/recruitment/resume-worker.php', '/api/recruitment/', 'blocked_external'),
  item('BIZ-012', 'implemented', 'verified', 'admin,php_api', 'scripts/recruitment_resume_review.test.mjs', 'api/admin/recruitment/candidates.php', '/api/admin/recruitment/'),
  item('BIZ-013', 'implemented', 'verified', 'admin,php_api', 'scripts/recruitment_resume_foundation.test.mjs', 'api/admin/recruitment/hire-to-employee.php', '/api/admin/recruitment/', 'blocked_external'),
  item('BIZ-014', 'deployed', 'verified', 'employee_web,pwa,mini_program,php_api', 'scripts/platform_business_domain_migration.test.mjs', 'api/learning/lesson.php', '/api/learning/'),
  item('BIZ-015', 'deployed', 'verified', 'employee_web,pwa,mini_program,php_api', 'scripts/platform_business_domain_migration.test.mjs', 'api/knowledge/list.php', '/api/knowledge/'),
  item('BIZ-016', 'deployed', 'verified', 'mini_program,employee_web,php_api', 'scripts/platform_business_domain_migration.test.mjs', 'api/exam/save.php', '/api/exam/'),
  item('BIZ-017', 'deployed', 'verified', 'pwa,mini_program,php_api', 'scripts/platform_contract_snapshot.test.mjs', 'api/pass/map.php', '/api/pass/'),
  item('BIZ-018', 'deployed', 'verified', 'pwa,mini_program,php_api', 'scripts/platform_business_domain_migration.test.mjs', 'api/policy/notify.php', '/api/policy/'),
  item('BIZ-019', 'deployed', 'verified', 'public_web,mini_program,php_api', 'scripts/platform_operational_domain_migration.test.mjs', 'api/survey/list.php', '/api/survey/'),
  item('BIZ-020', 'deployed', 'verified', 'public_web,php_api', 'scripts/platform_operational_domain_migration.test.mjs', 'api/campaign/list.php', '/api/campaign/'),
  item('BIZ-021', 'deployed', 'verified', 'public_web,php_api', 'scripts/platform_operational_domain_migration.test.mjs', 'api/summer-camp/assessment-api.php', '/api/summer-camp/', 'blocked_external'),
  item('BIZ-022', 'deployed', 'verified', 'public_web,pwa,php_api', 'scripts/fitness_assessment_ocr.test.mjs', 'api/ai-services.php', '/api/ai-services.php', 'blocked_external'),
  item('BIZ-023', 'deployed', 'verified', 'pwa,mini_program,php_api', 'scripts/platform_contract_snapshot.test.mjs', 'api/points/index.php', '/api/points/'),
  item('BIZ-024', 'deployed', 'verified', 'employee_web,admin,php_api', 'scripts/platform_contract_snapshot.test.mjs', 'api/statistics/staff.php', '/api/statistics/'),

  item('MSG-001', 'deployed', 'verified', 'admin,worker,php_api', 'scripts/platform_operational_domain_migration.test.mjs', 'api/wecom/sync-members.php', '/api/wecom/', 'blocked_external'),
  item('MSG-002', 'deployed', 'verified', 'worker,php_api', 'scripts/miniprogram_wecom_release.test.mjs', 'api/wecom/retry-message.php', '/api/wecom/', 'blocked_external'),
  item('MSG-003', 'deployed', 'verified', 'admin,pwa,worker,php_api', 'scripts/workload_reminder_role_coverage.test.mjs', 'api/reminder/jobs.php', '/api/reminder/'),
  item('MSG-004', 'implemented', 'verified', 'pwa,mini_program,php_api', 'scripts/miniprogram_static_contract.test.mjs', 'mini-program/pages/notifications/list.js', '/api/notifications/'),
  item('MSG-005', 'deployed', 'verified', 'employee_web,pwa,mini_program,php_api', 'scripts/platform_contract_snapshot.test.mjs', 'api/todos/my.php', '/api/todos/'),
  item('MSG-006', 'implemented', 'verified', 'mini_program,php_api', 'scripts/miniprogram_wecom_release.test.mjs', 'mini-program/app.js', 'external:wechat-subscribe-message', 'blocked_external'),

  item('CLIENT-001', 'implemented', 'verified', 'pwa', 'scripts/mobile_pwa_shell.test.mjs', 'manifest.webmanifest', '/manifest.webmanifest'),
  item('CLIENT-002', 'implemented', 'verified', 'pwa', 'scripts/service_worker_policy.test.mjs', 'sw.js', '/sw.js'),
  item('CLIENT-003', 'implemented', 'verified', 'pwa', 'scripts/mobile_pwa_shell.test.mjs', 'mobile/index.html', '/mobile/'),
  item('CLIENT-004', 'planned', 'planned', 'pwa', 'scripts/mobile_pwa_shell.test.mjs', 'mobile/index.html', '/mobile/'),
  item('CLIENT-005', 'implemented', 'verified', 'pwa', 'scripts/pwa_draft_store.test.mjs', 'js/draft-store.js', '/mobile/'),
  item('CLIENT-006', 'implemented', 'verified', 'mini_program', 'scripts/miniprogram_static_contract.test.mjs', 'mini-program/app.json', 'external:wechat-mini-program', 'blocked_external'),
  item('CLIENT-007', 'implemented', 'verified', 'employee_web', 'scripts/platform_inventory.test.mjs', 'mobile', '/mobile/'),
  item('CLIENT-008', 'implemented', 'verified', 'mini_program', 'scripts/miniprogram_api_client.test.mjs', 'mini-program/utils/api.js', 'external:wechat-mini-program', 'blocked_external'),
  item('CLIENT-009', 'implemented', 'verified', 'mini_program', 'scripts/platform_multiclient_state.property.test.mjs', 'mini-program/pages/workload/index.js', 'external:wechat-mini-program', 'blocked_external'),
  item('CLIENT-010', 'implemented', 'verified', 'mini_program', 'scripts/mini_program_drill_v2.test.mjs', 'mini-program/pages/drill/list/list.js', 'external:wechat-mini-program', 'blocked_external'),
  item('CLIENT-011', 'implemented', 'verified', 'mini_program', 'scripts/miniprogram_static_contract.test.mjs', 'mini-program/pages/learning/list.js', 'external:wechat-mini-program', 'blocked_external'),
  item('CLIENT-012', 'implemented', 'verified', 'mini_program', 'scripts/miniprogram_wecom_release.test.mjs', 'mini-program/project.config.json', 'external:wechat-developer-tools', 'blocked_external'),

  item('PLATFORM-001', 'implemented', 'verified', 'php_api', 'scripts/platform_api_kernel.test.mjs', 'api/kernel/bootstrap.php', '/api/'),
  item('PLATFORM-002', 'implemented', 'verified', 'php_api', 'scripts/platform_legacy_endpoint_governance.test.mjs', 'api/platform/LegacyEndpointGovernance.php', '/api/'),
  item('PLATFORM-003', 'implemented', 'verified', 'php_api,worker', 'scripts/platform_api_kernel.property.test.mjs', 'api/drill/v2/services/DrillIdempotencyService.php', '/api/'),
  item('PLATFORM-004', 'implemented', 'verified', 'php_api,worker', 'scripts/migration_readiness.test.mjs', 'database/MigrationReadiness.php', 'external:mysql-migration', 'blocked_external'),
  item('PLATFORM-005', 'implemented', 'verified', 'php_api,worker', 'scripts/platform_health.test.mjs', 'api/config.php', '/api/platform/health.php'),
  item('PLATFORM-006', 'deployed', 'verified', 'php_api,worker', 'scripts/ai_runtime_convergence.test.mjs', 'api/ai-runtime.php', 'external:ai-text-provider', 'blocked_external'),
  item('PLATFORM-007', 'deployed', 'verified', 'php_api,worker', 'scripts/platform_ai_capability.test.mjs', 'api/ai-services.php', 'external:ai-vision-provider', 'blocked_external'),
  item('PLATFORM-008', 'deployed', 'verified', 'php_api,worker', 'scripts/platform_ai_capability.test.mjs', 'api/ai-services.php', 'external:ocr-provider', 'blocked_external'),
  item('PLATFORM-009', 'deployed', 'verified', 'php_api,worker', 'scripts/platform_ai_capability.test.mjs', 'api/drill/v2/services/DrillAiAdapter.php', 'external:speech-provider', 'blocked_external'),
  item('PLATFORM-010', 'planned', 'planned', 'php_api', 'scripts/platform_ai_capability.test.mjs', 'api/platform/AiCapabilityGateway.php', 'external:image-generation-provider', 'approval_required'),
  item('PLATFORM-011', 'deployed', 'verified', 'php_api,worker', 'scripts/platform_private_file_storage.test.mjs', 'api/platform/PrivateFileStorage.php', '/api/platform/files/'),
  item('PLATFORM-012', 'deployed', 'verified', 'worker', 'scripts/platform_job_runner.test.mjs', 'scripts/platform-job-worker.php', 'external:production-worker', 'blocked_external'),
  item('PLATFORM-013', 'deployed', 'verified', 'cron,worker', 'scripts/platform_job_dispatcher.test.mjs', 'cron_monthly_stats.php', 'external:production-cron', 'blocked_external'),
  item('PLATFORM-014', 'deployed', 'verified', 'php_api,worker', 'scripts/platform_api_kernel.test.mjs', 'api/kernel/ApiLogger.php', '/api/'),
  item('PLATFORM-015', 'implemented', 'verified', 'admin,php_api', 'scripts/platform_health.test.mjs', 'api/platform/health.php', '/api/platform/health.php'),
  item('PLATFORM-016', 'implemented', 'verified', 'worker', 'scripts/migration_readiness.test.mjs', 'database/MigrationReadiness.php', 'external:backup-restore-drill', 'blocked_external'),
  item('PLATFORM-017', 'implemented', 'verified', 'admin,worker', 'scripts/platform_release_gate.test.mjs', 'scripts/platform_release_gate.mjs', 'external:production-release', 'approval_required'),

  item('FASTAPI-001', 'deployed', 'verified', 'fastapi', 'scripts/platform_inventory.test.mjs', 'external:fastapi:/api/v1/auth', 'external:fastapi:/api/v1/auth', 'blocked_external'),
  item('FASTAPI-002', 'deployed', 'verified', 'fastapi', 'scripts/platform_inventory.test.mjs', 'external:fastapi:/api/v1/children', 'external:fastapi:/api/v1/children', 'blocked_external'),
  item('FASTAPI-003', 'deployed', 'verified', 'fastapi', 'scripts/platform_inventory.test.mjs', 'external:fastapi:/api/v1/chat', 'external:fastapi:/api/v1/chat', 'blocked_external'),
  item('FASTAPI-004', 'deployed', 'verified', 'fastapi', 'scripts/platform_inventory.test.mjs', 'external:fastapi:/api/v1/assessments', 'external:fastapi:/api/v1/assessments', 'blocked_external'),
  item('FASTAPI-005', 'deployed', 'verified', 'fastapi', 'scripts/platform_inventory.test.mjs', 'external:fastapi:/api/v1/domain-services', 'external:fastapi:/api/v1/domain-services', 'blocked_external'),
  item('FASTAPI-006', 'deployed', 'verified', 'fastapi', 'scripts/platform_inventory.test.mjs', 'external:fastapi:/api/v1/kb', 'external:fastapi:/api/v1/kb', 'blocked_external'),
  item('FASTAPI-007', 'deployed', 'verified', 'fastapi', 'scripts/platform_inventory.test.mjs', 'external:fastapi:/api/v1/payment-share', 'external:fastapi:/api/v1/payment-share', 'blocked_external'),
  item('FASTAPI-008', 'deployed', 'verified', 'fastapi', 'scripts/platform_inventory.test.mjs', 'external:fastapi:/uploads', 'external:fastapi:/uploads', 'blocked_external'),
]);

function isExternal(reference) {
  return reference.startsWith('external:');
}

export function validateFunctionCoverage(coverage, inventory, { projectRoot = defaultProjectRoot } = {}) {
  const issues = [];
  const ids = coverage.map(({ id }) => id);
  const inventoryIds = inventory.groups.map(({ id }) => id);
  const inventoryById = new Map(inventory.groups.map((group) => [group.id, group]));
  if (coverage.length !== 89) issues.push({ code: 'COVERAGE_COUNT', expected: 89, actual: coverage.length });
  if (new Set(ids).size !== ids.length) issues.push({ code: 'DUPLICATE_COVERAGE_ID' });
  for (const id of inventoryIds.filter((id) => !ids.includes(id))) issues.push({ code: 'MISSING_COVERAGE_ID', id });
  for (const id of ids.filter((id) => !inventoryById.has(id))) issues.push({ code: 'UNKNOWN_COVERAGE_ID', id });

  for (const entry of coverage) {
    if (!lifecycleValues.has(entry.lifecycle) || !lifecycleValues.has(entry.target_lifecycle)) {
      issues.push({ code: 'INVALID_COVERAGE_LIFECYCLE', id: entry.id });
    }
    if (inventoryById.has(entry.id) && inventoryById.get(entry.id).lifecycle !== entry.lifecycle) {
      issues.push({ code: 'COVERAGE_LIFECYCLE_DRIFT', id: entry.id, expected: inventoryById.get(entry.id).lifecycle, actual: entry.lifecycle });
    }
    if (!releaseVerificationValues.has(entry.release_verification)) {
      issues.push({ code: 'INVALID_RELEASE_VERIFICATION', id: entry.id, status: entry.release_verification });
    }
    for (const field of ['surfaces', 'executable_items', 'automated_tests', 'static_evidence', 'production_paths']) {
      if (!Array.isArray(entry[field]) || entry[field].length === 0) issues.push({ code: 'MISSING_COVERAGE_FIELD', id: entry.id, field });
    }
    const references = new Set([...(entry.executable_items || []), ...(entry.static_evidence || []), ...(entry.automated_tests || [])]);
    for (const reference of references) {
      if (!isExternal(reference) && !existsSync(join(projectRoot, reference))) {
        issues.push({ code: 'MISSING_COVERAGE_EVIDENCE', id: entry.id, path: reference });
      }
    }
    for (const testPath of entry.automated_tests || []) {
      if (!testPath.endsWith('.test.mjs')) issues.push({ code: 'INVALID_AUTOMATED_TEST', id: entry.id, path: testPath });
    }
    if ((entry.executable_items || []).some(isExternal) && entry.release_verification !== 'blocked_external') {
      issues.push({ code: 'EXTERNAL_BOUNDARY_NOT_BLOCKED', id: entry.id });
    }
  }
  return issues;
}

export function summarizeFunctionCoverage(coverage) {
  const countBy = (field) => Object.fromEntries(
    [...new Set(coverage.map((entry) => entry[field]))].sort().map((value) => [value, coverage.filter((entry) => entry[field] === value).length]),
  );
  return {
    coverage_group_count: coverage.length,
    coverage_test_file_count: new Set(coverage.flatMap(({ automated_tests: tests }) => tests)).size,
    coverage_lifecycle_counts: countBy('lifecycle'),
    coverage_target_lifecycle_counts: countBy('target_lifecycle'),
    coverage_release_verification_counts: countBy('release_verification'),
  };
}

export function summarizeNodeTestRun(output, exitCode, testFileCount, coveredGroupCount) {
  const metric = (name) => Number(output.match(new RegExp(`^# ${name} (\\d+)$`, 'm'))?.[1] || 0);
  return {
    status: exitCode === 0 ? 'passed' : 'failed',
    exit_code: exitCode,
    covered_group_count: coveredGroupCount,
    test_file_count: testFileCount,
    test_count: metric('tests'),
    passed_test_count: metric('pass'),
    failed_test_count: metric('fail'),
    skipped_test_count: metric('skipped'),
  };
}

export function runLocalCoverageTests({ projectRoot = defaultProjectRoot, coverage = functionCoverage } = {}) {
  const tests = [...new Set(coverage.flatMap(({ automated_tests: paths }) => paths))].sort();
  const result = spawnSync(process.execPath, ['--test', ...tests], {
    cwd: projectRoot,
    encoding: 'utf8',
    maxBuffer: 64 * 1024 * 1024,
  });
  return {
    ...summarizeNodeTestRun(`${result.stdout || ''}\n${result.stderr || ''}`, result.status ?? 1, tests.length, coverage.length),
    output: `${result.stdout || ''}${result.stderr || ''}`,
  };
}

if (process.argv[1] && resolve(process.argv[1]) === currentFile) {
  const inventory = buildPlatformInventory({ projectRoot: defaultProjectRoot });
  const issues = validateFunctionCoverage(functionCoverage, inventory, { projectRoot: defaultProjectRoot });
  if (issues.length > 0) {
    process.stdout.write(`${JSON.stringify({ status: 'failed', issues }, null, 2)}\n`);
    process.exitCode = 1;
  } else if (process.argv.includes('--run-local')) {
    const result = runLocalCoverageTests();
    process.stdout.write(`${JSON.stringify({ ...result, output: undefined }, null, 2)}\n`);
    if (result.status === 'failed') process.exitCode = 1;
  } else {
    process.stdout.write(`${JSON.stringify({ status: 'passed', ...summarizeFunctionCoverage(functionCoverage) }, null, 2)}\n`);
  }
}
