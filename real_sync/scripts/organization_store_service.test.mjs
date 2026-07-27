import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/admin/services/OrganizationService.php', import.meta.url),
  'utf8',
);
const endpoint = readFileSync(
  new URL('../api/admin/organization/stores.php', import.meta.url),
  'utf8',
);
const staffLifecycle = readFileSync(
  new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url),
  'utf8',
);
const adminImport = readFileSync(new URL('../api/admin/staff-import.php', import.meta.url), 'utf8');
const cliImport = readFileSync(new URL('./import_staff_cli.php', import.meta.url), 'utf8');
const importService = readFileSync(
  new URL('../api/admin/services/StaffImportService.php', import.meta.url),
  'utf8',
);
const staffOptions = readFileSync(new URL('../api/staff/list.php', import.meta.url), 'utf8');

class StoreDictionaryModel {
  #stores = new Map();
  #staff = new Map();
  #assignments = [];
  #nextId = 1;

  addStaff(id, name, active = true) {
    this.#staff.set(id, { id, name, active });
  }

  setStaffActive(id, active) {
    const staff = this.#staff.get(id);
    assert.ok(staff, 'staff must exist');
    this.#staff.set(id, { ...staff, active });
  }

  create(input) {
    const normalized = this.#normalize(input);
    this.#assertCodeAvailable(normalized.storeCode);
    this.#assertManagerActive(normalized.managerStaffId);
    const store = { id: this.#nextId++, ...normalized };
    this.#stores.set(store.id, store);
    return structuredClone(store);
  }

  update(id, patch) {
    const current = this.#stores.get(id);
    assert.ok(current, 'store must exist');
    const next = this.#normalize({ ...current, ...patch });
    this.#assertCodeAvailable(next.storeCode, id);
    if (next.managerStaffId !== current.managerStaffId) {
      this.#assertManagerActive(next.managerStaffId);
    }
    if (current.status === 1 && next.status === 0) {
      const currentAssignments = this.#assignments.filter(
        (assignment) => assignment.storeId === id && assignment.current && assignment.staffActive,
      );
      assert.equal(currentAssignments.length, 0, 'current active staff assignments block deactivation');
    }
    const updated = { id, ...next };
    this.#stores.set(id, updated);
    return structuredClone(updated);
  }

  assign(storeId, { current, staffActive }) {
    this.#assignments.push({ storeId, current, staffActive });
  }

  selectable() {
    return [...this.#stores.values()]
      .filter((store) => store.status === 1)
      .sort((left, right) => left.sortOrder - right.sortOrder || left.id - right.id);
  }

  assignments() {
    return structuredClone(this.#assignments);
  }

  #normalize(input) {
    const storeCode = String(input.storeCode).trim().toUpperCase();
    assert.match(storeCode, /^[A-Z0-9][A-Z0-9_-]{1,63}$/);
    const name = String(input.name).trim();
    assert.ok(name.length > 0 && name.length <= 100);
    const managerStaffId = input.managerStaffId == null ? null : Number(input.managerStaffId);
    assert.ok(managerStaffId === null || Number.isInteger(managerStaffId) && managerStaffId > 0);
    const sortOrder = Number(input.sortOrder);
    assert.ok(Number.isInteger(sortOrder) && Math.abs(sortOrder) <= 1_000_000);
    const status = Number(input.status);
    assert.ok(status === 0 || status === 1);
    return { storeCode, name, managerStaffId, sortOrder, status };
  }

  #assertCodeAvailable(code, excludeId = null) {
    const duplicate = [...this.#stores.values()].some(
      (store) => store.storeCode === code && store.id !== excludeId,
    );
    assert.equal(duplicate, false, 'store code must be unique');
  }

  #assertManagerActive(managerStaffId) {
    if (managerStaffId === null) return;
    assert.equal(this.#staff.get(managerStaffId)?.active, true, 'manager must be active staff');
  }
}

test('store service exposes explicit dictionary reads and reference summaries', () => {
  assert.match(service, /function listStores\(array \$filters = \[\]\): array/);
  assert.match(service, /function getStore\(int \$storeId\): array/);
  assert.match(service, /s\.store_code, s\.name, s\.manager_staff_id, s\.manager_name/);
  assert.match(service, /ORDER BY s\.sort_order ASC, s\.id ASC/);
  assert.match(service, /historical_assignment_count/);
});

test('store writes validate every managed field and preserve legacy manager names', () => {
  for (const field of ['store_code', 'name', 'manager_staff_id', 'sort_order', 'status']) {
    assert.match(service, new RegExp(`['"]${field}['"]`));
  }
  assert.match(service, /strtoupper\(trim\(\(string\) \$input\['store_code'\]\)\)/);
  assert.match(service, /负责人必须是当前在职员工/);
  assert.match(service, /manager_staff_id = \?, manager_name = \?/);
  assert.match(service, /'manager_changed' => \$before === null \|\| \$managerStaffId !== \$beforeManagerStaffId/);
  assert.match(service, /: \$before\['manager_name'\]/);
});

test('store creation and updates run in transactions with before and after audit snapshots', () => {
  assert.match(service, /INSERT INTO stores/);
  assert.match(service, /UPDATE stores SET/);
  assert.match(service, /'action' => 'store\.create'/);
  assert.match(service, /'action' => 'store\.update'/);
  assert.match(service, /'target_type' => 'store'/);
  assert.match(service, /ensureAdminOperationLogsTable\(\$this->pdo\)/);
  assert.match(service, /rollBackIfNeeded\(\)/);
});

test('duplicate store codes produce a stable conflict response', () => {
  assert.match(service, /assertStoreCodeAvailable/);
  assert.match(service, /OrganizationStoreConflictException\('门店编码已存在', 'store_code'\)/);
  assert.match(endpoint, /jsonResponse\(409, \$error->getMessage\(\), \['conflict_field'/);
});

test('store deactivation checks active staff across fast fields and effective assignments', () => {
  assert.match(service, /store_id = \? AND status = 1 AND lifecycle_status = 'active'/);
  assert.match(service, /INNER JOIN staffs assigned_staff ON assigned_staff\.id = store_assignment\.staff_id/);
  assert.match(service, /store_assignment\.start_date <= CURDATE\(\)/);
  assert.match(service, /store_assignment\.end_date IS NULL OR store_assignment\.end_date >= CURDATE\(\)/);
  assert.match(service, /throw new OrganizationStoreReferenceException\(\$references\)/);
  assert.doesNotMatch(service, /DELETE\s+FROM\s+(stores|staff_assignments)/i);
});

test('store endpoint restricts management and supports query, create, edit, and status actions', () => {
  assert.match(endpoint, /adminRequirePermission\('organization\.manage'\)/);
  for (const action of ['create', 'update', 'set_status']) {
    assert.match(endpoint, new RegExp(`\\$action === '${action}'`));
  }
  assert.match(endpoint, /new OrganizationService\(getDB\(\)\)/);
  assert.match(endpoint, /\['reference_summary' => \$error->referenceSummary\(\)\]/);
  assert.match(endpoint, /\$operatorUser = is_array\(\$user\) \? \$user : \['user_id' => \(int\) \$userId\]/);
});

test('all staff import paths delegate active store validation to staff lifecycle service', () => {
  for (const source of [adminImport, cliImport]) {
    assert.match(source, /new StaffImportService\(/);
    assert.doesNotMatch(source, /INSERT INTO staffs|INSERT INTO wp_users/);
  }
  assert.match(importService, /new StaffLifecycleService\(\$this->db\)/);
  assert.match(importService, /\$lifecycle->create\(\$record, \$operatorUser, \$operatorStaff\)/);
  assert.match(staffLifecycle, /FROM stores WHERE id = \? AND status = 1/);
  assert.match(staffOptions, /SELECT id, name FROM stores WHERE status = 1 ORDER BY sort_order ASC, id ASC/);
});

test('historical and inactive staff assignments survive store deactivation', () => {
  const dictionary = new StoreDictionaryModel();
  dictionary.addStaff(8, '负责人');
  const store = dictionary.create({
    storeCode: 'store-0008',
    name: '未来中心',
    managerStaffId: 8,
    sortOrder: 20,
    status: 1,
  });
  dictionary.assign(store.id, { current: false, staffActive: true });
  dictionary.assign(store.id, { current: true, staffActive: false });

  const disabled = dictionary.update(store.id, { status: 0 });
  assert.equal(disabled.status, 0);
  assert.equal(dictionary.selectable().length, 0);
  assert.equal(dictionary.assignments().length, 2);
  assert.match(staffLifecycle, /FROM stores WHERE id = \? AND status = 1/);
});

test('current staff blocks deactivation while manager and code validation remain strict', () => {
  const dictionary = new StoreDictionaryModel();
  dictionary.addStaff(1, '有效负责人');
  dictionary.addStaff(2, '离职负责人', false);
  const store = dictionary.create({
    storeCode: 'STORE-A',
    name: 'A 店',
    managerStaffId: 1,
    sortOrder: 10,
    status: 1,
  });
  dictionary.create({
    storeCode: 'STORE-B',
    name: 'B 店',
    managerStaffId: null,
    sortOrder: 20,
    status: 1,
  });
  dictionary.assign(store.id, { current: true, staffActive: true });

  assert.throws(() => dictionary.update(store.id, { status: 0 }), /current active staff assignments/);
  assert.throws(() => dictionary.update(store.id, { storeCode: 'store-b' }), /store code must be unique/);
  assert.throws(() => dictionary.update(store.id, { managerStaffId: 2 }), /manager must be active staff/);
  assert.equal(dictionary.selectable().length, 2);
});

test('unchanged legacy manager references do not block unrelated store edits', () => {
  const dictionary = new StoreDictionaryModel();
  dictionary.addStaff(9, '原负责人');
  const store = dictionary.create({
    storeCode: 'STORE-C',
    name: 'C 店',
    managerStaffId: 9,
    sortOrder: 30,
    status: 1,
  });
  dictionary.setStaffActive(9, false);

  const updated = dictionary.update(store.id, {
    name: 'C 店新名称',
    managerStaffId: 9,
  });
  assert.equal(updated.name, 'C 店新名称');
  assert.equal(updated.managerStaffId, 9);
});
