import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const resultService = read('../api/workload/services/WorkloadReportConversionResultService.php');
const auditService = read('../api/workload/services/WorkloadAuditTaskService.php');
const evidenceUpload = read('../api/workload/evidence-upload.php');
const evidenceDelete = read('../api/workload/evidence-delete.php');
const confirmationService = read('../api/workload/services/WorkloadManagementConfirmationService.php');
const confirmationEndpoint = read('../api/workload/management-confirmation.php');
const confirmationMigration = read('../database/migrations/202608010002_workload_management_confirmations.sql');
const manifest = read('../database/migration_manifest.php');

test('[validates 1.2-1.3, 5.1-5.4] first submission persists a rule snapshot and later recalculation reuses it', () => {
  assert.match(resultService, /existingSnapshots\(int \$reportId\)/);
  assert.match(resultService, /initialRules\(string \$roleCode, string \$businessDate\)/);
  assert.match(resultService, /rule_snapshot_json/);
  assert.match(resultService, /ON DUPLICATE KEY UPDATE/);
  assert.match(resultService, /WorkloadConversionRuleService::normalizeRule/);
});

test('[validates 5.1-5.4] conversion results separate pending, approved, rejected, and missing-evidence paths', () => {
  assert.match(resultService, /return 'rejected';/);
  assert.match(resultService, /return in_array\('pending', \$statuses, true\) \? 'pending' : 'approved';/);
  assert.match(resultService, /\['image' => 0, 'screenshot' => 0\]/);
  assert.match(resultService, /deleted_at IS NULL GROUP BY metric_code/);
  assert.match(resultService, /WorkloadConversionRuleService::evaluate/);
});

test('[validates 5.1-5.4] submission, review, supplement, and deletion recalculate within their transactions', () => {
  const recalculation = /WorkloadReportConversionResultService\(\$this->pdo\)\)->recalculate/g;
  assert.equal((auditService.match(recalculation) ?? []).length, 3);
  assert.match(evidenceUpload, /WorkloadReportConversionResultService\(\$pdo\)\)->recalculate\(\$reportId\)/);
  assert.match(evidenceDelete, /WorkloadReportConversionResultService\(\$pdo\)\)->recalculate\(\(int\) \$evidence\['report_id'\]\)/);
  assert.ok(evidenceDelete.indexOf('$pdo->beginTransaction()') < evidenceDelete.indexOf('->recalculate('));
  assert.ok(evidenceDelete.indexOf('->recalculate(') < evidenceDelete.indexOf('$pdo->commit()'));
});

test('[validates 4.1-4.4, 5.1-5.4] management confirmations are auditable evidence limited to store managers and headquarters roles', () => {
  assert.match(confirmationMigration, /CREATE TABLE IF NOT EXISTS workload_management_confirmations/);
  assert.match(confirmationMigration, /UNIQUE KEY uq_workload_management_confirmation \(report_id, metric_code\)/);
  assert.match(manifest, /'202608010002'/);
  assert.match(confirmationService, /\['manager', 'operation', 'ceo', 'admin'\]/);
  assert.match(confirmationService, /店长只能确认本门店管理动作/);
  assert.match(confirmationService, /revoked_at = NULL/);
  assert.match(confirmationService, /WorkloadReportConversionResultService\(\$this->pdo\)\)->recalculate\(\$reportId\)/);
  assert.match(resultService, /workload_management_confirmations/);
  assert.match(resultService, /manager_confirmation/);
  assert.match(confirmationEndpoint, /appRequireStaffContext\(\)/);
  assert.match(confirmationEndpoint, /\$pdo->beginTransaction\(\)/);
});
