import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/admin/services/OrganizationService.php', import.meta.url),
  'utf8',
);
const endpoint = readFileSync(
  new URL('../api/admin/organization/tree.php', import.meta.url),
  'utf8',
);

function buildOrganizationTree(stores, assignments) {
  const groups = new Map(stores.map((store) => [store.id, { ...store, positions: new Map() }]));
  const uniqueStaff = new Set();

  for (const assignment of assignments) {
    const store = groups.get(assignment.storeId);
    if (!store || !assignment.staffActive || !assignment.storeActive || !assignment.positionActive) continue;
    if (!(assignment.startDate <= assignment.date && (assignment.endDate === null || assignment.endDate >= assignment.date))) continue;
    if (!store.positions.has(assignment.positionId)) {
      store.positions.set(assignment.positionId, { id: assignment.positionId, staff: [] });
    }
    store.positions.get(assignment.positionId).staff.push(assignment);
    uniqueStaff.add(assignment.staffId);
  }

  return {
    staffCount: uniqueStaff.size,
    stores: [...groups.values()].map((store) => ({
      id: store.id,
      positions: [...store.positions.values()],
    })),
  };
}

test('organization service exposes tree and flat current organization data', () => {
  assert.match(service, /public function getOrganizationTree\(\): array/);
  assert.match(service, /'business_date' => \$businessDate/);
  assert.match(service, /'type' => 'headquarters'/);
  assert.match(service, /'stores' => \$stores/);
  assert.match(service, /'positions' => \$positions/);
  assert.match(service, /'staff' => \$staffList/);
});

test('organization tree reads one database date and only current active relations', () => {
  assert.match(service, /query\('SELECT CURDATE\(\)'\)->fetchColumn\(\)/);
  assert.match(service, /staff\.status = 1 AND staff\.lifecycle_status = 'active'/);
  assert.match(service, /store\.id = a\.store_id AND store\.status = 1/);
  assert.match(service, /position\.id = a\.position_id AND position\.status = 1/);
  assert.match(service, /a\.start_date <= \? AND \(a\.end_date IS NULL OR a\.end_date >= \?\)/);
  assert.match(service, /execute\(\[\$businessDate, \$businessDate\]\)/);
});

test('tree hierarchy carries assignment identity without sensitive staff fields', () => {
  for (const field of ['assignment_id', 'employee_no', 'assignment_type', 'system_role', 'start_date', 'end_date']) {
    assert.match(service, new RegExp(`'${field}' =>`));
  }
  for (const sensitiveField of ['phone', 'user_login', 'user_email', 'openid']) {
    assert.doesNotMatch(service.slice(service.indexOf('public function getOrganizationTree'), service.indexOf('private function normalizeAssignmentInput')), new RegExp(`staff\\.${sensitiveField}`));
  }
});

test('organization endpoint is GET-only and uses headquarters management authorization', () => {
  assert.match(endpoint, /\$_SERVER\['REQUEST_METHOD'\] !== 'GET'/);
  assert.match(endpoint, /adminRequirePermission\('organization\.manage'\)/);
  assert.match(endpoint, /jsonResponse\(0, 'ok', \$service->getOrganizationTree\(\)\)/);
});

test('tree model preserves empty stores and exposes multiple current duties', () => {
  const stores = [{ id: 1 }, { id: 2 }];
  const base = {
    storeId: 1,
    staffId: 7,
    staffActive: true,
    storeActive: true,
    positionActive: true,
    startDate: '2026-01-01',
    endDate: null,
    date: '2026-07-24',
  };
  const tree = buildOrganizationTree(stores, [
    { ...base, positionId: 10, assignmentType: 'primary' },
    { ...base, positionId: 20, assignmentType: 'secondary' },
  ]);

  assert.equal(tree.staffCount, 1);
  assert.equal(tree.stores.length, 2);
  assert.equal(tree.stores[0].positions.length, 2);
  assert.equal(tree.stores[1].positions.length, 0);
});

test('tree model excludes future, ended, inactive staff, and inactive organization relations', () => {
  const common = {
    storeId: 1,
    positionId: 10,
    staffActive: true,
    storeActive: true,
    positionActive: true,
    startDate: '2026-01-01',
    endDate: null,
    date: '2026-07-24',
  };
  const tree = buildOrganizationTree([{ id: 1 }], [
    { ...common, staffId: 1 },
    { ...common, staffId: 2, startDate: '2026-07-25' },
    { ...common, staffId: 3, endDate: '2026-07-23' },
    { ...common, staffId: 4, staffActive: false },
    { ...common, staffId: 5, storeActive: false },
    { ...common, staffId: 6, positionActive: false },
  ]);

  assert.equal(tree.staffCount, 1);
  assert.deepEqual(tree.stores[0].positions[0].staff.map((staff) => staff.staffId), [1]);
});
