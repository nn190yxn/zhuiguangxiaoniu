import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url), 'utf8');
const endpoint = readFileSync(new URL('../api/admin/staff/create.php', import.meta.url), 'utf8');
const adminCommon = readFileSync(new URL('../api/admin/common.php', import.meta.url), 'utf8');

test('service validates every required staff creation field', () => {
  for (const field of ['name', 'phone', 'store_id', 'position_id', 'role', 'initial_password']) {
    assert.match(service, new RegExp(`['"]${field}['"]`));
  }
  assert.match(service, /PasswordPolicy::validate\(\$data\['initial_password'\]\)/);
  assert.match(service, /phone format is invalid/);
});

test('service validates active organization references and role compatibility', () => {
  assert.match(service, /FROM stores WHERE id = \? AND status = 1/);
  assert.match(service, /FROM organization_positions WHERE id = \? AND status = 1/);
  assert.match(service, /role does not match the position/);
});

test('service creates account, staff, primary assignment, and audit in one transaction', () => {
  const begin = service.indexOf('beginTransaction()');
  const account = service.indexOf('createWordPressUser($data)');
  const staff = service.indexOf('createStaff($data, $position, $userId)');
  const identity = service.indexOf('synchronizeRole(', staff);
  const assignment = service.indexOf('createPrimaryAssignment($data, $staffId');
  const audit = service.indexOf('adminRecordOperation($this->db');
  const commit = service.indexOf('$this->db->commit()');
  assert.ok(begin < account && account < staff && staff < identity && identity < assignment && assignment < audit && audit < commit);
  assert.match(service, /if \(\$this->db->inTransaction\(\)\)/);
  assert.match(service, /\$this->db->rollBack\(\)/);
  assert.match(service, /\(int\)\(\$operatorStaff\['id'\] \?\? 0\)/);
});

test('service generates missing employee numbers through a locked sequence', () => {
  assert.match(service, /STAFF_EMPLOYEE_NO_PREFIX/);
  assert.match(service, /STAFF_EMPLOYEE_NO_WIDTH/);
  assert.match(service, /staff_employee_number_sequences WHERE sequence_key = \? FOR UPDATE/);
  assert.match(service, /str_pad\(\(string\)\$value, \$width, '0', STR_PAD_LEFT\)/);
  assert.ok(service.indexOf('beginTransaction()') < service.indexOf('generateEmployeeNumber()'));
});

test('service reports duplicate employee, phone, username, and email identities', () => {
  assert.match(service, /s\.employee_no = \? OR s\.phone = \? OR u\.user_login = \? OR u\.user_email = \?/);
  assert.match(service, /WHERE user_login = \? OR user_email = \? FOR UPDATE/);
  assert.match(service, /new StaffIdentityConflictException\(\$fields, \$profiles\)/);
  assert.match(service, /adminMaskSensitiveValue/);
  assert.match(endpoint, /jsonResponse\(409/);
  assert.match(endpoint, /conflict_fields/);
  assert.match(endpoint, /existing_profiles/);
});

test('service delegates new account role metadata to identity consistency service', () => {
  assert.match(service, /new IdentityConsistencyService\(\$this->db\)/);
  assert.match(service, /synchronizeRole\([\s\S]*?\$staffId,[\s\S]*?\$data\['role'\],[\s\S]*?false/);
  assert.doesNotMatch(service, /private function writeWordPressRole/);
});

test('endpoint limits creation to authorized headquarters roles', () => {
  assert.match(endpoint, /adminRequirePermission\('staff\.create'\)/);
  assert.match(endpoint, /new StaffLifecycleService\(getDB\(\)\)/);
});

test('audit migration provides storage before staff creation transactions', () => {
  const migration = readFileSync(new URL('../database/migrations/202607240003_admin_operation_audit.sql', import.meta.url), 'utf8');
  assert.match(migration, /CREATE TABLE IF NOT EXISTS admin_operation_logs/);
  assert.match(migration, /KEY idx_target_lookup \(target_type, target_id\)/);
  assert.doesNotMatch(migration, /\bDROP\b|\bDELETE\s+FROM\b|\bTRUNCATE\b/i);
  const auditGuard = adminCommon.match(/function ensureAdminOperationLogsTable[\s\S]*?\n}/)?.[0] ?? '';
  assert.match(auditGuard, /adminTableExists\(\$db, 'admin_operation_logs'\)/);
  assert.doesNotMatch(auditGuard, /CREATE TABLE|ALTER TABLE/);
  const createMethod = service.match(/public function create\([\s\S]*?\n    }/)?.[0] ?? '';
  assert.match(createMethod, /adminTableExists\(\$this->db, 'admin_operation_logs'\)/);
  assert.match(createMethod, /admin operation log schema is not ready/);
  assert.doesNotMatch(createMethod, /ensureAdminOperationLogsTable/);
  assert.ok(createMethod.indexOf("adminTableExists($this->db, 'admin_operation_logs')") < createMethod.indexOf('beginTransaction()'));
});
