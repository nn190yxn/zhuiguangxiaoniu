import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const migrationUrl = new URL(
  '../database/migrations/202608120001_workload_daily_closure.sql',
  import.meta.url,
);
const sql = readFileSync(migrationUrl, 'utf8');

test('daily closure migration creates additive settlement, penalty, and audit ledgers', () => {
  for (const table of [
    'workload_daily_settlements',
    'workload_penalty_records',
    'workload_penalty_record_logs',
  ]) {
    assert.match(sql, new RegExp(`CREATE TABLE IF NOT EXISTS ${table} \\(`));
  }
  assert.doesNotMatch(sql, /\b(?:DROP|TRUNCATE|DELETE\s+FROM)\b/i);
});

test('settlements retain a unique business scope and point snapshots', () => {
  assert.match(sql, /UNIQUE KEY uq_workload_daily_settlement_scope \(business_date, store_id, staff_id, role_code\)/);
  for (const column of [
    'target_points',
    'reported_points',
    'pending_points',
    'effective_points',
    'rejected_points',
    'gap_points',
    'settlement_status',
    'makeup_deadline_at',
    'rule_snapshot_json',
  ]) {
    assert.match(sql, new RegExp(`\\b${column}\\b`));
  }
  assert.match(sql, /KEY idx_workload_daily_settlements_status \(settlement_status, business_date, makeup_deadline_at\)/);
});

test('penalties retain unique scope, monetary snapshots, and handling fields', () => {
  assert.match(sql, /UNIQUE KEY uq_workload_penalty_record_scope \(business_date, store_id, staff_id, role_code\)/);
  assert.match(sql, /settlement_id BIGINT UNSIGNED NOT NULL/);
  assert.match(sql, /unit_amount DECIMAL\(10,2\) NOT NULL DEFAULT 20\.00/);
  assert.match(sql, /penalty_amount DECIMAL\(10,2\) NOT NULL/);
  for (const column of [
    'confirmed_by_staff_id',
    'confirmation_comment',
    'cancelled_by_staff_id',
    'cancellation_reason',
    'payroll_handed_off_by_staff_id',
    'payroll_handoff_note',
  ]) {
    assert.match(sql, new RegExp(`\\b${column}\\b`));
  }
});

test('penalty audit log retains operation snapshots and lookup indexes', () => {
  assert.match(sql, /before_snapshot_json LONGTEXT NULL/);
  assert.match(sql, /after_snapshot_json LONGTEXT NOT NULL/);
  assert.match(sql, /KEY idx_workload_penalty_record_logs_record \(penalty_record_id, occurred_at\)/);
  assert.match(sql, /KEY idx_workload_penalty_record_logs_operator \(operated_by_staff_id, occurred_at\)/);
});
