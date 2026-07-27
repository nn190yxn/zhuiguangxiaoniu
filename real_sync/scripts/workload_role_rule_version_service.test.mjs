import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const service = read('../api/workload/services/WorkloadRoleRuleVersionService.php');
const migration = read('../database/migrations/202607240002_workload_governance.sql');
const saveReport = read('../api/workload/save-report.php');
const template = read('../api/workload/template.php');
const evidenceUpload = read('../api/workload/evidence-upload.php');
const common = read('../api/workload/_common.php');

function activeForDate(versions, role, date) {
  return versions
    .filter((version) => version.role === role && ['active', 'scheduled'].includes(version.status))
    .filter((version) => version.from <= date && (!version.to || version.to >= date))
    .sort((left, right) => right.from.localeCompare(left.from) || right.id - left.id)[0] ?? null;
}

function validate(values, version, evidence = {}) {
  const positiveCount = Object.values(values).filter((value) => value > 0).length;
  if (positiveCount < version.minimumPositive) return 'minimum_positive';
  for (const [code, rule] of Object.entries(version.rules)) {
    if (rule.required && !(code in values)) return 'required';
    if (!(code in values)) continue;
    if (rule.required && !rule.allowZero && values[code] <= 0) return 'positive_required';
    if (rule.min !== null && values[code] < rule.min) return 'min';
    if (rule.max !== null && values[code] > rule.max) return 'max';
    if (rule.evidence && values[code] > 0 && (evidence[code] ?? 0) < rule.minEvidence) return 'evidence';
  }
  return 'valid';
}

test('[validates 1.12] effective role rules use closed date bounds and deterministic precedence', () => {
  const versions = [
    { id: 1, role: 'sales', from: '1970-01-01', to: '2026-07-31', status: 'active' },
    { id: 2, role: 'sales', from: '2026-08-01', to: null, status: 'active' },
    { id: 3, role: 'coach', from: '2026-08-01', to: null, status: 'active' },
  ];
  assert.equal(activeForDate(versions, 'sales', '2026-07-31').id, 1);
  assert.equal(activeForDate(versions, 'sales', '2026-08-01').id, 2);
  assert.equal(activeForDate(versions, 'coach', '2026-08-01').id, 3);
  assert.match(service, /effective_from <= \?/);
  assert.match(service, /effective_to IS NULL OR effective_to >= \?/);
  assert.match(service, /ORDER BY effective_from DESC, id DESC LIMIT 1/);
  assert.match(service, /status IN \('active', 'scheduled'\)/);
  assert.match(service, /organization_positions WHERE position_code = \? AND status = 1/);
});

test('[validates 24.6, 24.8] scheduled versions become readable by business date without a worker', () => {
  const versions = [
    { id: 1, role: 'trainer', from: '2026-01-01', to: '2026-08-31', status: 'active' },
    { id: 2, role: 'trainer', from: '2026-09-01', to: null, status: 'scheduled' },
  ];
  assert.equal(activeForDate(versions, 'trainer', '2026-08-31').id, 1);
  assert.equal(activeForDate(versions, 'trainer', '2026-09-01').id, 2);
});

test('[validates 24.6] historical reports use immutable metric snapshots', () => {
  for (const field of ['metric_name_snapshot', 'unit_snapshot', 'value_type_snapshot']) {
    assert.match(service, new RegExp(field));
  }
  assert.match(service, /LEFT JOIN metric_definitions/);
  assert.doesNotMatch(service, /metric\.is_active = 1/);
  assert.match(template, /'metric_name' => \$rule\['metric_name'\]/);
  assert.match(template, /'unit' => \$rule\['unit'\]/);
  assert.match(template, /'value_type' => \$rule\['value_type'\]/);
});

test('[validates 1.11] initial versions preserve four positive metrics', () => {
  assert.match(migration, /minimum_positive_metrics,[\s\S]*?4,[\s\S]*?'1970-01-01'/);
  assert.match(service, /\$positiveCount < \(int\) \$version\['minimum_positive_metrics'\]/);
  const version = { minimumPositive: 4, rules: {} };
  assert.equal(validate({ a: 1, b: 1, c: 1 }, version), 'minimum_positive');
  assert.equal(validate({ a: 1, b: 1, c: 1, d: 1 }, version), 'valid');
});

test('[validates 1.12, 3.6] required, zero, and range rules are enforced together', () => {
  const version = {
    minimumPositive: 1,
    rules: {
      required: { required: true, allowZero: false, min: 1, max: 10, evidence: false },
      optional: { required: false, allowZero: true, min: 0, max: 20, evidence: false },
    },
  };
  assert.equal(validate({ optional: 1 }, version), 'required');
  assert.equal(validate({ required: 0, optional: 1 }, version), 'positive_required');
  assert.equal(validate({ required: 11 }, version), 'max');
  assert.equal(validate({ required: 2, optional: 0 }, version), 'valid');
  for (const field of ['is_required', 'allow_zero', 'min_value', 'max_value']) assert.match(service, new RegExp(field));
});

test('[validates 1.12, 3.7] positive evidence metrics enforce versioned image bounds', () => {
  const version = {
    minimumPositive: 1,
    rules: {
      visits: { required: true, allowZero: false, min: 0, max: null, evidence: true, minEvidence: 2 },
    },
  };
  assert.equal(validate({ visits: 3 }, version, { visits: 1 }), 'evidence');
  assert.equal(validate({ visits: 3 }, version, { visits: 2 }), 'valid');
  assert.match(service, /\$count < \$rule\['min_evidence_count'\]/);
  assert.match(service, /\$count > \$rule\['max_evidence_count'\]/);
});

test('[validates 1.12] templates expose the same effective rules used by report submission', () => {
  assert.match(template, /activeForDate\(\$role, \$date\)/);
  for (const field of ['rule_version', 'minimum_positive_metrics', 'required', 'allow_zero', 'min_value', 'max_value', 'need_evidence', 'audit_mode']) {
    assert.match(template, new RegExp(`'${field}'`));
  }
  assert.match(saveReport, /\$roleRuleService->validateValues\(\$normalizedValues, \$roleRuleVersion, true, \$evidenceCountMap\)/);
});

test('[validates 1.12] reports bind one rule version across evidence and pending-item reads', () => {
  assert.match(saveReport, /metric_version_id=\?, rule_version_id=\?/);
  assert.match(saveReport, /metric_version_id, rule_version_id, submit_status/);
  assert.match(saveReport, /'rule_version' => \$roleRuleVersion\['version_code'\]/);
  assert.match(service, /LEFT JOIN workload_role_rule_versions version ON version\.id = report\.rule_version_id/);
  assert.match(evidenceUpload, /WorkloadRoleRuleVersionService\(\$pdo\)\)->forReport\(\$reportId\)/);
  assert.match(common, /WorkloadRoleRuleVersionService\(\$pdo\)\)->forReport\(\$reportId\)/);
});
