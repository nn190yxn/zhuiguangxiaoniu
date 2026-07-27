import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const [service, endpoint] = await Promise.all([
  readFile(new URL('../api/workload/services/WorkloadExportService.php', import.meta.url), 'utf8'),
  readFile(new URL('../api/workload/exports.php', import.meta.url), 'utf8'),
]);
const validatesCriteria = (criteria) => `[validates ${criteria.join(', ')}]`;

test(`${validatesCriteria(['6.1', '6.2', '9.1'])} standard exports reuse statistics and permission scope`, () => {
  assert.match(service, /new WorkloadStoreAnalyticsService\(\$this->pdo\)/);
  assert.match(service, /new WorkloadMetricSelectionService\(\$this->pdo\)/);
  assert.match(service, /permission_scope[\s\S]*can_export/);
  assert.match(service, /metric_version/);
  assert.match(service, /generated_at/);
  assert.match(service, /json_encode\(\$filters/);
});

test(`${validatesCriteria(['6.1'])} store completion CSV contains required status columns`, () => {
  for (const field of ['日期', '门店', '岗位', '应交', '已提交', '草稿', '缺交', '锁定缺交', '管理更正', '完成率']) {
    assert.match(service, new RegExp(`'${field}'`));
  }
  assert.match(service, /\$completed = \$group\['submitted'\] \+ \$group\['corrected'\]/);
});

test(`${validatesCriteria(['6.2'])} metric selection CSV contains selection and coverage fields`, () => {
  for (const field of ['项目编码', '项目名称', '样本量', '选取率', '有效选取率', '员工覆盖率', '门店覆盖率']) {
    assert.match(service, new RegExp(`'${field}'`));
  }
});

test(`${validatesCriteria(['5.3', '6.3', '6.4'])} full-data exports include audit and value dimensions`, () => {
  assert.match(service, /staff_full_data/);
  assert.match(service, /metric_full_dimension/);
  for (const field of ['report_status', 'audit_status', 'evidence_count', 'raw_value', 'pending_value', 'effective_value', 'rejected_value']) {
    assert.match(service, new RegExp(`'${field}'`));
  }
  assert.match(service, /staffFullDataRows/);
  assert.match(service, /metricFullDimensionRows/);
});

test(`${validatesCriteria(['5.3', '6.5'])} endpoint emits bounded UTF-8 CSV and audit metadata`, () => {
  assert.match(endpoint, /REQUEST_METHOD[\s\S]*'POST'/);
  assert.match(endpoint, /appRequireStaffContext\(\)/);
  assert.match(endpoint, /Content-Type: text\/csv/);
  assert.match(endpoint, /X-Export-Row-Count/);
  assert.match(endpoint, /workload\.export\.completed/);
  assert.ok(service.includes('fwrite($stream, "\\xEF\\xBB\\xBF")'));
  assert.ok(service.includes("preg_match('/^[=+\\-@]/'"));
});
