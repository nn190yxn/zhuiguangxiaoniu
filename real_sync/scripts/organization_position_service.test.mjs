import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const service = readFileSync(
  new URL('../api/admin/services/OrganizationService.php', import.meta.url),
  'utf8',
);
const endpoint = readFileSync(
  new URL('../api/admin/organization/positions.php', import.meta.url),
  'utf8',
);
const staffLifecycle = readFileSync(
  new URL('../api/admin/services/StaffLifecycleService.php', import.meta.url),
  'utf8',
);

class PositionDictionaryModel {
  #positions = new Map();
  #assignments = [];
  #nextId = 1;

  create(input) {
    const normalized = this.#normalize(input);
    this.#assertCodeAvailable(normalized.positionCode);
    const position = { id: this.#nextId++, ...normalized };
    this.#positions.set(position.id, position);
    return structuredClone(position);
  }

  update(id, patch) {
    const current = this.#positions.get(id);
    assert.ok(current, 'position must exist');
    const next = this.#normalize({ ...current, ...patch });
    this.#assertCodeAvailable(next.positionCode, id);
    if (current.status === 1 && next.status === 0) {
      const activeReferences = this.#assignments.filter(
        (assignment) => assignment.positionId === id && assignment.active,
      );
      assert.equal(activeReferences.length, 0, 'active position references block deactivation');
    }
    const updated = { id, ...next };
    this.#positions.set(id, updated);
    return structuredClone(updated);
  }

  assign(positionId, active) {
    this.#assignments.push({ positionId, active });
  }

  selectableFor(role) {
    return [...this.#positions.values()]
      .filter((position) => position.status === 1 && position.roles.includes(role))
      .sort((left, right) => left.sortOrder - right.sortOrder || left.id - right.id);
  }

  assignments() {
    return structuredClone(this.#assignments);
  }

  #normalize(input) {
    const positionCode = String(input.positionCode).trim().toLowerCase();
    assert.match(positionCode, /^[a-z0-9][a-z0-9_-]{1,63}$/);
    const positionName = String(input.positionName).trim();
    assert.ok(positionName.length > 0 && positionName.length <= 100);
    const roles = [...new Set(input.roles.map((role) => String(role).trim().toLowerCase()))].sort();
    assert.ok(roles.length > 0);
    const sortOrder = Number(input.sortOrder);
    assert.ok(Number.isInteger(sortOrder) && Math.abs(sortOrder) <= 1_000_000);
    const status = Number(input.status);
    assert.ok(status === 0 || status === 1);
    return { positionCode, positionName, roles, sortOrder, status };
  }

  #assertCodeAvailable(code, excludeId = null) {
    const duplicate = [...this.#positions.values()].some(
      (position) => position.positionCode === code && position.id !== excludeId,
    );
    assert.equal(duplicate, false, 'position code must be unique');
  }
}

test('position service exposes explicit dictionary reads and normalized role output', () => {
  assert.match(service, /function listPositions\(array \$filters = \[\]\): array/);
  assert.match(service, /function getPosition\(int \$positionId\): array/);
  assert.match(service, /p\.position_code, p\.position_name, p\.applicable_roles_json/);
  assert.doesNotMatch(service, /SELECT\s+\*/i);
  assert.match(service, /json_decode\(\(string\) \(\$position\['applicable_roles_json'\]/);
  assert.match(service, /reference_summary/);
});

test('position writes validate fields, normalize roles, and preserve deterministic ordering', () => {
  for (const field of ['position_code', 'position_name', 'applicable_roles', 'sort_order', 'status']) {
    assert.match(service, new RegExp(`['"]${field}['"]`));
  }
  assert.match(service, /appRoleCode\(trim\(\(string\) \$role\)\)/);
  assert.match(service, /sort\(\$result, SORT_STRING\)/);
  assert.match(service, /ORDER BY p\.sort_order ASC, p\.id ASC/);
  assert.match(service, /至少选择一个适用系统角色/);
});

test('position creation and update are transactional and audited', () => {
  assert.match(service, /INSERT INTO organization_positions/);
  assert.match(service, /UPDATE organization_positions SET/);
  assert.match(service, /ensureAdminOperationLogsTable\(\$this->pdo\)/);
  assert.match(service, /beginTransaction\(\)/);
  assert.match(service, /adminRecordOperation\(/);
  assert.match(service, /\$this->pdo->commit\(\)/);
  assert.match(service, /rollBackIfNeeded\(\)/);
  assert.ok(service.indexOf('beginTransaction()') < service.indexOf('adminRecordOperation('));
  assert.ok(service.indexOf('adminRecordOperation(') < service.indexOf('$this->pdo->commit()'));
});

test('duplicate position codes map to a stable conflict field', () => {
  assert.match(service, /assertPositionCodeAvailable/);
  assert.match(service, /FOR UPDATE/);
  assert.match(service, /OrganizationPositionConflictException\('岗位编码已存在', 'position_code'\)/);
  assert.match(service, /1062/);
  assert.match(endpoint, /jsonResponse\(409, \$error->getMessage\(\), \['conflict_field'/);
});

test('deactivation checks current references while retaining historical assignments', () => {
  assert.match(service, /primary_position_id = \? AND status = 1 AND lifecycle_status = 'active'/);
  assert.match(service, /start_date <= CURDATE\(\)/);
  assert.match(service, /end_date IS NULL OR end_date >= CURDATE\(\)/);
  assert.match(service, /historical_assignment_count/);
  assert.match(service, /throw new OrganizationPositionReferenceException\(\$references\)/);
  assert.doesNotMatch(service, /DELETE\s+FROM\s+(organization_positions|staff_assignments)/i);
  assert.match(endpoint, /\['reference_summary' => \$error->referenceSummary\(\)\]/);
});

test('position endpoint enforces headquarters authorization and CRUD actions', () => {
  assert.match(endpoint, /adminRequirePermission\('organization\.manage'\)/);
  assert.match(endpoint, /\['GET', 'POST'\]/);
  for (const action of ['create', 'update', 'set_status']) {
    assert.match(endpoint, new RegExp(`\\$action === '${action}'`));
  }
  assert.match(endpoint, /new OrganizationService\(getDB\(\)\)/);
  assert.match(endpoint, /\$operatorUser = is_array\(\$user\) \? \$user : \['user_id' => \(int\) \$userId\]/);
});

test('inactive positions remain stored and disappear from new staff choices', () => {
  const dictionary = new PositionDictionaryModel();
  const position = dictionary.create({
    positionCode: 'Sales_Advisor',
    positionName: '销售顾问',
    roles: ['sales', 'sales'],
    sortOrder: 20,
    status: 1,
  });
  dictionary.assign(position.id, false);
  assert.equal(dictionary.selectableFor('sales').length, 1);

  const disabled = dictionary.update(position.id, { status: 0 });
  assert.equal(disabled.status, 0);
  assert.equal(dictionary.selectableFor('sales').length, 0);
  assert.deepEqual(dictionary.assignments(), [{ positionId: position.id, active: false }]);

  assert.match(staffLifecycle, /FROM organization_positions WHERE id = \? AND status = 1/);
});

test('current assignments block deactivation and edits keep unique position codes', () => {
  const dictionary = new PositionDictionaryModel();
  const sales = dictionary.create({
    positionCode: 'sales_advisor',
    positionName: '销售顾问',
    roles: ['sales'],
    sortOrder: 20,
    status: 1,
  });
  dictionary.create({
    positionCode: 'sales_manager',
    positionName: '销售经理',
    roles: ['manager'],
    sortOrder: 10,
    status: 1,
  });
  dictionary.assign(sales.id, true);

  assert.throws(() => dictionary.update(sales.id, { status: 0 }), /active position references/);
  assert.throws(
    () => dictionary.update(sales.id, { positionCode: 'sales_manager' }),
    /position code must be unique/,
  );
  assert.equal(dictionary.selectableFor('sales')[0].status, 1);
});
