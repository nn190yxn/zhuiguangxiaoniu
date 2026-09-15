import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const servicePath = new URL('../api/workload/services/WorkloadConversionRuleService.php', import.meta.url);
const service = readFileSync(servicePath, 'utf8');

function evaluate(rule, values, evidence = {}, auditStatus = 'approved') {
  const encode = (value) => Buffer.from(JSON.stringify(value)).toString('base64');
  const script = `require '${servicePath.pathname}'; echo json_encode(WorkloadConversionRuleService::evaluate(json_decode(base64_decode('${encode(rule)}'), true), json_decode(base64_decode('${encode(values)}'), true), json_decode(base64_decode('${encode(evidence)}'), true), '${auditStatus}'));`;
  return JSON.parse(execFileSync('php', ['-r', script], { encoding: 'utf8' }));
}

test('[validates 1.1-1.4] version selection detects overlapping effective conversion rules', () => {
  assert.match(service, /status IN \('active', 'scheduled'\)/);
  assert.match(service, /effective_from <= \?/);
  assert.match(service, /effective_to IS NULL OR effective_to >= \?/);
  assert.match(service, /换算规则生效区间冲突/);
  assert.match(service, /rule_snapshot/);
  assert.match(service, /function snapshotForResult\(int \$reportId, int \$conversionRuleId\)/);
  assert.match(service, /rule_snapshot_json/);
});

test('[validates 2.1, properties 3-4] threshold boundary and step rounding are deterministic', () => {
  const threshold = { rule_code: 'calls', metric_codes: ['calls'], conversion_mode: 'threshold', threshold_value: 30, points_per_match: 1 };
  assert.equal(evaluate(threshold, { calls: 29 }).effective_points, 0);
  assert.equal(evaluate(threshold, { calls: 30 }).effective_points, 1);
  const step = { rule_code: 'posts', metric_codes: ['posts'], conversion_mode: 'step', threshold_value: 3, points_per_match: 1 };
  assert.equal(evaluate(step, { posts: 8 }).effective_points, 2);
});

test('[validates property 5] each tier input uses one highest-priority matching tier', () => {
  const rule = { rule_code: 'orders', metric_codes: ['orders'], conversion_mode: 'tier', tiers: [
    { min: 0, max: 3999.99, points: 1, priority: 1 },
    { min: 4000, points: 2, priority: 2 },
  ] };
  assert.equal(evaluate(rule, { orders: [3999, 4000] }).effective_points, 3);
});

test('[validates property 6] composite rules require every metric and an allowed evidence type', () => {
  const rule = { rule_code: 'assessment_plan', metric_codes: ['assessment', 'plan'], conversion_mode: 'composite', threshold_value: 1, points_per_match: 1, requires_all_metrics: true, evidence_types: ['family_group'] };
  assert.equal(evaluate(rule, { assessment: 1, plan: 1 }).effective_points, 0);
  assert.equal(evaluate(rule, { assessment: 1, plan: 0 }, { family_group: 1 }).effective_points, 0);
  assert.equal(evaluate(rule, { assessment: 1, plan: 1 }, { family_group: 1 }).effective_points, 1);
});

test('[validates properties 2 and 9] daily caps apply while uncapped matches accumulate', () => {
  const capped = { rule_code: 'share', metric_codes: ['share'], conversion_mode: 'step', threshold_value: 1, points_per_match: 1, daily_cap_points: 2 };
  const uncapped = { ...capped, daily_cap_points: null };
  assert.equal(evaluate(capped, { share: 4 }).effective_points, 2);
  assert.equal(evaluate(uncapped, { share: 4 }).effective_points, 4);
});

test('[validates property 8] pending and rejected points remain separated from effective points', () => {
  const rule = { rule_code: 'visit', metric_codes: ['visit'], conversion_mode: 'threshold', threshold_value: 1, points_per_match: 1 };
  assert.equal(evaluate(rule, { visit: 1 }, {}, 'pending').pending_points, 1);
  assert.equal(evaluate(rule, { visit: 1 }, {}, 'rejected').rejected_points, 1);
  assert.equal(evaluate(rule, { visit: 1 }, {}, 'approved').effective_points, 1);
});
