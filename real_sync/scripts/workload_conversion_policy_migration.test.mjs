import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const migration = readFileSync(new URL(
  '../database/migrations/202608120002_workload_conversion_and_daily_target.sql',
  import.meta.url,
), 'utf8');
const queryService = readFileSync(new URL(
  '../api/workload/services/WorkloadConversionResultQueryService.php',
  import.meta.url,
), 'utf8');

test('daily target migration removes current submission count gate additively', () => {
  assert.match(migration, /UPDATE workload_role_rule_versions[\s\S]*?minimum_positive_metrics = 0/);
  assert.match(migration, /WHERE status IN \('active', 'scheduled'\)/);
  assert.match(migration, /UPDATE workload_templates[\s\S]*?minimum_positive_metrics = 0/);
  assert.doesNotMatch(migration, /DROP\s+(?:TABLE|COLUMN|INDEX)/i);
});

test('conversion policy is versioned and idempotently seeds body-test points', () => {
  assert.match(migration, /CREATE TABLE IF NOT EXISTS workload_conversion_rule_versions/);
  assert.match(migration, /CREATE TABLE IF NOT EXISTS workload_conversion_rules/);
  assert.match(migration, /CREATE TABLE IF NOT EXISTS workload_report_conversion_results/);
  assert.match(migration, /UNIQUE KEY uq_workload_conversion_rule_versions_code/);
  assert.match(migration, /UNIQUE KEY uq_workload_conversion_rule \(rule_version_id, rule_code\)/);
  assert.match(migration, /'workload-daily-points-v1', 'coach', '2026-08-12'/);
  assert.match(migration, /'coach-body-test-point', '\["coach_body_test"\]'/);
  assert.match(migration, /'draft'/);
  assert.match(migration, /1\.00, 1\.00, NULL/);
  assert.match(migration, /ON DUPLICATE KEY UPDATE/);
});

test('conversion result schema preserves rule snapshots and review buckets', () => {
  for (const field of ['rule_snapshot_json', 'raw_value', 'pending_points', 'effective_points', 'rejected_points', 'completion_state']) {
    assert.match(migration, new RegExp(`\\b${field}\\b`));
  }
  assert.match(migration, /UNIQUE KEY uq_workload_report_conversion_result \(report_id, conversion_rule_id\)/);
});

test('historical reports remain unchanged while conversion outcomes retain snapshots', () => {
  assert.doesNotMatch(migration, /\b(?:UPDATE|ALTER)\s+TABLE\s+workload_daily_reports\b/i);
  assert.doesNotMatch(migration, /\bDELETE\s+FROM\s+workload_daily_reports\b/i);
  assert.match(migration, /report_id BIGINT UNSIGNED NOT NULL/);
  assert.match(migration, /conversion_rule_id BIGINT UNSIGNED NOT NULL/);
  assert.match(migration, /rule_snapshot_json LONGTEXT NOT NULL/);
  assert.match(migration, /UNIQUE KEY uq_workload_report_conversion_result \(report_id, conversion_rule_id\)/);
});

test('historical conversion reads use stored snapshots and never recalculate from current rules', () => {
  assert.match(queryService, /result\.rule_snapshot_json/);
  assert.match(queryService, /json_decode\(\(string\) \$row\['rule_snapshot_json'\]/);
  assert.match(queryService, /LEFT JOIN workload_conversion_rules rule_detail ON rule_detail\.id = result\.conversion_rule_id/);
  assert.doesNotMatch(queryService, /WHERE version\.status\s*=\s*'active'/);
  assert.doesNotMatch(queryService, /UPDATE\s+workload_report_conversion_results/i);
});
