import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const migrationUrl = new URL(
  '../database/migrations/202608010001_workload_conversion_rules.sql',
  import.meta.url,
);
const sql = readFileSync(migrationUrl, 'utf8');
const manifest = readFileSync(new URL('../database/migration_manifest.php', import.meta.url), 'utf8');

test('conversion migration creates version, rule, and report snapshot tables', () => {
  for (const table of [
    'workload_conversion_rule_versions',
    'workload_conversion_rules',
    'workload_report_conversion_results',
  ]) {
    assert.match(sql, new RegExp(`CREATE TABLE IF NOT EXISTS ${table} \\(`));
    assert.match(manifest, new RegExp(table));
  }
  assert.equal(sql.match(/ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/g)?.length, 3);
});

test('conversion rules persist every supported mode and bounded configuration', () => {
  for (const mode of ['threshold', 'step', 'tier', 'composite', 'required_check']) {
    assert.match(sql, new RegExp(mode));
  }
  for (const column of [
    'metric_codes_json',
    'threshold_value',
    'points_per_match',
    'daily_cap_points',
    'tiers_json',
    'evidence_types_json',
    'requires_all_metrics',
    'is_required_check',
  ]) {
    assert.match(sql, new RegExp(`\\b${column}\\b`));
  }
  assert.match(sql, /UNIQUE KEY uq_workload_conversion_rule \(rule_version_id, rule_code\)/);
});

test('report conversion results preserve rule snapshots and status buckets', () => {
  for (const column of [
    'rule_snapshot_json',
    'raw_value',
    'pending_points',
    'effective_points',
    'rejected_points',
    'completion_state',
    'explanation',
  ]) {
    assert.match(sql, new RegExp(`\\b${column}\\b`));
  }
  assert.match(sql, /UNIQUE KEY uq_workload_report_conversion_result \(report_id, conversion_rule_id\)/);
  assert.match(sql, /KEY idx_workload_report_conversion_results_report \(report_id, completion_state\)/);
});

test('conversion migration is additive and safe to rerun', () => {
  assert.doesNotMatch(sql, /\bDROP\s+(?:TABLE|COLUMN|INDEX)\b/i);
  assert.doesNotMatch(sql, /\bTRUNCATE\b/i);
  assert.doesNotMatch(sql, /\bDELETE\s+FROM\b/i);
  assert.equal((sql.match(/CREATE TABLE IF NOT EXISTS/g) ?? []).length, 3);
});
