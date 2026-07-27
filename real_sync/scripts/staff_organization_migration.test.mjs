import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const migrationUrl = new URL(
  '../database/migrations/202607240001_staff_organization.sql',
  import.meta.url,
);
const sql = readFileSync(migrationUrl, 'utf8');

const requiredStaffColumns = [
  'lifecycle_status',
  'offboarded_at',
  'offboard_reason',
  'offboarded_by',
  'session_version',
  'primary_position_id',
];

const requiredTables = [
  'organization_positions',
  'staff_assignments',
  'staff_import_batches',
  'staff_import_rows',
  'staff_profile_correction_requests',
];

test('migration adds the staff lifecycle and session fields', () => {
  for (const column of requiredStaffColumns) {
    assert.match(sql, new RegExp(`COLUMN_NAME = '${column}'`));
    assert.match(sql, new RegExp(`ADD COLUMN ${column}\\b`));
  }

  assert.match(sql, /ADD COLUMN store_code VARCHAR\(64\)/);
  assert.match(sql, /ADD COLUMN manager_staff_id BIGINT UNSIGNED/);
});

test('migration creates every employee organization table additively', () => {
  for (const table of requiredTables) {
    assert.match(sql, new RegExp(`CREATE TABLE IF NOT EXISTS ${table} \\(`));
  }

  const utf8Tables = sql.match(/ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/g) ?? [];
  assert.equal(utf8Tables.length, requiredTables.length);
});

test('migration establishes required uniqueness constraints', () => {
  assert.match(sql, /UNIQUE KEY uq_staffs_employee_no \(employee_no\)/);
  assert.match(sql, /UNIQUE KEY uq_staffs_user_id \(user_id\)/);
  assert.match(sql, /UNIQUE KEY uq_stores_store_code \(store_code\)/);
  assert.match(sql, /UNIQUE KEY uq_organization_positions_code \(position_code\)/);
  assert.match(sql, /UNIQUE KEY uq_staff_import_batches_key \(batch_key\)/);
  assert.match(sql, /UNIQUE KEY uq_staff_import_rows_batch_row \(batch_id, row_number\)/);
});

test('migration indexes assignment history by staff, store, and position', () => {
  assert.match(
    sql,
    /KEY idx_staff_assignments_staff_effective \(staff_id, start_date, end_date, assignment_type\)/,
  );
  assert.match(
    sql,
    /KEY idx_staff_assignments_store_effective \(store_id, start_date, end_date\)/,
  );
  assert.match(
    sql,
    /KEY idx_staff_assignments_position_effective \(position_id, start_date, end_date\)/,
  );
});

test('migration records import retries and profile correction handling', () => {
  assert.match(sql, /batch_key CHAR\(36\) NOT NULL/);
  assert.match(sql, /validation_result_json LONGTEXT NULL/);
  assert.match(sql, /retry_count INT UNSIGNED NOT NULL DEFAULT 0/);
  assert.match(sql, /change_summary_json LONGTEXT NOT NULL/);
  assert.match(sql, /handled_by_staff_id BIGINT UNSIGNED NULL/);
  assert.match(sql, /handler_comment VARCHAR\(500\) NULL/);
});

test('migration maps legacy staff into positions and initial assignments', () => {
  assert.match(sql, /INSERT INTO organization_positions/);
  assert.match(sql, /UPDATE staffs s[\s\S]*SET s\.primary_position_id = p\.id/);
  assert.match(sql, /INSERT INTO staff_assignments/);
  assert.match(sql, /Initial assignment migrated from staffs/);
  assert.match(sql, /NOT EXISTS \([\s\S]*existing_assignment\.assignment_type = 'primary'/);
});

test('migration preserves existing business records', () => {
  assert.doesNotMatch(sql, /\bDROP\s+(?:TABLE|COLUMN)\b/i);
  assert.doesNotMatch(sql, /\bTRUNCATE\b/i);
  assert.doesNotMatch(sql, /\bDELETE\s+FROM\b/i);
  assert.doesNotMatch(sql, /CREATE TABLE(?: IF NOT EXISTS)? (?:staffs|stores)\b/i);
});
