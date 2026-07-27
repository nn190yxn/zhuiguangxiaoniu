import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const migrationUrl = new URL(
  '../database/migrations/202607240002_workload_governance.sql',
  import.meta.url,
);
const sql = readFileSync(migrationUrl, 'utf8');

const requiredTables = [
  'workload_submission_obligations',
  'workload_source_policies',
  'workload_metric_versions',
  'workload_role_rule_versions',
  'workload_role_metric_rules',
  'workload_alert_rules',
  'workload_alert_events',
  'workload_export_jobs',
  'workload_report_corrections',
];

test('migration creates every workload governance table additively', () => {
  for (const table of requiredTables) {
    assert.match(sql, new RegExp(`CREATE TABLE IF NOT EXISTS ${table} \\(`));
  }

  const utf8Tables = sql.match(/ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/g) ?? [];
  assert.equal(utf8Tables.length, requiredTables.length);
});

test('obligations have stable identity and operational query indexes', () => {
  assert.match(
    sql,
    /UNIQUE KEY uq_workload_submission_obligation \(obligation_date, store_id, staff_id, role_code\)/,
  );
  assert.match(
    sql,
    /KEY idx_workload_obligations_store_status \(obligation_date, store_id, completion_status\)/,
  );
  assert.match(
    sql,
    /KEY idx_workload_obligations_staff_status \(staff_id, obligation_date, completion_status\)/,
  );
  assert.match(sql, /KEY idx_workload_obligations_report \(report_id\)/);
  assert.match(sql, /KEY idx_workload_obligations_deadline \(completion_status, deadline_at\)/);
});

test('source policies separate production and synthetic reports', () => {
  for (const source of ['h5', 'mini_program']) {
    assert.match(sql, new RegExp(`\\('${source}', 'production', 1,`));
  }
  for (const source of ['codex-smoke', 'debug', 'h5-e2e', 'live_check', 'test']) {
    assert.match(sql, new RegExp(`\\('${source}', 'synthetic', 0,`));
  }
});

test('metric and role rule versions are bound to historical reports', () => {
  assert.match(sql, /UNIQUE KEY uq_workload_metric_versions_code \(version_code\)/);
  assert.match(sql, /UNIQUE KEY uq_workload_role_rule_versions_code \(version_code\)/);
  assert.match(sql, /minimum_positive_metrics INT UNSIGNED NOT NULL DEFAULT 4/);
  assert.match(sql, /ADD COLUMN metric_version_id BIGINT UNSIGNED/);
  assert.match(sql, /ADD COLUMN rule_version_id BIGINT UNSIGNED/);
  assert.match(sql, /SET report\.metric_version_id = metric_version\.id/);
  assert.match(sql, /SET report\.rule_version_id = rule_version\.id/);
});

test('role metric rules preserve validation, evidence, audit, and statistics metadata', () => {
  for (const column of [
    'is_required',
    'allow_zero',
    'min_value',
    'max_value',
    'need_evidence',
    'min_evidence_count',
    'max_evidence_count',
    'audit_mode',
    'statistic_direction',
    'target_value',
  ]) {
    assert.match(sql, new RegExp(`\\b${column}\\b`));
  }
  assert.match(sql, /UNIQUE KEY uq_workload_role_metric_rule \(rule_version_id, metric_code\)/);
});

test('historical reports backfill only confirmed obligations', () => {
  assert.match(sql, /INSERT INTO workload_submission_obligations/);
  assert.match(sql, /FROM workload_daily_reports report/);
  assert.match(sql, /DAYOFWEEK\(report\.report_date\) = 2 THEN 'exempt'/);
  assert.match(sql, /report\.submit_status = 'submitted' THEN 'submitted' ELSE 'draft'/);
  assert.match(sql, /'backfill'/);
  assert.doesNotMatch(sql, /INSERT INTO workload_submission_obligations[\s\S]*FROM staffs\b/);
});

test('alerts and exports have idempotency, ownership, and lifecycle indexes', () => {
  assert.match(
    sql,
    /UNIQUE KEY uq_workload_alert_event_scope \(rule_code, period_key, store_id, staff_id, role_code, metric_code\)/,
  );
  assert.match(sql, /handled_by_staff_id BIGINT UNSIGNED NULL/);
  assert.match(sql, /handler_comment VARCHAR\(500\) NULL/);
  assert.match(sql, /severity VARCHAR\(16\) NOT NULL DEFAULT 'warning'/);
  assert.match(
    sql,
    /KEY idx_workload_alert_events_status_date \(status, business_date, severity\)/,
  );
  assert.match(sql, /UNIQUE KEY uq_workload_export_jobs_key \(job_key\)/);
  assert.match(sql, /requested_by_staff_id BIGINT UNSIGNED NOT NULL/);
  assert.match(sql, /scope_hash CHAR\(64\) NOT NULL/);
  assert.match(sql, /KEY idx_workload_export_jobs_expiry \(expires_at, status\)/);
});

test('corrections preserve before and after snapshots with operators', () => {
  assert.match(sql, /before_snapshot_json LONGTEXT NOT NULL/);
  assert.match(sql, /after_snapshot_json LONGTEXT NOT NULL/);
  assert.match(sql, /correction_reason VARCHAR\(500\) NOT NULL/);
  assert.match(sql, /operated_by_staff_id BIGINT UNSIGNED NOT NULL/);
  assert.match(sql, /KEY idx_workload_report_corrections_report \(report_id, created_at\)/);
});

test('existing workload tables receive statistics indexes conditionally', () => {
  for (const index of [
    'idx_workload_reports_source_stats',
    'idx_workload_reports_staff_source',
    'idx_workload_reports_versions',
    'idx_workload_values_metric_report_value',
    'idx_workload_audit_backlog',
    'idx_workload_audit_report_status',
  ]) {
    assert.match(sql, new RegExp(`INDEX_NAME = '${index}'`));
    assert.match(sql, new RegExp(`ADD KEY ${index} \\(`));
  }
});

test('migration preserves existing workload records and compatibility keys', () => {
  assert.doesNotMatch(sql, /\bDROP\s+(?:TABLE|COLUMN|INDEX)\b/i);
  assert.doesNotMatch(sql, /\bTRUNCATE\b/i);
  assert.doesNotMatch(sql, /\bDELETE\s+FROM\b/i);
  assert.doesNotMatch(
    sql,
    /CREATE TABLE(?: IF NOT EXISTS)? (?:workload_daily_reports|workload_daily_report_values|workload_audit_tasks)\b/i,
  );
});
