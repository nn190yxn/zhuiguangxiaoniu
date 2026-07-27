import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const read = (relativePath) => readFileSync(new URL(relativePath, import.meta.url), 'utf8');
const sourceService = read('../api/workload/services/WorkloadSourcePolicyService.php');
const metricService = read('../api/workload/services/WorkloadMetricVersionService.php');
const ruleService = read('../api/workload/services/WorkloadRoleRuleVersionService.php');
const saveReport = read('../api/workload/save-report.php');

class GovernanceModel {
  constructor() {
    this.policies = new Map([
      ['h5', { kind: 'production', included: true }],
      ['mini_program', { kind: 'production', included: true }],
      ['codex-smoke', { kind: 'synthetic', included: false }],
      ['debug', { kind: 'synthetic', included: false }],
    ]);
    this.metricVersions = [{ id: 1, code: 'workload-v1', effectiveAt: '1970-01-01 00:00:00' }];
    this.ruleVersions = [{
      id: 1,
      code: 'sales-v1',
      role: 'sales',
      from: '1970-01-01',
      to: null,
      minimumPositive: 4,
      rules: {},
    }];
    this.reports = [];
  }

  activeMetric(now) {
    return this.metricVersions
      .filter(({ effectiveAt }) => effectiveAt <= now)
      .sort((left, right) => right.effectiveAt.localeCompare(left.effectiveAt) || right.id - left.id)[0];
  }

  activeRule(role, date) {
    return this.ruleVersions
      .filter((version) => version.role === role && version.from <= date && (!version.to || version.to >= date))
      .sort((left, right) => right.from.localeCompare(left.from) || right.id - left.id)[0];
  }

  submit({ date, now, role = 'sales', source, values, evidence = {} }) {
    const policy = this.policies.get(source);
    if (!policy) throw new Error('unregistered_source');
    const metricVersion = this.activeMetric(now);
    const ruleVersion = this.activeRule(role, date);
    const positiveCount = Object.values(values).filter((value) => value > 0).length;
    if (positiveCount < ruleVersion.minimumPositive) throw new Error('minimum_positive');
    for (const [code, rule] of Object.entries(ruleVersion.rules)) {
      if (rule.required && !(code in values)) throw new Error(`required:${code}`);
      if (!(code in values)) continue;
      if (rule.required && !rule.allowZero && values[code] <= 0) throw new Error(`positive_required:${code}`);
      if (rule.min !== null && values[code] < rule.min) throw new Error(`min:${code}`);
      if (rule.max !== null && values[code] > rule.max) throw new Error(`max:${code}`);
      if (rule.evidence && values[code] > 0 && (evidence[code] ?? 0) < rule.minEvidence) {
        throw new Error(`evidence:${code}`);
      }
    }
    const report = {
      id: this.reports.length + 1,
      source,
      total: Object.values(values).reduce((sum, value) => sum + value, 0),
      metricVersionId: metricVersion.id,
      ruleVersionId: ruleVersion.id,
    };
    this.reports.push(report);
    return report;
  }

  defaultOperatingTotal() {
    return this.reports
      .filter(({ source }) => this.policies.get(source)?.included === true)
      .reduce((sum, report) => sum + report.total, 0);
  }
}

const fourValues = { a: 1, b: 2, c: 3, d: 4 };

test('[validates 2.5, 9.2] production H5 and mini-program reports enter the default operating total', () => {
  const model = new GovernanceModel();
  model.submit({ date: '2026-07-25', now: '2026-07-25 12:00:00', source: 'h5', values: fourValues });
  model.submit({ date: '2026-07-25', now: '2026-07-25 12:00:00', source: 'mini_program', values: fourValues });
  assert.equal(model.defaultOperatingTotal(), 20);
});

test('[validates 2.5, 9.2] synthetic reports remain auditable with zero default operating contribution', () => {
  const model = new GovernanceModel();
  const report = model.submit({ date: '2026-07-25', now: '2026-07-25 12:00:00', source: 'debug', values: fourValues });
  assert.equal(report.source, 'debug');
  assert.equal(model.reports.length, 1);
  assert.equal(model.defaultOperatingTotal(), 0);
});

test('[validates 1.11] the legacy rule accepts exactly four positive metrics and rejects three', () => {
  const model = new GovernanceModel();
  assert.throws(
    () => model.submit({ date: '2026-07-25', now: '2026-07-25 12:00:00', source: 'h5', values: { a: 1, b: 1, c: 1 } }),
    /minimum_positive/,
  );
  assert.doesNotThrow(() => model.submit({ date: '2026-07-25', now: '2026-07-25 12:00:00', source: 'h5', values: fourValues }));
});

test('[validates 1.12] a dated role rule enforces required, zero, range, and evidence constraints', () => {
  const model = new GovernanceModel();
  model.ruleVersions[0].to = '2026-07-31';
  model.ruleVersions.push({
    id: 2,
    code: 'sales-v2',
    role: 'sales',
    from: '2026-08-01',
    to: null,
    minimumPositive: 1,
    rules: {
      visits: { required: true, allowZero: false, min: 1, max: 10, evidence: true, minEvidence: 2 },
    },
  });
  const base = { date: '2026-08-01', now: '2026-08-01 12:00:00', source: 'h5' };
  assert.throws(() => model.submit({ ...base, values: { optional: 1 } }), /required:visits/);
  assert.throws(() => model.submit({ ...base, values: { visits: 0, optional: 1 } }), /positive_required:visits/);
  assert.throws(() => model.submit({ ...base, values: { visits: 11 } }), /max:visits/);
  assert.throws(() => model.submit({ ...base, values: { visits: 2 }, evidence: { visits: 1 } }), /evidence:visits/);
  assert.doesNotThrow(() => model.submit({ ...base, values: { visits: 2 }, evidence: { visits: 2 } }));
});

test('[validates 1.12, 9.2] historical reports retain metric and role bindings after later versions activate', () => {
  const model = new GovernanceModel();
  const historical = model.submit({ date: '2026-07-25', now: '2026-07-25 12:00:00', source: 'h5', values: fourValues });
  model.metricVersions.push({ id: 2, code: 'workload-v2', effectiveAt: '2026-08-01 00:00:00' });
  model.ruleVersions[0].to = '2026-07-31';
  model.ruleVersions.push({ id: 2, code: 'sales-v2', role: 'sales', from: '2026-08-01', to: null, minimumPositive: 1, rules: {} });
  const current = model.submit({ date: '2026-08-01', now: '2026-08-01 12:00:00', source: 'h5', values: { a: 1 } });
  assert.deepEqual([historical.metricVersionId, historical.ruleVersionId], [1, 1]);
  assert.deepEqual([current.metricVersionId, current.ruleVersionId], [2, 2]);
  assert.deepEqual([model.reports[0].metricVersionId, model.reports[0].ruleVersionId], [1, 1]);
});

test('[validates 1.12, 2.5, 9.2] production contracts share source policy and bind both versions on save', () => {
  assert.match(sourceService, /included_by_default = 1/);
  assert.match(metricService, /ORDER BY effective_at DESC, id DESC LIMIT 1/);
  assert.match(ruleService, /ORDER BY effective_from DESC, id DESC LIMIT 1/);
  assert.match(ruleService, /report\.rule_version_id/);
  assert.match(saveReport, /metric_version_id=\?, rule_version_id=\?/);
  assert.match(saveReport, /metric_version_id, rule_version_id, submit_status/);
});
