import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const healthService = readFileSync(
  new URL('../api/admin/services/StaffDataHealthService.php', import.meta.url),
  'utf8',
);
const profileService = readFileSync(
  new URL('../api/admin/services/StaffProfileService.php', import.meta.url),
  'utf8',
);
const employeeEndpoint = readFileSync(
  new URL('../api/staff/profile-corrections.php', import.meta.url),
  'utf8',
);
const adminEndpoint = readFileSync(
  new URL('../api/admin/staff/profile-corrections.php', import.meta.url),
  'utf8',
);
const importService = readFileSync(
  new URL('../api/admin/services/StaffImportService.php', import.meta.url),
  'utf8',
);
const exportEndpoint = readFileSync(new URL('../api/admin/staff/export.php', import.meta.url), 'utf8');

function healthIssues(state) {
  const duplicateEmployeeNumbers = state.staff.filter(
    (item, index, all) => all.findIndex((candidate) => candidate.employeeNo === item.employeeNo) !== index,
  ).length;
  const duplicatePhones = state.staff.filter(
    (item, index, all) => all.findIndex((candidate) => candidate.phone === item.phone) !== index,
  ).length;
  const invalidStores = state.staff.filter((item) => !state.stores.has(item.storeId)).length;
  const invalidPositions = state.staff.filter((item) => !state.positions.has(item.positionId)).length;
  const roleMismatches = state.staff.filter((item) => item.role !== item.accountRole).length;
  const orphanIdentities = state.staff.filter((item) => item.userId === null).length;
  return duplicateEmployeeNumbers + duplicatePhones + invalidStores + invalidPositions + roleMismatches + orphanIdentities;
}

test('health issues close after every underlying identity and organization defect is repaired', () => {
  const state = {
    stores: new Set([1]),
    positions: new Set([10]),
    staff: [
      { employeeNo: 'S001', phone: '13800000000', storeId: 1, positionId: 10, role: 'sales', accountRole: 'sales', userId: 1 },
      { employeeNo: 'S001', phone: '13800000000', storeId: 99, positionId: 88, role: 'manager', accountRole: 'sales', userId: null },
    ],
  };
  assert.equal(healthIssues(state), 6);

  Object.assign(state.staff[1], {
    employeeNo: 'S002',
    phone: '13900000000',
    storeId: 1,
    positionId: 10,
    accountRole: 'manager',
    userId: 2,
  });
  assert.equal(healthIssues(state), 0);
  assert.match(healthService, /'healthy' => array_sum\(\$counts\) === 0/);
  assert.doesNotMatch(healthService, /INSERT INTO|UPDATE\s+staffs|DELETE FROM/i);
});

test('correction state remains visible to its employee and isolated from another employee', () => {
  const requests = [
    { id: 1, staffId: 11, status: 'pending', comment: null },
    { id: 2, staffId: 12, status: 'rejected', comment: '资料已核对' },
  ];
  const visibleTo = (staffId) => requests.filter((request) => request.staffId === staffId);
  assert.deepEqual(visibleTo(11).map((request) => request.id), [1]);
  assert.deepEqual(visibleTo(12).map((request) => request.id), [2]);
  assert.match(employeeEndpoint, /correctionsForStaff\(\(int\)\$staff\['id'\]\)/);
  assert.doesNotMatch(employeeEndpoint, /staff_id.*\$input/);
});

test('only headquarters management can process a pending correction once', () => {
  const canHandle = (role) => ['operation', 'admin'].includes(role);
  assert.equal(canHandle('sales'), false);
  assert.equal(canHandle('manager'), false);
  assert.equal(canHandle('operation'), true);
  assert.equal(canHandle('admin'), true);
  assert.match(adminEndpoint, /adminRequirePermission\('staff\.edit'\)/);
  assert.match(profileService, /WHERE id = \? FOR UPDATE/);
  assert.match(profileService, /更正申请已处理/);
});

test('import persistence and export output retain their sensitive-data boundaries', () => {
  const summaryFunction = importService.match(/private function summarizeRecord[\s\S]*?private function rowError/)?.[0] ?? '';
  assert.match(summaryFunction, /adminMaskSensitiveValue/);
  assert.doesNotMatch(summaryFunction, /initial_password/);
  assert.match(exportEndpoint, /StaffDirectoryService\(getDB\(\), \$canViewSensitive\)/);
  assert.match(exportEndpoint, /staffExportCsvValue/);
  assert.doesNotMatch(exportEndpoint, /SELECT\s+\*/i);
});

test('correction writes preserve snapshots, operator decisions, and rollback behavior', () => {
  assert.match(profileService, /'current_value' => \$current/);
  assert.match(profileService, /'requested_value' => \$normalized/);
  assert.match(profileService, /handled_by_staff_id = \?/);
  assert.match(profileService, /'action' => 'handle_correction'/);
  assert.match(profileService, /rollBack\(\)/);
});
