import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const read = (path) => readFileSync(new URL(path, root), 'utf8');

test('external processors capture and enforce the full data boundary', () => {
  const migration = read('database/migrations/202607310001_recruitment_resume_screening.sql');
  const governance = read('api/admin/recruitment/services/RecruitmentGovernanceService.php');
  const gate = read('api/admin/recruitment/services/ExternalProcessorGateService.php');
  for (const field of ['processor_name', 'model_name', 'service_region', 'transport_encryption', 'retention_days', 'training_use_allowed', 'subcontractors_json', 'deletion_mechanism', 'approval_status']) {
    assert.ok(migration.includes(field), `${field} should be persisted`);
  }
  assert.match(governance, /approval_status = 'draft'/);
  assert.match(governance, /真实简历处理服务必须关闭训练使用/);
  assert.match(gate, /transport_encryption/);
  assert.match(gate, /approval_status = 'approved'/);
});

test('retention policies require publication and separate disposal approval', () => {
  const governance = read('api/admin/recruitment/services/RecruitmentGovernanceService.php');
  const endpoint = read('api/admin/recruitment/retention.php');
  assert.match(governance, /仅草稿留存策略可发布/);
  assert.match(governance, /处置任务必须引用已发布留存策略/);
  assert.match(governance, /\['approval_status'\] !== 'approved'/);
  assert.match(endpoint, /recruitment\.retention_execute/);
  assert.match(endpoint, /approve_disposal/);
  assert.match(endpoint, /execute_disposal/);
});

test('legal holds are rechecked across related recruitment scopes', () => {
  const governance = read('api/admin/recruitment/services/RecruitmentGovernanceService.php');
  assert.match(governance, /holdBlocks/);
  assert.match(governance, /scopeEntities/);
  assert.match(governance, /处置范围受有效法务冻结阻断/);
  assert.match(governance, /处置范围新增有效法务冻结/);
  assert.match(governance, /retry_count = retry_count \+ 1/);
});

test('quality audit reports confirmation failure adjustment grade and duplicate metrics', () => {
  const audit = read('api/admin/recruitment/services/RecruitmentAuditService.php');
  for (const metric of ['field_confirmation_rate', 'parse_failure_rate', 'manual_adjustment_rate', 'duplicate_rate', 'grade_a', 'grade_b', 'grade_c']) {
    assert.ok(audit.includes(metric), `${metric} should be reported`);
  }
  assert.match(audit, /manual_adjustment_rate.*>= 0\.2/s);
  assert.match(audit, /decision_timeline/);
  assert.match(audit, /operation_logs/);
  assert.match(audit, /ai_quality/);
  assert.match(audit, /scoped_requirement/);
  assert.match(audit, /ai_requirement/);
});

test('governance audit payloads avoid resume text and full phone values', () => {
  const processors = read('api/admin/recruitment/processors.php');
  const retention = read('api/admin/recruitment/retention.php');
  assert.doesNotMatch(processors, /phone_ciphertext|model_output_json|fields_json/);
  assert.doesNotMatch(retention, /phone_ciphertext|model_output_json|fields_json/);
  assert.match(processors, /processor_code/);
  assert.match(retention, /data_category/);
});
